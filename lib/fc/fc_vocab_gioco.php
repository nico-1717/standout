<?php
/* fc_vocab_gioco.php — I NUMERI DIETRO IL GIOCO (lingue e studio)
 *
 * Nato per le lingue, vale ora anche per le flashcard di studio: le tabelle
 * vengono da fc_t() (fc_type.php), quindi si lavora su quelle del tipo della
 * pagina. I record stanno in fc_vocab_records (per corso) o in
 * fc_study_records (uno per utente).
 *
 * Il livello di ogni parola (o carta), la serie di risposte giuste di una partita e i
 * record personali. Li usano fc_game.php (per disegnare la carta) e
 * fc_vocab_game_api.php (checkpoint e record). La parte che si vede e' in
 * public/fc/fc_game_juice.js.
 *
 * Tutto si ricava dalle risposte gia' salvate (fc_vocab_plays): non c'e' un
 * contatore da tenere allineato, quindi non c'e' niente che possa sfasarsi.
 */

if (!function_exists('fcv_livello')) {

/* ============================================================
   I LIVELLI DI UNA CARTA
   ============================================================
   Dalla storia delle sue risposte, dalla piu' vecchia alla piu' recente:
   'C'/'W' giusta/sbagliata in una partita normale, 'c'/'w' in un RIPASSO
   (una partita nata da "ripassa gli errori", fc_games.parent_id > 0: li' ci
   sono solo carte appena sbagliate, quindi vale meno).

     nuova           mai giocata
     da_imparare     giocata al massimo due volte, sempre sbagliata
     sembra_facile   mai sbagliata, ma giocata una volta sola
     facile          mai sbagliata, giocata due volte (la terza: la_sai)
     la_sai          giusta le ultime TRE volte di fila
     insidiosa       nelle ultime cinque partite normali (o in tutte, se sono
                     meno di cinque), sbagliata una volta
     difficile       come sopra, sbagliata due volte o piu'
     difficilissima  difficile, e sbagliata anche in un ripasso fra le ultime
                     CINQUE risposte
     in_ripresa      era difficile: l'ultima volta (partite normali) giusta
     quasi           era difficile: le ultime DUE volte giusta (la terza: la_sai)
   Da in_ripresa o quasi, una risposta sbagliata riporta a difficile (o
   difficilissima). "Era difficile" lo si sa solo ripercorrendo la storia,
   quindi il livello si calcola un passo alla volta (fcv_livello_avanza).
   I TITOLI delle sottocarte (solo studio) non hanno livello: non sono una
   domanda a cui si risponde (vedi fcv_titoli_studio).
   Gemella di livello() in fc_game_juice.js: se cambia qui va cambiata li'. */

/** Dal peggiore al migliore: per ordinare e per dire se si sale o si scende. */
define('FCV_LIVELLI', ['difficilissima', 'difficile', 'insidiosa', 'da_imparare', 'nuova', 'in_ripresa', 'sembra_facile', 'facile', 'quasi', 'la_sai']);
/** Quanta storia si guarda (le ultime N risposte). */
define('FCV_STORIA', 30);

function fcv_livelli_vuoti(): array { return array_fill_keys(FCV_LIVELLI, 0); }
function fcv_livello_rango(string $l): int { $i = array_search($l, FCV_LIVELLI, true); return $i === false ? 4 : (int)$i; }
/** Le carte da ripassare: "solo le difficili", il badge "da ripassare". */
function fcv_da_ripassare(string $l): bool { return in_array($l, ['difficilissima', 'difficile', 'da_imparare'], true); }

/** Vero se un campo di testo (documento a blocchi di fc_parser.js, o vecchio
 *  testo semplice) non contiene niente di scritto. Gemella di fc_testo_vuoto()
 *  in fc_view.php: li' non si puo' prendere, la pagina non e' sempre caricata. */
function fcv_testo_vuoto($raw): bool {
    $raw = trim((string)$raw);
    if ($raw === '') return true;
    $doc = json_decode($raw, true);
    if (!is_array($doc) || !isset($doc['blocks']) || !is_array($doc['blocks'])) {
        return trim(strip_tags($raw)) === '';
    }
    foreach ($doc['blocks'] as $b) {
        if (!is_array($b)) continue;
        $tipo = (string)($b['t'] ?? 'p');
        if ($tipo !== 'p' && $tipo !== 'li') return false;
        foreach (($b['runs'] ?? []) as $r) {
            if (is_array($r) && trim((string)($r['s'] ?? '')) !== '') return false;
            if (is_string($r) && trim($r) !== '') return false;
        }
    }
    return true;
}

/**
 * I TITOLI di un utente (solo flashcard di studio): le carte padre con almeno
 * due sottocarte, la domanda scritta e la risposta completamente vuota. Non
 * sono una domanda a cui si risponde ma il titolo del gruppo, quindi non hanno
 * un livello ("nuova", "difficile"...): non si mostra, non si conta, non conta
 * per "da ripassare". E' la stessa regola con cui l'elenco (is-titolo) e il
 * gioco (fc_e_titolo) li riconoscono.
 * Ritorna ['p<id>' => true]. Vuoto per le lingue: una parola padre e' una
 * parola vera anche lei.
 */
function fcv_titoli_studio(int $uid): array {
    static $cache = [];
    if (isset($cache[$uid])) return $cache[$uid];
    $out = [];
    foreach (sec_all("SELECT i.id, i.question_desc, i.answer_desc
                        FROM fc_study_cards i
                        JOIN fc_study_decks d ON d.id = i.category_id AND d.user_id = ?
                       WHERE i.is_father = 1
                         AND TRIM(IFNULL(i.answer_image, '')) = '' AND TRIM(IFNULL(i.answer_audio, '')) = ''
                         AND (SELECT COUNT(*) FROM fc_study_subcards s WHERE s.parent_id = i.id) >= 2", [$uid]) as $r) {
        if (!fcv_testo_vuoto($r['question_desc']) && fcv_testo_vuoto($r['answer_desc'])) $out['p' . (int)$r['id']] = true;
    }
    return $cache[$uid] = $out;
}

/** Quante carte da ripassare, da un conteggio per livello. */
function fcv_conta_da_ripassare(array $conta): int {
    $n = 0;
    foreach ($conta as $l => $x) if (fcv_da_ripassare((string)$l)) $n += (int)$x;
    return $n;
}

/** Il livello di una storia gia' tagliata: $s = risposte, $n = quante in tutto, $rec = era difficile. */
function fcv_livello_prefisso(string $s, int $n, bool $rec): string {
    if ($n <= 0 || $s === '') return 'nuova';
    $tutte = strtoupper($s);
    $norm = preg_replace('/[a-z]/', '', $s);                      // solo le partite normali
    if ($n <= 2 && strpos($tutte, 'C') === false) return 'da_imparare';
    if ($n >= 3 && substr($tutte, -3) === 'CCC') return 'la_sai';
    $finestra = function () use ($s, $norm): string {
        $wN = substr_count(substr($norm, -5), 'W');
        /* il ripasso sbagliato conta se e' fra le ultime CINQUE risposte (prima
           dieci: una carta restava "difficilissima" troppo a lungo) */
        if ($wN >= 2) return strpos(substr($s, -5), 'w') !== false ? 'difficilissima' : 'difficile';
        return 'insidiosa';
    };
    if ($rec) {
        $serie = strlen($norm) - strlen(rtrim($norm, 'C'));
        if ($serie === 1) return 'in_ripresa';
        if ($serie >= 2) return 'quasi';   // 3 di fila sono gia' la_sai, presa sopra
        $f = $finestra();
        return fcv_livello_rango($f) < fcv_livello_rango('difficile') ? $f : 'difficile';
    }
    /* mai sbagliata: con una sola risposta "sembra facile", con due (tre di fila
       sono gia' la_sai, presa sopra) "facile" */
    if (strpos($tutte, 'W') === false) return $n >= 2 ? 'facile' : 'sembra_facile';
    return $finestra();
}

/** Un passo: lo stato dopo una risposta in piu' ('C', 'W', 'c', 'w'). */
function fcv_livello_avanza(array $st, string $r): array {
    $st['s'] = substr(($st['s'] ?? '') . $r, -FCV_STORIA);
    $st['n'] = ($st['n'] ?? 0) + 1;
    $st['liv'] = fcv_livello_prefisso($st['s'], $st['n'], !empty($st['rec']));
    if ($st['liv'] === 'difficile' || $st['liv'] === 'difficilissima') $st['rec'] = true;
    elseif ($st['liv'] === 'la_sai') $st['rec'] = false;
    return $st;
}

/** Il livello da una storia ($u) e dal numero di risposte di sempre ($n). */
function fcv_livello_storia(string $u, int $n): string {
    if ($n <= 0 || $u === '') return 'nuova';
    $st = ['s' => '', 'n' => max(0, $n - strlen($u)), 'rec' => false, 'liv' => 'nuova'];
    foreach (str_split($u) as $r) $st = fcv_livello_avanza($st, $r);
    return $st['liv'];
}

/** Come prima: giuste ($c), sbagliate ($w), storia ($ultime). */
function fcv_livello(int $c, int $w, string $ultime): string {
    return fcv_livello_storia($ultime, $c + $w);
}

/** La lettera di una risposta nella storia. */
function fcv_lettera(bool $giusta, bool $ripasso): string {
    $l = $giusta ? 'C' : 'W';
    return $ripasso ? strtolower($l) : $l;
}

/**
 * LE CARTE CHE SAI: quante sono adesso e QUANDO le hai imparate.
 *
 * Una carta "la sai" se e' segnata come imparata (il tastino col tocco)
 * OPPURE se il suo livello e' la_sai (giusta le ultime tre volte): prima
 * contava solo il tastino, e chi non lo toccava mai restava a zero per
 * sempre anche sapendo tutto.
 * Il giorno in cui l'hai imparata e' il primo fra il giorno del tastino e il
 * giorno della risposta che l'ha portata a la_sai. E' quel giorno che conta
 * per "imparate oggi / questa settimana / questo mese".
 *
 * Le carte che si contano sono le stesse dei totali che gia' si mostrano
 * (fc_summary.php): nello studio le carte senza sottocarte e le sottocarte
 * (una carta con sottocarte e' il titolo del gruppo), nelle lingue tutte le
 * parole e le sottoparole. Il tastino sta sulla carta padre e vale per le
 * sue sottocarte.
 *
 * Le carte di un mazzo ARCHIVIATO contano come imparate: archiviare vuol dire
 * "con questo ho finito", e senza questa regola chi archivia una materia dopo
 * l'esame vedrebbe scendere la percentuale. L'archivio e' a cascata (un ramo
 * intero segue la sua radice, come fc_in_ramo_archiviato in fc_decks.php), quindi
 * si risale dai padri. Della data di archiviazione non c'e' traccia: queste
 * carte entrano nel totale ma non in "oggi / settimana / mese" (giorni), per
 * non inventare un giorno.
 *
 * @return array{unita:int, totale:int, giorni:array<string,int>, per_corso:array<int,int>, dettaglio:array}
 *   giorni    = [Y-m-d => quante imparate quel giorno], in ordine
 *   dettaglio = [chiave => ['mazzo' => id, 'quando' => Y-m-d|null, 'ora' => la sai adesso, 'giocata' => bool]]
 */
function fcv_conosciute(int $uid, string $tipo): array {
    $vocab = $tipo === 'vocab';
    $T = $vocab
        ? ['c' => 'fc_vocab_cards', 's' => 'fc_vocab_subcards', 'd' => 'fc_vocab_decks', 'p' => 'fc_vocab_plays', 'l' => 'fc_vocab_learnt']
        : ['c' => 'fc_study_cards', 's' => 'fc_study_subcards', 'd' => 'fc_study_decks', 'p' => 'fc_study_plays', 'l' => 'fc_study_learnt'];
    $corsoCol = $vocab ? 'd.course_id' : '0';

    /* le carte che contano, con il loro corso */
    $unita = [];   // chiave => ['padre' => id del padre per il tastino, 'corso' => id, 'mazzo' => id]
    foreach (sec_all("SELECT c.id, c.is_father, c.category_id, $corsoCol AS corso FROM {$T['c']} c
                        JOIN {$T['d']} d ON d.id = c.category_id WHERE d.user_id = ?", [$uid]) as $r) {
        if (!$vocab && (int)$r['is_father'] === 1) continue;
        $unita['p' . (int)$r['id']] = ['padre' => (int)$r['id'], 'corso' => (int)$r['corso'], 'mazzo' => (int)$r['category_id']];
    }
    foreach (sec_all("SELECT s.id_child, s.parent_id, c.category_id, $corsoCol AS corso FROM {$T['s']} s
                        JOIN {$T['c']} c ON c.id = s.parent_id
                        JOIN {$T['d']} d ON d.id = c.category_id WHERE d.user_id = ?", [$uid]) as $r) {
        $unita['c' . (int)$r['id_child']] = ['padre' => (int)$r['parent_id'], 'corso' => (int)$r['corso'], 'mazzo' => (int)$r['category_id']];
    }

    /* i mazzi con padre e flag di archivio, per sapere quali stanno in un ramo
       archiviato */
    $mazzi = [];
    foreach (sec_all("SELECT id, parent_id, archived FROM {$T['d']} WHERE user_id = ?", [$uid]) as $r) {
        $mazzi[(int)$r['id']] = ['p' => (int)$r['parent_id'], 'a' => (int)$r['archived'] === 1];
    }
    $archCache = [];
    $inArchivio = function (int $id) use (&$mazzi, &$archCache): bool {
        if (isset($archCache[$id])) return $archCache[$id];
        $x = $id;
        /* 50 salti bastano per qualunque albero vero; il limite protegge solo da un
           ciclo nei dati (un mazzo padre di se stesso) */
        for ($passi = 0; $x > 0 && isset($mazzi[$x]) && $passi < 50; $passi++) {
            if ($mazzi[$x]['a']) return $archCache[$id] = true;
            $x = $mazzi[$x]['p'];
        }
        return $archCache[$id] = false;
    };

    $tastino = [];
    foreach (sec_all("SELECT fc_image_id, MIN(learnt_date) AS d FROM {$T['l']} WHERE user_id = ? GROUP BY fc_image_id", [$uid]) as $r) {
        $tastino[(int)$r['fc_image_id']] = (string)$r['d'];
    }

    /* la storia di ogni carta, un passo alla volta: il primo giorno in cui
       arriva a la_sai e il livello di adesso */
    $stato = []; $primo = [];
    foreach (sec_all("SELECT p.fc_image_id, p.fc_child_id, p.user_answer, DATE(p.play_date) AS d,
                             (g.parent_id IS NOT NULL AND g.parent_id > 0) AS ripasso
                        FROM {$T['p']} p JOIN fc_games g ON g.id = p.game_id
                       WHERE g.user_id = ? AND p.user_answer IN ('Correct', 'Wrong') AND p.play_date IS NOT NULL
                       ORDER BY p.play_date ASC, p.id ASC", [$uid]) as $r) {
        $k = $r['fc_child_id'] !== null ? 'c' . (int)$r['fc_child_id'] : 'p' . (int)$r['fc_image_id'];
        if (!isset($unita[$k])) continue;
        $st = fcv_livello_avanza($stato[$k] ?? ['s' => '', 'n' => 0, 'rec' => false, 'liv' => 'nuova'],
                                 fcv_lettera($r['user_answer'] === 'Correct', (bool)$r['ripasso']));
        $stato[$k] = $st;
        if ($st['liv'] === 'la_sai' && !isset($primo[$k])) $primo[$k] = (string)$r['d'];
    }

    $totale = 0; $giorni = []; $perCorso = []; $dettaglio = [];
    foreach ($unita as $k => $u) {
        $dt = $tastino[$u['padre']] ?? null;
        $dl = $primo[$k] ?? null;
        $quando = ($dt !== null && $dl !== null) ? min($dt, $dl) : ($dt ?? $dl);
        if ($quando !== null) $giorni[$quando] = ($giorni[$quando] ?? 0) + 1;
        $ora = $dt !== null || (($stato[$k]['liv'] ?? 'nuova') === 'la_sai') || $inArchivio($u['mazzo']);
        if ($ora) {
            $totale++;
            $perCorso[$u['corso']] = ($perCorso[$u['corso']] ?? 0) + 1;
        }
        $dettaglio[$k] = ['mazzo' => $u['mazzo'], 'quando' => $quando, 'ora' => $ora, 'giocata' => isset($stato[$k])];
    }
    ksort($giorni);
    return ['unita' => count($unita), 'totale' => $totale, 'giorni' => $giorni, 'per_corso' => $perCorso, 'dettaglio' => $dettaglio];
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
        /* il ripasso (una partita figlia di un'altra) conta in minuscolo */
        foreach (sec_all("SELECT p.$col AS id, p.user_answer, (g.parent_id IS NOT NULL AND g.parent_id > 0) AS ripasso
                            FROM " . fc_t('plays') . " p LEFT JOIN fc_games g ON g.id = p.game_id
                          WHERE p.$col IN ($ph) AND p.user_answer IN ('Correct', 'Wrong')
                          ORDER BY p.play_date ASC, p.id ASC", $ids) as $r) {
            $k = $pre . (int)$r['id'];
            $giusta = $r['user_answer'] === 'Correct';
            $out[$k][$giusta ? 'c' : 'w']++;
            $out[$k]['u'] = substr($out[$k]['u'] . fcv_lettera($giusta, (bool)$r['ripasso']), -FCV_STORIA);
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
    foreach (sec_all("SELECT user_answer FROM " . fc_t('plays') . "
                      WHERE game_id = ? AND user_answer IN ('Correct', 'Wrong')
                      ORDER BY play_date ASC, id ASC", [$game_id]) as $r) {
        $n++;
        if ($r['user_answer'] === 'Correct') { $ora++; if ($ora > $max) $max = $ora; }
        else $ora = 0;
    }
    return ['serie' => $ora, 'migliore' => $max, 'risposte' => $n];
}

/* Dove stanno i record: lingue per corso, studio uno per utente. */
function fcv_record_dove(int $user_id, int $course_id): array {
    return fc_is_vocab()
        ? ['fc_vocab_records', 'user_id = ? AND course_id = ?', [$user_id, $course_id], ['user_id', 'course_id'], [$user_id, $course_id]]
        : ['fc_study_records', 'user_id = ?', [$user_id], ['user_id'], [$user_id]];
}

/** I record dell'utente (nel corso, per le lingue): [tipo => valore]. */
function fcv_record(int $user_id, int $course_id): array {
    $out = [];
    if (fc_is_vocab() && $course_id <= 0) return $out;
    [$tab, $dove, $par] = fcv_record_dove($user_id, $course_id);
    foreach (sec_all("SELECT kind, value FROM $tab WHERE $dove", $par) as $r) {
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
    [$tab, $dove, $par, $cols, $vals] = fcv_record_dove($user_id, $course_id);
    $prima = sec_one("SELECT value FROM $tab WHERE $dove AND kind = ?", array_merge($par, [$tipo]));
    $vecchio = $prima ? (float)$prima['value'] : null;
    $meglio = $vecchio === null || ($vince_minore ? $valore < $vecchio : $valore > $vecchio);
    if ($meglio) {
        sec_q("INSERT INTO $tab (" . implode(', ', $cols) . ", kind, value, game_id, achieved_at)
               VALUES (" . implode(', ', array_fill(0, count($cols), '?')) . ", ?, ?, ?, NOW())
               ON DUPLICATE KEY UPDATE value = VALUES(value), game_id = VALUES(game_id), achieved_at = VALUES(achieved_at)",
              array_merge($vals, [$tipo, round($valore, 2), $game_id ?: null]));
    }
    return ['prima' => $vecchio, 'nuovo' => $meglio && $vecchio !== null, 'primo' => $vecchio === null];
}

/** Quante parole del corso (o carte di studio) sono "padroneggiate". */
function fcv_padroneggiate(int $user_id, int $course_id): int {
    if (fc_is_vocab() && $course_id <= 0) return 0;
    $stats = [];
    [$P, $C, $S, $D] = [fc_t('plays'), fc_t('cards'), fc_t('subcards'), fc_t('decks')];
    $filtro = fc_is_vocab() ? 'd.user_id = ? AND d.course_id = ?' : 'd.user_id = ?';
    $par = fc_is_vocab() ? [$user_id, $course_id] : [$user_id];
    $query = [
        'p' => "SELECT p.fc_image_id AS id, p.user_answer, (g.parent_id IS NOT NULL AND g.parent_id > 0) AS ripasso FROM $P p
                  JOIN $C c ON c.id = p.fc_image_id
                  JOIN $D d ON d.id = c.category_id
                  LEFT JOIN fc_games g ON g.id = p.game_id
                 WHERE $filtro AND p.user_answer IN ('Correct', 'Wrong')
                 ORDER BY p.play_date ASC, p.id ASC",
        'c' => "SELECT p.fc_child_id AS id, p.user_answer, (g.parent_id IS NOT NULL AND g.parent_id > 0) AS ripasso FROM $P p
                  JOIN $S s ON s.id_child = p.fc_child_id
                  JOIN $C c ON c.id = s.parent_id
                  JOIN $D d ON d.id = c.category_id
                  LEFT JOIN fc_games g ON g.id = p.game_id
                 WHERE $filtro AND p.user_answer IN ('Correct', 'Wrong')
                 ORDER BY p.play_date ASC, p.id ASC",
    ];
    foreach ($query as $pre => $sql) {
        foreach (sec_all($sql, $par) as $r) {
            $k = $pre . (int)$r['id'];
            if (!isset($stats[$k])) $stats[$k] = ['c' => 0, 'w' => 0, 'u' => ''];
            $giusta = $r['user_answer'] === 'Correct';
            $stats[$k][$giusta ? 'c' : 'w']++;
            $stats[$k]['u'] = substr($stats[$k]['u'] . fcv_lettera($giusta, (bool)$r['ripasso']), -FCV_STORIA);
        }
    }
    $n = 0;
    $titoli = fc_is_vocab() ? [] : fcv_titoli_studio($user_id);
    foreach ($stats as $k => $s) {
        if (isset($titoli[$k])) continue;   // un titolo non ha livello
        if (fcv_livello($s['c'], $s['w'], $s['u']) === 'la_sai') $n++;
    }
    return $n;
}

}
