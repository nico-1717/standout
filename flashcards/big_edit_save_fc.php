<?php
/* Salvataggio della modifica in blocco delle flashcard di una categoria.
 *
 * COSA E' CAMBIATO E PERCHE'
 *
 * 1. I file caricati - dodici punti, tutti con lo stesso difetto. L'estensione
 *    veniva presa dal nome scelto dal client (pathinfo del nome originale) e il
 *    file spostato senza guardarne il contenuto: bastava caricare "x.php" per
 *    ritrovarsi un file eseguibile in una cartella raggiungibile dal browser.
 *    Ora tutto passa da fc_salva_file(), che usa sec_file() (tipo vero letto con
 *    finfo) e sec_safe_filename() (nome generato dal server).
 *
 * 2. Le query. Erano costruite incollando pezzi di SET calcolati a mano
 *    (", question_image = '$final'") dentro la stringa. Con i segnaposto quel
 *    modo non regge: ora le colonne da aggiornare si raccolgono in due elenchi
 *    paralleli - i "campo = ?" e i valori - che crescono insieme e non possono
 *    sfasarsi.
 *
 * 3. CSRF e limite di frequenza: non c'erano.
 *
 * 4. La stessa logica di caricamento era ripetuta tre volte identica. Ora e' una
 *    funzione sola: tre copie divergono al primo ritocco, ed e' esattamente cosi'
 *    che un difetto sopravvive in due posti su tre.
 */
require_once 'config.php';
require_once 'auth_check.php';

$current_user_id = (int)$utente_corrente['id'];
$conn = sec_db();

header('Content-Type: application/json');

/* Token di sessione, origine riconosciuta e limite di frequenza. */
sec_guard_write($current_user_id, 'fc_big_edit_save', 0, 200, 60);

$categoryId = sec_int($_POST['category_id'] ?? 0, 1);
if (!sec_owns('fc_categories', $categoryId, $current_user_id)) {
    echo json_encode(['success' => false, 'error' => t('fc.non_autorizzato_categoria')]);
    exit;
}

$cards     = (isset($_POST['cards']) && is_array($_POST['cards'])) ? $_POST['cards'] : [];
$reordered = sec_int($_POST['reordered'] ?? 1, 0, 1, 1);

/* Le cartelle di destinazione e i tipi ammessi. L'audio non e' un'immagine,
   quindi ha il suo elenco: sec_file() controlla il tipo vero, non l'estensione. */
const FC_AUDIO_DIR = 'fc_audio/';
const FC_AUDIO_MIME = [
    'audio/mpeg' => 'mp3', 'audio/mp3' => 'mp3', 'audio/wav' => 'wav',
    'audio/x-wav' => 'wav', 'audio/webm' => 'webm', 'audio/ogg' => 'ogg',
    'audio/mp4' => 'm4a', 'audio/aac' => 'aac',
];

/* Salva un file di una riga del modulo. Restituisce il nome scritto dal server,
   oppure null se non e' stato caricato niente o se il contenuto non e' del tipo
   giusto. Il nome scelto dall'utente non viene mai usato. */
function fc_salva_file(string $campo, int $i, bool $audio): ?string {
    if (!isset($_FILES[$campo]['name'][$i])) return null;
    if ((int)$_FILES[$campo]['error'][$i] !== UPLOAD_ERR_OK) return null;

    $file = [
        'tmp_name' => $_FILES[$campo]['tmp_name'][$i],
        'error'    => $_FILES[$campo]['error'][$i],
        'size'     => $_FILES[$campo]['size'][$i],
    ];
    $ris = $audio
        ? sec_file($file, FC_AUDIO_MIME, 10485760)   // 10 MB: un audio pesa piu' di un'immagine
        : sec_file($file);
    if (empty($ris['ok'])) return null;

    $nome = sec_safe_filename($ris['ext']);
    $dest = ($audio ? FC_AUDIO_DIR : FC_IMAGE_PATH_INSERT) . $nome;
    return move_uploaded_file($ris['tmp'], $dest) ? $nome : null;
}

