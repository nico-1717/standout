<?php

require_once 'config.php';
require_once 'auth_check.php';
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
                  : sec_one("SELECT * FROM fc_child_cards WHERE id = ?", [$id]))
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
                ? sec_one("SELECT id, custom_order FROM fc_child_cards WHERE parent_id = ? AND custom_order < ? ORDER BY custom_order DESC LIMIT 1", [$scope, $current_order])
                : sec_one("SELECT id, custom_order FROM fc_child_cards WHERE parent_id = ? AND custom_order > ? ORDER BY custom_order ASC LIMIT 1", [$scope, $current_order]);
        }
        if ($adjacent) {
            $adj_id = (int)$adjacent['id'];
            $adj_order = (int)$adjacent['custom_order'];
            if ($padre) {
                sec_q("UPDATE fc_images SET custom_order = ? WHERE id = ?", [$adj_order, $id]);
                sec_q("UPDATE fc_images SET custom_order = ? WHERE id = ?", [$current_order, $adj_id]);
            } else {
                sec_q("UPDATE fc_child_cards SET custom_order = ? WHERE id = ?", [$adj_order, $id]);
                sec_q("UPDATE fc_child_cards SET custom_order = ? WHERE id = ?", [$current_order, $adj_id]);
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
                sec_q("UPDATE fc_child_cards SET custom_order = ? WHERE id = ? AND parent_id = ?", [$pos, $oid, $scope_id]);
            }
            $pos++;
        }
        $ok = true;
    }

    echo json_encode(['success' => $ok]);

    exit;

}



// ==========================================

// GESTIONE DELLE AZIONI DI MASSA E JOIN/UNJOIN (invariata)

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
            sec_q("DELETE FROM fc_child_cards WHERE id IN ($ph)", $figli);
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
            $row = sec_one("SELECT * FROM fc_child_cards WHERE id = ?", [$child_id]);
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
            sec_q("DELETE FROM fc_child_cards WHERE id = ?", [$child_id]);
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



$cat_query = sec_all("SELECT id, name, color, parent_id FROM fc_categories WHERE user_id = ? ORDER BY name", [$current_user_id]);
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
    foreach (sec_all("SELECT * FROM fc_child_cards WHERE parent_id IN ($ids_ph) ORDER BY custom_order ASC",
                     array_map('intval', $ids_array)) as $c) {

        $images_data[$c['parent_id']]['children'][] = $c;

    }

}



// Conteggio esempi reali (fc_examples usa id positivi per i padri e negativi per i figli, come in game_fc.php)

$examples_counts = [];

$lookup_ids = $ids_array;

foreach ($images_data as $row) {

    foreach ($row['children'] as $child) { $lookup_ids[] = -1 * intval($child['id']); }

}

if (count($lookup_ids) > 0) {

        $lookup_ph = implode(',', array_fill(0, count($lookup_ids), '?'));
    foreach (sec_all("SELECT fc_image_id, COUNT(*) AS c FROM fc_examples WHERE fc_image_id IN ($lookup_ph) GROUP BY fc_image_id",
                     array_map('intval', $lookup_ids)) as $ex) {
        $examples_counts[(int)$ex['fc_image_id']] = (int)$ex['c'];
    }

}



// Puo' il riordino dei PADRI essere attivato? Solo con ordinamento personalizzato e su una categoria precisa

// (in "Tutte le Categorie" l'ordine custom_order non e' comparabile tra categorie diverse).

$parent_reorder_enabled = ($sort === 'custom_asc' && $categoryID != 0);

$child_reorder_enabled = ($sort === 'custom_asc');



// Sezione "Examples": visibile solo se esistono davvero esempi per quella carta

function render_examples_section($realId, $type, $lookupId, $examples_counts) {

    $count = isset($examples_counts[$lookupId]) ? $examples_counts[$lookupId] : 0;

    if ($count <= 0) return;

    ?>

    <div class="qa-examples">

        <div class="qa-label"><?php te('fc.vis_n_esempi', ['n' => (int)$count]); ?></div>

        <div class="qa-examples-box" data-fc-id="<?= (int)$realId ?>" data-fc-type="<?= sec_h($type) ?>"></div>

    </div>

    <?php

}



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

