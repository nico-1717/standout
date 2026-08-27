<?php
/* Salvataggio della modifica in blocco delle schede di una categoria.
 *
 * COSA E' CAMBIATO E PERCHE'
 *
 * 1. I file caricati. Prima l'estensione veniva presa dal nome scelto dal
 *    client (pathinfo del nome originale) e il file spostato senza altri
 *    controlli: bastava caricare "foto.php" per ritrovarsi un file eseguibile
 *    dentro la cartella delle immagini, raggiungibile dal browser. Ora passa da
 *    sec_file(), che guarda il contenuto vero con finfo e non l'estensione
 *    dichiarata, e da sec_safe_filename(), che genera il nome sul server.
 *
 * 2. Le query. Erano costruite per concatenazione con real_escape_string. Non
 *    era sfruttabile, ma il numero di punti in cui bisognava ricordarsene era
 *    alto. Ora sono tutte prepared statement.
 *
 * 3. CSRF e limite di frequenza. Non c'erano: un altro sito poteva far partire
 *    dal browser di chi e' connesso una richiesta che svuota una categoria.
 *
 * 4. Proprieta'. La verifica c'era ed era corretta; ora e' detta con sec_owns(),
 *    e ogni query di scrittura continua a portare "AND category_id = ?" come
 *    seconda difesa.
 *
 * 5. AGGIUNTA (salvataggio automatico). La risposta contiene "map": per ogni
 *    indice di riga inviato, l'id vero della scheda e se e' padre o figlio.
 *    Serve alla pagina per aggiornarsi da sola invece di ricaricarsi - con il
 *    salvataggio automatico un ricaricamento ogni due secondi sarebbe
 *    insopportabile - e per sapere l'id delle righe appena nate.
 *
 * 6. AGGIUNTA (figli nuovi). Prima i figli si potevano solo modificare o
 *    eliminare: nascevano altrove. Ora il pulsante "aggiungi figlio" sotto ogni
 *    scheda ne crea di nuovi, quindi la FASE 2 deve saperli inserire. Il padre
 *    arriva in due modi: "parent_id" se esisteva gia', "parent_idx" (l'indice
 *    della riga padre in questo stesso invio) se e' nato adesso. In entrambi i
 *    casi si verifica che quel padre sia di questa categoria prima di scrivere.
 */
require_once 'config.php';
require_once 'auth_check.php';

$current_user_id = (int)$utente_corrente['id'];
$conn = sec_db();

header('Content-Type: application/json');

/* Token di sessione, origine e limite di frequenza in una riga.
   200 al minuto: un salvataggio in blocco e' una richiesta sola, ma chi
   corregge molte categorie di fila non deve trovarsi bloccato. */
sec_guard_write($current_user_id, 'big_edit_save', 0, 200, 60);

$catID = sec_int($_POST['category_id'] ?? 0, 1);
if (!sec_owns('categories', $catID, $current_user_id)) {
    echo json_encode(['success' => false, 'error' => t('vocab.non_autorizzato')]);
    exit;
}

if (!is_dir(IMAGE_PATH_INSERT)) { mkdir(IMAGE_PATH_INSERT, 0777, true); }

$cards = isset($_POST['cards']) && is_array($_POST['cards']) ? $_POST['cards'] : [];

/* indice della riga inviata -> id vero e tipo. La pagina la usa per aggiornarsi
   senza ricaricarsi. Le righe che il server decide di saltare (una riga nuova e
   completamente vuota) non finiscono qui: cosi' restano "nuove" in pagina e al
   prossimo salvataggio ci riprovano. */
$mappa = [];

/* Testo di una scheda: prima maiuscola e apostrofo tipografico, come prima,
   ma passando da sec_str/sec_text che togliono tag e caratteri di controllo. */
function be_testo($v, int $max = 500): string {
    $s = sec_text($v, $max);
    return ucfirst(str_replace("'", '’', trim($s)));
}

/* Immagine di una riga del modulo. Restituisce il nome del file salvato, oppure
   null se non e' stato caricato niente o se il file non e' un'immagine vera.
   Il nome lo genera il server: quello del client non viene mai usato. */