/* I quattro allegati di una riga, in una volta sola. */
function fc_allegati(int $i): array {
    return [
        'question_image' => fc_salva_file('row_q_img', $i, false),
        'answer_image'   => fc_salva_file('row_a_img', $i, false),
        'question_audio' => fc_salva_file('audio_q',   $i, true),
        'answer_audio'   => fc_salva_file('audio_a',   $i, true),
    ];
}

/* Da un elenco di allegati costruisce le clausole "campo = ?" e i valori.
   Restituiti insieme apposta: separati, prima o poi si sfasano. */
function fc_set_allegati(array $all): array {
    $sets = []; $vals = [];
    foreach ($all as $campo => $nome) {
        if ($nome === null) continue;         // non caricato: la colonna non si tocca
        $sets[] = "$campo = ?";
        $vals[] = $nome;
    }
    return [$sets, $vals];
}

function fc_testo($v): string { return sec_str($v ?? '', 2000); }

/* ============================================================
   PERCORSO VELOCE: nessun cambio di struttura, solo testi e allegati
   ============================================================ */
if ($reordered === 0) {
    foreach ($cards as $i => $c) {
        if (!is_array($c)) continue;
        $status = sec_enum($c['status'] ?? null, ['clean', 'new', 'edited', 'deleted'], 'clean');
        if ($status === 'clean' || $status === 'deleted') continue;

        $id        = sec_int($c['id'] ?? 0, 1);
        $orig_type = sec_enum($c['orig_type'] ?? null, ['parent', 'child'], 'parent');
        $q = fc_testo($c['q'] ?? '');
        $a = fc_testo($c['a'] ?? '');

        [$sets, $vals] = fc_set_allegati(fc_allegati((int)$i));
        $clausole = array_merge(['question_desc = ?', 'answer_desc = ?'], $sets);
        $valori   = array_merge([$q, $a], $vals);

        if ($orig_type === 'parent') {
            sec_q("UPDATE fc_images SET " . implode(', ', $clausole) . " WHERE id = ? AND category_id = ?",
                  array_merge($valori, [$id, $categoryId]));
        } else {
            sec_q("UPDATE fc_child_cards SET " . implode(', ', $clausole) . "
                    WHERE id = ? AND parent_id IN (SELECT id FROM fc_images WHERE category_id = ?)",
                  array_merge($valori, [$id, $categoryId]));
        }
    }
    echo json_encode(['success' => true]);
    exit;
}

/* ============================================================
   PERCORSO COMPLETO: riordino e cambi di struttura
   ============================================================ */

/* Elimina una riga, padre o figlio che sia. La categoria resta sempre nella
   clausola: una riga di un'altra categoria non si tocca. */
function fc_elimina(string $tipo, int $id, int $categoryId): void {
    if ($id <= 0) return;
    if ($tipo === 'parent') {
        sec_q("DELETE FROM fc_images WHERE id = ? AND category_id = ?", [$id, $categoryId]);
    } else {
        sec_q("DELETE FROM fc_child_cards
                WHERE id = ? AND parent_id IN (SELECT id FROM fc_images WHERE category_id = ?)",
              [$id, $categoryId]);
    }
}

$parent_real_ids = [];
$custom_order_parent = 1;

