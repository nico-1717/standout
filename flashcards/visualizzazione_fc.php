<?php

require_once 'config.php';

require_once 'auth_check.php';

$conn = mysqli_connect(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);



// Prima qui si leggeva la lista di TUTTI gli utenti per poterli scegliere da un menu:

// ora l'utente è sempre e solo quello che ha fatto login, niente più menu.

$current_user_id = $utente_corrente['id'];


// ==========================================
// AJAX: collega la categoria corrente a un progetto creando un sotto-progetto
// "Flashcards" sotto al progetto scelto (richiesta: tasto "collega progetto").
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'fc_link_project') {
    header('Content-Type: application/json');
    $catId = intval($_POST['category_id'] ?? 0);
    $projectId = intval($_POST['project_id'] ?? 0);

    // la categoria deve esistere ed essere dell'utente
    $chkCat = mysqli_query($conn, "SELECT id FROM fc_categories WHERE id = $catId AND user_id = $current_user_id");
    if (!$chkCat || mysqli_num_rows($chkCat) === 0) { echo json_encode(['success' => false, 'error' => 'Categoria non valida']); exit(); }
    // il progetto scelto deve esistere ed essere dell'utente
    $chkPrj = mysqli_query($conn, "SELECT id, color, color_edited FROM projects WHERE id = $projectId AND user_id = $current_user_id");
    $prjRow = $chkPrj ? mysqli_fetch_assoc($chkPrj) : null;
    if (!$prjRow) { echo json_encode(['success' => false, 'error' => 'Progetto non valido']); exit(); }

    // colore ereditato dal progetto scelto (catena dei padri, come fa project_selector)
    if (!function_exists('fc_resolve_project_color')) {
        function fc_resolve_project_color($conn, $parentId, $userId) {
            $parentId = intval($parentId); $guard = 0;
            while ($parentId > 0 && $guard < 50) {
                $guard++;
                $res = mysqli_query($conn, "SELECT parent_id, color, color_edited FROM projects WHERE id = $parentId AND user_id = " . intval($userId));
                $row = $res ? mysqli_fetch_assoc($res) : null;
                if (!$row) break;
                if (intval($row['color_edited']) === 1 && !empty($row['color'])) return $row['color'];
                $parentId = intval($row['parent_id']);
            }
            return '#8E8E93';
        }
    }
    $newColor = (intval($prjRow['color_edited']) === 1 && !empty($prjRow['color'])) ? $prjRow['color'] : fc_resolve_project_color($conn, $projectId, $current_user_id);
    $newColorEsc = mysqli_real_escape_string($conn, $newColor);

    // Prima di creare, controlla se esiste già un progetto "Flashcards" da riutilizzare:
    // 1) il progetto cliccato è già chiamato "Flashcards" -> lega quello
    // 2) ha un sotto-progetto chiamato "Flashcards" -> lega quello
    // 3) altrimenti crea un nuovo sotto-progetto "Flashcards"
    $targetProjectId = 0;
    $targetName = 'Flashcards';

    $selfName = '';
    $selfRes = mysqli_query($conn, "SELECT name FROM projects WHERE id = $projectId AND user_id = $current_user_id");
    if ($selfRes && ($sr = mysqli_fetch_assoc($selfRes))) { $selfName = $sr['name']; }

    if (mb_strtolower(trim($selfName)) === 'flashcards') {
        // il progetto scelto è già "Flashcards"
        $targetProjectId = $projectId;
        $targetName = $selfName;
    } else {
        // cerca un sotto-progetto diretto chiamato "Flashcards"
        $childRes = mysqli_query($conn, "SELECT id, name FROM projects WHERE user_id = $current_user_id AND parent_id = $projectId AND LOWER(name) = 'flashcards' LIMIT 1");
        if ($childRes && ($cr = mysqli_fetch_assoc($childRes))) {
            $targetProjectId = (int)$cr['id'];
            $targetName = $cr['name'];
        }
    }

    if ($targetProjectId === 0) {
        // nessuno esistente: crea il sotto-progetto "Flashcards"
        $ordRes = mysqli_query($conn, "SELECT COALESCE(MAX(custom_order), -1) + 1 AS next_order FROM projects WHERE user_id = $current_user_id AND parent_id = $projectId");
        $nextOrder = $ordRes ? intval(mysqli_fetch_assoc($ordRes)['next_order']) : 0;
        mysqli_query($conn, "INSERT INTO projects (user_id, parent_id, name, color, color_edited, status, custom_order, created_at)
                              VALUES ($current_user_id, $projectId, 'Flashcards', '$newColorEsc', 0, 'in_progress', $nextOrder, NOW())");
        $targetProjectId = mysqli_insert_id($conn);
        if ($targetProjectId <= 0) { echo json_encode(['success' => false, 'error' => 'Creazione non riuscita']); exit(); }
        $created = true;
    } else {
        $created = false;
    }

    // collega il progetto (nuovo o riutilizzato) a questa categoria (evita doppioni)
    mysqli_query($conn, "DELETE FROM project_fc_categories WHERE fc_category_id = $catId AND project_id IN (SELECT id FROM projects WHERE user_id = $current_user_id)");
    mysqli_query($conn, "INSERT INTO project_fc_categories (project_id, fc_category_id) VALUES ($targetProjectId, $catId)");

    echo json_encode(['success' => true, 'project_id' => $targetProjectId, 'name' => $targetName, 'color' => $newColor, 'created' => $created]);
    exit();
}



// ==========================================

// GESTIONE SWAP POSIZIONI (LEGACY - non piu' usata dalla UI, tenuta per compatibilita')

// ==========================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] == 'swap_order') {

    $id = intval($_POST['id']);

    $type = $_POST['type']; // 'parent' o 'child'

    $dir = $_POST['direction']; // 'up' o 'down'



    $table = ($type == 'parent') ? 'fc_images' : 'fc_child_cards';
    $id_col = 'id'; // Standardizzato a 'id' per entrambe le tabelle
    
    $res = mysqli_query($conn, "SELECT * FROM $table WHERE $id_col = $id");

    $current = mysqli_fetch_assoc($res);

    

    if ($current) {

        $current_order = intval($current['custom_order']);

        $where = ($type == 'parent') ? "category_id = " . intval($current['category_id']) : "parent_id = " . intval($current['parent_id']);



        if ($dir == 'up') {

            $q_adj = "SELECT $id_col, custom_order FROM $table WHERE $where AND custom_order < $current_order ORDER BY custom_order DESC LIMIT 1";

        } else {

            $q_adj = "SELECT $id_col, custom_order FROM $table WHERE $where AND custom_order > $current_order ORDER BY custom_order ASC LIMIT 1";

        }

        

        $adj_res = mysqli_query($conn, $q_adj);

        $adjacent = mysqli_fetch_assoc($adj_res);

        

        if ($adjacent) {

            $adj_id = $adjacent[$id_col];

            $adj_order = $adjacent['custom_order'];

            mysqli_query($conn, "UPDATE $table SET custom_order = $adj_order WHERE $id_col = $id");

            mysqli_query($conn, "UPDATE $table SET custom_order = $current_order WHERE $id_col = $adj_id");

        }

    }

    exit;

}



// ==========================================

// NUOVO: RIORDINO CONTINUO (drag & drop) — riscrive l'ordine di un intero gruppo

// ==========================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] == 'reorder_batch') {

    header('Content-Type: application/json');

    $type = isset($_POST['type']) ? $_POST['type'] : '';

    $scope_id = isset($_POST['scope_id']) ? intval($_POST['scope_id']) : -1;

    $ordered_ids = (isset($_POST['ordered_ids']) && is_array($_POST['ordered_ids'])) ? $_POST['ordered_ids'] : [];



    $ok = false;

    if (count($ordered_ids) > 0 && $scope_id >= 0) {

        if ($type === 'parent') {
            $table = 'fc_images'; $id_col = 'id'; $scope_col = 'category_id';
        } elseif ($type === 'child') {
            $table = 'fc_child_cards'; $id_col = 'id'; $scope_col = 'parent_id';
        } else {
            $table = null;
        }



        if ($table) {

            $pos = 0;

            foreach ($ordered_ids as $oid) {

                $oid = intval($oid);

                mysqli_query($conn, "UPDATE $table SET custom_order = $pos WHERE $id_col = $oid AND $scope_col = $scope_id");

                $pos++;

            }

            $ok = true;

        }

    }

    echo json_encode(['success' => $ok]);

    exit;

}



// ==========================================

// GESTIONE DELLE AZIONI DI MASSA E JOIN/UNJOIN (invariata)