function render_qa_side($label, $text, $image, $audio) {

    $text = trim((string)$text);

    $image = trim((string)$image);

    $audio = trim((string)$audio);

    $hasText = $text !== '';

    $hasImage = $image !== '';

    $hasAudio = $audio !== '';

    $contentClass = ($hasText && $hasImage) ? 'qa-split' : 'qa-solo';

    ?>

    <div class="qa-side">

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



<?php echo nisama_i18n_json(['comune', 'fc', 'vocab']); ?>
<script>

    window.MathJax = {

    tex: {

        inlineMath: [['$', '$'], ['\\(', '\\)']],

        displayMath: [['$$', '$$'], ['\\[', '\\]']]

    }

    };

</script>

<script id="MathJax-script" async src="https://cdn.jsdelivr.net/npm/mathjax@3/es5/tex-mml-chtml.js"></script>

<script src="fc_parser.js"></script>



<link rel="stylesheet" href="<?php echo PWA_BASE; ?>visualizzazione_fc.css?v=<?php echo @filemtime(dirname(__FILE__) . '/visualizzazione_fc.css') ?: 1; ?>">

</head>

<body>



<div id="image-overlay">

    <div id="close-overlay" onclick="closeImageZoom()">&#10005;</div>

    <img id="overlay-img" src="" alt="">

</div>



<header class="app-header" id="appHeader">

    <div class="header-top">

        <div class="cat-pill" id="catPill" role="link" tabindex="0" onclick="window.location.href='categories_fc.php'">

            <span class="cat-pill-dot"></span>

            <div class="cat-pill-text">

                <div class="cat-pill-label"><?php te('vocab.cat_categoria'); ?></div>

                <div class="cat-pill-name"><?= sec_h($current_cat_name) ?></div>
                <?php if ($categoryID != 0): ?>
                    <?php if ($fc_linked_project): ?>
                    <button type="button" class="cat-proj-chip<?= !empty($fc_linked_project['via_ancestor']) ? ' via-ancestor' : '' ?>"
                            style="--cp-color:<?= sec_h($fc_linked_project['color']) ?>;"
                            onclick="event.stopPropagation(); window.location.href='progetti.php?id=<?= (int)$fc_linked_project['id'] ?>';">
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

        <a href="inserimento_fc.php?category_id=<?= sec_h($categoryID) ?>" class="icon-btn quick-add-btn" aria-label="<?php te('fc.vis_aggiungi_fc'); ?>" title="<?php te('fc.vis_aggiungi_in_cat'); ?>">

            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>

        </a>

        <?php if ($categoryID != 0): ?>
        <a href="categories_game_fc.php?preselect_category=<?= sec_h($categoryID) ?>" class="icon-btn play-cat-btn" aria-label="<?php te('vocab.vi_gioca'); ?>" title="Gioca con questa categoria">
            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M8 5.14v13.72a1 1 0 0 0 1.53.85l11-6.86a1 1 0 0 0 0-1.7l-11-6.86A1 1 0 0 0 8 5.14Z"/></svg>
        </a>
        <a href="big_edit_fc.php?category_id=<?= sec_h($categoryID) ?>" class="icon-btn big-edit-btn" aria-label="<?php te('vocab.vi_big_edit'); ?>" title="Big Edit">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
        </a>
        <?php endif; ?>

    </div>



    <div class="toolbar-row" id="normalToolbar">

        <div class="search-wrap">

            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>

            <input id="filterText" class="search-input" type="text" placeholder="<?php te('fc.vis_cerca'); ?>" oninput="visibleLimit=30; filterList()">

        </div>

        <div class="tb-select-wrap">

            <select id="filterSort" class="tb-select" onchange="changeSort()">

                <option value="custom_asc" <?= $sort == 'custom_asc' ? 'selected' : '' ?>><?php te('fc.vis_posizione'); ?></option>

                <option value="id_desc" <?= $sort == 'id_desc' ? 'selected' : '' ?>><?php te('fc.vis_id_recenti'); ?></option>

                <option value="id_asc" <?= $sort == 'id_asc' ? 'selected' : '' ?>><?php te('fc.vis_id_meno_recenti'); ?></option>

                <option value="q_asc" <?= $sort == 'q_asc' ? 'selected' : '' ?>><?php te('fc.vis_domanda_az'); ?></option>

            </select>

        </div>

        <div class="tb-select-wrap">

            <select id="filterStatus" class="tb-select" onchange="visibleLimit=30; filterList()">

                <option value="all"><?php te('fc.vis_tutte_carte'); ?></option>

                <option value="not_learnt"><?php te('vocab.vi_da_imparare'); ?></option>

                <option value="learnt"><?php te('vocab.vi_imparate'); ?></option>

                <option value="duplicates"><?php te('vocab.vi_duplicati'); ?></option>

            </select>

        </div>

        <button type="button" class="select-mode-btn" id="selectModeBtn" onclick="enterSelectMode()">

            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m9 12 2 2 4-4"/><rect x="3" y="3" width="18" height="18" rx="6"/></svg>

            <?php te('vocab.vi_seleziona'); ?>

        </button>

    </div>



    <div class="select-toolbar" id="selectToolbar" style="display:none;">

        <button type="button" class="st-cancel" onclick="exitSelectMode()" aria-label="<?php te('fc.vis_annulla_sel'); ?>">

            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>

        </button>

        <button type="button" class="st-selectall" id="selectAllBtn" onclick="toggleSelectAll()"><?php te('vocab.vi_tutte'); ?></button>

        <div class="st-count" id="selectCount">0</div>

        <div class="st-actions" id="stActions">

            <button type="button" class="st-action" data-action="move" onclick="onBulkActionPick('move')">

                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><path d="M21 8V6a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h6"/><path d="M17 22v-6M14 19h6"/></svg>

                <?php te('vocab.cat_sposta_attr'); ?>

            </button>

            <button type="button" class="st-action" data-action="join" onclick="onBulkActionPick('join')">

                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><circle cx="7" cy="12" r="3"/><circle cx="17" cy="6" r="3"/><circle cx="17" cy="18" r="3"/><path d="M9.6 10.5 14.5 7.7M9.6 13.5l4.9 2.8"/></svg>

                <?php te('vocab.vi_unisci'); ?>

            </button>

            <button type="button" class="st-action" data-action="unjoin" onclick="onBulkActionPick('unjoin')">

                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><circle cx="6" cy="6" r="3"/><circle cx="6" cy="18" r="3"/><path d="M20 4 8.12 15.88M14.47 14.48 20 20M8.12 8.12 12 12"/></svg>

                <?php te('vocab.vi_separa'); ?>

            </button>

            <button type="button" class="st-action danger" data-action="delete" onclick="onBulkActionPick('delete')">

                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0-1 14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2L4 6"/></svg>

                <?php te('comune.elimina'); ?>

            </button>

        </div>

    </div>



    <div class="join-banner" id="joinBanner" style="display:none;">

        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>

        <?php te('fc.vis_tocca_padre'); ?>

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

            <input type="hidden" name="bulk_action" id="hiddenBulkAction">

            <input type="hidden" name="target_category" id="hiddenTargetCategory">



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



                            <?php render_qa_side(T('fc.ifc_domanda'), $row['question_desc'], $row['question_image'], $row['question_audio']); ?>

                            <?php render_qa_side(T('fc.ifc_risposta'), $row['answer_desc'], $row['answer_image'], $row['answer_audio']); ?>



                            <?php render_examples_section($row['id'], 'parent', $row['id'], $examples_counts); ?>

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

                                    <div class="child-tag"><span>↳</span> Sotto-carta</div>

                                    <label class="select-circle">

                                        <input type="checkbox" name="selected_child_ids[]" value="<?= (int)$child['id'] ?>" class="bulk-checkbox child-cb record-cb" onchange="onRecordCbChange(this)">

                                        <span class="cc-visual"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span>

                                    </label>

                                    <div class="card-head-spacer"></div>

                                </div>



                                <?php render_qa_side(T('fc.ifc_domanda'), $child['question_desc'], $child['question_image'], $child['question_audio']); ?>

                                <?php render_qa_side(T('fc.ifc_risposta'), $child['answer_desc'], $child['answer_image'], $child['answer_audio']); ?>



                                <?php render_examples_section($child['id'], 'child', -1 * intval($child['id']), $examples_counts); ?>

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

    </div>

</main>



<div class="confirm-bar" id="joinConfirmBar">

    <button type="button" class="cb-btn" id="joinConfirmBtn" disabled onclick="confirmJoinExecution()"><?php te('fc.vis_confermo_padre'); ?></button>

</div>



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



<div class="sheet-overlay" id="moveSheet">

    <div class="sheet-panel">

        <h3><?php te('fc.vis_sposta_dove'); ?></h3>

        <p class="sheet-sub"><?php te('fc.vis_sposta_txt'); ?></p>

        <div class="cat-pick-list" id="moveCatList">

            <?php foreach ($categories_list as $c): ?>

                <button type="button" class="cat-pick-item" onclick="pickMoveCategory(<?= (int)$c['id'] ?>)">

                    <span class="cat-pick-dot" style="background:<?= sec_h(fc_color_or_default($c['color'])) ?>"></span>

                    <span class="cat-pick-name"><?= sec_h($c['name']) ?></span>

                </button>

            <?php endforeach; ?>

        </div>

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

function openImageZoom(src){

    document.getElementById('overlay-img').src = src;

    document.getElementById('image-overlay').style.display = 'flex';

}

function closeImageZoom(){

    document.getElementById('image-overlay').style.display = 'none';

}



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

        if (selecting && (actionFilter === 'unjoin') && !isFather && !isChild) byStatus = false;
        if (!byStatus) return false;

        const searchText = card.getAttribute('data-search') || '';
        return searchText.indexOf(textFilter) !== -1;
    }

    let visibleCards = 0;
    let matchedGroups = 0;
    const isTutteLeMaterie = <?php echo $categoryID == 0 ? 'true' : 'false'; ?>;

    $all('.fc-group').forEach(function(group){
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
}

</script>



<script>

/* ================================================================

   SELECT MODE / AZIONI DI MASSA

   ================================================================ */

function enterSelectMode(){

    document.body.classList.add('select-mode');

    document.getElementById('normalToolbar').style.display = 'none';

    document.getElementById('selectToolbar').style.display = 'flex';

    buzz(8);

    updateSelectCount();

    filterList();

}



function exitSelectMode(){

    document.body.classList.remove('select-mode', 'action-join', 'action-unjoin');

    document.body.dataset.bulkAction = '';

    document.getElementById('normalToolbar').style.display = 'flex';

    document.getElementById('selectToolbar').style.display = 'none';

    document.getElementById('joinBanner').style.display = 'none';

    document.getElementById('joinConfirmBar').style.display = 'none';

    document.getElementById('stActions').style.display = 'flex';

    document.getElementById('selectAllBtn').style.display = '';

    $all('.st-action').forEach(function(b){ b.classList.remove('active-pick'); });

    restoreFatherRadiosToCheckboxes();

    $all('.record-cb').forEach(function(cb){ cb.checked = false; });

    $all('.fc-card').forEach(function(c){ c.classList.remove('selected-row'); });

    filterList();

}



function restoreFatherRadiosToCheckboxes(){

    $all('.father-radio').forEach(function(radio){

        radio.outerHTML = "<input type='checkbox' name='selected_ids[]' value='" + radio.value + "' class='bulk-checkbox standalone-cb record-cb' onchange='onRecordCbChange(this)'>";

    });

}



function updateSelectCount(){

    const n = $all('.record-cb:checked').length;

    const el = document.getElementById('selectCount');

    if (el) el.textContent = n;

    const joinBtn = document.getElementById('joinConfirmBtn');

    if (joinBtn) joinBtn.disabled = !document.querySelector('.father-radio:checked');

}



function onRecordCbChange(cb){

    const card = cb.closest('.fc-card');

    if (card) card.classList.toggle('selected-row', cb.checked);

    updateSelectCount();

    if (document.body.classList.contains('select-mode')) filterList();

}



function toggleSelectAll(){

    const boxes = $all('.record-cb').filter(function(cb){

        const card = cb.closest('.fc-card');

        return card && card.style.display !== 'none';

    });

    const allChecked = boxes.length > 0 && boxes.every(function(cb){ return cb.checked; });

    boxes.forEach(function(cb){

        cb.checked = !allChecked;

        const card = cb.closest('.fc-card');

        if (card) card.classList.toggle('selected-row', cb.checked);

    });

    document.getElementById('selectAllBtn').textContent = allChecked ? 'Tutte' : T('vocab.vi_nessuna');

    updateSelectCount();

}



function onBulkActionPick(action){

    const totalChecked = $all('.record-cb:checked').length;

    if (totalChecked === 0) { showToast(T('fc.vis_serve_carta')); return; }

    $all('.st-action').forEach(function(b){ b.classList.toggle('active-pick', b.dataset.action === action); });



    if (action === 'delete') {

        openDeleteConfirm(totalChecked, '', function(){ submitBulkForm('delete'); });

        return;

    }

    if (action === 'move') {

        document.getElementById('moveSheet').style.display = 'flex';

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

        const checkedFatherExists = $all('.standalone-cb:checked').some(function(cb){

            const group = cb.closest('.fc-group');

            return group && group.querySelector('.fc-children .fc-card');

        });

        if (checkedFatherExists) { showToast(T('fc.vis_no_padre_con_figli')); return; }

        if ($all('.child-cb:checked').length > 0) { showToast(T('fc.vis_no_figlie')); return; }



        document.body.dataset.bulkAction = 'join';

        document.body.classList.add('action-join');

        buzz(10);

        document.getElementById('joinBanner').style.display = 'flex';

        document.getElementById('stActions').style.display = 'none';

        document.getElementById('selectAllBtn').style.display = 'none';

        document.getElementById('joinConfirmBar').style.display = 'block';



        $all('.standalone-cb').forEach(function(cb){

            if (!cb.checked) {

                cb.outerHTML = "<input type='radio' name='father_id' value='" + cb.value + "' class='father-radio' onchange='updateSelectCount()'>";

            }

        });

        filterList();

    }

}



function confirmJoinExecution(){

    if (!document.querySelector('.father-radio:checked')) { showToast(T('fc.vis_serve_padre')); return; }

    submitBulkForm('join');

}



function submitBulkForm(actionValue){

    document.getElementById('hiddenBulkAction').value = actionValue;

    document.getElementById('bulkForm').submit();

}



function pickMoveCategory(categoryId){

    document.getElementById('hiddenTargetCategory').value = categoryId;

    closeSheet('moveSheet');

    submitBulkForm('move');

}



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

document.getElementById('moveSheet').addEventListener('click', function(e){ if (e.target === this) closeSheet('moveSheet'); });



/* ================================================================

   AZIONI SWIPE: MODIFICA / ELIMINA (singola carta)

   ================================================================ */

function goEditCard(btn){

    const card = btn.closest('.fc-card');

    const url = card && card.getAttribute('data-edit-url');

    if (url) window.location.href = url;

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

        return !!(el.closest && el.closest('button, a, input, select, textarea, audio, .ap-track, .audio-player'));

    }

    function getTranslateX(el){

        var m = el.style.transform.match(/translateX\((-?\d+(?:\.\d+)?)px\)/);

        return m ? parseFloat(m[1]) : 0;

    }

    function closeOpenSwipe(){

        if (openSwipeContent) {

            openSwipeContent.style.transition = 'transform .3s var(--spring)';

            openSwipeContent.style.transform = 'translateX(0px)';

            openSwipeContent = null;

        }

    }

    window.resetSwipeCard = function(card){

        var content = card && card.querySelector('.swipe-content');

        if (!content) return;

        content.style.transition = 'transform .3s var(--spring)';

        content.style.transform = 'translateX(0px)';

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

        else if (openSwipeContent === ctx.content) openSwipeContent = null;

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

    syncHeaderHeight();

    setTimeout(syncHeaderHeight, 250);

    filterList();

    playEntranceAnimations();

    scrollCatPathToEnd();
    setTimeout(scrollCatPathToEnd, 280);

}

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