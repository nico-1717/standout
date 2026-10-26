<?php
/* ai_shatter_save.php — salva il risultato di "Frantuma" come carte vere.
 *
 * Arriva dal pannello di ai_shatter.php: un titolo e le mini carte. Si crea:
 *   - una carta PADRE col titolo come domanda e la risposta vuota (con almeno
 *     due figli e senza risposta il gioco la mostra come carta titolo, vedi
 *     fc_e_titolo in fc_game.php);
 *   - un FIGLIO per ogni mini carta, in fc_study_subcards.
 * La carta originale NON si tocca: cancellarla o cambiarla senza che l'utente
 * lo abbia chiesto sarebbe peggio di averne una copia in piu', che si toglie
 * in un attimo. La nuova famiglia si mette subito dopo l'originale, nello
 * stesso mazzo, cosi' si ritrova dove ci si aspetta.
 *
 * Risposta: { success, parent_id, children } oppure { success:false, error }.
 */

// Il corpo si legge SUBITO, prima dei require (vedi ai_example.php).
$__raw_input = file_get_contents('php://input');

require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/auth_check_api.php';

header('Content-Type: application/json');

$uid = (int)$utente_corrente['id'];

/* Token di sessione, origine riconosciuta e limite di frequenza. */
sec_guard_write($uid, 'fc_ai_frantuma_salva', 0, 20, 60);

function fc_frantuma_salva_errore(string $msg): void {
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

/* Come fc_testo() di fc_insert_save.php: via i caratteri di controllo, e un
   tetto di lunghezza. Niente sec_text(): potrebbe tagliare un '<' di una
   formula, e il testo delle carte e' un documento di fc_parser, non HTML. */
function fc_frantuma_testo($v, int $max): string {
    $s = is_string($v) ? $v : '';
    $s = str_replace(["\r\n", "\r"], "\n", $s);
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s) ?? '';
    return trim(mb_substr($s, 0, $max, 'UTF-8'));
}

$data = json_decode($__raw_input, true);
if (!is_array($data)) {
    fc_frantuma_salva_errore(t('ai.nessun_dato'));
}

$id_orig = sec_int($data['card_id'] ?? 0, 1);
$figlio  = !empty($data['is_child']);

/* La carta da cui si e' partiti: serve il mazzo (di chi e') e il posto in cui
   mettere la nuova famiglia. Un figlio si risale dal suo padre. */
if ($figlio) {
    $orig = sec_one("SELECT p.category_id AS cat, p.custom_order AS ord
                       FROM fc_study_subcards s
                       JOIN fc_study_cards p ON p.id = s.parent_id
                       JOIN fc_study_decks d ON d.id = p.category_id
                      WHERE s.id_child = ? AND d.user_id = ? LIMIT 1", [$id_orig, $uid]);
} else {
    $orig = sec_one("SELECT c.category_id AS cat, c.custom_order AS ord
                       FROM fc_study_cards c
                       JOIN fc_study_decks d ON d.id = c.category_id
                      WHERE c.id = ? AND d.user_id = ? LIMIT 1", [$id_orig, $uid]);
}
if (!$orig) {
    fc_frantuma_salva_errore(t('fc.non_autorizzato_categoria'));
}
$categoria = (int)$orig['cat'];
$posto     = (int)($orig['ord'] ?? 0);

$titolo = fc_frantuma_testo($data['title'] ?? '', 200);
$titolo = rtrim($titolo, " .\t");
if ($titolo === '') {
    fc_frantuma_salva_errore(t('fc.gm_frantuma_titolo_vuoto'));
}

/* Le mini carte: coppie domanda/risposta piene. Otto al massimo (l'IA ne
   scrive di solito due o tre): il tetto evita che un corpo manomesso riempia
   il mazzo. Meno di due non e' una famiglia. */
$figli = [];
foreach ((is_array($data['cards'] ?? null) ? $data['cards'] : []) as $c) {
    if (!is_array($c)) continue;
    $q = fc_frantuma_testo($c['q'] ?? '', 20000);
    $a = fc_frantuma_testo($c['a'] ?? '', 20000);
    if ($q === '' || $a === '') continue;
    $figli[] = [$q, $a];
    if (count($figli) >= 8) break;
}
if (count($figli) < 2) {
    fc_frantuma_salva_errore(t('fc.gm_frantuma_poche'));
}

$conn = sec_db();
mysqli_begin_transaction($conn);
try {
    /* Si fa posto subito dopo l'originale spostando di uno quelle che seguono.
       Se l'originale non ha un ordine (0, carte vecchie) la nuova famiglia
       finisce comunque dopo di lui senza scavalcare nessuno. */
    sec_q("UPDATE fc_study_cards SET custom_order = custom_order + 1
            WHERE category_id = ? AND custom_order > ?", [$categoria, $posto]);

    sec_q("INSERT INTO fc_study_cards (category_id, question_desc, answer_desc, is_father, custom_order)
           VALUES (?, ?, '', 1, ?)", [$categoria, $titolo, $posto + 1]);
    $padre = sec_insert_id();
    if ($padre <= 0) throw new RuntimeException('padre non creato');

    $n = 1;
    foreach ($figli as [$q, $a]) {
        sec_q("INSERT INTO fc_study_subcards (parent_id, question_desc, answer_desc, custom_order)
               VALUES (?, ?, ?, ?)", [$padre, $q, $a, $n]);
        $n++;
    }
    mysqli_commit($conn);
} catch (Throwable $e) {
    mysqli_rollback($conn);
    sec_log_error('frantuma_salva', $e->getMessage());
    fc_frantuma_salva_errore(t('fc.gm_frantuma_salva_errore'));
}

echo json_encode(['success' => true, 'parent_id' => $padre, 'children' => count($figli)]);
