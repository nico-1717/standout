<?php
/* Avvia una partita di flashcard sulle categorie scelte.
 *
 * COSA C'ERA CHE NON ANDAVA
 *
 * 1. Nessun controllo di accesso: il file includeva solo config.php, quindi
 *    chiunque poteva chiamarlo anche senza essere connesso.
 *
 * 2. $user_id arrivava dalla querystring, con 1 come ripiego: bastava cambiare
 *    un numero nell'indirizzo per creare partite a nome di un altro utente.
 *    Adesso l'utente e' quello della sessione, e basta.
 *
 * 3. Le categorie arrivavano dalla querystring e finivano nella query senza
 *    verificare che fossero dell'utente: si poteva giocare - e quindi leggere -
 *    le schede di un altro. Ora si tengono solo quelle sue.
 *
 * 4. "Tutte" e "Da imparare" scrivevano lo stesso tipo ('fc'): lo storico non
 *    poteva distinguerle, e chi riprendeva la partita non sapeva con che stato
 *    farlo. Ora lo stato sta in fc_games.card_status.
 *
 * 5. Il totale veniva contato con una query a parte, prima di decidere la
 *    sequenza: due conteggi dello stesso insieme che potevano non coincidere.
 *    Ora il totale E' la sequenza, e una selezione vuota non crea nessuna
 *    partita (prima nasceva una partita fantasma con totale 1).
 */
require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/auth_check.php';

$current_user_id = (int)$utente_corrente['id'];
$conn = sec_db();

/* Studio o lingua: con ?course=N la partita e' di parole, nasce con
   kind='vocab' e il suo corso, e porta nel tipo il verso scelto (vedi
   fc_verso_da_tipo in fc_type.php). Da li' in poi le pagine della partita
   leggono il tipo dalla partita, non dall'indirizzo. */
require_once __DIR__ . '/../../lib/fc/fc_type.php';
fc_avvia($current_user_id);

/* Limite di frequenza: crea partite, quindi righe nel database. La pagina si
   raggiunge con un GET, quindi il token CSRF non si applica (vedi note). */
sec_guard_write($current_user_id, 'fc_start', 0, 60, 60);

if (!isset($_GET['category_id'])) {
    header('Location: ' . fc_url('fc_game_setup.php'));
    exit();
}

/* 'all' = tutte, '1' = solo imparate, altro = solo da imparare. */
$status = sec_enum($_GET['status'] ?? null, ['all', '1', '0'], '0');
$order  = sec_enum($_GET['order'] ?? null, ['in_order', 'random'], 'random');

/* Le categorie chieste, tenute solo se sono davvero sue. */
$chieste = [];
foreach (explode(',', (string)$_GET['category_id']) as $c) {
    $n = sec_int($c, 1);
    if ($n > 0) $chieste[] = $n;
}
$chieste = array_values(array_unique($chieste));

$valid_cats = [];
if (!empty($chieste)) {
    $ph = implode(',', array_fill(0, count($chieste), '?'));
    $sue = [];
    [$fcW, $fcP] = fc_filtro_corso();
    foreach (sec_all("SELECT id FROM " . fc_t('decks') . " WHERE id IN ($ph) AND user_id = ?" . $fcW,
                     array_merge($chieste, [$current_user_id], $fcP)) as $r) {
        $sue[(int)$r['id']] = true;
    }
    /* Si rispetta l'ordine CHIESTO, non quello in cui il database restituisce
       le righe: e' l'ordine che l'utente ha scelto nella lista, ed e' quello
       che decide in che sequenza usciranno le carte. */
    foreach ($chieste as $n) {
        if (isset($sue[$n])) $valid_cats[] = $n;
    }
}

/* Il tipo resta quello di sempre ('fc' / 'fc_L') perche' altre pagine lo
   leggono; la distinzione vera fra le tre scelte sta in card_status. */
$game_type = (fc_is_vocab() ? sec_enum($_GET['dir'] ?? null, FC_VERSI, 't2w') : 'fc') . ($status === '1' ? '_L' : '');

/* ---------------------------------------------------------------------
   La sequenza della partita si decide QUI, una volta sola: quale padre va
   a ciascuna posizione. E' l'unica cosa che va decisa in anticipo -
   "random" va estratto una volta sola, non ripescato a ogni pagina - ed
   e' economica da scrivere anche per selezioni enormi, perche' non porta
   dietro il testo delle risposte ne' i figli: quelli si risolvono da soli
   quando la posizione viene letta la prima volta (fc_game.php), seguendo
   il loro custom_order come hanno sempre fatto.
   --------------------------------------------------------------------- */
$par_sel = [];
if ($status === '1') {
    $where_sel = 'i.id IN (SELECT fc_image_id FROM ' . fc_t('learnt') . ' WHERE user_id = ?)';
    $par_sel[] = $current_user_id;
} elseif ($status === '0') {
    $where_sel = 'i.id NOT IN (SELECT fc_image_id FROM ' . fc_t('learnt') . ' WHERE user_id = ?)';
    $par_sel[] = $current_user_id;
} else {
    $where_sel = '1=1';
}

/* La JOIN su fc_study_decks con user_id e' la garanzia che si prendano solo
   le proprie schede, anche se l'elenco delle categorie fosse vuoto. */
$from_sel = "FROM " . fc_t('cards') . " i
             JOIN " . fc_t('decks') . " c ON c.id = i.category_id
             WHERE $where_sel AND c.user_id = ?";
$par_sel[] = $current_user_id;
/* Per le lingue, solo i mazzi del corso: "tutte le categorie" vuol dire
   tutte quelle di questa lingua, non anche quelle di un'altra. */
[$fcWc, $fcPc] = fc_filtro_corso('c');
$from_sel .= $fcWc;
$par_sel = array_merge($par_sel, $fcPc);

