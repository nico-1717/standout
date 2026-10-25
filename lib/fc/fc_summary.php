<?php
/* ============================================================================
   fc_summary.php — I NUMERI RIASSUNTIVI DELLE FLASHCARD

   Serie di giorni, carte di oggi, settimana, precisione, e i conteggi di
   ciascun tipo. Li usano la pagina principale delle flashcard (fc_home.php),
   la pagina delle lingue (fc_languages.php) e il cruscotto del sito
   (dashboard_content.php): una funzione sola, cosi' i tre posti non possono
   dire numeri diversi. Prima il cruscotto aveva la sua copia delle query, e
   contava le carte di TUTTI gli utenti.

   Le giocate valide sono solo quelle con una risposta (Correct/Wrong): il
   gioco crea in anticipo le righe delle carte successive per caricarle piu'
   in fretta (vedi fc_game_prefetch.md), e quelle righe non sono studio.

   Da includere dopo auth_check.php. Nessuna funzione stampa niente.
   ============================================================================ */

if (defined('FC_SUMMARY_CARICATO')) return;
define('FC_SUMMARY_CARICATO', true);

/* "Imparate" non vuol dire piu' solo "col tastino": anche le carte al livello
   la_sai (giuste le ultime cinque volte). Il conto, con il giorno in cui
   ognuna e' stata imparata, e' fcv_conosciute() in fc_vocab_gioco.php. */
require_once __DIR__ . '/fc_vocab_gioco.php';

/**
 * Serie di giorni consecutivi da un elenco di date 'Y-m-d' ordinate.
 * La serie "attuale" vale solo se l'ultimo giorno e' oggi o ieri: chi ha
 * studiato ieri e non ancora oggi non l'ha persa, ha ancora tempo.
 */
function fc_serie_da_giorni(array $giorni): array {
    $attuale = 0; $record = 0; $corsa = 0; $prima = null;
    foreach ($giorni as $d) {
        $t = strtotime($d . ' 12:00:00');
        /* 12:00 e non mezzanotte: con i cambi d'ora un giorno puo' durare 23 o
           25 ore, e la differenza fra due mezzenotti non sarebbe un multiplo
           esatto di 86400 */
        $corsa = ($prima !== null && (int)round(($t - $prima) / 86400) === 1) ? $corsa + 1 : 1;
        $record = max($record, $corsa);
        $prima = $t;
    }
    if ($prima !== null) {
        $distanza = (int)round((strtotime('today 12:00:00') - $prima) / 86400);
        if ($distanza <= 1) $attuale = $corsa;
    }
    return ['attuale' => $attuale, 'record' => $record];
}

/** L'obiettivo giornaliero dell'utente (carte al giorno). */
function fc_obiettivo(int $uid): int {
    try {
        $r = sec_one("SELECT daily_goal FROM fc_goals WHERE user_id = ?", [$uid], 'i');
        return $r ? max(1, (int)$r['daily_goal']) : 20;
    } catch (Throwable $e) {
        return 20;
    }
}

/** fcv_conosciute una volta sola per richiesta: la usano piu' funzioni qui. */
function fc_conosciute_cache(int $uid, string $tipo): array {
    static $cache = [];
    $k = $uid . ':' . $tipo;
    if (!isset($cache[$k])) $cache[$k] = fcv_conosciute($uid, $tipo);
    return $cache[$k];
}

/**
 * Statistiche dei corsi di lingua dell'utente, una riga per corso, in tre
 * query sole qualunque sia il numero di corsi.
 */
