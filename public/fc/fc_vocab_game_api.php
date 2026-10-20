<?php
/* fc_vocab_game_api.php — CHECKPOINT E RECORD DEL GIOCO (lingue e studio)
 *
 * Con ?course=N lavora sulle lingue, senza sulle flashcard di studio (come
 * tutte le pagine delle flashcard, vedi fc_type.php).
 *
 * GET  ?course=N&action=blocco&game_id=G&da=A&a=B
 *      Il riassunto delle posizioni A..B della partita, per la schermata del
 *      checkpoint: giuste, sbagliate, tempo per carta, le parole indovinate e
 *      quelle sbagliate (con traduzione ed esempio, per il round del boss).
 *      Aggiorna da se' i record "veloce" e "padroneggiate".
 * POST ?course=N  action=combo&game_id=G&valore=V
 *      La serie di risposte giuste appena superata: diventa record se lo e'.
 *
 * Solo partite di lingua dell'utente, nel corso dell'indirizzo.
 */
require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/auth_check_api.php';

ob_start();

$uid = (int)$utente_corrente['id'];
$conn = sec_db();
require_once __DIR__ . '/../../lib/fc/fc_type.php';
fc_avvia($uid, true);
require_once __DIR__ . '/../../lib/fc/fc_vocab_gap.php';
require_once __DIR__ . '/../../lib/fc/fc_vocab_gioco.php';

function fcga_out(array $dati): void {
    ob_clean();
    header('Content-Type: application/json');
    echo json_encode($dati, JSON_UNESCAPED_UNICODE);
    exit;
}

$vocab = fc_is_vocab();
$corso = $vocab ? fc_corso_id() : 0;

$action = sec_enum($_GET['action'] ?? $_POST['action'] ?? null, ['blocco', 'combo'], '');
$game_id = sec_int($_GET['game_id'] ?? $_POST['game_id'] ?? 0, 1);
$partita = sec_one("SELECT id, course_id FROM fc_games WHERE id = ? AND user_id = ? AND kind = ?", [$game_id, $uid, $vocab ? 'vocab' : 'study']);
if (!$partita || ($vocab && (int)$partita['course_id'] !== $corso)) fcga_out(['ok' => false, 'error' => t('fc.non_autorizzato_scheda')]);

/* Un campo di una carta di studio in poche parole, per le pastiglie "prese
   al volo": il documento a blocchi (fc_parser.js) o il vecchio testo. */
function fcga_breve(?string $raw): string {
    $raw = trim((string)$raw);
    $doc = ($raw !== '' && $raw[0] === '{') ? json_decode($raw, true) : null;
    if (is_array($doc) && isset($doc['blocks']) && is_array($doc['blocks'])) {
        $parti = [];
        foreach ($doc['blocks'] as $b) foreach ((array)($b['runs'] ?? []) as $r) $parti[] = is_array($r) ? (string)($r['s'] ?? '') : (string)$r;
        $raw = implode(' ', $parti);
    }
    $t = trim(preg_replace('/\s+/u', ' ', strip_tags($raw)) ?? '');
    return mb_strlen($t) > 36 ? rtrim(mb_substr($t, 0, 34)) . '…' : $t;
}