if (!empty($valid_cats)) {
    $ph_sel = implode(',', array_fill(0, count($valid_cats), '?'));
    $from_sel .= " AND i.category_id IN ($ph_sel)";
    $par_sel = array_merge($par_sel, $valid_cats);
}

$schede = sec_all("SELECT i.id, i.category_id, i.custom_order $from_sel", $par_sel);

/* Niente da giocare: si torna alla scelta senza creare una partita che
   resterebbe vuota nello storico. */
if (empty($schede)) {
    header('Location: ' . fc_url('fc_game_setup.php', ['vuota' => 1]));
    exit();
}

/* Carte totali, figli compresi, contate sullo stesso insieme della sequenza.
   Solo i padri veri portano figli: e' la stessa regola con cui fc_game.php
   li genera, cosi' i due numeri partono allineati. */
$figli = (int)(sec_one("SELECT COUNT(*) AS n FROM " . fc_t('subcards') . " ch
                        JOIN (SELECT i.id $from_sel AND i.is_father = 1) p ON p.id = ch.parent_id",
                       $par_sel)['n'] ?? 0);

/* SOLO LE DIFFICILI (?solo=difficili, dal badge "da ripassare" dell'elenco
   dei mazzi e dal tasto nel mazzo): restano le carte che sono, o hanno una
   sottocarta che e', al livello "difficile" (fcv_livello). Lo stato delle
   carte e' sempre "tutte": una carta difficile segnata come imparata va
   ripassata lo stesso. */
if (($_GET['solo'] ?? '') === 'difficili') {
    require_once __DIR__ . '/../../lib/fc/fc_vocab_gioco.php';
    $idsP = array_map(function ($s) { return (int)$s['id']; }, $schede);
    $figliDi = [];
    if ($idsP) {
        $ph = implode(',', array_fill(0, count($idsP), '?'));
        foreach (sec_all("SELECT id_child, parent_id FROM " . fc_t('subcards') . " WHERE parent_id IN ($ph)", $idsP) as $r) {
            $figliDi[(int)$r['parent_id']][] = (int)$r['id_child'];
        }
    }
    $st = fcv_stats_carte($idsP, array_merge([], ...array_values($figliDi ?: [[]])));
    $schede = array_values(array_filter($schede, function ($s) use ($st, $figliDi) {
        $chiavi = ['p' . (int)$s['id']];
        foreach ($figliDi[(int)$s['id']] ?? [] as $f) $chiavi[] = 'c' . $f;
        foreach ($chiavi as $k) {
            $x = $st[$k] ?? ['c' => 0, 'w' => 0, 'u' => ''];
            if (fcv_livello($x['c'], $x['w'], $x['u']) === 'difficile') return true;
        }
        return false;
    }));
    if (empty($schede)) {
        header('Location: ' . fc_url('fc_decks.php', ['nessuna_difficile' => 1]));
        exit();
    }
    $order = 'random';
    /* i figli si ricontano sulle sole carte rimaste */
    $figli = 0;
    foreach ($schede as $s) $figli += count($figliDi[(int)$s['id']] ?? []);
}

$tot_padri = count($schede);

/* game_order si scrive subito: chi riprende la partita senza "order"
   nell'indirizzo lo legge da qui, e prima restava sempre 'random'. */
sec_q("INSERT INTO fc_games (game_date, score, type, kind, course_id, card_status, game_order, user_id,
                          total_father_words, total_words_with_children)
       VALUES (NOW(), 0, ?, ?, ?, ?, ?, ?, ?, ?)",
      [$game_type, fc_tipo(), fc_is_vocab() ? fc_corso_id() : null, $status, $order, $current_user_id, $tot_padri, $tot_padri + $figli]);
$new_game_id = sec_insert_id();

sec_q("INSERT INTO fc_game_durations (game_id, start_time, duration_seconds, updated_at)
       VALUES (?, NOW(), 0, NOW())", [$new_game_id]);

foreach ($valid_cats as $i => $cid) {
    sec_q("INSERT INTO " . fc_t('game_decks') . " (game_id, fc_category_id, category_order) VALUES (?, ?, ?)",
          [$new_game_id, $cid, $i]);
}

if ($order === 'random') {
    shuffle($schede);
} else {
    /* In ordine: prima la categoria (secondo la scelta dell'utente),
       poi l'ordine personalizzato della scheda dentro la categoria. */
    $pos_cat = array_flip($valid_cats);
    usort($schede, function ($a, $b) use ($pos_cat) {
        $ca = $pos_cat[(int)$a['category_id']] ?? PHP_INT_MAX;
        $cb = $pos_cat[(int)$b['category_id']] ?? PHP_INT_MAX;
        if ($ca !== $cb) return $ca <=> $cb;
        return ((int)$a['custom_order']) <=> ((int)$b['custom_order']);
    });
}

/* Scritta a blocchi: una INSERT per riga su una selezione enorme sarebbe
   lenta proprio nel momento in cui l'utente sta aspettando. */
$righe = [];
$posizione = 0;
foreach ($schede as $s) {
    $posizione++;
    $righe[] = [$new_game_id, $posizione, (int)$s['id']];
}

foreach (array_chunk($righe, 300) as $blocco) {
    $valori = [];
    $ph_riga = implode(',', array_fill(0, count($blocco), '(?, ?, ?)'));
    foreach ($blocco as $r) { $valori = array_merge($valori, $r); }
    sec_q("INSERT INTO " . fc_t('sequence') . " (game_id, position, fc_image_id) VALUES $ph_riga", $valori);
}

header("Location: fc_game.php?game_id=" . (int)$new_game_id
       . "&position=1&status=" . urlencode($status) . "&order=" . urlencode($order));
exit();