// ==========================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {

    $action = $_POST['bulk_action'];

    $cat_param = isset($_GET['category_id']) ? "?category_id=" . intval($_GET['category_id']) . "&user_id=$current_user_id" : "?user_id=$current_user_id";

    

    if ($action === 'delete') {
        if (isset($_POST['selected_ids']) && is_array($_POST['selected_ids'])) {
            $ids_str = implode(',', array_map('intval', $_POST['selected_ids']));
            mysqli_query($conn, "DELETE FROM fc_images WHERE id IN ($ids_str)");
        }
        if (isset($_POST['selected_child_ids']) && is_array($_POST['selected_child_ids'])) {
            $c_ids_str = implode(',', array_map('intval', $_POST['selected_child_ids']));
            mysqli_query($conn, "DELETE FROM fc_child_cards WHERE id IN ($c_ids_str)");
        }
    } 
    elseif ($action === 'move' && isset($_POST['target_category']) && isset($_POST['selected_ids']) && is_array($_POST['selected_ids'])) {
        $target_cat = intval($_POST['target_category']);
        $ids_str = implode(',', array_map('intval', $_POST['selected_ids']));
        mysqli_query($conn, "UPDATE fc_images SET category_id = $target_cat WHERE id IN ($ids_str)");
    } 
    elseif ($action === 'join' && isset($_POST['father_id']) && isset($_POST['selected_ids'])) {
        $father_id = intval($_POST['father_id']);
        
        $max_c = mysqli_query($conn, "SELECT MAX(custom_order) as m FROM fc_child_cards WHERE parent_id = $father_id");
        $c_order = intval(mysqli_fetch_assoc($max_c)['m']) + 1;

        foreach ($_POST['selected_ids'] as $child_id) {
            $child_id = intval($child_id);
            if ($child_id == $father_id) continue;
            
            $res = mysqli_query($conn, "SELECT * FROM fc_images WHERE id = $child_id");
            if ($row = mysqli_fetch_assoc($res)) {
                $qd = mysqli_real_escape_string($conn, $row['question_desc']);
                $qi = mysqli_real_escape_string($conn, $row['question_image']);
                $qa = mysqli_real_escape_string($conn, $row['question_audio']);
                $ad = mysqli_real_escape_string($conn, $row['answer_desc']);
                $ai = mysqli_real_escape_string($conn, $row['answer_image']);
                $aa = mysqli_real_escape_string($conn, $row['answer_audio']);
                
                mysqli_query($conn, "INSERT INTO fc_child_cards (parent_id, question_desc, question_image, question_audio, answer_desc, answer_image, answer_audio, custom_order) VALUES ($father_id, '$qd', '$qi', '$qa', '$ad', '$ai', '$aa', $c_order)");
                mysqli_query($conn, "DELETE FROM fc_images WHERE id = $child_id");
                $c_order++;
            }
        }
        mysqli_query($conn, "UPDATE fc_images SET is_father = 1 WHERE id = $father_id");
    } 
    elseif ($action === 'unjoin' && isset($_POST['selected_child_ids'])) {
        $parents_to_check = [];
        foreach ($_POST['selected_child_ids'] as $child_id) {
            $child_id = intval($child_id);
            $res = mysqli_query($conn, "SELECT * FROM fc_child_cards WHERE id = $child_id");
            if ($row = mysqli_fetch_assoc($res)) {
                $parent_id = $row['parent_id'];
                $parents_to_check[$parent_id] = true;
                
                $p_res = mysqli_query($conn, "SELECT category_id FROM fc_images WHERE id = $parent_id");
                $p_row = mysqli_fetch_assoc($p_res);
                $cat_id = $p_row ? $p_row['category_id'] : 0;

                $max_p = mysqli_query($conn, "SELECT MAX(custom_order) as m FROM fc_images WHERE category_id = $cat_id");
                $p_order = intval(mysqli_fetch_assoc($max_p)['m']) + 1;
                
                $qd = mysqli_real_escape_string($conn, $row['question_desc']);
                $qi = mysqli_real_escape_string($conn, $row['question_image']);
                $qa = mysqli_real_escape_string($conn, $row['question_audio']);
                $ad = mysqli_real_escape_string($conn, $row['answer_desc']);
                $ai = mysqli_real_escape_string($conn, $row['answer_image']);
                $aa = mysqli_real_escape_string($conn, $row['answer_audio']);
                
                mysqli_query($conn, "INSERT INTO fc_images (question_desc, question_image, question_audio, answer_desc, answer_image, answer_audio, category_id, is_father, custom_order) VALUES ('$qd', '$qi', '$qa', '$ad', '$ai', '$aa', $cat_id, 0, $p_order)");
                mysqli_query($conn, "DELETE FROM fc_child_cards WHERE id = $child_id");
            }
        }
        foreach(array_keys($parents_to_check) as $pid) {
            $c_res = mysqli_query($conn, "SELECT COUNT(*) as c FROM fc_child_cards WHERE parent_id = $pid");
            if (mysqli_fetch_assoc($c_res)['c'] == 0) {
                mysqli_query($conn, "UPDATE fc_images SET is_father = 0 WHERE id = $pid");
            }
        }
    }

    header("Location: visualizzazione_fc.php" . $cat_param);

    exit;

}



$categoryID = isset($_GET['category_id']) ? (int)$_GET['category_id'] : 0;



$cat_query = mysqli_query($conn, "SELECT id, name, color, parent_id FROM fc_categories WHERE user_id = $current_user_id ORDER BY name");
$categories_list = [];

$current_cat_name = 'Tutte le Categorie';

$current_cat_color = '#5E5CE6'; // Colore neutro di default (vista "tutte le categorie")



