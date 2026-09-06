<?php

require_once 'config.php';
require_once 'auth_check.php';
require_once 'examples_box.php'; // render_examples_section(), condivisa con modifica_immagine_fc.php
$current_user_id = (int)$utente_corrente['id'];
/* sec_db() DOPO auth_check: e' quest'ultimo a caricare security_core.php. */
$conn = sec_db();

/* Filtra un elenco di id di carte tenendo solo quelle di questo utente.
   Prima non c'era: gli id del modulo finivano nelle query senza controllo, e
   cambiando i numeri si cancellavano le flashcard di un altro. */
function fc_solo_mie(array $ids, int $userId): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (empty($ids)) return [];
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $ok = [];
    foreach (sec_all("SELECT i.id FROM fc_images i
                      JOIN fc_categories c ON c.id = i.category_id
                      WHERE i.id IN ($ph) AND c.user_id = ?", array_merge($ids, [$userId])) as $r) {
        $ok[] = (int)$r['id'];
    }
    return $ok;
}

/* Come sopra per le sotto-carte: la proprieta' si verifica passando dal padre. */
function fc_figli_miei(array $ids, int $userId): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (empty($ids)) return [];
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $ok = [];
    foreach (sec_all("SELECT ch.id_child FROM fc_child_cards ch
                      JOIN fc_images i ON i.id = ch.parent_id
                      JOIN fc_categories c ON c.id = i.category_id
                      WHERE ch.id_child IN ($ph) AND c.user_id = ?", array_merge($ids, [$userId])) as $r) {
        $ok[] = (int)$r['id_child'];
    }
    return $ok;
}



// Prima qui si leggeva la lista di TUTTI gli utenti per poterli scegliere da un menu:

// ora l'utente è sempre e solo quello che ha fatto login, niente più menu.

$current_user_id = $utente_corrente['id'];


// ==========================================
// AJAX: collega la categoria corrente a un progetto creando un sotto-progetto
// "Flashcards" sotto al progetto scelto (richiesta: tasto "collega progetto").
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'fc_link_project') {
    header('Content-Type: application/json');
        /* Token di sessione, origine e limite di frequenza. */
    sec_guard_write($current_user_id, 'fc_vis', 0, 120, 60);

    $catId = sec_int($_POST['category_id'] ?? 0, 1);
    $projectId = sec_int($_POST['project_id'] ?? 0, 1);
    // la categoria deve essere dell'utente
    if (!sec_owns('fc_categories', $catId, $current_user_id)) {
        echo json_encode(['success' => false, 'error' => t('fc.vis_categoria_non_valida')]); exit();
    }
    // e anche il progetto scelto
    $prjRow = sec_one("SELECT id, color, color_edited FROM projects WHERE id = ? AND user_id = ?",
                      [$projectId, $current_user_id]);
    if (!$prjRow) { echo json_encode(['success' => false, 'error' => t('fc.vis_progetto_non_valido')]); exit(); }

    // colore ereditato dal progetto scelto (catena dei padri, come fa project_selector)
    if (!function_exists('fc_resolve_project_color')) {
                function fc_resolve_project_color($parentId, $userId) {
            $parentId = intval($parentId); $guard = 0;
            while ($parentId > 0 && $guard < 50) {
                $guard++;
                $row = sec_one("SELECT parent_id, color, color_edited FROM projects WHERE id = ? AND user_id = ?",
                               [$parentId, (int)$userId]);
                if (!$row) break;
                if (intval($row['color_edited']) === 1 && !empty($row['color'])) return $row['color'];
                $parentId = intval($row['parent_id']);
            }
            return '#8E8E93';
        }
    }
        $newColor = (intval($prjRow['color_edited']) === 1 && !empty($prjRow['color']))
        ? $prjRow['color'] : fc_resolve_project_color($projectId, $current_user_id);

    // Prima di creare, controlla se esiste già un progetto "Flashcards" da riutilizzare:
    // 1) il progetto cliccato è già chiamato "Flashcards" -> lega quello
    // 2) ha un sotto-progetto chiamato "Flashcards" -> lega quello
    // 3) altrimenti crea un nuovo sotto-progetto "Flashcards"
    $targetProjectId = 0;
    $targetName = 'Flashcards';

    $selfName = '';
        $sr = sec_one("SELECT name FROM projects WHERE id = ? AND user_id = ?", [$projectId, $current_user_id]);
    if ($sr) { $selfName = $sr['name']; }

    if (mb_strtolower(trim($selfName)) === 'flashcards') {
        // il progetto scelto è già "Flashcards"
        $targetProjectId = $projectId;
        $targetName = $selfName;
    } else {
        // cerca un sotto-progetto diretto chiamato "Flashcards"
                $cr = sec_one("SELECT id, name FROM projects WHERE user_id = ? AND parent_id = ? AND LOWER(name) = 'flashcards' LIMIT 1",
                      [$current_user_id, $projectId]);
        if ($cr) {
            $targetProjectId = (int)$cr['id'];
            $targetName = $cr['name'];
        }
    }

    if ($targetProjectId === 0) {
        // nessuno esistente: crea il sotto-progetto "Flashcards"
                $nextOrder = (int)(sec_one("SELECT COALESCE(MAX(custom_order), -1) + 1 AS next_order FROM projects WHERE user_id = ? AND parent_id = ?",
                                   [$current_user_id, $projectId])['next_order'] ?? 0);
        sec_q("INSERT INTO projects (user_id, parent_id, name, color, color_edited, status, custom_order, created_at)
               VALUES (?, ?, 'Flashcards', ?, 0, 'in_progress', ?, NOW())",
              [$current_user_id, $projectId, $newColor, $nextOrder]);
        $targetProjectId = sec_insert_id();
        if ($targetProjectId <= 0) { echo json_encode(['success' => false, 'error' => t('fc.vis_creazione_fallita')]); exit(); }
        $created = true;
    } else {
        $created = false;
    }

    // collega il progetto (nuovo o riutilizzato) a questa categoria (evita doppioni)
        sec_q("DELETE FROM project_fc_categories WHERE fc_category_id = ? AND project_id IN (SELECT id FROM projects WHERE user_id = ?)",
          [$catId, $current_user_id]);
    sec_q("INSERT INTO project_fc_categories (project_id, fc_category_id) VALUES (?, ?)", [$targetProjectId, $catId]);

    echo json_encode(['success' => true, 'project_id' => $targetProjectId, 'name' => $targetName, 'color' => $newColor, 'created' => $created]);
    exit();
}




// ==========================================
// AJAX: crea una CARTELLA (categoria figlia) senza uscire dalla vista.
// Il tasto "+" ora chiede prima cosa si vuole aggiungere; se la risposta e'
// "cartella" si finisce qui invece che su categories_fc.php.
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'fc_create_subcat') {
    header('Content-Type: application/json; charset=utf-8');
    /* Token di sessione, origine e limite di frequenza. */
    sec_guard_write($current_user_id, 'fc_vis', 0, 60, 60);

    $nfNome   = sec_str($_POST['name'] ?? '', 120);
    $nfPadre  = sec_int($_POST['parent_id'] ?? 0, 0);
    $nfModo   = sec_enum($_POST['color_mode'] ?? null, ['auto', 'manual'], 'auto');
    $nfColore = sec_color($_POST['color'] ?? null, '');

    if (trim($nfNome) === '') {
        echo json_encode(['success' => false, 'error' => t('fc.vis_nome_mancante')]); exit;
    }
    /* Il padre dev'essere suo: altrimenti si potrebbe appendere una cartella
       propria sotto l'albero di un altro utente. parent_id 0 = radice. */
    if ($nfPadre > 0 && !sec_owns('fc_categories', $nfPadre, $current_user_id)) {
        echo json_encode(['success' => false, 'error' => t('fc.vis_categoria_non_valida')]); exit;
    }

    if ($nfModo === 'manual' && $nfColore !== '') {
        $nfFinale = $nfColore;
        $nfEdited = 1;
    } else {
        $nfFinale = fc_colore_ereditato($nfPadre, $current_user_id);
        $nfEdited = 0;
    }

    sec_q("INSERT INTO fc_categories (name, parent_id, color, color_edited, user_id) VALUES (?, ?, ?, ?, ?)",
          [$nfNome, $nfPadre, $nfFinale, $nfEdited, $current_user_id]);
    $nfId = sec_insert_id();
    if ($nfId <= 0) { echo json_encode(['success' => false, 'error' => t('fc.vis_creazione_fallita')]); exit; }

    echo json_encode(['success' => true, 'id' => (int)$nfId, 'name' => $nfNome, 'color' => $nfFinale]);
    exit;
}

/* Colore ereditato: si risale la catena dei padri finche' non si trova chi ha
   un colore scelto a mano (color_edited = 1). E' la stessa regola di
   categories_fc.php; il nome e' diverso apposta, per non azzuffarsi con
   cs_resolve_color() se un domani questa pagina includesse quel file. */
function fc_colore_ereditato($parentId, $userId) {
    $parentId = (int)$parentId;
    $guard = 0;
    while ($parentId > 0 && $guard < 50) {
        $guard++;
        $row = sec_one("SELECT parent_id, color, color_edited FROM fc_categories WHERE id = ? AND user_id = ?",
                       [$parentId, (int)$userId]);
        if (!$row) break;
        if ((int)$row['color_edited'] === 1 && !empty($row['color'])) return $row['color'];
        $parentId = (int)$row['parent_id'];
    }
    return '#A0A0A0';
}


// ==========================================

// GESTIONE SWAP POSIZIONI (LEGACY - non piu' usata dalla UI, tenuta per compatibilita')

// ==========================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] == 'swap_order') {

    $id = sec_int($_POST['id'] ?? 0, 1);

    $type = $_POST['type']; // 'parent' o 'child'

        $dir = sec_enum($_POST['direction'] ?? null, ['up', 'down'], 'up');
    $padre = ($type == 'parent');

    /* La carta dev'essere sua: prima l'id arrivava dal modulo e finiva nella
       UPDATE senza nessun controllo. */
    $mie = $padre ? fc_solo_mie([$id], $current_user_id) : fc_figli_miei([$id], $current_user_id);
    $current = !empty($mie)
        ? ($padre ? sec_one("SELECT * FROM fc_images WHERE id = ?", [$id])
                  : sec_one("SELECT * FROM fc_child_cards WHERE id_child = ?", [$id]))
        : null;

    if ($current) {
        $current_order = (int)$current['custom_order'];
        $scope = $padre ? (int)$current['category_id'] : (int)$current['parent_id'];
        /* Quattro forme scritte per esteso invece di comporre "$table" e
           "$where": i nomi di tabella non si legano a un segnaposto, quindi
           meno se ne scrivono a runtime meglio e'. */
        if ($padre) {
            $adjacent = ($dir == 'up')
                ? sec_one("SELECT id, custom_order FROM fc_images WHERE category_id = ? AND custom_order < ? ORDER BY custom_order DESC LIMIT 1", [$scope, $current_order])
                : sec_one("SELECT id, custom_order FROM fc_images WHERE category_id = ? AND custom_order > ? ORDER BY custom_order ASC LIMIT 1", [$scope, $current_order]);
        } else {
            $adjacent = ($dir == 'up')
                ? sec_one("SELECT id_child AS id, custom_order FROM fc_child_cards WHERE parent_id = ? AND custom_order < ? ORDER BY custom_order DESC LIMIT 1", [$scope, $current_order])
                : sec_one("SELECT id_child AS id, custom_order FROM fc_child_cards WHERE parent_id = ? AND custom_order > ? ORDER BY custom_order ASC LIMIT 1", [$scope, $current_order]);
        }
        if ($adjacent) {
            $adj_id = (int)$adjacent['id'];
            $adj_order = (int)$adjacent['custom_order'];
            if ($padre) {
                sec_q("UPDATE fc_images SET custom_order = ? WHERE id = ?", [$adj_order, $id]);
                sec_q("UPDATE fc_images SET custom_order = ? WHERE id = ?", [$current_order, $adj_id]);
            } else {
                sec_q("UPDATE fc_child_cards SET custom_order = ? WHERE id_child = ?", [$adj_order, $id]);
                sec_q("UPDATE fc_child_cards SET custom_order = ? WHERE id_child = ?", [$current_order, $adj_id]);
            }
        }
    }

    exit;

}



// ==========================================

// NUOVO: RIORDINO CONTINUO (drag & drop) — riscrive l'ordine di un intero gruppo

// ==========================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] == 'reorder_batch') {

    header('Content-Type: application/json');

        sec_guard_write($current_user_id, 'fc_vis', 0, 300, 60);

    $type = sec_enum($_POST['type'] ?? null, ['parent', 'child'], '');
    $scope_id = sec_int($_POST['scope_id'] ?? -1, 0, PHP_INT_MAX, -1);
    $ordered_ids = (isset($_POST['ordered_ids']) && is_array($_POST['ordered_ids'])) ? $_POST['ordered_ids'] : [];
    $ok = false;

    if (count($ordered_ids) > 0 && $scope_id >= 0 && $type !== '') {
        /* Solo le carte davvero sue: prima gli id del modulo finivano nella
           UPDATE senza controllo. Il filtro qui e' la difesa; la clausola sullo
           scope resta come seconda rete. */
        $miei = ($type === 'parent')
            ? fc_solo_mie($ordered_ids, $current_user_id)
            : fc_figli_miei($ordered_ids, $current_user_id);
        /* si conserva l'ordine scelto dall'utente, non quello del database */
        $ordine = array_values(array_filter(array_map('intval', $ordered_ids),
                                            function ($x) use ($miei) { return in_array($x, $miei, true); }));
        $pos = 0;
        foreach ($ordine as $oid) {
            if ($type === 'parent') {
                sec_q("UPDATE fc_images SET custom_order = ? WHERE id = ? AND category_id = ?", [$pos, $oid, $scope_id]);
            } else {
                sec_q("UPDATE fc_child_cards SET custom_order = ? WHERE id_child = ? AND parent_id = ?", [$pos, $oid, $scope_id]);
            }
            $pos++;
        }
        $ok = true;
    }

    echo json_encode(['success' => $ok]);

    exit;

}



// ==========================================

// GESTIONE DELLE AZIONI DI MASSA E JOIN/UNJOIN
//
// NOTA: la chiave primaria di fc_child_cards e' id_child, non id. Diverse
// query di questa pagina usavano "WHERE id = ?": non trovavano mai niente e
// uscivano in silenzio, senza errore. Da qui lo "split" (unjoin) che non
// faceva nulla, e con lui il riordino dei figli, il loro ordine
// personalizzato e la loro eliminazione di gruppo. Tutte le altre query del
// progetto usavano gia' id_child.

// ==========================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {

        sec_guard_write($current_user_id, 'fc_vis', 0, 120, 60);

    $action = sec_enum($_POST['bulk_action'] ?? null, ['delete', 'move', 'join', 'unjoin'], '');
    $cat_param = isset($_GET['category_id'])
        ? "?category_id=" . sec_int($_GET['category_id'], 0)
        : "?";

    if ($action === 'delete') {
        $miei = fc_solo_mie($_POST['selected_ids'] ?? [], $current_user_id);
        if (!empty($miei)) {
            $ph = implode(',', array_fill(0, count($miei), '?'));
            sec_q("DELETE FROM fc_images WHERE id IN ($ph)", $miei);
        }
        $figli = fc_figli_miei($_POST['selected_child_ids'] ?? [], $current_user_id);
        if (!empty($figli)) {
            $ph = implode(',', array_fill(0, count($figli), '?'));
            sec_q("DELETE FROM fc_child_cards WHERE id_child IN ($ph)", $figli);
        }
    }
    elseif ($action === 'move' && isset($_POST['target_category'])) {
        $target_cat = sec_int($_POST['target_category'], 1);
        $miei = fc_solo_mie($_POST['selected_ids'] ?? [], $current_user_id);
        /* anche la categoria di destinazione dev'essere sua */
        if (!empty($miei) && sec_owns('fc_categories', $target_cat, $current_user_id)) {
            $ph = implode(',', array_fill(0, count($miei), '?'));
            sec_q("UPDATE fc_images SET category_id = ? WHERE id IN ($ph)", array_merge([$target_cat], $miei));
        }
    } 
        elseif ($action === 'join' && isset($_POST['father_id'])) {
        $father_id = sec_int($_POST['father_id'], 1);
        $miei = fc_solo_mie($_POST['selected_ids'] ?? [], $current_user_id);
        /* anche il padre dev'essere una carta sua */
        if (!empty($miei) && fc_solo_mie([$father_id], $current_user_id)) {
            $c_order = (int)(sec_one("SELECT MAX(custom_order) AS m FROM fc_child_cards WHERE parent_id = ?",
                                     [$father_id])['m'] ?? 0) + 1;
            foreach ($miei as $child_id) {
                if ($child_id === $father_id) continue;
                $row = sec_one("SELECT * FROM fc_images WHERE id = ?", [$child_id]);
                if (!$row) continue;
                sec_q("INSERT INTO fc_child_cards (parent_id, question_desc, question_image, question_audio,
                                                   answer_desc, answer_image, answer_audio, custom_order)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                      [$father_id, $row['question_desc'], $row['question_image'], $row['question_audio'],
                       $row['answer_desc'], $row['answer_image'], $row['answer_audio'], $c_order]);
                sec_q("DELETE FROM fc_images WHERE id = ?", [$child_id]);
                $c_order++;
            }
            sec_q("UPDATE fc_images SET is_father = 1 WHERE id = ?", [$father_id]);
        }
    } 
    elseif ($action === 'unjoin') {
        $parents_to_check = [];
        foreach (fc_figli_miei($_POST['selected_child_ids'] ?? [], $current_user_id) as $child_id) {
            $row = sec_one("SELECT * FROM fc_child_cards WHERE id_child = ?", [$child_id]);
            if (!$row) continue;
            $parent_id = (int)$row['parent_id'];
            $parents_to_check[$parent_id] = true;

            $p_row = sec_one("SELECT category_id FROM fc_images WHERE id = ?", [$parent_id]);
            $cat_id = $p_row ? (int)$p_row['category_id'] : 0;
            $p_order = (int)(sec_one("SELECT MAX(custom_order) AS m FROM fc_images WHERE category_id = ?",
                                     [$cat_id])['m'] ?? 0) + 1;

            sec_q("INSERT INTO fc_images (question_desc, question_image, question_audio,
                                          answer_desc, answer_image, answer_audio, category_id, is_father, custom_order)
                   VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?)",
                  [$row['question_desc'], $row['question_image'], $row['question_audio'],
                   $row['answer_desc'], $row['answer_image'], $row['answer_audio'], $cat_id, $p_order]);
            sec_q("DELETE FROM fc_child_cards WHERE id_child = ?", [$child_id]);
        }
        foreach (array_keys($parents_to_check) as $pid) {
            $n = (int)(sec_one("SELECT COUNT(*) AS c FROM fc_child_cards WHERE parent_id = ?", [$pid])['c'] ?? 0);
            if ($n === 0) sec_q("UPDATE fc_images SET is_father = 0 WHERE id = ?", [$pid]);
        }
    }

    header("Location: visualizzazione_fc.php" . $cat_param);

    exit;

}



$categoryID = isset($_GET['category_id']) ? (int)$_GET['category_id'] : 0;



$cat_query = sec_all("SELECT id, name, color, color_edited, parent_id FROM fc_categories WHERE user_id = ? ORDER BY name", [$current_user_id]);
$categories_list = [];

$current_cat_name = 'Tutte le Categorie';

$current_cat_color = '#5E5CE6'; // Colore neutro di default (vista "tutte le categorie")



foreach ($cat_query as $c) { 

    $categories_list[] = $c; 

    if ($c['id'] == $categoryID) {

        $current_cat_name = $c['name'];

        $current_cat_color = !empty($c['color']) ? $c['color'] : '#007AFF';

    }

}

// ==========================================
// PERCORSO (breadcrumb) DEGLI ANTENATI DELLA CATEGORIA CORRENTE.
// Ordine per la visualizzazione (sinistra -> destra): radice ... padre.
// La 'home' e' rappresentata dalla freccetta a sinistra.
// ==========================================
$fc_cat_by_id = [];
foreach ($categories_list as $__c) { $fc_cat_by_id[(int)$__c['id']] = $__c; }
$fc_ancestors = []; // ordine: padre, nonno, bisnonno, ... (immediato per primo)
if ($categoryID != 0 && isset($fc_cat_by_id[$categoryID])) {
    $__cur = (int)($fc_cat_by_id[$categoryID]['parent_id'] ?? 0);
    $__guard = 0;
    while ($__cur > 0 && isset($fc_cat_by_id[$__cur]) && $__guard < 60) {
        $fc_ancestors[] = $fc_cat_by_id[$__cur];
        $__cur = (int)($fc_cat_by_id[$__cur]['parent_id'] ?? 0);
        $__guard++;
    }
}
// per la UI: radice a sinistra, padre a destra
$fc_path_ltr = array_reverse($fc_ancestors);

/* Serve al popup del colore: qual e' il colore che verrebbe ereditato dal
   padre (la prima opzione, "colore di default") e se quello attuale e' invece
   stato scelto a mano. */
$fc_color_edited = 0;
$fc_parent_color = '#A0A0A0';
if ($categoryID != 0 && isset($fc_cat_by_id[$categoryID])) {
    $fc_color_edited = (int)($fc_cat_by_id[$categoryID]['color_edited'] ?? 0);
    $fc_parent_color = fc_colore_ereditato((int)($fc_cat_by_id[$categoryID]['parent_id'] ?? 0), $current_user_id);
}

// ==========================================
// AJAX: RICERCA DENTRO LE CARTELLE SOTTO LA CATEGORIA CORRENTE.
// La pagina stampa in memoria solo le carte di QUESTA categoria: per cercare
// anche in quelle sotto, senza questa passata a parte bisognerebbe caricare
// tutto l'albero a ogni apertura, anche quando non si sta cercando niente.
// Torna HTML gia' pronto invece di dati grezzi perche' cosi' le carte trovate
// escono dalle stesse render_qa_side()/render_examples_section() dell'elenco
// normale: una sola descrizione della scheda, non due destinate a divergere.
// ==========================================
if (isset($_GET['fc_deep_search'])) {
    header('Content-Type: application/json; charset=utf-8');

    $fcTerm = trim((string)sec_str($_GET['q'] ?? '', 80));
    if ($fcTerm === '' || mb_strlen($fcTerm) < 2 || $categoryID == 0) {
        echo json_encode(['success' => true, 'html' => '', 'total' => 0]); exit;
    }

    /* L'albero e' gia' in memoria in $categories_list, che nasce da una query
       filtrata sull'utente: la visita in ampiezza da' insieme i discendenti e
       il percorso di ognuno a partire dalla categoria che si sta guardando. */
    $fcFigliDi = []; $fcNomeDi = []; $fcColoreDi = [];
    foreach ($categories_list as $__c) {
        $fcFigliDi[(int)($__c['parent_id'] ?? 0)][] = (int)$__c['id'];
        $fcNomeDi[(int)$__c['id']] = (string)$__c['name'];
        $fcColoreDi[(int)$__c['id']] = fc_color_or_default($__c['color']);
    }
    $fcPercorso = []; $fcOrdine = []; $fcCoda = [];
    foreach (($fcFigliDi[$categoryID] ?? []) as $__f) { $fcCoda[] = [$__f, [$fcNomeDi[$__f] ?? '']]; }
    while (!empty($fcCoda) && count($fcOrdine) < 400) {
        $__nodo = array_shift($fcCoda);
        $fcPercorso[$__nodo[0]] = $__nodo[1];
        $fcOrdine[] = $__nodo[0];
        foreach (($fcFigliDi[$__nodo[0]] ?? []) as $__f) {
            $fcCoda[] = [$__f, array_merge($__nodo[1], [$fcNomeDi[$__f] ?? ''])];
        }
    }
    if (empty($fcOrdine)) { echo json_encode(['success' => true, 'html' => '', 'total' => 0]); exit; }

    /* I jolly di LIKE vanno neutralizzati: cercando "_" o "%" si otterrebbe
       altrimenti l'intero archivio invece delle carte che contengono quel
       carattere. */
    $fcLike = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $fcTerm) . '%';
    $fcPh = implode(',', array_fill(0, count($fcOrdine), '?'));

    $fcPadri = sec_all(
        "SELECT i.*, c.name AS category_name, c.color AS category_color,
                (CASE WHEN ul.fc_image_id IS NOT NULL THEN 1 ELSE 0 END) AS is_learnt
         FROM fc_images i
         JOIN fc_categories c ON c.id = i.category_id AND c.user_id = ?
         LEFT JOIN user_learnt_fc ul ON ul.fc_image_id = i.id AND ul.user_id = ?
         WHERE i.category_id IN ($fcPh) AND (i.question_desc LIKE ? OR i.answer_desc LIKE ?)
         ORDER BY i.custom_order ASC, i.id ASC
         LIMIT 300",
        array_merge([$current_user_id, $current_user_id], $fcOrdine, [$fcLike, $fcLike]));

    $fcFigli = sec_all(
        "SELECT ch.*, ch.id_child AS id, i.category_id AS category_id
         FROM fc_child_cards ch
         JOIN fc_images i ON i.id = ch.parent_id
         JOIN fc_categories c ON c.id = i.category_id AND c.user_id = ?
         WHERE i.category_id IN ($fcPh) AND (ch.question_desc LIKE ? OR ch.answer_desc LIKE ?)
         ORDER BY ch.custom_order ASC, ch.id_child ASC
         LIMIT 300",
        array_merge([$current_user_id], $fcOrdine, [$fcLike, $fcLike]));

    if (empty($fcPadri) && empty($fcFigli)) {
        echo json_encode(['success' => true, 'html' => '', 'total' => 0]); exit;
    }

    /* Conteggio esempi con le stesse due colonne separate dell'elenco normale
       (chiave negativa = figlio, convenzione di render_examples_section). */
    $fcEsempi = [];
    $__idPadri = array_map(function ($r) { return (int)$r['id']; }, $fcPadri);
    $__idFigli = array_map(function ($r) { return (int)$r['id']; }, $fcFigli);
    if (!empty($__idPadri)) {
        $__ph = implode(',', array_fill(0, count($__idPadri), '?'));
        foreach (sec_all("SELECT parent_id, COUNT(*) AS c FROM fc_examples WHERE parent_id IN ($__ph) GROUP BY parent_id", $__idPadri) as $__ex) {
            $fcEsempi[(int)$__ex['parent_id']] = (int)$__ex['c'];
        }
    }
    if (!empty($__idFigli)) {
        $__ph = implode(',', array_fill(0, count($__idFigli), '?'));
        foreach (sec_all("SELECT child_id, COUNT(*) AS c FROM fc_examples WHERE child_id IN ($__ph) GROUP BY child_id", $__idFigli) as $__ex) {
            $fcEsempi[-1 * (int)$__ex['child_id']] = (int)$__ex['c'];
        }
    }

    $fcGruppi = [];
    foreach ($fcPadri as $__r) { $fcGruppi[(int)$__r['category_id']]['padri'][] = $__r; }
    foreach ($fcFigli as $__r) { $fcGruppi[(int)$__r['category_id']]['figli'][] = $__r; }

    $fcTot = 0;
    ob_start();
    /* L'ordine e' quello della visita in ampiezza: prima le cartelle piu'
       vicine alla categoria aperta, poi quelle piu' in fondo. */
    foreach ($fcOrdine as $__cid) {
        if (!isset($fcGruppi[$__cid])) continue;
        $__padri = $fcGruppi[$__cid]['padri'] ?? [];
        $__figli = $fcGruppi[$__cid]['figli'] ?? [];
        $__n = count($__padri) + count($__figli);
        $fcTot += $__n;
        $__col = $fcColoreDi[$__cid] ?? '#007AFF';
?>
<section class="fc-deep-group" data-cat-id="<?= (int)$__cid ?>" style="--row-color:<?= sec_h($__col) ?>;">
    <div class="fc-deep-head">
        <button type="button" class="fdh-main" onclick="fcDeepToggle(this)" aria-expanded="true">
            <span class="fdh-chev" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg></span>
            <span class="fdh-path">
                <?php foreach (($fcPercorso[$__cid] ?? []) as $__i => $__seg): ?>
                    <?php if ($__i > 0): ?><span class="fdh-sep" aria-hidden="true">&rsaquo;</span><?php endif; ?><span class="fdh-seg"><?= sec_h($__seg) ?></span>
                <?php endforeach; ?>
            </span>
            <span class="fdh-count"><?= (int)$__n ?></span>
        </button>
        <a class="fdh-go" href="visualizzazione_fc.php?category_id=<?= (int)$__cid ?>" aria-label="<?php te('fc.vis_apri_cartella'); ?>" title="<?php te('fc.vis_apri_cartella'); ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>
        </a>
    </div>
    <div class="fc-deep-body">
        <?php foreach ($__padri as $__row):
            $__learnt = ((int)$__row['is_learnt'] === 1);
            $__search = sec_h(strtolower($__row['question_desc'] . ' ' . $__row['answer_desc']));
        ?>
        <div class="fc-group fc-deep-item" data-parent-id="<?= (int)$__row['id'] ?>" data-category-id="<?= (int)$__cid ?>" data-reorderable="0" style="--row-color:<?= sec_h($__col) ?>;">
            <div class="fc-card fc-parent<?= $__learnt ? ' is-learnt' : '' ?>"
                 data-id="<?= (int)$__row['id'] ?>" data-type="parent"
                 data-search="<?= $__search ?>" data-duplicate="false" data-reorderable="0"
                 data-edit-url="modifica_immagine_fc.php?fc_id=<?= (int)$__row['id'] ?>&previous_catid=<?= (int)$__cid ?>"
                 data-child-count="0">
                <div class="swipe-actions">
                    <button type="button" class="swipe-act act-edit" onclick="goEditCard(this)">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>
                        <?php te('comune.modifica'); ?>
                    </button>
                    <button type="button" class="swipe-act act-delete" onclick="askDeleteCard(this)">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0-1 14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2L4 6"/></svg>
                        <?php te('comune.elimina'); ?>
                    </button>
                </div>
                <div class="swipe-content">
                    <div class="card-head">
                        <?php if ($current_user_id == 3): ?>
                            <div class="learnt-disabled">&ndash;</div>
                        <?php else: ?>
                            <label class="learnt-wrapper">
                                <input type="checkbox" class="learnt-cb" <?= $__learnt ? 'checked' : '' ?> onclick="toggleLearnt(<?= (int)$__row['id'] ?>, this)">
                                <div class="learnt-emoji">🧐</div>
                                <div class="learnt-check"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></div>
                            </label>
                        <?php endif; ?>
                        <div class="card-head-spacer"></div>
                    </div>
                    <?php render_qa_side(T('fc.ifc_domanda'), $__row['question_desc'], $__row['question_image'], $__row['question_audio'], 'q'); ?>
                    <?php render_qa_side(T('fc.ifc_risposta'), $__row['answer_desc'], $__row['answer_image'], $__row['answer_audio'], 'a'); ?>
                    <?php render_examples_section($__row['id'], 'parent', $__row['id'], $fcEsempi); ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>

        <?php foreach ($__figli as $__row):
            $__search = sec_h(strtolower($__row['question_desc'] . ' ' . $__row['answer_desc']));
        ?>
        <div class="fc-group fc-deep-item" data-reorderable="0" style="--row-color:<?= sec_h($__col) ?>;">
            <div class="fc-children">
                <div class="fc-card fc-child" data-id="<?= (int)$__row['id'] ?>" data-type="child"
                     data-parent-id="<?= (int)$__row['parent_id'] ?>" data-search="<?= $__search ?>"
                     data-duplicate="false" data-reorderable="0"
                     data-edit-url="modifica_immagine_fc.php?child_id=<?= (int)$__row['id'] ?>&previous_catid=<?= (int)$__cid ?>">
                    <div class="swipe-actions">
                        <button type="button" class="swipe-act act-edit" onclick="goEditCard(this)">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>
                            <?php te('vocab.cat_modifica_attr'); ?>
                        </button>
                        <button type="button" class="swipe-act act-delete" onclick="askDeleteCard(this)">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0-1 14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2L4 6"/></svg>
                            <?php te('comune.elimina'); ?>
                        </button>
                    </div>
                    <div class="swipe-content">
                        <div class="card-head">
                            <div class="child-tag"><span>&#8627;</span> <?php te('fc.vis_sotto_carta'); ?></div>
                            <div class="card-head-spacer"></div>
                        </div>
                        <?php render_qa_side(T('fc.ifc_domanda'), $__row['question_desc'], $__row['question_image'], $__row['question_audio'], 'q'); ?>
                        <?php render_qa_side(T('fc.ifc_risposta'), $__row['answer_desc'], $__row['answer_image'], $__row['answer_audio'], 'a'); ?>
                        <?php render_examples_section($__row['id'], 'child', -1 * (int)$__row['id'], $fcEsempi); ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</section>
<?php
    }
    $fcHtml = ob_get_clean();
    echo json_encode(['success' => true, 'html' => $fcHtml, 'total' => $fcTot]);
    exit;
}