/* ---------- IL RIASSUNTO DI UN BLOCCO ---------- */
if ($action === 'blocco' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $da = sec_int($_GET['da'] ?? 1, 1);
    $a  = sec_int($_GET['a'] ?? $da, $da);
    if ($a - $da > 500) $a = $da + 500;

    $righe = sec_all("SELECT position, fc_image_id, fc_child_id, user_answer, UNIX_TIMESTAMP(play_date) AS t
                        FROM " . fc_t('plays') . "
                       WHERE game_id = ? AND position BETWEEN ? AND ? AND user_answer IN ('Correct', 'Wrong')
                       ORDER BY play_date ASC, id ASC", [$game_id, $da, $a]);
    $giuste = 0; $sbagliate = 0;
    $chiaviGiuste = []; $chiaviSbagliate = [];
    $tempi = [];
    foreach ($righe as $r) {
        $k = $r['fc_child_id'] !== null ? 'c' . (int)$r['fc_child_id'] : 'p' . (int)$r['fc_image_id'];
        if ($r['user_answer'] === 'Correct') { $giuste++; $chiaviGiuste[$k] = true; }
        else { $sbagliate++; $chiaviSbagliate[$k] = true; }
        if ($r['t'] !== null) $tempi[] = (int)$r['t'];
    }
    $n = $giuste + $sbagliate;

    /* IL TEMPO: si sommano gli intervalli fra una risposta e la successiva,
       ognuno al massimo un minuto — una pausa caffe' non e' lentezza. Si parte
       dall'ultima risposta prima del blocco, se c'e'. */
    $prima = sec_one("SELECT UNIX_TIMESTAMP(MAX(play_date)) AS t FROM " . fc_t('plays') . "
                       WHERE game_id = ? AND position < ? AND user_answer IN ('Correct', 'Wrong')", [$game_id, $da]);
    if ($prima && $prima['t'] !== null) array_unshift($tempi, (int)$prima['t']);
    $secondi = 0;
    for ($i = 1; $i < count($tempi); $i++) $secondi += min(60, max(0, $tempi[$i] - $tempi[$i - 1]));
    $intervalli = count($tempi) - 1;
    $al_carta = $intervalli > 0 ? $secondi / $intervalli : null;

    /* LE PAROLE: le sbagliate (per il boss) con tutto quello che serve a
       giocarle di nuovo; le giuste solo come nome, per i "presi al volo". */
    $carta = function (string $k) use ($vocab): ?array {
        $figlio = $k[0] === 'c'; $id = (int)substr($k, 1);
        if (!$vocab) {
            /* STUDIO: domanda e risposta come sono (le disegna il browser con
               fc_parser.js, formule comprese) e le immagini dei due lati. */
            $r = $figlio
                ? sec_one("SELECT question_desc, answer_desc, question_image, answer_image FROM fc_study_subcards WHERE id_child = ?", [$id])
                : sec_one("SELECT question_desc, answer_desc, question_image, answer_image FROM fc_study_cards WHERE id = ?", [$id]);
            if (!$r) return null;
            $img = function ($f) { $f = trim((string)$f); return $f !== '' ? fc_cartella_immagini() . rawurlencode($f) : ''; };
            return [
                'chiave' => $k,
                'parola' => fcga_breve($r['question_desc']),
                'domanda' => (string)$r['question_desc'], 'risposta' => (string)$r['answer_desc'],
                'img_domanda' => $img($r['question_image']), 'img_risposta' => $img($r['answer_image']),
            ];
        }
        $r = $figlio
            ? sec_one("SELECT word, translation, meaning FROM fc_vocab_subcards WHERE id_child = ?", [$id])
            : sec_one("SELECT word, translation, meaning FROM fc_vocab_cards WHERE id = ?", [$id]);
        if (!$r) return null;
        $ex = sec_one("SELECT example_text, gap_text FROM fc_vocab_examples WHERE " . ($figlio ? 'child_id' : 'parent_id')
                      . " = ? AND example_text IS NOT NULL AND example_text <> '' ORDER BY id LIMIT 1", [$id]);
        $parola = trim((string)$r['word']);
        return [
            'chiave' => $k,
            'parola' => $parola,
            'traduzione' => trim((string)$r['translation']),
            'significato' => trim((string)($r['meaning'] ?? '')),
            'esempio' => $ex ? fcv_lacuna_html(trim((string)$ex['example_text']), $parola, (string)($ex['gap_text'] ?? '')) : '',
        ];
    };
    $sbagliateLista = [];
    foreach (array_keys($chiaviSbagliate) as $k) {
        if (count($sbagliateLista) >= 12) break;
        if ($c = $carta($k)) $sbagliateLista[] = $c;
    }
    $giusteLista = [];
    foreach (array_keys($chiaviGiuste) as $k) {
        if (isset($chiaviSbagliate[$k])) continue;
        if (count($giusteLista) >= 8) break;
        if ($c = $carta($k)) $giusteLista[] = $c['parola'];
    }

    /* I RECORD che si decidono qui */
    $record = [];
    if ($n >= 5 && $al_carta !== null && $giuste / $n >= 0.8) {
        $record['veloce'] = fcv_record_aggiorna($uid, $corso, 'veloce', $al_carta, $game_id, true) + ['valore' => round($al_carta, 1)];
    }
    $padr = fcv_padroneggiate($uid, $corso);
    $record['padroneggiate'] = fcv_record_aggiorna($uid, $corso, 'padroneggiate', $padr, $game_id) + ['valore' => $padr];

    fcga_out([
        'ok' => true,
        'carte' => $n, 'giuste' => $giuste, 'sbagliate' => $sbagliate,
        'precisione' => $n ? round($giuste / $n * 100) : 0,
        'al_carta' => $al_carta !== null ? round($al_carta, 1) : null,
        'secondi' => $secondi,
        'sbagliate_lista' => $sbagliateLista,
        'giuste_lista' => $giusteLista,
        'record' => $record,
    ]);
}

/* ---------- LA SERIE PIU' LUNGA ---------- */
if ($action === 'combo' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    sec_guard_write($uid, 'fc_vocab_records', 0, 120, 60);
    /* Il valore arriva dal browser, che lo sa prima del server (il voto e'
       ancora in viaggio): non puo' comunque superare le risposte date. */
    $serie = fcv_serie_partita($game_id);
    $valore = min(sec_int($_POST['valore'] ?? 0, 0), $serie['risposte']);
    $valore = max($valore, $serie['migliore']);
    if ($valore <= 0) fcga_out(['ok' => true, 'nuovo' => false]);
    fcga_out(['ok' => true, 'valore' => $valore] + fcv_record_aggiorna($uid, $corso, 'combo', $valore, $game_id));
}

fcga_out(['ok' => false, 'error' => t('fc.azione_non_riconosciuta')]);
