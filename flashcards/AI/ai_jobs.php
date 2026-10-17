<?php
/**
 * ai_jobs.php — il motore della generazione IA lunga.
 *
 * Un documento da 200 pagine non sta in una richiesta HTTP: sono una ventina
 * di estrazioni di concetti e un centinaio di lotti di carte, cioe' decine di
 * minuti. Qui quel lavoro diventa un JOB (una riga di ai_jobs) scomposto in
 * PASSI (righe di ai_job_steps), e avanza di un passo ogni volta che qualcuno
 * chiama ai_job_avanza().
 *
 * CHI CHIAMA ai_job_avanza() NON E' AFFAR SUO
 *
 * Oggi la chiama l'endpoint che la pagina interroga mentre mostra
 * l'avanzamento (su Altervista non c'e' un cron). Dopo il trasloco sul VPS la
 * chiamera' anche un cron, cosi' il lavoro va avanti a pagina chiusa. La
 * funzione non sa e non deve sapere chi l'ha chiamata: per questo lo scambio,
 * quel giorno, sara' un file da aggiungere e non una riscrittura.
 *
 * LE TRE REGOLE CHE TENGONO IN PIEDI TUTTO
 *
 *   1. Un passo lo esegue uno solo. Lo "prende" chi riesce a passarlo da
 *      todo a running con il proprio gettone (claim_token): due schede
 *      aperte, o polling e cron insieme, non lo eseguono due volte.
 *   2. Si addebita solo cio' che e' stato consegnato, e una volta sola. Lo
 *      garantisce il vincolo UNIQUE del libro mastro (ai_crediti.php), non la
 *      disciplina di questo file.
 *   3. Un passo che fallisce non ferma il job. Troppi fallimenti DI FILA si':
 *      e' il segno di un guasto nostro, e continuare vorrebbe dire bruciare
 *      tempo e token contro un muro.
 *
 * Non e' un endpoint e non controlla il login: lo fa chi la include. Ogni
 * funzione che agisce per conto di un utente riceve il suo id e lo verifica
 * nella query stessa (AND user_id = ?).
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security_core.php';
require_once __DIR__ . '/ai_crediti.php';
require_once __DIR__ . '/fc_ai_lib.php';

/* ============================================================
   TARATURA
   ============================================================ */

/* Carte per passo. fc_ai.php ne fa 12 per chiamata, ma li' e' il browser ad
   aspettare e un lotto lento si vede. Qui un passo deve stare comodo dentro
   il tempo massimo di una richiesta anche quando Gemini e' lento, e un passo
   che salta ne perde meno: 8 e' il compromesso fra velocita' complessiva
   (meno passi) e rischio di ogni passo. */
define('AI_JOB_LOTTO', 8);

/* Lotto piu' piccolo per i lavori corti. Vedi ai_job_lotto_per(). */
define('AI_JOB_LOTTO_CORTO', 4);
define('AI_JOB_CORTO_FINO_A', 16);

/**
 * Quante carte per passo, viste quante ne servono in tutto.
 *
 * Con lotti da 8, otto concetti presi da un elenco fanno UN passo solo: il
 * lavoro e' atomico, non esiste nessun avanzamento da mostrare, e l'utente
 * guarda "0 di 8" fermo finche' non arriva tutto insieme. Sembra bloccato, ed
 * e' il momento in cui uno ricarica la pagina.
 *
 * Fino a 16 carte si scende a 4 per passo: due o piu' pezzi, quindi le prime
 * carte compaiono in tabella a meta' strada e la barra si muove davvero. Costa
 * un po' di piu' (l'intestazione del prompt viaggia una volta in piu'), ma su
 * un lavoro corto e' pochissimo — ed e' esattamente il lavoro dove l'attesa si
 * nota, perche' l'utente sta li' a guardare.
 *
 * Oltre, si resta a 8: su 790 carte i passi in piu' sarebbero cento, e li'
 * l'avanzamento si vede comunque.
 */
function ai_job_lotto_per(int $quante): int {
    return $quante <= AI_JOB_CORTO_FINO_A ? AI_JOB_LOTTO_CORTO : AI_JOB_LOTTO;
}

/* Circa 300 pagine fitte. Oltre, il testo arriva in un corpo di richiesta di
   diversi megabyte e il job durerebbe ore: meglio dividerlo in due documenti. */
define('AI_JOB_TESTO_MAX', 1200000);

define('AI_JOB_MAX_CONCETTI', 3000);

/* Tentativi per passo. Contano anche le volte in cui l'esecutore e' morto a
   meta' (tempo massimo di PHP): un passo che uccide chi lo esegue tre volte
   di fila non passera' mai, e va segnato fallito invece di riprovarlo
   all'infinito. */
define('AI_JOB_MAX_TENTATIVI', 3);

/* Fallimenti di fila che fermano il job (circuit breaker). */
define('AI_JOB_BREAKER', 5);

/* Dopo quanti secondi un passo "running" si considera abbandonato. Deve
   stare ben sopra la durata massima di un passo: se fosse piu' corto, un
   passo lento ma vivo verrebbe ripreso da un altro esecutore e pagato due
   volte a Google (all'utente no: lo impedisce il libro mastro). */
define('AI_JOB_PRESA_SCADE', 180);

/* Sotto questo tempo residuo non si prende un passo: non farebbe in tempo. */
define('AI_JOB_TEMPO_MINIMO', 20.0);

/* Passi al minuto su tutto il sito, tutti gli utenti insieme. E' il tetto che
   protegge il server condiviso (e la quota Gemini) quando in dieci lanciano
   un documento lungo nello stesso momento: chi supera il tetto aspetta il
   giro dopo, non riceve un errore. */
if (!defined('AI_JOB_PASSI_MINUTO')) define('AI_JOB_PASSI_MINUTO', 40); // sul VPS si alza definendola prima

/* ============================================================
   1. DIVIDERE IL DOCUMENTO
   ============================================================ */

/**
 * Blocchi di al massimo $max caratteri, tagliati ai confini di paragrafo.
 *
 * Un taglio a meta' frase manderebbe al modello un pezzo di ragionamento
 * tronco proprio all'inizio o alla fine del blocco, dove l'attenzione e' gia'
 * minore. Le pagine del PDF arrivano gia' separate da una riga vuota, quindi
 * anche nel caso peggiore si taglia almeno fra una pagina e l'altra.
 */
function ai_job_dividi(string $testo, int $max): array {
    $testo = trim($testo);
    if ($testo === '') return [];
    if (mb_strlen($testo, 'UTF-8') <= $max) return [$testo];

    $blocchi = [];
    $corrente = '';
    foreach (preg_split("/\n[ \t]*\n/u", $testo) as $par) {
        $par = trim($par);
        if ($par === '') continue;
        $lp = mb_strlen($par, 'UTF-8');

        // un paragrafo piu' lungo di un blocco intero (tabelle, testo senza a capo)
        if ($lp > $max) {
            if ($corrente !== '') { $blocchi[] = $corrente; $corrente = ''; }
            foreach (ai_job_spezza_lungo($par, $max) as $pezzo) $blocchi[] = $pezzo;
            continue;
        }
        if ($corrente === '') {
            $corrente = $par;
        } elseif (mb_strlen($corrente, 'UTF-8') + 2 + $lp <= $max) {
            $corrente .= "\n\n" . $par;
        } else {
            $blocchi[] = $corrente;
            $corrente = $par;
        }
    }
    if ($corrente !== '') $blocchi[] = $corrente;
    return $blocchi;
}

/** Un paragrafo gigante: si taglia fra una frase e l'altra, e solo come ultima risorsa a forza. */
function ai_job_spezza_lungo(string $par, int $max): array {
    $pezzi = [];
    $corrente = '';
    foreach (preg_split('/(?<=[.!?;:])\s+/u', $par) as $frase) {
        if (mb_strlen($frase, 'UTF-8') > $max) {
            if ($corrente !== '') { $pezzi[] = $corrente; $corrente = ''; }
            for ($i = 0, $n = mb_strlen($frase, 'UTF-8'); $i < $n; $i += $max) {
                $pezzi[] = mb_substr($frase, $i, $max, 'UTF-8');
            }
            continue;
        }
        if ($corrente === '') $corrente = $frase;
        elseif (mb_strlen($corrente, 'UTF-8') + 1 + mb_strlen($frase, 'UTF-8') <= $max) $corrente .= ' ' . $frase;
        else { $pezzi[] = $corrente; $corrente = $frase; }
    }
    if ($corrente !== '') $pezzi[] = $corrente;
    return $pezzi;
}

