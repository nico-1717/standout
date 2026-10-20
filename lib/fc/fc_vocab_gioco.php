<?php
/* fc_vocab_gioco.php — I NUMERI DIETRO IL GIOCO DELLE LINGUE
 *
 * Il livello di ogni parola, la serie di risposte giuste di una partita e i
 * record personali. Li usano fc_game.php (per disegnare la carta) e
 * fc_vocab_game_api.php (checkpoint e record). La parte che si vede e' in
 * public/fc/fc_game_juice.js.
 *
 * Tutto si ricava dalle risposte gia' salvate (fc_vocab_plays): non c'e' un
 * contatore da tenere allineato, quindi non c'e' niente che possa sfasarsi.
 */

if (!function_exists('fcv_livello')) {

/**
 * Il livello di una parola da quante volte e' stata giusta ($c) e sbagliata
 * ($w), e dalle ultime risposte ($ultime: 'C'/'W', dalla piu' vecchia alla
 * piu' recente). Gemella di livello() in fc_game_juice.js: se cambia qui va
 * cambiata anche li'.
 *   nuova          mai giocata
 *   difficile      sbagliata piu' spesso che indovinata, o le ultime due sbagliate
 *   in_corso       tutto il resto
 *   buona          almeno 3 giuste, 70% giuste, l'ultima giusta
 *   padroneggiata  almeno 5 giuste, 85% giuste, le ultime tre giuste
 */
function fcv_livello(int $c, int $w, string $ultime): string {
    $n = $c + $w;
    if ($n === 0) return 'nuova';
    $acc = $c / $n;
    if ($n >= 2 && ($acc < 0.5 || substr($ultime, -2) === 'WW')) return 'difficile';
    if ($c >= 5 && $acc >= 0.85 && substr($ultime, -3) === 'CCC') return 'padroneggiata';
    if ($c >= 3 && $acc >= 0.7 && substr($ultime, -1) === 'C') return 'buona';
    return 'in_corso';
}

/**
 * Le statistiche delle carte date: [chiave => ['c' => giuste, 'w' => sbagliate,
 * 'u' => ultime sei risposte]], con chiave "p<id>" per una carta e "c<id>"
 * per una sottocarta. Una query per tipo, non una per carta.
 */
function fcv_stats_carte(array $padri, array $figli): array {
    $out = [];
    $giri = [['p', 'fc_image_id', array_values(array_unique(array_map('intval', $padri)))],
             ['c', 'fc_child_id', array_values(array_unique(array_map('intval', $figli)))]];
    foreach ($giri as [$pre, $col, $ids]) {
        if (!$ids) continue;
        foreach ($ids as $id) $out[$pre . $id] = ['c' => 0, 'w' => 0, 'u' => ''];
        $ph = implode(',', array_fill(0, count($ids), '?'));
        foreach (sec_all("SELECT $col AS id, user_answer FROM fc_vocab_plays
                          WHERE $col IN ($ph) AND user_answer IN ('Correct', 'Wrong')
                          ORDER BY play_date ASC, id ASC", $ids) as $r) {
            $k = $pre . (int)$r['id'];
            $giusta = $r['user_answer'] === 'Correct';
            $out[$k][$giusta ? 'c' : 'w']++;
            $out[$k]['u'] = substr($out[$k]['u'] . ($giusta ? 'C' : 'W'), -6);
        }
    }
    return $out;
}

/**
 * La serie di una partita: quante risposte giuste di fila adesso, la serie
 * piu' lunga della partita e quante risposte in tutto.
 */
function fcv_serie_partita(int $game_id): array {
    $ora = 0; $max = 0; $n = 0;
    foreach (sec_all("SELECT user_answer FROM fc_vocab_plays
                      WHERE game_id = ? AND user_answer IN ('Correct', 'Wrong')
                      ORDER BY play_date ASC, id ASC", [$game_id]) as $r) {
        $n++;
        if ($r['user_answer'] === 'Correct') { $ora++; if ($ora > $max) $max = $ora; }
        else $ora = 0;
    }
    return ['serie' => $ora, 'migliore' => $max, 'risposte' => $n];
}

/** I record dell'utente in un corso: [tipo => valore]. */
function fcv_record(int $user_id, int $course_id): array {
    $out = [];
    if ($course_id <= 0) return $out;
    foreach (sec_all("SELECT kind, value FROM fc_vocab_records WHERE user_id = ? AND course_id = ?",
                     [$user_id, $course_id]) as $r) {
        $out[$r['kind']] = (float)$r['value'];
    }
    return $out;
}

/**
 * Scrive un record se e' migliore di quello che c'e' (piu' alto, o piu' basso
 * per i tipi in cui vince il minore). Restituisce il valore di prima (null se
 * non c'era) e se e' cambiato.
 */
function fcv_record_aggiorna(int $user_id, int $course_id, string $tipo, float $valore, int $game_id, bool $vince_minore = false): array {
    $prima = sec_one("SELECT value FROM fc_vocab_records WHERE user_id = ? AND course_id = ? AND kind = ?",
                     [$user_id, $course_id, $tipo]);
    $vecchio = $prima ? (float)$prima['value'] : null;
    $meglio = $vecchio === null || ($vince_minore ? $valore < $vecchio : $valore > $vecchio);
    if ($meglio) {
        sec_q("INSERT INTO fc_vocab_records (user_id, course_id, kind, value, game_id, achieved_at)
               VALUES (?, ?, ?, ?, ?, NOW())
               ON DUPLICATE KEY UPDATE value = VALUES(value), game_id = VALUES(game_id), achieved_at = VALUES(achieved_at)",
              [$user_id, $course_id, $tipo, round($valore, 2), $game_id ?: null]);
    }
    return ['prima' => $vecchio, 'nuovo' => $meglio && $vecchio !== null, 'primo' => $vecchio === null];
}

/** Quante parole del corso sono "padroneggiate" (carte e sottocarte). */
function fcv_padroneggiate(int $user_id, int $course_id): int {
    if ($course_id <= 0) return 0;
    $stats = [];
    $query = [
        'p' => "SELECT p.fc_image_id AS id, p.user_answer FROM fc_vocab_plays p
                  JOIN fc_vocab_cards c ON c.id = p.fc_image_id
                  JOIN fc_vocab_decks d ON d.id = c.category_id
                 WHERE d.user_id = ? AND d.course_id = ? AND p.user_answer IN ('Correct', 'Wrong')
                 ORDER BY p.play_date ASC, p.id ASC",
        'c' => "SELECT p.fc_child_id AS id, p.user_answer FROM fc_vocab_plays p
                  JOIN fc_vocab_subcards s ON s.id_child = p.fc_child_id
                  JOIN fc_vocab_cards c ON c.id = s.parent_id
                  JOIN fc_vocab_decks d ON d.id = c.category_id
                 WHERE d.user_id = ? AND d.course_id = ? AND p.user_answer IN ('Correct', 'Wrong')
                 ORDER BY p.play_date ASC, p.id ASC",
    ];
    foreach ($query as $pre => $sql) {
        foreach (sec_all($sql, [$user_id, $course_id]) as $r) {
            $k = $pre . (int)$r['id'];
            if (!isset($stats[$k])) $stats[$k] = ['c' => 0, 'w' => 0, 'u' => ''];
            $giusta = $r['user_answer'] === 'Correct';
            $stats[$k][$giusta ? 'c' : 'w']++;
            $stats[$k]['u'] = substr($stats[$k]['u'] . ($giusta ? 'C' : 'W'), -6);
        }
    }
    $n = 0;
    foreach ($stats as $s) if (fcv_livello($s['c'], $s['w'], $s['u']) === 'padroneggiata') $n++;
    return $n;
}

}
