<?php
/**
 * credits.php — "i tuoi crediti": cosa ha speso l'utente con l'IA, e per cosa.
 *
 * Tutto viene da ai_credit_ledger, il libro mastro che il motore scrive gia'
 * (ai_credits.php). Qui non si calcola niente di nuovo: si legge e si
 * raggruppa. E' il motivo per cui il libro mastro esiste — un saldo che
 * scende senza un elenco che lo spieghi e' la cosa che fa scrivere al
 * supporto.
 *
 * Non c'e' JavaScript e non c'e' nessun endpoint: e' una pagina che il server
 * disegna e basta. I mesi si sfogliano con un link (?mese=AAAA-MM), cosi'
 * funziona anche indietro col browser e si puo' mandare a qualcuno.
 *
 * SOLO I PROPRI: ogni query filtra per user_id. Un id di job nell'indirizzo
 * non esiste proprio, quindi non c'e' niente da forzare.
 */

/* Due modi di chiedere questa pagina:
     - a tutta pagina (l'indirizzo si puo' salvare e mandare);
     - come RIQUADRO (?riquadro=1), cioe' solo il contenuto, che il pannello
       delle carte infila in un popup senza far uscire l'utente da quello che
       stava facendo. Andare a un'altra pagina per guardare quanto resta vuol
       dire perdere il posto: l'elenco dei concetti, le impostazioni, il
       lavoro in corso.
   Cambia anche il controllo del login: da una fetch un reindirizzo alla home
   finirebbe DENTRO il popup, e si vedrebbe la home dentro un riquadro. */
require_once __DIR__ . '/../../lib/config.php';
$cred_riquadro = isset($_GET['riquadro']);
if ($cred_riquadro) require_once __DIR__ . '/../../lib/auth_check_api.php';   // risponde JSON, non reindirizza
else                require_once __DIR__ . '/../../lib/auth_check.php';
require_once __DIR__ . '/../../lib/security_core.php';
require_once __DIR__ . '/../../lib/piano_helpers.php';
require_once __DIR__ . '/ai_credits.php';
require_once __DIR__ . '/../../lib/fc/fc_ai_style.php';         // per rileggere la firma dello stile

$uid = (int)$utente_corrente['id'];

/* Il mese da mostrare. Di serie quello in corso; si accettano solo i dodici
   mesi passati, perche' oltre non c'e' niente da vedere e un indirizzo con
   un anno a caso non deve generare una query strana. */
$mese_corrente = ai_crediti_mese();
$mese = $_GET['mese'] ?? $mese_corrente;
if (!preg_match('/^\d{4}-\d{2}$/', (string)$mese)) $mese = substr($mese_corrente, 0, 7);
else $mese = (string)$mese;
$mese_primo = $mese . '-01';
if ($mese_primo > $mese_corrente) $mese_primo = $mese_corrente;
if ($mese_primo < date('Y-m-01', strtotime($mese_corrente . ' -12 months'))) {
    $mese_primo = date('Y-m-01', strtotime($mese_corrente . ' -12 months'));
}
$e_questo_mese = ($mese_primo === $mese_corrente);

$stato = ai_crediti_stato($uid);
$piano = standout_get_piano_utente($uid);

/* ---- le generazioni lunghe (un job = una riga) ---- */
$generazioni = sec_all(
    "SELECT l.job_id,
            SUM(-l.delta) AS crediti,
            COUNT(*) AS passi,
            MIN(l.created_at) AS inizio,
            MAX(l.note) AS nota,
            j.context_id, j.status, j.steps_total, j.steps_done,
            c.name AS mazzo
       FROM ai_credit_ledger l
       LEFT JOIN ai_jobs j ON j.id = l.job_id
       LEFT JOIN fc_study_decks c ON c.id = j.context_id AND c.user_id = l.user_id
      WHERE l.user_id = ? AND l.month = ? AND l.reason = 'usage' AND l.job_id IS NOT NULL
      GROUP BY l.job_id, j.context_id, j.status, j.steps_total, j.steps_done, c.name
      ORDER BY inizio DESC",
    [$uid, $mese_primo], 'is'
);

/* ---- le richieste singole (una carta migliorata, un esempio, i concetti) ----
   Si raggruppano per giorno e per azione: venti esempi in un pomeriggio sono
   una riga con "20 esempi", non venti righe da leggere una per una. */