/* ============================================================
   2. ANCORE ED ESTRATTI — la stessa logica di fc_ai_client.js
   ============================================================
   Nel flusso di oggi le finestre di testo attorno ai concetti le calcola il
   browser (estrattoPer). Nel job le deve calcolare il server, perche' il
   browser puo' non esserci piu'. E' una traduzione riga per riga di
   indice(), trovaAncora() ed estrattoPer(): se si corregge una delle due, va
   corretta anche l'altra. */

function ai_job_piatto(string $c): string {
    static $mappa = ['à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a', 'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
                     'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o',
                     'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'ñ' => 'n'];
    return $mappa[$c] ?? $c;
}

function ai_job_normalizza(string $s): string {
    $out = '';
    foreach (mb_str_split(mb_strtolower($s, 'UTF-8'), 1, 'UTF-8') as $c) {
        $c = ai_job_piatto($c);
        $out .= preg_match('/^[a-z0-9]$/', $c) ? $c : ' ';
    }
    return trim(preg_replace('/\s+/', ' ', $out));
}

/**
 * Indice di ricerca di un blocco: il testo normalizzato e, per ogni suo
 * carattere, la posizione (in caratteri, non in byte) nel testo originale.
 * L'array dei singoli caratteri serve solo a costruirlo e poi si butta:
 * tenerlo in memoria per venti blocchi costerebbe decine di megabyte.
 */
function ai_job_indice(string $testo): array {
    $norm = '';
    $mappa = [];
    $precedenteSpazio = true;
    /* Minuscole carattere per carattere, come fa il JS: mb_strtolower sulla
       stringa intera puo' cambiare il numero di caratteri (la I con il punto
       turca ne diventa due), e ogni posizione dopo sarebbe sfalsata. */
    foreach (mb_str_split($testo, 1, 'UTF-8') as $i => $c) {
        $c = ai_job_piatto(mb_strtolower($c, 'UTF-8'));
        if (preg_match('/^[a-z0-9]$/', $c)) { $norm .= $c; $mappa[] = $i; $precedenteSpazio = false; }
        elseif (!$precedenteSpazio) { $norm .= ' '; $mappa[] = $i; $precedenteSpazio = true; }
    }
    return ['testo' => $testo, 'lung' => mb_strlen($testo, 'UTF-8'), 'norm' => $norm, 'mappa' => $mappa];
}

/** Posizione (in caratteri) dell'ancora nel blocco, o -1. */
function ai_job_trova_ancora(array $idx, string $ancora): int {
    if ($ancora === '') return -1;
    $diretto = mb_strpos($idx['testo'], $ancora, 0, 'UTF-8');
    if ($diretto !== false) return $diretto;

    /* Il modello riscrive quasi sempre qualcosa (accenti, spaziatura,
       maiuscole): il confronto normalizzato recupera questi casi. Se fallisce
       anche quello, si prova con le prime tre parole soltanto. La norm e'
       tutta ASCII, quindi qui strpos in byte e' anche in caratteri. */
    $n = ai_job_normalizza($ancora);
    if ($n === '') return -1;
    $p = strpos($idx['norm'], $n);
    $parole = explode(' ', $n);
    if ($p === false && count($parole) > 3) {
        $p = strpos($idx['norm'], implode(' ', array_slice($parole, 0, 3)));
    }
    return $p === false ? -1 : $idx['mappa'][$p];
}

/**
 * L'estratto per un gruppo di concetti: le finestre attorno alle loro ancore,
 * blocco per blocco, unite quando si sovrappongono. Ogni concetto porta le
 * sue ancore come coppie [b => blocco, a => citazione]: un concetto nato
 * dalla fusione di due ne ha due, anche in blocchi diversi.
 *
 * $blocchi: testi per numero di blocco. $cache: indici gia' costruiti, per
 * riferimento, cosi' ogni blocco si indicizza una volta per tutto il job.
 */
function ai_job_estratto(array $concetti, array $blocchi, array &$cache, int $raggio = 450): string {
    $finestre = []; // per blocco
    foreach ($concetti as $c) {
        foreach ((array)($c['ancore'] ?? []) as $anc) {
            $b = (int)($anc['b'] ?? -1);
            $a = (string)($anc['a'] ?? '');
            if ($a === '' || !isset($blocchi[$b])) continue;
            if (!isset($cache[$b])) $cache[$b] = ai_job_indice($blocchi[$b]);
            $p = ai_job_trova_ancora($cache[$b], $a);
            if ($p === -1) continue;
            // piu' contesto DOPO che prima: la definizione segue il concetto
            $finestre[$b][] = [max(0, $p - (int)round($raggio / 3)), min($cache[$b]['lung'], $p + $raggio)];
        }
    }
    if (!$finestre) return '';

    ksort($finestre);
    $pezzi = [];
    foreach ($finestre as $b => $f) {
        usort($f, function ($x, $y) { return $x[0] <=> $y[0]; });
        $unite = [$f[0]];
        for ($i = 1; $i < count($f); $i++) {
            $u = &$unite[count($unite) - 1];
            if ($f[$i][0] <= $u[1] + 120) $u[1] = max($u[1], $f[$i][1]);
            else $unite[] = $f[$i];
            unset($u);
        }
        foreach ($unite as $w) {
            $pezzi[] = trim(mb_substr($blocchi[$b], $w[0], $w[1] - $w[0], 'UTF-8'));
        }
    }
    return mb_substr(implode("\n[...]\n", $pezzi), 0, FC_AI_MAX_TESTO, 'UTF-8');
}

/* ============================================================
   3. LEGGERE E VALIDARE
   ============================================================ */

/**
 * Le opzioni del job, validate una volta alla creazione e poi lette uguali
 * da ogni passo. Qualunque cosa il client mandi in piu' non entra.
 */
function ai_job_opzioni($in): array {
    $in = is_array($in) ? $in : [];
    return [
        'fonte'        => sec_enum($in['fonte'] ?? 'file', ['file', 'elenco', 'argomento'], 'file'),
        'lingua'       => sec_enum($in['lingua'] ?? 'italiano', ['italiano', 'inglese'], 'italiano'),
        'esempi'       => sec_bool($in['esempi'] ?? false),
        'esempi_figli' => sec_bool($in['esempi_figli'] ?? false),
        'gerarchia'    => sec_bool($in['gerarchia'] ?? false),
        'solo_elenco'  => sec_bool($in['solo_elenco'] ?? false),
        'integra'      => sec_bool($in['integra'] ?? false),
        'corregge'     => sec_bool($in['corregge'] ?? false),
        'testuale'     => sec_bool($in['testuale'] ?? false),
        'indicazioni'  => fc_ai_indicazioni($in['indicazioni'] ?? ''),
        /* Revisione: dopo l'estrazione il job si ferma e mostra l'elenco dei
           concetti, come fa oggi il pannello, prima di spendere crediti sulle
           carte. Di serie e' acceso: e' l'esperienza che l'utente conosce, e
           su un documento lungo e' il punto in cui togliere dieci concetti
           inutili fa risparmiare dieci carte. */
        'revisione'    => array_key_exists('revisione', $in) ? sec_bool($in['revisione']) : true,
        // quante carte si aspetta l'utente (es. 4 per pagina): serve solo al preventivo
        'carte_attese' => sec_int($in['carte_attese'] ?? 0, 0, AI_JOB_MAX_CONCETTI, 0),
        /* Stile e impegno si scelgono una volta e valgono per ogni passo: un
           job di cento lotti con lo stile che cambia a meta' darebbe un mazzo
           scritto in due modi. */
        'stile'        => fc_ai_stile_leggi($in['stile'] ?? []),
        'impegno'      => fc_ai_impegno_leggi($in['impegno'] ?? 'medio'),
    ];
}

/** Un job dell'utente, con le opzioni gia' decodificate. null se non c'e' o non e' suo. */
function ai_job_leggi(int $job_id, ?int $user_id = null): ?array {
    $job = $user_id === null
        ? sec_one("SELECT * FROM ai_jobs WHERE id = ?", [$job_id], 'i')
        : sec_one("SELECT * FROM ai_jobs WHERE id = ? AND user_id = ?", [$job_id, $user_id], 'ii');
    if (!$job) return null;
    $job['opzioni'] = json_decode((string)$job['options'], true) ?: [];
    return $job;
}

/** Il job vivo dell'utente (in coda, in corso o in pausa), se c'e'. Al massimo uno: lo garantisce lo schema. */
function ai_job_attivo(int $user_id): ?array {
    $r = sec_one("SELECT id FROM ai_jobs WHERE active_user_id = ?", [$user_id], 'i');
    return $r ? ai_job_leggi((int)$r['id'], $user_id) : null;
}

/**
 * Una scrittura che puo' violare un vincolo UNIQUE in modo legittimo (due
 * schede che creano un job nello stesso istante, un job ripreso mentre ce
 * n'e' gia' un altro attivo). sec_q in quel caso interromperebbe la richiesta
 * con un errore 500; qui il doppione torna false, e chi chiama sa cosa dire.
 * Ogni altro errore resta un errore.
 */
function ai_job_scrivi_o_doppione(string $sql, array $params, string $types): bool {
    $conn = sec_db();
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        sec_log_error('ai_jobs', 'prepare: ' . mysqli_error($conn));
        sec_die_json('Errore nella richiesta al database.', 500);
    }
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    /* Il doppione arriva come eccezione se mysqli e' in modalita' "strict"
       (la imposta sec_db quando apre la connessione), come valore di ritorno
       false altrimenti. Si gestiscono entrambi: la connessione potrebbe
       averla aperta una pagina vecchia, con le sue impostazioni. */
    try {
        $riuscito = mysqli_stmt_execute($stmt);
        $codice = $riuscito ? 0 : mysqli_stmt_errno($stmt);
        $messaggio = $riuscito ? '' : mysqli_stmt_error($stmt);
    } catch (mysqli_sql_exception $e) {
        $riuscito = false;
        $codice = (int)$e->getCode();
        $messaggio = $e->getMessage();
    }
    if (!$riuscito) {
        mysqli_stmt_close($stmt);
        if ($codice === 1062) return false;
        sec_log_error('ai_jobs', 'execute: ' . $messaggio);
        sec_die_json('Errore nella richiesta al database.', 500);
    }
    $GLOBALS['SEC_LAST_INSERT_ID'] = mysqli_insert_id($conn);
    $GLOBALS['SEC_LAST_AFFECTED']  = mysqli_stmt_affected_rows($stmt);
    mysqli_stmt_close($stmt);
    return true;
}