function be_immagine(int $i): ?string {
    if (!isset($_FILES['row_img']['name'][$i])) return null;
    if ((int)$_FILES['row_img']['error'][$i] !== UPLOAD_ERR_OK) return null;

    $file = [
        'tmp_name' => $_FILES['row_img']['tmp_name'][$i],
        'error'    => $_FILES['row_img']['error'][$i],
        'size'     => $_FILES['row_img']['size'][$i],
    ];
    $ris = sec_file($file);
    if (empty($ris['ok'])) return null;

    $nome = sec_safe_filename($ris['ext']);
    if (!move_uploaded_file($ris['tmp'], IMAGE_PATH_INSERT . $nome)) return null;
    return $nome;
}

/* Cancella dal disco l'immagine vecchia di una riga, se c'era. basename() e'
   una rete in piu': il nome viene dal database, ma se un giorno ci finisse
   dentro un percorso non deve poter uscire dalla cartella. */
function be_togli_file(?string $nome): void {
    if ($nome === null || $nome === '') return;
    $percorso = IMAGE_PATH_INSERT . basename($nome);
    if (is_file($percorso)) @unlink($percorso);
}

/* Indice della riga del modulo -> id reale del padre. Serve per agganciare i
   figli anche quando il padre e' stato creato in questo stesso salvataggio. */
$parent_real_ids = [];

/* ============================================================
   FASE 1 - PADRI (righe non-figlio)
   ============================================================ */