// ==========================================
// AJAX: RIEPILOGO DI UN PROGETTO.
// La pastiglia del progetto non porta piu' dritto a progetti.php: prima si
// vede un riassunto, e da li' si decide se andarci. Tutti i numeri sono
// calcolati sul progetto E sui suoi discendenti, come fa progetti.php: un
// progetto padre senza tempo proprio ma con figli pieni non deve sembrare
// vuoto.
// ==========================================
if (isset($_GET['fc_project_summary'])) {
    header('Content-Type: application/json; charset=utf-8');
    $prjId = sec_int($_GET['project_id'] ?? 0, 1);
    if (!$prjId || !sec_owns('projects', $prjId, $current_user_id)) {
        echo json_encode(['success' => false, 'error' => t('fc.vis_progetto_non_valido')]); exit;
    }

    /* Un solo giro sull'albero dei progetti: da qui escono antenati,
       discendenti e figli diretti senza altre query. */
    $prjTutti = []; $prjFigliDi = [];
    foreach (sec_all("SELECT id, parent_id, name, notes, status, color, color_edited, is_favorite,
                             target_date, due_date, created_at
                      FROM projects WHERE user_id = ? ORDER BY custom_order ASC, id ASC",
                     [$current_user_id]) as $__p) {
        $prjTutti[(int)$__p['id']] = $__p;
        $prjFigliDi[(int)($__p['parent_id'] ?? 0)][] = (int)$__p['id'];
    }
    if (!isset($prjTutti[$prjId])) {
        echo json_encode(['success' => false, 'error' => t('fc.vis_progetto_non_valido')]); exit;
    }
    $prj = $prjTutti[$prjId];

    // antenati, dal piu' lontano al padre
    $prjAntenati = [];
    $__cur = (int)($prj['parent_id'] ?? 0); $__g = 0;
    while ($__cur > 0 && isset($prjTutti[$__cur]) && $__g < 60) {
        array_unshift($prjAntenati, $prjTutti[$__cur]['name']);
        $__cur = (int)($prjTutti[$__cur]['parent_id'] ?? 0);
        $__g++;
    }

    // se stesso + tutti i discendenti
    $prjRamo = []; $__stack = [$prjId];
    while (!empty($__stack) && count($prjRamo) < 500) {
        $__n = array_pop($__stack);
        $prjRamo[] = $__n;
        foreach (($prjFigliDi[$__n] ?? []) as $__c) { $__stack[] = $__c; }
    }
    $prjPh = implode(',', array_fill(0, count($prjRamo), '?'));

    // figli diretti: quanti sono e quanti chiusi
    $prjFigli = $prjFigliDi[$prjId] ?? [];
    $prjFigliChiusi = 0;
    $prjFigliElenco = [];
    foreach ($prjFigli as $__c) {
        $__chiuso = (($prjTutti[$__c]['status'] ?? '') === 'completed');
        if ($__chiuso) $prjFigliChiusi++;
        if (count($prjFigliElenco) < 6) {
            $prjFigliElenco[] = [
                'id' => $__c,
                'name' => $prjTutti[$__c]['name'],
                'done' => $__chiuso,
                'color' => (preg_match('/^#[0-9A-Fa-f]{6}$/', (string)$prjTutti[$__c]['color']))
                           ? $prjTutti[$__c]['color'] : '#8E8E93',
            ];
        }
    }

    // pomodoro: tempo e sessioni su tutto il ramo, piu' la settimana appena passata
    $__pom = sec_one("SELECT COUNT(*) AS n, COALESCE(SUM(duration_seconds), 0) AS tot
                      FROM pomodoro_sessions
                      WHERE user_id = ? AND project_id IN ($prjPh)",
                     array_merge([$current_user_id], $prjRamo));
    $__pom7 = sec_one("SELECT COALESCE(SUM(duration_seconds), 0) AS tot
                       FROM pomodoro_sessions
                       WHERE user_id = ? AND project_id IN ($prjPh)
                         AND start_time >= (CURDATE() - INTERVAL 6 DAY)",
                      array_merge([$current_user_id], $prjRamo));

    // attivita' collegate
    $__todo = sec_one("SELECT COUNT(*) AS n, COALESCE(SUM(is_done), 0) AS fatti
                       FROM todos WHERE user_id = ? AND project_id IN ($prjPh)",
                      array_merge([$current_user_id], $prjRamo));

    // mazzi di flashcard collegati al ramo
    $prjMazzi = [];
    foreach (sec_all("SELECT c.id, c.name, c.color, COUNT(i.id) AS carte
                      FROM project_fc_categories pfc
                      JOIN fc_categories c ON c.id = pfc.fc_category_id AND c.user_id = ?
                      LEFT JOIN fc_images i ON i.category_id = c.id
                      WHERE pfc.project_id IN ($prjPh)
                      GROUP BY c.id, c.name, c.color
                      ORDER BY c.name ASC
                      LIMIT 8",
                     array_merge([$current_user_id], $prjRamo)) as $__m) {
        $prjMazzi[] = [
            'id' => (int)$__m['id'],
            'name' => $__m['name'],
            'color' => fc_color_or_default($__m['color']),
            'carte' => (int)$__m['carte'],
            'corrente' => ((int)$__m['id'] === (int)$categoryID),
        ];
    }

    // il prossimo appuntamento gia' in calendario per questo progetto
    $__ev = sec_one("SELECT title, event_date, start_time, is_all_day
                     FROM planner_events
                     WHERE user_id = ? AND project_id IN ($prjPh)
                       AND is_cancelled = 0 AND event_date >= CURDATE()
                     ORDER BY event_date ASC, start_time ASC
                     LIMIT 1",
                    array_merge([$current_user_id], $prjRamo));

    $__oggi = new DateTime('today');
    $giorniA = function ($data) use ($__oggi) {
        if (empty($data)) return null;
        try { $d = new DateTime($data); } catch (Exception $e) { return null; }
        return (int)$__oggi->diff($d)->format('%r%a');
    };

    echo json_encode([
        'success'   => true,
        'id'        => $prjId,
        'name'      => $prj['name'],
        'notes'     => mb_substr(trim((string)$prj['notes']), 0, 400),
        'status'    => $prj['status'],
        'favorite'  => (int)$prj['is_favorite'] === 1,
        'color'     => preg_match('/^#[0-9A-Fa-f]{6}$/', (string)$prj['color']) ? $prj['color'] : '#8E8E93',
        'path'      => $prjAntenati,
        'target'    => $prj['target_date'],
        'target_in' => $giorniA($prj['target_date']),
        'due'       => $prj['due_date'],
        'due_in'    => $giorniA($prj['due_date']),
        'secondi'   => (int)($__pom['tot'] ?? 0),
        'secondi7'  => (int)($__pom7['tot'] ?? 0),
        'sessioni'  => (int)($__pom['n'] ?? 0),
        'figli'     => count($prjFigli),
        'figli_ok'  => $prjFigliChiusi,
        'figli_el'  => $prjFigliElenco,
        'todo'      => (int)($__todo['n'] ?? 0),
        'todo_ok'   => (int)($__todo['fatti'] ?? 0),
        'mazzi'     => $prjMazzi,
        'evento'    => $__ev ? [
            'title'    => $__ev['title'],
            'date'     => $__ev['event_date'],
            'time'     => ((int)$__ev['is_all_day'] === 1) ? '' : substr((string)$__ev['start_time'], 0, 5),
            'giorni'   => $giorniA($__ev['event_date']),
        ] : null,
    ]);
    exit;
}

// ==========================================
// AJAX: rinomina la categoria che si sta guardando (tocco sul nome nella
// barra in alto) e cambio del suo colore (tocco sulla pastiglia).
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])
    && in_array($_POST['action'], ['fc_rename_cat', 'fc_recolor_cat'], true)) {
    header('Content-Type: application/json; charset=utf-8');
    sec_guard_write($current_user_id, 'fc_vis', 0, 120, 60);

    $ccId = sec_int($_POST['category_id'] ?? 0, 1);
    if (!sec_owns('fc_categories', $ccId, $current_user_id)) {
        echo json_encode(['success' => false, 'error' => t('fc.vis_categoria_non_valida')]); exit;
    }

    if ($_POST['action'] === 'fc_rename_cat') {
        $ccNome = sec_str($_POST['name'] ?? '', 120);
        if (trim($ccNome) === '') {
            echo json_encode(['success' => false, 'error' => t('fc.vis_nome_mancante')]); exit;
        }
        sec_q("UPDATE fc_categories SET name = ? WHERE id = ? AND user_id = ?",
              [$ccNome, $ccId, $current_user_id]);
        echo json_encode(['success' => true, 'name' => $ccNome]);
        exit;
    }

    /* Colore. "auto" non e' un colore ma una rinuncia: si azzera color_edited e
       si riprende quello del padre, poi lo si ripropaga ai discendenti che a
       loro volta ereditano. */
    $ccModo = sec_enum($_POST['color_mode'] ?? null, ['auto', 'manual'], 'auto');
    $ccCol  = sec_color($_POST['color'] ?? null, '');
    $ccRiga = sec_one("SELECT parent_id FROM fc_categories WHERE id = ? AND user_id = ?",
                      [$ccId, $current_user_id]);
    $ccPadre = $ccRiga ? (int)$ccRiga['parent_id'] : 0;

    if ($ccModo === 'manual' && $ccCol !== '') {
        $ccFinale = $ccCol; $ccEdited = 1;
    } else {
        $ccFinale = fc_colore_ereditato($ccPadre, $current_user_id); $ccEdited = 0;
    }
    sec_q("UPDATE fc_categories SET color = ?, color_edited = ? WHERE id = ? AND user_id = ?",
          [$ccFinale, $ccEdited, $ccId, $current_user_id]);
    fc_cascata_colore($ccId, $ccFinale, $current_user_id);

    echo json_encode(['success' => true, 'color' => $ccFinale, 'edited' => $ccEdited]);
    exit;
}

/* Ripropaga un colore ai discendenti che ereditano, fermandosi ai rami che
   hanno un colore scelto a mano: quelli non si toccano. Stessa regola di
   cs_cascade_color() in categories_fc.php. */
function fc_cascata_colore($categoryId, $color, $userId, $depth = 0) {
    if ($depth > 50) return;
    foreach (sec_all("SELECT id, color_edited FROM fc_categories WHERE parent_id = ? AND user_id = ?",
                     [(int)$categoryId, (int)$userId]) as $ch) {
        if ((int)$ch['color_edited'] === 0) {
            sec_q("UPDATE fc_categories SET color = ? WHERE id = ? AND user_id = ?",
                  [$color, (int)$ch['id'], (int)$userId]);
            fc_cascata_colore((int)$ch['id'], $color, $userId, $depth + 1);
        }
    }
}


// ==========================================

// SOTTOCATEGORIE della categoria corrente (per la mini-griglia "sottocartelle")

// ==========================================

$subcategories = [];

$subcat_children_count = [];

$subcat_card_counts = [];



if ($categoryID != 0) {

    foreach ($categories_list as $c) {

        $pid = isset($c['parent_id']) ? (int)$c['parent_id'] : 0;

        if ($pid == $categoryID) {

            $subcategories[] = $c;

        }

    }



    // Quante sotto-sottocategorie ha ciascuna sottocategoria (badge "cartelle")

    foreach ($categories_list as $c) {

        $pid = isset($c['parent_id']) ? (int)$c['parent_id'] : 0;

        if ($pid != 0) {

            if (!isset($subcat_children_count[$pid])) { $subcat_children_count[$pid] = 0; }

            $subcat_children_count[$pid]++;

        }

    }



    if (!empty($subcategories)) {

        $subcat_ids = array_map(function($c) { return (int)$c['id']; }, $subcategories);

        foreach ($subcat_ids as $sid) { $subcat_card_counts[$sid] = 0; }

                $subcat_ph = implode(',', array_fill(0, count($subcat_ids), '?'));



        // Carte "padre" o singole, direttamente dentro ciascuna sottocategoria

        $image_to_subcat = [];

        $all_image_ids_in_subcats = [];

                foreach (sec_all("SELECT id, category_id FROM fc_images WHERE category_id IN ($subcat_ph)", $subcat_ids) as $r) {

            $cid = (int)$r['category_id'];

            $subcat_card_counts[$cid]++;

            $image_to_subcat[(int)$r['id']] = $cid;

            $all_image_ids_in_subcats[] = (int)$r['id'];

        }



        // Carte "figlie" agganciate ai padri di quelle sottocategorie

        if (!empty($all_image_ids_in_subcats)) {

                        $img_ph = implode(',', array_fill(0, count($all_image_ids_in_subcats), '?'));
            foreach (sec_all("SELECT parent_id, COUNT(*) AS c FROM fc_child_cards WHERE parent_id IN ($img_ph) GROUP BY parent_id",
                             $all_image_ids_in_subcats) as $r) {

                $pid_img = (int)$r['parent_id'];

                if (isset($image_to_subcat[$pid_img])) {

                    $subcat_card_counts[$image_to_subcat[$pid_img]] += (int)$r['c'];

                }

            }

        }

    }

}



$global_fc_counts = [];

// ==========================================
// CONTEGGIO CARTE della categoria corrente (comprese TUTTE le sottocategorie).
// Prima mostrava sempre "0 carte" perche' il valore non veniva mai calcolato.
// ==========================================
$fc_total_cards = 0;
{
    $fc_children_map = [];
    foreach ($categories_list as $c) {
        $pid = isset($c['parent_id']) ? (int)$c['parent_id'] : 0;
        $fc_children_map[$pid][] = (int)$c['id'];
    }
    $fc_count_cat_ids = [];
    if ($categoryID == 0) {
        foreach ($categories_list as $c) { $fc_count_cat_ids[] = (int)$c['id']; }
    } else {
        $stack = [$categoryID];
        while (!empty($stack)) {
            $cur = array_pop($stack);
            $fc_count_cat_ids[] = $cur;
            foreach (($fc_children_map[$cur] ?? []) as $ch) { $stack[] = $ch; }
        }
    }
    if (!empty($fc_count_cat_ids)) {
                $conteggio_ids = array_map('intval', $fc_count_cat_ids);
        $conteggio_ph = implode(',', array_fill(0, count($conteggio_ids), '?'));
        $fc_total_cards += (int)(sec_one("SELECT COUNT(*) AS c FROM fc_images WHERE category_id IN ($conteggio_ph)",
                                         $conteggio_ids)['c'] ?? 0);
        $img_ids = [];
        foreach (sec_all("SELECT id FROM fc_images WHERE category_id IN ($conteggio_ph)", $conteggio_ids) as $ri) {
            $img_ids[] = (int)$ri['id'];
        }
        if (!empty($img_ids)) {
            $img_ph2 = implode(',', array_fill(0, count($img_ids), '?'));
            $fc_total_cards += (int)(sec_one("SELECT COUNT(*) AS c FROM fc_child_cards WHERE parent_id IN ($img_ph2)",
                                             $img_ids)['c'] ?? 0);
        }
    }
}
// ==========================================
// PROGETTO collegato alla categoria corrente (o al primo antenato collegato).
// Se una categoria non e' collegata ma lo e' il padre/nonno, mostriamo quello.
// ==========================================
$fc_linked_project = null;   // ['id','name','color','via_ancestor'=>bool]
if ($categoryID != 0) {
    // mappa id -> parent per risalire la catena
    $fc_parent_of = [];
    foreach ($categories_list as $c) { $fc_parent_of[(int)$c['id']] = isset($c['parent_id']) ? (int)$c['parent_id'] : 0; }
    // catena: categoria corrente, poi antenati
    $fc_chain = [];
    $cur = $categoryID; $guard = 0;
    while ($cur > 0 && $guard < 60) { $fc_chain[] = $cur; $cur = $fc_parent_of[$cur] ?? 0; $guard++; }
    if (!empty($fc_chain)) {
                $chain_ids = array_map('intval', $fc_chain);
        $chain_ph = implode(',', array_fill(0, count($chain_ids), '?'));
        $lpRes = sec_all("SELECT pfc.fc_category_id, p.id AS pid, p.name AS pname, p.color AS pcolor, pp.name AS parent_name
                          FROM project_fc_categories pfc
                          INNER JOIN projects p ON p.id = pfc.project_id AND p.user_id = ?
                          LEFT JOIN projects pp ON pp.id = p.parent_id AND pp.user_id = ?
                          WHERE pfc.fc_category_id IN ($chain_ph)",
                         array_merge([$current_user_id, $current_user_id], $chain_ids));
        $by_cat = [];
        foreach ($lpRes as $lp) {
            $pcol = (!empty($lp['pcolor']) && preg_match('/^#[0-9A-Fa-f]{6}$/', $lp['pcolor'])) ? $lp['pcolor'] : '#8E8E93';
            $by_cat[(int)$lp['fc_category_id']] = ['id' => (int)$lp['pid'], 'name' => $lp['pname'], 'parent_name' => $lp['parent_name'], 'color' => $pcol];
        }
        // scegli il primo lungo la catena (categoria stessa prima, poi antenati)
        foreach ($fc_chain as $i => $cid) {
            if (isset($by_cat[$cid])) {
                $fc_linked_project = $by_cat[$cid];
                $fc_linked_project['via_ancestor'] = ($i > 0);
                break;
            }
        }
    }
}

// Cerca i duplicati SOLO tra le carte che appartengono alle categorie dell'utente loggato
foreach (sec_all("SELECT i.question_desc FROM fc_images i JOIN fc_categories c ON i.category_id = c.id WHERE c.user_id = ?",
                 [$current_user_id]) as $r) {

    $qk = strtolower(trim($r['question_desc']));

    if(!isset($global_fc_counts[$qk])) $global_fc_counts[$qk] = 0;

    $global_fc_counts[$qk]++;

}



// Query blindata: usa una JOIN rigida per assicurarsi che la categoria appartenga al current_user_id
// Query blindata: usa una JOIN rigida per assicurarsi che la categoria appartenga al current_user_id
$query = "SELECT i.*, c.name AS category_name, c.color AS category_color, (CASE WHEN ul.fc_image_id IS NOT NULL THEN 1 ELSE 0 END) as is_learnt 
          FROM fc_images AS i 
          JOIN fc_categories AS c ON i.category_id = c.id 
          LEFT JOIN user_learnt_fc ul ON i.id = ul.fc_image_id AND ul.user_id = ? 
          WHERE c.user_id = ?";

// Se è selezionata una categoria specifica, aggiunge la condizione con AND invece di WHERE
$par = [$current_user_id, $current_user_id];
if ($categoryID != 0) { $query .= " AND i.category_id = ?"; $par[] = $categoryID; }



// Modifica ordinamento: default id_desc per "tutte le materie"
if ($categoryID == 0 && !isset($_GET['sort'])) {
    $sort = 'id_desc';
} else {
        $sort = sec_enum($_GET['sort'] ?? null, ['custom_asc', 'id_desc', 'id_asc', 'q_asc'], 'custom_asc');
}

$order_by = "i.custom_order ASC"; 
if ($sort === 'id_desc') { $order_by = "i.id DESC"; }
elseif ($sort === 'id_asc') { $order_by = "i.id ASC"; }
elseif ($sort === 'q_asc') { $order_by = "TRIM(LOWER(i.question_desc)) ASC"; }

/* $order_by non e' un valore ma un pezzo di SQL, e non puo' essere un
   segnaposto: nasce dall'if qui sopra a quattro rami, quindi non arriva
   dall'esterno. */
$query .= " ORDER BY $order_by";
$images_data = [];
$ids_array = [];
foreach (sec_all($query, $par) as $row) {

    $row['children'] = [];

    $images_data[$row['id']] = $row;

    $ids_array[] = $row['id'];

}



if (count($ids_array) > 0) {

        $ids_ph = implode(',', array_fill(0, count($ids_array), '?'));
    foreach (sec_all("SELECT *, id_child AS id FROM fc_child_cards WHERE parent_id IN ($ids_ph) ORDER BY custom_order ASC",
                     array_map('intval', $ids_array)) as $c) {

        $images_data[$c['parent_id']]['children'][] = $c;

    }

}



// Conteggio esempi reali: due liste separate (padri e figli), perche' ora
// sono due colonne diverse in fc_examples (parent_id / child_id), non piu'
// un'unica colonna con il segno a fare da spartiacque.

$examples_counts = [];

$child_ids = [];

foreach ($images_data as $row) {

    foreach ($row['children'] as $child) { $child_ids[] = intval($child['id']); }

}

if (count($ids_array) > 0) {

    $ph = implode(',', array_fill(0, count($ids_array), '?'));
    foreach (sec_all("SELECT parent_id, COUNT(*) AS c FROM fc_examples WHERE parent_id IN ($ph) GROUP BY parent_id",
                     array_map('intval', $ids_array)) as $ex) {
        $examples_counts[(int)$ex['parent_id']] = (int)$ex['c'];
    }

}

if (count($child_ids) > 0) {

    $ph2 = implode(',', array_fill(0, count($child_ids), '?'));
    /* La chiave negativa resta solo come convenzione privata di QUESTO
       array PHP, per distinguere un figlio da un padre con lo stesso
       numero dentro un unico dizionario — render_examples_section() piu'
       sotto la usa gia' cosi'. Non ha piu' nulla a che fare col database:
       li' padre e figlio sono due colonne separate. */
    foreach (sec_all("SELECT child_id, COUNT(*) AS c FROM fc_examples WHERE child_id IN ($ph2) GROUP BY child_id",
                     array_map('intval', $child_ids)) as $ex) {
        $examples_counts[-1 * (int)$ex['child_id']] = (int)$ex['c'];
    }

}



// Puo' il riordino dei PADRI essere attivato? Solo con ordinamento personalizzato e su una categoria precisa

// (in "Tutte le Categorie" l'ordine custom_order non e' comparabile tra categorie diverse).

$parent_reorder_enabled = ($sort === 'custom_asc' && $categoryID != 0);

$child_reorder_enabled = ($sort === 'custom_asc');

/* Stessa tavolozza di categories_fc.php: una cartella creata da qui deve
   poter avere gli stessi colori di una creata di la'. */
$colorPalette = ['#FF3B30', '#FF9500', '#FFCC00', '#34C759', '#00C7BE', '#30B0C7',
                 '#32ADE6', '#007AFF', '#5856D6', '#AF52DE', '#FF2D55', '#A2845E'];



// Sezione "Examples": visibile solo se esistono davvero esempi per quella carta

// render_examples_section() vive in examples_box.php, incluso in cima a
// questo file — condivisa con modifica_immagine_fc.php.


// ==========================================

// HELPER: normalizza un colore categoria con fallback

// ==========================================

function fc_color_or_default($color) {

    $color = trim((string)$color);

    return $color !== '' ? $color : '#007AFF';

}



// ==========================================

// HELPER: render di un lato (Domanda/Risposta) di una carta.

// Se manca il testo o l'immagine, il contenuto presente occupa tutto lo spazio.

// L'audio, se presente, e' sempre in fondo con la sua barra.

// ==========================================