/* ============================================================
   4. PREVENTIVI
   ============================================================
   Tutti passano da fc_ai_preventivo(), la stessa funzione che usa fc_ai.php:
   il preventivo di un job e' la somma dei preventivi dei suoi passi, non una
   formula a parte che prima o poi smetterebbe di coincidere. */

function ai_job_stima_passo(array $input, string $tipo, array $opz): int {
    if ($tipo === 'concepts') {
        return fc_ai_preventivo('concetti', [
            'testo' => $input['testo'] ?? '', 'elenco' => $input['elenco'] ?? [],
            'indicazioni' => $opz['indicazioni'] ?? '', 'opzioni' => $opz,
        ]);
    }
    return fc_ai_preventivo('carte', [
        'concetti' => $input['concetti'] ?? [], 'estratto' => $input['estratto'] ?? '',
        'opzioni' => $opz,   // intere: l'impegno e lo stile cambiano il prezzo
    ]) + (int)ceil(mb_strlen((string)($opz['indicazioni'] ?? ''), 'UTF-8') / FC_AI_CHAR_PER_TOKEN);
}

/**
 * Le carte non si possono preventivare esattamente prima di sapere quanti
 * concetti usciranno. Si usa la stessa proporzione di fc_ai_preventivo (un
 * concetto ogni 600 caratteri di prosa didattica), oppure il numero che
 * l'utente ha detto di volere, e un estratto medio per concetto.
 */
function ai_job_stima_carte(int $quante, array $opz): int {
    if ($quante <= 0) return 0;
    /* La stessa misura di lotto che usera' davvero ai_job_lotti_carte(): con
       due numeri diversi il preventivo direbbe una cifra e il conto finale
       un'altra, ed e' il genere di differenza che l'utente nota subito. */
    $lotto = ai_job_lotto_per($quante);
    $lotti = (int)ceil($quante / $lotto);
    $per_lotto = fc_ai_preventivo('carte', [
        'concetti'  => array_fill(0, $lotto, ['t' => str_repeat('x', 40)]),
        'caratteri' => $lotto * 600,
        'opzioni'   => $opz,
    ]);
    return $lotti * $per_lotto;
}

/* ============================================================
   5. CREARE UN JOB
   ============================================================ */

/**
 * $in: context_id, request_id, opzioni, testo, titolo, elenco, concetti.
 *
 * Due partenze possibili:
 *   - dal documento (o da elenco/argomento): prima i concetti, poi le carte;
 *   - da un elenco di concetti gia' rivisto nel pannello (campo "concetti"):
 *     solo le carte. E' il caso di chi ha usato il pannello di oggi e vuole
 *     che la generazione vada avanti da sola.
 *
 * Torna ['ok' => true, 'job' => stato] oppure ['ok' => false, 'error' => ...].
 */
