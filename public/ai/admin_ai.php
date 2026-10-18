<?php
/**
 * admin_ai.php — la lente su come sta andando l'IA. Non e' per gli utenti:
 * e' la pagina che si guarda una volta al giorno per accorgersi dei problemi
 * prima che li scriva qualcuno.
 *
 * Risponde a quattro domande, in quest'ordine:
 *
 *   1. QUANTO STO SPENDENDO DAVVERO, e quanto ne ho riaddebitato. La
 *      differenza e' cio' che assorbiamo noi (ritentativi, errori del
 *      fornitore): finche' e' qualche punto percentuale e' il prezzo di un
 *      servizio serio, se sale e' il segnale che qualcosa non va.
 *   2. STA FUNZIONANDO? Passi falliti, secondi tentativi, job fermati dal
 *      circuit breaker. Il tasso di fallimento che sale e' il primo segnale
 *      che un prompt o un modello ha un problema, e arriva molto prima della
 *      prima segnalazione di un utente.
 *   3. L'IMPEGNO SERVE? Per ciascun livello: quanto costa, quanto ci mette,
 *      e quanti secondi tentativi richiede. E' il numero che dice se
 *      "Accurato" vale il suo prezzo, e se la taratura di
 *      fc_ai_pensiero() va cambiata.
 *   4. CHI SPENDE. Gli utenti piu' costosi, e i job vivi in questo momento.
 *
 * ACCESSO: livello 10 (sec_require_level). Per darselo:
 *   INSERT INTO user_roles (user_id, role_level) VALUES (<tuo id>, 10)
 *   ON DUPLICATE KEY UPDATE role_level = 10;
 * Con un livello piu' basso la pagina risponde 403 e lo scrive in
 * security_audit_log, come ogni altro permesso negato del sito.
 *
 * I testi non passano da i18n apposta: la leggi tu, non gli utenti, e
 * quaranta chiavi di dizionario per una pagina interna sarebbero peso morto.
 */

require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/auth_check.php';
require_once __DIR__ . '/../../lib/security_core.php';
require_once __DIR__ . '/ai_credits.php';

sec_require_level(10, false);   // false = pagina, non endpoint: risponde in HTML

/* Prezzi di listino, dollari per milione di token, MEDIATI fra ingresso e
   uscita su una chiamata tipica di questo sito (circa 2.000 token dentro e
   1.200 fuori). Servono a trasformare i token in una cifra: e' una stima, non
   la fattura di Google — quella resta la fonte vera. Da aggiornare quando
   cambia un listino, insieme ai pesi in ai_credits.php. */
const AI_PREZZI = [
    'gemini-2.5-flash'      => 1.10,
    'gemini-2.5-flash-lite' => 0.25,
    'gemini-2.5-pro'        => 4.50,
];
/* Sopra questa spesa in un giorno, la pagina lo dice in rosso. Non e' un
   limite: e' la soglia oltre la quale vale la pena guardare cosa sta
   succedendo (un ciclo impazzito, o semplicemente molti utenti nuovi). */
const AI_SOGLIA_GIORNO = 5.00;

$giorni = (int)($_GET['giorni'] ?? 7);
if (!in_array($giorni, [1, 7, 30], true)) $giorni = 7;

function euro_da_token(int $token, string $modello): float {
    return $token / 1000000 * (AI_PREZZI[$modello] ?? AI_PREZZI['gemini-2.5-pro']);
}
function n($x, int $dec = 0): string { return number_format((float)$x, $dec, ',', '.'); }
function soldi(float $d): string { return '$' . number_format($d, 2, ',', '.'); }