while ($c = mysqli_fetch_assoc($cat_query)) { 

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

        $subcat_ids_str = implode(',', $subcat_ids);



        // Carte "padre" o singole, direttamente dentro ciascuna sottocategoria

        $image_to_subcat = [];

        $all_image_ids_in_subcats = [];

        $res_sc_images = mysqli_query($conn, "SELECT id, category_id FROM fc_images WHERE category_id IN ($subcat_ids_str)");

        while ($r = mysqli_fetch_assoc($res_sc_images)) {

            $cid = (int)$r['category_id'];

            $subcat_card_counts[$cid]++;

            $image_to_subcat[(int)$r['id']] = $cid;

            $all_image_ids_in_subcats[] = (int)$r['id'];

        }



        // Carte "figlie" agganciate ai padri di quelle sottocategorie

        if (!empty($all_image_ids_in_subcats)) {

            $img_ids_str = implode(',', $all_image_ids_in_subcats);

            $res_sc_children = mysqli_query($conn, "SELECT parent_id, COUNT(*) as c FROM fc_child_cards WHERE parent_id IN ($img_ids_str) GROUP BY parent_id");

            while ($r = mysqli_fetch_assoc($res_sc_children)) {

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
        $fc_count_ids_str = implode(',', array_map('intval', $fc_count_cat_ids));
        $r_cnt = mysqli_query($conn, "SELECT COUNT(*) AS c FROM fc_images WHERE category_id IN ($fc_count_ids_str)");
        if ($r_cnt && ($row_cnt = mysqli_fetch_assoc($r_cnt))) { $fc_total_cards += (int)$row_cnt['c']; }
        $r_img = mysqli_query($conn, "SELECT id FROM fc_images WHERE category_id IN ($fc_count_ids_str)");
        $img_ids = [];
        while ($r_img && ($ri = mysqli_fetch_assoc($r_img))) { $img_ids[] = (int)$ri['id']; }
        if (!empty($img_ids)) {
            $img_ids_str2 = implode(',', $img_ids);
            $r_ch = mysqli_query($conn, "SELECT COUNT(*) AS c FROM fc_child_cards WHERE parent_id IN ($img_ids_str2)");
            if ($r_ch && ($row_ch = mysqli_fetch_assoc($r_ch))) { $fc_total_cards += (int)$row_ch['c']; }
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
        $chain_str = implode(',', array_map('intval', $fc_chain));
        $lpRes = mysqli_query($conn, "SELECT pfc.fc_category_id, p.id AS pid, p.name AS pname, p.color AS pcolor, pp.name AS parent_name
                                      FROM project_fc_categories pfc
                                      INNER JOIN projects p ON p.id = pfc.project_id AND p.user_id = $current_user_id
                                      LEFT JOIN projects pp ON pp.id = p.parent_id AND pp.user_id = $current_user_id
                                      WHERE pfc.fc_category_id IN ($chain_str)");
        $by_cat = [];
        while ($lpRes && ($lp = mysqli_fetch_assoc($lpRes))) {
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
$res_all_words = mysqli_query($conn, "SELECT i.question_desc FROM fc_images i JOIN fc_categories c ON i.category_id = c.id WHERE c.user_id = $current_user_id");

while($r = mysqli_fetch_assoc($res_all_words)) {

    $qk = strtolower(trim($r['question_desc']));

    if(!isset($global_fc_counts[$qk])) $global_fc_counts[$qk] = 0;

    $global_fc_counts[$qk]++;

}



// Query blindata: usa una JOIN rigida per assicurarsi che la categoria appartenga al current_user_id
// Query blindata: usa una JOIN rigida per assicurarsi che la categoria appartenga al current_user_id
$query = "SELECT i.*, c.name AS category_name, c.color AS category_color, (CASE WHEN ul.fc_image_id IS NOT NULL THEN 1 ELSE 0 END) as is_learnt 
          FROM fc_images AS i 
          JOIN fc_categories AS c ON i.category_id = c.id 
          LEFT JOIN user_learnt_fc ul ON i.id = ul.fc_image_id AND ul.user_id = $current_user_id 
          WHERE c.user_id = $current_user_id";

// Se è selezionata una categoria specifica, aggiunge la condizione con AND invece di WHERE
if ($categoryID != 0) { $query .= " AND i.category_id = $categoryID"; }



// Modifica ordinamento: default id_desc per "tutte le materie"
if ($categoryID == 0 && !isset($_GET['sort'])) {
    $sort = 'id_desc';
} else {
    $sort = isset($_GET['sort']) ? $_GET['sort'] : 'custom_asc';
}

$order_by = "i.custom_order ASC"; 
if ($sort === 'id_desc') { $order_by = "i.id DESC"; }
elseif ($sort === 'id_asc') { $order_by = "i.id ASC"; }
elseif ($sort === 'q_asc') { $order_by = "TRIM(LOWER(i.question_desc)) ASC"; }

$query .= " ORDER BY $order_by";



$result = mysqli_query($conn, $query);



$images_data = [];

$ids_array = [];

while ($row = mysqli_fetch_assoc($result)) {

    $row['children'] = [];

    $images_data[$row['id']] = $row;

    $ids_array[] = $row['id'];

}



if (count($ids_array) > 0) {

    $ids_str = implode(',', $ids_array);

    $res_children = mysqli_query($conn, "SELECT * FROM fc_child_cards WHERE parent_id IN ($ids_str) ORDER BY custom_order ASC");

    while ($c = mysqli_fetch_assoc($res_children)) {

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

    $lookup_str = implode(',', $lookup_ids);

    $res_ex = mysqli_query($conn, "SELECT fc_image_id, COUNT(*) as c FROM fc_examples WHERE fc_image_id IN ($lookup_str) GROUP BY fc_image_id");

    while ($ex = mysqli_fetch_assoc($res_ex)) { $examples_counts[intval($ex['fc_image_id'])] = intval($ex['c']); }

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

        <div class="qa-label">Examples (<?= $count ?>)</div>

        <div class="qa-examples-box" data-fc-id="<?= (int)$realId ?>" data-fc-type="<?= htmlspecialchars($type) ?>"></div>

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

        <div class="qa-label"><?= htmlspecialchars($label) ?></div>

        <?php if ($hasText || $hasImage): ?>

        <div class="qa-content <?= $contentClass ?>">

            <?php if ($hasText): ?>

                <div class="qa-text raw-text-content" data-raw="<?= htmlspecialchars($text, ENT_QUOTES) ?>"></div>

            <?php endif; ?>

            <?php if ($hasImage): ?>

                <img class="qa-image" src="fc_Images/<?= htmlspecialchars($image) ?>" alt="" loading="lazy" onclick="event.stopPropagation(); openImageZoom(this.src);">

            <?php endif; ?>

        </div>

        <?php else: ?>

        <div class="qa-content qa-empty"><span>—</span></div>

        <?php endif; ?>

        <?php if ($hasAudio): render_audio_player('fc_audio/' . htmlspecialchars($audio)); endif; ?>

    </div>

    <?php

}



function render_audio_player($src) {

    ?>

    <div class="audio-player" data-src="<?= $src ?>">

        <button type="button" class="ap-btn" aria-label="Riproduci audio" onclick="event.stopPropagation(); toggleAudioPlayer(this);">

            <svg class="ap-ic ap-ic-play" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5.14v13.72c0 .84.91 1.36 1.63.93l11.32-6.86a1.08 1.08 0 0 0 0-1.86L9.63 4.21A1.08 1.08 0 0 0 8 5.14Z"/></svg>

            <svg class="ap-ic ap-ic-pause" viewBox="0 0 24 24" fill="currentColor" style="display:none"><path d="M7 5.5A1.5 1.5 0 0 1 8.5 4h1A1.5 1.5 0 0 1 11 5.5v13a1.5 1.5 0 0 1-1.5 1.5h-1A1.5 1.5 0 0 1 7 18.5v-13ZM13 5.5A1.5 1.5 0 0 1 14.5 4h1A1.5 1.5 0 0 1 17 5.5v13a1.5 1.5 0 0 1-1.5 1.5h-1a1.5 1.5 0 0 1-1.5-1.5v-13Z"/></svg>

        </button>

        <div class="ap-track" onclick="event.stopPropagation(); seekAudioPlayer(event, this);">

            <div class="ap-fill"></div>

            <div class="ap-knob"></div>

        </div>

        <span class="ap-time">0:00</span>

        <audio preload="none" src="<?= $src ?>"></audio>

    </div>

    <?php

}

?>

<!DOCTYPE html>

<html lang="it" style="--cat-color: <?= htmlspecialchars(fc_color_or_default($current_cat_color)) ?>;">

<head>

<meta charset="UTF-8">

<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=0, viewport-fit=cover">

<meta name="mobile-web-app-capable" content="yes">

<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">

<meta name="format-detection" content="telephone=no">

<meta name="theme-color" content="#F2F2F7" media="(prefers-color-scheme: light)">

<meta name="theme-color" content="#000000" media="(prefers-color-scheme: dark)">

<title>Visualizzazione Dati Materie</title>



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



<style>

/* ================================================================

   DESIGN SYSTEM — iOS nativo, light+dark automatico, colore-per-categoria

   ================================================================ */

:root{

    color-scheme: light dark;

    --bg:#F2F2F7; --surface:#FFFFFF; --surface-2:#F2F2F7; --surface-3:#FAFAFC;

    --text:#1C1C1E; --text-2:#8A8A8E; --text-3:#AEAEB2;

    --hairline:rgba(60,60,67,.12); --hairline-2:rgba(60,60,67,.22);

    --blue:#007AFF; --green:#34C759; --red:#FF3B30; --orange:#FF9500; --purple:#AF52DE;

    --blue-soft:rgba(0,122,255,.09); --green-soft:rgba(52,199,89,.13); --red-soft:rgba(255,59,48,.12); --orange-soft:rgba(255,149,0,.14);

    --header-glass:rgba(249,249,251,.82);

    --shadow-card:0 1px 1px rgba(0,0,0,.04), 0 10px 26px rgba(0,0,0,.07);

    --raise-sm:0 1px 1.5px rgba(0,0,0,.08), 0 5px 12px -4px rgba(0,0,0,.13);

    --pill-hl:inset 0 0 0 .5px var(--hairline);

    --r-card:20px; --r-btn:14px; --r-chip:100px;

    --spring:cubic-bezier(.32,.72,0,1);

    --bounce:cubic-bezier(.34,1.56,.64,1);

    --safe-top:env(safe-area-inset-top,0px);

    --safe-bottom:env(safe-area-inset-bottom,0px);

    --header-h:150px;



    --cat-soft: color-mix(in srgb, var(--cat-color), transparent 88%);

    --cat-soft-2: color-mix(in srgb, var(--cat-color), transparent 74%);

    --cat-line: color-mix(in srgb, var(--cat-color), transparent 35%);

    --cat-dark: color-mix(in srgb, var(--cat-color), black 22%);

    --cat-light: color-mix(in srgb, var(--cat-color), white 22%);

}

@media (prefers-color-scheme: dark){

    :root{

        --bg:#000000; --surface:#1C1C1E; --surface-2:#2C2C2E; --surface-3:#242426;

        --text:#F5F5F7; --text-2:#98989F; --text-3:#636366;

        --hairline:rgba(84,84,88,.42); --hairline-2:rgba(84,84,88,.62);

        --blue:#0A84FF; --green:#30D158; --red:#FF453A; --orange:#FF9F0A; --purple:#BF5AF2;

        --blue-soft:rgba(10,132,255,.16); --green-soft:rgba(48,209,88,.17); --red-soft:rgba(255,69,58,.17); --orange-soft:rgba(255,159,10,.18);

        --header-glass:rgba(16,16,18,.78);

        --shadow-card:0 1px 1px rgba(0,0,0,.35), 0 10px 30px rgba(0,0,0,.5);

        --raise-sm:0 1px 1.5px rgba(0,0,0,.45), 0 5px 12px -4px rgba(0,0,0,.55);

        --pill-hl:inset 0 0 0 .5px var(--hairline);

    }

}



*{ box-sizing:border-box; -webkit-tap-highlight-color:transparent; }

html,body{ height:100%; }

html{ -webkit-text-size-adjust:100%; }

body{

    margin:0; background:var(--bg); color:var(--text);

    font-family:-apple-system, BlinkMacSystemFont, "SF Pro Text", "SF Pro Display", system-ui, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;

    font-size:16px; line-height:1.45; -webkit-font-smoothing:antialiased;

    overscroll-behavior-y:none;

}

body.no-select, body.no-select *{ -webkit-user-select:none !important; user-select:none !important; }

:focus-visible{ outline:2px solid var(--blue); outline-offset:2px; border-radius:8px; }

a{ color:inherit; }

button{ font-family:inherit; }

img{ -webkit-touch-callout:none; }



/* ================================================================

   HEADER

   ================================================================ */

.app-header{

    position:fixed; top:0; left:0; right:0; z-index:200;

    padding-top:var(--safe-top);

    background:var(--header-glass);

    -webkit-backdrop-filter:saturate(1.8) blur(22px); backdrop-filter:saturate(1.8) blur(22px);

    border-bottom:.5px solid var(--hairline);

    box-shadow:0 1px 0 rgba(0,0,0,.02);

}

.header-top{ display:flex; align-items:center; gap:10px; padding:10px 14px 8px; }

.icon-btn{

    width:38px; height:38px; border-radius:50%; border:none; cursor:pointer; padding:0; flex-shrink:0;

    background:var(--surface); color:var(--text-2); text-decoration:none;

    display:flex; align-items:center; justify-content:center;

    box-shadow:var(--pill-hl), var(--raise-sm);

    transition:transform .15s var(--spring), box-shadow .2s var(--spring);

}

.icon-btn:active{ transform:scale(.88); box-shadow:var(--pill-hl), inset 0 2px 5px rgba(0,0,0,.1); }

.icon-btn svg{ width:18px; height:18px; }



.cat-pill{

    flex:1; min-width:0; display:flex; align-items:center; gap:10px;

    padding:8px 14px; border-radius:16px; color:#fff; text-decoration:none; cursor:pointer;

    background:linear-gradient(135deg, var(--cat-light), var(--cat-color) 55%, var(--cat-dark));

    box-shadow:0 8px 20px -8px var(--cat-line), inset 0 1px 0 rgba(255,255,255,.25);

    text-shadow:0 1px 3px rgba(0,0,0,.28);

    transition:background 1.1s var(--spring), box-shadow .5s var(--spring);

    animation:catPillIn .55s var(--spring) both;

}

@keyframes catPillIn{ from{ opacity:0; transform:translateY(-8px) scale(.97); } }

.cat-pill-dot{ width:9px; height:9px; border-radius:50%; background:#fff; flex-shrink:0; box-shadow:0 0 0 4px rgba(255,255,255,.28); animation:dotPulse 2.4s ease-in-out infinite; }

@keyframes dotPulse{ 0%,100%{ transform:scale(1); opacity:1; } 50%{ transform:scale(1.35); opacity:.65; } }

.cat-pill-text{ min-width:0; flex:1; }

.cat-pill-label{ font-size:10px; font-weight:800; letter-spacing:.1em; text-transform:uppercase; opacity:.78; }

.cat-pill-name{ font-size:16px; font-weight:800; letter-spacing:-.01em; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }

.cat-pill-count{

    flex-shrink:0; font-size:11.5px; font-weight:800; padding:5px 10px; border-radius:99px;

    background:rgba(255,255,255,.22); font-variant-numeric:tabular-nums;

}
.cat-proj-chip{ display:inline-flex; align-items:center; gap:5px; margin-top:6px; max-width:100%;
    padding:4px 10px 4px 8px; border-radius:99px; cursor:pointer; border:none;
    background:rgba(255,255,255,.24); color:#fff; font-size:11px; font-weight:800; letter-spacing:-.1px;
    line-height:1.3; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;
    -webkit-tap-highlight-color:transparent; transition:transform .15s ease, background .2s; }
.cat-proj-chip svg{ flex-shrink:0; }
.cat-proj-chip:active{ transform:scale(.95); }
.cat-proj-chip.via-ancestor{ background:rgba(255,255,255,.16); opacity:.92; }
.cat-proj-chip.cat-proj-link{ background:rgba(255,255,255,.16); border:1px dashed rgba(255,255,255,.55); }
.cat-proj-chip .cp-anc{ opacity:.72; font-weight:700; }
.cat-proj-chip .cp-sep{ opacity:.6; margin:0 3px; }
.cat-proj-chip .cp-leaf{ font-weight:800; }



.header-row2{ display:flex; align-items:center; gap:8px; padding:0 14px 10px; }

.big-edit-btn{ background:linear-gradient(180deg, color-mix(in srgb, var(--green), white 18%), var(--green) 55%, color-mix(in srgb, var(--green), black 10%)); color:#fff; box-shadow:inset 0 1px 0 rgba(255,255,255,.3), 0 6px 16px -6px rgba(52,199,89,.6); }

.quick-add-btn{ background:linear-gradient(180deg, color-mix(in srgb, var(--blue), white 18%), var(--blue) 55%, color-mix(in srgb, var(--blue), black 10%)); color:#fff; box-shadow:inset 0 1px 0 rgba(255,255,255,.3), 0 6px 16px -6px rgba(0,122,255,.6); }

.play-cat-btn{ background:linear-gradient(180deg, var(--cat-light), var(--cat-color) 55%, var(--cat-dark)); color:#fff; box-shadow:inset 0 1px 0 rgba(255,255,255,.3), 0 6px 16px -6px var(--cat-line); }



/* ---- Percorso categorie (breadcrumb scorrevole in header-row2) ---- */
.cat-path{
    flex:1 1 auto; min-width:0; display:flex; align-items:center; gap:3px;
    overflow-x:auto; overflow-y:hidden; scrollbar-width:none; -webkit-overflow-scrolling:touch;
    scroll-behavior:auto; padding:2px 0; margin:0;
    -webkit-mask-image:linear-gradient(to right, transparent 0, #000 10px, #000 calc(100% - 4px), transparent 100%);
            mask-image:linear-gradient(to right, transparent 0, #000 10px, #000 calc(100% - 4px), transparent 100%);
}
.cat-path::-webkit-scrollbar{ display:none; }
.cat-path-home{
    flex-shrink:0; display:flex; align-items:center; justify-content:center;
    width:26px; height:26px; border-radius:8px; color:var(--text-2);
    transition:opacity .15s var(--spring), transform .15s var(--spring);
}
.cat-path-home svg{ width:15px; height:15px; }
.cat-path-home:active{ opacity:.5; transform:scale(.9); }
.cat-path-item{
    flex-shrink:0; white-space:nowrap; text-decoration:none; line-height:1;
    font-size:13.5px; font-weight:800; letter-spacing:-.01em;
    color:color-mix(in srgb, var(--cat-color) 62%, var(--text));
    padding:5px 4px; border-radius:8px;
    transition:opacity .15s var(--spring), transform .15s var(--spring);
}
.cat-path-item:active{ opacity:.5; transform:scale(.96); }
.cat-path-current{
    color:color-mix(in srgb, var(--cat-color) 52%, var(--text)); background:var(--cat-soft);
    padding:5px 11px; border-radius:99px;
}
.cat-path-sep{
    flex-shrink:0; color:var(--text-3); font-size:13px; font-weight:600;
    padding:0 1px; -webkit-user-select:none; user-select:none;
}

        .toolbar-row{ display:flex; align-items:center; gap:8px; padding:0 14px 12px; overflow-x:auto; scrollbar-width:none; -webkit-overflow-scrolling:touch; }

.toolbar-row::-webkit-scrollbar{ display:none; }

.search-wrap{ position:relative; flex:1 1 140px; min-width:120px; }

.search-wrap svg{ position:absolute; left:12px; top:50%; transform:translateY(-50%); width:15px; height:15px; color:var(--text-3); pointer-events:none; }

.search-input{

    width:100%; padding:9px 12px 9px 34px; border-radius:var(--r-chip); border:none;

    font-family:inherit; font-size:14px; font-weight:600; color:var(--text);

    background:var(--surface-2); box-shadow:inset 0 0 0 1px transparent;

    transition:box-shadow .25s var(--spring), background .25s;

}

.search-input:focus{ background:var(--surface); box-shadow:inset 0 0 0 1.5px var(--cat-color), 0 0 0 4px var(--cat-soft); }

.search-input::placeholder{ color:var(--text-3); font-weight:500; }

.tb-select-wrap{ position:relative; flex-shrink:0; }

.tb-select-wrap::after{

    content:''; position:absolute; right:9px; top:50%; width:6px; height:6px; pointer-events:none;

    border-right:1.6px solid var(--text-2); border-bottom:1.6px solid var(--text-2);

    transform:translateY(-70%) rotate(45deg);

}

select.tb-select{

    padding:9px 24px 9px 12px; border-radius:var(--r-chip); border:none;

    font-family:inherit; font-size:13px; font-weight:700; color:var(--text);

    background:var(--surface-2); -webkit-appearance:none; appearance:none; cursor:pointer;

    max-width:132px; text-overflow:ellipsis;

}

.select-mode-btn{

    flex-shrink:0; display:flex; align-items:center; gap:6px; padding:9px 14px; border:none; border-radius:var(--r-chip);

    font-family:inherit; font-size:13px; font-weight:800; cursor:pointer; color:#fff;

    background:linear-gradient(180deg, var(--cat-light), var(--cat-color) 55%, var(--cat-dark));

    box-shadow:inset 0 1px 0 rgba(255,255,255,.28), 0 6px 16px -6px var(--cat-line);

    transition:transform .15s var(--spring);

}

.select-mode-btn:active{ transform:scale(.94); }

.select-mode-btn svg{ width:14px; height:14px; }



/* Toolbar di selezione — sostituisce la toolbar normale con un morph animato */

.select-toolbar{

    display:flex; align-items:center; gap:8px; padding:0 14px 12px;

}

.st-cancel{

    width:34px; height:34px; border-radius:50%; border:none; background:var(--surface-2); color:var(--text-2);

    display:flex; align-items:center; justify-content:center; cursor:pointer; flex-shrink:0;

    transition:transform .15s var(--spring);

}

.st-cancel:active{ transform:scale(.85); }

.st-cancel svg{ width:15px; height:15px; }

.st-selectall{

    flex-shrink:0; padding:8px 13px; border:none; border-radius:var(--r-chip); cursor:pointer;

    font-size:12.5px; font-weight:800; color:var(--text); background:var(--surface-2);

    transition:transform .15s var(--spring);

}

.st-selectall:active{ transform:scale(.93); }

.st-count{

    flex-shrink:0; padding:7px 12px; border-radius:var(--r-chip); font-size:12.5px; font-weight:800;

    color:#fff; background:var(--cat-color); font-variant-numeric:tabular-nums;

    box-shadow:0 4px 12px -4px var(--cat-line);

    transition:background .3s;

}

.st-actions{ display:flex; gap:6px; overflow-x:auto; scrollbar-width:none; flex:1; }

.st-actions::-webkit-scrollbar{ display:none; }

.st-action{

    flex-shrink:0; display:flex; align-items:center; gap:5px; padding:8px 12px; border:none; border-radius:var(--r-chip);

    font-size:12.5px; font-weight:800; cursor:pointer; color:var(--blue); background:var(--blue-soft);

    transition:transform .15s var(--spring), filter .15s;

}

.st-action:active{ transform:scale(.93); }

.st-action svg{ width:13px; height:13px; }

.st-action.danger{ color:var(--red); background:var(--red-soft); }

.st-action.active-pick{ color:#fff; background:var(--cat-color); box-shadow:0 4px 12px -4px var(--cat-line); }



.join-banner{

    margin:0 14px 10px; padding:10px 14px; border-radius:16px; background:var(--purple);

    color:#fff; font-size:13px; font-weight:700; display:flex; align-items:center; gap:8px;

    animation:revealIn .4s var(--spring) both;

    box-shadow:0 8px 20px -8px rgba(175,82,222,.55);

}



@keyframes revealIn{ from{ opacity:0; transform:translateY(10px) scale(.98); } }



/* ================================================================

   FEED / LISTA

   ================================================================ */

.feed{ padding-top:calc(var(--header-h) + 12px); padding-bottom:calc(90px + var(--safe-bottom)); min-height:100%; }

.feed-inner{ max-width:640px; margin:0 auto; padding:0 12px; }



.item-counter{

    display:flex; align-items:center; gap:8px; font-size:13px; font-weight:700; color:var(--text-2);

    padding:2px 4px 12px;

}

.item-counter b{ color:var(--text); font-variant-numeric:tabular-nums; }

.item-counter .ic-dot{ width:6px; height:6px; border-radius:50%; background:var(--cat-color); }



/* ================================================================

   SOTTOCATEGORIE (mini-griglia "sottocartelle" dentro una categoria)

   ================================================================ */

.fc-subcats{ margin:0 0 18px; }

.fc-subcats-label{

    display:flex; align-items:center; gap:6px;

    font-size:11px; font-weight:800; letter-spacing:.09em; text-transform:uppercase;

    color:var(--text-3); padding:0 4px 10px;

}

.fc-subcats-label svg{ width:13px; height:13px; opacity:.85; }

.fc-subcats-grid{

    display:grid; grid-template-columns:repeat(auto-fill, minmax(136px, 1fr)); gap:10px;

}

.fc-subcat-card{

    position:relative; display:block; text-align:left; text-decoration:none; color:inherit;

    background:var(--surface); border-radius:16px; padding:14px 14px 12px;

    box-shadow:var(--shadow-card); overflow:hidden;

    animation:revealIn .45s var(--spring) both;

    transition:transform .15s var(--spring), box-shadow .2s var(--spring);

}

.fc-subcats-grid .fc-subcat-card:nth-child(1){ animation-delay:.02s; }

.fc-subcats-grid .fc-subcat-card:nth-child(2){ animation-delay:.05s; }

.fc-subcats-grid .fc-subcat-card:nth-child(3){ animation-delay:.08s; }

.fc-subcats-grid .fc-subcat-card:nth-child(4){ animation-delay:.11s; }

.fc-subcats-grid .fc-subcat-card:nth-child(n+5){ animation-delay:.14s; }

.fc-subcat-card::before{ content:""; position:absolute; top:0; left:0; right:0; height:4px; background:var(--sc-color); }

.fc-subcat-card:active{ transform:scale(.96); }

.fc-subcat-dot{

    width:32px; height:32px; border-radius:10px; flex-shrink:0;

    background:color-mix(in srgb, var(--sc-color) 16%, var(--surface)); color:var(--sc-color);

    display:flex; align-items:center; justify-content:center; margin-bottom:10px;

}

.fc-subcat-dot svg{ width:16px; height:16px; }

.fc-subcat-name{

    font-size:14.5px; font-weight:800; letter-spacing:-.01em; color:var(--text);

    line-height:1.3; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical;

    overflow:hidden; margin-bottom:9px; min-height:37px;

}

.fc-subcat-meta{ display:flex; align-items:center; gap:10px; font-size:11.5px; font-weight:700; color:var(--text-2); flex-wrap:wrap; }

.fc-subcat-meta span{ display:flex; align-items:center; gap:4px; font-variant-numeric:tabular-nums; }

.fc-subcat-meta svg{ opacity:.8; flex-shrink:0; }



.empty-state{

    text-align:center; padding:60px 20px; color:var(--text-2);

    animation:revealIn .5s var(--spring) both;

}

.empty-state .es-icon{ font-size:44px; margin-bottom:10px; }

.empty-state h3{ margin:0 0 6px; color:var(--text); font-size:17px; font-weight:800; }

.empty-state p{ margin:0; font-size:14px; }



/* ================================================================

   GRUPPO (padre + figli) — unita' di trascinamento per i padri

   ================================================================ */

.fc-group{

    --row-color: var(--cat-color);

    margin-bottom:14px;

}



.fc-children{

    margin:8px 0 0 20px; padding-left:14px;

    border-left:2px dashed color-mix(in srgb, var(--row-color), transparent 55%);

    display:flex; flex-direction:column; gap:8px;

}



/* Riordino: placeholder che fa spazio + ghost che segue il dito (fixed) */

.fc-drag-placeholder{

    border-radius:var(--r-card); border:2px dashed var(--hairline-2);

    background:var(--surface-2); opacity:.7;

}

.fc-drag-ghost{ position:fixed; z-index:9999; pointer-events:none; cursor:grabbing; }

.fc-drag-ghost .fc-card{ box-shadow:0 22px 44px -12px rgba(0,0,0,.38), 0 4px 14px rgba(0,0,0,.16); }

.fc-drag-ghost .swipe-content{ box-shadow:0 0 0 2px var(--row-color); }



/* ================================================================

   CARD

   ================================================================ */

.fc-card{

    position:relative; border-radius:var(--r-card); box-shadow:var(--shadow-card);

}

.fc-card.duplicate-flag .card-head{ padding-right:4px; }



.swipe-actions{

    position:absolute; top:0; right:0; bottom:0; z-index:1;

    display:flex; align-items:stretch; border-radius:0 var(--r-card) var(--r-card) 0; overflow:hidden;

}

.swipe-act{

    width:78px; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:4px;

    border:none; cursor:pointer; color:#fff; font-size:11px; font-weight:800;

}

.swipe-act svg{ width:20px; height:20px; }

.swipe-act.act-edit{ background:linear-gradient(180deg, color-mix(in srgb, var(--row-color), white 15%), var(--row-color)); }

.swipe-act.act-delete{ background:linear-gradient(180deg, #FF6961, var(--red)); }



.swipe-content{

    position:relative; z-index:2; border-radius:var(--r-card); overflow:hidden;

    background:var(--surface); border:.5px solid var(--hairline);

    padding:14px 14px 12px 16px;

    border-left:4px solid var(--row-color);

    touch-action:pan-y;

}

.fc-card.fc-child .swipe-content{ border-left:3px dashed color-mix(in srgb, var(--row-color), transparent 25%); padding-left:14px; }

.fc-card.is-learnt .swipe-content{

    background:color-mix(in srgb, var(--green), var(--surface) 88%);

}

.fc-card.selected-row .swipe-content{

    box-shadow:inset 0 0 0 2px var(--row-color);

    background:var(--cat-soft);

}

body.select-mode .swipe-actions{ opacity:0; }



.card-head{ display:flex; align-items:center; gap:8px; margin-bottom:10px; min-height:28px; }

.card-head-spacer{ flex:1; min-width:0; }

.child-tag{ display:flex; align-items:center; gap:5px; font-size:11.5px; font-weight:800; color:color-mix(in srgb, var(--row-color) 55%, var(--text)); }

.child-tag svg{ width:13px; height:13px; }

.cat-badge{

    display:inline-flex; align-items:center; gap:5px; padding:4px 9px; border-radius:99px;

    font-size:10.5px; font-weight:800; color:#fff; background:var(--row-color); white-space:nowrap;

    box-shadow:0 3px 8px -3px var(--cat-line);

}

.dup-badge{

    display:inline-flex; align-items:center; gap:4px; padding:4px 8px; border-radius:99px;

    font-size:10px; font-weight:800; color:var(--orange); background:var(--orange-soft); white-space:nowrap;

}



/* Toggle "Imparato" */

.learnt-wrapper{ position:relative; width:30px; height:30px; cursor:pointer; user-select:none; flex-shrink:0; display:flex; align-items:center; justify-content:center; }

.learnt-cb{ display:none; }

.learnt-emoji, .learnt-check{ position:absolute; inset:0; display:flex; align-items:center; justify-content:center; transition:all .35s var(--bounce); }

.learnt-emoji{ font-size:21px; opacity:1; }

.learnt-check{ opacity:0; transform:scale(.3); pointer-events:none; }

.learnt-check svg{ width:26px; height:26px; color:var(--green); filter:drop-shadow(0 2px 4px rgba(0,0,0,.18)); }

.learnt-cb:checked ~ .learnt-emoji{ opacity:0; transform:scale(.2) rotate(-25deg); }

.learnt-cb:checked ~ .learnt-check{ opacity:1; transform:scale(1); }

.learnt-disabled{ color:var(--text-3); font-size:20px; font-weight:800; width:30px; text-align:center; flex-shrink:0; }



/* Cerchio di selezione (select mode) */

.select-circle{ position:relative; width:28px; height:28px; flex-shrink:0; display:none; align-items:center; justify-content:center; cursor:pointer; }

body.select-mode .select-circle{ display:flex; }

body.select-mode .learnt-wrapper, body.select-mode .learnt-disabled, body.select-mode .child-tag{ display:none; }

.select-circle input{ position:absolute; inset:0; opacity:0; margin:0; cursor:pointer; }

.select-circle .cc-visual{

    position:absolute; inset:0; border-radius:50%; border:2px solid var(--hairline-2); background:var(--surface);

    display:flex; align-items:center; justify-content:center; transition:all .25s var(--bounce);

}

.select-circle input:checked ~ .cc-visual{ background:var(--row-color); border-color:var(--row-color); transform:scale(1.06); }

.select-circle .cc-visual svg{ width:14px; height:14px; color:#fff; opacity:0; transform:scale(.4); transition:all .2s var(--bounce); }

.select-circle input:checked ~ .cc-visual svg{ opacity:1; transform:scale(1); }

.select-circle.is-radio .cc-visual{ border-radius:10px; }



/* ================================================================

   CONTENUTO DOMANDA / RISPOSTA

   ================================================================ */

.qa-side{ margin-bottom:12px; }

.qa-side:last-of-type{ margin-bottom:0; }

.qa-label{

    font-size:10.5px; font-weight:800; letter-spacing:.11em; text-transform:uppercase; color:var(--text-3);

    margin-bottom:6px; display:flex; align-items:center; gap:6px;

}

.qa-label::after{ content:''; flex:1; height:.5px; background:var(--hairline); }

.qa-content{ display:flex; gap:12px; align-items:flex-start; flex-wrap:wrap; }

.qa-content.qa-split .qa-text{ flex:1 1 130px; min-width:0; }

.qa-content.qa-split .qa-image{ flex:0 0 76px; width:76px; height:76px; }

.qa-content.qa-solo .qa-text{ flex:1 1 100%; font-size:16.5px; }

.qa-content.qa-solo .qa-image{ flex:1 1 100%; width:100%; max-height:230px; height:auto; }

.qa-content.qa-empty{ color:var(--text-3); font-size:14px; }

.qa-text{

    font-size:15.5px; font-weight:550; line-height:1.48; letter-spacing:-.008em; color:var(--text);

    white-space:pre-wrap; word-wrap:break-word; max-height:190px; overflow-y:auto; padding-right:4px;

}

.qa-text::-webkit-scrollbar{ width:5px; }

.qa-text::-webkit-scrollbar-thumb{ background:var(--hairline-2); border-radius:4px; }

.qa-image{

    border-radius:14px; object-fit:cover; cursor:zoom-in; display:block;

    border:.5px solid var(--hairline); box-shadow:0 3px 10px rgba(0,0,0,.08);

    transition:transform .2s var(--spring);

}

.qa-content.qa-solo .qa-image{ object-fit:contain; background:var(--surface-2); }

.qa-image:active{ transform:scale(.97); }



/* ================================================================

   PLAYER AUDIO CUSTOM

   ================================================================ */

.audio-player{

    display:flex; align-items:center; gap:9px; margin-top:9px; padding:7px 10px 7px 6px;

    background:var(--surface-2); border-radius:99px; box-shadow:inset 0 0 0 .5px var(--hairline);

}

.ap-btn{

    width:30px; height:30px; border-radius:50%; border:none; cursor:pointer; flex-shrink:0;

    background:var(--row-color); color:#fff; display:flex; align-items:center; justify-content:center;

    box-shadow:0 3px 8px -2px var(--cat-line); transition:transform .15s var(--spring);

}

.ap-btn:active{ transform:scale(.88); }

.ap-ic{ width:14px; height:14px; }

.ap-track{ position:relative; flex:1; height:20px; display:flex; align-items:center; cursor:pointer; }

.ap-track::before{ content:''; position:absolute; left:0; right:0; height:4px; border-radius:99px; background:var(--hairline-2); }

.ap-fill{ position:absolute; left:0; height:4px; width:0%; border-radius:99px; background:var(--row-color); }

.ap-knob{ position:absolute; left:0%; width:11px; height:11px; border-radius:50%; background:var(--row-color); box-shadow:0 1px 4px rgba(0,0,0,.3); transform:translateX(-50%); transition:opacity .15s; opacity:0; }

.audio-player.is-playing .ap-knob, .audio-player:hover .ap-knob{ opacity:1; }

.ap-time{ font-size:11px; font-weight:700; color:var(--text-2); font-variant-numeric:tabular-nums; flex-shrink:0; min-width:32px; text-align:right; }



/* ================================================================

   SEZIONE EXAMPLES (placeholder, da completare)

   ================================================================ */

.qa-examples{ margin-top:12px; }

.qa-examples .qa-label{ color:var(--purple); opacity:.75; }

.qa-examples-box{

    border:1.5px dashed color-mix(in srgb, var(--purple), transparent 55%);

    border-radius:14px; padding:12px; min-height:38px;

    display:flex; align-items:center; justify-content:center;

    color:var(--text-3); font-size:12.5px; font-weight:600;

    background:color-mix(in srgb, var(--purple), transparent 95%);

}



/* ================================================================

   OVERLAY ZOOM IMMAGINI

   ================================================================ */

#image-overlay{

    position:fixed; inset:0; z-index:9999; display:none;

    justify-content:center; align-items:center; background:rgba(0,0,0,.94);

    -webkit-backdrop-filter:blur(10px); backdrop-filter:blur(10px);

}

#overlay-img{ max-width:100%; max-height:100%; object-fit:contain; animation:zoomIn .3s var(--spring); }

@keyframes zoomIn{ from{ transform:scale(.85); opacity:0; } }

#close-overlay{

    position:absolute; top:calc(var(--safe-top) + 12px); right:14px; z-index:10000;

    width:40px; height:40px; border-radius:50%; background:rgba(255,255,255,.16); color:#fff;

    font-size:17px; display:flex; align-items:center; justify-content:center; cursor:pointer;

    -webkit-backdrop-filter:blur(8px); backdrop-filter:blur(8px);

}



/* ================================================================

   SHEET MODALI (stile iOS dal basso)

   ================================================================ */

.sheet-overlay{

    position:fixed; inset:0; z-index:10001; display:none; justify-content:center; align-items:flex-end;

    background:rgba(0,0,0,.45); -webkit-backdrop-filter:blur(6px); backdrop-filter:blur(6px);

}

.sheet-panel{

    position:relative; width:100%; max-width:600px; max-height:80vh; overflow-y:auto;

    background:var(--surface); color:var(--text); border-radius:26px 26px 0 0;

    padding:26px 20px calc(20px + var(--safe-bottom));

    box-shadow:0 -10px 50px rgba(0,0,0,.35);

    animation:sheetUp .42s var(--spring) both;

}

@keyframes sheetUp{ from{ transform:translateY(60%); opacity:.4; } to{ transform:none; opacity:1; } }

.sheet-panel::before{

    content:''; position:absolute; top:8px; left:50%; transform:translateX(-50%);

    width:38px; height:5px; border-radius:99px; background:var(--hairline-2);

}

.sheet-panel h3{ margin:2px 0 4px; font-size:19px; font-weight:800; letter-spacing:-.02em; }

.sheet-panel p.sheet-sub{ margin:0 0 16px; font-size:13.5px; color:var(--text-2); font-weight:500; }

.sheet-warn{

    margin:0 0 14px; padding:10px 12px; border-radius:12px; background:var(--orange-soft); color:var(--orange);

    font-size:12.5px; font-weight:700; display:none;

}

.sheet-actions{ display:flex; gap:10px; margin-top:6px; }

.sheet-btn{

    flex:1; padding:14px; border:none; border-radius:var(--r-btn); cursor:pointer;

    font-family:inherit; font-size:15.5px; font-weight:800; color:var(--text); background:var(--surface-2);

    transition:transform .15s var(--spring);

}

.sheet-btn:active{ transform:scale(.97); }

.sheet-btn.danger{ color:#fff; background:linear-gradient(180deg, #FF6961, var(--red)); }

.cat-pick-list{ display:flex; flex-direction:column; gap:6px; max-height:46vh; overflow-y:auto; }

.cat-pick-item{

    display:flex; align-items:center; gap:10px; padding:12px 14px; border-radius:14px; cursor:pointer;

    background:var(--surface-2); border:none; width:100%; text-align:left; font-family:inherit;

    transition:transform .15s var(--spring), background .15s;

}

.cat-pick-item:active{ transform:scale(.98); }

.cat-pick-dot{ width:12px; height:12px; border-radius:50%; flex-shrink:0; box-shadow:0 0 0 3px rgba(0,0,0,.04); }

.cat-pick-name{ font-size:15px; font-weight:700; }



/* ================================================================

   TOAST

   ================================================================ */

#toast-host{ position:fixed; left:50%; bottom:calc(var(--safe-bottom) + 22px); z-index:10500; transform:translateX(-50%); display:flex; flex-direction:column; gap:8px; align-items:center; pointer-events:none; }

.toast{

    padding:12px 18px; border-radius:99px; background:var(--text); color:var(--bg);

    font-size:13.5px; font-weight:700; text-align:center; box-shadow:0 12px 30px rgba(0,0,0,.3);

    animation:toastIn .4s var(--bounce) both; max-width:88vw;

}

.toast.leaving{ transition:.3s; opacity:0; transform:translateY(10px) scale(.9); }

@keyframes toastIn{ from{ opacity:0; transform:translateY(16px) scale(.85); } }



/* ================================================================

   BARRA FLOTTANTE DI CONFERMA (join)

   ================================================================ */

.confirm-bar{

    position:fixed; left:0; right:0; bottom:0; z-index:250; display:none;

    padding:12px 14px calc(12px + var(--safe-bottom)); background:var(--header-glass);

    -webkit-backdrop-filter:blur(20px); backdrop-filter:blur(20px); border-top:.5px solid var(--hairline);

    animation:revealIn .3s var(--spring) both;

}

.confirm-bar .cb-btn{

    width:100%; padding:15px; border:none; border-radius:var(--r-btn); cursor:pointer;

    font-family:inherit; font-size:16px; font-weight:800; color:#fff;

    background:linear-gradient(180deg, var(--cat-light), var(--cat-color) 55%, var(--cat-dark));

    box-shadow:inset 0 1px 0 rgba(255,255,255,.25), 0 10px 24px -8px var(--cat-line);

    transition:transform .15s var(--spring), opacity .2s;

}

.confirm-bar .cb-btn:disabled{ opacity:.4; box-shadow:none; }

.confirm-bar .cb-btn:active:not(:disabled){ transform:scale(.98); }



@media (prefers-reduced-motion: reduce){

    *, *::before, *::after{ animation-duration:.01ms !important; transition-duration:.01ms !important; }

}

@media (min-width:700px){

    .qa-content.qa-split .qa-image{ flex:0 0 96px; width:96px; height:96px; }

}

</style>

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

                <div class="cat-pill-label">Categoria</div>

                <div class="cat-pill-name"><?= htmlspecialchars($current_cat_name) ?></div>
                <?php if ($categoryID != 0): ?>
                    <?php if ($fc_linked_project): ?>
                    <button type="button" class="cat-proj-chip<?= !empty($fc_linked_project['via_ancestor']) ? ' via-ancestor' : '' ?>"
                            style="--cp-color:<?= htmlspecialchars($fc_linked_project['color']) ?>;"
                            onclick="event.stopPropagation(); window.location.href='progetti.php?id=<?= (int)$fc_linked_project['id'] ?>';">
                        <svg viewBox="0 0 24 24" width="10" height="10" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z"/></svg>
                        <?php if (!empty($fc_linked_project['parent_name'])): ?><span class="cp-anc"><?= htmlspecialchars($fc_linked_project['parent_name']) ?></span><span class="cp-sep">•</span><?php endif; ?><span class="cp-leaf"><?= htmlspecialchars($fc_linked_project['name']) ?></span>
                    </button>
                    <?php else: ?>
                    <button type="button" class="cat-proj-chip cat-proj-link" onclick="event.stopPropagation(); fcOpenLinkProject();">
                        <svg viewBox="0 0 24 24" width="11" height="11" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
                        Collega progetto
                    </button>
                    <?php endif; ?>
                <?php endif; ?>

            </div>

            <span class="cat-pill-count" id="catCount"><?= (int)$fc_total_cards ?> <?= ((int)$fc_total_cards === 1 ? 'carta' : 'carte') ?></span>

        </div>

    </div>



    <div class="header-row2">

        <nav class="cat-path" id="catPath" aria-label="Percorso categorie">
            <a href="categories_fc.php" class="cat-path-home" aria-label="Home — torna alle categorie" title="Torna alle categorie">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m3 10 9-7 9 7v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2Z"/></svg>
            </a>
            <span class="cat-path-sep" aria-hidden="true">&rsaquo;</span>
            <?php if ($categoryID == 0): ?>
            <span class="cat-path-item cat-path-current">Tutte le Categorie</span>
            <?php else: ?>
                <?php foreach ($fc_path_ltr as $__anc): ?>
                <a class="cat-path-item" href="visualizzazione_fc.php?category_id=<?= (int)$__anc['id'] ?>"><?= htmlspecialchars($__anc['name'], ENT_QUOTES) ?></a>
                <span class="cat-path-sep" aria-hidden="true">&rsaquo;</span>
                <?php endforeach; ?>
            <span class="cat-path-item cat-path-current"><?= htmlspecialchars($current_cat_name, ENT_QUOTES) ?></span>
            <?php endif; ?>
        </nav>

        <a href="inserimento_fc.php?category_id=<?= $categoryID ?>" class="icon-btn quick-add-btn" aria-label="Aggiungi flashcard" title="Aggiungi flashcard in questa categoria">

            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>

        </a>

        <?php if ($categoryID != 0): ?>
        <a href="categories_game_fc.php?preselect_category=<?= $categoryID ?>" class="icon-btn play-cat-btn" aria-label="Gioca con questa categoria" title="Gioca con questa categoria">
            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M8 5.14v13.72a1 1 0 0 0 1.53.85l11-6.86a1 1 0 0 0 0-1.7l-11-6.86A1 1 0 0 0 8 5.14Z"/></svg>
        </a>
        <a href="big_edit_fc.php?category_id=<?= $categoryID ?>" class="icon-btn big-edit-btn" aria-label="Big Edit" title="Big Edit">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
        </a>
        <?php endif; ?>

    </div>



    <div class="toolbar-row" id="normalToolbar">

        <div class="search-wrap">

            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>

            <input id="filterText" class="search-input" type="text" placeholder="Cerca in domanda o risposta..." oninput="visibleLimit=30; filterList()">

        </div>

        <div class="tb-select-wrap">

            <select id="filterSort" class="tb-select" onchange="changeSort()">

                <option value="custom_asc" <?= $sort == 'custom_asc' ? 'selected' : '' ?>>Posizione</option>

                <option value="id_desc" <?= $sort == 'id_desc' ? 'selected' : '' ?>>ID recenti</option>

                <option value="id_asc" <?= $sort == 'id_asc' ? 'selected' : '' ?>>ID meno recenti</option>

                <option value="q_asc" <?= $sort == 'q_asc' ? 'selected' : '' ?>>Domanda A-Z</option>

            </select>

        </div>

        <div class="tb-select-wrap">

            <select id="filterStatus" class="tb-select" onchange="visibleLimit=30; filterList()">

                <option value="all">Tutte le carte</option>

                <option value="not_learnt">Da imparare</option>

                <option value="learnt">Imparate</option>

                <option value="duplicates">Duplicati</option>

            </select>

        </div>

        <button type="button" class="select-mode-btn" id="selectModeBtn" onclick="enterSelectMode()">

            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m9 12 2 2 4-4"/><rect x="3" y="3" width="18" height="18" rx="6"/></svg>

            Seleziona

        </button>

    </div>



    <div class="select-toolbar" id="selectToolbar" style="display:none;">

        <button type="button" class="st-cancel" onclick="exitSelectMode()" aria-label="Annulla selezione">

            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>

        </button>

        <button type="button" class="st-selectall" id="selectAllBtn" onclick="toggleSelectAll()">Tutte</button>

        <div class="st-count" id="selectCount">0</div>

        <div class="st-actions" id="stActions">

            <button type="button" class="st-action" data-action="move" onclick="onBulkActionPick('move')">

                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><path d="M21 8V6a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h6"/><path d="M17 22v-6M14 19h6"/></svg>

                Sposta

            </button>

            <button type="button" class="st-action" data-action="join" onclick="onBulkActionPick('join')">

                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><circle cx="7" cy="12" r="3"/><circle cx="17" cy="6" r="3"/><circle cx="17" cy="18" r="3"/><path d="M9.6 10.5 14.5 7.7M9.6 13.5l4.9 2.8"/></svg>

                Unisci

            </button>

            <button type="button" class="st-action" data-action="unjoin" onclick="onBulkActionPick('unjoin')">

                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><circle cx="6" cy="6" r="3"/><circle cx="6" cy="18" r="3"/><path d="M20 4 8.12 15.88M14.47 14.48 20 20M8.12 8.12 12 12"/></svg>

                Separa

            </button>

            <button type="button" class="st-action danger" data-action="delete" onclick="onBulkActionPick('delete')">

                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0-1 14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2L4 6"/></svg>

                Elimina

            </button>

        </div>

    </div>



    <div class="join-banner" id="joinBanner" style="display:none;">

        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>

        Ora tocca la carta che diventerà il padre del gruppo

    </div>

</header>



<?php include 'sidebar.html'; ?>



<main class="feed">

    <div class="feed-inner">



        <?php if (!empty($subcategories)): ?>

        <div class="fc-subcats">

            <div class="fc-subcats-label">

                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z"/></svg>

                Sottocategorie

            </div>

            <div class="fc-subcats-grid">

                <?php foreach ($subcategories as $sc):

                    $scColor = fc_color_or_default($sc['color']);

                    $scCardCount = isset($subcat_card_counts[$sc['id']]) ? (int)$subcat_card_counts[$sc['id']] : 0;

                    $scFolderCount = isset($subcat_children_count[$sc['id']]) ? (int)$subcat_children_count[$sc['id']] : 0;

                ?>

                <a href="visualizzazione_fc.php?category_id=<?= (int)$sc['id'] ?>" class="fc-subcat-card" style="--sc-color: <?= htmlspecialchars($scColor) ?>;">

                    <span class="fc-subcat-dot">

                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z"/></svg>

                    </span>

                    <div class="fc-subcat-name"><?= htmlspecialchars($sc['name'], ENT_QUOTES) ?></div>

                    <div class="fc-subcat-meta">

                        <span title="<?= $scCardCount ?> carte">

                            <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="7" width="13" height="10" rx="2.2"/><path d="M7.5 7V5a2 2 0 0 1 2-2h7a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2h-2"/></svg>

                            <?= $scCardCount ?>

                        </span>

                        <?php if ($scFolderCount > 0): ?>

                        <span title="<?= $scFolderCount ?> sottocategorie">

                            <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h3.5l1.5 2H19a2 2 0 0 1 2 2v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z"/></svg>

                            <?= $scFolderCount ?>

                        </span>

                        <?php endif; ?>

                    </div>

                </a>

                <?php endforeach; ?>

            </div>

        </div>

        <?php endif; ?>



        <div class="item-counter" id="itemCounter"><span class="ic-dot"></span> <b id="itemCounterNum">0</b> carte mostrate</div>



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
                    $catName = $row['category_name'] ? $row['category_name'] : 'Nessuna';
                    $searchString = htmlspecialchars(strtolower($row['question_desc'] . ' ' . $row['answer_desc']), ENT_QUOTES);
                    $qk = strtolower(trim($row['question_desc']));
                    $isDuplicate = (isset($global_fc_counts[$qk]) && $global_fc_counts[$qk] > 1);
                    $childCount = count($row['children']);
                    
                    // Pre-nascondi via PHP per evitare rendering lag
                    $hide_style = ($categoryID == 0 && $group_counter > 30) ? 'display: none;' : '';
                ?>
                <div class="fc-group" data-parent-id="<?= (int)$row['id'] ?>" data-category-id="<?= (int)$row['category_id'] ?>" data-reorderable="<?= $parent_reorder_enabled ? '1' : '0' ?>" style="--row-color:<?= htmlspecialchars($catColor) ?>; <?= $hide_style ?>">



                    <div class="fc-card fc-parent <?= $isLearnt ? 'is-learnt' : '' ?> <?= $isDuplicate ? 'duplicate-flag' : '' ?>"

                         data-id="<?= (int)$row['id'] ?>" data-type="parent"

                         data-search="<?= $searchString ?>" data-duplicate="<?= $isDuplicate ? 'true' : 'false' ?>"

                         data-edit-url="modifica_immagine_fc.php?fc_id=<?= (int)$row['id'] ?>&previous_catid=<?= (int)$categoryID ?>"

                         data-child-count="<?= (int)$childCount ?>">



                        <div class="swipe-actions">

                            <button type="button" class="swipe-act act-edit" onclick="goEditCard(this)">

                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>

                                Modifica

                            </button>

                            <button type="button" class="swipe-act act-delete" onclick="askDeleteCard(this)">

                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0-1 14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2L4 6"/></svg>

                                Elimina

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

                                <?php if ($isDuplicate): ?><span class="dup-badge">⧉ Duplicato</span><?php endif; ?>

                                <?php if ($categoryID == 0): ?><span class="cat-badge" style="background:<?= htmlspecialchars($catColor) ?>"><?= htmlspecialchars($catName) ?></span><?php endif; ?>

                            </div>



                            <?php render_qa_side('Domanda', $row['question_desc'], $row['question_image'], $row['question_audio']); ?>

                            <?php render_qa_side('Risposta', $row['answer_desc'], $row['answer_image'], $row['answer_audio']); ?>



                            <?php render_examples_section($row['id'], 'parent', $row['id'], $examples_counts); ?>

                        </div>

                    </div>



                    <?php if ($childCount > 0): ?>

                    <div class="fc-children" data-parent-id="<?= (int)$row['id'] ?>">

                        <?php foreach ($row['children'] as $child):

                            $searchChild = htmlspecialchars(strtolower($child['question_desc'] . ' ' . $child['answer_desc']), ENT_QUOTES);

                        ?>

                        <div class="fc-card fc-child" data-id="<?= (int)$child['id'] ?>" data-type="child" data-parent-id="<?= (int)$row['id'] ?>"

                             data-search="<?= $searchChild ?>" data-duplicate="false" data-reorderable="<?= $child_reorder_enabled ? '1' : '0' ?>"

                             data-edit-url="modifica_immagine_fc.php?child_id=<?= (int)$child['id'] ?>&previous_catid=<?= (int)$categoryID ?>">



                            <div class="swipe-actions">

                                <button type="button" class="swipe-act act-edit" onclick="goEditCard(this)">

                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>

                                    Modifica

                                </button>

                                <button type="button" class="swipe-act act-delete" onclick="askDeleteCard(this)">

                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0-1 14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2L4 6"/></svg>

                                    Elimina

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



                                <?php render_qa_side('Domanda', $child['question_desc'], $child['question_image'], $child['question_audio']); ?>

                                <?php render_qa_side('Risposta', $child['answer_desc'], $child['answer_image'], $child['answer_audio']); ?>



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
                    Carica altre 30 carte
                </button>
            <?php endif; ?>

            <?php else: ?>

            <div class="empty-state">

                <div class="es-icon">🗂️</div>

                <h3>Nessuna carta trovata</h3>

                <p>Non ci sono flashcard in questa categoria.</p>

            </div>

            <?php endif; ?>

        </form>

    </div>

</main>



<div class="confirm-bar" id="joinConfirmBar">

    <button type="button" class="cb-btn" id="joinConfirmBtn" disabled onclick="confirmJoinExecution()">Confermo il padre selezionato</button>

</div>



<div class="sheet-overlay" id="deleteSheet">

    <div class="sheet-panel">

        <h3 id="deleteSheetTitle">Eliminare questa carta?</h3>

        <p class="sheet-sub" id="deleteSheetSub">L'operazione non può essere annullata.</p>

        <div class="sheet-warn" id="deleteSheetWarn"></div>

        <div class="sheet-actions">

            <button type="button" class="sheet-btn" onclick="closeSheet('deleteSheet')">Annulla</button>

            <button type="button" class="sheet-btn danger" id="deleteSheetConfirm">Elimina</button>

        </div>

    </div>

</div>



<div class="sheet-overlay" id="moveSheet">

    <div class="sheet-panel">

        <h3>Sposta in quale categoria?</h3>

        <p class="sheet-sub">Le carte selezionate verranno spostate.</p>

        <div class="cat-pick-list" id="moveCatList">

            <?php foreach ($categories_list as $c): ?>

                <button type="button" class="cat-pick-item" onclick="pickMoveCategory(<?= (int)$c['id'] ?>)">

                    <span class="cat-pick-dot" style="background:<?= htmlspecialchars(fc_color_or_default($c['color'])) ?>"></span>

                    <span class="cat-pick-name"><?= htmlspecialchars($c['name']) ?></span>

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

    document.getElementById('selectAllBtn').textContent = allChecked ? 'Tutte' : 'Nessuna';

    updateSelectCount();

}



function onBulkActionPick(action){

    const totalChecked = $all('.record-cb:checked').length;

    if (totalChecked === 0) { showToast('Seleziona almeno una carta'); return; }

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

            showToast('Per separare, seleziona solo sotto-carte');

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

        if (checkedFatherExists) { showToast('Non puoi aggregare una carta che ha già sotto-carte'); return; }

        if ($all('.child-cb:checked').length > 0) { showToast('Non puoi aggregare carte già figlie'); return; }



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

    if (!document.querySelector('.father-radio:checked')) { showToast('Seleziona una carta padre'); return; }

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

    document.getElementById('deleteSheetTitle').textContent = (count === 1) ? 'Eliminare questa carta?' : ('Eliminare ' + count + ' carte?');

    document.getElementById('deleteSheetSub').textContent = "L'operazione non può essere annullata.";

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

            ? "Questa carta ha 1 sotto-carta collegata: rimarrà orfana nel database."

            : ("Questa carta ha " + childCount + " sotto-carte collegate: rimarranno orfane nel database.");

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

        .catch(function(){ showToast('Errore di rete: la carta non è stata eliminata'); if (window.resetSwipeCard) window.resetSwipeCard(card); });

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

            showToast('Ordine non salvato: controlla la connessione');

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
        alert("Errore tecnico: Il codice HTML del selettore progetti non è presente nella pagina.");
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