function ai_job_crea(int $user_id, array $in): array {
    $ctx = sec_int($in['context_id'] ?? 0, 0, PHP_INT_MAX, 0);
    if (!sec_owns('fc_categories', $ctx, $user_id)) {
        return ['ok' => false, 'error' => 'mazzo_non_trovato', 'http' => 404];
    }

    /* Il client genera il request_id al clic su "Genera" e lo rimanda uguale
       se ripete la richiesta (doppio clic, rete che ritenta). Se il job
       esiste gia', si restituisce quello invece di crearne un secondo. */
    $req = (string)($in['request_id'] ?? '');
    $req = preg_match('/^[a-f0-9]{32}$/', $req) ? $req : null;
    if ($req !== null) {
        $gia = sec_one("SELECT id FROM ai_jobs WHERE user_id = ? AND request_id = ?", [$user_id, $req], 'is');
        if ($gia) return ['ok' => true, 'job' => ai_job_stato(ai_job_leggi((int)$gia['id'], $user_id))];
    }

    $attivo = ai_job_attivo($user_id);
    if ($attivo) return ['ok' => false, 'error' => 'job_attivo', 'http' => 409, 'job_id' => (int)$attivo['id']];

    $opz = ai_job_opzioni($in['opzioni'] ?? []);
    $testo = fc_ai_documento($in['testo'] ?? '', AI_JOB_TESTO_MAX);
    $blocchi = $testo !== '' ? ai_job_dividi($testo, FC_AI_MAX_TESTO) : [];

    $passi_concetti = [];
    $passi_carte = [];
    $preventivo = 0;

    $concetti_dati = ai_job_concetti_da_client($in['concetti'] ?? null);
    if ($concetti_dati) {
        // partenza da un elenco gia' rivisto: si preparano subito tutti i lotti
        $passi_carte = ai_job_lotti_carte($concetti_dati, $blocchi, $opz);
        /* La nota di "rigenera tutte" ("piu' brevi", "piu' esempi") vale per
           l'intera rigenerazione: viaggia dentro ogni lotto, dove
           fc_ai_azione_carte la legge come ha sempre fatto. */
        $nota = fc_ai_riga($in['nota'] ?? '', 500);
        if ($nota !== '') foreach ($passi_carte as $k => $p) $passi_carte[$k]['nota'] = $nota;
        foreach ($passi_carte as $p) $preventivo += ai_job_stima_passo($p, 'cards', $opz);
    } else {
        if ($opz['fonte'] === 'file') {
            if (!$blocchi) return ['ok' => false, 'error' => 'testo_mancante', 'http' => 400];
            foreach ($blocchi as $b => $t) $passi_concetti[] = ['b' => $b, 'testo' => $t];
        } else {
            $titolo = fc_ai_riga($in['titolo'] ?? '', 200);
            $elenco = [];
            foreach ((array)($in['elenco'] ?? []) as $v) {
                $t = fc_ai_riga($v, 300);
                if ($t !== '') $elenco[] = $t;
                if (count($elenco) >= FC_AI_MAX_CONCETTI) break;
            }
            if ($opz['fonte'] === 'elenco' && !$elenco) return ['ok' => false, 'error' => 'elenco_mancante', 'http' => 400];
            if ($opz['fonte'] === 'argomento' && $titolo === '') return ['ok' => false, 'error' => 'argomento_mancante', 'http' => 400];
            $passi_concetti[] = ['b' => 0, 'titolo' => $titolo, 'elenco' => $elenco];
        }
        $attese = 0;
        foreach ($passi_concetti as $p) {
            $preventivo += ai_job_stima_passo($p, 'concepts', $opz);
            $attese += max(8, (int)ceil(mb_strlen((string)($p['testo'] ?? ''), 'UTF-8') / 600));
        }
        $preventivo += ai_job_stima_carte($opz['carte_attese'] > 0 ? $opz['carte_attese'] : $attese, $opz);
    }

    if (!$passi_concetti && !$passi_carte) return ['ok' => false, 'error' => 'niente_da_fare', 'http' => 400];

    /* Il preventivo del job intero, non del primo passo: chi lancia 200 pagine
       deve sapere prima di partire se i crediti bastano. Se a meta' finiscono
       lo stesso (una stima resta una stima), il job si ferma in pausa e cio'
       che e' gia' fatto resta suo. */
    if (!ai_crediti_bastano($user_id, $preventivo)) {
        $s = ai_crediti_stato($user_id);
        return ['ok' => false, 'error' => 'limite_piano', 'http' => 200,
                'stima' => $preventivo, 'disponibili' => $s['disponibili']];
    }

    $conn = sec_db();
    mysqli_begin_transaction($conn);

    $ok = ai_job_scrivi_o_doppione(
        "INSERT INTO ai_jobs (user_id, context_type, context_id, status, options, credits_estimated, request_id)
         VALUES (?, 'fc_deck', ?, 'queued', ?, ?, ?)",
        [$user_id, $ctx, json_encode($opz, JSON_UNESCAPED_UNICODE), $preventivo, $req],
        'iisis'
    );
    if (!$ok) {
        /* Doppione: o lo stesso request_id arrivato due volte nello stesso
           istante, o un altro job diventato attivo fra il controllo di sopra e
           questo INSERT. Nel primo caso si restituisce quel job. */
        mysqli_rollback($conn);
        if ($req !== null) {
            $gia = sec_one("SELECT id FROM ai_jobs WHERE user_id = ? AND request_id = ?", [$user_id, $req], 'is');
            if ($gia) return ['ok' => true, 'job' => ai_job_stato(ai_job_leggi((int)$gia['id'], $user_id))];
        }
        return ['ok' => false, 'error' => 'job_attivo', 'http' => 409];
    }
    $job_id = sec_insert_id();

    if ($passi_concetti) ai_job_aggiungi_passi($job_id, 'concepts', $passi_concetti);
    if ($passi_carte)    ai_job_aggiungi_passi($job_id, 'cards', $passi_carte);

    mysqli_commit($conn);
    return ['ok' => true, 'job' => ai_job_stato(ai_job_leggi($job_id, $user_id))];
}

/** Accoda passi in fondo al job e aggiorna il totale. */
function ai_job_aggiungi_passi(int $job_id, string $tipo, array $ingressi): void {
    $r = sec_one("SELECT COALESCE(MAX(seq), 0) AS s FROM ai_job_steps WHERE job_id = ?", [$job_id], 'i');
    $seq = (int)($r['s'] ?? 0);
    foreach ($ingressi as $input) {
        $seq++;
        sec_q("INSERT INTO ai_job_steps (job_id, seq, kind, input) VALUES (?, ?, ?, ?)",
              [$job_id, $seq, $tipo, json_encode($input, JSON_UNESCAPED_UNICODE)], 'iiss');
    }
    sec_q("UPDATE ai_jobs SET steps_total = steps_total + ? WHERE id = ?", [count($ingressi), $job_id], 'ii');
}

/**
 * Un elenco di concetti arrivato dal client (partenza da elenco rivisto, o
 * conferma della revisione). Ogni campo passa da un validatore; le ancore
 * devono avere la forma [b, a] o vengono scartate. Un elenco vuoto torna [].
 */
function ai_job_concetti_da_client($lista): array {
    if (!is_array($lista)) return [];
    $out = [];
    foreach ($lista as $c) {
        if (!is_array($c)) continue;
        $t = fc_ai_riga($c['t'] ?? '', 300);
        if ($t === '') continue;
        $ancore = [];
        foreach ((array)($c['ancore'] ?? []) as $anc) {
            /* Due forme: [b, a] dal motore (il blocco e' noto), stringa semplice
               dal pannello, che i blocchi non li conosce e ha l'abitudine di
               tenere le ancore di un concetto fuso come elenco di testi. Nel
               secondo caso il blocco si cerca dopo, in ai_job_lotti_carte. */
            if (is_string($anc)) $anc = ['b' => -1, 'a' => $anc];
            if (!is_array($anc)) continue;
            $a = fc_ai_riga($anc['a'] ?? '', 300);
            if ($a !== '') $ancore[] = ['b' => sec_int($anc['b'] ?? -1, -1, 10000, -1), 'a' => $a];
            if (count($ancore) >= 6) break;
        }
        // compatibilita' con l'elenco del pannello di oggi, che ha una sola ancora in "a"
        if (!$ancore && !empty($c['a'])) $ancore[] = ['b' => -1, 'a' => fc_ai_riga($c['a'], 300)];
        $p = isset($c['p']) && $c['p'] !== null ? sec_int($c['p'], -1, AI_JOB_MAX_CONCETTI, -1) : -1;
        $out[] = ['t' => $t, 'e' => sec_bool($c['e'] ?? false) ? 1 : 0, 'p' => $p >= 0 ? $p : null, 'ancore' => $ancore];
        if (count($out) >= AI_JOB_MAX_CONCETTI) break;
    }
    return $out ? fc_ai_appiana_gerarchia($out) : [];
}

/**
 * I lotti di carte per un elenco di concetti. Ogni lotto porta gia' il suo
 * estratto: da quel momento il passo non ha piu' bisogno del documento.
 *
 * Un'ancora con blocco -1 (arrivata dal pannello, che non conosce i blocchi)
 * si cerca in tutti i blocchi e prende il primo in cui compare.
 */
function ai_job_lotti_carte(array $concetti, array $blocchi, array $opz): array {
    $cache = [];
    if ($blocchi) {
        foreach ($concetti as $k => $c) {
            foreach ($c['ancore'] as $j => $anc) {
                if ($anc['b'] >= 0) continue;
                $concetti[$k]['ancore'][$j]['b'] = -1;
                foreach ($blocchi as $b => $testo) {
                    if (!isset($cache[$b])) $cache[$b] = ai_job_indice($testo);
                    if (ai_job_trova_ancora($cache[$b], $anc['a']) !== -1) { $concetti[$k]['ancore'][$j]['b'] = $b; break; }
                }
            }
        }
    }

    $lotti = [];
    foreach (array_chunk($concetti, ai_job_lotto_per(count($concetti)), true) as $gruppo) {
        $voci = [];
        foreach ($gruppo as $i => $c) {
            /* Un figlio riceve l'esempio solo se l'utente lo ha chiesto: stessa
               regola di generaTutte() nel client, fatta rispettare in codice
               perche' la gerarchia qui la conosciamo con certezza. */
            $ha_padre = $c['p'] !== null;
            $esempio = ($opz['esempi'] && $c['e'] && ($opz['esempi_figli'] || !$ha_padre)) ? 1 : 0;
            $voci[] = ['i' => $i, 't' => $c['t'], 'e' => $esempio, 'p' => $c['p']];
        }
        $lotti[] = [
            'concetti' => $voci,
            'estratto' => $blocchi ? ai_job_estratto(array_values($gruppo), $blocchi, $cache) : '',
        ];
    }
    return $lotti;
}

/* ============================================================
   6. PRENDERE UN PASSO
   ============================================================ */

/**
 * Il prossimo passo da eseguire, gia' preso: nessun altro lo eseguira'
 * finche' non viene rilasciato o non scade. null se non ce n'e' o se un
 * altro esecutore l'ha preso un istante prima.
 */