/* $lato ('q' o 'a') finisce come classe sul contenitore. Serve alla vista
   compatta: li' le etichette "Domanda"/"Risposta" spariscono per far posto,
   e senza un segno che le distingua le due righe diventano due righe di
   testo uguali di cui non si sa quale sia quale. Con la classe, la domanda
   resta scura e la risposta piu' tenue — la stessa coppia che usa la vista
   compatta di inserimento_big_fc.php. */
function render_qa_side($label, $text, $image, $audio, $lato = '') {

    $text = trim((string)$text);

    $image = trim((string)$image);

    $audio = trim((string)$audio);

    $hasText = $text !== '';

    $hasImage = $image !== '';

    $hasAudio = $audio !== '';

    $contentClass = ($hasText && $hasImage) ? 'qa-split' : 'qa-solo';

    ?>

    <div class="qa-side<?= $lato === 'q' ? ' qa-q' : ($lato === 'a' ? ' qa-a' : '') ?>">

        <div class="qa-label"><?= sec_h($label) ?></div>

        <?php if ($hasText || $hasImage): ?>

        <div class="qa-content <?= sec_h($contentClass) ?>">

            <?php if ($hasText): ?>

                <div class="qa-text raw-text-content" data-raw="<?= sec_h($text) ?>"></div>

            <?php endif; ?>

            <?php if ($hasImage): ?>

                <img class="qa-image" src="fc_Images/<?= sec_h($image) ?>" alt="" loading="lazy" onclick="event.stopPropagation(); openImageZoom(this.src);">

            <?php endif; ?>

        </div>

        <?php else: ?>

        <div class="qa-content qa-empty"><span>—</span></div>

        <?php endif; ?>

        <?php if ($hasAudio): render_audio_player('fc_audio/' . sec_h($audio)); endif; ?>

    </div>

    <?php

}



function render_audio_player($src) {

    ?>

    <div class="audio-player" data-src="<?= sec_h($src) ?>">

        <button type="button" class="ap-btn" aria-label="<?php te('fc.vis_riproduci'); ?>" onclick="event.stopPropagation(); toggleAudioPlayer(this);">

            <svg class="ap-ic ap-ic-play" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5.14v13.72c0 .84.91 1.36 1.63.93l11.32-6.86a1.08 1.08 0 0 0 0-1.86L9.63 4.21A1.08 1.08 0 0 0 8 5.14Z"/></svg>

            <svg class="ap-ic ap-ic-pause" viewBox="0 0 24 24" fill="currentColor" style="display:none"><path d="M7 5.5A1.5 1.5 0 0 1 8.5 4h1A1.5 1.5 0 0 1 11 5.5v13a1.5 1.5 0 0 1-1.5 1.5h-1A1.5 1.5 0 0 1 7 18.5v-13ZM13 5.5A1.5 1.5 0 0 1 14.5 4h1A1.5 1.5 0 0 1 17 5.5v13a1.5 1.5 0 0 1-1.5 1.5h-1a1.5 1.5 0 0 1-1.5-1.5v-13Z"/></svg>

        </button>

        <div class="ap-track" onclick="event.stopPropagation(); seekAudioPlayer(event, this);">

            <div class="ap-fill"></div>

            <div class="ap-knob"></div>

        </div>

        <span class="ap-time">0:00</span>

        <audio preload="none" src="<?= sec_h($src) ?>"></audio>

    </div>

    <?php

}

?>

<!DOCTYPE html>

<html lang="it" style="--cat-color: <?= sec_h(fc_color_or_default($current_cat_color)) ?>;">

<head>

<meta charset="UTF-8">

<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=0, viewport-fit=cover">

<meta name="mobile-web-app-capable" content="yes">

<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">

<meta name="format-detection" content="telephone=no">

<meta name="theme-color" content="#F2F2F7" media="(prefers-color-scheme: light)">

<meta name="theme-color" content="#000000" media="(prefers-color-scheme: dark)">

<title><?php te('fc.vis_titolo'); ?></title>

<meta name="csrf-token" content="<?php echo sec_h(sec_csrf_token()); ?>">
<script src="nisama_fetch.js"></script>

<?php echo nisama_i18n_json(['comune', 'fc', 'vocab']); ?>
<script>

    window.MathJax = {

    /* I dollari NON sono piu' delimitatori di formula.
       Col formato nuovo la formattazione non sta dentro il testo: una formula
       e' un elemento suo, e il codice LaTeX gli arriva gia' racchiuso in
       \\( \\) o \\[ \\] da fcdDisegnaFormule(). Lasciare attivo il "$"
       significherebbe che una frase come "il libro costa 5$ e il quaderno 3$"
       verrebbe presa per una formula e stampata in corsivo matematico - lo
       stesso identico bug che il formato nuovo elimina. */

    tex: {

        inlineMath: [['\\(', '\\)']],

        displayMath: [['\\[', '\\]']]

    }

    };

</script>

<script id="MathJax-script" async src="https://cdn.jsdelivr.net/npm/mathjax@3/es5/tex-mml-chtml.js"></script>

<script src="fc_parser.js"></script>
<script src="examples_modal.js"></script>
<script src="<?php echo PWA_BASE; ?>fc_edit_popup.js"></script>



<link rel="stylesheet" href="<?php echo PWA_BASE; ?>fc_edit_popup.css">
<link rel="stylesheet" href="<?php echo PWA_BASE; ?>examples_box.css">
<link rel="stylesheet" href="<?php echo PWA_BASE; ?>visualizzazione_fc.css?v=<?php echo @filemtime(dirname(__FILE__) . '/visualizzazione_fc.css') ?: 1; ?>">

</head>

<body>



<div id="image-overlay">

    <div id="close-overlay" onclick="closeImageZoom()">&#10005;</div>

    <img id="overlay-img" src="" alt="">

</div>



<header class="app-header" id="appHeader">

    <div class="header-top">

        <div class="cat-pill<?= $categoryID != 0 ? ' editable' : '' ?>" id="catPill" onclick="fcToccaPastiglia(event)">

            <span class="cat-pill-dot"></span>

            <div class="cat-pill-text">

                <div class="cat-pill-label"><?php te('vocab.cat_categoria'); ?></div>

                <div class="cat-pill-name" id="catPillName"<?php if ($categoryID != 0): ?> onclick="fcApriRinomina(event)" title="<?php te('fc.vis_rinomina'); ?>"<?php endif; ?>><?= sec_h($current_cat_name) ?></div>
                <?php if ($categoryID != 0): ?>
                <input type="text" class="cat-pill-name-input" id="catPillNameInput" maxlength="120" style="display:none;" aria-label="<?php te('fc.vis_rinomina'); ?>">
                <?php endif; ?>
                <?php if ($categoryID != 0): ?>
                    <?php if ($fc_linked_project): ?>
                    <button type="button" class="cat-proj-chip<?= !empty($fc_linked_project['via_ancestor']) ? ' via-ancestor' : '' ?>"
                            style="--cp-color:<?= sec_h($fc_linked_project['color']) ?>;"
                            onclick="event.stopPropagation(); fcApriProgetto(<?= (int)$fc_linked_project['id'] ?>);">
                        <svg viewBox="0 0 24 24" width="10" height="10" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z"/></svg>
                        <?php if (!empty($fc_linked_project['parent_name'])): ?><span class="cp-anc"><?= sec_h($fc_linked_project['parent_name']) ?></span><span class="cp-sep">•</span><?php endif; ?><span class="cp-leaf"><?= sec_h($fc_linked_project['name']) ?></span>
                    </button>
                    <?php else: ?>
                    <button type="button" class="cat-proj-chip cat-proj-link" onclick="event.stopPropagation(); fcOpenLinkProject();">
                        <svg viewBox="0 0 24 24" width="11" height="11" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
                        <?php te('fc.vis_collega_progetto'); ?>
                    </button>
                    <?php endif; ?>
                <?php endif; ?>

            </div>

            <span class="cat-pill-count" id="catCount"><?= (int)$fc_total_cards ?> <?= ((int)$fc_total_cards === 1 ? 'carta' : 'carte') ?></span>

        </div>

    </div>



    <div class="header-row2">

        <nav class="cat-path" id="catPath" aria-label="<?php te('vocab.vi_percorso'); ?>">
            <a href="categories_fc.php" class="cat-path-home" aria-label="<?php te('vocab.vi_home'); ?>" title="<?php te('vocab.vi_torna_cat'); ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m3 10 9-7 9 7v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2Z"/></svg>
            </a>
            <span class="cat-path-sep" aria-hidden="true">&rsaquo;</span>
            <?php if ($categoryID == 0): ?>
            <span class="cat-path-item cat-path-current"><?php te('fc.vis_tutte_categorie'); ?></span>
            <?php else: ?>
                <?php foreach ($fc_path_ltr as $__anc): ?>
                <a class="cat-path-item" href="visualizzazione_fc.php?category_id=<?= (int)$__anc['id'] ?>"><?= sec_h($__anc['name']) ?></a>
                <span class="cat-path-sep" aria-hidden="true">&rsaquo;</span>
                <?php endforeach; ?>
            <span class="cat-path-item cat-path-current"><?= sec_h($current_cat_name) ?></span>
            <?php endif; ?>
        </nav>

        <button type="button" class="icon-btn quick-add-btn" onclick="fcApriAggiungi()" aria-label="<?php te('fc.vis_aggiungi_fc'); ?>" title="<?php te('fc.vis_aggiungi_in_cat'); ?>">

            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>

        </button>

        <?php if ($categoryID != 0): ?>
        <a href="categories_game_fc.php?preselect_category=<?= sec_h($categoryID) ?>" class="icon-btn play-cat-btn" aria-label="<?php te('vocab.vi_gioca'); ?>" title="Gioca con questa categoria">
            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M8 5.14v13.72a1 1 0 0 0 1.53.85l11-6.86a1 1 0 0 0 0-1.7l-11-6.86A1 1 0 0 0 8 5.14Z"/></svg>
        </a>
        <a href="inserimento_big_fc.php?category_id=<?= sec_h($categoryID) ?>&modo=edit" class="icon-btn big-edit-btn" aria-label="<?php te('vocab.vi_big_edit'); ?>" title="Big Edit">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
        </a>
        <?php endif; ?>

    </div>



    <div class="toolbar-row" id="normalToolbar">

        <div class="search-wrap">

            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>

            <input id="filterText" class="search-input" type="text" placeholder="<?php te('fc.vis_cerca'); ?>" oninput="fcOnSearchInput()">

        </div>

        <div class="tb-select-wrap">

            <select id="filterSort" class="tb-select" onchange="changeSort()">

                <option value="custom_asc" <?= $sort == 'custom_asc' ? 'selected' : '' ?>><?php te('fc.vis_posizione'); ?></option>

                <option value="id_desc" <?= $sort == 'id_desc' ? 'selected' : '' ?>><?php te('fc.vis_id_recenti'); ?></option>

                <option value="id_asc" <?= $sort == 'id_asc' ? 'selected' : '' ?>><?php te('fc.vis_id_meno_recenti'); ?></option>

                <option value="q_asc" <?= $sort == 'q_asc' ? 'selected' : '' ?>><?php te('fc.vis_domanda_az'); ?></option>

            </select>

        </div>

        <button type="button" class="tb-icon-btn" id="settingsBtn" onclick="fcApriImpostazioni()"
                aria-label="<?php te('fc.vis_impostazioni'); ?>" title="<?php te('fc.vis_impostazioni'); ?>">

            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3.1"/><path d="M19.4 15a1.6 1.6 0 0 0 .32 1.77l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.6 1.6 0 0 0-1.77-.32 1.6 1.6 0 0 0-1 1.47V21a2 2 0 1 1-4 0v-.11a1.6 1.6 0 0 0-1.05-1.46 1.6 1.6 0 0 0-1.77.32l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.6 1.6 0 0 0 .32-1.77 1.6 1.6 0 0 0-1.47-1H3a2 2 0 1 1 0-4h.11A1.6 1.6 0 0 0 4.57 9a1.6 1.6 0 0 0-.32-1.77l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.6 1.6 0 0 0 1.77.32H9a1.6 1.6 0 0 0 1-1.47V3a2 2 0 1 1 4 0v.11a1.6 1.6 0 0 0 1 1.47 1.6 1.6 0 0 0 1.77-.32l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.6 1.6 0 0 0-.32 1.77V9a1.6 1.6 0 0 0 1.47 1H21a2 2 0 1 1 0 4h-.11a1.6 1.6 0 0 0-1.47 1Z"/></svg>

        </button>

        <button type="button" class="select-mode-btn icon-only" id="selectModeBtn" onclick="enterSelectMode()"
                aria-label="<?php te('vocab.vi_seleziona'); ?>" title="<?php te('vocab.vi_seleziona'); ?>">

            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m9 12 2 2 4-4"/><rect x="3" y="3" width="18" height="18" rx="6"/></svg>

        </button>

    </div>



    <?php /* LA BARRA DELLA SELEZIONE, IN TRE GRUPPI
             Prima era una fila unica: X, Tutte, conteggio, Sposta, Unisci,
             Separa, Elimina. Sette comandi di fila, e nessun segno che
             dicesse che i primi tre parlano della SELEZIONE (chi scegliere)
             e gli altri di che cosa FARNE. Le lineette dividono le due cose,
             e dividono anche le azioni fra loro: Sposta ed Elimina agiscono
             sulle carte scelte, Unisci e Separa cambiano la struttura
             padre/figlio, che e' un altro mestiere.
             Sposta ed Elimina, con niente di selezionato, non hanno nessun
             lavoro da fare: se ne vanno e si portano dietro la loro lineetta,
             cosi' non restano due stanghette appiccicate. */ ?>
    <div class="select-toolbar" id="selectToolbar" style="display:none;">

        <button type="button" class="st-cancel" onclick="onSelectCancel()" aria-label="<?php te('fc.vis_annulla_sel'); ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
        </button>

        <button type="button" class="st-selectall" id="selectAllBtn" onclick="toggleSelectAll()"><?php te('vocab.vi_tutte'); ?></button>

        <?php /* La vista compatta e' lo stesso interruttore di
                 inserimento_big_fc.php, con le stesse tre righine: una carta
                 per riga, l'inizio della domanda e l'inizio della risposta.
                 Sta qui e non nella barra normale perche' e' una vista di
                 LAVORO — serve a vedere venti carte insieme mentre se ne
                 scelgono alcune, non a leggerle. */ ?>
        <button type="button" class="st-compact" id="compactBtn" onclick="toggleCompactMode()"
                aria-label="<?php te('fc.vis_compatta'); ?>" title="<?php te('fc.vis_compatta'); ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M4 7h16M4 12h16M4 17h16"/>
            </svg>
        </button>

        <div class="st-count" id="selectCount">0</div>

        <div class="st-actions" id="stActions">

            <span class="st-sep" id="stSepScelte" aria-hidden="true"></span>

            <span class="st-group" id="stGruppoScelte">
                <button type="button" class="st-action" data-action="move" onclick="onBulkActionPick('move')">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><path d="M21 8V6a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h6"/><path d="M17 22v-6M14 19h6"/></svg>
                    <?php te('vocab.cat_sposta_attr'); ?>
                </button>

                <button type="button" class="st-action danger" data-action="delete" onclick="onBulkActionPick('delete')">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0-1 14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2L4 6"/></svg>
                    <?php te('comune.elimina'); ?>
                </button>
            </span>

            <span class="st-sep" id="stSepStruttura" aria-hidden="true"></span>

            <span class="st-group" id="stGruppoStruttura">
                <button type="button" class="st-action" data-action="join" id="btnUnisci" onclick="onBulkActionPick('join')">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><circle cx="7" cy="12" r="3"/><circle cx="17" cy="6" r="3"/><circle cx="17" cy="18" r="3"/><path d="M9.6 10.5 14.5 7.7M9.6 13.5l4.9 2.8"/></svg>
                    <?php te('vocab.vi_unisci'); ?>
                </button>

                <button type="button" class="st-action" data-action="unjoin" id="btnSepara" onclick="onBulkActionPick('unjoin')">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><circle cx="6" cy="6" r="3"/><circle cx="6" cy="18" r="3"/><path d="M20 4 8.12 15.88M14.47 14.48 20 20M8.12 8.12 12 12"/></svg>
                    <?php te('vocab.vi_separa'); ?>
                </button>
            </span>

        </div>

    </div>

    <?php /* Una riga che dice cosa sta succedendo. Le modalita' Separa e
             Unisci cambiano le regole sotto i piedi — spariscono delle carte,
             certe caselle non ci sono piu' — e senza una frase che lo dica
             sembra che la pagina si sia rotta. */ ?>
    <div class="join-banner mode-banner" id="modeBanner" style="display:none;">
        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>
        <span id="modeBannerText"></span>
    </div>

</header>



<?php include 'sidebar.html'; ?>



<main class="feed">

    <div class="feed-inner">



        <?php if (!empty($subcategories)): ?>

        <div class="fc-subcats">

            <div class="fc-subcats-label">

                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z"/></svg>

                <?php te('vocab.vi_sottocategorie'); ?>

            </div>

            <div class="fc-subcats-grid">

                <?php foreach ($subcategories as $sc):

                    $scColor = fc_color_or_default($sc['color']);

                    $scCardCount = isset($subcat_card_counts[$sc['id']]) ? (int)$subcat_card_counts[$sc['id']] : 0;

                    $scFolderCount = isset($subcat_children_count[$sc['id']]) ? (int)$subcat_children_count[$sc['id']] : 0;

                ?>

                <a href="visualizzazione_fc.php?category_id=<?= (int)$sc['id'] ?>" class="fc-subcat-card" style="--sc-color: <?= sec_h($scColor) ?>;">

                    <span class="fc-subcat-dot">

                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z"/></svg>

                    </span>

                    <div class="fc-subcat-name"><?= sec_h($sc['name']) ?></div>

                    <div class="fc-subcat-meta">

                        <span title="<?php te('fc.vis_n_carte', ['n' => (int)$scCardCount]); ?>">

                            <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="7" width="13" height="10" rx="2.2"/><path d="M7.5 7V5a2 2 0 0 1 2-2h7a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2h-2"/></svg>

                            <?= sec_h($scCardCount) ?>

                        </span>

                        <?php if ($scFolderCount > 0): ?>

                        <span title="<?php te('fc.vis_n_sottocategorie', ['n' => (int)$scFolderCount]); ?>">

                            <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h3.5l1.5 2H19a2 2 0 0 1 2 2v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z"/></svg>

                            <?= sec_h($scFolderCount) ?>

                        </span>

                        <?php endif; ?>

                    </div>

                </a>

                <?php endforeach; ?>

            </div>

        </div>

        <?php endif; ?>



        <div class="item-counter" id="itemCounter"><span class="ic-dot"></span> <?php th('fc.vis_n_carte_mostrate', ['n' => '<b id="itemCounterNum">0</b>']); ?></div>



        <form id="bulkForm" method="POST">

            <input type="hidden" name="csrf_token" value="<?php echo sec_h(sec_csrf_token()); ?>">

            <input type="hidden" name="bulk_action" id="hiddenBulkAction">

            <input type="hidden" name="target_category" id="hiddenTargetCategory">

            <?php /* Il padre scelto arriva qui. Prima ci arrivava attraverso dei
                     radio creati al volo al posto delle caselle: si sceglieva, poi
                     si confermava con un pulsante in fondo allo schermo — due gesti
                     per una cosa sola. Ora si tocca la carta e basta, come in
                     inserimento_big_fc.php, e il valore lo scrive qui il
                     JavaScript. */ ?>
            <input type="hidden" name="father_id" id="hiddenFatherId">



            <?php if (count($images_data) > 0): ?>

            <div class="fc-list" id="fcList">
                <?php 
                $group_counter = 0;
                foreach ($images_data as $row):
                    $group_counter++;
                    $isLearnt = $row['is_learnt'] == 1;
                    $catColor = fc_color_or_default($row['category_color']);
                    $catName = $row['category_name'] ? $row['category_name'] : T('vocab.vi_nessuna');
                    $searchString = sec_h(strtolower($row['question_desc'] . ' ' . $row['answer_desc']));
                    $qk = strtolower(trim($row['question_desc']));
                    $isDuplicate = (isset($global_fc_counts[$qk]) && $global_fc_counts[$qk] > 1);
                    $childCount = count($row['children']);
                    
                    // Pre-nascondi via PHP per evitare rendering lag
                    $hide_style = ($categoryID == 0 && $group_counter > 30) ? 'display: none;' : '';
                ?>
                <div class="fc-group" data-parent-id="<?= (int)$row['id'] ?>" data-category-id="<?= (int)$row['category_id'] ?>" data-reorderable="<?= $parent_reorder_enabled ? '1' : '0' ?>" style="--row-color:<?= sec_h($catColor) ?>; <?= sec_h($hide_style) ?>">



                    <div class="fc-card fc-parent <?= $isLearnt ? 'is-learnt' : '' ?> <?= $isDuplicate ? 'duplicate-flag' : '' ?>"

                         data-id="<?= (int)$row['id'] ?>" data-type="parent"

                         data-search="<?= sec_h($searchString) ?>" data-duplicate="<?= $isDuplicate ? 'true' : 'false' ?>"

                         data-edit-url="modifica_immagine_fc.php?fc_id=<?= (int)$row['id'] ?>&previous_catid=<?= (int)$categoryID ?>"

                         data-child-count="<?= (int)$childCount ?>">



                        <div class="swipe-actions">

                            <button type="button" class="swipe-act act-edit" onclick="goEditCard(this)">

                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>

                                <?php te('comune.modifica'); ?>

                            </button>

                            <button type="button" class="swipe-act act-delete" onclick="askDeleteCard(this)">

                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0-1 14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2L4 6"/></svg>

                                <?php te('comune.elimina'); ?>

                            </button>

                        </div>



                        <div class="swipe-content">

                            <div class="card-head">

                                <?php if ($current_user_id == 3): ?>

                                    <div class="learnt-disabled">–</div>

                                <?php else: ?>

                                    <label class="learnt-wrapper">

                                        <input type="checkbox" class="learnt-cb" <?= $isLearnt ? 'checked' : '' ?> onclick="toggleLearnt(<?= (int)$row['id'] ?>, this)">

                                        <div class="learnt-emoji">🧐</div>

                                        <div class="learnt-check"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></div>

                                    </label>

                                <?php endif; ?>

                                <label class="select-circle">

                                    <input type="checkbox" name="selected_ids[]" value="<?= (int)$row['id'] ?>" class="bulk-checkbox standalone-cb record-cb" onchange="onRecordCbChange(this)">

                                    <span class="cc-visual"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span>

                                </label>

                                <div class="card-head-spacer"></div>

                                <?php if ($isDuplicate): ?><span class="dup-badge"><?php te('fc.vis_duplicato'); ?></span><?php endif; ?>

                                <?php if ($categoryID == 0): ?><span class="cat-badge" style="background:<?= sec_h($catColor) ?>"><?= sec_h($catName) ?></span><?php endif; ?>

                            </div>



                            <?php render_qa_side(T('fc.ifc_domanda'), $row['question_desc'], $row['question_image'], $row['question_audio'], 'q'); ?>

                            <?php render_qa_side(T('fc.ifc_risposta'), $row['answer_desc'], $row['answer_image'], $row['answer_audio'], 'a'); ?>



                            <?php render_examples_section($row['id'], 'parent', $row['id'], $examples_counts); ?>

                            <?php /* GLI ATTREZZI DELLA VISTA COMPATTA
                                     Esistono sempre nel markup ma si vedono solo a
                                     vista compatta accesa: costruirli da JavaScript
                                     avrebbe voluto dire ricostruirli a ogni
                                     accensione, su tutte le carte della pagina.
                                     La freccia apre QUESTA carta a grandezza vera
                                     senza spegnere la vista compatta per tutte le
                                     altre: serve a controllare una carta prima di
                                     spuntarla, non a uscire dalla vista.
                                     Su e giu' salvano subito il nuovo ordine, come
                                     il trascinamento; spariscono da sole dove
                                     riordinare non si puo' (ordinamento diverso da
                                     "Posizione", o "Tutte le materie"). */ ?>
                            <div class="fc-compact-tools" aria-hidden="false">
                                <button type="button" class="ct-btn ct-open" onclick="toggleCardOpen(this)"
                                        aria-label="<?php te('fc.vis_apri_carta'); ?>" title="<?php te('fc.vis_apri_carta'); ?>">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                                </button>
                                <button type="button" class="ct-btn ct-up" onclick="moveCardBy(this, -1)"
                                        aria-label="<?php te('fc.vis_su'); ?>" title="<?php te('fc.vis_su'); ?>">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="m6 15 6-6 6 6"/></svg>
                                </button>
                                <button type="button" class="ct-btn ct-down" onclick="moveCardBy(this, 1)"
                                        aria-label="<?php te('fc.vis_giu'); ?>" title="<?php te('fc.vis_giu'); ?>">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                                </button>
                            </div>

                            <?php /* Il tastino che rivela le sottocarte, e che si vede
                                     solo in modalita' Unisci: li' l'elenco mostra i soli
                                     padri, ma per decidere a chi attaccare una carta
                                     serve poter sbirciare cosa c'e' gia' dentro. Lo
                                     stesso tocco le richiude. */ ?>
                            <?php if ($childCount > 0): ?>
                            <button type="button" class="fc-kids-toggle" onclick="toggleKids(this)">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                                <span><?php echo sec_h(T('fc.vis_n_sottocarte', ['n' => (int)$childCount])); ?></span>
                            </button>
                            <?php endif; ?>

                        </div>

                    </div>



                    <?php if ($childCount > 0): ?>

                    <div class="fc-children" data-parent-id="<?= (int)$row['id'] ?>">

                        <?php foreach ($row['children'] as $child):

                            $searchChild = sec_h(strtolower($child['question_desc'] . ' ' . $child['answer_desc']));

                        ?>

                        <div class="fc-card fc-child" data-id="<?= (int)$child['id'] ?>" data-type="child" data-parent-id="<?= (int)$row['id'] ?>"

                             data-search="<?= sec_h($searchChild) ?>" data-duplicate="false" data-reorderable="<?= $child_reorder_enabled ? '1' : '0' ?>"

                             data-edit-url="modifica_immagine_fc.php?child_id=<?= (int)$child['id'] ?>&previous_catid=<?= (int)$categoryID ?>">



                            <div class="swipe-actions">

                                <button type="button" class="swipe-act act-edit" onclick="goEditCard(this)">

                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>

                                    <?php te('vocab.cat_modifica_attr'); ?>

                                </button>

                                <button type="button" class="swipe-act act-delete" onclick="askDeleteCard(this)">

                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0-1 14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2L4 6"/></svg>

                                    <?php te('comune.elimina'); ?>

                                </button>

                            </div>



                            <div class="swipe-content">

                                <div class="card-head">

                                    <div class="child-tag"><span>↳</span> <?php te('fc.vis_sotto_carta'); ?></div>

                                    <label class="select-circle">

                                        <input type="checkbox" name="selected_child_ids[]" value="<?= (int)$child['id'] ?>" class="bulk-checkbox child-cb record-cb" onchange="onRecordCbChange(this)">

                                        <span class="cc-visual"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span>

                                    </label>

                                    <div class="card-head-spacer"></div>

                                </div>



                                <?php render_qa_side(T('fc.ifc_domanda'), $child['question_desc'], $child['question_image'], $child['question_audio'], 'q'); ?>

                                <?php render_qa_side(T('fc.ifc_risposta'), $child['answer_desc'], $child['answer_image'], $child['answer_audio'], 'a'); ?>



                                <?php render_examples_section($child['id'], 'child', -1 * intval($child['id']), $examples_counts); ?>

                                <?php /* Gli stessi attrezzi della carta padre: qui su e giu'
                                         riordinano le sottocarte dentro il loro padre. */ ?>
                                <div class="fc-compact-tools">
                                    <button type="button" class="ct-btn ct-open" onclick="toggleCardOpen(this)"
                                            aria-label="<?php te('fc.vis_apri_carta'); ?>" title="<?php te('fc.vis_apri_carta'); ?>">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                                    </button>
                                    <button type="button" class="ct-btn ct-up" onclick="moveCardBy(this, -1)"
                                            aria-label="<?php te('fc.vis_su'); ?>" title="<?php te('fc.vis_su'); ?>">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="m6 15 6-6 6 6"/></svg>
                                    </button>
                                    <button type="button" class="ct-btn ct-down" onclick="moveCardBy(this, 1)"
                                            aria-label="<?php te('fc.vis_giu'); ?>" title="<?php te('fc.vis_giu'); ?>">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                                    </button>
                                </div>

                            </div>

                        </div>

                        <?php endforeach; ?>

                    </div>

                    <?php endif; ?>

                </div>

                <?php endforeach; ?>
            </div>
            
            <?php if ($categoryID == 0): ?>
                <button type="button" id="loadMoreBtn" style="display: none; width: calc(100% - 24px); margin: 0 auto 20px auto; padding: 14px; background: var(--surface); color: var(--blue); border: none; border-radius: var(--r-btn); font-size: 15.5px; font-weight: 800; cursor: pointer; box-shadow: var(--shadow-card), inset 0 0 0 .5px var(--hairline); transition: transform .15s var(--spring); text-align: center;" onclick="loadMoreCards()" onmousedown="this.style.transform='scale(.97)'" onmouseup="this.style.transform='scale(1)'" onmouseleave="this.style.transform='scale(1)'">
                    <?php te('fc.vis_carica_altre'); ?>
                </button>
            <?php endif; ?>

            <?php else: ?>

            <div class="empty-state">

                <div class="es-icon">🗂️</div>

                <h3><?php te('fc.vis_nessuna_carta'); ?></h3>

                <p><?php te('fc.vis_nessuna_carta_txt'); ?></p>

            </div>

            <?php endif; ?>

        </form>

        <div class="fc-deep" id="fcDeep" hidden></div>

    </div>

