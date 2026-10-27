<?php
/* ai_shatter_save.php — salva il risultato di "Frantuma" come carte vere.
 *
 * Due azioni (campo "action" del corpo JSON):
 *
 *   info   Cosa ha attaccato la carta originale: le 4 immagini/audio e gli
 *          esempi. Serve alla pagina per chiedere all'utente dove mettere
 *          ciascuno quando sceglie di SOSTITUIRE.
 *   save   Crea le carte. Due modi:
 *            keep     (predefinito) nasce una carta PADRE col titolo, risposta
 *                     vuota, e un figlio per mini carta, subito dopo l'originale.
 *                     L'originale non si tocca.
 *            replace  l'originale scompare e al suo posto c'e' la famiglia:
 *              - originale PADRE: la riga resta la stessa e DIVENTA la carta
 *                titolo (titolo come domanda, risposta vuota); le mini carte
 *                diventano suoi figli, davanti ai figli che aveva gia'. Tenere
 *                la stessa riga significa che partita in corso, "imparata",
 *                preferiti e storico restano agganciati.
 *              - originale FIGLIO: un figlio non puo' avere figli, quindi si
 *                cancella e si crea la famiglia (titolo + figli) come nuovo
 *                padre subito dopo il suo padre.
 *            Gli allegati dell'originale (immagini, audio, esempi) non si
 *            perdono mai per distrazione: per ognuno l'utente sceglie la carta
 *            di destinazione o "non tenerlo", e il server rifiuta la
 *            sostituzione se manca anche una sola scelta.
 *
 * Risposta: { success, mode, parent_id, children, reload } o { success:false, error }.
 */

// Il corpo si legge SUBITO, prima dei require (vedi ai_example.php).
$__raw_input = file_get_contents('php://input');

require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/auth_check_api.php';
require_once __DIR__ . '/../../lib/fc/fc_ai_saved.php';
require_once __DIR__ . '/../../lib/fc/fc_frantuma.php';
require_once __DIR__ . '/../../lib/fc/fc_files.php';   // fc_file_in_uso(): i file sono condivisi fra mazzi copiati

header('Content-Type: application/json');

$uid = (int)$utente_corrente['id'];

/* Token di sessione, origine riconosciuta e limite di frequenza. */
sec_guard_write($uid, 'fc_ai_frantuma_salva', 0, 30, 60);