function fc_corsi_con_numeri(int $uid): array {
    $corsi = [];
    foreach (sec_all("SELECT id, known_lang, learn_lang, project_id, custom_order, created_at
                        FROM fc_vocab_courses WHERE user_id = ?
                       ORDER BY custom_order ASC, created_at ASC, id ASC", [$uid], 'i') as $r) {
        $r['parole'] = 0; $r['imparate'] = 0; $r['mazzi'] = 0;
        $r['ultimo'] = null; $r['serie'] = 0; $r['oggi'] = 0;
        $corsi[(int)$r['id']] = $r;
    }
    if (!$corsi) return [];

    foreach (sec_all("SELECT d.course_id, COUNT(DISTINCT d.id) AS mazzi, COUNT(c.id) AS parole
                        FROM fc_vocab_decks d LEFT JOIN fc_vocab_cards c ON c.category_id = d.id
                       WHERE d.user_id = ? AND d.course_id IS NOT NULL GROUP BY d.course_id", [$uid], 'i') as $r) {
        if (isset($corsi[(int)$r['course_id']])) {
            $corsi[(int)$r['course_id']]['parole'] = (int)$r['parole'];
            $corsi[(int)$r['course_id']]['mazzi']  = (int)$r['mazzi'];
        }
    }
    foreach (sec_all("SELECT d.course_id, COUNT(*) AS n
                        FROM fc_vocab_subcards s JOIN fc_vocab_cards c ON c.id = s.parent_id
                        JOIN fc_vocab_decks d ON d.id = c.category_id
                       WHERE d.user_id = ? GROUP BY d.course_id", [$uid], 'i') as $r) {
        if (isset($corsi[(int)$r['course_id']])) $corsi[(int)$r['course_id']]['parole'] += (int)$r['n'];
    }
    /* imparate: col tastino O al livello la_sai (fcv_conosciute) */
    foreach (fc_conosciute_cache($uid, 'vocab')['per_corso'] as $cid => $n) {
        if (isset($corsi[(int)$cid])) $corsi[(int)$cid]['imparate'] = (int)$n;
    }

    /* giorni di studio per corso: servono sia per l'ultimo giorno sia per la
       serie, quindi una lettura sola */
    $giorni = [];
    foreach (sec_all("SELECT g.course_id, DATE(p.play_date) AS d, COUNT(*) AS n
                        FROM fc_vocab_plays p JOIN fc_games g ON g.id = p.game_id
                       WHERE g.user_id = ? AND g.kind = 'vocab' AND p.play_date IS NOT NULL
                         AND p.user_answer IN ('Correct','Wrong')
                       GROUP BY g.course_id, d ORDER BY d ASC", [$uid], 'i') as $r) {
        $giorni[(int)$r['course_id']][] = $r['d'];
        if ($r['d'] === date('Y-m-d') && isset($corsi[(int)$r['course_id']])) $corsi[(int)$r['course_id']]['oggi'] = (int)$r['n'];
    }
    foreach ($giorni as $cid => $lista) {
        if (!isset($corsi[$cid])) continue;
        $corsi[$cid]['ultimo'] = end($lista);
        $corsi[$cid]['serie'] = fc_serie_da_giorni($lista)['attuale'];
    }
    return $corsi;
}

/**
 * Tutto il riepilogo. Ogni chiave e' un numero o un piccolo array:
 *   serie, record, oggi, oggi_study, oggi_vocab, obiettivo, settimana[Y-m-d => n],
 *   precisione (0-100, ultimi 30 giorni), study{mazzi, carte, imparate},
 *   vocab{corsi[], parole, imparate}, ultima (ultima partita o null),
 *   imparate_giorni[Y-m-d => n] (quante imparate quel giorno), imparate_tot
 */
function fc_riepilogo(int $uid): array {
    $oggi = date('Y-m-d');
    $settimana = [];
    for ($i = 6; $i >= 0; $i--) $settimana[date('Y-m-d', strtotime("-$i day"))] = 0;

    /* Un'unica lettura dei giorni di studio di entrambi i tipi: serie,
       settimana e carte di oggi escono tutte da qui. */
    $giorniTutti = []; $oggiStudy = 0; $oggiVocab = 0;
    foreach (sec_all("SELECT t.kind, t.d, COUNT(*) AS n FROM (
                          SELECT 'study' AS kind, DATE(p.play_date) AS d
                            FROM fc_study_plays p JOIN fc_games g ON g.id = p.game_id
                           WHERE g.user_id = ? AND p.play_date IS NOT NULL AND p.user_answer IN ('Correct','Wrong')
                          UNION ALL
                          SELECT 'vocab', DATE(p.play_date)
                            FROM fc_vocab_plays p JOIN fc_games g ON g.id = p.game_id
                           WHERE g.user_id = ? AND p.play_date IS NOT NULL AND p.user_answer IN ('Correct','Wrong')
                      ) t GROUP BY t.kind, t.d ORDER BY t.d ASC", [$uid, $uid], 'ii') as $r) {
        $giorniTutti[$r['d']] = true;
        if (isset($settimana[$r['d']])) $settimana[$r['d']] += (int)$r['n'];
        if ($r['d'] === $oggi) {
            if ($r['kind'] === 'study') $oggiStudy += (int)$r['n']; else $oggiVocab += (int)$r['n'];
        }
    }
    $serie = fc_serie_da_giorni(array_keys($giorniTutti));

    $prec = sec_one("SELECT SUM(t.a = 'Correct') AS ok, COUNT(*) AS tot FROM (
                         SELECT p.user_answer AS a FROM fc_study_plays p JOIN fc_games g ON g.id = p.game_id
                          WHERE g.user_id = ? AND p.play_date >= (CURDATE() - INTERVAL 29 DAY) AND p.user_answer IN ('Correct','Wrong')
                         UNION ALL
                         SELECT p.user_answer FROM fc_vocab_plays p JOIN fc_games g ON g.id = p.game_id
                          WHERE g.user_id = ? AND p.play_date >= (CURDATE() - INTERVAL 29 DAY) AND p.user_answer IN ('Correct','Wrong')
                     ) t", [$uid, $uid], 'ii');
    $precisione = ($prec && (int)$prec['tot'] > 0) ? (int)round((int)$prec['ok'] / (int)$prec['tot'] * 100) : 0;

    /* Studio. Le carte padre di studio sono titoli che raggruppano le figlie:
       si contano le carte "vere", cioe' le figlie e le carte senza figlie
       (stessa regola che usava il cruscotto). */
    $sMazzi = (int)(sec_one("SELECT COUNT(*) AS n FROM fc_study_decks WHERE user_id = ? AND archived = 0", [$uid], 'i')['n'] ?? 0);
    $sCarte = (int)(sec_one("SELECT
                                (SELECT COUNT(*) FROM fc_study_cards c JOIN fc_study_decks d ON d.id = c.category_id
                                  WHERE d.user_id = ? AND c.is_father = 0)
                              + (SELECT COUNT(*) FROM fc_study_subcards s JOIN fc_study_cards c ON c.id = s.parent_id
                                   JOIN fc_study_decks d ON d.id = c.category_id WHERE d.user_id = ?) AS n",
                            [$uid, $uid], 'ii')['n'] ?? 0);
    /* Prima si contavano le carte padre segnate imparate e si dividevano per
       le carte "vere" (figlie e carte singole): due unita' diverse, e la
       percentuale non tornava. Ora le stesse carte sopra e sotto. */
    $sConosciute = fc_conosciute_cache($uid, 'study');
    $sImparate = $sConosciute['totale'];

    $corsi = fc_corsi_con_numeri($uid);
    $vParole = 0; $vImparate = 0;
    foreach ($corsi as $c) { $vParole += $c['parole']; $vImparate += $c['imparate']; }

    /* il giorno in cui ogni carta e' stata imparata, studio e lingue insieme */
    $imparateGiorni = $sConosciute['giorni'];
    foreach (fc_conosciute_cache($uid, 'vocab')['giorni'] as $d => $n) $imparateGiorni[$d] = ($imparateGiorni[$d] ?? 0) + $n;
    ksort($imparateGiorni);

    $ultima = sec_one("SELECT id, kind, course_id, game_date, score FROM fc_games
                        WHERE user_id = ? ORDER BY game_date DESC, id DESC LIMIT 1", [$uid], 'i');

    return [
        'serie' => $serie['attuale'], 'record' => $serie['record'],
        'oggi' => $oggiStudy + $oggiVocab, 'oggi_study' => $oggiStudy, 'oggi_vocab' => $oggiVocab,
        'obiettivo' => fc_obiettivo($uid),
        'settimana' => $settimana,
        'precisione' => $precisione,
        'study' => ['mazzi' => $sMazzi, 'carte' => $sCarte, 'imparate' => $sImparate],
        'vocab' => ['corsi' => array_values($corsi), 'parole' => $vParole, 'imparate' => $vImparate],
        'imparate_giorni' => $imparateGiorni,
        'imparate_tot' => $sImparate + $vImparate,
        'ultima' => $ultima,
    ];
}