</main>



<?php /* La barra "Confermo il padre" non c'e' piu': il padre si sceglie
         toccando la sua carta, e un gesto che vale da solo non ha bisogno di
         essere confermato da un secondo gesto in fondo allo schermo. Restano
         le regole di prima su chi puo' diventare padre — le controlla
         onBulkActionPick prima di far partire la scelta. */ ?>



<div class="sheet-overlay" id="deleteSheet">

    <div class="sheet-panel">

        <h3 id="deleteSheetTitle"><?php te('fc.vis_eliminare_carta'); ?></h3>

        <p class="sheet-sub" id="deleteSheetSub"><?php te('fc.vis_non_annullabile'); ?></p>

        <div class="sheet-warn" id="deleteSheetWarn"></div>

        <div class="sheet-actions">

            <button type="button" class="sheet-btn" onclick="closeSheet('deleteSheet')"><?php te('comune.annulla'); ?></button>

            <button type="button" class="sheet-btn danger" id="deleteSheetConfirm"><?php te('comune.elimina'); ?></button>

        </div>

    </div>

</div>



<?php /* Lo spostamento usa ora category_selector.php invece di un elenco
         piatto: le materie sono un albero con sottocategorie, e un elenco
         piatto le mostrava tutte allo stesso livello, senza ricerca e senza
         far capire dove si stesse spostando. Il selettore parte gia' aperto
         sulla categoria in cui ci si trova, che e' quasi sempre il punto da
         cui si vuole spostare di poco. */
       $cs_id = 'move';
       $cs_selected_id = (int)$categoryID;
       $cs_selected_name = $current_cat_name ?? '';
       $cs_title = t('fc.vis_sposta_dove');
       $cs_placeholder = t('fc.vis_sposta_dove');
       $cs_hidden_name = 'cs_move_target';
       $cs_render_trigger = false;
       $cs_allow_create = true;
       include 'category_selector.php';
       unset($cs_id, $cs_selected_id, $cs_selected_name, $cs_title,
             $cs_placeholder, $cs_hidden_name, $cs_render_trigger, $cs_allow_create); ?>




<!-- ============ IMPOSTAZIONI DELLA VISTA ============ -->
<div class="sheet-overlay" id="settingsSheet">
    <div class="sheet-panel">
        <h3><?php te('fc.vis_impostazioni'); ?></h3>
        <p class="sheet-sub"><?php te('fc.vis_impostazioni_txt'); ?></p>

        <div class="opt-group">
            <div class="opt-group-label"><?php te('fc.vis_quali_carte'); ?></div>
            <div class="opt-row">
                <div class="opt-txt">
                    <div class="opt-title"><?php te('fc.vis_filtro'); ?></div>
                </div>
                <div class="opt-select-wrap">
                    <select id="filterStatus" class="opt-select" onchange="fcSalvaImpostazioni(); visibleLimit=30; filterList();">
                        <option value="all"><?php te('fc.vis_tutte_carte'); ?></option>
                        <option value="not_learnt"><?php te('vocab.vi_da_imparare'); ?></option>
                        <option value="learnt"><?php te('vocab.vi_imparate'); ?></option>
                        <option value="duplicates"><?php te('vocab.vi_duplicati'); ?></option>
                    </select>
                </div>
            </div>
        </div>

        <div class="opt-group">
            <div class="opt-group-label"><?php te('fc.vis_come_mostrarle'); ?></div>
            <div class="opt-row">
                <div class="opt-txt">
                    <div class="opt-title"><?php te('fc.vis_carte_intere'); ?></div>
                    <div class="opt-sub"><?php te('fc.vis_carte_intere_txt'); ?></div>
                </div>
                <label class="opt-switch">
                    <input type="checkbox" id="optFullCards" onchange="fcSalvaImpostazioni()">
                    <span class="opt-track"><span class="opt-knob"></span></span>
                </label>
            </div>
            <div class="opt-row">
                <div class="opt-txt">
                    <div class="opt-title"><?php te('fc.vis_esempi_interi'); ?></div>
                    <div class="opt-sub"><?php te('fc.vis_esempi_interi_txt'); ?></div>
                </div>
                <label class="opt-switch">
                    <input type="checkbox" id="optFullExamples" onchange="fcSalvaImpostazioni()">
                    <span class="opt-track"><span class="opt-knob"></span></span>
                </label>
            </div>
        </div>

        <div class="sheet-actions">
            <button type="button" class="sheet-btn" onclick="closeSheet('settingsSheet')"><?php te('comune.chiudi'); ?></button>
        </div>
    </div>
</div>

<!-- ============ COSA VUOI AGGIUNGERE? ============ -->
<div class="sheet-overlay" id="addSheet">
    <div class="sheet-panel">
        <h3><?php te('fc.vis_cosa_aggiungere'); ?></h3>
        <p class="sheet-sub"><?php te('fc.vis_cosa_aggiungere_txt'); ?></p>
        <div class="pick-grid">
            <button type="button" class="pick-tile" onclick="fcAggiungiCarta()">
                <span class="pt-ic">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="7" width="13" height="10" rx="2.2"/><path d="M7.5 7V5a2 2 0 0 1 2-2h7a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2h-2"/></svg>
                </span>
                <span class="pt-lab"><?php te('fc.vis_una_carta'); ?></span>
                <span class="pt-sub"><?php te('fc.vis_una_carta_txt'); ?></span>
            </button>
            <button type="button" class="pick-tile" onclick="fcApriNuovaCartella()">
                <span class="pt-ic">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z"/><path d="M12 11v5M9.5 13.5h5"/></svg>
                </span>
                <span class="pt-lab"><?php te('fc.vis_una_cartella'); ?></span>
                <span class="pt-sub"><?php te('fc.vis_una_cartella_txt'); ?></span>
            </button>
        </div>
        <div class="sheet-actions">
            <button type="button" class="sheet-btn" onclick="closeSheet('addSheet')"><?php te('comune.annulla'); ?></button>
        </div>
    </div>
</div>

<!-- ============ NUOVA CARTELLA ============ -->
<div class="sheet-overlay" id="newFolderSheet">
    <div class="sheet-panel">
        <h3><?php te('fc.vis_nuova_cartella'); ?></h3>
        <p class="sheet-sub" id="newFolderSub"></p>

        <input type="text" id="newFolderName" class="nf-input" maxlength="120"
               placeholder="<?php te('fc.vis_nome_cartella'); ?>" autocomplete="off">

        <div class="nf-color-head">
            <span class="nf-color-dot" id="newFolderDot"></span>
            <span class="nf-color-name" id="newFolderColorName"></span>
        </div>

        <div class="nf-grid" id="newFolderGrid">
            <?php /* Prima opzione sempre il colore ereditato dal padre: e' il
                     comportamento normale delle categorie, quello scelto a mano
                     e' l'eccezione. */ ?>
            <button type="button" class="nf-sw nf-sw-auto selected" data-auto="1" onclick="fcPickerAuto('cartella')"
                    title="<?php te('fc.vis_colore_default'); ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 9-9"/><path d="M3 4v8h8"/></svg>
            </button>
            <?php foreach ($colorPalette as $__ci => $__hex): ?>
            <button type="button" class="nf-sw" style="--sw:<?= sec_h($__hex) ?>;" data-hex="<?= sec_h($__hex) ?>"
                    onclick="fcPickerManuale('cartella', '<?= sec_h($__hex) ?>')">
                <svg class="nf-check" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
            </button>
            <?php endforeach; ?>
        </div>

        <div class="nf-custom">
            <input type="color" id="newFolderCustom" value="#007AFF" oninput="fcPickerManuale('cartella', this.value)">
            <span class="nf-custom-lab"><?php te('vocab.cat_colore_pers'); ?></span>
            <span class="nf-custom-hex" id="newFolderHex">#A0A0A0</span>
        </div>

        <div class="sheet-actions">
            <button type="button" class="sheet-btn" onclick="closeSheet('newFolderSheet')"><?php te('comune.annulla'); ?></button>
            <button type="button" class="sheet-btn primary" id="newFolderSave" onclick="fcCreaCartella()"><?php te('comune.salva'); ?></button>
        </div>
    </div>
</div>



<!-- ============ COLORE DELLA CATEGORIA ============ -->
<div class="sheet-overlay" id="catColorSheet">
    <div class="sheet-panel">
        <h3><?php te('fc.vis_colore_categoria'); ?></h3>
        <p class="sheet-sub"><?php te('fc.vis_colore_categoria_txt'); ?></p>

        <div class="nf-color-head">
            <span class="nf-color-dot" id="catColorDot"></span>
            <span class="nf-color-name" id="catColorName"></span>
        </div>

        <div class="nf-grid" id="catColorGrid">
            <button type="button" class="nf-sw nf-sw-auto selected" data-auto="1" onclick="fcPickerAuto('categoria')"
                    title="<?php te('fc.vis_colore_default'); ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 9-9"/><path d="M3 4v8h8"/></svg>
            </button>
            <?php foreach ($colorPalette as $__ci2 => $__hex2): ?>
            <button type="button" class="nf-sw" style="--sw:<?= sec_h($__hex2) ?>;" data-hex="<?= sec_h($__hex2) ?>"
                    onclick="fcPickerManuale('categoria', '<?= sec_h($__hex2) ?>')">
                <svg class="nf-check" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
            </button>
            <?php endforeach; ?>
        </div>

        <div class="nf-custom">
            <input type="color" id="catColorCustom" value="#007AFF" oninput="fcPickerManuale('categoria', this.value)">
            <span class="nf-custom-lab"><?php te('vocab.cat_colore_pers'); ?></span>
            <span class="nf-custom-hex" id="catColorHex">#A0A0A0</span>
        </div>

        <div class="sheet-actions">
            <button type="button" class="sheet-btn" onclick="closeSheet('catColorSheet')"><?php te('comune.annulla'); ?></button>
            <button type="button" class="sheet-btn primary" id="catColorSave" onclick="fcSalvaColoreCategoria()"><?php te('comune.salva'); ?></button>
        </div>
    </div>
</div>

<!-- ============ RIEPILOGO DEL PROGETTO ============ -->
<div class="sheet-overlay" id="projectSheet">
    <div class="sheet-panel prj-panel">
        <div class="prj-body" id="prjBody"></div>
        <div class="sheet-actions">
            <button type="button" class="sheet-btn" onclick="closeSheet('projectSheet')"><?php te('comune.chiudi'); ?></button>
            <a class="sheet-btn primary prj-go" id="prjGo" href="progetti.php"><?php te('fc.vis_prj_apri'); ?></a>
        </div>
    </div>
</div>



<!-- ============ VISTA A FLASHCARD (una carta per volta) ============ -->
<button type="button" class="fcv-fab" id="fcvFab" onclick="fcvApri()"
        aria-label="<?php te('fc.vis_fcv_apri'); ?>" title="<?php te('fc.vis_fcv_apri'); ?>">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="7" width="14" height="13" rx="3"/><path d="M8.5 4H19a2 2 0 0 1 2 2v10.5"/></svg>
</button>

<div id="fcv" class="fcv" aria-hidden="true">
    <div class="fcv-back" id="fcvBack"></div>

    <header class="fcv-top">
        <button type="button" id="fcvClose" class="fcv-tb" aria-label="<?php te('comune.chiudi'); ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
        </button>
        <div class="fcv-count"><span id="fcvIdx">1</span> / <span id="fcvTot">1</span></div>
        <?php if ($categoryID != 0): ?>
        <a class="fcv-tb fcv-play" href="categories_game_fc.php?preselect_category=<?= (int)$categoryID ?>"
           aria-label="<?php te('vocab.vi_gioca'); ?>" title="<?php te('vocab.vi_gioca'); ?>">
            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M8 5.14v13.72a1 1 0 0 0 1.53.85l11-6.86a1 1 0 0 0 0-1.7l-11-6.86A1 1 0 0 0 8 5.14Z"/></svg>
        </a>
        <?php else: ?>
        <span class="fcv-tb fcv-ghost" aria-hidden="true"></span>
        <?php endif; ?>
    </header>

    <div class="fcv-progress"><span id="fcvBar"></span></div>
    <?php /* Seconda barra, solo per le sotto-carte: dice a che punto si e'
             fra i FRATELLI, non nell'intero mazzo. Sui padri non sparisce, si
             limita a diventare invisibile (classe is-off): tolta dal flusso
             farebbe scendere e risalire le carte a ogni cambio. */ ?>
    <div class="fcv-progress fcv-progress-sib is-off" id="fcvBarSibWrap"><span id="fcvBarSib"></span></div>

    <div class="fcv-stage" id="fcvStage">
        <div class="fcv-card" id="fcvCard">
            <div class="fcv-inner" id="fcvInner">
                <div class="fcv-face fcv-front">
                    <?php /* Le prime righe della domanda del padre: uguale per
                             tutti i fratelli, fa da titolo del gruppo e riporta
                             alla carta padre. Nascosta sui padri. */ ?>
                    <button type="button" class="fcv-parent-strip" id="fcvParent" hidden>
                        <span class="fcv-parent-lab">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
                            <?php te('fc.vis_fcv_carta_padre'); ?>
                        </span>
                        <span class="fcv-parent-txt" id="fcvParentTxt"></span>
                    </button>
                    <div class="fcv-eyebrow"><?php te('fc.ifc_domanda'); ?></div>
                    <div class="fcv-body" id="fcvQ"></div>
                    <div class="fcv-hint"><?php te('fc.res_tocca_girare'); ?></div>
                </div>
                <div class="fcv-face fcv-backface">
                    <?php /* Stessa fascia della domanda, sul retro: prima compariva
                             solo davanti, e girando la carta il contesto "di chi e'
                             figlia" spariva — proprio quando serve di piu', leggendo
                             una risposta senza piu' la domanda sotto gli occhi. */ ?>
                    <button type="button" class="fcv-parent-strip" id="fcvParentA" hidden>
                        <span class="fcv-parent-lab">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
                            <?php te('fc.vis_fcv_carta_padre'); ?>
                        </span>
                        <span class="fcv-parent-txt" id="fcvParentTxtA"></span>
                    </button>
                    <div class="fcv-eyebrow ans"><?php te('fc.ifc_risposta'); ?></div>
                    <div class="fcv-body" id="fcvA"></div>
                </div>
            </div>
            <?php /* Fuori da .fcv-inner: cosi' il tastino resta fermo mentre la
                     carta gira, invece di ritrovarsi specchiato sul retro. */ ?>
            <button type="button" class="fcv-edit" id="fcvEdit"
                    aria-label="<?php te('comune.modifica'); ?>" title="<?php te('comune.modifica'); ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
            </button>
        </div>
    </div>

    <div class="fcv-nav">
        <button type="button" id="fcvPrev" class="fcv-nb" aria-label="<?php te('fc.res_precedente'); ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
        </button>
        <button type="button" id="fcvFlip" class="fcv-nb fcv-nb-main" aria-label="<?php te('fc.res_gira'); ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-2.6-6.3"/><path d="M21 3v5h-5"/></svg>
        </button>
        <button type="button" id="fcvNext" class="fcv-nb" aria-label="<?php te('fc.res_successiva'); ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>
        </button>
    </div>
</div>


<!-- ============ ESEMPIO A TUTTO SCHERMO ============ -->
<?php /* Vive fuori dal visore a flashcard e con uno z-index piu' alto: si apre
         sia dall'elenco sia da dentro il visore, e nel secondo caso deve
         starci sopra. */ ?>
<div class="ex-modal" id="exModal" aria-hidden="true">
    <div class="ex-modal-back" id="exModalBack"></div>
    <div class="ex-modal-panel">
        <header class="ex-modal-head">
            <span class="ex-modal-title" id="exModalTitle"></span>
            <button type="button" class="ex-modal-close" id="exModalClose" aria-label="<?php te('comune.chiudi'); ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
            </button>
        </header>
        <div class="ex-modal-body" id="exModalBody"></div>
        <div class="ex-modal-nav" id="exModalNav"></div>
    </div>
</div>

<div id="toast-host"></div>



<script>

const NISAMA_CURRENT_USER_ID = <?php echo (int)$current_user_id; ?>;

/* ================================================================

   UTILITY

   ================================================================ */