$singole_righe = sec_all(
    "SELECT DATE(created_at) AS giorno, note, SUM(-delta) AS crediti, COUNT(*) AS quante
       FROM ai_credit_ledger
      WHERE user_id = ? AND month = ? AND reason = 'usage' AND job_id IS NULL
      GROUP BY giorno, note
      ORDER BY giorno DESC",
    [$uid, $mese_primo], 'is'
);
$singole = [];
foreach ($singole_righe as $r) {
    // la nota e' "fc_ai:<azione> <firma dello stile>"
    $azione = 'altro';
    if (preg_match('/^fc_ai:([a-z]+)/', (string)$r['note'], $m)) $azione = $m[1];
    $k = $r['giorno'] . '|' . $azione;
    if (!isset($singole[$k])) $singole[$k] = ['giorno' => $r['giorno'], 'azione' => $azione, 'crediti' => 0, 'quante' => 0];
    $singole[$k]['crediti'] += (int)$r['crediti'];
    $singole[$k]['quante']  += (int)$r['quante'];
}

/* ---- accrediti: rimborsi, bonus, acquisti ---- */
$accrediti = sec_all(
    "SELECT delta, reason, note, created_at FROM ai_credit_ledger
      WHERE user_id = ? AND month = ? AND delta > 0 ORDER BY created_at DESC",
    [$uid, $mese_primo], 'is'
);

$tot_generazioni = array_sum(array_column($generazioni, 'crediti'));
$tot_singole = array_sum(array_column($singole, 'crediti'));
$tot_accrediti = array_sum(array_column($accrediti, 'delta'));

/** La firma dello stile, scritta per una persona: "Accurato · Schema · Informatica". */
function crediti_stile_leggibile(?string $nota): string {
    if (!$nota) return '';
    $voci = [];
    foreach (explode(' ', $nota) as $pezzo) {
        if (strpos($pezzo, '=') === false) continue;
        [$campo, $valore] = explode('=', $pezzo, 2);
        if ($campo === 'impegno') {
            if ($valore !== 'medio') $voci[] = t('fc.ibfai_impegno_' . $valore);
            continue;
        }
        if ($campo === 'voci') { $voci[] = t('fc.cred_voci', ['n' => (int)$valore]); continue; }
        if ($campo === 'modello') { $voci[] = t('fc.cred_carta_modello'); continue; }
        $chiave = 'fc.ibfai_stile_' . $campo . '_' . $valore;
        $testo = t($chiave);
        if ($testo !== $chiave) $voci[] = $testo;   // una chiave sconosciuta non si stampa grezza
    }
    return implode(' · ', $voci);
}

/** Il mese in lettere, senza dipendere da setlocale (che sugli hosting condivisi spesso non c'e'). */
function strftime_it(string $iso): string {
    $t = strtotime($iso);
    return t('fc.cred_mese_anno', ['mese' => t('comune.mese_' . (int)date('n', $t)), 'anno' => date('Y', $t)]);
}

function crediti_data(string $iso): string {
    return date('j/n', strtotime($iso));
}

$titolo = t('fc.cred_titolo');

if ($cred_riquadro) {
    /* Il riquadro non manda intestazioni di pagina: e' un pezzo di HTML che
       finisce dentro un'altra pagina gia' aperta. */
    header('Content-Type: text/html; charset=utf-8');
    include __DIR__ . '/credits_body.php';
    return;
}
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(standout_lingua(), ENT_QUOTES); ?>">
<head>
    <meta name="csrf-token" content="<?php echo sec_h(sec_csrf_token()); ?>">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?php echo sec_h($titolo); ?></title>
    <link rel="stylesheet" href="<?php echo PWA_BASE; ?>ai/credits.css?v=<?php echo @filemtime(__DIR__ . '/credits.css') ?: 1; ?>">
</head>
<body class="cred-pagina">

<header class="app-header">
    <a class="hdr-back" href="../index.php" aria-label="<?php echo sec_h(t('fc.cred_indietro')); ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="m15 6-6 6 6 6"/></svg>
    </a>
    <div class="hdr-text"><h1><?php echo sec_h($titolo); ?></h1></div>
</header>

<?php include __DIR__ . '/credits_body.php'; ?>
</body>
</html>