foreach ($cards as $i => $c) {
    if (!is_array($c)) continue;
    if (sec_int($c['is_child'] ?? 0, 0, 1) === 1) continue;

    $status = sec_enum($c['status'] ?? null, ['clean', 'new', 'edited', 'deleted'], 'clean');
    $id     = sec_int($c['id'] ?? 0, 0);

    /* Eliminazione: prima i figli, poi il padre. Sempre con category_id nella
       clausola, cosi' una riga di un'altra categoria non si tocca. */
    if ($status === 'deleted' && $id > 0) {
        $old = sec_one("SELECT image FROM images WHERE id = ? AND category_id = ?", [$id, $catID]);
        if ($old) be_togli_file($old['image'] ?? null);

        sec_q("DELETE FROM english_child_words
                WHERE parent_id IN (SELECT id FROM images WHERE id = ? AND category_id = ?)", [$id, $catID]);
        sec_q("DELETE FROM images WHERE id = ? AND category_id = ?", [$id, $catID]);
        $mappa[(int)$i] = ['id' => 0, 'type' => 'deleted'];
        continue;
    }

    $en      = be_testo($c['en'] ?? '', 200);
    $it      = be_testo($c['it'] ?? '', 200);
    $meaning = be_testo($c['meaning'] ?? '', 1000);
    $example = be_testo($c['example'] ?? '', 1000);

    // riga nuova e vuota: non c'e' niente da salvare
    if ($status === 'new' && $en === '' && $it === '') continue;

    $nuovaImg = be_immagine((int)$i);

    $real_id = 0;
    if ($status === 'new') {
        sec_q("INSERT INTO images (category_id, description_en, description_it, meaning, example, is_father, image)
               VALUES (?, ?, ?, ?, ?, 0, ?)",
              [$catID, $en, $it, $meaning, $example, $nuovaImg]);
        $real_id = sec_insert_id();
    } elseif ($id > 0) {
        /* L'immagine si aggiorna solo se ne e' arrivata una nuova: due forme di
           query invece di incollare un pezzo di SET, che era il punto in cui
           prima entrava una stringa costruita a mano. */
        if ($nuovaImg !== null) {
            $vecchia = sec_one("SELECT image FROM images WHERE id = ? AND category_id = ?", [$id, $catID]);
            if ($vecchia) be_togli_file($vecchia['image'] ?? null);
            sec_q("UPDATE images SET description_en = ?, description_it = ?, meaning = ?, example = ?, image = ?
                    WHERE id = ? AND category_id = ?",
                  [$en, $it, $meaning, $example, $nuovaImg, $id, $catID]);
        } else {
            sec_q("UPDATE images SET description_en = ?, description_it = ?, meaning = ?, example = ?
                    WHERE id = ? AND category_id = ?",
                  [$en, $it, $meaning, $example, $id, $catID]);
        }
        $real_id = $id;
    }
    $parent_real_ids[$i] = $real_id;
    if ($real_id > 0) $mappa[(int)$i] = ['id' => $real_id, 'type' => 'parent'];
}

/* ============================================================
   FASE 2 - FIGLI (english_child_words)
   Si modificano, si eliminano e - da quando c'e' il pulsante "aggiungi figlio"
   sotto ogni scheda - si creano.
   ============================================================ */
foreach ($cards as $i => $c) {
    if (!is_array($c)) continue;
    if (sec_int($c['is_child'] ?? 0, 0, 1) !== 1) continue;

    $status = sec_enum($c['status'] ?? null, ['clean', 'new', 'edited', 'deleted'], 'clean');
    $id     = sec_int($c['id'] ?? 0, 0);

    if ($status === 'deleted' && $id > 0) {
        sec_q("DELETE FROM english_child_words
                WHERE id_child = ? AND parent_id IN (SELECT id FROM images WHERE category_id = ?)", [$id, $catID]);
        $mappa[(int)$i] = ['id' => 0, 'type' => 'deleted'];
        continue;
    }
    if ($status === 'deleted') continue;   // figlio nuovo e gia' cancellato: non e' mai esistito

    $en      = be_testo($c['en'] ?? '', 200);
    $it      = be_testo($c['it'] ?? '', 200);
    $meaning = be_testo($c['meaning'] ?? '', 1000);
    $example = be_testo($c['example'] ?? '', 1000);

    /* ---- FIGLIO NUOVO ----
       Il padre puo' essere una scheda che esisteva gia' (parent_id) oppure una
       nata in questo stesso salvataggio (parent_idx, l'indice della sua riga).
       Il cast a int su parent_idx e' voluto: serve solo come chiave di un
       elenco costruito qui dentro, e -1 significa "non c'e'". */
    if ($id <= 0) {
        if ($status !== 'new') continue;
        if ($en === '' && $it === '') continue;   // riga vuota: niente da creare

        $parentId = sec_int($c['parent_id'] ?? 0, 0);
        $parentIdx = isset($c['parent_idx']) ? (int)$c['parent_idx'] : -1;
        if ($parentId <= 0 && $parentIdx >= 0) {
            $parentId = (int)($parent_real_ids[$parentIdx] ?? 0);
        }
        if ($parentId <= 0) continue;

        /* Il padre dev'essere di questa categoria: senza questa riga si
           potrebbe appendere un figlio alla scheda di un altro utente
           passando un id qualsiasi. */
        if (!sec_one("SELECT id FROM images WHERE id = ? AND category_id = ?", [$parentId, $catID])) continue;

        $nuovaImg = be_immagine((int)$i);

        sec_q("INSERT INTO english_child_words (parent_id, description_en, description_it, meaning, example, image)
               VALUES (?, ?, ?, ?, ?, ?)",
              [$parentId, $en, $it, $meaning, $example, $nuovaImg]);
        $nuovoId = sec_insert_id();

        sec_q("UPDATE images SET is_father = 1 WHERE id = ? AND category_id = ?", [$parentId, $catID]);
        $mappa[(int)$i] = ['id' => $nuovoId, 'type' => 'child'];
        continue;
    }

    $nuovaImg = be_immagine((int)$i);

    if ($nuovaImg !== null) {
        /* la vecchia si cancella solo dopo aver verificato che il figlio sia di
           questa categoria: senza il controllo si potrebbe far cancellare un
           file altrui passando un id qualsiasi */
        $vecchia = sec_one("SELECT c.image FROM english_child_words c
                             JOIN images p ON p.id = c.parent_id
                            WHERE c.id_child = ? AND p.category_id = ?", [$id, $catID]);
        if ($vecchia) be_togli_file($vecchia['image'] ?? null);

        sec_q("UPDATE english_child_words
                  SET description_en = ?, description_it = ?, meaning = ?, example = ?, image = ?
                WHERE id_child = ? AND parent_id IN (SELECT id FROM images WHERE category_id = ?)",
              [$en, $it, $meaning, $example, $nuovaImg, $id, $catID]);
    } else {
        sec_q("UPDATE english_child_words
                  SET description_en = ?, description_it = ?, meaning = ?, example = ?
                WHERE id_child = ? AND parent_id IN (SELECT id FROM images WHERE category_id = ?)",
              [$en, $it, $meaning, $example, $id, $catID]);
    }

    $mappa[(int)$i] = ['id' => $id, 'type' => 'child'];
}

echo json_encode(['success' => true, 'map' => $mappa]);