/* ---- 1. spesa ---- */
$per_modello = sec_all(
    "SELECT COALESCE(model, 'sconosciuto') AS modello,
            SUM(provider_tokens) AS token, SUM(credits) AS crediti, COUNT(*) AS passi,
            ROUND(AVG(duration_ms)) AS media_ms, MAX(duration_ms) AS max_ms
       FROM ai_job_steps
      WHERE finished_at >= (NOW() - INTERVAL ? DAY)
      GROUP BY modello ORDER BY token DESC", [$giorni], 'i');

$tot_token = array_sum(array_column($per_modello, 'token'));
$tot_costo = 0.0;
foreach ($per_modello as $m) $tot_costo += euro_da_token((int)$m['token'], $m['modello']);

/* Gli addebiti veri (tutte le strade, non solo i job) e i token che abbiamo
   pagato per ottenerli: il rapporto fra i due e' il margine di servizio. */
$addebitati = (int)(sec_one("SELECT COALESCE(SUM(-delta), 0) AS c FROM ai_credit_ledger
                              WHERE reason = 'usage' AND created_at >= (NOW() - INTERVAL ? DAY)", [$giorni], 'i')['c'] ?? 0);
$rimborsati = (int)(sec_one("SELECT COALESCE(SUM(delta), 0) AS c FROM ai_credit_ledger
                              WHERE reason LIKE 'refund%' AND created_at >= (NOW() - INTERVAL ? DAY)", [$giorni], 'i')['c'] ?? 0);

$oggi = sec_all("SELECT COALESCE(model, 'sconosciuto') AS modello, SUM(provider_tokens) AS token
                   FROM ai_job_steps WHERE finished_at >= CURDATE() GROUP BY modello");
$costo_oggi = 0.0;
foreach ($oggi as $o) $costo_oggi += euro_da_token((int)$o['token'], $o['modello']);

/* ---- 2. salute ---- */
$salute = sec_one(
    "SELECT COUNT(*) AS passi,
            SUM(status = 'failed') AS falliti,
            SUM(attempts > 1) AS ritentati,
            SUM(last_error IS NOT NULL AND status = 'done') AS recuperati
       FROM ai_job_steps WHERE created_at >= (NOW() - INTERVAL ? DAY)", [$giorni], 'i');
$errori = sec_all(
    "SELECT last_error AS errore, COUNT(*) AS quanti FROM ai_job_steps
      WHERE last_error IS NOT NULL AND created_at >= (NOW() - INTERVAL ? DAY)
      GROUP BY last_error ORDER BY quanti DESC LIMIT 12", [$giorni], 'i');
$pause = sec_all(
    "SELECT COALESCE(pause_reason, status) AS motivo, COUNT(*) AS quanti FROM ai_jobs
      WHERE status IN ('paused', 'failed') AND updated_at >= (NOW() - INTERVAL ? DAY)
      GROUP BY motivo ORDER BY quanti DESC", [$giorni], 'i');

/* ---- 3. l'impegno serve? ---- */
$per_impegno = sec_all(
    "SELECT COALESCE(JSON_UNQUOTE(JSON_EXTRACT(j.options, '$.impegno')), 'medio') AS impegno,
            COUNT(*) AS passi,
            ROUND(AVG(s.provider_tokens)) AS token_medi,
            ROUND(AVG(s.duration_ms) / 1000, 1) AS secondi,
            ROUND(AVG(s.attempts), 2) AS tentativi_medi,
            ROUND(100 * SUM(s.last_error IS NOT NULL) / COUNT(*), 1) AS perc_inciampi
       FROM ai_job_steps s JOIN ai_jobs j ON j.id = s.job_id
      WHERE s.finished_at >= (NOW() - INTERVAL ? DAY) AND s.kind = 'cards'
      GROUP BY impegno", [$giorni], 'i');
$ordine = array_flip(['basso', 'medio', 'alto', 'massimo']);
usort($per_impegno, fn($a, $b) => ($ordine[$a['impegno']] ?? 9) <=> ($ordine[$b['impegno']] ?? 9));

/* Gli stili piu' usati, dalla firma che il libro mastro salva a ogni
   addebito: dice quali scelte la gente usa davvero, e quali no. */
$stili = sec_all(
    "SELECT note, COUNT(*) AS richieste, SUM(-delta) AS crediti FROM ai_credit_ledger
      WHERE reason = 'usage' AND note IS NOT NULL AND created_at >= (NOW() - INTERVAL ? DAY)
      GROUP BY note ORDER BY crediti DESC LIMIT 15", [$giorni], 'i');

/* ---- 4. chi spende, e cosa sta girando ---- */
$utenti = sec_all(
    "SELECT l.user_id, u.username, SUM(-l.delta) AS crediti, COUNT(DISTINCT l.job_id) AS job,
            MAX(l.created_at) AS ultimo
       FROM ai_credit_ledger l LEFT JOIN users u ON u.id = l.user_id
      WHERE l.reason = 'usage' AND l.created_at >= (NOW() - INTERVAL ? DAY)
      GROUP BY l.user_id, u.username ORDER BY crediti DESC LIMIT 10", [$giorni], 'i');

$attivi = sec_all(
    "SELECT j.id, j.user_id, u.username, j.status, j.pause_reason, j.steps_done, j.steps_total,
            j.credits_used, j.last_tick_at,
            TIMESTAMPDIFF(MINUTE, j.last_tick_at, NOW()) AS fermo_da
       FROM ai_jobs j LEFT JOIN users u ON u.id = j.user_id
      WHERE j.status IN ('queued', 'running', 'paused') ORDER BY j.last_tick_at IS NULL, j.last_tick_at LIMIT 20");
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>IA — come sta andando</title>
    <link rel="stylesheet" href="<?php echo PWA_BASE; ?>ai/admin_ai.css?v=<?php echo @filemtime(__DIR__ . '/admin_ai.css') ?: 1; ?>">
</head>
<body>
<header class="adm-header">
    <h1>IA — come sta andando</h1>
    <nav class="adm-periodo">
        <?php foreach ([1 => 'oggi', 7 => '7 giorni', 30 => '30 giorni'] as $g => $et): ?>
            <a href="?giorni=<?php echo $g; ?>"<?php echo $g === $giorni ? ' class="is-on"' : ''; ?>><?php echo $et; ?></a>
        <?php endforeach; ?>
    </nav>
</header>

<main class="adm-main">

    <section class="adm-riquadri">
        <div class="adm-box">
            <small>speso da noi (stima)</small>
            <b><?php echo soldi($tot_costo); ?></b>
            <span><?php echo n($tot_token); ?> token</span>
        </div>
        <div class="adm-box">
            <small>addebitato agli utenti</small>
            <b><?php echo n($addebitati); ?></b>
            <span>crediti<?php if ($rimborsati) echo ', ' . n($rimborsati) . ' rimborsati'; ?></span>
        </div>
        <div class="adm-box<?php echo $costo_oggi > AI_SOGLIA_GIORNO ? ' is-allarme' : ''; ?>">
            <small>oggi</small>
            <b><?php echo soldi($costo_oggi); ?></b>
            <span>soglia <?php echo soldi(AI_SOGLIA_GIORNO); ?></span>
        </div>
        <?php
        /* Quanta parte dei token pagati NON e' stata addebitata: ritentativi,
           errori del fornitore, secondi giri per formato o stile. E' il costo
           del "non facciamo pagare i nostri inciampi". */
        $non_addebitato = $tot_token > 0 ? max(0, 100 - round($addebitati / $tot_token * 100)) : 0; ?>
        <div class="adm-box<?php echo $non_addebitato > 25 ? ' is-allarme' : ''; ?>">
            <small>assorbito da noi</small>
            <b><?php echo $non_addebitato; ?>%</b>
            <span>dei token pagati</span>
        </div>
    </section>

    <h2>Salute</h2>
    <section class="adm-card">
        <?php
        $passi = (int)($salute['passi'] ?? 0);
        $perc_falliti = $passi ? round(100 * (int)$salute['falliti'] / $passi, 1) : 0;
        $perc_ritentati = $passi ? round(100 * (int)$salute['ritentati'] / $passi, 1) : 0; ?>
        <div class="adm-righe">
            <div><b><?php echo n($passi); ?></b><small>passi eseguiti</small></div>
            <div class="<?php echo $perc_falliti > 5 ? 'is-allarme' : ''; ?>"><b><?php echo n($perc_falliti, 1); ?>%</b><small>falliti del tutto</small></div>
            <div class="<?php echo $perc_ritentati > 20 ? 'is-allarme' : ''; ?>"><b><?php echo n($perc_ritentati, 1); ?>%</b><small>ritentati</small></div>
            <div><b><?php echo n((int)($salute['recuperati'] ?? 0)); ?></b><small>inciampati ma riusciti</small></div>
        </div>
        <?php if ($errori): ?>
            <table class="adm-tab">
                <tr><th>errore</th><th>quante volte</th><th></th></tr>
                <?php $max = max(array_column($errori, 'quanti')); foreach ($errori as $e): ?>
                    <tr>
                        <td><?php echo sec_h($e['errore']); ?></td>
                        <td class="num"><?php echo n($e['quanti']); ?></td>
                        <td class="barra"><i style="width:<?php echo round(100 * $e['quanti'] / $max); ?>%"></i></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
        <?php if ($pause): ?>
            <p class="adm-nota">Job fermati: <?php
                echo sec_h(implode(' · ', array_map(fn($p) => $p['motivo'] . ' ' . $p['quanti'], $pause))); ?></p>
        <?php endif; ?>
        <p class="adm-nota">Un tasso di fallimento che sale, o molti "ia_troncata"/"ia_lento", vogliono dire lotti troppo grandi per questo hosting; molti "ia_filtro" vogliono dire un prompt che urta le regole di Google.</p>
    </section>

    <h2>Costo e resa per modello</h2>
    <section class="adm-card">
        <table class="adm-tab">
            <tr><th>modello</th><th>passi</th><th>token</th><th>costo</th><th>crediti</th><th>media</th><th>max</th></tr>
            <?php foreach ($per_modello as $m): ?>
                <tr>
                    <td><?php echo sec_h($m['modello']); ?></td>
                    <td class="num"><?php echo n($m['passi']); ?></td>
                    <td class="num"><?php echo n($m['token']); ?></td>
                    <td class="num"><?php echo soldi(euro_da_token((int)$m['token'], $m['modello'])); ?></td>
                    <td class="num"><?php echo n($m['crediti']); ?></td>
                    <td class="num"><?php echo n(($m['media_ms'] ?? 0) / 1000, 1); ?>s</td>
                    <td class="num"><?php echo n(($m['max_ms'] ?? 0) / 1000, 1); ?>s</td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$per_modello): ?><tr><td colspan="7" class="adm-niente">nessun passo nel periodo</td></tr><?php endif; ?>
        </table>
    </section>

    <h2>L'impegno serve?</h2>
    <section class="adm-card">
        <table class="adm-tab">
            <tr><th>impegno</th><th>passi</th><th>token medi</th><th>secondi</th><th>tentativi medi</th><th>inciampi</th></tr>
            <?php foreach ($per_impegno as $i): ?>
                <tr>
                    <td><?php echo sec_h($i['impegno']); ?></td>
                    <td class="num"><?php echo n($i['passi']); ?></td>
                    <td class="num"><?php echo n($i['token_medi']); ?></td>
                    <td class="num"><?php echo n($i['secondi'], 1); ?></td>
                    <td class="num"><?php echo n($i['tentativi_medi'], 2); ?></td>
                    <td class="num"><?php echo n($i['perc_inciampi'], 1); ?>%</td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$per_impegno): ?><tr><td colspan="6" class="adm-niente">nessun lotto di carte nel periodo</td></tr><?php endif; ?>
        </table>
        <p class="adm-nota">Se "alto" costa il doppio di "medio" ma ha gli stessi tentativi e gli stessi inciampi, non sta servendo: il budget in fc_ai_pensiero() va abbassato, o la differenza va spiegata meglio all'utente. Se invece "basso" ha molti piu' inciampi, sta costando in secondi giri quello che fa risparmiare in ragionamento.</p>
    </section>

    <h2>Stili piu' usati</h2>
    <section class="adm-card">
        <table class="adm-tab">
            <tr><th>scelte</th><th>richieste</th><th>crediti</th></tr>
            <?php foreach ($stili as $s): ?>
                <tr>
                    <td><?php echo sec_h($s['note']); ?></td>
                    <td class="num"><?php echo n($s['richieste']); ?></td>
                    <td class="num"><?php echo n($s['crediti']); ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$stili): ?><tr><td colspan="3" class="adm-niente">niente nel periodo</td></tr><?php endif; ?>
        </table>
    </section>

    <h2>Chi spende</h2>
    <section class="adm-card">
        <table class="adm-tab">
            <tr><th>utente</th><th>crediti</th><th>job</th><th>ultimo uso</th></tr>
            <?php foreach ($utenti as $u): ?>
                <tr>
                    <td><?php echo sec_h($u['username'] ?? ('#' . $u['user_id'])); ?></td>
                    <td class="num"><?php echo n($u['crediti']); ?></td>
                    <td class="num"><?php echo n($u['job']); ?></td>
                    <td class="num"><?php echo date('j/n H:i', strtotime($u['ultimo'])); ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$utenti): ?><tr><td colspan="4" class="adm-niente">nessun consumo nel periodo</td></tr><?php endif; ?>
        </table>
    </section>

    <h2>Job vivi adesso</h2>
    <section class="adm-card">
        <table class="adm-tab">
            <tr><th>job</th><th>utente</th><th>stato</th><th>avanzamento</th><th>crediti</th><th>fermo da</th></tr>
            <?php foreach ($attivi as $a):
                /* "Fermo da" e' il numero da guardare: un job in corso che non
                   avanza da mezz'ora vuol dire che nessuno lo sta facendo
                   andare avanti — pagina chiusa su Altervista, o cron morto
                   sul VPS. */
                $fermo = $a['fermo_da'] === null ? null : (int)$a['fermo_da']; ?>
                <tr>
                    <td class="num"><?php echo (int)$a['id']; ?></td>
                    <td><?php echo sec_h($a['username'] ?? ('#' . $a['user_id'])); ?></td>
                    <td><?php echo sec_h($a['status'] . ($a['pause_reason'] ? ' · ' . $a['pause_reason'] : '')); ?></td>
                    <td class="num"><?php echo (int)$a['steps_done']; ?>/<?php echo (int)$a['steps_total']; ?></td>
                    <td class="num"><?php echo n($a['credits_used']); ?></td>
                    <td class="num <?php echo ($fermo !== null && $fermo > 30 && $a['status'] === 'running') ? 'is-allarme' : ''; ?>">
                        <?php echo $fermo === null ? 'mai' : $fermo . ' min'; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$attivi): ?><tr><td colspan="6" class="adm-niente">nessun job vivo</td></tr><?php endif; ?>
        </table>
    </section>

    <p class="adm-nota adm-fondo">I costi sono stime: i token si contano davvero, il prezzo per milione e' mediato fra ingresso e uscita (vedi AI_PREZZI in questo file). La cifra vera resta quella della console di Google, e le due vanno confrontate ogni tanto.</p>
</main>
</body>
</html>