function fc_frantuma_salva_errore(string $msg): void {
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

/* Come fc_testo() di fc_insert_save.php: via i caratteri di controllo, e un
   tetto di lunghezza. Niente sec_text(): potrebbe tagliare un '<' di una
   formula, e il testo delle carte non e' HTML. */
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

$azione  = ($data['action'] ?? 'save') === 'info' ? 'info' : 'save';
$id_orig = sec_int($data['card_id'] ?? 0, 1);
$figlio  = !empty($data['is_child']);

/* La carta da cui si e' partiti, SOLO se e' dell'utente: serve il mazzo, il
   posto in cui mettere la nuova famiglia e gli allegati. Un figlio si risale
   dal suo padre. */
$ALL = 'question_image, question_audio, answer_image, answer_audio';
if ($figlio) {
    $orig = sec_one("SELECT s.parent_id AS padre, p.category_id AS cat, p.custom_order AS ord,
                            s.question_image, s.question_audio, s.answer_image, s.answer_audio
                       FROM fc_study_subcards s
                       JOIN fc_study_cards p ON p.id = s.parent_id
                       JOIN fc_study_decks d ON d.id = p.category_id
                      WHERE s.id_child = ? AND d.user_id = ? LIMIT 1", [$id_orig, $uid]);
} else {
    $orig = sec_one("SELECT c.id AS padre, c.category_id AS cat, c.custom_order AS ord,
                            c.question_image, c.question_audio, c.answer_image, c.answer_audio
                       FROM fc_study_cards c
                       JOIN fc_study_decks d ON d.id = c.category_id
                      WHERE c.id = ? AND d.user_id = ? LIMIT 1", [$id_orig, $uid]);
}
if (!$orig) {
    fc_frantuma_salva_errore(t('fc.non_autorizzato_categoria'));
}
$categoria = (int)$orig['cat'];
$posto     = (int)($orig['ord'] ?? 0);
$col_ex    = $figlio ? 'child_id' : 'parent_id';

/* Gli allegati che la carta ha davvero: chiave => valore (nome del file). */
$colonne_all = fc_frantuma_colonne_allegati();
$allegati = [];
foreach ($colonne_all as $chiave => $colonna) {
    $v = trim((string)($orig[$colonna] ?? ''));
    if ($v !== '') $allegati[$chiave] = $v;
}
$esempi = sec_all("SELECT id, example_text, example_image, example_audio
                     FROM fc_study_examples WHERE $col_ex = ? ORDER BY id", [$id_orig]);

/* ------------------------------------------------------------------ INFO */
if ($azione === 'info') {
    $voci = [];
    foreach (array_keys($allegati) as $chiave) $voci[] = ['key' => $chiave, 'kind' => $chiave];
    $n = 0;
    foreach ($esempi as $e) {
        $n++;
        $testo = trim(preg_replace('/\s+/u', ' ', fc_frantuma_in_testo((string)($e['example_text'] ?? ''))) ?? '');
        $voci[] = [
            'key'     => 'ex:' . (int)$e['id'],
            'kind'    => 'ex',
            'n'       => $n,
            'preview' => mb_substr($testo, 0, 50, 'UTF-8'),
            'image'   => trim((string)($e['example_image'] ?? '')) !== '',
            'audio'   => trim((string)($e['example_audio'] ?? '')) !== '',
        ];
    }
    echo json_encode(['success' => true, 'items' => $voci]);
    exit;
}

/* ------------------------------------------------------------------ SAVE */
$modo = ($data['mode'] ?? 'keep') === 'replace' ? 'replace' : 'keep';

$titolo = rtrim(fc_frantuma_testo($data['title'] ?? '', 200), " .\t");
if ($titolo === '') {
    fc_frantuma_salva_errore(t('fc.gm_frantuma_titolo_vuoto'));
}

/* Le mini carte: coppie domanda/risposta piene. Se una e' vuota si rifiuta
   tutto invece di saltarla: gli indici delle destinazioni degli allegati
   (la "carta 2") devono restare quelli che l'utente ha visto. Otto al massimo
   (l'IA ne scrive di solito due o tre). */
$figli = [];
foreach ((is_array($data['cards'] ?? null) ? $data['cards'] : []) as $c) {
    if (!is_array($c)) fc_frantuma_salva_errore(t('fc.gm_frantuma_poche'));
    $q = fc_frantuma_testo($c['q'] ?? '', 20000);
    $a = fc_frantuma_testo($c['a'] ?? '', 20000);
    if ($q === '' || $a === '') fc_frantuma_salva_errore(t('fc.gm_frantuma_poche'));
    $figli[] = [$q, $a];
}
if (count($figli) < 2 || count($figli) > 8) {
    fc_frantuma_salva_errore(t('fc.gm_frantuma_poche'));
}
$n = count($figli);

/* Dove va ciascun allegato (solo se si sostituisce). */
$att_titolo = [];                 // colonna => nome file, per la carta titolo
$att_figlio = array_fill(0, $n, []);   // indice => [colonna => nome file]
$dest_esempio = [];               // id esempio => indice | 'drop'
$da_togliere = [];                // [cartella, nome file] dei file scartati: si cancellano a salvataggio riuscito
if ($modo === 'replace') {
    $presenti = array_keys($allegati);
    foreach ($esempi as $e) $presenti[] = 'ex:' . (int)$e['id'];
    $verifica = fc_frantuma_assegnazioni($presenti, $data['assign'] ?? null, $n);
    if (!$verifica['ok']) {
        fc_frantuma_salva_errore(t('fc.gm_frantuma_scegli_tutto'));
    }
    $file_esempio = [];
    foreach ($esempi as $e) $file_esempio[(int)$e['id']] = $e;
    foreach ($verifica['map'] as $chiave => $dest) {
        if (strpos($chiave, 'ex:') === 0) {
            $id_ex = (int)substr($chiave, 3);
            $dest_esempio[$id_ex] = $dest;
            if ($dest === 'drop') {
                $img = trim((string)($file_esempio[$id_ex]['example_image'] ?? ''));
                $aud = trim((string)($file_esempio[$id_ex]['example_audio'] ?? ''));
                if ($img !== '') $da_togliere[] = [STANDOUT_DATA_DIR . 'example_images/', $img];
                if ($aud !== '') $da_togliere[] = [STANDOUT_DATA_DIR . 'flashcard_audio/', $aud];
            }
            continue;
        }
        if ($dest === 'drop') {
            /* Immagini di carta: cartella delle immagini di studio; audio: quella degli audio. */
            $da_togliere[] = [in_array($chiave, ['qimg', 'aimg'], true) ? FC_IMAGE_PATH_INSERT : STANDOUT_DATA_DIR . 'flashcard_audio/', $allegati[$chiave]];
            continue;
        }
        $colonna = $colonne_all[$chiave];
        if ($dest === 'title') $att_titolo[$colonna] = $allegati[$chiave];
        else $att_figlio[$dest][$colonna] = $allegati[$chiave];
    }
}

/**
 * Un file e' ancora usato da qualche carta o esempio, di QUALSIASI utente?
 * Va chiesto a tutte le tabelle che possono nominarlo: un mazzo copiato (o preso
 * dal mercato) punta agli stessi file dell'originale, e cancellarne uno ancora
 * in uso lascerebbe l'altro mazzo con un'immagine rotta.
 */
function fc_frantuma_file_usato(string $nome): bool {
    return fc_file_in_uso($nome);   // elenco delle tabelle in lib/fc/fc_files.php
}

/** Cancella dal disco i file che nessuno usa piu'. basename(): il nome viene dal database, ma non deve poter uscire dalla cartella. */
function fc_frantuma_pulisci_file(array $elenco): void {
    $visti = [];
    foreach ($elenco as [$cartella, $nome]) {
        $nome = basename((string)$nome);
        if ($nome === '' || isset($visti[$cartella . $nome])) continue;
        $visti[$cartella . $nome] = true;
        $f = rtrim($cartella, '/') . '/' . $nome;
        if (is_file($f) && !fc_frantuma_file_usato($nome)) @unlink($f);
    }
}

/** Inserisce un figlio col suo testo e gli eventuali allegati; ne restituisce l'id. */
function fc_frantuma_inserisci_figlio(int $padre, string $q, string $a, int $ordine, array $allegati): int {
    $cols = ['parent_id', 'question_desc', 'answer_desc', 'custom_order'];
    $vals = [$padre, $q, $a, $ordine];
    foreach ($allegati as $colonna => $file) { $cols[] = $colonna; $vals[] = $file; }   // colonne: sempre da fc_frantuma_colonne_allegati()
    sec_q("INSERT INTO fc_study_subcards (" . implode(', ', $cols) . ")
           VALUES (" . implode(', ', array_fill(0, count($cols), '?')) . ")", $vals);
    $id = sec_insert_id();
    if ($id <= 0) throw new RuntimeException('figlio non creato');
    return $id;
}

/* Prima della transazione: se la tabella dei testi salvati dei figli va creata,
   CREATE TABLE chiude da sola la transazione in corso, e il salvataggio
   resterebbe a meta'. */
[$tab_ai, $col_ai] = fc_ai_dove($figlio);

$conn = sec_db();
mysqli_begin_transaction($conn);
try {
    $padre_nuovo = 0;
    $id_figli = [];

    if ($modo === 'replace' && !$figlio) {
        /* L'originale e' un padre: resta la stessa riga e diventa il titolo. */
        $padre_nuovo = $id_orig;
        /* I figli che aveva gia' scalano in fondo: le mini carte vengono prima. */
        sec_q("UPDATE fc_study_subcards SET custom_order = custom_order + ? WHERE parent_id = ?", [$n, $padre_nuovo]);
        foreach ($figli as $i => [$q, $a]) {
            $id_figli[$i] = fc_frantuma_inserisci_figlio($padre_nuovo, $q, $a, $i + 1, $att_figlio[$i]);
        }
        /* Il padre diventa titolo: domanda = titolo, risposta e suoi allegati
           di risposta via (una carta titolo con una risposta non e' un titolo). */
        sec_q("UPDATE fc_study_cards
                  SET question_desc = ?, answer_desc = '', is_father = 1,
                      question_image = ?, question_audio = ?, answer_image = NULL, answer_audio = NULL
                WHERE id = ? AND category_id = ?",
              [$titolo, $att_titolo['question_image'] ?? null, $att_titolo['question_audio'] ?? null, $padre_nuovo, $categoria]);
        /* Una partita in corso ha gia' la riga di questa carta, vuota: i titoli
           partono con 'Titolo' (vedi fc_game.php). Quelle gia' risposte restano. */
        sec_q("UPDATE fc_study_plays SET user_answer = 'Titolo', correct_answer = ''
                WHERE fc_image_id = ? AND fc_child_id IS NULL AND (user_answer IS NULL OR user_answer = '')", [$padre_nuovo]);
    } else {
        /* Nuova famiglia subito dopo l'originale (o dopo il padre dell'originale).
           Si fa posto spostando di uno quelle che seguono. */
        sec_q("UPDATE fc_study_cards SET custom_order = custom_order + 1
                WHERE category_id = ? AND custom_order > ?", [$categoria, $posto]);
        sec_q("INSERT INTO fc_study_cards (category_id, question_desc, answer_desc, is_father, custom_order,
                                           question_image, question_audio)
               VALUES (?, ?, '', 1, ?, ?, ?)",
              [$categoria, $titolo, $posto + 1, $att_titolo['question_image'] ?? null, $att_titolo['question_audio'] ?? null]);
        $padre_nuovo = sec_insert_id();
        if ($padre_nuovo <= 0) throw new RuntimeException('padre non creato');
        foreach ($figli as $i => [$q, $a]) {
            $id_figli[$i] = fc_frantuma_inserisci_figlio($padre_nuovo, $q, $a, $i + 1, $att_figlio[$i]);
        }
    }

    if ($modo === 'replace') {
        /* Gli esempi seguono la scelta: nella mini carta indicata, oppure via.
           La condizione sulla colonna d'origine impedisce di toccare esempi
           che non sono di questa carta. */
        foreach ($dest_esempio as $id_ex => $dest) {
            if ($dest === 'drop') {
                sec_q("DELETE FROM fc_study_examples WHERE id = ? AND $col_ex = ?", [$id_ex, $id_orig]);
            } else {
                sec_q("UPDATE fc_study_examples SET parent_id = NULL, child_id = ? WHERE id = ? AND $col_ex = ?",
                      [$id_figli[$dest], $id_ex, $id_orig]);
            }
        }
        /* Spiegazione e metafora salvate parlavano del testo di prima. */
        sec_q("DELETE FROM $tab_ai WHERE $col_ai = ?", [$id_orig]);

        if ($figlio) {
            /* L'originale era un figlio: ora c'e' la famiglia nuova, il figlio va via
               (le sue partite con lui) e il suo padre, se non ha altri figli,
               smette di esserlo. Preferiti del figlio: la colonna puo' non
               esserci su un database vecchio, e senza il figlio non si vedrebbero
               comunque. */
            sec_q("DELETE FROM fc_study_plays WHERE fc_child_id = ?", [$id_orig]);
            sec_q("DELETE FROM fc_study_subcards WHERE id_child = ? AND parent_id = ?", [$id_orig, (int)$orig['padre']]);
            $restano = (int)(sec_one("SELECT COUNT(*) AS c FROM fc_study_subcards WHERE parent_id = ?", [(int)$orig['padre']])['c'] ?? 0);
            sec_q("UPDATE fc_study_cards SET is_father = ? WHERE id = ? AND category_id = ?",
                  [$restano > 0 ? 1 : 0, (int)$orig['padre'], $categoria]);
        }
    }
    mysqli_commit($conn);
} catch (Throwable $e) {
    mysqli_rollback($conn);
    sec_log_error('frantuma_salva', $e->getMessage());
    fc_frantuma_salva_errore(t('fc.gm_frantuma_salva_errore'));
}

/* Le righe sono salvate: ora i file scartati che nessun'altra carta usa si
   cancellano dal disco. Dopo il commit e non prima, perche' un file non si
   "annulla" come una riga: se il salvataggio fosse fallito sarebbe perso. */
fc_frantuma_pulisci_file($da_togliere);

echo json_encode([
    'success'   => true,
    'mode'      => $modo,
    'parent_id' => $padre_nuovo,
    'children'  => $n,
    'reload'    => $modo === 'replace',
]);
