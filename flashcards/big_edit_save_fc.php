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
 *
 * 5. AGGIUNTA (salvataggio automatico). La risposta ora contiene "map": per
 *    ogni indice di riga inviato, l'id vero della carta nel database e se e'
 *    padre o figlio. Serve alla pagina per aggiornarsi da sola invece di
 *    ricaricarsi: con il salvataggio automatico un ricaricamento ogni due
 *    secondi sarebbe insopportabile, e per le carte nuove e' l'unico modo di
 *    sapere l'id appena nato (senza id non si possono agganciare esempi).
 *
 * 6. AGGIUNTA (big insert). Con "append=1" il custom_order dei padri non
 *    riparte da 1 ma dal primo numero libero della categoria: big_edit ha in
 *    pagina tutte le carte e le puo' rinumerare, big insert ne ha solo un
 *    pezzo e rinumerando da 1 calpesterebbe quelle che gia' c'erano.
 *
 * 7. CORREZIONE. Le tre query su fc_child_cards (aggiornamento veloce,
 *    aggiornamento nel percorso completo, cancellazione) cercavano la riga
 *    con "WHERE id = ?", ma su questa tabella la chiave si chiama id_child,
 *    non id (id e' la chiave di fc_images). La query falliva sempre - non
 *    con un errore silenzioso, con un errore fatale non gestito, quindi il
 *    file finiva senza mandare risposta: da qui il "risposta vuota" visto
 *    dal browser come "Unexpected end of JSON input" a ogni salvataggio
 *    successivo di una carta figlia gia' esistente (la primissima INSERT,
 *    che non usa questa clausola, andava sempre a buon fine).
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
$append    = sec_int($_POST['append'] ?? 0, 0, 1, 0);

/* indice della riga inviata -> id vero e tipo. La pagina la usa per
   aggiornarsi senza ricaricarsi. */
$mappa = [];

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

/* NON e' testo semplice: e' il documento intero della domanda o della
   risposta, cosi' come lo salva fc_parser.js — un JSON con dentro
   paragrafi, elenchi, formule. sec_str() era pensata per un campo di
   testo su una riga, e toglie i tag HTML per proteggersi da chi scrive
   "<script>" in una casella — ma qui un "<" dentro una formula o in mezzo
   a una frase ("<0", "x < y") non e' un tag, e' un dato dentro una
   stringa JSON, e strip_tags() non lo sa distinguere: se non trova mai il
   ">" che "chiuderebbe" quel presunto tag, cancella tutto da li' alla
   fine della stringa. Cosi' spariva meta' risposta a ogni salvataggio —
   ed e' esattamente quello che succedeva.
   Il confine giusto qui non e' "niente HTML dentro": e' "questo testo non
   deve MAI essere stampato cosi' com'e' in una pagina", ed e' un impegno
   che fc_parser.js gia' rispetta ogni volta che lo rilegge (fcdEscape,
   verificato). Al salvataggio restano da togliere solo i caratteri di
   controllo (mai legittimi in un JSON) e un tetto di lunghezza — ampio,
   perche' un documento con anche solo qualche formula supera presto i
   2000 caratteri che bastavano al vecchio testo semplice. */
function fc_testo($v): string {
    $s = is_string($v) ? $v : '';
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s) ?? '';
    if (strlen($s) > 50000) $s = substr($s, 0, 50000);
    return $s;
}

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
                    WHERE id_child = ? AND parent_id IN (SELECT id FROM fc_images WHERE category_id = ?)",
                  array_merge($valori, [$id, $categoryId]));
        }
        $mappa[(int)$i] = ['id' => $id, 'type' => $orig_type];
    }
    echo json_encode(['success' => true, 'map' => $mappa]);
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
                WHERE id_child = ? AND parent_id IN (SELECT id FROM fc_images WHERE category_id = ?)",
              [$id, $categoryId]);
    }
}

$parent_real_ids = [];

/* In big_edit la pagina contiene tutte le carte della categoria, quindi
   rinumerare da 1 e' giusto. In big insert ce ne sono solo di nuove: si parte
   dal primo numero libero, altrimenti le carte gia' esistenti si ritroverebbero
   lo stesso ordine di quelle appena inserite. */
$custom_order_parent = 1;
if ($append) {
    $custom_order_parent = (int)(sec_one("SELECT MAX(custom_order) AS m FROM fc_images WHERE category_id = ?",
                                         [$categoryId])['m'] ?? 0) + 1;
}

// --- FASE 1: i padri ---
foreach ($cards as $i => $c) {
    if (!is_array($c) || (int)($c['is_child'] ?? 0) === 1) continue;

    $status    = sec_enum($c['status'] ?? null, ['clean', 'new', 'edited', 'deleted'], 'clean');
    $id        = sec_int($c['id'] ?? 0, 0);
    $orig_type = sec_enum($c['orig_type'] ?? null, ['parent', 'child'], 'parent');

    if ($status === 'deleted') {
        fc_elimina($orig_type, $id, $categoryId);
        $mappa[(int)$i] = ['id' => 0, 'type' => 'deleted'];
        continue;
    }

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
    $mappa[(int)$i] = ['id' => $real_parent_id, 'type' => 'parent'];
    $custom_order_parent++;
}

// --- FASE 2: i figli ---
$custom_order_child = [];
foreach ($cards as $i => $c) {
    if (!is_array($c) || (int)($c['is_child'] ?? 0) === 0) continue;

    $status    = sec_enum($c['status'] ?? null, ['clean', 'new', 'edited', 'deleted'], 'clean');
    $id        = sec_int($c['id'] ?? 0, 0);
    $orig_type = sec_enum($c['orig_type'] ?? null, ['parent', 'child'], 'child');

    if ($status === 'deleted') {
        fc_elimina($orig_type, $id, $categoryId);
        $mappa[(int)$i] = ['id' => 0, 'type' => 'deleted'];
        continue;
    }

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
        $id = sec_insert_id();   // serve alla mappa: e' l'id con cui la pagina continuera' a lavorare
    } else {
        [$sets, $vals] = fc_set_allegati($all);
        $clausole = array_merge(['parent_id = ?', 'custom_order = ?', 'question_desc = ?', 'answer_desc = ?'], $sets);
        $valori   = array_merge([$real_parent_id, $ordine, $q, $a], $vals);
        sec_q("UPDATE fc_child_cards SET " . implode(', ', $clausole) . "
                WHERE id_child = ? AND parent_id IN (SELECT id FROM fc_images WHERE category_id = ?)",
              array_merge($valori, [$id, $categoryId]));
    }

    sec_q("UPDATE fc_images SET is_father = 1 WHERE id = ? AND category_id = ?", [$real_parent_id, $categoryId]);
    $mappa[(int)$i] = ['id' => $id, 'type' => 'child'];
    $custom_order_child[$real_parent_id]++;
}

echo json_encode(['success' => true, 'map' => $mappa]);