// --- FASE 1: i padri ---
foreach ($cards as $i => $c) {
    if (!is_array($c) || (int)($c['is_child'] ?? 0) === 1) continue;

    $status    = sec_enum($c['status'] ?? null, ['clean', 'new', 'edited', 'deleted'], 'clean');
    $id        = sec_int($c['id'] ?? 0, 0);
    $orig_type = sec_enum($c['orig_type'] ?? null, ['parent', 'child'], 'parent');

    if ($status === 'deleted') { fc_elimina($orig_type, $id, $categoryId); continue; }

    $q = fc_testo($c['q'] ?? '');
    $a = fc_testo($c['a'] ?? '');
    $all = fc_allegati((int)$i);

    $real_parent_id = 0;

    if ($status === 'new' || $orig_type === 'child') {
        /* Una riga nuova, oppure un figlio promosso a padre: in entrambi i casi
           nasce una riga in fc_images. Se era un figlio, la vecchia si toglie. */
        if ($orig_type === 'child') fc_elimina('child', $id, $categoryId);

        sec_q("INSERT INTO fc_images (category_id, question_desc, answer_desc, question_image, answer_image,
                                      question_audio, answer_audio, is_father, custom_order)
               VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?)",
              [$categoryId, $q, $a, $all['question_image'], $all['answer_image'],
               $all['question_audio'], $all['answer_audio'], $custom_order_parent]);
        $real_parent_id = sec_insert_id();
    } else {
        [$sets, $vals] = fc_set_allegati($all);
        $clausole = array_merge(['custom_order = ?', 'question_desc = ?', 'answer_desc = ?'], $sets);
        $valori   = array_merge([$custom_order_parent, $q, $a], $vals);
        sec_q("UPDATE fc_images SET " . implode(', ', $clausole) . " WHERE id = ? AND category_id = ?",
              array_merge($valori, [$id, $categoryId]));
        $real_parent_id = $id;
    }

    $parent_real_ids[$i] = $real_parent_id;
    $custom_order_parent++;
}

// --- FASE 2: i figli ---
$custom_order_child = [];
foreach ($cards as $i => $c) {
    if (!is_array($c) || (int)($c['is_child'] ?? 0) === 0) continue;

    $status    = sec_enum($c['status'] ?? null, ['clean', 'new', 'edited', 'deleted'], 'clean');
    $id        = sec_int($c['id'] ?? 0, 0);
    $orig_type = sec_enum($c['orig_type'] ?? null, ['parent', 'child'], 'child');

    if ($status === 'deleted') { fc_elimina($orig_type, $id, $categoryId); continue; }

    $idx = sec_int($c['parent_idx'] ?? 0, 0);
    $real_parent_id = $parent_real_ids[$idx] ?? 0;
    if ($real_parent_id <= 0) continue;

    if (!isset($custom_order_child[$real_parent_id])) $custom_order_child[$real_parent_id] = 1;
    $ordine = $custom_order_child[$real_parent_id];

    $q = fc_testo($c['q'] ?? '');
    $a = fc_testo($c['a'] ?? '');
    $all = fc_allegati((int)$i);

    if ($status === 'new' || $orig_type === 'parent') {
        /* Riga nuova, oppure un padre retrocesso a figlio: nasce una riga in
           fc_child_cards, e se era un padre la vecchia si toglie. */
        if ($orig_type === 'parent') fc_elimina('parent', $id, $categoryId);

        sec_q("INSERT INTO fc_child_cards (parent_id, question_desc, answer_desc, question_image, answer_image,
                                           question_audio, answer_audio, custom_order)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
              [$real_parent_id, $q, $a, $all['question_image'], $all['answer_image'],
               $all['question_audio'], $all['answer_audio'], $ordine]);
    } else {
        [$sets, $vals] = fc_set_allegati($all);
        $clausole = array_merge(['parent_id = ?', 'custom_order = ?', 'question_desc = ?', 'answer_desc = ?'], $sets);
        $valori   = array_merge([$real_parent_id, $ordine, $q, $a], $vals);
        sec_q("UPDATE fc_child_cards SET " . implode(', ', $clausole) . "
                WHERE id = ? AND parent_id IN (SELECT id FROM fc_images WHERE category_id = ?)",
              array_merge($valori, [$id, $categoryId]));
    }

    sec_q("UPDATE fc_images SET is_father = 1 WHERE id = ? AND category_id = ?", [$real_parent_id, $categoryId]);
    $custom_order_child[$real_parent_id]++;
}

echo json_encode(['success' => true]);
