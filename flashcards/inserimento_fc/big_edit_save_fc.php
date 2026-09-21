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
 *
 * 8. CORREZIONE (cambio di livello). Quando una carta cambia natura, la sua
 *    riga viene cancellata da una tabella e ricreata nell'altra: e' una carta
 *    che rinasce con un id nuovo, non una riga modificata. fc_examples e
 *    fc_gameplays puntano alle carte con ON DELETE CASCADE, quindi sarebbero
 *    sparite insieme alla vecchia riga; ora il legame si stacca prima della
 *    cancellazione e si riattacca dopo, alla colonna dell'altro livello.
 *    Nella stessa occasione: i figli del padre retrocesso passano al nuovo
 *    padre (fc_child_cards non ha la cascata, sarebbero rimasti appesi a un
 *    id inesistente) e is_father viene ricalcolato anche per il padre che
 *    resta svuotato.
 *    Restano fuori le partite gia' iniziate (fc_game_sequence conosce solo i
 *    padri, con una colonna NOT NULL). I preferiti seguono la carta appena
 *    migrazione_preferiti_figli.sql insegna a user_favorites_fc a puntare
 *    anche a un figlio; finche' quella non e' stata eseguita quella tabella
 *    viene semplicemente saltata, senza che nulla si rompa.
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

/* CORREZIONE — bug grave: agganciare una carta come figlia (o l'inverso,
   scollegarla) sembrava salvarsi ma non cambiava niente nel database.
   Il percorso veloce piu' sotto fa solo un UPDATE nella tabella che
   $orig_type indica, senza mai spostare la riga fra fc_images e
   fc_child_cards: e' pensato per quando NESSUNA struttura e' cambiata, solo
   testo e allegati. Sapere se e' davvero cosi' dipendeva pero' del tutto da
   $reordered, un valore mandato dal client — e un aggancio cambia la
   struttura (padre diventa figlio o viceversa) senza necessariamente far
   scattare quel flag, se la pagina che invia non lo prevede. Il risultato:
   l'UPDATE va a buon fine, il server risponde successo, ma la carta resta
   esattamente dov'era.
   Invece di fidarsi ciecamente di $reordered, lo si verifica qui: se anche
   una sola carta ha is_child in disaccordo con orig_type (una struttura e'
   davvero cambiata, qualunque cosa dica il client), si passa al percorso
   completo, che sa gestire la migrazione fra le due tabelle. */
if ($reordered === 0) {
    foreach ($cards as $c) {
        if (!is_array($c)) continue;
        $era_figlio  = sec_enum($c['orig_type'] ?? null, ['parent', 'child'], 'parent') === 'child';
        $ora_figlio  = (int)($c['is_child'] ?? 0) === 1;
        if ($era_figlio !== $ora_figlio) { $reordered = 1; break; }
    }
}

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
    if (!move_uploaded_file($ris['tmp'], $dest)) return null;
    /* Le misure si prendono adesso, con il file appena scritto sotto mano:
       e' l'unico momento in cui leggerlo costa una apertura sola invece di
       una per ogni volta che la carta verra' mostrata. */
    if (!$audio) fc_misura_registra($nome, $dest);
    return $nome;
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
   Restituiti insieme apposta: separati, prima o poi si sfasano.

   $c e' la riga inviata dal client. Serve per i segnali "svuota questa
   colonna" (clear_audio_q/a, clear_image_q/a), che non sono file caricati ma
   richieste di cancellazione: l'utente ha tolto un audio o un'immagine che
   erano gia' nel database, e senza questo il salvataggio non avrebbe modo di
   dirlo — manda i file quando ce ne sono di nuovi, e una colonna che nessuno
   tocca resta com'era. */