function $all(sel, root){ return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

function buzz(ms){ try{ if (navigator.vibrate) navigator.vibrate(ms); }catch(e){} }



function showToast(msg){

    const host = document.getElementById('toast-host');

    if (!host) return;

    const t = document.createElement('div');

    t.className = 'toast';

    t.textContent = msg;

    host.appendChild(t);

    setTimeout(function(){

        t.classList.add('leaving');

        setTimeout(function(){ t.remove(); }, 320);

    }, 2200);

}



function syncHeaderHeight(){

    const h = document.getElementById('appHeader');

    if (h) document.documentElement.style.setProperty('--header-h', h.offsetHeight + 'px');

}

window.addEventListener('resize', syncHeaderHeight);



/* Confetti leggero — usato solo per il momento "Imparato" (tocco dopaminico) */

function miniConfetti(x, y){

    const colors = ['#30D158', '#34C759', '#FFD60A', '#0A84FF', '#ffffff'];

    for (let i = 0; i < 10; i++) {

        const s = document.createElement('div');

        s.style.position = 'fixed'; s.style.left = x + 'px'; s.style.top = y + 'px';

        s.style.width = '6px'; s.style.height = '6px'; s.style.borderRadius = (i % 3 === 0) ? '50%' : '2px';

        s.style.background = colors[i % colors.length]; s.style.zIndex = '9000'; s.style.pointerEvents = 'none';

        document.body.appendChild(s);

        const ang = -Math.PI/2 + (Math.random()-0.5) * Math.PI * 1.3;

        const dist = 40 + Math.random() * 70;

        const dx = Math.cos(ang) * dist;

        const dy = Math.sin(ang) * dist + 60 + Math.random() * 40;

        const rot = (Math.random()-0.5) * 500;

        try {

            const anim = s.animate(

                [{ transform:'translate(-50%,-50%) rotate(0deg)', opacity:1 },

                 { transform:'translate(calc(-50% + '+dx+'px), calc(-50% + '+dy+'px)) rotate('+rot+'deg)', opacity:0 }],

                { duration: 550 + Math.random()*250, easing:'cubic-bezier(.16,.8,.4,1)' }

            );

            anim.onfinish = function(){ s.remove(); };

        } catch(e){ s.remove(); }

    }

}



/* ================================================================

   ZOOM IMMAGINI

   ================================================================ */

var fcZoomStato = null;

function openImageZoom(src){
    var ov = document.getElementById('image-overlay');
    var img = document.getElementById('overlay-img');
    img.src = src;
    /* Si riparte sempre dall'immagine intera: uscendo mentre era ingrandita,
       riaprendone un'altra si erediterebbe lo zoom precedente. */
    img.style.transition = 'none';
    img.style.transform = '';
    ov.style.background = '';
    ov.style.display = 'flex';
    /* La pagina dietro non deve scorrere ne' rispondere ai tocchi: qui si sta
       guardando un'immagine, ogni gesto appartiene a lei. */
    document.body.classList.add('fc-zoom-aperto');
    fcZoomStato = { scala: 1, x: 0, y: 0, distIniziale: 0, scalaIniziale: 1,
                    trascina: false, x0: 0, y0: 0, dy: 0, xPart: 0, yPart: 0 };
}

function closeImageZoom(){
    var ov = document.getElementById('image-overlay');
    var img = document.getElementById('overlay-img');
    ov.style.display = 'none';
    ov.style.background = '';
    img.style.transform = '';
    img.style.transition = '';
    document.body.classList.remove('fc-zoom-aperto');
    fcZoomStato = null;
}

/* ---------- Zoom con due dita, trascinamento verso il basso per chiudere ----------
   Due gesti sullo stesso elemento: con due dita si ingrandisce e si sposta
   l'immagine per guardarla da vicino; con un dito solo, e SOLO quando non e'
   ingrandita, si trascina verso il basso per chiuderla — il gesto che su iOS
   chiude qualunque visore a tutto schermo. A immagine ingrandita il dito
   singolo serve invece a spostarla, altrimenti non si potrebbe esplorarla. */
(function () {
    var ov = null, img = null;

    function elementi() {
        if (!ov) ov = document.getElementById('image-overlay');
        if (!img) img = document.getElementById('overlay-img');
        return !!(ov && img);
    }
    function applica(z) {
        img.style.transform = 'translate(' + z.x + 'px,' + z.y + 'px) scale(' + z.scala + ')';
    }
    function distanza(t) {
        var dx = t[0].clientX - t[1].clientX, dy = t[0].clientY - t[1].clientY;
        return Math.sqrt(dx * dx + dy * dy);
    }
    function dentro(e) {
        return e.target && e.target.closest && e.target.closest('#image-overlay');
    }

    document.addEventListener('touchstart', function (e) {
        if (!fcZoomStato || !elementi() || !dentro(e)) return;
        var z = fcZoomStato;
        img.style.transition = 'none';
        ov.style.transition = 'none';
        if (e.touches.length === 2) {
            z.distIniziale = distanza(e.touches);
            z.scalaIniziale = z.scala;
            z.trascina = false;
        } else if (e.touches.length === 1) {
            z.trascina = true;
            z.x0 = e.touches[0].clientX;
            z.y0 = e.touches[0].clientY;
            z.dy = 0;
            z.xPart = z.x;
            z.yPart = z.y;
        }
    }, { passive: true });

    document.addEventListener('touchmove', function (e) {
        if (!fcZoomStato || !elementi() || !dentro(e)) return;
        var z = fcZoomStato;

        if (e.touches.length === 2 && z.distIniziale > 0) {
            e.preventDefault();
            var f = distanza(e.touches) / z.distIniziale;
            z.scala = Math.min(5, Math.max(1, z.scalaIniziale * f));
            if (z.scala === 1) { z.x = 0; z.y = 0; }
            applica(z);
            return;
        }

        if (e.touches.length === 1 && z.trascina) {
            var dx = e.touches[0].clientX - z.x0;
            var dy = e.touches[0].clientY - z.y0;

            if (z.scala > 1) {
                e.preventDefault();
                z.x = z.xPart + dx;
                z.y = z.yPart + dy;
                applica(z);
                return;
            }
            /* Non ingrandita: conta solo il trascinamento verso il basso, e
               solo se e' piu' verticale che orizzontale — altrimenti un
               movimento obliquo chiuderebbe per sbaglio. */
            if (dy > 0 && dy > Math.abs(dx)) {
                e.preventDefault();
                z.dy = dy;
                img.style.transform = 'translateY(' + dy + 'px) scale(' + Math.max(0.75, 1 - dy / 1000) + ')';
                /* Lo sfondo si schiarisce mentre si trascina: si vede che il
                   gesto sta portando alla chiusura, e si fa in tempo a
                   cambiare idea tornando su. */
                ov.style.background = 'rgba(0,0,0,' + Math.max(0.3, 0.94 - dy / 500) + ')';
            }
        }
    }, { passive: false });

    document.addEventListener('touchend', function () {
        if (!fcZoomStato || !elementi()) return;
        var z = fcZoomStato;
        z.distIniziale = 0;
        if (!z.trascina) return;
        z.trascina = false;
        if (z.scala > 1) return;

        img.style.transition = 'transform .28s cubic-bezier(.32,.72,0,1)';
        ov.style.transition = 'background .28s ease';
        if (z.dy > 110) {
            img.style.transform = 'translateY(' + window.innerHeight + 'px) scale(.7)';
            ov.style.background = 'rgba(0,0,0,0)';
            setTimeout(closeImageZoom, 220);
        } else {
            img.style.transform = '';
            ov.style.background = '';
        }
        z.dy = 0;
    });
})();



/* ================================================================

   PLAYER AUDIO CUSTOM

   ================================================================ */

let currentAudioEl = null;

function fmtTime(s){

    if (!isFinite(s) || s < 0) s = 0;

    const m = Math.floor(s / 60), sec = Math.floor(s % 60);

    return m + ':' + (sec < 10 ? '0' : '') + sec;

}

function toggleAudioPlayer(btn){

    const wrap = btn.closest('.audio-player');

    const audio = wrap.querySelector('audio');

    if (!audio) return;

    if (currentAudioEl && currentAudioEl !== audio) {

        currentAudioEl.pause();

    }

    if (audio.paused) {

        audio.play().catch(function(){});

        currentAudioEl = audio;

    } else {

        audio.pause();

    }

}

function seekAudioPlayer(evt, track){

    const wrap = track.closest('.audio-player');

    const audio = wrap.querySelector('audio');

    if (!audio || !isFinite(audio.duration)) return;

    const rect = track.getBoundingClientRect();

    const p = Math.min(1, Math.max(0, (evt.clientX - rect.left) / rect.width));

    audio.currentTime = p * audio.duration;

}

document.addEventListener('play', function(e){

    if (!e.target.matches) return;

    if (e.target.tagName === 'AUDIO' && e.target.closest('.audio-player')) {

        if (currentAudioEl && currentAudioEl !== e.target) currentAudioEl.pause();

        currentAudioEl = e.target;

    }

}, true);

$all('.audio-player').forEach(function(wrap){

    const audio = wrap.querySelector('audio');

    const fill = wrap.querySelector('.ap-fill');

    const knob = wrap.querySelector('.ap-knob');

    const time = wrap.querySelector('.ap-time');

    const playIc = wrap.querySelector('.ap-ic-play');

    const pauseIc = wrap.querySelector('.ap-ic-pause');

    if (!audio) return;

    audio.addEventListener('timeupdate', function(){

        const d = audio.duration;

        const p = (isFinite(d) && d > 0) ? (audio.currentTime / d) * 100 : 0;

        fill.style.width = p + '%';

        knob.style.left = p + '%';

        time.textContent = fmtTime(isFinite(d) && d > 0 ? (d - audio.currentTime) : audio.currentTime);

    });

    audio.addEventListener('play', function(){ wrap.classList.add('is-playing'); playIc.style.display = 'none'; pauseIc.style.display = ''; });

    audio.addEventListener('pause', function(){ wrap.classList.remove('is-playing'); playIc.style.display = ''; pauseIc.style.display = 'none'; });

    audio.addEventListener('ended', function(){ fill.style.width = '0%'; knob.style.left = '0%'; time.textContent = '0:00'; });

});



/* ================================================================

   TOGGLE "IMPARATO"

   ================================================================ */

function toggleLearnt(id, checkbox){

    const status = checkbox.checked ? 1 : 0;

    const userId = NISAMA_CURRENT_USER_ID;

    const card = checkbox.closest('.fc-card');

    buzz(status ? 10 : 6);

    if (status && card) {

        const r = checkbox.getBoundingClientRect();

        miniConfetti(r.left + r.width/2, r.top + r.height/2);

    }

    if (card) card.classList.toggle('is-learnt', !!status);

    const xhr = new XMLHttpRequest();

    xhr.open("POST", "update_learnt.php", true);

    xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");

    xhr.onreadystatechange = function(){

        if (this.readyState === XMLHttpRequest.DONE && this.status === 200) {

            filterList();

        }

    };

    xhr.send("id=" + id + "&status=" + status + "&user_id=" + userId + "&type=fc");

}



/* ================================================================

   NAVIGAZIONE (ordinamento) — changeUser() è stata rimossa insieme

   al menu a tendina: l'utente non si cambia più, è sempre quello loggato

   ================================================================ */

function changeSort(){

    const sortVal = document.getElementById('filterSort').value;

    const urlParams = new URLSearchParams(window.location.search);

    urlParams.set('sort', sortVal);

    window.location.search = urlParams.toString();

}



/* ================================================================

   FILTRO / RICERCA / CONTATORE

   ================================================================ */

let visibleLimit = 30; // Limite carte visibili per "Tutte le materie"

function loadMoreCards() {
    visibleLimit += 30;
    filterList();
    buzz(10);
}

let displayedCount = 0;
function animateCounter(target){
    const el = document.getElementById('itemCounterNum');
    if (!el) return;

    const start = displayedCount;

    const diff = target - start;

    displayedCount = target;

    if (diff === 0) { el.textContent = target; return; }

    const dur = 260, t0 = performance.now();

    function step(now){

        const p = Math.min(1, (now - t0) / dur);

        const val = Math.round(start + diff * (1 - Math.pow(1 - p, 3)));

        el.textContent = val;

        if (p < 1) requestAnimationFrame(step);

    }

    requestAnimationFrame(step);

}



function filterList(){
    const statusFilter = document.getElementById('filterStatus').value;
    const textFilter = document.getElementById('filterText').value.toLowerCase().trim();
    const selecting = document.body.classList.contains('select-mode');
    const actionFilter = selecting ? (document.body.dataset.bulkAction || '') : '';

    function cardMatches(card){
        if (!card) return false;
        const cb = card.querySelector('.select-circle input');
        if (selecting && cb && cb.checked) return true;
        const isLearnt = card.classList.contains('is-learnt');
        const isDup = card.getAttribute('data-duplicate') === 'true';
        const isChild = card.classList.contains('fc-child');
        const group = card.closest('.fc-group');
        const isFather = card.classList.contains('fc-parent') && group && group.querySelector('.fc-children .fc-card');

        let byStatus = false;
        if (statusFilter === 'all') byStatus = true;
        else if (statusFilter === 'learnt') byStatus = isLearnt;
        else if (statusFilter === 'not_learnt') byStatus = !isLearnt;
        else if (statusFilter === 'duplicates') byStatus = isDup;

        /* SEPARA: hanno senso solo i padri con figli e i figli stessi. Una
           carta che non sta in nessuna famiglia non ha niente da separare, e
           tenerla nell'elenco vuol dire farla scorrere per niente. */
        if (selecting && actionFilter === 'unjoin' && !isFather && !isChild) byStatus = false;

        /* UNISCI: si lavora sui padri. I figli restano nel documento — il
           tastino "n sottocarte" li rivela per far vedere cosa c'e' gia'
           dentro a un padre — ma non sono loro l'oggetto della scelta, e
           l'elenco parte senza. Li mostra il CSS quando il gruppo ha la classe
           kids-open. */
        if (selecting && actionFilter === 'join' && isChild) byStatus = true;

        if (!byStatus) return false;

        const searchText = card.getAttribute('data-search') || '';
        return searchText.indexOf(textFilter) !== -1;
    }

    let visibleCards = 0;
    let matchedGroups = 0;
    const isTutteLeMaterie = <?php echo $categoryID == 0 ? 'true' : 'false'; ?>;

    /* Solo i gruppi dell'elenco locale: quelli dei risultati nelle
       sottocartelle sono gia' filtrati dal server e vivono fuori da #fcList. */
    $all('#fcList > .fc-group').forEach(function(group){
        const parentCard = group.querySelector(':scope > .fc-card.fc-parent');
        const childCards = $all('.fc-children > .fc-card.fc-child', group);

        const parentMatch = cardMatches(parentCard);
        const childMatchFlags = childCards.map(cardMatches);
        const anyChildMatch = childMatchFlags.indexOf(true) !== -1;
        const groupVisible = parentMatch || anyChildMatch;

        if (groupVisible) {
            matchedGroups++;
            if (isTutteLeMaterie && matchedGroups > visibleLimit) {
                group.style.display = 'none';
                return;
            }
            group.style.display = '';
            if (parentCard) { parentCard.style.display = ''; visibleCards++; }
            childCards.forEach(function(c, i){
                const show = childMatchFlags[i];
                c.style.display = show ? '' : 'none';
                if (show) visibleCards++;
            });
        } else {
            group.style.display = 'none';
        }
    });

    animateCounter(visibleCards);
    
    const loadMoreBtn = document.getElementById('loadMoreBtn');
    if (loadMoreBtn) {
        if (isTutteLeMaterie && matchedGroups > visibleLimit) {
            loadMoreBtn.style.display = 'block';
        } else {
            loadMoreBtn.style.display = 'none';
        }
    }

    /* Le cartelle seguono la ricerca come le carte: se il testo non e' nel

       loro nome spariscono, e sparisce anche l'intestazione se non ne resta

       nessuna. Senza filtro tornano tutte. */

    var sezioneSub = document.querySelector('.fc-subcats');

    if (sezioneSub) {

        var restano = 0;

        $all('.fc-subcat-card', sezioneSub).forEach(function(sc){

            var nome = sc.querySelector('.fc-subcat-name');

            var testo = nome ? nome.textContent.toLowerCase() : '';

            var ok = (textFilter === '') || testo.indexOf(textFilter) !== -1;

            sc.style.display = ok ? '' : 'none';

            if (ok) restano++;

        });

        sezioneSub.style.display = restano ? '' : 'none';

    }

    if (window.fcAfterFilter) window.fcAfterFilter();
}

</script>
<script>

/* ================================================================

   RICERCA: EVIDENZIAZIONE + CARTE TROVATE NELLE SOTTOCARTELLE

   In pagina ci sono solo le carte di questa categoria. Quelle che stanno

   nelle cartelle sotto arrivano da visualizzazione_fc.php?fc_deep_search=1,

   gia' come HTML: sono le stesse funzioni di render dell'elenco normale, cosi'

   una modifica alla scheda vale per tutte e due gli elenchi.

   ================================================================ */

var FC_CAT_ID = <?= (int)$categoryID ?>;

var FC_HA_SOTTOCARTELLE = <?= !empty($subcategories) ? 'true' : 'false' ?>;

/* Stessa chiave che legge categories_fc.php: cosi', tornando li' (tasto Home

   o "indietro" del browser), l'albero si ritrova gia' aperto su questa

   categoria, anche se ci si e' arrivati scendendo per sotto-cartelle senza

   mai passare da un clic su una riga di quella pagina. */

if (FC_CAT_ID !== 0) { try { sessionStorage.setItem('nisama_cat_tree_focus', FC_CAT_ID); } catch(e){} }



/* ---------- Evidenziazione ---------- */

/* Si lavora sul testo gia' disegnato, non sul data-raw: il testo grezzo ha

   dentro la formattazione (formule comprese) e infilarci dei tag lo

   romperebbe. Qui invece si spezzano solo nodi di testo. */

/* Il dito e' partito dentro qualcosa che scorre gia' per conto suo (una

   formula larga, un testo che sborda)? Allora quel trascinamento e' suo: ne'

   i tastini a scorrimento della carta ne' la sfogliata del visore devono

   rubarglielo. Si risale fino a un limite, non fino in cima alla pagina. */

function fcScorrevoleOrizz(el, limite){

    while (el && el !== limite && el.nodeType === 1) {

        if (el.scrollWidth - el.clientWidth > 2) {

            var st = getComputedStyle(el);

            if (st.overflowX === 'auto' || st.overflowX === 'scroll') return true;

        }

        el = el.parentElement;

    }

    return false;

}



function fcTogliEvidenze(root){

    var scope = root || document;

    Array.prototype.slice.call(scope.querySelectorAll('mark.fc-hl')).forEach(function(m){

        var p = m.parentNode;

        if (!p) return;

        p.replaceChild(document.createTextNode(m.textContent), m);

        p.normalize();

    });

}



function fcEvidenziaIn(el, termine){

    if (!el || !termine) return;

    var basso = termine.toLowerCase();

    var nodi = [];

    var walker = document.createTreeWalker(el, NodeFilter.SHOW_TEXT, null, false);

    var n;

    while ((n = walker.nextNode())) {

        if (!n.nodeValue || n.nodeValue.toLowerCase().indexOf(basso) === -1) continue;

        var p = n.parentNode;

        if (!p || p.nodeType !== 1) continue;

        /* MathJax ridisegna i propri nodi da capo: metterci dentro un <mark>

           significa vederselo sparire o, peggio, rompere la formula. */

        if (p.closest('mjx-container, .MathJax, script, style, mark.fc-hl')) continue;

        nodi.push(n);

    }

    nodi.forEach(function(node){

        var txt = node.nodeValue, low = txt.toLowerCase();

        var frag = document.createDocumentFragment();

        var i = 0, j;

        while ((j = low.indexOf(basso, i)) !== -1) {

            if (j > i) frag.appendChild(document.createTextNode(txt.slice(i, j)));

            var mk = document.createElement('mark');

            mk.className = 'fc-hl';

            mk.textContent = txt.slice(j, j + basso.length);

            frag.appendChild(mk);

            i = j + basso.length;

        }

        if (i < txt.length) frag.appendChild(document.createTextNode(txt.slice(i)));

        if (node.parentNode) node.parentNode.replaceChild(frag, node);

    });

}



var fcEvidenzaAttiva = '';

function fcApplicaEvidenze(){

    var input = document.getElementById('filterText');

    var termine = input ? input.value.trim() : '';

    /* Ripulire tutto a ogni battuta costerebbe caro su elenchi lunghi: si

       rifa' solo quando il termine cambia davvero. */

    if (termine === fcEvidenzaAttiva) return;

    fcTogliEvidenze(document);

    fcEvidenzaAttiva = termine;

    if (termine.length < 1) return;

    var visibili = $all('.fc-card').filter(function(c){

        if (c.offsetParent === null) return false;

        var g = c.closest('.fc-group');

        return !(g && g.style.display === 'none');

    });

    visibili.forEach(function(card){

        $all('.qa-text, .qa-example-text', card).forEach(function(el){

            fcEvidenziaIn(el, termine);

        });

    });

    /* Anche il nome delle cartelle rimaste: se una resta a video per via del

       testo cercato, si deve vedere dov'e' quel testo. */

    $all('.fc-subcat-card .fc-subcat-name').forEach(function(el){

        if (el.closest('.fc-subcat-card').style.display !== 'none') fcEvidenziaIn(el, termine);

    });

}



/* ---------- Ricerca nelle sottocartelle ---------- */

var fcDeepTimer = null, fcDeepCtl = null, fcDeepUltimo = null;



function fcOnSearchInput(){

    visibleLimit = 30;

    filterList();

    fcDeepProgramma();

}



function fcDeepProgramma(){

    if (fcDeepTimer) clearTimeout(fcDeepTimer);

    fcDeepTimer = setTimeout(fcDeepEsegui, 320);

}



function fcDeepEsegui(){

    var wrap = document.getElementById('fcDeep');

    var input = document.getElementById('filterText');

    if (!wrap || !input) return;

    var termine = input.value.trim();

    if (termine === fcDeepUltimo) return;

    fcDeepUltimo = termine;



    if (fcDeepCtl) { try { fcDeepCtl.abort(); } catch(e){} fcDeepCtl = null; }



    if (!FC_HA_SOTTOCARTELLE || FC_CAT_ID === 0 || termine.length < 2) {

        wrap.innerHTML = ''; wrap.hidden = true;

        return;

    }



    wrap.hidden = false;

    wrap.innerHTML = '<div class="fc-deep-loading">' + fcEscapeHtml(T('fc.vis_cerco_sottocartelle')) + '</div>';



    var opt = {};

    if (window.AbortController) { fcDeepCtl = new AbortController(); opt.signal = fcDeepCtl.signal; }



    fetch('visualizzazione_fc.php?fc_deep_search=1&category_id=' + FC_CAT_ID + '&q=' + encodeURIComponent(termine), opt)

        .then(function(r){ return r.json(); })

        .then(function(data){

            /* Risposta di una battuta gia' superata: si butta. */

            if (input.value.trim() !== termine) return;

            if (!data || !data.success || !data.total) {

                wrap.innerHTML = ''; wrap.hidden = true;

                return;

            }

            wrap.innerHTML = '<div class="fc-deep-label">'

                + '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z"/></svg>'

                + fcEscapeHtml(T('fc.vis_anche_sottocartelle', { n: data.total }))

                + '</div>' + data.html;

            if (typeof renderAllRawTexts === 'function') renderAllRawTexts(wrap);

            if (typeof fcLoadExampleBoxes === 'function') fcLoadExampleBoxes(wrap);

            fcEvidenzaAttiva = '';   /* la roba nuova non e' ancora evidenziata */

            fcApplicaEvidenze();

        })

        .catch(function(err){

            if (err && err.name === 'AbortError') return;

            wrap.innerHTML = ''; wrap.hidden = true;

        });

}



/* Chiude/riapre una cartella dei risultati: il tocco sta sulla riga del

   percorso, come chiesto. */

function fcDeepToggle(btn){

    var g = btn.closest('.fc-deep-group');

    if (!g) return;

    var chiuso = g.classList.toggle('collapsed');

    btn.setAttribute('aria-expanded', chiuso ? 'false' : 'true');

    buzz(6);

}



/* Chiamata in fondo a filterList(): tiene allineate evidenziazione e

   ricerca profonda a ogni cambio di filtro. */

window.fcAfterFilter = function(){

    /* In selezione i risultati delle sottocartelle spariscono: non fanno parte

       del modulo delle azioni di massa, tenerli sotto gli occhi mentre si

       spunta farebbe credere che vengano toccati anche loro. */

    var wrap = document.getElementById('fcDeep');

    if (wrap) wrap.style.display = document.body.classList.contains('select-mode') ? 'none' : '';

    fcApplicaEvidenze();

};



/* ---------- Ritorno da modifica_immagine_fc: si riparte dalla carta ----------

   Il salvataggio torna qui con fc_focus / fc_focus_child nell'indirizzo:

   senza, il browser ricomincia da capo e in un elenco lungo si perde il

   punto in cui si stava lavorando. */

function fcVaiAllaCartaDelFocus(){

    var p = new URLSearchParams(window.location.search);

    var idPadre = parseInt(p.get('fc_focus') || '0', 10);

    var idFiglio = parseInt(p.get('fc_focus_child') || '0', 10);

    if (!idPadre && !idFiglio) return;



    var sel = idPadre

        ? '.fc-card[data-type="parent"][data-id="' + idPadre + '"]'

        : '.fc-card[data-type="child"][data-id="' + idFiglio + '"]';

    var card = document.querySelector(sel);

    if (!card) return;



    /* Ci si e' appena tornati da una modifica: la carta deve comparire a

       prescindere da un filtro o da una ricerca rimasti impostati da prima

       (sono preferenze della vista, non un giudizio su questa carta in

       particolare). Si azzerano solo se davvero stanno nascondendo qualcosa,

       cosi' non si rifa' il filtro per niente quando non serve. */

    var selStatus = document.getElementById('filterStatus');

    var inputSearch = document.getElementById('filterText');

    var toccato = false;

    if (selStatus && selStatus.value !== 'all') { selStatus.value = 'all'; toccato = true; }

    if (inputSearch && inputSearch.value !== '') { inputSearch.value = ''; toccato = true; }

    if (toccato) { fcEvidenzaAttiva = ''; filterList(); }



    /* In "tutte le categorie" l'elenco e' tagliato a 30 alla volta: se la

       carta e' oltre il taglio si alza il limite finche' non compare. */

    var guardia = 0;

    var gruppo = card.closest('.fc-group');

    while (gruppo && gruppo.style.display === 'none' && guardia < 80) {

        visibleLimit += 30;

        filterList();

        guardia++;

    }

    if (gruppo && gruppo.style.display === 'none') return;



    function porta(){

        var h = document.getElementById('appHeader');

        var alto = h ? h.offsetHeight + 12 : 80;

        var y = card.getBoundingClientRect().top + window.pageYOffset - alto;

        window.scrollTo(0, Math.max(0, y));

    }

    porta();

    /* Le immagini arrivano dopo e spostano tutto: si ripassa due volte. */

    setTimeout(porta, 160);

    setTimeout(porta, 520);



    card.classList.add('fc-focus-flash');

    setTimeout(function(){ card.classList.remove('fc-focus-flash'); }, 2400);



    /* Via il parametro dall'indirizzo: un aggiornamento della pagina non deve

       rifare il salto e il lampeggio. */

    try {

        p.delete('fc_focus'); p.delete('fc_focus_child');

        var q = p.toString();

        history.replaceState(null, '', window.location.pathname + (q ? '?' + q : ''));

    } catch(e){}

}
</script>

<script>

/* ================================================================

   IMPOSTAZIONI DELLA VISTA + TASTO "+" A DUE STRADE

   Il filtro (tutte / da imparare / imparate / duplicati) non e' piu' una

   tendina nella barra ma sta nelle impostazioni, insieme a quanto in lungo

   mostrare carte ed esempi. Le scelte restano nel browser: sono preferenze di

   come si guarda l'elenco, non dati da tenere sul server.

   ================================================================ */

var FC_PREFS_KEY = 'nisama_fc_vis_prefs';

var FC_COLORE_PADRE = '<?= sec_h(fc_color_or_default($current_cat_color)) ?>';

/* json_encode e non sec_h: qui il nome finisce dentro una stringa JavaScript,

   non nel testo della pagina, e le opzioni HEX chiudono la strada anche a un

   nome che contenesse una chiusura di script. */

var FC_CAT_NOME = <?= json_encode($current_cat_name, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

/* Il colore che si erediterebbe dal padre e quello attuale: servono al popup

   del colore per sapere quale casella nasce selezionata. */

var FC_COLORE_PADRE_CAT = '<?= sec_h(fc_color_or_default($fc_parent_color)) ?>';

var FC_COLORE_CORRENTE = '<?= sec_h(fc_color_or_default($current_cat_color)) ?>';

var FC_COLORE_EDITED = <?= $fc_color_edited ? 'true' : 'false' ?>;



function fcLeggiPrefs(){

    try {

        var raw = localStorage.getItem(FC_PREFS_KEY);

        return raw ? (JSON.parse(raw) || {}) : {};

    } catch(e){ return {}; }

}



function fcApplicaPrefs(p){

    document.body.classList.toggle('fc-full-cards', !!p.fullCards);

    document.body.classList.toggle('fc-full-examples', !!p.fullExamples);

    var btn = document.getElementById('settingsBtn');

    /* Pallino sul tasto quando c'e' qualcosa di diverso dal normale:

       altrimenti un filtro attivo diventa invisibile e sembra che manchino

       delle carte. */

    if (btn) btn.classList.toggle('has-dot', !!(p.fullCards || p.fullExamples || (p.status && p.status !== 'all')));

}



function fcCaricaImpostazioni(){

    var p = fcLeggiPrefs();

    var sel = document.getElementById('filterStatus');

    if (sel && p.status) {

        /* Se il valore salvato non esiste piu' fra le opzioni si torna a

           "tutte", invece di lasciare la tendina su niente. */

        sel.value = p.status;

        if (!sel.value) sel.value = 'all';

    }

    var c = document.getElementById('optFullCards');

    var e = document.getElementById('optFullExamples');

    if (c) c.checked = !!p.fullCards;

    if (e) e.checked = !!p.fullExamples;

    fcApplicaPrefs(p);

}



function fcSalvaImpostazioni(){

    var sel = document.getElementById('filterStatus');

    var c = document.getElementById('optFullCards');

    var e = document.getElementById('optFullExamples');

    var p = {

        status: sel ? sel.value : 'all',

        fullCards: !!(c && c.checked),

        fullExamples: !!(e && e.checked)

    };

    try { localStorage.setItem(FC_PREFS_KEY, JSON.stringify(p)); } catch(err){}

    fcApplicaPrefs(p);

}



function fcApriImpostazioni(){

    document.getElementById('settingsSheet').style.display = 'flex';

    buzz(8);

}



/* ---------- Tasto "+": carta o cartella ---------- */

function fcApriAggiungi(){

    document.getElementById('addSheet').style.display = 'flex';

    buzz(8);

}




/* Ricarica riportandosi sulla carta appena creata o modificata. Prima ci
   pensava il redirect del server, che tornava con fc_focus/fc_focus_child
   nell'indirizzo; ora che il modulo vive in un popup quel redirect non
   avviene piu', quindi il parametro lo rimettiamo qui. Senza, si tornava
   sempre in cima all'elenco. */
function fcRicaricaSuCarta(dati){
    var carta = dati && dati.carta;
    if (!carta || !carta.id) { window.location.reload(); return; }
    var u = new URL(window.location.href);
    u.searchParams.delete('fc_focus');
    u.searchParams.delete('fc_focus_child');
    u.searchParams.set(carta.tipo === 'child' ? 'fc_focus_child' : 'fc_focus', carta.id);
    window.location.href = u.toString();
}

function fcAggiungiCarta(){

    /* Apre il modulo di inserimento nello stesso popup usato per la modifica:
       e' la stessa pagina (modifica_immagine_fc.php) in modalita' insert.
       Cosi' non si lascia l'elenco per aggiungere una carta, e dopo averla
       creata si puo' restare li' e inserirne un'altra. Al ritorno si ricarica
       solo se qualcosa e' stato davvero creato: chi apre e chiude senza
       salvare ritrova l'elenco esattamente dov'era, senza uno sfarfallio. */
    var url = 'modifica_immagine_fc.php?modo=insert&category_id=' + FC_CAT_ID;
    if (typeof apriModificaPopup === 'function') {
        apriModificaPopup(url, fcRicaricaSuCarta);
    } else {
        window.location.href = url;
    }

}



/* ---------- Nuova cartella (categoria figlia di questa) ---------- */

function fcApriNuovaCartella(){

    closeSheet('addSheet');

    /* Il selettore di colore e' quello condiviso con il popup del colore

       della categoria: qui si apre sulla scelta ereditata. */

    fcPickerApri('cartella', FC_COLORE_PADRE, false, '');

    document.getElementById('newFolderName').value = '';

    document.getElementById('newFolderSub').textContent = FC_CAT_ID === 0

        ? T('fc.vis_cartella_radice')

        : T('fc.vis_cartella_dentro', { nome: FC_CAT_NOME });

    document.getElementById('newFolderSheet').style.display = 'flex';

    setTimeout(function(){

        var i = document.getElementById('newFolderName');

        if (i) i.focus();

    }, 320);

}



function fcCreaCartella(){

    var nome = (document.getElementById('newFolderName').value || '').trim();

    if (!nome) {

        showToast(T('fc.vis_nome_mancante'));

        document.getElementById('newFolderName').focus();

        return;

    }

    var btn = document.getElementById('newFolderSave');

    btn.disabled = true;



    var fd = new FormData();

    fd.append('action', 'fc_create_subcat');

    fd.append('name', nome);

    fd.append('parent_id', FC_CAT_ID);

    fd.append('color_mode', fcPicker.cartella.manuale ? 'manual' : 'auto');

    fd.append('color', fcPicker.cartella.manuale ? fcPicker.cartella.colore : '');



    fetch('visualizzazione_fc.php', { method: 'POST', body: fd })

        .then(function(r){ return r.json(); })

        .then(function(data){

            btn.disabled = false;

            if (data && data.success) {

                closeSheet('newFolderSheet');

                showToast(T('fc.vis_cartella_creata', { nome: data.name }));

                /* Ricarico: la griglia delle sottocartelle e i conteggi

                   nascono lato server, rifarli a mano qui vorrebbe dire

                   scrivere due volte la stessa cosa. */

                setTimeout(function(){ window.location.reload(); }, 450);

            } else {

                showToast((data && data.error) ? data.error : T('fc.vis_creazione_fallita'));

            }

        })

        .catch(function(){

            btn.disabled = false;

            showToast(T('comune.errore_rete'));

        });

}



document.getElementById('settingsSheet').addEventListener('click', function(e){ if (e.target === this) closeSheet('settingsSheet'); });

document.getElementById('addSheet').addEventListener('click', function(e){ if (e.target === this) closeSheet('addSheet'); });

document.getElementById('newFolderSheet').addEventListener('click', function(e){ if (e.target === this) closeSheet('newFolderSheet'); });

document.getElementById('newFolderName').addEventListener('keydown', function(e){ if (e.key === 'Enter') { e.preventDefault(); fcCreaCartella(); } });

</script>

<script>

/* ================================================================

   BARRA IN ALTO: NOME MODIFICABILE, COLORE, RIEPILOGO DEL PROGETTO

   La pastiglia non porta piu' via dalla pagina. Il ritorno alle categorie

   resta la casetta nel percorso, qui sotto: da li' non si tocca niente per

   sbaglio, mentre un tocco sulla pastiglia larga capitava di continuo.

   ================================================================ */



/* ---------- Selettore di colore condiviso ----------

   Due popup (nuova cartella e colore della categoria) hanno la stessa

   griglia e la stessa prima casella "colore di default": tenerne due copie

   voleva dire vederle divergere alla prima modifica. Cambia solo dove il

   risultato viene poi letto. */

var fcPicker = {

    cartella:  { grid:'newFolderGrid', dot:'newFolderDot', nome:'newFolderColorName',

                 hex:'newFolderHex', custom:'newFolderCustom',

                 ereditato:'#A0A0A0', manuale:false, colore:'' },

    categoria: { grid:'catColorGrid', dot:'catColorDot', nome:'catColorName',

                 hex:'catColorHex', custom:'catColorCustom',

                 ereditato:'#A0A0A0', manuale:false, colore:'' }

};



function fcPickerApri(k, ereditato, manuale, colore){

    var s = fcPicker[k];

    s.ereditato = ereditato || '#A0A0A0';

    s.manuale = !!manuale;

    s.colore = s.manuale ? (colore || s.ereditato) : '';

    fcPickerDisegna(k);

}

function fcPickerAuto(k){ var s = fcPicker[k]; s.manuale = false; s.colore = ''; fcPickerDisegna(k); }

function fcPickerManuale(k, hex){ var s = fcPicker[k]; s.manuale = true; s.colore = hex; fcPickerDisegna(k); }

function fcPickerValore(k){ var s = fcPicker[k]; return s.manuale ? s.colore : s.ereditato; }



function fcPickerDisegna(k){

    var s = fcPicker[k];

    var mostrato = fcPickerValore(k);

    var el;

    if ((el = document.getElementById(s.dot))) el.style.background = mostrato;

    if ((el = document.getElementById(s.nome))) el.textContent = s.manuale ? T('vocab.cat_colore_pers') : T('fc.vis_colore_default');

    if ((el = document.getElementById(s.hex))) el.textContent = String(mostrato).toUpperCase();

    if ((el = document.getElementById(s.custom))) el.value = mostrato;

    $all('#' + s.grid + ' .nf-sw').forEach(function(sw){

        if (sw.dataset.auto) sw.classList.toggle('selected', !s.manuale);

        else sw.classList.toggle('selected', s.manuale && (sw.dataset.hex || '').toLowerCase() === (s.colore || '').toLowerCase());

    });

}



/* ---------- Tocco sulla pastiglia ---------- */

function fcToccaPastiglia(e){

    /* Il nome e la pastiglia del progetto hanno un loro comando: qui si

       raccoglie solo il tocco sul resto, il "niente". */

    if (e.target.closest && e.target.closest('.cat-pill-name, .cat-pill-name-input, .cat-proj-chip')) return;

    if (FC_CAT_ID === 0) return;

    fcApriColoreCategoria();

}



function fcApriColoreCategoria(){

    fcPickerApri('categoria', FC_COLORE_PADRE_CAT, FC_COLORE_EDITED, FC_COLORE_CORRENTE);

    document.getElementById('catColorSheet').style.display = 'flex';

    buzz(8);

}



function fcSalvaColoreCategoria(){

    var btn = document.getElementById('catColorSave');

    var s = fcPicker.categoria;

    btn.disabled = true;



    var fd = new FormData();

    fd.append('action', 'fc_recolor_cat');

    fd.append('category_id', FC_CAT_ID);

    fd.append('color_mode', s.manuale ? 'manual' : 'auto');

    fd.append('color', s.manuale ? s.colore : '');



    fetch('visualizzazione_fc.php', { method: 'POST', body: fd })

        .then(function(r){ return r.json(); })

        .then(function(data){

            btn.disabled = false;

            if (!data || !data.success) {

                showToast((data && data.error) ? data.error : T('comune.errore_rete'));

                return;

            }

            closeSheet('catColorSheet');

            /* Colpo d'occhio immediato: --cat-color tinge mezza pagina. */

            document.documentElement.style.setProperty('--cat-color', data.color);

            FC_COLORE_CORRENTE = data.color;

            FC_COLORE_EDITED = (data.edited === 1);

            showToast(T('fc.vis_colore_aggiornato'));

            /* Se ci sono cartelle sotto, il colore puo' essere sceso anche su

               di loro: i loro riquadri li disegna il server, quindi si

               ricarica invece di indovinare quali sono cambiate. */

            if (FC_HA_SOTTOCARTELLE) setTimeout(function(){ window.location.reload(); }, 700);

        })

        .catch(function(){ btn.disabled = false; showToast(T('comune.errore_rete')); });

}



/* ---------- Nome della categoria modificabile sul posto ---------- */

function fcApriRinomina(e){

    if (e) e.stopPropagation();

    if (FC_CAT_ID === 0) return;

    var nome = document.getElementById('catPillName');

    var input = document.getElementById('catPillNameInput');

    if (!nome || !input) return;

    input.value = nome.textContent.trim();

    nome.style.display = 'none';

    input.style.display = 'block';

    input.focus();

    input.select();

}



function fcChiudiRinomina(salva){

    var nome = document.getElementById('catPillName');

    var input = document.getElementById('catPillNameInput');

    if (!nome || !input || input.style.display === 'none') return;

    var nuovo = input.value.trim();

    var vecchio = nome.textContent.trim();

    input.style.display = 'none';

    nome.style.display = '';

    if (!salva || nuovo === '' || nuovo === vecchio) return;



    /* Si scrive subito a schermo e poi si chiede al server: se rifiuta si

       torna indietro. Aspettare la risposta per far comparire il nome nuovo

       fa sembrare la pagina lenta su una cosa che non lo e'. */

    nome.textContent = nuovo;

    var corrente = document.querySelector('.cat-path-current');

    if (corrente) corrente.textContent = nuovo;

    FC_CAT_NOME = nuovo;



    var fd = new FormData();

    fd.append('action', 'fc_rename_cat');

    fd.append('category_id', FC_CAT_ID);

    fd.append('name', nuovo);



    fetch('visualizzazione_fc.php', { method: 'POST', body: fd })

        .then(function(r){ return r.json(); })

        .then(function(data){

            if (data && data.success) {

                nome.textContent = data.name;

                if (corrente) corrente.textContent = data.name;

                FC_CAT_NOME = data.name;

                showToast(T('fc.vis_rinominata'));

            } else {

                nome.textContent = vecchio;

                if (corrente) corrente.textContent = vecchio;

                FC_CAT_NOME = vecchio;

                showToast((data && data.error) ? data.error : T('comune.errore_rete'));

            }

        })

        .catch(function(){

            nome.textContent = vecchio;

            if (corrente) corrente.textContent = vecchio;

            FC_CAT_NOME = vecchio;

            showToast(T('comune.errore_rete'));

        });

}



/* ---------- Riepilogo del progetto ---------- */

function fcFormattaDurata(sec){

    sec = Math.max(0, parseInt(sec || 0, 10));

    var h = Math.floor(sec / 3600), m = Math.round((sec % 3600) / 60);

    if (h && m) return h + 'h ' + m + 'm';

    if (h) return h + 'h';

    return m + 'm';

}



function fcFormattaData(iso){

    if (!iso) return '';

    var d = new Date(iso + 'T00:00:00');

    if (isNaN(d.getTime())) return iso;

    try {

        return d.toLocaleDateString(document.documentElement.lang || 'it',

                                    { day: 'numeric', month: 'short', year: 'numeric' });

    } catch(e){ return iso; }

}



/* Quanto manca, detto come lo direbbe una persona. */

function fcQuandoTesto(giorni){

    if (giorni === null || giorni === undefined) return '';

    if (giorni === 0) return T('fc.vis_prj_oggi');

    if (giorni === 1) return T('fc.vis_prj_domani');

    if (giorni > 1)  return T('fc.vis_prj_fra_giorni', { n: giorni });

    return T('fc.vis_prj_giorni_fa', { n: Math.abs(giorni) });

}



function fcApriProgetto(id){

    var sheet = document.getElementById('projectSheet');

    var body = document.getElementById('prjBody');

    document.getElementById('prjGo').href = 'progetti.php?id=' + encodeURIComponent(id);

    body.innerHTML = '<div class="prj-loading">' + fcEscapeHtml(T('fc.vis_prj_caricamento')) + '</div>';

    sheet.style.display = 'flex';

    buzz(8);



    fetch('visualizzazione_fc.php?fc_project_summary=1&project_id=' + encodeURIComponent(id))

        .then(function(r){ return r.json(); })

        .then(function(d){

            if (!d || !d.success) {

                body.innerHTML = '<div class="prj-loading">' + fcEscapeHtml((d && d.error) ? d.error : T('comune.errore_rete')) + '</div>';

                return;

            }

            body.innerHTML = fcRiepilogoHtml(d);

        })

        .catch(function(){

            body.innerHTML = '<div class="prj-loading">' + fcEscapeHtml(T('comune.errore_rete')) + '</div>';

        });

}



function fcRiepilogoHtml(d){

    var h = '';

    var esc = fcEscapeHtml;



    h += '<div class="prj-head" style="--prj:' + esc(d.color) + ';">';

    if (d.path && d.path.length) {

        h += '<div class="prj-path">' + d.path.map(function(p){ return esc(p); }).join('<span class="prj-path-sep">›</span>') + '</div>';

    }

    h += '<div class="prj-title-row"><span class="prj-dot"></span><span class="prj-name">' + esc(d.name) + '</span></div>';



    h += '<div class="prj-badges">';

    h += '<span class="prj-badge ' + (d.status === 'completed' ? 'ok' : 'live') + '">'

       + esc(d.status === 'completed' ? T('fc.vis_prj_completato') : T('fc.vis_prj_in_corso')) + '</span>';

    if (d.favorite) h += '<span class="prj-badge fav">★ ' + esc(T('fc.vis_prj_preferito')) + '</span>';

    h += '</div></div>';



    if (d.notes) h += '<div class="prj-notes">' + esc(d.notes) + '</div>';



    /* Quattro numeri, quelli che dicono davvero a che punto e' il progetto. */

    h += '<div class="prj-stats">';

    h += fcStatBox(fcFormattaDurata(d.secondi), T('fc.vis_prj_tempo'),

                   d.secondi7 ? (T('fc.vis_prj_settimana') + ': ' + fcFormattaDurata(d.secondi7)) : '');

    h += fcStatBox(String(d.sessioni), T('fc.vis_prj_pomodori'), '');

    h += fcStatBox(d.figli ? (d.figli_ok + '/' + d.figli) : '—', T('fc.vis_prj_sottoprogetti'), '');

    h += fcStatBox(d.todo ? (d.todo_ok + '/' + d.todo) : '—', T('fc.vis_prj_attivita'), '');

    h += '</div>';



    if (d.figli || d.todo) {

        h += '<div class="prj-bars">';

        if (d.figli) h += fcBarra(T('fc.vis_prj_sottoprogetti'), d.figli_ok, d.figli, d.color);

        if (d.todo)  h += fcBarra(T('fc.vis_prj_attivita'), d.todo_ok, d.todo, d.color);

        h += '</div>';

    }



    if (d.target || d.due) {

        h += '<div class="prj-block"><div class="prj-block-lab">' + esc(T('fc.vis_prj_date')) + '</div><div class="prj-dates">';

        if (d.target) h += fcRigaData(T('fc.vis_prj_obiettivo'), d.target, d.target_in, d.status === 'completed');

        if (d.due)    h += fcRigaData(T('fc.vis_prj_scadenza'), d.due, d.due_in, d.status === 'completed');

        h += '</div></div>';

    }



    if (d.evento) {

        h += '<div class="prj-block"><div class="prj-block-lab">' + esc(T('fc.vis_prj_prossimo')) + '</div>';

        h += '<a class="prj-event" href="planner.php?date=' + esc(d.evento.date) + '">'

           + '<span class="prj-event-day">' + esc(fcFormattaData(d.evento.date)) + (d.evento.time ? ' · ' + esc(d.evento.time) : '') + '</span>'

           + '<span class="prj-event-title">' + esc(d.evento.title) + '</span>'

           + '<span class="prj-event-when">' + esc(fcQuandoTesto(d.evento.giorni)) + '</span></a>';

        h += '</div>';

    }



    if (d.figli_el && d.figli_el.length) {

        h += '<div class="prj-block"><div class="prj-block-lab">' + esc(T('fc.vis_prj_sottoprogetti')) + '</div><div class="prj-children">';

        d.figli_el.forEach(function(c){

            h += '<a class="prj-child' + (c.done ? ' done' : '') + '" href="progetti.php?id=' + encodeURIComponent(c.id) + '"'

               + ' style="--prj:' + esc(c.color) + ';"><span class="prj-child-dot"></span>' + esc(c.name) + '</a>';

        });

        if (d.figli > d.figli_el.length) {

            h += '<span class="prj-child more">+' + (d.figli - d.figli_el.length) + '</span>';

        }

        h += '</div></div>';

    }



    if (d.mazzi && d.mazzi.length) {

        h += '<div class="prj-block"><div class="prj-block-lab">' + esc(T('fc.vis_prj_mazzi')) + '</div><div class="prj-decks">';

        d.mazzi.forEach(function(m){

            h += '<a class="prj-deck' + (m.corrente ? ' qui' : '') + '" href="visualizzazione_fc.php?category_id=' + encodeURIComponent(m.id) + '"'

               + ' style="--prj:' + esc(m.color) + ';"><span class="prj-deck-dot"></span>'

               + '<span class="prj-deck-name">' + esc(m.name) + '</span>'

               + '<span class="prj-deck-n">' + m.carte + '</span>'

               + (m.corrente ? '<span class="prj-deck-qui">' + esc(T('fc.vis_prj_qui')) + '</span>' : '')

               + '</a>';

        });

        h += '</div></div>';

    }



    return h;

}



function fcStatBox(valore, etichetta, sotto){

    return '<div class="prj-stat"><div class="prj-stat-v">' + fcEscapeHtml(valore) + '</div>'

         + '<div class="prj-stat-l">' + fcEscapeHtml(etichetta) + '</div>'

         + (sotto ? '<div class="prj-stat-s">' + fcEscapeHtml(sotto) + '</div>' : '')

         + '</div>';

}



function fcBarra(etichetta, fatti, totale, colore){

    var pct = totale ? Math.round(fatti / totale * 100) : 0;

    return '<div class="prj-bar" style="--prj:' + fcEscapeHtml(colore) + ';">'

         + '<div class="prj-bar-top"><span>' + fcEscapeHtml(etichetta) + '</span><span>' + pct + '%</span></div>'

         + '<div class="prj-bar-track"><span style="width:' + pct + '%"></span></div></div>';

}



function fcRigaData(etichetta, iso, giorni, chiuso){

    /* In rosso solo se e' passata e il progetto e' ancora aperto: su un

       progetto chiuso una data passata e' la normalita', non un allarme. */

    var scaduta = (giorni !== null && giorni < 0 && !chiuso);

    return '<div class="prj-date' + (scaduta ? ' late' : '') + '">'

         + '<span class="prj-date-lab">' + fcEscapeHtml(etichetta) + '</span>'

         + '<span class="prj-date-val">' + fcEscapeHtml(fcFormattaData(iso)) + '</span>'

         + '<span class="prj-date-when">' + fcEscapeHtml(fcQuandoTesto(giorni)) + '</span></div>';

}



document.getElementById('catColorSheet').addEventListener('click', function(e){ if (e.target === this) closeSheet('catColorSheet'); });

document.getElementById('projectSheet').addEventListener('click', function(e){ if (e.target === this) closeSheet('projectSheet'); });

(function(){

    var input = document.getElementById('catPillNameInput');

    if (!input) return;

    input.addEventListener('keydown', function(e){

        if (e.key === 'Enter') { e.preventDefault(); fcChiudiRinomina(true); }

        else if (e.key === 'Escape') { e.preventDefault(); fcChiudiRinomina(false); }

    });

    input.addEventListener('blur', function(){ fcChiudiRinomina(true); });

    input.addEventListener('click', function(e){ e.stopPropagation(); });

})();

</script>

<script>

/* ================================================================

   VISTA A FLASHCARD — una carta per volta, si gira al tocco.

   Il mazzo si costruisce da quello che e' VISIBILE in pagina: filtro,

   ricerca e risultati nelle sottocartelle valgono anche qui, senza dover

   rifare da capo la stessa selezione lato server.

   ================================================================ */

(function(){

    'use strict';



    var mazzo = [], idx = 0, girata = false, occupato = false;

    var fcv, inner, qEl, aEl, cnt, tot, bar, prevB, nextB, card, edit, stage;

    var barSib, barSibWrap, parentStrip, parentTxt, parentStripA, parentTxtA;

    var idxPadre = -1;   /* indice nel mazzo della carta padre di quella corrente */

    var PASSO_EXTRA = 24;   /* aria fra una carta e l'altra nel carosello */



    function elementi(){

        fcv   = document.getElementById('fcv');

        inner = document.getElementById('fcvInner');

        qEl   = document.getElementById('fcvQ');

        aEl   = document.getElementById('fcvA');

        cnt   = document.getElementById('fcvIdx');

        tot   = document.getElementById('fcvTot');

        bar   = document.getElementById('fcvBar');

        prevB = document.getElementById('fcvPrev');

        nextB = document.getElementById('fcvNext');

        card  = document.getElementById('fcvCard');

        edit  = document.getElementById('fcvEdit');

        stage = document.getElementById('fcvStage');

        barSib     = document.getElementById('fcvBarSib');

        barSibWrap = document.getElementById('fcvBarSibWrap');

        parentStrip = document.getElementById('fcvParent');

        parentTxt   = document.getElementById('fcvParentTxt');

        parentStripA = document.getElementById('fcvParentA');

        parentTxtA   = document.getElementById('fcvParentTxtA');

    }



    /* Un lato della carta senza la sua etichetta: l'etichetta la mette gia' il

       visore, ripeterla sarebbe scriverla due volte. */

    function latoHtml(lato){

        if (!lato) return '';

        var copia = lato.cloneNode(true);

        var lab = copia.querySelector('.qa-label');

        if (lab) lab.remove();

        return copia.innerHTML;

    }



    function costruisciMazzo(){

        mazzo = [];

        $all('.fc-card').forEach(function(c){

            if (c.style.display === 'none') return;

            var g = c.closest('.fc-group');

            if (g && g.style.display === 'none') return;

            var gDeep = c.closest('.fc-deep-group');

            if (gDeep && gDeep.classList.contains('collapsed')) return;



            var lati = c.querySelectorAll(':scope > .swipe-content > .qa-side');

            if (!lati.length) return;

            var esempi = c.querySelector(':scope > .swipe-content > .qa-examples');



            /* Il testo semplice della domanda serve per la striscia "carta

               padre" mostrata sulle figlie: li' bastano le prime righe, e il

               testo nudo si taglia in modo prevedibile (l'HTML no). */

            var testoQ = lati[0].querySelector('.qa-text');



            mazzo.push({

                q: latoHtml(lati[0]),

                a: latoHtml(lati[1]) + (esempi ? esempi.outerHTML : ''),

                url: c.getAttribute('data-edit-url') || '',

                figlia: c.classList.contains('fc-child'),

                id: c.getAttribute('data-id') || '',

                idPadre: c.getAttribute('data-parent-id') || '',

                testoQ: testoQ ? testoQ.textContent.trim() : '',

                el: c   /* la carta nell'elenco: serve a capire da quale partire */

            });

        });

    }



    /* Indici dei fratelli di una sotto-carta (le figlie dello stesso padre),

       nell'ordine in cui stanno nel mazzo. */

    function fratelliDi(i){

        var d = mazzo[i];

        if (!d || !d.figlia || !d.idPadre) return [];

        var out = [];

        for (var k = 0; k < mazzo.length; k++) {

            if (mazzo[k].figlia && mazzo[k].idPadre === d.idPadre) out.push(k);

        }

        return out;

    }



    function indicePadreDi(i){

        var d = mazzo[i];

        if (!d || !d.figlia || !d.idPadre) return -1;

        for (var k = 0; k < mazzo.length; k++) {

            if (!mazzo[k].figlia && mazzo[k].id === d.idPadre) return k;

        }

        return -1;

    }



    function riempi(i){

        var d = mazzo[i];

        qEl.innerHTML = d.q;

        aEl.innerHTML = d.a;

        cnt.textContent = (i + 1);

        bar.style.width = ((i + 1) / mazzo.length * 100) + '%';

        prevB.classList.toggle('off', i === 0);

        nextB.classList.toggle('off', i === mazzo.length - 1);

        qEl.scrollTop = 0; aEl.scrollTop = 0;

        card.classList.toggle('is-child', !!d.figlia);

        edit.style.display = d.url ? '' : 'none';



        /* Barra dei fratelli: solo sulle sotto-carte, e solo se ce n'e' piu'

           di una - con un fratello solo direbbe sempre "1 di 1".

           Non si usa hidden: l'elemento resta sempre nel flusso e si limita a

           diventare invisibile, altrimenti comparendo spingerebbe le carte piu'

           in basso e a fine animazione si vedrebbe uno scatto. */

        var frat = fratelliDi(i);

        if (frat.length > 1) {

            var pos = frat.indexOf(i) + 1;

            barSibWrap.classList.remove('is-off');

            barSib.style.width = (pos / frat.length * 100) + '%';

        } else {

            barSibWrap.classList.add('is-off');

            barSib.style.width = '0%';

        }



        /* Striscia della carta padre: le prime righe della sua domanda, uguali

           per tutti i fratelli. L'indice del padre si tiene da parte qui, cosi'

           il tocco non deve ricalcolarlo. */

        idxPadre = indicePadreDi(i);

        if (idxPadre >= 0) {

            parentTxt.textContent = mazzo[idxPadre].testoQ;

            parentStrip.hidden = false;

            /* Stesso testo, stessa visibilita': sul retro serve lo stesso
               contesto "di chi e' figlia", non una versione diversa. */

            if (parentTxtA) parentTxtA.textContent = mazzo[idxPadre].testoQ;

            if (parentStripA) parentStripA.hidden = false;

        } else {

            parentStrip.hidden = true;

            if (parentStripA) parentStripA.hidden = true;

        }

    }



    /* Salto diretto a una carta (dalla striscia del padre): non e' una

       sfogliata fra vicine, quindi niente carosello - solo una dissolvenza

       breve, che dice "siamo altrove" senza fingere una continuita' che non

       c'e'. */

    function vaiAIndice(ni){

        if (occupato || ni < 0 || ni >= mazzo.length || ni === idx) return;

        occupato = true;

        pulisciVicini();

        try {

            var via = card.animate([{ opacity:1, transform:'none' },

                                    { opacity:0, transform:'scale(.96)' }],

                                   { duration:150, easing:'ease-in', fill:'forwards' });

            via.onfinish = function(){

                idx = ni; raddrizza(true); riempi(idx);

                var dentro = card.animate([{ opacity:0, transform:'scale(1.03)' },

                                           { opacity:1, transform:'none' }],

                                          { duration:260, easing:'cubic-bezier(.22,1,.36,1)', fill:'both' });

                via.cancel();

                dentro.onfinish = function(){ dentro.cancel(); occupato = false; };

                buzz(8);

            };

        } catch(e){

            idx = ni; raddrizza(true); riempi(idx); occupato = false;

        }

    }



    function raddrizza(subito){

        girata = false;

        if (subito) {

            inner.style.transition = 'none';

            inner.classList.remove('flipped');

            void inner.offsetWidth;

            inner.style.transition = '';

        } else {

            inner.classList.remove('flipped');

        }

    }



    /* ---------- Carosello: la carta accanto esiste davvero ----------

       Prima si animava la stessa carta due volte (fuori, poi di nuovo dentro

       col contenuto nuovo): fra le due animazioni ricompariva per un istante

       quella vecchia. Ora le carte vicine sono elementi veri, messi ai lati

       e mossi insieme: quella che esce e quella che entra si vedono nello

       stesso momento, come sfogliando davvero. */

    var vicinoPrev = null, vicinoNext = null, passo = 0;

    var animCard = null, animPrev = null, animNext = null;   /* animazioni in volo, da poter cancellare */



    function creaVicino(i){

        if (i < 0 || i >= mazzo.length) return null;

        var el = card.cloneNode(true);

        el.removeAttribute('id');

        el.classList.add('fcv-vicino');

        el.classList.toggle('is-child', !!mazzo[i].figlia);

        $all('[id]', el).forEach(function(n){ n.removeAttribute('id'); });

        var f = el.querySelector('.fcv-front .fcv-body');

        var b = el.querySelector('.fcv-backface .fcv-body');

        if (f) f.innerHTML = mazzo[i].q;

        if (b) b.innerHTML = mazzo[i].a;

        var inn = el.querySelector('.fcv-inner');

        /* La carta accanto e' sempre sulla domanda: si arriva sempre a una

           carta da indovinare, non a una gia' girata. */

        if (inn) { inn.classList.remove('flipped'); inn.style.transition = 'none'; }

        /* La striscia arriva dalla copia, quindi porterebbe il padre della

           carta CORRENTE: va riscritta con quello della carta accanto, o tolta

           se quella e' un padre. */

        /* Sono due, ora — una per faccia — quindi querySelectorAll e non
           querySelector: un solo elemento trovato avrebbe aggiornato solo
           la prima delle due, lasciando l'altra ferma al valore vecchio. */
        var strips = el.querySelectorAll('.fcv-parent-strip');

        if (strips.length) {

            var ip = indicePadreDi(i);

            strips.forEach(function (strip) {

                if (ip >= 0) {

                    var t = strip.querySelector('.fcv-parent-txt');

                    if (t) t.textContent = mazzo[ip].testoQ;

                    strip.hidden = false;

                } else {

                    strip.hidden = true;

                }

            });

        }

        var ed = el.querySelector('.fcv-edit');

        if (ed) ed.remove();

        stage.appendChild(el);

        return el;

    }



    function preparaVicini(){

        if (vicinoPrev || vicinoNext) return;

        passo = (card.offsetWidth || 320) + PASSO_EXTRA;

        vicinoPrev = creaVicino(idx - 1);

        vicinoNext = creaVicino(idx + 1);

        posiziona(0);

    }



    /* Il linguaggio visivo di prima, tenuto sincronizzato su piu' elementi:

       la carta al centro ruota leggermente e sfuma andandosene (si sta

       "staccando"), quella accanto cresce e si schiarisce avvicinandosi al

       centro (sta "arrivando"). Una sola funzione per il trascinamento in

       diretta e per il volo finale, cosi' il volo riparte esattamente da

       dove il dito ha lasciato la carta invece che da una posa ferma. */

    function trasfCorrente(dx){

        var rot = dx / 26;

        var op = Math.max(.5, 1 - Math.abs(dx) / 480);

        return { transform: 'translateX(' + dx + 'px) rotate(' + rot.toFixed(2) + 'deg)', opacity: op };

    }



    function trasfVicino(base, dx){

        var pos = base + dx;

        var vicinanza = Math.max(0, 1 - Math.min(1, Math.abs(pos) / passo));

        var scala = .92 + .08 * vicinanza;

        var op = .55 + .45 * vicinanza;

        var rot = pos / 90;

        return { transform: 'translateX(' + pos + 'px) scale(' + scala.toFixed(3) + ') rotate(' + rot.toFixed(2) + 'deg)', opacity: op };

    }



    function applica(el, t){

        if (!el) return;

        el.style.transform = t.transform;

        el.style.opacity = t.opacity;

    }



    function posiziona(dx){

        applica(card, trasfCorrente(dx));

        applica(vicinoPrev, trasfVicino(-passo, dx));

        applica(vicinoNext, trasfVicino(passo, dx));

    }



    function pulisciVicini(){

        if (vicinoPrev) { vicinoPrev.remove(); vicinoPrev = null; }

        if (vicinoNext) { vicinoNext.remove(); vicinoNext = null; }

        card.style.transform = '';

        card.style.opacity = '';

    }



    /* Il volo finale: non piu' una transizione CSS che ripartiva sempre dalla

       stessa posa ferma (da li' la sensazione "meccanica"), ma un'animazione

       vera che parte esattamente da dove il dito ha lasciato la carta e

       arriva con una piccola frenata elastica in fondo, la stessa curva di

       prima. verso: 1 avanti, -1 indietro, 0 torna al suo posto. */

    function concludi(verso, dxPartenza){

        if (verso && !mazzo[idx + verso]) verso = 0;

        occupato = true;



        var dx0 = dxPartenza || 0;

        /* Esattamente un passo, senza margine in piu'. Con un moltiplicatore

           (prima 1.06, per buttare la carta uscente ben oltre il bordo) tutto

           il carosello si spostava di quel 6% in eccesso: la carta ENTRANTE

           finiva il volo un po' oltre il centro, e al momento di prendere il

           posto di quella vera scattava indietro per rimettersi al suo posto.

           Un passo intero basta comunque a portare fuori schermo l'uscente:

           passo e' gia' la larghezza della carta piu' l'aria fra una e

           l'altra. */

        var dxF = verso ? (verso * -passo) : 0;

        var durata = verso ? 300 : 260;

        var easing = verso ? 'cubic-bezier(.19,.86,.32,1.04)' : 'cubic-bezier(.22,1,.36,1)';



        var partenza = trasfCorrente(dx0);

        var arrivo = trasfCorrente(dxF);

        if (verso) arrivo.opacity = 0;



        animCard = card.animate([partenza, arrivo], { duration: durata, easing: easing, fill: 'forwards' });

        if (vicinoPrev) {

            animPrev = vicinoPrev.animate(

                [trasfVicino(-passo, dx0), trasfVicino(-passo, dxF)],

                { duration: durata, easing: easing, fill: 'forwards' });

        }

        if (vicinoNext) {

            animNext = vicinoNext.animate(

                [trasfVicino(passo, dx0), trasfVicino(passo, dxF)],

                { duration: durata, easing: easing, fill: 'forwards' });

        }



        setTimeout(function(){

            /* La carta vera si porta dietro il fotogramma finale prima di

               cancellare l'animazione: cosi' lo scambio non si vede. */

            applica(card, arrivo);

            if (animCard) { animCard.cancel(); animCard = null; }

            if (animPrev) { animPrev.cancel(); animPrev = null; }

            if (animNext) { animNext.cancel(); animNext = null; }



            if (verso) {

                idx += verso;

                raddrizza(true);

                riempi(idx);

            }

            pulisciVicini();

            occupato = false;

            if (verso) buzz(7);

        }, durata + 10);

    }



    function vai(verso){

        if (occupato) return;

        if (!mazzo[idx + verso]) return;

        preparaVicini();

        concludi(verso, 0);

    }



    /* Quale carta si stava guardando nell'elenco: quella che occupa piu'

       spazio nella parte utile dello schermo (sotto l'intestazione fissa).

       Non la prima visibile in assoluto: se una carta e' tagliata a meta' in

       cima e sotto ce n'e' una intera, e' quest'ultima che si stava leggendo. */

    function indiceInVista(){

        var h = document.getElementById('appHeader');

        var alto = h ? h.offsetHeight : 0;

        var finestra = window.innerHeight;

        var migliore = 0, miglioreArea = -1;

        for (var k = 0; k < mazzo.length; k++) {

            var el = mazzo[k].el;

            if (!el || !el.getBoundingClientRect) continue;

            var r = el.getBoundingClientRect();

            var area = Math.min(r.bottom, finestra) - Math.max(r.top, alto);

            if (area > miglioreArea) { miglioreArea = area; migliore = k; }

        }

        return migliore;

    }



    window.fcvApri = function(i){

        elementi();

        costruisciMazzo();

        if (!mazzo.length) { showToast(T('fc.vis_fcv_vuoto')); return; }

        /* Senza un indice esplicito (il tasto flottante) si riparte da dove si

           stava leggendo, non dall'inizio del mazzo. */

        if (i === undefined || i === null) i = indiceInVista();

        idx = Math.max(0, Math.min(mazzo.length - 1, i || 0));

        tot.textContent = mazzo.length;

        raddrizza(true);

        riempi(idx);

        bloccaPagina(true);

        fcv.classList.add('on');

        fcv.setAttribute('aria-hidden', 'false');

        try {

            card.animate([{ opacity:0, transform:'translateY(46px) scale(.92)' }, { opacity:1, transform:'none' }],

                         { duration: 430, easing:'cubic-bezier(.24,1.2,.35,1)' });

        } catch(e){}

        buzz(8);

    };



    /* Apre il visore posizionandolo su una carta precisa invece che su un

       indice: al ritorno da una modifica si conosce la carta, non la sua

       posizione nel mazzo (che nel frattempo puo' essere cambiata). */

    window.fcvApriSuCarta = function(tipo, id){

        elementi();

        costruisciMazzo();

        if (!mazzo.length) return false;

        var vuoleFiglia = (tipo === 'child');

        for (var k = 0; k < mazzo.length; k++) {

            if (mazzo[k].id === String(id) && !!mazzo[k].figlia === vuoleFiglia) {

                window.fcvApri(k);

                return true;

            }

        }

        return false;

    };



    function chiudi(){

        if (!fcv) return;

        pulisciVicini();

        fcv.classList.remove('on');

        fcv.setAttribute('aria-hidden', 'true');

        bloccaPagina(false);

    }



    /* ---------- La pagina sotto deve stare ferma ----------

       overflow:hidden sul body non basta: il dito continua a trascinare la

       pagina dietro al visore. Si congela la posizione con position:fixed e

       la si restituisce identica alla chiusura. */

    var scrollSalvato = 0;

    function bloccaPagina(blocca){

        var b = document.body;

        if (blocca) {

            scrollSalvato = window.pageYOffset || document.documentElement.scrollTop || 0;

            b.classList.add('fcv-lock');

            b.style.top = (-scrollSalvato) + 'px';

        } else {

            b.classList.remove('fcv-lock');

            b.style.top = '';

            window.scrollTo(0, scrollSalvato);

        }

    }



    function gira(){

        if (occupato) return;

        girata = !girata;

        inner.classList.toggle('flipped', girata);

        buzz(9);

    }



    document.addEventListener('click', function(e){

        if (!e.target || !e.target.closest) return;

        if (e.target.closest('#fcvClose')) { chiudi(); return; }

        if (!fcv || !fcv.classList.contains('on')) return;

        if (e.target.closest('#fcvEdit')) {

            var d = mazzo[idx];

            if (d && d.url) {

                /* Si lascia comunque detto dov'eravamo: al ricaricamento con
                   cui si chiude il popup (qui sotto) il visore si riapre da
                   solo sulla stessa carta invece di lasciare l'elenco —
                   esattamente come faceva prima, quando si usciva davvero
                   dalla pagina per andare a modificare. */
                try { sessionStorage.setItem('nisama_fcv_riapri', (d.figlia ? 'child:' : 'parent:') + d.id); } catch(err){}

                /* Ma la navigazione vera non serve piu': il visore resta
                   aperto sotto al popup, che si apre sopra di lui. Nessun
                   trucco per "tornare dove si era": non ci si e' mai
                   spostati. */
                if (typeof apriModificaPopup === 'function') {
                    apriModificaPopup(d.url, fcRicaricaSuCarta);
                } else {
                    window.location.href = d.url;
                }

            }

            return;

        }

        if (e.target.closest('#fcvParent, #fcvParentA')) { vaiAIndice(idxPadre); return; }

        if (e.target.closest('#fcvPrev')) { vai(-1); return; }

        if (e.target.closest('#fcvNext')) { vai(1); return; }

        if (e.target.closest('#fcvFlip')) { gira(); return; }

        if (e.target.closest('#fcvCard')) {

            /* Dentro la carta ci sono immagini che ingrandiscono, audio da far

               partire e il riquadro degli esempi che si apre a tutto schermo:

               quelli hanno la precedenza sul giro. */

            if (!e.target.closest('a, button, audio, .audio-player, .qa-image, .qa-examples-box')) gira();

            return;

        }

        if (e.target.closest('.fcv-back')) { chiudi(); return; }

    });



    /* Sfogliata orizzontale, con tutto il carosello che segue il dito. */

    (function(){

        var x0 = 0, y0 = 0, attivo = false, deciso = '', dxAttuale = 0, punti = [];

        document.addEventListener('touchstart', function(e){

            if (!fcv || !fcv.classList.contains('on') || occupato) return;

            if (!e.target.closest || !e.target.closest('#fcvStage')) return;

            if (e.touches.length !== 1) return;

            /* Il carosello degli esempi ha una sua sfogliata orizzontale (cambia

               esempio, non carta): se il gesto nasce li' dentro e' suo. */

            if (e.target.closest('.qa-examples-box')) return;

            /* Se il dito parte dentro qualcosa che scorre di suo (una formula

               larga, un testo che sborda), quel gesto e' suo: sfogliare qui

               vorrebbe dire non poter piu' leggere la formula. */

            if (fcScorrevoleOrizz(e.target, stage)) return;

            x0 = e.touches[0].clientX; y0 = e.touches[0].clientY;

            attivo = true; deciso = ''; dxAttuale = 0;

            punti = [{ x: x0, t: e.timeStamp }];

        }, { passive: true });



        document.addEventListener('touchmove', function(e){

            if (!attivo) return;

            var dx = e.touches[0].clientX - x0;

            var dy = e.touches[0].clientY - y0;

            if (!deciso) {

                if (Math.abs(dx) < 10 && Math.abs(dy) < 10) return;

                /* Un verso solo, e con un margine chiaro: se si comincia in

                   verticale si lascia scorrere il testo lungo. */

                if (Math.abs(dx) <= Math.abs(dy) * 1.3) { attivo = false; return; }

                deciso = 'x';

                preparaVicini();

            }

            /* Ai bordi del mazzo la carta si muove poco: si sente che di la'

               non c'e' piu' niente invece di sbattere contro un muro. */

            var freno = ((dx < 0 && !mazzo[idx + 1]) || (dx > 0 && !mazzo[idx - 1])) ? 0.28 : 1;

            dxAttuale = dx * freno;

            posiziona(dxAttuale);



            /* Solo gli ultimi istanti contano per la velocita' di rilascio:

               una sfogliata lunga che finisce lenta deve sentirsi lenta. */

            punti.push({ x: e.touches[0].clientX, t: e.timeStamp });

            if (punti.length > 6) punti.shift();

        }, { passive: true });



        function fine(e){

            if (!attivo) return;

            attivo = false;

            if (deciso !== 'x') return;



            var velocita = 0;

            if (punti.length >= 2) {

                var a = punti[0], b = punti[punti.length - 1];

                var dt = b.t - a.t;

                if (dt > 0) velocita = (b.x - a.x) / dt;   /* px al millisecondo */

            }



            /* Un colpo secco vale anche se il dito non ha percorso molta

               strada: e' la differenza fra una sfogliata "trascinata fino in

               fondo" e una "data con un colpetto", entrambe naturali. */

            var soglia = Math.min(90, (card.offsetWidth || 320) * 0.22);

            var scatto = Math.abs(velocita) > 0.55;

            var verso = 0;

            if (Math.abs(dxAttuale) > soglia || scatto) {

                verso = (dxAttuale !== 0 ? dxAttuale < 0 : velocita < 0) ? 1 : -1;

            }

            concludi(verso, dxAttuale);

        }

        document.addEventListener('touchend', fine);

        document.addEventListener('touchcancel', fine);

    })();



    document.addEventListener('keydown', function(e){

        if (!fcv || !fcv.classList.contains('on')) return;

        if (e.key === 'Escape') { e.preventDefault(); chiudi(); }

        else if (e.key === 'ArrowLeft') { e.preventDefault(); vai(-1); }

        else if (e.key === 'ArrowRight') { e.preventDefault(); vai(1); }

        else if (e.key === ' ' || e.key === 'ArrowUp' || e.key === 'ArrowDown') { e.preventDefault(); gira(); }

    });



    /* ---------- Il tasto flottante alla stessa altezza di quello della

       sidebar. La misura si prende a video invece di ricopiare un numero:

       la sidebar ha il suo foglio di stile e un giorno potrebbe cambiarlo,

       e due numeri uguali scritti in due posti diversi restano uguali solo

       finche' qualcuno non ne tocca uno. ---------- */

    function fabDellaSidebar(){

        var candidati = ['#sbFab', '.sb-fab', '#sidebarFab', '.sidebar-fab'];

        for (var i = 0; i < candidati.length; i++) {

            var el = document.querySelector(candidati[i]);

            if (el && el.id !== 'fcvFab') return el;

        }

        var trovato = null;

        $all('[id*="fab" i], [class*="fab" i]').forEach(function(el){

            if (trovato || el.id === 'fcvFab' || el.closest('#fcv')) return;

            var st = getComputedStyle(el);

            if (st.position === 'fixed' && el.offsetHeight > 20) trovato = el;

        });

        return trovato;

    }



    function allineaFab(){

        var mio = document.getElementById('fcvFab');

        var sb = fabDellaSidebar();

        if (!mio || !sb) return;

        var r = sb.getBoundingClientRect();

        if (!r.height) return;

        document.documentElement.style.setProperty('--fcv-fab-bottom',

            Math.max(0, Math.round(window.innerHeight - r.bottom)) + 'px');

        document.documentElement.style.setProperty('--fcv-fab-size', Math.round(r.height) + 'px');

    }



    window.fcvAllineaFab = allineaFab;

    window.addEventListener('resize', allineaFab);

})();

</script>











<script>

/* ================================================================

   SELECT MODE / AZIONI DI MASSA

   ================================================================

   Tre stati, non uno. Prima c'era solo "sto selezionando"; ora dentro la
   selezione possono aprirsi due modalita' di lavoro che cambiano le regole:

     ''       selezione normale: si scelgono carte e si fa qualcosa con loro
     'unjoin' SEPARA: restano solo i padri con i loro figli, e si spuntano
              soltanto i figli — un padre non si puo' separare da se stesso
     'join'   UNISCI: restano solo i padri, se ne sceglie uno e poi si tocca
              la carta che deve diventargli padre

   Lo stato vive in document.body.dataset.bulkAction (piu' le classi
   action-join / action-unjoin, che servono al CSS). Il CSS non sa fare "se",
   quindi tutte le decisioni su cosa mostrare passano da qui.

   ================================================================ */

/* Quale modalita' di struttura e' aperta ('', 'join', 'unjoin'). */
function modoStruttura(){ return document.body.dataset.bulkAction || ''; }

/* Siamo nel momento in cui si tocca la carta che diventera' padre? */
function stoScegliendoIlPadre(){ return document.body.classList.contains('join-picking'); }

function enterSelectMode(){
    document.body.classList.add('select-mode');
    document.getElementById('normalToolbar').style.display = 'none';
    document.getElementById('selectToolbar').style.display = 'flex';
    buzz(8);
    aggiornaBarraSelezione();
    filterList();
}

function exitSelectMode(){
    document.body.classList.remove('select-mode', 'action-join', 'action-unjoin',
                                   'join-picking', 'is-compact');
    document.body.dataset.bulkAction = '';
    document.getElementById('normalToolbar').style.display = 'flex';
    document.getElementById('selectToolbar').style.display = 'none';
    document.getElementById('modeBanner').style.display = 'none';
    document.getElementById('stActions').style.display = 'flex';
    document.getElementById('selectAllBtn').style.display = '';
    document.getElementById('compactBtn').classList.remove('is-on');
    document.getElementById('hiddenFatherId').value = '';
    $all('.st-action').forEach(function(b){ b.classList.remove('active-pick'); });
    $all('.record-cb').forEach(function(cb){ cb.checked = false; });
    $all('.fc-card').forEach(function(c){ c.classList.remove('selected-row', 'is-open'); });
    $all('.fc-group').forEach(function(g){ g.classList.remove('kids-open'); });
    aggiornaBarraSelezione();
    filterList();
}

/* La X non chiude sempre la stessa cosa: chiude LO STRATO PIU' ESTERNO.
   Aperta la modalita' Separa, la X toglie quella e riporta alla selezione
   normale — perche' e' li' che si e' appena entrati e quasi sempre e' quello
   che si vuole disfare. Solo dalla selezione nuda la X esce del tutto.
   Un gesto solo che disfa un passo per volta, senza dover indovinare quale
   dei due gesti serviva. */
function onSelectCancel(){
    if (stoScegliendoIlPadre()) { annullaSceltaPadre(); return; }
    if (modoStruttura() !== '') { chiudiModalitaStruttura(); return; }
    exitSelectMode();
}



/* ---------------- Conteggio e barra ---------------- */

function contaSpuntate(){ return $all('.record-cb:checked').length; }

/* Decide, ogni volta che qualcosa cambia, cosa si vede nella barra. Un posto
   solo: prima queste stesse decisioni erano sparse fra enterSelectMode,
   exitSelectMode e onBulkActionPick, e bastava aggiungere un caso perche' due
   di loro si contraddicessero. */
function aggiornaBarraSelezione(){
    var n = contaSpuntate();
    var modo = modoStruttura();
    var el = document.getElementById('selectCount');
    if (el) el.textContent = n;

    /* Sposta ed Elimina hanno senso solo con qualcosa di scelto. La loro
       lineetta se ne va con loro: due lineette di fila sarebbero un divisorio
       che non divide niente. */
    var conScelte = n > 0;
    document.getElementById('stGruppoScelte').style.display = conScelte ? 'flex' : 'none';
    document.getElementById('stSepScelte').style.display    = conScelte ? 'block' : 'none';

    var bUnisci = document.getElementById('btnUnisci');
    var bSepara = document.getElementById('btnSepara');

    /* Dentro una modalita' l'altra sparisce: in Separa l'elenco mostra i figli,
       e "Unisci" li' non avrebbe niente su cui lavorare. Il pulsante della
       modalita' in corso resta, spento, come promemoria di dove si e' — e si
       riaccende appena c'e' qualcosa da separare o da unire. */
    bUnisci.style.display = (modo === 'unjoin') ? 'none' : '';
    bSepara.style.display = (modo === 'join')   ? 'none' : '';
    bUnisci.classList.toggle('is-spento', modo === 'join'   && n === 0);
    bSepara.classList.toggle('is-spento', modo === 'unjoin' && n === 0);
    bUnisci.classList.toggle('is-pronto', modo === 'join'   && n > 0);
    bSepara.classList.toggle('is-pronto', modo === 'unjoin' && n > 0);

    /* Scegliendo il padre non c'e' piu' niente da spuntare: via tutto tranne
       la X, che e' l'unica cosa che serve se si e' cambiato idea. */
    var scegliendo = stoScegliendoIlPadre();
    document.getElementById('stActions').style.display    = scegliendo ? 'none' : 'flex';
    document.getElementById('selectAllBtn').style.display = scegliendo ? 'none' : '';
    document.getElementById('compactBtn').style.display   = scegliendo ? 'none' : '';
}

/* Nome storico: lo chiamano gli onchange delle caselle nel markup. */
function updateSelectCount(){ aggiornaBarraSelezione(); }

function onRecordCbChange(cb){
    var card = cb.closest('.fc-card');
    if (card) card.classList.toggle('selected-row', cb.checked);
    aggiornaBarraSelezione();
    if (document.body.classList.contains('select-mode')) filterList();
}

/* Le caselle su cui "Tutte" ha effetto: solo quelle visibili, e solo quelle
   che nella modalita' in corso hanno senso. In Separa si spuntano i figli (i
   padri li' non hanno nemmeno la casella); in Unisci si sceglie fra i padri. */
function caselleDiQuestaModalita(){
    var modo = modoStruttura();
    var sel = '.record-cb';
    if (modo === 'unjoin') sel = '.child-cb';
    else if (modo === 'join') sel = '.standalone-cb';
    return $all(sel).filter(function(cb){
        var card = cb.closest('.fc-card');
        if (!card || card.style.display === 'none') return false;
        var gruppo = card.closest('.fc-group');
        if (gruppo && gruppo.style.display === 'none') return false;
        /* Un figlio dentro un padre chiuso e' nel documento ma non a schermo:
           spuntarlo vorrebbe dire scegliere qualcosa che non si sta vedendo. */
        if (card.classList.contains('fc-child') && !card.offsetParent) return false;
        return true;
    });
}

function toggleSelectAll(){
    var boxes = caselleDiQuestaModalita();
    var tutteSpuntate = boxes.length > 0 && boxes.every(function(cb){ return cb.checked; });
    boxes.forEach(function(cb){
        cb.checked = !tutteSpuntate;
        var card = cb.closest('.fc-card');
        if (card) card.classList.toggle('selected-row', cb.checked);
    });
    document.getElementById('selectAllBtn').textContent =
        tutteSpuntate ? T('vocab.vi_tutte') : T('vocab.vi_nessuna');
    aggiornaBarraSelezione();
}



/* ---------------- VISTA COMPATTA ---------------- */

/* Una carta per riga: inizio della domanda, inizio della risposta. Tutto il
   lavoro lo fa il CSS (body.is-compact): niente anteprime da costruire, il
   testo e' gia' nella pagina e si limita a una riga sola.
   E' una vista di lavoro, non una preferenza: non si salva, e si spegne da
   sola uscendo dalla selezione. */
function toggleCompactMode(forza){
    var acceso = (typeof forza === 'boolean')
        ? forza
        : !document.body.classList.contains('is-compact');
    document.body.classList.toggle('is-compact', acceso);
    document.getElementById('compactBtn').classList.toggle('is-on', acceso);
    /* Spegnendola, le carte aperte a mano tornano al passo con le altre. */
    if (!acceso) $all('.fc-card.is-open').forEach(function(c){ c.classList.remove('is-open'); });
    buzz(6);
}

/* Apre UNA carta a grandezza vera senza spegnere la vista compatta per tutte
   le altre: serve a controllare cosa c'e' dentro prima di spuntarla. */
function toggleCardOpen(btn){
    var card = btn.closest('.fc-card');
    if (!card) return;
    var apro = !card.classList.contains('is-open');
    card.classList.toggle('is-open', apro);
    btn.setAttribute('aria-label', T(apro ? 'fc.vis_chiudi_carta' : 'fc.vis_apri_carta'));
    btn.setAttribute('title', btn.getAttribute('aria-label'));
}

/* Mostra o nasconde le sottocarte di un padre. Vale in modalita' Unisci, dove
   l'elenco tiene i figli chiusi per non far scorrere per pagine. */
function toggleKids(btn){
    var gruppo = btn.closest('.fc-group');
    if (!gruppo) return;
    gruppo.classList.toggle('kids-open');
    buzz(5);
}

/* Su e giu' al posto del trascinamento: nella vista compatta le carte sono
   righe, e trascinare una riga per venti posizioni e' peggio che premere venti
   volte una freccia. Sposta l'unita' giusta — il GRUPPO intero per un padre
   (con i suoi figli appresso), la singola carta per un figlio — e salva subito
   l'ordine con la stessa funzione che usa il trascinamento. */
function moveCardBy(btn, verso){
    var card = btn.closest('.fc-card');
    if (!card) return;
    var eUnPadre = card.classList.contains('fc-parent');
    var unita = eUnPadre ? card.closest('.fc-group') : card;
    if (!unita || !unita.parentElement) return;

    if (unita.getAttribute('data-reorderable') !== '1') {
        showToast(T('fc.vis_ordine_bloccato'));
        return;
    }

    var scope = unita.parentElement;
    var selettore = eUnPadre ? '.fc-group' : '.fc-card.fc-child';
    /* Solo i vicini VISIBILI: con un filtro attivo, saltare sopra una carta
       nascosta darebbe uno spostamento che a schermo non si vede. */
    var vicini = Array.prototype.slice.call(scope.children).filter(function(el){
        return el.matches(selettore) && el.style.display !== 'none';
    });
    var i = vicini.indexOf(unita);
    var j = i + verso;
    if (i < 0 || j < 0 || j >= vicini.length) { buzz(4); return; }

    if (verso < 0) scope.insertBefore(unita, vicini[j]);
    else           scope.insertBefore(vicini[j], unita);

    buzz(8);
    if (typeof window.fcSalvaOrdine === 'function') window.fcSalvaOrdine(unita, scope);
    if (card.scrollIntoView) card.scrollIntoView({ block: 'nearest' });
}



/* ---------------- MODALITA' SEPARA / UNISCI ---------------- */

/* mantieni=true quando la modalita' si apre da una selezione gia' fatta: si e'
   scelto qualcosa e POI si e' premuto Unisci, e quelle carte sono l'oggetto
   del lavoro, non un residuo da buttare. */
function apriModalitaStruttura(modo, mantieni){
    document.body.dataset.bulkAction = modo;
    document.body.classList.remove('action-join', 'action-unjoin');
    document.body.classList.add(modo === 'join' ? 'action-join' : 'action-unjoin');

    /* Le due modalita' esistono per lavorare su molte carte insieme: la vista
       compatta non e' un di piu', e' il motivo per cui si riesce a farlo. */
    toggleCompactMode(true);

    /* Quello che era spuntato prima appartiene alle regole di prima: in Separa
       si spuntano i figli, in Unisci i padri, e portarsi dietro la selezione
       vecchia vorrebbe dire partire con una scelta illegale gia' fatta. */
    if (!mantieni) {
        $all('.record-cb:checked').forEach(function(cb){
            cb.checked = false;
            var card = cb.closest('.fc-card');
            if (card) card.classList.remove('selected-row');
        });
    }

    var banner = document.getElementById('modeBanner');
    document.getElementById('modeBannerText').textContent =
        T(modo === 'join' ? 'fc.vis_modo_unisci' : 'fc.vis_modo_separa');
    banner.style.display = 'flex';

    buzz(10);
    aggiornaBarraSelezione();
    filterList();
}

function chiudiModalitaStruttura(){
    document.body.dataset.bulkAction = '';
    document.body.classList.remove('action-join', 'action-unjoin', 'join-picking');
    document.getElementById('modeBanner').style.display = 'none';
    document.getElementById('hiddenFatherId').value = '';
    $all('.st-action').forEach(function(b){ b.classList.remove('active-pick'); });
    $all('.fc-group').forEach(function(g){ g.classList.remove('kids-open'); });
    $all('.record-cb:checked').forEach(function(cb){
        cb.checked = false;
        var card = cb.closest('.fc-card');
        if (card) card.classList.remove('selected-row');
    });
    aggiornaBarraSelezione();
    filterList();
}

function avviaSceltaPadre(){
    document.body.classList.add('join-picking');
    document.getElementById('modeBannerText').textContent = T('fc.vis_tocca_padre');
    document.getElementById('modeBanner').style.display = 'flex';
    buzz(12);
    aggiornaBarraSelezione();
    filterList();
}

function annullaSceltaPadre(){
    document.body.classList.remove('join-picking');
    document.getElementById('modeBannerText').textContent = T('fc.vis_modo_unisci');
    aggiornaBarraSelezione();
    filterList();
}

/* Il padre si sceglie toccandolo, punto: niente da spuntare e niente da
   confermare. Prima le caselle delle carte non scelte venivano sostituite al
   volo da dei radio e serviva un secondo gesto su un pulsante in fondo allo
   schermo — due passi per dire una cosa sola, e nel frattempo il markup della
   pagina veniva riscritto e poi rimesso a posto. E' lo stesso gesto che si fa
   in inserimento_big_fc.php. */
document.addEventListener('click', function(e){
    if (!stoScegliendoIlPadre()) return;
    var card = e.target.closest('.fc-card.fc-parent');
    if (!card) return;

    e.preventDefault();
    e.stopPropagation();

    var cb = card.querySelector('.standalone-cb');
    if (cb && cb.checked) { showToast(T('fc.vis_padre_e_scelta')); return; }

    document.getElementById('hiddenFatherId').value = card.getAttribute('data-id');
    buzz(14);
    submitBulkForm('join');
}, true);



/* ---------------- Il pulsante premuto ---------------- */

function onBulkActionPick(action){
    var scelte = contaSpuntate();
    var modo = modoStruttura();

    /* SEPARA e UNISCI hanno due significati a seconda di dove si e':
       - da fuori, senza niente di scelto, APRONO la loro modalita' di lavoro
       - dentro la modalita' (o con delle carte gia' scelte), ESEGUONO
       E' la stessa idea del pulsante che apre un cassetto e poi lo conferma:
       un solo posto dove cercarlo, invece di due pulsanti quasi uguali. */
    if (action === 'unjoin' && modo !== 'unjoin' && scelte === 0) { apriModalitaStruttura('unjoin'); return; }
    if (action === 'join'   && modo !== 'join'   && scelte === 0) { apriModalitaStruttura('join');   return; }

    if (scelte === 0) { showToast(T('fc.vis_serve_carta')); return; }

    $all('.st-action').forEach(function(b){ b.classList.toggle('active-pick', b.dataset.action === action); });

    if (action === 'delete') {
        openDeleteConfirm(scelte, '', function(){ submitBulkForm('delete'); });
        return;
    }

    if (action === 'move') {
        /* csOpen() vuole l'elemento del modale, non il suo id. Con $cs_id='move'
           il componente lo chiama 'cs-modal-move'. */
        var mv = document.getElementById('cs-modal-move');
        if (mv && typeof csOpen === 'function') csOpen(mv);
        else showToast(T('fc.vis_sposta_dove'));
        return;
    }

    if (action === 'unjoin') {
        if ($all('.standalone-cb:checked').length > 0) {
            showToast(T('fc.vis_solo_sottocarte'));
            return;
        }
        submitBulkForm('unjoin');
        return;
    }

    if (action === 'join') {
        var padreConFigli = $all('.standalone-cb:checked').some(function(cb){
            var group = cb.closest('.fc-group');
            return group && group.querySelector('.fc-children .fc-card');
        });
        if (padreConFigli) { showToast(T('fc.vis_no_padre_con_figli')); return; }
        if ($all('.child-cb:checked').length > 0) { showToast(T('fc.vis_no_figlie')); return; }

        /* Si arriva qui da fuori (carte gia' scelte, poi Unisci) o da dentro la
           modalita'. Nel primo caso la modalita' si apre adesso, tenendosi le
           carte scelte: sono loro l'oggetto del lavoro. */
        if (modo !== 'join') apriModalitaStruttura('join', true);
        avviaSceltaPadre();
    }
}

function submitBulkForm(actionValue){
    document.getElementById('hiddenBulkAction').value = actionValue;
    document.getElementById('bulkForm').submit();
}



function pickMoveCategory(categoryId){

    document.getElementById('hiddenTargetCategory').value = categoryId;



    submitBulkForm('move');

}

/* Il selettore scrive la categoria scelta nel proprio campo nascosto: si
   osserva quello invece di inventare una richiamata che il componente non
   prevede. */
document.addEventListener('change', function (e) {
    if (e.target && e.target.name === 'cs_move_target' && e.target.value) {
        pickMoveCategory(parseInt(e.target.value, 10) || 0);
    }
});




function closeSheet(id){

    const el = document.getElementById(id);

    if (el) el.style.display = 'none';

}



function openDeleteConfirm(count, warnText, onConfirm){

    document.getElementById('deleteSheetTitle').textContent = (count === 1) ? T('fc.vis_eliminare_carta') : T('fc.vis_eliminare_n_carte', {n: count});

    document.getElementById('deleteSheetSub').textContent = T('fc.vis_non_annullabile');

    const warnEl = document.getElementById('deleteSheetWarn');

    if (warnText) { warnEl.textContent = warnText; warnEl.style.display = 'block'; }

    else { warnEl.style.display = 'none'; }

    const btn = document.getElementById('deleteSheetConfirm');

    btn.onclick = function(){ closeSheet('deleteSheet'); onConfirm(); };

    document.getElementById('deleteSheet').style.display = 'flex';

}



document.getElementById('deleteSheet').addEventListener('click', function(e){ if (e.target === this) closeSheet('deleteSheet'); });




/* ================================================================

   AZIONI SWIPE: MODIFICA / ELIMINA (singola carta)

   ================================================================ */

function goEditCard(btn){

    const card = btn.closest('.fc-card');

    const url = card && card.getAttribute('data-edit-url');

    /* Apre il popup di modifica invece di lasciare la pagina: al salvataggio
       o all'eliminazione si ricarica, cosi' l'elenco torna coerente con
       quello che c'e' davvero nel database — piu' semplice e piu' sicuro
       che provare ad aggiornare a mano solo la carta toccata. */
    if (url && typeof apriModificaPopup === 'function') {
        apriModificaPopup(url, fcRicaricaSuCarta);
    } else if (url) {
        window.location.href = url;
    }

}



function askDeleteCard(btn){

    const card = btn.closest('.fc-card');

    const type = card.getAttribute('data-type');

    const childCount = parseInt(card.getAttribute('data-child-count') || '0', 10);

    let warn = '';

    if (type === 'parent' && childCount > 0) {

        warn = (childCount === 1)

            ? T('fc.vis_una_orfana')

            : T('fc.vis_n_orfane', {n: childCount});

    }

    openDeleteConfirm(1, warn, function(){ performSingleDelete(card); });

}



function performSingleDelete(card){

    const type = card.getAttribute('data-type');

    const id = card.getAttribute('data-id');

    const fd = new FormData();

    fd.append('bulk_action', 'delete');

    if (type === 'parent') { fd.append('selected_ids[]', id); } else { fd.append('selected_child_ids[]', id); }



    const target = (type === 'parent') ? card.closest('.fc-group') : card;

    fetch('visualizzazione_fc.php', { method: 'POST', body: fd })

        .then(function(){ animateCardOut(target); })

        .catch(function(){ showToast(T('fc.vis_errore_elimina')); if (window.resetSwipeCard) window.resetSwipeCard(card); });

}



function animateCardOut(el){

    if (!el) return;

    const h = el.offsetHeight;

    el.style.overflow = 'hidden';

    el.style.transition = 'none';

    el.style.height = h + 'px';

    requestAnimationFrame(function(){

        el.style.transition = 'height .32s var(--spring), opacity .28s ease-out, transform .32s var(--spring), margin .32s var(--spring)';

        el.style.height = '0px';

        el.style.opacity = '0';

        el.style.transform = 'scale(.94)';

        el.style.marginBottom = '0px';

        el.style.marginTop = '0px';

    });

    setTimeout(function(){

        const parentChildrenBox = el.closest('.fc-children');

        el.remove();

        if (parentChildrenBox && !parentChildrenBox.querySelector('.fc-card')) parentChildrenBox.remove();

        filterList();

    }, 340);

}

</script>



<script>

/* ================================================================

   MOTORE GESTUALE — porta la stessa logica di inserimento_big_fc.php:

   ghost "fixed" che segue il dito + placeholder che apre lo spazio,

   animate con la tecnica FLIP, blocco scroll dedicato su touchmove,

   auto-scroll ai bordi. Il contenimento figlio-dentro-il-padre e'

   garantito dalla struttura stessa (il placeholder vive solo dentro

   lo scope .fc-children o solo dentro #fcList, non puo' uscirne).

   ================================================================ */

(function(){

    'use strict';

    var TAP_SLOP = 10;

    var LONG_PRESS_MS = 420;

    var pend = null;

    var openSwipeContent = null;

    var gestureBlock = false;

    var activeDrag = null;

    var lastPointerY = 0;

    var autoScrollRAF = null;



    function isInteractive(el){

        if (el.closest && el.closest('button, a, input, select, textarea, audio, .ap-track, .audio-player')) return true;

        /* Una formula larga si legge scorrendola: se il gesto nasce li' dentro

           non deve aprire i tastini modifica/elimina. */

        if (typeof fcScorrevoleOrizz === 'function' && fcScorrevoleOrizz(el, el.closest('.fc-card'))) return true;

        /* Gli esempi hanno un loro swipe orizzontale (cambia esempio): se il

           gesto nasce li' dentro non deve aprire i tastini modifica/elimina. */

        if (el.closest && el.closest('.qa-examples')) return true;

        return false;

    }

    function getTranslateX(el){

        var m = el.style.transform.match(/translateX\((-?\d+(?:\.\d+)?)px\)/);

        return m ? parseFloat(m[1]) : 0;

    }

    function closeOpenSwipe(){

        if (openSwipeContent) {

            openSwipeContent.style.transition = 'transform .3s var(--spring)';

            openSwipeContent.style.transform = 'translateX(0px)';

            var chiudiCard = openSwipeContent.closest('.fc-card');

            var chiudiActions = chiudiCard && chiudiCard.querySelector('.swipe-actions');

            if (chiudiActions) chiudiActions.classList.remove('revealing');

            openSwipeContent = null;

        }

    }

    window.resetSwipeCard = function(card){

        var content = card && card.querySelector('.swipe-content');

        if (!content) return;

        content.style.transition = 'transform .3s var(--spring)';

        content.style.transform = 'translateX(0px)';

        var actionsEl = card.querySelector('.swipe-actions');

        if (actionsEl) actionsEl.classList.remove('revealing');

        if (openSwipeContent === content) openSwipeContent = null;

    };



    /* ---- FLIP: anima i figli di "container" dalla posizione pre-mutate a quella nuova ---- */

    function flip(container, mutate){

        var items = Array.prototype.slice.call(container.children);

        var first = new Map();

        items.forEach(function(el){ first.set(el, el.getBoundingClientRect()); });

        mutate();

        Array.prototype.slice.call(container.children).forEach(function(el){

            var f = first.get(el);

            if (!f) return;

            var l = el.getBoundingClientRect();

            var dx = f.left - l.left, dy = f.top - l.top;

            if (Math.abs(dx) > 0.5 || Math.abs(dy) > 0.5) {

                el.style.transition = 'none';

                el.style.transform = 'translate(' + dx + 'px,' + dy + 'px)';

                requestAnimationFrame(function(){

                    el.style.transition = 'transform .38s var(--spring)';

                    el.style.transform = '';

                });

            }

        });

    }



    /* chiude lo swipe aperto quando si tocca altrove */

    document.addEventListener('pointerdown', function(e){

        if (activeDrag) return;

        if (!openSwipeContent) return;

        if (e.target.closest && e.target.closest('.swipe-content') === openSwipeContent) return;

        if (e.target.closest && e.target.closest('.swipe-act')) return;

        closeOpenSwipe();

    }, true);



    function eligibleCard(e){

        if (!e.target || !e.target.closest) return null;

        if (isInteractive(e.target)) return null;

        var card = e.target.closest('.fc-card');

        if (!card || !card.closest('#fcList')) return null;

        return card;

    }



    document.addEventListener('pointerdown', function(e){

        if (document.body.classList.contains('select-mode')) return;

        if (pend || activeDrag) return;

        if (e.pointerType === 'mouse' && e.button !== 0) return;

        var card = eligibleCard(e);

        if (!card) return;



        var content = card.querySelector('.swipe-content');

        var isParent = card.classList.contains('fc-parent');

        var dragUnit = isParent ? card.closest('.fc-group') : card;

        var scopeEl = dragUnit ? dragUnit.parentElement : null;

        var reorderable = !!(dragUnit && scopeEl && dragUnit.getAttribute('data-reorderable') === '1');



        pend = {

            card: card, content: content, dragUnit: dragUnit, scopeEl: scopeEl,

            pointerId: e.pointerId, startX: e.clientX, startY: e.clientY,

            swiping: false, reordering: false,

            longPressTimer: reorderable ? setTimeout(function(){ if (pend && !pend.swiping) activateReorder(pend); }, LONG_PRESS_MS) : null

        };

    });



    document.addEventListener('pointermove', function(e){

        if (!pend || e.pointerId !== pend.pointerId) return;

        if (pend.reordering) { doReorderMove(pend, e); return; }

        if (pend.swiping) { doSwipeMove(pend, e); return; }



        var dx = e.clientX - pend.startX, dy = e.clientY - pend.startY;

        var adx = Math.abs(dx), ady = Math.abs(dy);



        if (adx > TAP_SLOP && adx > ady * 1.25) {

            clearTimeout(pend.longPressTimer);

            pend.swiping = true;

            activateSwipeGesture(pend);

            doSwipeMove(pend, e);

        } else if (ady > TAP_SLOP && ady > adx * 1.25) {

            clearTimeout(pend.longPressTimer);

            pend = null;

        }

    }, { passive: true });



    document.addEventListener('pointerup', function(e){ finishGesture(e, true); });

    document.addEventListener('pointercancel', function(e){ finishGesture(e, false); });



    function finishGesture(e, committed){

        if (!pend || e.pointerId !== pend.pointerId) return;

        var p = pend; pend = null;

        clearTimeout(p.longPressTimer);

        if (p.reordering) { finalizeReorder(p); }

        else if (p.swiping) { finalizeSwipeGesture(p, committed); }

        else if (openSwipeContent === p.content && getTranslateX(p.content) < -4) {

            closeOpenSwipe();

        }

    }



    /* ---------------- SWIPE ORIZZONTALE (Modifica / Elimina) ---------------- */

    function activateSwipeGesture(ctx){

        gestureBlock = true;

        document.body.classList.add('no-select');

        if (openSwipeContent && openSwipeContent !== ctx.content) closeOpenSwipe();

        ctx.content.style.transition = 'none';

        var actionsEl = ctx.card.querySelector('.swipe-actions');

        /* Si rivela PRIMA di misurare: partono nascosti (display:none), e la

           larghezza letta su un elemento non renderizzato sarebbe 0. */

        if (actionsEl) actionsEl.classList.add('revealing');

        ctx.maxReveal = actionsEl ? actionsEl.offsetWidth : 150;

        ctx.startTranslate = getTranslateX(ctx.content);

    }

    function doSwipeMove(ctx, e){

        var x = ctx.startTranslate + (e.clientX - ctx.startX);

        if (x > 0) x = x * 0.15;

        if (x < -ctx.maxReveal) x = -ctx.maxReveal;

        ctx.content.style.transform = 'translateX(' + x + 'px)';

        ctx.currentX = x;

    }

    function finalizeSwipeGesture(ctx, committed){

        document.body.classList.remove('no-select');

        gestureBlock = false;

        ctx.content.style.transition = 'transform .32s var(--spring)';

        var target = 0;

        if (committed && ctx.currentX !== undefined && ctx.currentX < -ctx.maxReveal * 0.4) target = -ctx.maxReveal;

        ctx.content.style.transform = 'translateX(' + target + 'px)';

        if (target !== 0) { openSwipeContent = ctx.content; buzz(8); }

        else {

            if (openSwipeContent === ctx.content) openSwipeContent = null;

            var actionsEl = ctx.card.querySelector('.swipe-actions');

            if (actionsEl) actionsEl.classList.remove('revealing');

        }

    }



    /* ---------------- RIORDINO A PRESSIONE PROLUNGATA (ghost + placeholder) ---------------- */

    function activateReorder(ctx){

        ctx.reordering = true;

        gestureBlock = true;

        buzz(12);

        document.body.classList.add('no-select');



        var unit = ctx.dragUnit, scope = ctx.scopeEl;

        var startRect = unit.getBoundingClientRect();



        var placeholder = document.createElement('div');

        placeholder.className = 'fc-drag-placeholder';

        placeholder.style.height = startRect.height + 'px';

        scope.insertBefore(placeholder, unit);

        ctx.placeholder = placeholder;



        var ghost = document.createElement('div');

        ghost.className = 'fc-drag-ghost';

        ghost.style.left = startRect.left + 'px';

        ghost.style.top = startRect.top + 'px';

        ghost.style.width = startRect.width + 'px';

        try {

            var rc = getComputedStyle(unit).getPropertyValue('--row-color');

            if (rc) ghost.style.setProperty('--row-color', rc.trim());

        } catch(err){}

        ghost.appendChild(unit);

        document.body.appendChild(ghost);

        ctx.ghost = ghost;

        activeDrag = ctx;

    }



    function doReorderMove(ctx, e){

        var dy = e.clientY - ctx.startY;

        ctx.ghost.style.transform = 'translateY(' + dy + 'px) scale(1.02)';

        lastPointerY = e.clientY;

        ensureAutoScroll();

        updateReorderPlacement(ctx, e.clientY);

    }



    function updateReorderPlacement(ctx, pointerY){

        var scope = ctx.scopeEl;

        var items = Array.prototype.slice.call(scope.children);

        var idx = items.indexOf(ctx.placeholder);

        if (idx < 0) return;



        if (idx > 0) {

            var prev = items[idx - 1];

            var rPrev = prev.getBoundingClientRect();

            if (pointerY < rPrev.top + rPrev.height / 2) {

                flip(scope, function(){ scope.insertBefore(ctx.placeholder, prev); });

                buzz(4);

                return;

            }

        }

        if (idx < items.length - 1) {

            var next = items[idx + 1];

            var rNext = next.getBoundingClientRect();

            if (pointerY > rNext.top + rNext.height / 2) {

                flip(scope, function(){ scope.insertBefore(ctx.placeholder, next.nextSibling); });

                buzz(4);

                return;

            }

        }

    }



    function finalizeReorder(ctx){

        stopAutoScroll();

        document.body.classList.remove('no-select');

        gestureBlock = false;

        activeDrag = null;



        var scope = ctx.scopeEl, placeholder = ctx.placeholder, unit = ctx.dragUnit;

        flip(scope, function(){

            scope.insertBefore(unit, placeholder);

            placeholder.remove();

        });

        ctx.ghost.remove();

        buzz(10);

        persistReorderFromScope(unit, scope);

    }



    /* Le freccette su/giu' della vista compatta (moveCardBy) spostano le stesse
       unita' che sposta il trascinamento, e devono salvare allo stesso modo:
       una seconda funzione che scrive l'ordine sarebbe una seconda funzione da
       tenere d'accordo con il server. */
    window.fcSalvaOrdine = function(unit, scope){ persistReorderFromScope(unit, scope); };

    function persistReorderFromScope(unit, scope){

        var isGroup = unit.classList.contains('fc-group');

        var type = isGroup ? 'parent' : 'child';

        var scopeId = isGroup ? unit.getAttribute('data-category-id') : unit.getAttribute('data-parent-id');

        var fd = new FormData();

        fd.append('action', 'reorder_batch');

        fd.append('type', type);

        fd.append('scope_id', scopeId);

        Array.prototype.slice.call(scope.children).forEach(function(el){

            var id = isGroup ? el.getAttribute('data-parent-id') : el.getAttribute('data-id');

            fd.append('ordered_ids[]', id);

        });

        fetch('visualizzazione_fc.php', { method: 'POST', body: fd }).catch(function(){

            showToast(T('fc.vis_ordine_non_salvato'));

        });

    }



    /* ---------------- AUTO-SCROLL AI BORDI DELLO SCHERMO ---------------- */

    function ensureAutoScroll(){ if (!autoScrollRAF) autoScrollLoop(); }

    function autoScrollLoop(){

        var margin = 110, maxSpeed = 16;

        var vh = window.innerHeight;

        var dy = 0;

        if (lastPointerY < margin) dy = -maxSpeed * (1 - lastPointerY / margin);

        else if (lastPointerY > vh - margin) dy = maxSpeed * (1 - (vh - lastPointerY) / margin);

        if (dy && activeDrag) window.scrollBy(0, dy);

        autoScrollRAF = activeDrag ? requestAnimationFrame(autoScrollLoop) : null;

    }

    function stopAutoScroll(){ if (autoScrollRAF) { cancelAnimationFrame(autoScrollRAF); autoScrollRAF = null; } }



    /* blocco dedicato dello scroll nativo: separato dal motore pointer-events */

    document.addEventListener('touchmove', function(e){ if (gestureBlock) e.preventDefault(); }, { passive: false });

})();

</script>



<script>

/* ================================================================

   ANIMAZIONI DI INGRESSO E AVVIO PAGINA

   ================================================================ */

function playEntranceAnimations(){

    $all('.fc-group').forEach(function(group, i){

        if (i > 25) return;

        try {

            group.animate(

                [{ opacity:0, transform:'translateY(16px)' }, { opacity:1, transform:'none' }],

                { duration: 420, delay: 40 + i * 35, easing:'cubic-bezier(.22,1,.36,1)', fill:'backwards' }

            );

        } catch(e){}

    });

}



function scrollCatPathToEnd(){
    var cp = document.getElementById('catPath');
    if (cp) { cp.scrollLeft = cp.scrollWidth; }
}

function initPage(){

    fcCaricaImpostazioni();

    syncHeaderHeight();

    setTimeout(syncHeaderHeight, 250);

    filterList();

    playEntranceAnimations();

    scrollCatPathToEnd();
    setTimeout(scrollCatPathToEnd, 280);

    fcVaiAllaCartaDelFocus();

    if (window.fcvAllineaFab) { fcvAllineaFab(); setTimeout(fcvAllineaFab, 400); }

    fcLoadExampleBoxes();

    fcRiapriVisoreSeServe();

}



/* Si tornava dal modulo di modifica dopo esserci andati DAL visore a

   flashcard: si riapre il visore su quella carta, invece di lasciare a video

   l'elenco. Il ricordo e' usa e getta - una volta consumato si toglie, cosi'

   una visita successiva all'elenco non riapre il visore a sorpresa.

   L'attesa serve a fcLoadExampleBoxes(): il visore copia la sezione esempi

   com'e' in quel momento, e senza aspettare la copierebbe ancora vuota. */

function fcRiapriVisoreSeServe(){

    var ricordo = null;

    try { ricordo = sessionStorage.getItem('nisama_fcv_riapri'); } catch(e){}

    if (!ricordo) return;

    try { sessionStorage.removeItem('nisama_fcv_riapri'); } catch(e){}



    var pezzi = ricordo.split(':');

    if (pezzi.length !== 2) return;

    setTimeout(function(){

        if (window.fcvApriSuCarta) window.fcvApriSuCarta(pezzi[0], pezzi[1]);

    }, 450);

}

/* Il riquadro "N esempi" con il carosello (fcLoadExampleBoxes,
   fcDisegnaEsempio, lo swipe orizzontale, fcRenderExampleBox e le altre
   funzioni che lo compongono) e' stato estratto in examples_modal.js,
   caricato da examples_modal.php: modifica_immagine_fc.php lo usa anche
   lui, per lo stesso riquadro sotto la carta che si sta modificando. Qui
   resta solo cio' che e' specifico di QUESTA pagina: il visore a schermo
   intero che si apre toccando un riquadro gia' pieno (subito sotto). */


/* ---------- Esempio a tutto schermo ----------
   Dentro la scheda l'esempio ha poco spazio (un riquadro alto 120px che
   scorre). Toccandolo si apre qui, dove ce n'e' quanto serve. Funziona
   uguale dall'elenco e da dentro il visore a flashcard: il popup lavora sul
   box da cui e' partito il tocco, quindi non gli importa dove sia. */

var fcExBox = null;                 /* riquadro da cui e' partito il popup */

function fcExApri(box) {
    if (!fcHydrateEsempioBox(box)) return;
    fcExBox = box;
    fcExDisegna();
    var modal = document.getElementById('exModal');
    modal.classList.add('on');
    modal.setAttribute('aria-hidden', 'false');
    /* Se il visore a flashcard e' gia' aperto la pagina e' bloccata da lui:
       rifarlo qui la sbloccherebbe alla chiusura del solo popup. */
    if (!document.body.classList.contains('fcv-lock')) document.body.classList.add('ex-lock');
    if (window.buzz) buzz(8);
}

function fcExChiudi() {
    var modal = document.getElementById('exModal');
    modal.classList.remove('on');
    modal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('ex-lock');
    fcExBox = null;
}

function fcExDisegna() {
    if (!fcExBox) return;
    var esempi = fcExBox._fcExamples || [];
    var i = fcExBox._fcIndex || 0;
    if (!esempi.length) return;

    document.getElementById('exModalTitle').textContent =
        (esempi.length > 1) ? T('fc.vis_esempio_n_di', { n: i + 1, tot: esempi.length })
                            : T('fc.vis_esempio_uno');

    var body = document.getElementById('exModalBody');
    body.innerHTML = '<div class="qa-example-item">' + fcEsempioHtml(esempi[i]) + '</div>';
    body.scrollTop = 0;
    /* Dentro il visore dell'esempio l'immagine e' comunque limitata in
       altezza: toccandola si apre a tutto schermo, come gia' fa quella della
       carta. Un solo ascoltatore sul contenitore, non uno per immagine, cosi'
       vale anche per l'esempio successivo che verra' disegnato qui dentro. */
    body.onclick = function (ev) {
        var img = ev.target.closest && ev.target.closest('.qa-example-image');
        if (!img) return;
        ev.stopPropagation();
        openImageZoom(img.src);
    };
    if (typeof fcdDisegnaFormule === 'function') fcdDisegnaFormule(body);

    var nav = document.getElementById('exModalNav');
    if (esempi.length < 2) { nav.innerHTML = ''; return; }
    var html = '<button type="button" class="ex-modal-arrow" data-verso="-1" aria-label="' + fcEscapeHtml(T('fc.vis_esempio_prec')) + '">'
             + '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg></button>'
             + '<span class="qa-ex-dots">';
    for (var d = 0; d < esempi.length; d++) html += '<span class="qa-ex-dot' + (d === i ? ' active' : '') + '"></span>';
    html += '</span><button type="button" class="ex-modal-arrow" data-verso="1" aria-label="' + fcEscapeHtml(T('fc.vis_esempio_succ')) + '">'
          + '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg></button>';
    nav.innerHTML = html;
}

/* Qui le frecce fanno il giro, come lo swipe: nel popup non c'e' il riquadro
   piccolo a fare da riferimento, quindi restare bloccati ai bordi
   sembrerebbe solo un tasto rotto. Il riquadro sotto viene tenuto allineato,
   cosi' chiudendo si ritrova lo stesso esempio che si stava leggendo. */
function fcExVai(verso) {
    if (!fcExBox) return;
    var n = (fcExBox._fcExamples || []).length;
    if (n < 2) return;
    var ni = (fcExBox._fcIndex + verso + n) % n;
    fcCambiaEsempioAnimato(fcExBox, ni, verso);
    fcExDisegna();
}

(function () {
    document.addEventListener('click', function (e) {
        if (!e.target || !e.target.closest) return;

        if (e.target.closest('#exModalClose') || e.target.closest('#exModalBack')) { fcExChiudi(); return; }

        var freccia = e.target.closest('.ex-modal-arrow');
        if (freccia) { fcExVai(parseInt(freccia.dataset.verso, 10)); return; }

        /* Apertura: il tocco deve essere sul riquadro dell'esempio, non su un
           comando che ha gia' un suo significato (frecce del carosello,
           audio, immagini da ingrandire, link). */
        var box = e.target.closest('.qa-examples-box-filled');
        if (!box) return;
        /* .qa-example-image NON e' piu' esclusa: dentro il riquadro l'immagine
           e' l'esempio stesso, e toccarla non faceva niente — un esempio fatto
           di sola immagine risultava, di fatto, non apribile. L'ingrandimento
           a tutto schermo si ottiene dall'interno del popup. */
        if (e.target.closest('.qa-ex-nav, a, audio, .audio-player')) return;
        /* Fine di una sfogliata: il browser manda comunque un click, che
           altrimenti aprirebbe il popup ogni volta che si cambia esempio. */
        if (Date.now() - fcExUltimoSwipe < 400) return;
        e.stopPropagation();
        fcExApri(box);
    });

    document.addEventListener('keydown', function (e) {
        var modal = document.getElementById('exModal');
        if (!modal || !modal.classList.contains('on')) return;
        if (e.key === 'Escape') { e.preventDefault(); fcExChiudi(); }
        else if (e.key === 'ArrowLeft') { e.preventDefault(); fcExVai(-1); }
        else if (e.key === 'ArrowRight') { e.preventDefault(); fcExVai(1); }
    });
})();

/* ---------- Collega progetto (tasto nel riquadro CATEGORIA) ---------- */
/* definito PRIMA di initPage() così, anche se l'init dovesse fallire, il tasto funziona */
var FC_CURRENT_CATEGORY = <?= (int)$categoryID ?>;
window.fcOpenLinkProject = function(){
    var modal = document.getElementById('ps-modal-fclink');
    
    // 1. Controllo di sicurezza base
    if (!modal) {
        alert(T('fc.vis_errore_selettore'));
        return;
    }
    
    // 2. APERTURA FORZATA E MANUALE (Bypassa completamente window.psOpen)
    // Spostiamo il modale in fondo al body per evitare conflitti di z-index o overflow
    if (modal.parentElement !== document.body) {
        document.body.appendChild(modal);
    }
    
    // Aggiungiamo le classi CSS che rendono visibile il popup
    modal.classList.add('ps-open');
    document.documentElement.classList.add('sb-locked');
    
    // 3. Fallback per la chiusura (nel caso in cui mancasse anche window.psClose)
    var closeBtn = modal.querySelector('.ps-sheet-close');
    if (closeBtn && !closeBtn.dataset.fallbackAttached) {
        closeBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            modal.classList.remove('ps-open');
            document.documentElement.classList.remove('sb-locked');
        });
        // Evita che il listener venga aggiunto più volte
        closeBtn.dataset.fallbackAttached = "true"; 
    }
};
document.addEventListener('projectselected', function(e){
    if (!e.detail || e.detail.uid !== 'fclink') return;
    var projectId = e.detail.id;
    if (!projectId || FC_CURRENT_CATEGORY === 0) return;
    fetch('visualizzazione_fc.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=fc_link_project&category_id=' + encodeURIComponent(FC_CURRENT_CATEGORY) + '&project_id=' + encodeURIComponent(projectId)
    })
    .then(function(r){ return r.json(); })
    .then(function(data){
        if (data && data.success) {
            if (window.showToast) showToast(data.created ? 'Progetto collegato: creato "Flashcards"' : 'Categoria collegata al progetto');
            setTimeout(function(){ window.location.reload(); }, 500);
        } else {
            if (window.showToast) showToast((data && data.error) ? data.error : 'Errore nel collegamento');
        }
    })
    .catch(function(){ if (window.showToast) showToast('Errore di rete'); });
});

if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', initPage); }

else { initPage(); }

</script>

<?php
    // Selettore progetto per il tasto "Collega progetto" (nessun trigger visibile:
    // lo apriamo noi da JS). Alla scelta creiamo un sotto-progetto "Flashcards".
    $ps_id = 'fclink'; $ps_selected_id = 0; $ps_selected_name = ''; $ps_selected_color = null;
    $ps_placeholder = 'Seleziona progetto'; $ps_title = 'Collega a un progetto';
    $ps_show_all_option = false; $ps_allow_create = true; $ps_render_trigger = false;
    $ps_hidden_name = 'fc_link_project_id'; $ps_exclude_id = 0;
    include 'project_selector.php';
?>

</body>

</html>