function ai_job_prendi_passo(int $job_id): ?array {
    // prima si recuperano i passi rimasti in mano a un esecutore morto a meta'
    sec_q("UPDATE ai_job_steps SET status = 'todo', claim_token = NULL
            WHERE job_id = ? AND status = 'running' AND claimed_at < (NOW() - INTERVAL ? SECOND)",
          [$job_id, AI_JOB_PRESA_SCADE], 'ii');

    $p = sec_one("SELECT id FROM ai_job_steps WHERE job_id = ? AND status = 'todo' ORDER BY seq LIMIT 1", [$job_id], 'i');
    if (!$p) return null;

    /* La presa vera: passa a running SOLO se e' ancora todo. Se due
       esecutori arrivano insieme, l'UPDATE riesce a uno solo (affected = 1),
       e l'altro torna a mani vuote invece di eseguire lo stesso passo. */
    $gettone = bin2hex(random_bytes(8));
    sec_q("UPDATE ai_job_steps
              SET status = 'running', claimed_at = NOW(), claim_token = ?, attempts = attempts + 1
            WHERE id = ? AND status = 'todo'",
          [$gettone, (int)$p['id']], 'si');
    if (sec_affected() !== 1) return null;

    $passo = sec_one("SELECT * FROM ai_job_steps WHERE id = ? AND claim_token = ?", [(int)$p['id'], $gettone], 'is');
    if (!$passo) return null;
    $passo['ingresso'] = json_decode((string)$passo['input'], true) ?: [];
    return $passo;
}

/** Restituisce un passo alla coda senza averlo eseguito (crediti finiti, tempo scaduto). */
function ai_job_rilascia_passo(array $passo): void {
    sec_q("UPDATE ai_job_steps
              SET status = 'todo', claim_token = NULL, attempts = GREATEST(0, attempts - 1)
            WHERE id = ? AND claim_token = ?",
          [(int)$passo['id'], $passo['claim_token']], 'is');
}

/* ============================================================
   7. AVANZARE
   ============================================================ */

/**
 * Esegue UN passo del job e torna il suo stato aggiornato.
 *
 * $user_id: con un valore, il job deve essere suo (chiamata dal browser); a
 * null, lo prende per quello che e' (il cron del VPS, che non ha una
 * sessione e lavora per tutti). null come risultato = job inesistente o non
 * suo.
 *
 * Chi chiama deve aver fissato il tempo disponibile con
 * fc_ai_scadenza_imposta(): da quello dipende se un passo si puo' iniziare.
 */
function ai_job_avanza(int $job_id, ?int $user_id = null, int $dopo = 0): ?array {
    if (random_int(1, 50) === 1) ai_job_pulizia();

    $job = ai_job_leggi($job_id, $user_id);
    if (!$job) return null;
    if (!in_array($job['status'], ['queued', 'running'], true)) return ai_job_stato($job, $dopo);

    // al millisecondo: il lavoratore sceglie il job fermo da piu' tempo, e al secondo due job pareggerebbero spesso
    sec_q("UPDATE ai_jobs SET last_tick_at = NOW(3), status = 'running' WHERE id = ? AND status IN ('queued', 'running')",
          [$job_id], 'i');
    $job['status'] = 'running';

    if (fc_ai_tempo_rimasto() < AI_JOB_TEMPO_MINIMO) return ai_job_stato($job, $dopo);

    $passo = ai_job_prendi_passo($job_id);
    if (!$passo) {
        ai_job_chiudi_fase($job_id);
        return ai_job_stato(ai_job_leggi($job_id), $dopo);
    }

    $opz = $job['opzioni'];
    $uid = (int)$job['user_id'];

    if ((int)$passo['attempts'] > AI_JOB_MAX_TENTATIVI) {
        ai_job_passo_fallito($job, $passo, ['error' => 'tentativi', 'riprovabile' => false,
                                           'token' => fc_ai_token_nuovo(), 'modello' => FC_AI_MODELLO], 0);
        ai_job_chiudi_fase($job_id);
        return ai_job_stato(ai_job_leggi($job_id), $dopo);
    }

    // i crediti per QUESTO passo, non per il resto del job
    if (!ai_crediti_bastano($uid, ai_job_stima_passo($passo['ingresso'], $passo['kind'], $opz))) {
        ai_job_rilascia_passo($passo);
        sec_q("UPDATE ai_jobs SET status = 'paused', pause_reason = 'credits' WHERE id = ?", [$job_id], 'i');
        return ai_job_stato(ai_job_leggi($job_id), $dopo);
    }

    /* Il tetto di tutto il sito, controllato solo qui, un attimo prima di
       chiamare l'IA: conta i passi eseguiti, non i giri di aggiornamento
       della pagina (che senza lavoro da fare non costano niente). Chi lo
       trova pieno non riceve un errore: il passo torna in coda e lo stato
       dice "in_attesa", cosi' la pagina scrive "il server sta elaborando
       altre richieste" invece di sembrare bloccata. */
    if (!sec_rate_limit('ai_job_passo', 'sito', AI_JOB_PASSI_MINUTO, 60)) {
        ai_job_rilascia_passo($passo);
        return ai_job_stato($job, $dopo, ['in_attesa' => true]);
    }

    $t0 = microtime(true);
    $esito = ai_job_esegui($passo, $opz);
    $durata = (int)round((microtime(true) - $t0) * 1000);

    if ($esito['ok']) ai_job_passo_riuscito($job, $passo, $esito, $durata);
    else              ai_job_passo_fallito($job, $passo, $esito, $durata);

    ai_job_chiudi_fase($job_id);
    return ai_job_stato(ai_job_leggi($job_id), $dopo);
}

/** Il lavoro vero di un passo: la stessa funzione che userebbe fc_ai.php. */
function ai_job_esegui(array $passo, array $opz): array {
    $in = $passo['ingresso'];
    $lingua = $opz['lingua'] ?? 'italiano';

    if ($passo['kind'] === 'concepts') {
        return fc_ai_azione_concetti([
            'fonte'       => $opz['fonte'],
            'testo'       => $in['testo'] ?? '',
            'titolo'      => $in['titolo'] ?? '',
            'elenco'      => $in['elenco'] ?? [],
            'indicazioni' => $opz['indicazioni'],
            'opzioni'     => $opz,
        ], $lingua);
    }

    return fc_ai_azione_carte([
        'concetti'    => $in['concetti'] ?? [],
        'estratto'    => $in['estratto'] ?? '',
        'nota'        => $in['nota'] ?? '',
        'indicazioni' => $opz['indicazioni'],
        'opzioni'     => $opz,
    ], $lingua);
}

function ai_job_passo_riuscito(array $job, array $passo, array $esito, int $durata): void {
    $job_id = (int)$job['id'];
    $crediti = ai_crediti_da_token($esito['token']['utili'], $esito['modello']);

    /* Prima l'addebito, poi il passo. Nell'ordine inverso, un esecutore che
       muore fra le due scritture lascerebbe un passo fatto e non pagato; cosi'
       lascia un passo pagato ma ancora "running", che scade, viene rifatto, e
       al secondo addebito il libro mastro risponde "gia' registrato". L'utente
       paga una volta sola in entrambi i casi; noi, nel caso raro, due. */
    $addebitato = ai_crediti_addebita((int)$job['user_id'], $crediti, [
        'job_id' => $job_id, 'step_id' => (int)$passo['id'],
        'model' => $esito['modello'], 'provider_tokens' => $esito['token']['totali'],
        'note' => fc_ai_stile_firma($job['opzioni']['stile'] ?? fc_ai_stile_default(), $job['opzioni']['impegno'] ?? 'medio'),
    ]);

    sec_q("UPDATE ai_job_steps
              SET status = 'done', output = ?, credits = ?, provider_tokens = provider_tokens + ?,
                  model = ?, duration_ms = ?, last_error = NULL, claim_token = NULL, finished_at = NOW()
            WHERE id = ? AND claim_token = ?",
          [json_encode($esito['dati'], JSON_UNESCAPED_UNICODE), $crediti, $esito['token']['totali'],
           $esito['modello'], $durata, (int)$passo['id'], $passo['claim_token']],
          'siisiis');

    sec_q("UPDATE ai_jobs
              SET steps_done = steps_done + 1, credits_used = credits_used + ?,
                  provider_tokens = provider_tokens + ?, consecutive_failures = 0
            WHERE id = ?",
          [$addebitato ? $crediti : 0, $esito['token']['totali'], $job_id], 'iii');
}