function fc_set_allegati(array $all, array $c = []): array {
    $sets = []; $vals = [];
    foreach ($all as $campo => $nome) {
        if ($nome === null) continue;         // non caricato: la colonna non si tocca
        $sets[] = "$campo = ?";
        $vals[] = $nome;
    }

    /* La cancellazione vale solo se per quel campo NON e' arrivato anche un
       file nuovo: se nello stesso salvataggio l'utente ha cancellato e poi
       ricaricato, vince il nuovo. $all ha sempre tutte e quattro le chiavi
       (fc_allegati le riempie tutte, con null dove non c'e' niente), quindi
       basta guardare se quel valore e' rimasto null. */
    $segnali = [
        'clear_audio_q' => 'question_audio', 'clear_audio_a' => 'answer_audio',
        'clear_image_q' => 'question_image', 'clear_image_a' => 'answer_image',
    ];
    foreach ($segnali as $segnale => $colonna) {
        if (!empty($c[$segnale]) && ($all[$colonna] ?? null) === null) {
            $sets[] = "$colonna = NULL";      // niente segnaposto: e' un valore fisso, non arriva dal client
        }
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

        [$sets, $vals] = fc_set_allegati(fc_allegati((int)$i), $c);
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
   CIO' CHE SEGUE UNA CARTA QUANDO CAMBIA LIVELLO
   ============================================================
   Cambiare livello significa cancellare una riga da una tabella e ricrearla
   nell'altra: non e' una modifica, e' una carta che rinasce con un id nuovo.
   Le tabelle qui sotto puntano alle carte con ON DELETE CASCADE (vedi il
   dump), quindi la cancellazione si porta via anche loro — e un UPDATE fatto
   dopo non trova piu' niente da aggiornare. Il legame va staccato PRIMA, e
   riattaccato all'id nuovo DOPO.
   Per ogni tabella: la colonna che lega a un padre e quella che lega a un
   figlio. Resta fuori solo fc_game_sequence, che conosce i padri e basta con
   una colonna NOT NULL: una carta che scende di livello perde per forza il
   posto in una partita gia' iniziata, perche' non c'e' dove scriverlo.
   Nomi di tabelle e colonne stanno qui, scritti a mano: non arrivano mai dal
   client, ed e' per questo che si possono incollare nella query dove un
   segnaposto non e' ammesso. */
const FC_SEGUONO = [
    'fc_examples'       => ['parent_id',   'child_id'],
    'fc_gameplays'      => ['fc_image_id', 'fc_child_id'],
    /* I preferiti sanno puntare a un figlio solo dopo
       migrazione_preferiti_figli.sql. Finche' non e' stata eseguita questa
       riga si salta da sola (vedi fc_colonna_esiste), quindi il file funziona
       sia prima sia dopo. */
    'user_favorites_fc' => ['fc_image_id', 'fc_child_id'],
];

/* Vero se quella colonna esiste davvero. Serve perche' l'elenco qui sopra
   guarda un po' avanti: una migrazione puo' non essere ancora stata eseguita,
   e legare l'ordine di caricamento dei file all'ordine delle migrazioni e' il
   modo piu' facile per ritrovarsi un errore fatale in mezzo a un salvataggio.
   La risposta si tiene da parte: la si chiede una volta per richiesta, e solo
   quando una carta cambia davvero livello — non a ogni salvataggio. */
function fc_colonna_esiste(string $tabella, string $colonna): bool {
    static $viste = [];
    $chiave = $tabella . '.' . $colonna;
    if (!isset($viste[$chiave])) {
        $r = sec_one("SELECT COUNT(*) AS c FROM information_schema.COLUMNS
                       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
                     [$tabella, $colonna]);
        $viste[$chiave] = ((int)($r['c'] ?? 0)) > 0;
    }
    return $viste[$chiave];
}

function fc_ids(array $righe): array {
    return array_map(function ($r) { return (int)$r['id']; }, $righe);
}

/* Stacca dalla carta che sta per essere cancellata tutto quello che la
   seguirebbe nella tomba, e restituisce gli id delle righe staccate: dopo
   l'azzeramento non c'e' piu' nessun altro modo di ritrovarle.
   $daPadre dice da quale delle due colonne staccare. */
function fc_stacca(int $id, bool $daPadre): array {
    $tenute = [];
    foreach (FC_SEGUONO as $tabella => $colonne) {
        /* Si guarda la colonna dei figli, che e' quella che puo' mancare. Se
           non c'e', questa tabella si lascia proprio stare: staccare senza
           poter riagganciare sarebbe peggio che non fare niente — la riga
           resterebbe senza padrone da tutte e due le parti. */
        if (!fc_colonna_esiste($tabella, $colonne[1])) continue;
        $col = $daPadre ? $colonne[0] : $colonne[1];
        $ids = fc_ids(sec_all("SELECT id FROM $tabella WHERE $col = ?", [$id]));
        if ($ids) sec_q("UPDATE $tabella SET $col = NULL WHERE $col = ?", [$id]);
        $tenute[$tabella] = $ids;
    }
    return $tenute;
}

/* ...e le riattacca alla riga nuova, dall'altra parte del confine. */
function fc_riattacca(array $tenute, int $nuovoId, bool $aPadre): void {
    if ($nuovoId <= 0) return;
    foreach (FC_SEGUONO as $tabella => $colonne) {
        $ids = $tenute[$tabella] ?? [];
        if (!$ids) continue;
        $col   = $aPadre ? $colonne[0] : $colonne[1];
        $segna = implode(',', array_fill(0, count($ids), '?'));
        sec_q("UPDATE $tabella SET $col = ? WHERE id IN ($segna)", array_merge([$nuovoId], $ids));
    }
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
        $ex_da_promuovere = ($orig_type === 'child' && $id > 0) ? $id : 0;
        /* Va letto PRIMA della cancellazione: dopo, la riga che dice di chi
           era figlia questa carta non c'e' piu'. */
        $padre_lasciato = $ex_da_promuovere > 0
            ? (int)(sec_one("SELECT parent_id FROM fc_child_cards WHERE id_child = ?", [$id])['parent_id'] ?? 0)
            : 0;
        /* Esempi e partite giocate della carta, messi al riparo dalla cascata
           un attimo prima che la vecchia riga sparisca. */
        $al_seguito = $ex_da_promuovere > 0 ? fc_stacca($id, false) : [];
        if ($orig_type === 'child') fc_elimina('child', $id, $categoryId);

        sec_q("INSERT INTO fc_images (category_id, question_desc, answer_desc, question_image, answer_image,
                                      question_audio, answer_audio, is_father, custom_order)
               VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?)",
              [$categoryId, $q, $a, $all['question_image'], $all['answer_image'],
               $all['question_audio'], $all['answer_audio'], $custom_order_parent]);
        $real_parent_id = sec_insert_id();

        /* Verso opposto della retrocessione (vedi FASE 2): la carta e' la
           stessa, ma la riga e' nuova e sta in un'altra tabella. Esempi e
           partite si riagganciano qui, alla colonna "padre". */
        if ($ex_da_promuovere > 0) fc_riattacca($al_seguito, $real_parent_id, true);

        /* Il padre da cui la carta se n'e' andata puo' essere rimasto senza
           figli: se non si ricalcola, continua a mostrarsi come se ne avesse
           ancora. Stessa cura che la FASE 2 ha gia' per il cambio di padre. */
        if ($padre_lasciato > 0) {
            $restano = (int)(sec_one("SELECT COUNT(*) AS c FROM fc_child_cards WHERE parent_id = ?", [$padre_lasciato])['c'] ?? 0);
            sec_q("UPDATE fc_images SET is_father = ? WHERE id = ? AND category_id = ?",
                  [$restano > 0 ? 1 : 0, $padre_lasciato, $categoryId]);
        }
    } else {
        [$sets, $vals] = fc_set_allegati($all, $c);
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

    /* In PHP una variabile assegnata dentro un giro di ciclo resta viva anche
       nei giri successivi: senza questi azzeramenti, il controllo sul vecchio
       padre in fondo poteva usare il valore rimasto dalla carta precedente, e
       fc_riattacca appiccicare a questa carta gli esempi di quell'altra. */
    $vecchio_padre_id = 0;
    $al_seguito       = [];

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
        if ($orig_type === 'parent' && $id > 0) {
            /* Prima di cancellare la vecchia riga bisogna occuparsi di cio'
               che le sta attaccato, altrimenti se ne va con lei.
               1. I suoi figli: fc_child_cards.parent_id non ha una chiave
                  esterna, quindi non vengono cancellati — resterebbero pero'
                  appesi a un id che non esiste piu'. Passano al nuovo padre,
                  che e' anche quello che l'utente vede in pagina: la carta
                  scende di un livello e si porta dietro i suoi, che le
                  diventano fratelli. Vale anche come rete di sicurezza, se la
                  pagina non li avesse mandati.
               2. Esempi e partite giocate: quelli hanno la cascata, e si
                  staccano un attimo prima (vedi fc_stacca). */
            sec_q("UPDATE fc_child_cards SET parent_id = ? WHERE parent_id = ?", [$real_parent_id, $id]);
            $al_seguito = fc_stacca($id, true);   // si riattaccano dopo l'INSERT, che da' l'id nuovo
            fc_elimina('parent', $id, $categoryId);
        }

        sec_q("INSERT INTO fc_child_cards (parent_id, question_desc, answer_desc, question_image, answer_image,
                                           question_audio, answer_audio, custom_order)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
              [$real_parent_id, $q, $a, $all['question_image'], $all['answer_image'],
               $all['question_audio'], $all['answer_audio'], $ordine]);
        $id = sec_insert_id();   // serve alla mappa: e' l'id con cui la pagina continuera' a lavorare

        /* Adesso che l'id nuovo esiste, esempi e partite tornano al loro
           posto — dall'altra parte del confine, sulla colonna "figlio". */
        fc_riattacca($al_seguito, $id, false);
    } else {
        /* Il vecchio padre serve DOPO l'update, per capire se e' rimasto
           orfano di figli — va letto ORA, prima che parent_id venga
           sovrascritto qui sotto. */
        $vecchio_padre_id = (int)(sec_one("SELECT parent_id FROM fc_child_cards WHERE id_child = ?", [$id])['parent_id'] ?? 0);

        [$sets, $vals] = fc_set_allegati($all, $c);
        $clausole = array_merge(['parent_id = ?', 'custom_order = ?', 'question_desc = ?', 'answer_desc = ?'], $sets);
        $valori   = array_merge([$real_parent_id, $ordine, $q, $a], $vals);
        sec_q("UPDATE fc_child_cards SET " . implode(', ', $clausole) . "
                WHERE id_child = ? AND parent_id IN (SELECT id FROM fc_images WHERE category_id = ?)",
              array_merge($valori, [$id, $categoryId]));
    }

    /* Il vecchio padre, se questa carta ne aveva uno diverso, potrebbe aver
       appena perso il suo ultimo figlio: is_father andrebbe ricalcolato per
       lui too, non solo acceso per il nuovo. Non e' la causa nota del bug
       segnalato (la carta appartiene comunque al padre giusto in
       fc_child_cards, questo flag serve solo a sapere se mostrare la
       freccia "ha figli"), ma lasciarlo sbagliato avrebbe fatto vedere un
       padre svuotato come se ne avesse ancora. */
    if (isset($vecchio_padre_id) && $vecchio_padre_id > 0 && $vecchio_padre_id !== $real_parent_id) {
        $ha_ancora_figli = sec_one("SELECT COUNT(*) AS c FROM fc_child_cards WHERE parent_id = ?", [$vecchio_padre_id])['c'] ?? 0;
        sec_q("UPDATE fc_images SET is_father = ? WHERE id = ? AND category_id = ?",
              [(int)$ha_ancora_figli > 0 ? 1 : 0, $vecchio_padre_id, $categoryId]);
    }

    sec_q("UPDATE fc_images SET is_father = 1 WHERE id = ? AND category_id = ?", [$real_parent_id, $categoryId]);
    $mappa[(int)$i] = ['id' => $id, 'type' => 'child'];
    $custom_order_child[$real_parent_id]++;
}

echo json_encode(['success' => true, 'map' => $mappa]);
