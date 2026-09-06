<?php
/* Esempi allegati a una flashcard: leggi, salva, elimina.
 *
 * COSA C'ERA CHE NON ANDAVA
 *
 * 1. Nessun controllo di accesso: chiunque, anche senza essere connesso, poteva
 *    leggere gli esempi di qualunque scheda, modificarli e cancellarli passando
 *    un id qualsiasi. E' il difetto piu' grave di questo gruppo di file, perche'
 *    riguarda anche la lettura.
 *
 * 2. I due file caricati prendevano l'estensione dal nome del client e non
 *    guardavano il contenuto: "x.php" finiva eseguibile in fc_images/.
 *
 * 3. Query costruite per concatenazione, e i messaggi di errore rimandavano al
 *    client il testo di mysqli_error(): e' una mappa del database regalata a chi
 *    attacca. Ora l'errore finisce nel log e l'utente riceve un messaggio
 *    generico, come in tutto il resto del sito.
 *
 * Resta l'ob_start() dell'originale: serve a impedire che un avviso di PHP
 * stampato prima del JSON lo renda illeggibile al browser.
 */
require_once 'config.php';
require_once 'auth_check_api.php';

ob_start();

$current_user_id = (int)$utente_corrente['id'];
$conn = sec_db();

function ex_api_json_out(array $payload): void {
    ob_clean();
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

/* Vero se la scheda e' dell'utente: la proprieta' passa dalla categoria.
   La stessa verifica vale per le schede figlie, che ereditano dal padre.

   $fcType dice a quale tabella appartiene $fcId ('parent' -> fc_images.id,
   'child' -> fc_child_cards.id_child): un parametro esplicito invece del
   segno di prima, che si affidava al fatto che chi chiamava se lo
   ricordasse ovunque. Con due colonne vere in fc_examples (parent_id /
   child_id) il tipo non serve piu' a leggere/scrivere la riga giusta, ma
   serve ancora qui, per sapere in quale tabella cercare $fcId. */
function ex_scheda_mia(int $fcId, string $fcType, int $userId): bool {
    if ($fcId <= 0) return false;

    if ($fcType === 'child') {
        $isFiglia = sec_one("SELECT ch.id_child FROM fc_child_cards ch
                             JOIN fc_images i ON i.id = ch.parent_id
                             JOIN fc_categories c ON c.id = i.category_id
                             WHERE ch.id_child = ? AND c.user_id = ?", [$fcId, $userId]);
        return $isFiglia !== null;
    }

    $isPadre = sec_one("SELECT i.id FROM fc_images i
                        JOIN fc_categories c ON c.id = i.category_id
                        WHERE i.id = ? AND c.user_id = ?", [$fcId, $userId]);
    return $isPadre !== null;
}

/* Vero se l'esempio appartiene a una scheda dell'utente. Con due colonne
   esplicite non serve decodificare niente: si guarda quale delle due non
   e' NULL, e si passa quella a ex_scheda_mia() col tipo giusto. */
function ex_esempio_mio(int $exId, int $userId): bool {
    if ($exId <= 0) return false;

    $riga = sec_one("SELECT parent_id, child_id FROM fc_examples WHERE id = ?", [$exId]);
    if ($riga === null) return false;

    if ($riga['parent_id'] !== null) return ex_scheda_mia((int)$riga['parent_id'], 'parent', $userId);
    if ($riga['child_id']  !== null) return ex_scheda_mia((int)$riga['child_id'], 'child', $userId);
    return false; // riga orfana: ne' padre ne' figlio (non dovrebbe piu' potersi creare, con le FK)
}

/* Salva un allegato. Il tipo vero si legge con finfo e il nome lo genera il
   server: prima l'estensione arrivava dal nome scelto dal client. */
const EX_AUDIO_MIME = [
    'audio/mpeg' => 'mp3', 'audio/mp3' => 'mp3', 'audio/wav' => 'wav',
    'audio/x-wav' => 'wav', 'audio/webm' => 'webm', 'audio/ogg' => 'ogg',
    'audio/mp4' => 'm4a', 'audio/aac' => 'aac',
];
function ex_salva(string $campo, string $cartella, bool $audio): ?string {
    if (!isset($_FILES[$campo]) || ($_FILES[$campo]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return null;
    $ris = $audio ? sec_file($_FILES[$campo], EX_AUDIO_MIME, 10485760) : sec_file($_FILES[$campo]);
    if (empty($ris['ok'])) return null;
    if (!is_dir($cartella)) { @mkdir($cartella, 0755, true); }
    $nome = sec_safe_filename($ris['ext']);
    return move_uploaded_file($ris['tmp'], $cartella . '/' . $nome) ? $nome : null;
}

$action = sec_enum($_GET['action'] ?? $_POST['action'] ?? null, ['get', 'save', 'delete'], '');

/* ---------- LETTURA ---------- */
if ($action === 'get' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $fc_id   = sec_int($_GET['fc_id'] ?? 0, 1);
    $fc_type = sec_enum($_GET['fc_type'] ?? 'parent', ['parent', 'child'], 'parent');
    if (!ex_scheda_mia($fc_id, $fc_type, $current_user_id)) {
        ex_api_json_out(['success' => false, 'error' => t('fc.non_autorizzato_scheda')]);
    }
    $col = $fc_type === 'child' ? 'child_id' : 'parent_id';
    $examples = sec_all("SELECT * FROM fc_examples WHERE $col = ? ORDER BY id ASC", [$fc_id]);
    ex_api_json_out(['success' => true, 'examples' => $examples]);
}

/* ---------- SALVATAGGIO ---------- */
if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    sec_guard_write($current_user_id, 'fc_examples', 0, 120, 60);

    $fc_id   = sec_int($_POST['fc_id'] ?? 0, 0);
    $fc_type = sec_enum($_POST['fc_type'] ?? 'parent', ['parent', 'child'], 'parent');
    $ex_id   = sec_int($_POST['ex_id'] ?? 0, 0);
    $text    = sec_text($_POST['text'] ?? '', 20000);

    /* Modifica di un esempio esistente, oppure aggiunta a una scheda: in
       entrambi i casi si verifica che la riga toccata sia sua. */
    if ($ex_id > 0) {
        if (!ex_esempio_mio($ex_id, $current_user_id)) {
            ex_api_json_out(['success' => false, 'error' => t('fc.non_autorizzato_esempio')]);
        }
    } elseif (!ex_scheda_mia($fc_id, $fc_type, $current_user_id)) {
        ex_api_json_out(['success' => false, 'error' => t('fc.non_autorizzato_scheda')]);
    }

    $img = ex_salva('image', 'fc_images', false);
    $aud = ex_salva('audio', 'fc_audio', true);

    /* TOGLIERE L'IMMAGINE, NON SOLO SOSTITUIRLA
       ex_salva() torna null quando non e' stato caricato niente, e null vuol
       dire "lascia com'era": giusto per un salvataggio in cui l'immagine non
       si tocca, ma cosi' non c'era modo di dire "toglila". L'unica via era
       caricarne un'altra, cioe' non c'era.
       La casella "Rimuovi immagine" manda remove_image=1. Un file appena
       caricato vince comunque: se si e' scelta una foto nuova e insieme
       spuntata la casella, l'intenzione piu' recente e' la foto. */
    if ($img === null && !empty($_POST['remove_image'])) $img = '';

    /* Le colonne da aggiornare crescono insieme ai valori: separati, prima o
       poi si sfasano. */
    $sets = ['example_text = ?']; $vals = [$text];
    if ($img !== null) { $sets[] = 'example_image = ?'; $vals[] = ($img === '' ? null : $img); }
    if ($aud !== null) { $sets[] = 'example_audio = ?'; $vals[] = $aud; }

    if ($ex_id > 0) {
        sec_q("UPDATE fc_examples SET " . implode(', ', $sets) . " WHERE id = ?", array_merge($vals, [$ex_id]));
    } else {
        $col = $fc_type === 'child' ? 'child_id' : 'parent_id';
        sec_q("INSERT INTO fc_examples ($col, example_text, example_image, example_audio)
               VALUES (?, ?, ?, ?)", [$fc_id, $text, $img, $aud]);
        $ex_id = sec_insert_id();
    }
    ex_api_json_out(['success' => true, 'ex_id' => $ex_id]);
}

/* ---------- ELIMINAZIONE ---------- */
if ($action === 'delete') {
    sec_guard_write($current_user_id, 'fc_examples', 0, 120, 60);

    $ex_id = sec_int($_GET['ex_id'] ?? $_POST['ex_id'] ?? 0, 1);
    if (!ex_esempio_mio($ex_id, $current_user_id)) {
        ex_api_json_out(['success' => false, 'error' => t('fc.non_autorizzato_esempio')]);
    }
    sec_q("DELETE FROM fc_examples WHERE id = ?", [$ex_id]);
    ex_api_json_out(['success' => true]);
}

ex_api_json_out(['success' => false, 'error' => t('fc.azione_non_riconosciuta')]);