/**
 * Divide un passo in due passi nuovi in fondo alla coda. Torna false se non
 * si puo' dividere (un lotto di una carta sola, un blocco gia' piccolo): in
 * quel caso il passo segue la strada normale dei fallimenti.
 *
 * Un blocco di documento diviso prende due numeri di blocco nuovi, perche'
 * le ancore dei concetti che ne usciranno devono puntare al testo giusto.
 */
function ai_job_dividi_passo(int $job_id, array $passo): bool {
    $in = $passo['ingresso'];

    if ($passo['kind'] === 'cards') {
        $concetti = (array)($in['concetti'] ?? []);
        if (count($concetti) < 2) return false;
        $meta = (int)ceil(count($concetti) / 2);
        $estratto = (string)($in['estratto'] ?? '');
        ai_job_aggiungi_passi($job_id, 'cards', [
            ['concetti' => array_slice($concetti, 0, $meta), 'estratto' => $estratto],
            ['concetti' => array_slice($concetti, $meta), 'estratto' => $estratto],
        ]);
        return true;
    }

    $testo = (string)($in['testo'] ?? '');
    $lung = mb_strlen($testo, 'UTF-8');
    if ($lung < 8000) return false; // un blocco gia' piccolo non e' lento per colpa della lunghezza
    $meta = ai_job_dividi($testo, (int)ceil($lung / 2) + 200);
    if (count($meta) < 2) return false;

    $r = sec_one("SELECT MAX(CAST(JSON_UNQUOTE(JSON_EXTRACT(input, '$.b')) AS UNSIGNED)) AS m
                    FROM ai_job_steps WHERE job_id = ? AND kind = 'concepts'", [$job_id], 'i');
    $b = (int)($r['m'] ?? 0);
    $nuovi = [];
    foreach ($meta as $pezzo) $nuovi[] = ['b' => ++$b, 'testo' => $pezzo];
    ai_job_aggiungi_passi($job_id, 'concepts', $nuovi);
    return true;
}

function ai_job_passo_fallito(array $job, array $passo, array $esito, int $durata): void {
    $job_id = (int)$job['id'];
    $errore = mb_substr((string)$esito['error'], 0, 40);
    $totali = (int)($esito['token']['totali'] ?? 0);

    /* Un passo troppo grande per il tempo o per la lunghezza massima della
       risposta: ripeterlo uguale fallirebbe di nuovo. Lo si divide in due
       passi nuovi in fondo alla coda, e questo si chiude senza contare come
       fallimento — non e' colpa di nessuno, era solo troppo grande.

       E' anche cio' che adatta il motore all'hosting senza doverlo tarare a
       mano: se il tempo massimo di PHP e' stretto, i lotti che non ci stanno
       si dimezzano da soli finche' ci stanno. */
    if (in_array($errore, ['ia_troncata', 'ia_lento'], true) && ai_job_dividi_passo($job_id, $passo)) {
        sec_q("UPDATE ai_job_steps
                  SET status = 'done', output = ?, provider_tokens = provider_tokens + ?, last_error = ?,
                      duration_ms = ?, claim_token = NULL, finished_at = NOW()
                WHERE id = ? AND claim_token = ?",
              [json_encode(['carte' => [], 'mancanti' => [], 'concetti' => [], 'diviso' => true]),
               $totali, $errore, $durata, (int)$passo['id'], $passo['claim_token']], 'sisiis');
        sec_q("UPDATE ai_jobs SET steps_done = steps_done + 1, provider_tokens = provider_tokens + ? WHERE id = ?",
              [$totali, $job_id], 'ii');
        return;
    }

    $definitivo = !$esito['riprovabile'] || (int)$passo['attempts'] >= AI_JOB_MAX_TENTATIVI;

    if ($definitivo) {
        sec_q("UPDATE ai_job_steps
                  SET status = 'failed', provider_tokens = provider_tokens + ?, last_error = ?,
                      duration_ms = ?, claim_token = NULL, finished_at = NOW()
                WHERE id = ? AND claim_token = ?",
              [$totali, $errore, $durata, (int)$passo['id'], $passo['claim_token']], 'isiis');
    } else {
        // torna in coda: lo riprendera' il giro successivo
        sec_q("UPDATE ai_job_steps
                  SET status = 'todo', provider_tokens = provider_tokens + ?, last_error = ?, claim_token = NULL
                WHERE id = ? AND claim_token = ?",
              [$totali, $errore, (int)$passo['id'], $passo['claim_token']], 'isis');
    }

    sec_q("UPDATE ai_jobs
              SET steps_failed = steps_failed + ?, provider_tokens = provider_tokens + ?,
                  consecutive_failures = consecutive_failures + 1
            WHERE id = ?",
          [$definitivo ? 1 : 0, $totali, $job_id], 'iii');

    /* IL CIRCUIT BREAKER. Cinque fallimenti di fila, su passi diversi o sullo
       stesso, non sono sfortuna: e' una chiave scaduta, un fornitore giu', un
       bug nostro. Si ferma il job e lo si scrive nel registro di sicurezza,
       che e' dove il sito annota gia' gli eventi da capire dopo. L'utente
       non ha pagato niente per questi tentativi: si addebita solo il
       riuscito. */
    $r = sec_one("SELECT consecutive_failures FROM ai_jobs WHERE id = ?", [$job_id], 'i');
    if ($r && (int)$r['consecutive_failures'] >= AI_JOB_BREAKER) {
        sec_q("UPDATE ai_jobs SET status = 'paused', pause_reason = 'breaker' WHERE id = ? AND status = 'running'",
              [$job_id], 'i');
        sec_audit('ai_job_breaker', 'error', "job $job_id, ultimo errore: $errore");
    }
}

/* ============================================================
   8. FINE DI UNA FASE
   ============================================================ */

/**
 * Quando non restano passi da fare: se era finita l'estrazione dei concetti
 * si preparano le carte (o ci si ferma per la revisione), altrimenti il job
 * e' finito.
 */
function ai_job_chiudi_fase(int $job_id): void {
    $job = ai_job_leggi($job_id);
    if (!$job || $job['status'] !== 'running') return;

    $c = sec_one(
        "SELECT SUM(status IN ('todo','running')) AS aperti,
                SUM(kind = 'cards') AS carte,
                SUM(kind = 'concepts' AND status = 'done') AS concetti_fatti,
                SUM(status = 'done') AS fatti
           FROM ai_job_steps WHERE job_id = ?",
        [$job_id], 'i'
    );
    if ((int)($c['aperti'] ?? 0) > 0) return;

    // finita l'estrazione, carte ancora da preparare
    if ((int)($c['carte'] ?? 0) === 0 && (int)($c['concetti_fatti'] ?? 0) > 0) {
        $concetti = ai_job_concetti_uniti($job_id);
        if (!$concetti) {
            sec_q("UPDATE ai_jobs SET status = 'failed', finished_at = NOW() WHERE id = ?", [$job_id], 'i');
            return;
        }
        if (!empty($job['opzioni']['revisione'])) {
            sec_q("UPDATE ai_jobs SET status = 'paused', pause_reason = 'review' WHERE id = ?", [$job_id], 'i');
            return;
        }
        ai_job_prepara_carte($job, $concetti);
        return;
    }

    $esito = (int)($c['fatti'] ?? 0) > 0 ? 'done' : 'failed';
    sec_q("UPDATE ai_jobs SET status = ?, finished_at = NOW() WHERE id = ? AND status = 'running'",
          [$esito, $job_id], 'si');
}

/**
 * L'elenco unico dei concetti, dai passi di estrazione, in ordine di blocco.
 *
 * Due blocchi possono trovare lo stesso concetto (la fine di un capitolo e
 * l'inizio del successivo): si tiene il primo, e il secondo gli aggiunge la
 * propria ancora, cosi' la carta ricevera' il contesto di entrambi i punti.
 *
 * La gerarchia arriva da ogni blocco con indici locali: qui diventano
 * indici dell'elenco unico. Un figlio il cui padre era un doppione punta al
 * concetto tenuto, che e' lo stesso concetto.
 */
function ai_job_concetti_uniti(int $job_id): array {
    $righe = sec_all("SELECT input, output FROM ai_job_steps
                       WHERE job_id = ? AND kind = 'concepts' AND status = 'done' ORDER BY seq", [$job_id], 'i');
    $tutti = [];
    $visti = [];
    foreach ($righe as $r) {
        $in = json_decode((string)$r['input'], true) ?: [];
        $out = json_decode((string)$r['output'], true) ?: [];
        $b = (int)($in['b'] ?? 0);
        $lista = (array)($out['concetti'] ?? []);

        $mappa = [];      // indice locale -> indice unico
        $nuovi = [];      // indici unici nati da questo blocco, con il padre locale
        foreach ($lista as $li => $c) {
            $k = fc_ai_chiave((string)($c['t'] ?? ''));
            if ($k === '') continue;
            $anc = (string)($c['a'] ?? '');
            if (isset($visti[$k])) {
                $mappa[$li] = $visti[$k];
                if ($anc !== '') $tutti[$visti[$k]]['ancore'][] = ['b' => $b, 'a' => $anc];
                continue;
            }
            $idx = count($tutti);
            $tutti[] = ['t' => (string)$c['t'], 'e' => !empty($c['e']) ? 1 : 0, 'p' => null,
                        'ancore' => $anc !== '' ? [['b' => $b, 'a' => $anc]] : []];
            $visti[$k] = $idx;
            $mappa[$li] = $idx;
            $nuovi[$idx] = $c['p'] ?? null;
        }
        // i padri si risolvono dopo aver mappato tutto il blocco: un padre puo' venire dopo il figlio
        foreach ($nuovi as $idx => $p_locale) {
            if ($p_locale !== null && isset($mappa[(int)$p_locale]) && $mappa[(int)$p_locale] !== $idx) {
                $tutti[$idx]['p'] = $mappa[(int)$p_locale];
            }
        }
        if (count($tutti) >= AI_JOB_MAX_CONCETTI) break;
    }
    return $tutti ? fc_ai_appiana_gerarchia(array_slice($tutti, 0, AI_JOB_MAX_CONCETTI)) : [];
}

/** I testi dei blocchi, dai passi di estrazione. Servono solo per gli estratti. */
function ai_job_blocchi(int $job_id): array {
    $blocchi = [];
    foreach (sec_all("SELECT input FROM ai_job_steps WHERE job_id = ? AND kind = 'concepts' ORDER BY seq", [$job_id], 'i') as $r) {
        $in = json_decode((string)$r['input'], true) ?: [];
        if (isset($in['testo']) && $in['testo'] !== '') $blocchi[(int)($in['b'] ?? 0)] = (string)$in['testo'];
    }
    return $blocchi;
}

/**
 * Prepara i lotti di carte e rimette il job in corsa. Il preventivo del job
 * si aggiorna con i numeri veri: ora i concetti si sanno.
 */
function ai_job_prepara_carte(array $job, array $concetti): void {
    $job_id = (int)$job['id'];
    $lotti = ai_job_lotti_carte($concetti, ai_job_blocchi($job_id), $job['opzioni']);
    $stima = 0;
    foreach ($lotti as $l) $stima += ai_job_stima_passo($l, 'cards', $job['opzioni']);

    ai_job_aggiungi_passi($job_id, 'cards', $lotti);
    sec_q("UPDATE ai_jobs SET credits_estimated = credits_used + ?, status = 'running', pause_reason = NULL WHERE id = ?",
          [$stima, $job_id], 'ii');
}

/* ============================================================
   9. LO STATO, PER LA PAGINA
   ============================================================ */

/**
 * Quello che la pagina deve sapere a ogni giro.
 *
 * $dopo: l'id dell'ultimo passo di carte che la pagina ha gia' ricevuto. Si
 * mandano solo le carte dei passi successivi, cosi' ogni giro pesa poche
 * righe anche a job quasi finito, e chi riapre la pagina con $dopo = 0
 * riceve tutto da capo.
 */
function ai_job_stato(?array $job, int $dopo = 0, array $extra = []): ?array {
    if (!$job) return null;
    $job_id = (int)$job['id'];

    $s = [
        'id'            => $job_id,
        'stato'         => $job['status'],
        'motivo_pausa'  => $job['pause_reason'],
        'passi_totali'  => (int)$job['steps_total'],
        'passi_fatti'   => (int)$job['steps_done'],
        'passi_falliti' => (int)$job['steps_failed'],
        'crediti_stimati' => (int)$job['credits_estimated'],
        'crediti_usati'   => (int)$job['credits_used'],
        'contesto_id'   => (int)$job['context_id'],
    ];
    /* Il nome del mazzo serve alla pagina che trova un job vivo su un ALTRO
       mazzo ("generazione in corso su Analisi 2 — aprilo"). Si legge con il
       filtro per utente: un id di mazzo non e' un'informazione da restituire
       senza sapere di chi e'. */
    $m = sec_one("SELECT name FROM fc_categories WHERE id = ? AND user_id = ?",
                 [(int)$job['context_id'], (int)$job['user_id']], 'ii');
    $s['contesto_nome'] = $m ? (string)$m['name'] : '';

    $fasi = sec_one("SELECT SUM(kind = 'cards') AS carte FROM ai_job_steps WHERE job_id = ?", [$job_id], 'i');
    $s['fase'] = (int)($fasi['carte'] ?? 0) > 0 ? 'carte' : 'concetti';

    // le carte nuove dall'ultimo giro
    $carte = [];
    $mancanti = [];
    $cursore = $dopo;
    foreach (sec_all("SELECT id, output FROM ai_job_steps
                       WHERE job_id = ? AND kind = 'cards' AND status = 'done' AND id > ? ORDER BY id",
                     [$job_id, $dopo], 'ii') as $r) {
        $out = json_decode((string)$r['output'], true) ?: [];
        foreach ((array)($out['carte'] ?? []) as $c) $carte[] = $c;
        foreach ((array)($out['mancanti'] ?? []) as $m) $mancanti[] = (int)$m;
        $cursore = max($cursore, (int)$r['id']);
    }
    $s['carte'] = $carte;
    $s['mancanti'] = $mancanti;
    $s['cursore'] = $cursore;

    /* L'elenco dei concetti: in revisione, per mostrarlo e farlo modificare;
       in fase carte, solo al primo giro ($dopo = 0), perche' la pagina che si
       riapre deve sapere a quale concetto appartiene ogni carta. */
    if ($job['status'] === 'paused' && $job['pause_reason'] === 'review') {
        $s['concetti'] = ai_job_concetti_uniti($job_id);
    } elseif ($s['fase'] === 'carte' && $dopo === 0) {
        $elenco = [];
        foreach (sec_all("SELECT input FROM ai_job_steps WHERE job_id = ? AND kind = 'cards' ORDER BY seq", [$job_id], 'i') as $r) {
            $in = json_decode((string)$r['input'], true) ?: [];
            foreach ((array)($in['concetti'] ?? []) as $c) $elenco[(int)$c['i']] = $c;
        }
        ksort($elenco);
        $s['concetti'] = array_values($elenco);
    }

    $cr = ai_crediti_stato((int)$job['user_id']);
    $s['crediti_disponibili'] = $cr['disponibili'];

    return array_merge($s, $extra);
}

/* ============================================================
   10. LE AZIONI DELL'UTENTE
   ============================================================ */

/** Annulla: cio' che e' gia' stato generato resta, e resta leggibile. */
function ai_job_annulla(int $job_id, int $user_id): bool {
    sec_q("UPDATE ai_jobs SET status = 'cancelled', finished_at = NOW()
            WHERE id = ? AND user_id = ? AND status IN ('queued', 'running', 'paused')",
          [$job_id, $user_id], 'ii');
    return sec_affected() === 1;
}

/**
 * Riprende un job in pausa per crediti o per il circuit breaker. Per i
 * crediti si ricontrolla che ora bastino almeno per il prossimo passo:
 * altrimenti ripartirebbe per fermarsi al giro successivo.
 */
function ai_job_riprendi(int $job_id, int $user_id): array {
    $job = ai_job_leggi($job_id, $user_id);
    if (!$job || $job['status'] !== 'paused' || $job['pause_reason'] === 'review') {
        return ['ok' => false, 'error' => 'non_riprendibile', 'http' => 409];
    }
    if ($job['pause_reason'] === 'credits') {
        $p = sec_one("SELECT input, kind FROM ai_job_steps WHERE job_id = ? AND status = 'todo' ORDER BY seq LIMIT 1", [$job_id], 'i');
        $stima = $p ? ai_job_stima_passo(json_decode((string)$p['input'], true) ?: [], $p['kind'], $job['opzioni']) : 0;
        if (!ai_crediti_bastano($user_id, $stima)) {
            $s = ai_crediti_stato($user_id);
            return ['ok' => false, 'error' => 'limite_piano', 'http' => 200, 'stima' => $stima, 'disponibili' => $s['disponibili']];
        }
    }
    /* Dopo un guasto, i passi falliti DURANTE il guasto non hanno niente che
       non va: sono caduti perche' il fornitore era giu'. Chi preme "Riprendi"
       a fornitore tornato si aspetta che si completi tutto, non di trovarsi
       alla fine con un secondo pulsante "riprova i mancanti". Si rimettono in
       coda insieme agli altri. */
    if ($job['pause_reason'] === 'breaker') {
        sec_q("UPDATE ai_job_steps SET status = 'todo', attempts = 0, claim_token = NULL, finished_at = NULL
                WHERE job_id = ? AND status = 'failed'", [$job_id], 'i');
        $rimessi = sec_affected();
        if ($rimessi > 0) {
            sec_q("UPDATE ai_jobs SET steps_failed = GREATEST(0, CAST(steps_failed AS SIGNED) - ?) WHERE id = ?",
                  [$rimessi, $job_id], 'ii');
        }
    }
    sec_q("UPDATE ai_jobs SET status = 'running', pause_reason = NULL, consecutive_failures = 0
            WHERE id = ? AND user_id = ? AND status = 'paused'", [$job_id, $user_id], 'ii');
    return ['ok' => true, 'job' => ai_job_stato(ai_job_leggi($job_id, $user_id))];
}

/**
 * Conferma della revisione: l'elenco (eventualmente modificato) diventa la
 * base dei lotti di carte, e il job riparte da solo.
 */
function ai_job_conferma_concetti(int $job_id, int $user_id, $lista): array {
    $job = ai_job_leggi($job_id, $user_id);
    if (!$job || $job['status'] !== 'paused' || $job['pause_reason'] !== 'review') {
        return ['ok' => false, 'error' => 'non_in_revisione', 'http' => 409];
    }
    $concetti = ai_job_concetti_da_client($lista);
    if (!$concetti) return ['ok' => false, 'error' => 'concetti_mancanti', 'http' => 400];

    ai_job_prepara_carte($job, $concetti);

    /* Un avviso, non un blocco: ora che i concetti si sanno, il preventivo e'
       quello vero. Se supera i crediti rimasti, il job parte lo stesso e si
       fermera' in pausa quando finiscono — cio' che avra' fatto resta. */
    $job = ai_job_leggi($job_id, $user_id);
    $cr = ai_crediti_stato($user_id);
    $residuo = max(0, (int)$job['credits_estimated'] - (int)$job['credits_used']);
    $avviso = ($cr['disponibili'] !== null && $residuo > $cr['disponibili']);

    return ['ok' => true, 'job' => ai_job_stato($job, 0, ['avviso_crediti' => $avviso])];
}

/**
 * Riprova cio' che non e' andato: i passi falliti tornano in coda, e i
 * concetti rimasti senza carta nei passi riusciti diventano un passo nuovo.
 * Funziona anche su un job gia' finito, che torna in corsa.
 */
function ai_job_riprova(int $job_id, int $user_id): array {
    $job = ai_job_leggi($job_id, $user_id);
    if (!$job || !in_array($job['status'], ['done', 'failed', 'paused', 'running'], true)
        || ($job['status'] === 'paused' && $job['pause_reason'] === 'review')) {
        return ['ok' => false, 'error' => 'non_riprovabile', 'http' => 409];
    }
    /* Un job finito che torna vivo non deve scontrarsi con un altro job
       attivo dello stesso utente. Lo si controlla PRIMA di toccare i passi:
       dopo, i concetti mancanti sarebbero gia' stati spostati in un passo
       nuovo di un job che non puo' ripartire, e una riprova successiva non
       li troverebbe piu'. */
    if (in_array($job['status'], ['done', 'failed'], true)) {
        $altro = ai_job_attivo($user_id);
        if ($altro && (int)$altro['id'] !== $job_id) {
            return ['ok' => false, 'error' => 'job_attivo', 'http' => 409, 'job_id' => (int)$altro['id']];
        }
    }

    // i concetti scoperti, con l'estratto del loro lotto
    $recupero = [];
    foreach (sec_all("SELECT id, input, output FROM ai_job_steps
                       WHERE job_id = ? AND kind = 'cards' AND status = 'done'", [$job_id], 'i') as $r) {
        $out = json_decode((string)$r['output'], true) ?: [];
        $manca = array_map('intval', (array)($out['mancanti'] ?? []));
        if (!$manca) continue;
        $in = json_decode((string)$r['input'], true) ?: [];
        $voci = array_values(array_filter((array)($in['concetti'] ?? []), function ($c) use ($manca) {
            return in_array((int)$c['i'], $manca, true);
        }));
        if ($voci) $recupero[] = ['concetti' => $voci, 'estratto' => (string)($in['estratto'] ?? '')];
        // i mancanti passano al passo nuovo: qui si svuotano, o un'altra riprova li raddoppierebbe
        $out['mancanti'] = [];
        sec_q("UPDATE ai_job_steps SET output = ? WHERE id = ?",
              [json_encode($out, JSON_UNESCAPED_UNICODE), (int)$r['id']], 'si');
    }

    sec_q("UPDATE ai_job_steps SET status = 'todo', attempts = 0, claim_token = NULL, finished_at = NULL
            WHERE job_id = ? AND status = 'failed'", [$job_id], 'i');
    $rimessi = sec_affected();

    if ($recupero) ai_job_aggiungi_passi($job_id, 'cards', $recupero);
    if (!$rimessi && !$recupero) return ['ok' => false, 'error' => 'niente_da_riprovare', 'http' => 409];

    /* Il controllo di sopra lascia una finestra di un istante (un altro job
       creato proprio adesso): il vincolo dello schema la chiude, e qui lo si
       traduce in una risposta invece che in un errore 500. */
    $ok = ai_job_scrivi_o_doppione(
        "UPDATE ai_jobs SET status = 'running', pause_reason = NULL, finished_at = NULL,
                steps_failed = GREATEST(0, CAST(steps_failed AS SIGNED) - ?), consecutive_failures = 0
          WHERE id = ? AND user_id = ?",
        [$rimessi, $job_id, $user_id], 'iii'
    );
    if (!$ok) return ['ok' => false, 'error' => 'job_attivo', 'http' => 409];

    return ['ok' => true, 'job' => ai_job_stato(ai_job_leggi($job_id, $user_id))];
}

/* ============================================================
   11. PULIZIA
   ============================================================
   Lanciata ogni tanto da ai_job_avanza (una volta su cinquanta), come fa
   sec_rate_limit con le sue righe vecchie: nessun cron necessario.

   I job finiti da piu' di 30 giorni si cancellano: i loro passi portano il
   testo del documento, che pesa, e le carte sono gia' state salvate nel
   mazzo. Il libro mastro NON si tocca: le sue righe restano, con job_id a
   NULL (ON DELETE SET NULL nello schema).

   Un job vivo fermo da 45 giorni (in pausa per crediti e mai ripreso, o
   abbandonato) si annulla: tiene occupato l'unico posto di job attivo
   dell'utente, che magari non si ricorda nemmeno di averlo. */

function ai_job_pulizia(): void {
    sec_q("DELETE FROM ai_jobs
            WHERE status IN ('done', 'failed', 'cancelled') AND finished_at < (NOW() - INTERVAL 30 DAY)
            LIMIT 50");
    sec_q("UPDATE ai_jobs SET status = 'cancelled', finished_at = NOW()
            WHERE status IN ('queued', 'running', 'paused') AND updated_at < (NOW() - INTERVAL 45 DAY)
            LIMIT 50");
}
