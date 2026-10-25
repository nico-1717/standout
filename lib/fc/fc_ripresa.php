<?php
/* ============================================================================
   fc_ripresa.php — DA CHE POSIZIONE SI RIPRENDE UNA PARTITA

   Una sola risposta per «Continua partita» (pagina dei risultati), «Riprendi»
   (storico) e la Home, cosi' nessuno dei tre puo' mandare in un posto diverso.

   Perche' non basta «ultima posizione + 1»: il precaricamento di fc_game.php
   scrive le righe fino a dieci posizioni PIU' AVANTI di dove si e' arrivati, e
   le intestazioni («Titolo») nascono gia' chiuse. Dopo una chiusura della
   partita le righe vuote vengono cancellate ma quelle dei titoli restano, quindi
   la posizione piu' alta con una riga puo' stare oltre la fine della sequenza:
   «+ 1» dava una posizione che non esiste, fc_game.php rimandava ai risultati
   e il tasto sembrava non funzionare mai.

   Si riprende dalla prima posizione della sequenza che NON e' chiusa: con una
   riga ancora vuota, oppure senza nessuna riga. Se non ce n'e', la partita e'
   finita davvero e si ritorna null.
   ============================================================================ */

require_once __DIR__ . '/fc_type.php';

/**
 * @param string $kind 'study' | 'vocab' (si passa e non si legge dallo stato
 *                     della richiesta: la Home guarda i due tipi insieme)
 */
function fc_ripresa_posizione(int $gameId, string $kind): ?int {
    $t = FC_TABELLE[$kind === 'vocab' ? 'vocab' : 'study'];
    $plays = $t['plays'];
    $seq = $t['sequence'];

    /* una riga ancora da rispondere: vale anche se la sequenza non la conosce
       piu' (partita vecchia), perche' fc_game.php sa aprirla */
    $r = sec_one("SELECT MIN(position) AS p FROM $plays WHERE game_id = ? AND COALESCE(user_answer, '') = ''", [$gameId], 'i');
    if ($r && (int)$r['p'] > 0) return (int)$r['p'];

    $r = sec_one("SELECT MIN(s.position) AS p
                    FROM $seq s
                   WHERE s.game_id = ?
                     AND NOT EXISTS (SELECT 1 FROM $plays p
                                      WHERE p.game_id = s.game_id AND p.position = s.position
                                        AND COALESCE(p.user_answer, '') <> '')", [$gameId], 'i');
    return ($r && (int)$r['p'] > 0) ? (int)$r['p'] : null;
}

/* ----------------------------------------------------------------------------
   LA PARTITA LASCIATA A META' (una per tipo: studio, lingue)
   Serve alla Home e alla pagina principale delle flashcard: «Continua» e
   «Gioca» devono apparire per lo studio E per le lingue, ciascuno con la sua
   partita. «A meta'» vuol dire quello che dice lo Storico: ha almeno una
   risposta e non tutte le posizioni della sequenza sono chiuse.
   ---------------------------------------------------------------------------- */

/** @return array|null id, kind, course, pos, closed, total, label, lang, date */
function fc_partita_in_sospeso(int $uid, string $kind): ?array {
    $kind = $kind === 'vocab' ? 'vocab' : 'study';
    $t = FC_TABELLE[$kind];
    $cand = sec_all("SELECT id, game_date, course_id FROM fc_games
                      WHERE user_id = ? AND kind = ? AND game_date >= (NOW() - INTERVAL 90 DAY)
                      ORDER BY game_date DESC, id DESC LIMIT 12", [$uid, $kind], 'is');
    foreach ($cand as $g) {
        $id = (int)$g['id'];
        $totale = (int)(sec_one("SELECT COUNT(*) AS n FROM {$t['sequence']} WHERE game_id = ?", [$id], 'i')['n'] ?? 0);
        if ($totale < 1) continue;
        $risposte = (int)(sec_one("SELECT COUNT(*) AS n FROM {$t['plays']} WHERE game_id = ? AND user_answer IN ('Correct','Wrong')", [$id], 'i')['n'] ?? 0);
        if ($risposte < 1) continue;
        /* posizioni chiuse: hanno righe e nessuna ancora da rispondere */
        $chiuse = (int)(sec_one("SELECT COUNT(*) AS n FROM (SELECT position FROM {$t['plays']} WHERE game_id = ?
                                  GROUP BY position HAVING SUM(COALESCE(user_answer, '') = '') = 0) q", [$id], 'i')['n'] ?? 0);
        $pos = fc_ripresa_posizione($id, $kind);
        if ($pos === null || $chiuse >= $totale) continue;
        $mazzo = sec_one("SELECT d.name FROM {$t['game_decks']} gd JOIN {$t['decks']} d ON d.id = gd.fc_category_id
                           WHERE gd.game_id = ? ORDER BY gd.category_order ASC LIMIT 1", [$id], 'i');
        $lingua = null;
        if ($kind === 'vocab' && (int)$g['course_id'] > 0) {
            $c = sec_one("SELECT learn_lang FROM fc_vocab_courses WHERE id = ? AND user_id = ?", [(int)$g['course_id'], $uid], 'ii');
            if (!$c) continue;   // corso eliminato: la partita non si riapre piu'
            $lingua = (string)$c['learn_lang'];
        }
        return ['id' => $id, 'kind' => $kind, 'course' => $kind === 'vocab' ? (int)$g['course_id'] : null,
                'pos' => $pos, 'closed' => min($chiuse, $totale), 'total' => $totale,
                'label' => (string)($mazzo['name'] ?? ''), 'lang' => $lingua, 'date' => (string)$g['game_date']];
    }
    return null;
}

/**
 * Il corso su cui «Gioca» deve portare: quello dell'ultima partita di lingue,
 * altrimenti il primo. null se non ha ancora nessuna lingua.
 * @return array|null id, learn_lang, known_lang
 */
function fc_corso_da_giocare(int $uid): ?array {
    $r = sec_one("SELECT c.id, c.learn_lang, c.known_lang
                    FROM fc_games g JOIN fc_vocab_courses c ON c.id = g.course_id AND c.user_id = g.user_id
                   WHERE g.user_id = ? AND g.kind = 'vocab' ORDER BY g.game_date DESC, g.id DESC LIMIT 1", [$uid], 'i');
    if (!$r) $r = sec_one("SELECT id, learn_lang, known_lang FROM fc_vocab_courses WHERE user_id = ? ORDER BY custom_order ASC, id ASC LIMIT 1", [$uid], 'i');
    return $r ? ['id' => (int)$r['id'], 'learn_lang' => (string)$r['learn_lang'], 'known_lang' => (string)$r['known_lang']] : null;
}
