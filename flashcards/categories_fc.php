<?php

require_once 'config.php';
require_once 'auth_check.php';

$current_user_id = (int)$utente_corrente['id'];

/* sec_db() DOPO auth_check.php: e' quest'ultimo a caricare security_core.php,
   dove la funzione e' definita. Chiamarla prima e' un errore fatale e la pagina
   risponde 500 senza dire altro. */
$conn = sec_db();



/* ==========================================================================

   EREDITARIETÀ COLORE

   - color_edited = 0  -> il colore è "ereditato": risale la catena dei padri

                          finché non trova un colore impostato esplicitamente

                          (color_edited = 1); se nessuno lo ha, usa il grigio

                          di default #A0A0A0.

   - color_edited = 1  -> il colore è stato scelto esplicitamente per QUELLA

                          categoria: quando cambia, va ripropagato a tutti i

                          discendenti con color_edited = 0, fermandosi ai rami

                          che hanno a loro volta un colore esplicito.

   ========================================================================== */

if (!function_exists('cs_resolve_color')) {

        /* $userId e' un parametro nuovo: prima la funzione leggeva qualunque
       categoria, quindi risaliva anche l'albero di un altro utente. */
    function cs_resolve_color($parentId, $userId) {
        $parentId = intval($parentId);
        $guard = 0;
        while ($parentId > 0 && $guard < 50) {
            $guard++;
            $row = sec_one("SELECT parent_id, color, color_edited FROM fc_categories WHERE id = ? AND user_id = ?",
                           [$parentId, (int)$userId]);
            if (!$row) break;

            if (intval($row['color_edited']) === 1 && !empty($row['color'])) {

                return $row['color'];

            }

            $parentId = intval($row['parent_id']);

        }

        return '#A0A0A0';

    }

}

if (!function_exists('cs_cascade_color')) {

        /* Questa e' la piu' pericolosa delle tre: SCRIVE. Senza "AND user_id"
       nella UPDATE, un id passato dal modulo cambiava i colori delle materie di
       un altro utente. */
    function cs_cascade_color($categoryId, $color, $userId, $depth = 0) {
        if ($depth > 50) return;
        $children = sec_all("SELECT id, color_edited FROM fc_categories WHERE parent_id = ? AND user_id = ?",
                            [(int)$categoryId, (int)$userId]);
        foreach ($children as $child) {
            if (intval($child['color_edited']) === 0) {
                sec_q("UPDATE fc_categories SET color = ? WHERE id = ? AND user_id = ?",
                      [$color, (int)$child['id'], (int)$userId]);
                cs_cascade_color($child['id'], $color, $userId, $depth + 1);
            }

            // color_edited === 1: questo ramo ha un colore proprio, non si tocca.

        }

    }

}

if (!function_exists('cs_apply_parent_color_update')) {

    // Da chiamare DOPO aver aggiornato il parent_id di una categoria (sposta,

    // o modifica del padre dal form di modifica): se la categoria eredita il

    // colore (color_edited = 0), lo ricalcola dal nuovo padre e lo ripropaga

    // ai suoi discendenti che a loro volta ereditano.

        function cs_apply_parent_color_update($catId, $newParentId, $userId) {
        $row = sec_one("SELECT color_edited FROM fc_categories WHERE id = ? AND user_id = ?",
                       [(int)$catId, (int)$userId]);
        if (!$row || intval($row['color_edited']) !== 0) return;
        $newColor = cs_resolve_color($newParentId, $userId);
        sec_q("UPDATE fc_categories SET color = ? WHERE id = ? AND user_id = ?",
              [$newColor, (int)$catId, (int)$userId]);
        cs_cascade_color($catId, $newColor, $userId);
    }

}



// --- AZIONE: INSERIMENTO / MODIFICA NOME E PADRE ---

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    /* Token di sessione, origine e limite di frequenza: prima non c'erano, e un
       altro sito poteva far cancellare una materia dal browser di chi e'
       connesso. */
    sec_guard_write((int)$current_user_id, 'fc_categorie', 0, 120, 60);

    /* elenco chiuso: un'azione non prevista non arriva agli if */
    $action = sec_enum($_POST['action'] ?? null, ['save', 'delete', 'move', 'color'], '');
    $okParam = '';
    if ($action === 'save') {
        $catId = sec_int($_POST['category_id'] ?? 0, 0);
        $catName = sec_str($_POST['category_name'] ?? '', 120);
        $parentId = sec_int($_POST['parent_id'] ?? 0, 0);
        $colorMode = sec_enum($_POST['color_mode'] ?? null, ['auto', 'manual'], 'auto');
        $manualColor = sec_str($_POST['category_color'] ?? '', 7);

        /* Il padre dev'essere suo, e la categoria modificata anche: senza questi
           controlli si poteva agganciare una propria materia sotto quella di un
           altro, o passare l'id di una materia altrui. */
        if ($parentId > 0 && !sec_owns('fc_categories', $parentId, (int)$current_user_id)) $parentId = 0;
        if ($catId > 0 && !sec_owns('fc_categories', $catId, (int)$current_user_id)) {
            header('Location: categories_fc.php'); exit;
        }

        if ($colorMode === 'manual' && preg_match('/^#[0-9A-Fa-f]{6}$/', $manualColor)) {
            $finalColor = $manualColor;
            $colorEditedFlag = 1;
        } else {
            $finalColor = cs_resolve_color($parentId, $current_user_id);
            $colorEditedFlag = 0;
        }
        if ($catId === 0) {
            sec_q("INSERT INTO fc_categories (name, parent_id, color, color_edited, user_id) VALUES (?, ?, ?, ?, ?)",
                  [$catName, $parentId, $finalColor, $colorEditedFlag, (int)$current_user_id]);
            $okParam = 'added';
        } else {
            sec_q("UPDATE fc_categories SET name = ?, parent_id = ?, color = ?, color_edited = ? WHERE id = ? AND user_id = ?",
                  [$catName, $parentId, $finalColor, $colorEditedFlag, $catId, (int)$current_user_id]);
            cs_cascade_color($catId, $finalColor, $current_user_id);
            $okParam = 'saved';
        }
    } elseif ($action === 'delete' && isset($_POST['delete_category_id'])) {
        $catId = sec_int($_POST['delete_category_id'] ?? 0, 1);
        if (sec_owns('fc_categories', $catId, (int)$current_user_id)) {
            sec_q("DELETE FROM fc_categories WHERE id = ? AND user_id = ?", [$catId, (int)$current_user_id]);
            $okParam = 'deleted';
        }

    } elseif ($action === 'move' && isset($_POST['move_category_id']) && isset($_POST['target_parent_id'])) {

                $moveId = sec_int($_POST['move_category_id'] ?? 0, 1);
        $targetParentId = sec_int($_POST['target_parent_id'] ?? 0, 0);
        /* sia la materia spostata sia la destinazione devono essere sue */
        if (sec_owns('fc_categories', $moveId, (int)$current_user_id)
            && ($targetParentId === 0 || sec_owns('fc_categories', $targetParentId, (int)$current_user_id))) {
            sec_q("UPDATE fc_categories SET parent_id = ? WHERE id = ? AND user_id = ?",
                  [$targetParentId, $moveId, (int)$current_user_id]);
            cs_apply_parent_color_update($moveId, $targetParentId, $current_user_id);
            $okParam = 'moved';
        }

    } elseif ($action === 'color' && isset($_POST['category_id'])) {

        $catId = sec_int($_POST['category_id'] ?? 0, 1);
        /* "auto" non e' un colore ma una rinuncia: si smette di averne uno
           proprio e si riprende quello ereditato dal padre. Senza questo modo
           il popup del colore poteva solo IMPORRE un colore, mai toglierlo. */
        $colorMode = sec_enum($_POST['color_mode'] ?? null, ['auto', 'manual'], 'manual');
        if (sec_owns('fc_categories', $catId, (int)$current_user_id)) {
            if ($colorMode === 'auto') {
                $catRow = sec_one("SELECT parent_id FROM fc_categories WHERE id = ? AND user_id = ?",
                                  [$catId, (int)$current_user_id]);
                $color = cs_resolve_color($catRow ? $catRow['parent_id'] : 0, $current_user_id);
                sec_q("UPDATE fc_categories SET color = ?, color_edited = 0 WHERE id = ? AND user_id = ?",
                      [$color, $catId, (int)$current_user_id]);
                cs_cascade_color($catId, $color, $current_user_id);
                $okParam = 'colored';
            } else {
                $color = sec_color($_POST['category_color'] ?? null, '');
                if ($color !== '') {
                    sec_q("UPDATE fc_categories SET color = ?, color_edited = 1 WHERE id = ? AND user_id = ?",
                          [$color, $catId, (int)$current_user_id]);
                    cs_cascade_color($catId, $color, $current_user_id);
                    $okParam = 'colored';
                }
            }
        }

    }



    header("Location: categories_fc.php" . ($okParam ? "?ok=$okParam" : ""));

    exit;

}





$result = sec_all("SELECT c.*, IFNULL(im.image_count, 0) AS image_count
          FROM fc_categories AS c
          LEFT JOIN (
              SELECT category_id, COUNT(*) AS image_count FROM fc_images GROUP BY category_id
          ) AS im ON c.id = im.category_id
          WHERE c.user_id = ?
          ORDER BY c.name", [(int)$current_user_id]);
$all_categories = [];
$categories_by_parent = [];
foreach ($result as $row) {

    $all_categories[$row['id']] = $row;

    $categories_by_parent[$row['parent_id']][] = $row['id'];

}



$totalImages = (int)(sec_one("SELECT COUNT(img.id) AS total
    FROM fc_images img
    INNER JOIN fc_categories cat ON img.category_id = cat.id
    WHERE cat.user_id = ?", [(int)$current_user_id])['total'] ?? 0);



$catCount = count($all_categories);

$rootIds = isset($categories_by_parent[0]) ? $categories_by_parent[0] : [];


// Mappa categoria -> progetto collegato (per il tag "progetto" sotto al nome).
// project_fc_categories collega un progetto a una categoria flashcard.
$cat_project_links = [];
$cplRes = sec_all("SELECT pfc.fc_category_id, p.id AS pid, p.name AS pname, p.color AS pcolor, pp.name AS parent_name
                   FROM project_fc_categories pfc
                   INNER JOIN projects p ON p.id = pfc.project_id AND p.user_id = ?
                   LEFT JOIN projects pp ON pp.id = p.parent_id AND pp.user_id = ?
                   WHERE pfc.fc_category_id IN (SELECT id FROM fc_categories WHERE user_id = ?)",
                  [(int)$current_user_id, (int)$current_user_id, (int)$current_user_id]);
if ($cplRes) {
    foreach ($cplRes as $cpl) {
        $pcol = (!empty($cpl['pcolor']) && preg_match('/^#[0-9A-Fa-f]{6}$/', $cpl['pcolor'])) ? $cpl['pcolor'] : '#8E8E93';
        $cat_project_links[(int)$cpl['fc_category_id']] = ['id' => (int)$cpl['pid'], 'name' => $cpl['pname'], 'parent_name' => $cpl['parent_name'], 'color' => $pcol];
    }
}



$colorPalette = ['#FF3B30', '#FF9500', '#FFCC00', '#34C759', '#00C7BE', '#30B0C7', '#32ADE6', '#007AFF', '#5856D6', '#AF52DE', '#FF2D55', '#A2845E'];



function renderCatRow($id, $level, $all_cats, $cats_by_parent) {
    global $cat_project_links;
    $cat = $all_cats[$id];
    $has_children = isset($cats_by_parent[$id]) && count($cats_by_parent[$id]) > 0;
    $can_delete = ($cat['image_count'] == 0 && !$has_children) ? 1 : 0;
    $display = ($level === 0) ? 'flex' : 'none';
    $pad = 12 + $level * 24;
    $nameEsc = sec_h($cat['name']);
    $color = (!empty($cat['color']) && preg_match('/^#[0-9A-Fa-f]{6}$/', $cat['color'])) ? $cat['color'] : '#007AFF';
    $parentId = (int)$cat['parent_id'];
    $parentName = '';
    $parentColor = '#A0A0A0';
    if ($parentId !== 0 && isset($all_cats[$parentId])) {
        $parentName = $all_cats[$parentId]['name'];
        $pColorRaw = $all_cats[$parentId]['color'];
        $parentColor = (!empty($pColorRaw) && preg_match('/^#[0-9A-Fa-f]{6}$/', $pColorRaw)) ? $pColorRaw : '#A0A0A0';
    }
    
    // JS array data per il tasto modifica
    $isColorEdited = (!empty($cat['color_edited']) && $cat['color_edited'] == 1) ? 'true' : 'false';
?>
<div class="cat-row" data-id="<?php echo (int)$id; ?>" data-parent="<?php echo (int)$cat['parent_id']; ?>" data-level="<?php echo (int)$level; ?>" data-name="<?php echo sec_h($nameEsc); ?>"
     data-parent-name="<?php echo sec_h($parentName); ?>" data-parent-color="<?php echo sec_h($parentColor); ?>"
     data-color="<?php echo sec_h($color); ?>" data-color-edited="<?php echo (int)($cat['color_edited'] ?? 0); ?>"
     style="display:<?php echo sec_h($display); ?>; --row-color:<?php echo sec_h($color); ?>;">
    
    <!-- TASTI SWIPE (Nascosti sotto) -->
    <div class="cat-swipe-actions">
        <button type="button" class="swipe-btn swipe-edit" aria-label="<?php te('comune.modifica'); ?>"
                        onclick="chiudiTuttiSwipe(); apriModalSalvaDaRiga(this)">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
        </button>
        <button type="button" class="swipe-btn swipe-addchild" aria-label="<?php te('vocab.cat_aggiungi_figlia'); ?>"
                        onclick="chiudiTuttiSwipe(); aggiungiFiglioDaRiga(this)">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z"/><path d="M12 11v5M9.5 13.5h5"/></svg>
        </button>
        <button type="button" class="swipe-btn swipe-move" aria-label="<?php te('vocab.cat_sposta_attr'); ?>"
                        onclick="avviaSpostaDaRiga(this)">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 9l-3 3 3 3M19 9l3 3-3 3M2 12h20"/></svg>
        </button>
        <?php if ($can_delete): ?>
        <button type="button" class="swipe-btn swipe-delete" aria-label="<?php te('comune.elimina'); ?>"
                        onclick="chiudiTuttiSwipe(); apriConfermaEliminaDaRiga(this)">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0-1 14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2L4 6"/></svg>
        </button>
        <?php endif; ?>
    </div>

    <!-- RIGA PRINCIPALE (In primo piano) -->
    <div class="cat-row-main" style="padding-left:<?php echo (int)$pad; ?>px;" data-cat-link="visualizzazione_fc.php?category_id=<?php echo (int)$id; ?>" onclick="fcCatRowNavigate(event, this)">
        <?php if ($has_children): ?>
        <button type="button" class="cat-arrow" onclick="event.stopPropagation(); toggleBranch(<?php echo (int)$id; ?>)" aria-label="<?php te('vocab.cat_espandi'); ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m9 6 6 6-6 6"/></svg>
        </button>
        <?php else: ?>
        <span class="cat-arrow-spacer"></span>
        <?php endif; ?>

        <span class="cat-icon <?php echo $level === 0 ? 'lvl0' : 'lvln'; ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z"/></svg>
        </span>

        <div class="cat-name-wrap">
        <span class="cat-name"><?php echo sec_h($nameEsc); ?></span>
        <?php $catProj = $cat_project_links[$id] ?? null; if ($catProj): ?>
        <a class="cat-proj-tag" href="progetti.php?id=<?php echo (int)$catProj['id']; ?>" style="--cp-color:<?php echo sec_h($catProj['color']); ?>;" onclick="event.stopPropagation();">
            <svg viewBox="0 0 24 24" width="9" height="9" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z"/></svg>
            <?php if (!empty($catProj['parent_name'])): ?><span class="cat-proj-anc"><?php echo sec_h($catProj['parent_name']); ?></span><span class="cat-proj-sep">•</span><?php endif; ?>
            <span class="cat-proj-leaf"><?php echo sec_h($catProj['name']); ?></span>
        </a>
        <?php endif; ?>
        </div>

        <span class="cat-badge"><?php echo (int)$cat['image_count']; ?></span>
        <button type="button" class="cat-kebab" aria-label="<?php te('vocab.cat_altre_azioni'); ?>"
            data-id="<?php echo (int)$id; ?>"
            data-name="<?php echo sec_h($nameEsc); ?>"
            data-parent="<?php echo (int)$cat['parent_id']; ?>"
            data-parent-name="<?php echo sec_h($parentName); ?>"
            data-parent-color="<?php echo sec_h($parentColor); ?>"
            data-candelete="<?php echo (int)$can_delete; ?>"
            data-color="<?php echo sec_h($color); ?>"
            data-color-edited="<?php echo (int)($cat['color_edited'] ?? 0); ?>"
            onclick="event.stopPropagation(); apriAzioni(this);">
            <svg viewBox="0 0 24 24" fill="currentColor">
                <circle cx="5" cy="12" r="1.7"/>
                <circle cx="12" cy="12" r="1.7"/>
                <circle cx="19" cy="12" r="1.7"/>
            </svg>
        </button>
    </div>
</div>
<?php
    if ($has_children) {
        foreach ($cats_by_parent[$id] as $childId) {
            renderCatRow($childId, $level + 1, $all_cats, $cats_by_parent);
        }
    }
}
?>

<!DOCTYPE html>

<html lang="it">

<head>

<meta charset="UTF-8">

<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=0, viewport-fit=cover">

<title><?php te('fc.cfc_titolo'); ?></title>

<link rel="stylesheet" href="<?php echo PWA_BASE; ?>categories_fc.css?v=<?php echo @filemtime(dirname(__FILE__) . '/categories_fc.css') ?: 1; ?>">

</head>

<body>



<div class="app-header">

    <div class="hdr-title"><?php te('vocab.cat_categorie'); ?></div>

    <div class="hdr-sub"><?php te('fc.cfc_conteggio', ['categorie' => (int)$catCount, 'carte' => (int)$totalImages]); ?></div>

    <div class="search-box">

        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>

        <input type="text" id="searchInput" placeholder="<?php te('vocab.cat_cerca'); ?>" oninput="filterCategories(this.value)" autocomplete="off">

        <button type="button" class="search-clear" id="searchClear" onclick="clearSearch()" aria-label="<?php te('vocab.cat_cancella_ricerca'); ?>">

            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>

        </button>

    </div>

</div>



<?php include 'sidebar.html'; ?>



<div class="page">



    <a href="visualizzazione_fc.php?category_id=0" class="hero-card">

        <div class="hero-icon">

            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M3.6 9h16.8M3.6 15h16.8M12 3a14.5 14.5 0 0 1 0 18M12 3a14.5 14.5 0 0 0 0 18"/></svg>

        </div>

        <div class="hero-text">

            <div class="hero-num"><?php echo (int)$totalImages; ?></div>

            <div class="hero-label"><?php te('fc.cs_tutte_materie'); ?></div>

        </div>

        <div class="hero-chev">

            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m9 6 6 6-6 6"/></svg>

        </div>

    </a>



    <?php if (empty($rootIds)): ?>

        <div class="empty-state">

            <div class="empty-icon">

                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z"/></svg>

            </div>

            <div class="empty-title"><?php te('vocab.cat_nessuna'); ?></div>

            <div class="empty-sub"><?php te('fc.cfc_nessuna_txt'); ?></div>

            <button type="button" class="btn-create-first" onclick="apriModalSalva({id:0,name:'',parentId:0,parentName:'',parentColor:'#A0A0A0',color:'#A0A0A0',colorEdited:false})">

                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>

                <?php te('vocab.cat_crea'); ?>

            </button>

        </div>

    <?php else: ?>

        <?php foreach ($rootIds as $i => $rid):

            $rootCat = $all_categories[$rid];

            $rootColor = (!empty($rootCat['color']) && preg_match('/^#[0-9A-Fa-f]{6}$/', $rootCat['color'])) ? $rootCat['color'] : '#007AFF';

            $delay = min($i * 45, 300);

        ?>

        <div class="materia-card" style="--accent:<?php echo sec_h($rootColor); ?>; animation-delay:<?php echo sec_h($delay); ?>ms;">

            <?php renderCatRow($rid, 0, $all_categories, $categories_by_parent); ?>

        </div>

        <?php endforeach; ?>

    <?php endif; ?>



</div>



<button type="button" class="fab" onclick="apriModalSalva({id:0,name:'',parentId:0,parentName:'',parentColor:'#A0A0A0',color:'#A0A0A0',colorEdited:false})" aria-label="<?php te('vocab.cat_aggiungi'); ?>">

    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>

</button>



<!-- SHEET: Nuova / Modifica categoria -->

<div id="sheetForm" class="modal-overlay">

    <div class="sheet-panel">

        <h3 class="sheet-title" id="modalSalvaTitolo">Nuova categoria</h3>

        <form id="formSalva" method="POST">

            <input type="hidden" name="csrf_token" value="<?php echo sec_h(sec_csrf_token()); ?>">

            <input type="hidden" name="action" value="save">

            <input type="hidden" name="category_id" id="form_cat_id" value="0">

            <input type="hidden" name="color_mode" id="form_color_mode" value="auto">

            <input type="hidden" name="category_color" id="form_color_value" value="#A0A0A0">



            <div class="field">

                <label for="form_cat_name"><?php te('vocab.cat_nome'); ?></label>

                <input type="text" name="category_name" id="form_cat_name" required placeholder="<?php te('fc.cfc_es_nome'); ?>">

            </div>



            <div class="field">

                <label><?php te('vocab.cat_padre'); ?></label>

                <?php

                    $cs_id = 'formParent';

                    $cs_hidden_name = 'parent_id';

                    $cs_placeholder = 'Nessuna (categoria principale)';

                    $cs_title = T('fc.cfc_padre_titolo');

                    $cs_show_all_option = true;

                    $cs_all_option_label = 'Nessuna (categoria principale)';

                    $cs_allow_create = false;

                    include 'category_selector.php';

                ?>

            </div>



            <div class="field">

                <label><?php te('vocab.cat_colore'); ?></label>

                <div class="color-preview-row">

                    <span class="color-preview-icon" id="formColorPreviewIcon">

                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z"/></svg>

                    </span>

                    <span class="color-preview-name" id="formColorPreviewName"><?php te('vocab.cat_auto_eredita'); ?></span>

                </div>



                <div class="color-grid" id="formColorGrid">

                    <button type="button" class="color-swatch color-swatch-auto selected" data-auto="1" onclick="setFormColorAuto()" title="Automatico">

                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 9-9"/><path d="M3 4v8h8"/></svg>

                    </button>

                    <?php foreach ($colorPalette as $ci => $hex): ?>

                    <button type="button" class="color-swatch" style="--sw:<?php echo sec_h($hex); ?>; animation-delay:<?php echo (int)($ci * 22); ?>ms;" data-hex="<?php echo sec_h($hex); ?>" onclick="setFormColorManual('<?php echo sec_h($hex); ?>')">

                        <svg class="check-ic" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>

                    </button>

                    <?php endforeach; ?>

                </div>



                <div class="custom-color-row">

                    <input type="color" id="formCustomColorInput" value="#007AFF" oninput="setFormColorManual(this.value)">

                    <span class="cc-label"><?php te('vocab.cat_colore_pers'); ?></span>

                    <span class="cc-hex" id="formCustomColorHex">#A0A0A0</span>

                </div>

            </div>



            <div class="sheet-actions">

                <button type="button" class="btn btn-cancel" onclick="chiudiModals()"><?php te('comune.annulla'); ?></button>

                <button type="submit" class="btn btn-primary"><?php te('comune.salva'); ?></button>

            </div>

        </form>

    </div>

</div>



<!-- SHEET: Sposta categoria -->

<form id="formSposta" method="POST" style="display:none;">
    <input type="hidden" name="csrf_token" value="<?php echo sec_h(sec_csrf_token()); ?>">
    <input type="hidden" name="action" value="move">
    <input type="hidden" name="move_category_id" id="move_cat_id">
    <input type="hidden" name="target_parent_id" id="target_parent_id">
</form>

<button type="button" id="btnOpenMoveSelector" data-cs-open="cs-modal-moveCategory" style="display:none;"></button>

<?php
    $cs_id = 'moveCategory';
    $cs_title = T('fc.cfc_sposta_titolo');
    $cs_show_all_option = true;
    $cs_all_option_label = 'Porta in cima (nessun padre)';
    $cs_allow_create = false; // DISABILITA IL TASTO PER CREARE UNA NUOVA MATERIA
    $cs_render_trigger = false; // Nascondiamo il bottone standard, lo apriamo via JS
    include 'category_selector.php';
?>



<!-- SHEET: Azioni categoria (kebab) -->

<div id="sheetActions" class="modal-overlay">

    <div class="sheet-panel">

        <h3 class="sheet-title" id="azioniTitolo"><?php te('vocab.cat_categoria'); ?></h3>

        <div class="action-list">

            <button type="button" class="action-item" id="azioneModifica">

                <span class="a-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg></span>

                Modifica

            </button>

            <button type="button" class="action-item" id="azioneAggiungiFiglia">

                <span class="a-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z"/><path d="M12 11v5M9.5 13.5h5"/></svg></span>

                <?php te('vocab.cat_aggiungi_figlia'); ?>

            </button>

            <button type="button" class="action-item" id="azioneColore">

                <span class="a-ic color-wheel"></span>

                Colore

            </button>

            <button type="button" class="action-item" id="azioneSposta">

                <span class="a-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 9l-3 3 3 3M19 9l3 3-3 3M2 12h20"/></svg></span>

                Sposta

            </button>

            <button type="button" class="action-item danger" id="azioneElimina">

                <span class="a-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0-1 14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2L4 6"/></svg></span>

                Elimina

            </button>

        </div>

        <button type="button" class="btn btn-cancel" style="width:100%;" onclick="chiudiModals()"><?php te('comune.chiudi'); ?></button>

    </div>

</div>



<!-- SHEET: Colore cartella -->

<div id="sheetColor" class="modal-overlay">

    <div class="sheet-panel">

        <h3 class="sheet-title"><?php te('fc.cfc_colore_cartella'); ?></h3>



        <div class="color-preview-row">

            <span class="color-preview-icon" id="colorPreviewIcon">

                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z"/></svg>

            </span>

            <span class="color-preview-name" id="colorPreviewName"><?php te('vocab.cat_categoria'); ?></span>

        </div>



        <div class="color-grid" id="colorGrid">

            <?php /* Prima opzione sempre il colore ereditato dal padre: senza
                     questa il popup poteva solo IMPORRE un colore, mai
                     toglierlo per tornare a quello del padre. */ ?>

            <button type="button" class="color-swatch color-swatch-auto" id="colorSwatchAuto" data-auto="1" onclick="selectColorAuto()" title="<?php te('vocab.cat_auto_eredita'); ?>">

                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 9-9"/><path d="M3 4v8h8"/></svg>

            </button>

            <?php foreach ($colorPalette as $ci => $hex): ?>

            <button type="button" class="color-swatch" style="--sw:<?php echo sec_h($hex); ?>; animation-delay:<?php echo (int)($ci * 22); ?>ms;" data-hex="<?php echo sec_h($hex); ?>" onclick="selectColor('<?php echo sec_h($hex); ?>')">

                <svg class="check-ic" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>

            </button>

            <?php endforeach; ?>

        </div>



        <div class="custom-color-row">

            <input type="color" id="customColorInput" value="#007AFF" oninput="selectColor(this.value)">

            <span class="cc-label"><?php te('vocab.cat_colore_pers'); ?></span>

            <span class="cc-hex" id="customColorHex">#007AFF</span>

        </div>



        <form id="formColor" method="POST" style="display:none;">

            <input type="hidden" name="csrf_token" value="<?php echo sec_h(sec_csrf_token()); ?>">

            <input type="hidden" name="action" value="color">

            <input type="hidden" name="category_id" id="color_cat_id">

            <input type="hidden" name="color_mode" id="color_cat_mode" value="manual">

            <input type="hidden" name="category_color" id="color_cat_value">

        </form>



        <div class="sheet-actions">

            <button type="button" class="btn btn-cancel" onclick="annullaColore()"><?php te('comune.annulla'); ?></button>

            <button type="button" class="btn btn-primary" onclick="salvaColore()"><?php te('comune.salva'); ?></button>

        </div>

    </div>

</div>



<!-- SHEET: Conferma eliminazione -->

<div id="sheetConfirmDelete" class="modal-overlay">

    <div class="sheet-panel">

        <div class="confirm-icon">

            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0-1 14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2L4 6"/></svg>

        </div>

        <h3 class="sheet-title"><?php te('vocab.cat_eliminare'); ?></h3>

        <p class="confirm-text"><?php th('vocab.cat_stai_eliminando', ['nome' => '<b id="confermaEliminaNome"></b>']); ?></p>

        <div class="sheet-actions">

            <button type="button" class="btn btn-cancel" onclick="chiudiModals()"><?php te('comune.annulla'); ?></button>

            <button type="button" class="btn btn-danger" onclick="confermaEliminaSubmit()"><?php te('comune.elimina'); ?></button>

        </div>

    </div>

</div>



<form id="formDelete" method="POST" style="display:none;">

    <input type="hidden" name="csrf_token" value="<?php echo sec_h(sec_csrf_token()); ?>">

    <input type="hidden" name="action" value="delete">

    <input type="hidden" name="delete_category_id" id="delete_cat_id">

</form>



<?php echo nisama_i18n_json(['comune', 'fc', 'vocab']); ?>
<script>

/* ======================================================================
   Il nome della materia non entra piu' dentro onclick.
   Prima ci finiva passato da addslashes(), che protegge dagli apici ma non dal
   carattere "<": una materia il cui nome contenesse un tag di chiusura di
   script avrebbe chiuso il blocco, e il resto della pagina sarebbe stato
   interpretato come HTML. Ora il dato sta negli attributi data- della riga,
   scritti con sec_h(), e da li' il JavaScript lo legge come testo.
   ====================================================================== */
function rigaFcDi(el) { return el.closest('.cat-row'); }

function apriModalSalvaDaRiga(btn) {
    var r = rigaFcDi(btn);
    apriModalSalva({
        id:          parseInt(r.dataset.id, 10),
        name:        r.dataset.name,
        parentId:    parseInt(r.dataset.parent, 10),
        parentName:  r.dataset.parentName || '',
        parentColor: r.dataset.parentColor || '#A0A0A0',
        color:       r.dataset.color,
        colorEdited: r.dataset.colorEdited === '1'
    });
}
function avviaSpostaDaRiga(btn) {
    var r = rigaFcDi(btn);
    avviaSposta(parseInt(r.dataset.id, 10), r.dataset.name);
}
function apriConfermaEliminaDaRiga(btn) {
    var r = rigaFcDi(btn);
    apriConfermaElimina(parseInt(r.dataset.id, 10), r.dataset.name);
}
function aggiungiFiglioDaRiga(btn) {
    var r = rigaFcDi(btn);
    apriAggiungiFiglio(parseInt(r.dataset.id, 10), r.dataset.name, r.dataset.color);
}
/* La categoria toccata diventa il padre: si riusa lo stesso foglio di
   creazione, solo con il padre gia' precompilato e il nome pronto da
   scrivere (il fuoco arriva dal focus() gia' presente in apriModalSalva). */
function apriAggiungiFiglio(parentId, parentName, parentColor) {
    apriModalSalva({
        id: 0, name: '', parentId: parentId,
        parentName: parentName || '', parentColor: parentColor || '#A0A0A0',
        color: parentColor || '#A0A0A0', colorEdited: false
    });
}
    if (window.matchMedia("(pointer:coarse)").matches) {
        document.body.classList.add("touch-device");
    }

    /* ---------- Apertura / chiusura sheet ---------- */

    function openSheet(el){

        el.style.display = 'flex';

        el.classList.remove('closing');

    }

    function closeSheet(el){

        el.classList.add('closing');

        setTimeout(function(){

            el.style.display = 'none';

            el.classList.remove('closing');

        }, 240);

    }

    function chiudiModals(){

        document.querySelectorAll('.modal-overlay').forEach(function(ov){

            if (ov.style.display === 'flex') closeSheet(ov);

        });

    }



    /* ---------- Espandi / comprimi albero categorie ---------- */

    function toggleBranch(id){

        var row = document.querySelector('.cat-row[data-id="' + id + '"]');

        var arrow = row.querySelector('.cat-arrow');

        var isOpen = arrow.classList.contains('open');

        if (isOpen) {

            arrow.classList.remove('open');

            comprimiFigli(id);

        } else {

            arrow.classList.add('open');

            espandiFigli(id);

        }

    }

    function espandiFigli(parentId){

        var children = document.querySelectorAll('.cat-row[data-parent="' + parentId + '"]');

        children.forEach(function(row){

            row.style.display = 'flex';

            row.style.animation = 'none';

            void row.offsetWidth;

            row.style.animation = '';

            row.classList.add('row-anim');

            var subId = row.getAttribute('data-id');

            var subArrow = row.querySelector('.cat-arrow');

            if (subArrow && subArrow.classList.contains('open')) espandiFigli(subId);

        });

    }

    function comprimiFigli(parentId){

        var children = document.querySelectorAll('.cat-row[data-parent="' + parentId + '"]');

        children.forEach(function(row){

            row.style.display = 'none';

            var subId = row.getAttribute('data-id');

            comprimiFigli(subId);

        });

    }



    /* ---------- Ricerca ---------- */

    function filterCategories(raw){

        var q = raw.trim().toLowerCase();

        var rows = document.querySelectorAll('.cat-row');

        var clearBtn = document.getElementById('searchClear');

        clearBtn.style.display = q ? 'flex' : 'none';



        if (!q) {

            rows.forEach(function(r){ r.style.display = (r.dataset.level === '0') ? 'flex' : 'none'; });

            document.querySelectorAll('.cat-arrow.open').forEach(function(a){ a.classList.remove('open'); });

            document.querySelectorAll('.materia-card').forEach(function(c){ c.style.display = ''; });

            return;

        }



        var byId = {};

        rows.forEach(function(r){ byId[r.dataset.id] = r; });



        var visible = new Set();

        rows.forEach(function(r){

            if (r.dataset.name.toLowerCase().indexOf(q) !== -1) {

                var cur = r;

                while (cur) {

                    visible.add(cur.dataset.id);

                    var pid = cur.dataset.parent;

                    cur = (pid && pid !== '0') ? byId[pid] : null;

                }

            }

        });



        rows.forEach(function(r){

            var show = visible.has(r.dataset.id);

            r.style.display = show ? 'flex' : 'none';

            if (show) {

                var arrow = r.querySelector('.cat-arrow');

                if (arrow) arrow.classList.add('open');

            }

        });



        document.querySelectorAll('.materia-card').forEach(function(card){

            var any = Array.prototype.some.call(card.querySelectorAll('.cat-row'), function(r){ return visible.has(r.dataset.id); });

            card.style.display = any ? '' : 'none';

        });

    }

    function clearSearch(){

        var input = document.getElementById('searchInput');

        input.value = '';

        filterCategories('');

        input.focus();

    }



    /* ---------- Sheet: Nuova / Modifica ---------- */

    var formColorManual = false;

    var formColorValue = '#A0A0A0';

    var formParentColor = '#A0A0A0';



    function apriModalSalva(opts){

        document.getElementById('form_cat_id').value = opts.id;

        document.getElementById('form_cat_name').value = opts.name;

        document.getElementById('modalSalvaTitolo').textContent = (opts.id == 0) ? T('vocab.cat_nuova') : T('vocab.cat_modifica_titolo');



        formColorManual = !!opts.colorEdited;

        formParentColor = opts.parentColor || '#A0A0A0';

        formColorValue = formColorManual ? (opts.color || '#A0A0A0') : formParentColor;

        updateFormColorUI();



        if (window.csSetValue) {

            var parentLabel = (opts.parentId == 0) ? 'Nessuna (categoria principale)' : opts.parentName;

            window.csSetValue('cs-modal-formParent', opts.parentId, parentLabel, opts.parentColor);

        }

        csExcludeSubtree('cs-modal-formParent', opts.id);



        openSheet(document.getElementById('sheetForm'));

        setTimeout(function(){ document.getElementById('form_cat_name').focus(); }, 350);

    }



    /* Nasconde dal selettore "categoria padre" la categoria che si sta

       modificando (non può essere padre di se stessa). */

    function csExcludeSubtree(modalId, excludeId){

        var overlay = document.getElementById(modalId);

        if (!overlay) return;

        overlay.querySelectorAll('.cs-row-wrap').forEach(function(w){ w.style.display = ''; });

        if (!excludeId || excludeId == 0) return;

        var nameEl = overlay.querySelector('[data-cs-select="' + excludeId + '"]');

        var wrap = nameEl ? nameEl.closest('.cs-row-wrap') : null;

        if (wrap) wrap.style.display = 'none';

    }



    /* Quando cambia la categoria padre selezionata, se il colore è ancora in

       automatico aggiorniamo subito l'anteprima con il colore ereditato. */

    /* Intercettiamo le selezioni di QUALSIASI category_selector nella pagina */
    document.addEventListener('categoryselected', function(e){
        if (!e.detail) return;
        
        // 1. Gestione del selettore per "Sposta"
        if (e.detail.uid === 'moveCategory') {
            document.getElementById('target_parent_id').value = e.detail.id;
            document.getElementById('formSposta').submit();
        }
        
        // 2. Gestione del selettore "Categoria Padre" nel form di Creazione/Modifica
        if (e.detail.uid === 'formParent') {
            formParentColor = e.detail.color || '#A0A0A0';
            if (!formColorManual) {
                formColorValue = formParentColor;
                updateFormColorUI();
            }
        }
    });



    function setFormColorAuto(){

        formColorManual = false;

        formColorValue = formParentColor;

        updateFormColorUI();

    }

    function setFormColorManual(hex){

        formColorManual = true;

        formColorValue = hex;

        updateFormColorUI();

    }

    function updateFormColorUI(){

        document.getElementById('form_color_mode').value = formColorManual ? 'manual' : 'auto';

        document.getElementById('form_color_value').value = formColorValue;

        document.getElementById('formColorPreviewIcon').style.setProperty('--row-color', formColorValue);

        document.getElementById('formColorPreviewName').textContent = formColorManual ? T('vocab.cat_colore_pers') : T('vocab.cat_auto_eredita');

        document.getElementById('formCustomColorInput').value = formColorValue;

        document.getElementById('formCustomColorHex').textContent = formColorValue.toUpperCase();

        document.querySelectorAll('#formColorGrid .color-swatch').forEach(function(sw){

            if (sw.classList.contains('color-swatch-auto')) {

                sw.classList.toggle('selected', !formColorManual);

            } else {

                sw.classList.toggle('selected', formColorManual && (sw.dataset.hex || '').toLowerCase() === formColorValue.toLowerCase());

            }

        });

    }



    /* ---------- Sheet: Azioni (kebab) ---------- */

    function apriAzioni(btn){

        var id = btn.dataset.id;

        var name = btn.dataset.name;

        var parentId = btn.dataset.parent;

        var parentName = btn.dataset.parentName || '';

        var parentColor = btn.dataset.parentColor || '#A0A0A0';

        var color = btn.dataset.color || '#007AFF';

        var colorEdited = btn.dataset.colorEdited === '1';

        var canDelete = btn.dataset.candelete === '1';



        document.getElementById('azioniTitolo').textContent = name;



        document.getElementById('azioneModifica').onclick = function(){

            chiudiModals();

            setTimeout(function(){

                apriModalSalva({

                    id: id, name: name, parentId: parentId,

                    parentName: parentName, parentColor: parentColor,

                    color: color, colorEdited: colorEdited

                });

            }, 260);

        };

        document.getElementById('azioneColore').onclick = function(){

            chiudiModals();

            setTimeout(function(){ apriColore(id, name, color, colorEdited, parentColor); }, 260);

        };

        document.getElementById('azioneAggiungiFiglia').onclick = function(){

            chiudiModals();

            setTimeout(function(){ apriAggiungiFiglio(id, name, color); }, 260);

        };

        document.getElementById('azioneSposta').onclick = function(){
            chiudiModals();
            setTimeout(function(){
                // 1. Imposto l'id della categoria che sto muovendo
                document.getElementById('move_cat_id').value = id;
                
                // 2. Nascondo questa categoria dall'albero del selettore
                csExcludeSubtree('cs-modal-moveCategory', id);
                
                // 3. Modifico dinamicamente il titolo del popup per renderlo chiaro
                var moveModal = document.getElementById('cs-modal-moveCategory');
                if(moveModal) {
                    var titleEl = moveModal.querySelector('.cs-sheet-title');
                    if(titleEl) titleEl.innerHTML = 'Sposta <span style="color:var(--blue);">"' + name + '"</span>';
                }
                
                // 4. Clicco il bottone invisibile per aprire il modale
                document.getElementById('btnOpenMoveSelector').click();
            }, 260);
        };

        var delBtn = document.getElementById('azioneElimina');

        if (canDelete) {

            delBtn.style.display = 'flex';

            delBtn.onclick = function(){

                chiudiModals();

                setTimeout(function(){ apriConfermaElimina(id, name); }, 260);

            };

        } else {

            delBtn.style.display = 'none';

        }



        openSheet(document.getElementById('sheetActions'));

    }



    /* ---------- Sheet: Colore cartella (con anteprima live) ---------- */

    var colorTargetId = null;

    var colorManual = true;

    var colorParentColor = '#A0A0A0';

    var colorOriginal = '#007AFF';

    var colorOriginalManual = true;

    var colorCurrent = '#007AFF';



    /* colorEdited/parentColor arrivano dagli stessi dati che gia' viaggiano

       per ogni riga (data-color-edited, data-parent-color): servono per

       sapere se si parte da "colore proprio" o da "eredita dal padre", e

       cosa mostrare per l'opzione automatica. */

    function apriColore(id, name, color, colorEdited, parentColor){

        colorTargetId = id;

        colorManual = !!colorEdited;

        colorParentColor = parentColor || '#A0A0A0';

        colorOriginal = colorManual ? color : colorParentColor;

        colorOriginalManual = colorManual;

        colorCurrent = colorOriginal;



        document.getElementById('color_cat_id').value = id;

        document.getElementById('colorPreviewName').textContent = name;



        updateColorUI();

        openSheet(document.getElementById('sheetColor'));

    }



    function selectColorAuto(){

        colorManual = false;

        colorCurrent = colorParentColor;

        updateColorUI();

    }



    function selectColor(hex){

        colorManual = true;

        colorCurrent = hex;

        updateColorUI();

    }



    function updateColorUI(){

        document.getElementById('customColorInput').value = colorCurrent;

        document.getElementById('customColorHex').textContent = colorCurrent.toUpperCase();

        applyColorPreview(colorCurrent);

        highlightSwatch();

    }



    function highlightSwatch(){

        var norm = colorCurrent.toLowerCase();

        document.querySelectorAll('#colorGrid .color-swatch').forEach(function(sw){

            if (sw.dataset.auto) {

                sw.classList.toggle('selected', !colorManual);

            } else if (sw.dataset.hex) {

                sw.classList.toggle('selected', colorManual && sw.dataset.hex.toLowerCase() === norm);

            }

        });

    }



    function applyColorPreview(hex){

        document.getElementById('colorPreviewIcon').style.setProperty('--row-color', hex);



        /* anteprima live sulla riga reale, dietro al foglio */

        var row = document.querySelector('.cat-row[data-id="' + colorTargetId + '"]');

        if (row) {

            row.style.setProperty('--row-color', hex);

            if (row.dataset.level === '0') {

                var card = row.closest('.materia-card');

                if (card) card.style.setProperty('--accent', hex);

            }

        }

        var kebab = document.querySelector('.cat-kebab[data-id="' + colorTargetId + '"]');

        if (kebab) kebab.dataset.color = hex;

    }



    function annullaColore(){

        applyColorPreview(colorOriginal);

        chiudiModals();

    }



    function salvaColore(){

        document.getElementById('color_cat_mode').value = colorManual ? 'manual' : 'auto';

        document.getElementById('color_cat_value').value = colorCurrent;

        document.getElementById('formColor').submit();

    }



    /* ---------- Sheet: Conferma eliminazione ---------- */

    function apriConfermaElimina(id, name){

        document.getElementById('delete_cat_id').value = id;

        document.getElementById('confermaEliminaNome').textContent = '"' + name + '"';

        openSheet(document.getElementById('sheetConfirmDelete'));

    }

    function confermaEliminaSubmit(){

        document.getElementById('formDelete').submit();

    }



    /* ---------- Toast di conferma dopo redirect ---------- */

    function showToast(text){

        var t = document.createElement('div');

        t.className = 'toast';

        t.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg><span>' + text + '</span>';

        document.body.appendChild(t);

        requestAnimationFrame(function(){ t.classList.add('show'); });

        setTimeout(function(){

            t.classList.remove('show');

            setTimeout(function(){ t.remove(); }, 320);

        }, 2200);

    }

    (function(){

        var params = new URLSearchParams(window.location.search);

        var ok = params.get('ok');

        var messages = { added: T('vocab.cat_creata'), saved: T('vocab.cat_salvate'), deleted: T('vocab.cat_eliminata'), moved: T('vocab.cat_spostata'), colored: T('vocab.cat_colore_ok') };

        if (ok && messages[ok]) {

            showToast(messages[ok]);

            history.replaceState(null, '', window.location.pathname);

        }

    })();

    /* ---------- Gestione Swipe (Da Sinistra verso Destra) ----------
   I tasti dietro la riga restano display:none finche' non si sente un vero
   trascinamento orizzontale: prima stavano sempre nel flusso (nascosti solo
   dall'overlap con la riga sopra) ed era facile vederli spuntare per un
   istante, specie durante lo scroll verticale della pagina. Ora compaiono
   SOLO quando lo swipe e' confermato, sulla riga giusta soltanto. */
(function(){

    if (!window.matchMedia("(hover:none) and (pointer:coarse)").matches){
        return;
    }

    var SOGLIA = 8;   /* px minimi prima di considerarlo un vero swipe orizzontale */

    var activeRow = null;
    var activeActions = null;
    var startX = 0, startY = 0;
    var isDragging = false;
    var confermato = false;
    var maxSwipe = 0;

    window.chiudiTuttiSwipe = function() {
        document.querySelectorAll('.cat-row-main.swiped-open').forEach(function(r) {
            r.style.transform = 'translateX(0)';
            r.classList.remove('swiped-open');
        });
        /* pulizia difensiva: qualunque riga fosse rimasta rivelata a meta'
           gesto, a prescindere da quale sia activeRow in questo momento */
        document.querySelectorAll('.cat-swipe-actions.revealing').forEach(function(a){
            a.classList.remove('revealing');
        });
        activeRow = null;
        activeActions = null;
    };

    document.addEventListener('touchstart', function(e) {
        if (e.target.closest('.cat-swipe-actions')) return; // Permette il click sui tasti svelati

        var rowMain = e.target.closest('.cat-row-main');
        if (!rowMain) {
            chiudiTuttiSwipe();
            return;
        }

        if (activeRow && activeRow !== rowMain) chiudiTuttiSwipe();

        activeRow = rowMain;
        activeActions = rowMain.parentElement.querySelector('.cat-swipe-actions');
        if (!activeActions) { activeRow = null; return; }

        /* La larghezza NON si misura qui: i tasti partono display:none, e
           offsetWidth su un elemento non renderizzato vale 0. Si misura al
           momento della conferma, subito dopo averli resi visibili (vedi
           touchmove). Con la misura presa qui il primo swipe si fermava dopo
           una ventina di pixel, e solo il secondo - trovando i tasti rimasti
           visibili dal tentativo precedente - funzionava. */
        maxSwipe = 0;
        startX = e.touches[0].clientX;
        startY = e.touches[0].clientY;
        isDragging = true;
        confermato = rowMain.classList.contains('swiped-open');   /* gia' aperta: nessuna soglia da riconfermare */
        rowMain.style.transition = 'none';
    }, {passive: true});

    document.addEventListener('touchmove', function(e) {
        if (!isDragging || !activeRow) return;
        var dx = e.touches[0].clientX - startX;
        var dy = e.touches[0].clientY - startY;

        if (!confermato) {
            // Se lo scroll è verticale nativo, annulla lo swipe orizzontale
            if (Math.abs(dy) > Math.abs(dx) && Math.abs(dy) > 10) {
                isDragging = false;
                chiudiTuttiSwipe();
                return;
            }
            // Sotto soglia: ambiguo, si aspetta il prossimo movimento senza
            // rivelare ancora nulla (evita il lampo su un piccolo tremolio).
            if (Math.abs(dx) < SOGLIA) return;
            confermato = true;
            activeActions.classList.add('revealing');
            maxSwipe = activeActions.offsetWidth;   /* ora sono a schermo: la misura e' vera */
        }
        /* Riga gia' aperta da prima: i tasti sono visibili ma la misura non e'
           ancora stata presa in questo gesto. */
        if (!maxSwipe) maxSwipe = activeActions.offsetWidth;

        if (dx > 0) {
            // Swipe da sinistra a destra (Rivela i tasti a sinistra)
            var tx = Math.min(dx, maxSwipe + 20); // 20px di effetto "molla" per fluidità
            activeRow.style.transform = 'translateX(' + tx + 'px)';
            e.preventDefault();
        } else if (dx < 0) {
            // Piccolo rimbalzo se provi a scorrere verso sinistra
            var tx = Math.max(dx, -15);
            activeRow.style.transform = 'translateX(' + tx + 'px)';
        }
    }, {passive: false});

    document.addEventListener('touchend', function(e) {
        if (!isDragging || !activeRow) return;
        isDragging = false;
        activeRow.style.transition = 'transform 0.3s cubic-bezier(0.32, 0.72, 0, 1)';

        var tx = 0;
        var transformStr = activeRow.style.transform;
        if (transformStr && transformStr.indexOf('translateX') !== -1) {
            tx = parseFloat(transformStr.replace('translateX(', ''));
        }

        // Se hai superato la metà della larghezza dei tasti, scatta l'apertura
        if (tx > maxSwipe / 2) {
            activeRow.style.transform = 'translateX(' + maxSwipe + 'px)';
            activeRow.classList.add('swiped-open');
            /* resta 'revealing': i tasti devono restare visibili e toccabili
               finche' la riga e' aperta */
        } else {
            activeRow.style.transform = 'translateX(0)';
            activeRow.classList.remove('swiped-open');
            if (activeActions) activeActions.classList.remove('revealing');
            activeRow = null;
            activeActions = null;
        }
    });
})();

// Navigazione dell'intera riga categoria verso visualizzazione_fc.
// Freccetta, tag progetto e kebab fermano già la propagazione, quindi qui
// arrivano solo i click sul "corpo" della riga. Se la riga è aperta in swipe,
// un tap la richiude soltanto (non naviga).
function fcCatRowNavigate(e, el){
    if (el.classList.contains('swiped-open')) { if (window.chiudiTuttiSwipe) chiudiTuttiSwipe(); return; }
    if (document.querySelector('.cat-row-main.swiped-open')) { if (window.chiudiTuttiSwipe) chiudiTuttiSwipe(); return; }
    var link = el.getAttribute('data-cat-link');
    if (!link) return;
    /* Si ricorda quale categoria si sta per aprire: se poi si torna qui
       (tasto Home dentro la categoria, o il "indietro" del browser), la si
       ritrova gia' scoperta invece di ripartire dall'albero tutto chiuso. */
    var row = el.closest('.cat-row');
    if (row) { try { sessionStorage.setItem('nisama_cat_tree_focus', row.dataset.id); } catch(err){} }
    window.location.href = link;
}

/* ---------- Ritorno da una categoria: la si ritrova gia' scoperta ----------
   La chiave la scrive sia questa pagina (al clic su una riga) sia
   visualizzazione_fc.php (a ogni sua apertura, cosi' vale anche se dentro ci
   si e' spostati su una sotto-cartella senza passare di qui). E' un
   ricordo "usa e getta": una volta applicato si toglie, cosi' un giro
   successivo per l'albero non continua a riportare sempre li'. */
function fcRestoreTreeFocus(){
    var id = null;
    try { id = sessionStorage.getItem('nisama_cat_tree_focus'); } catch(err){}
    if (!id) return;
    try { sessionStorage.removeItem('nisama_cat_tree_focus'); } catch(err){}

    var row = document.querySelector('.cat-row[data-id="' + id + '"]');
    if (!row) return;

    // Catena degli antenati, dal piu' lontano (radice) al piu' vicino.
    var catena = [];
    var cur = row;
    var guardia = 0;
    while (cur && guardia < 60) {
        guardia++;
        var pid = cur.dataset.parent;
        if (!pid || pid === '0') break;
        catena.unshift(pid);
        cur = document.querySelector('.cat-row[data-id="' + pid + '"]');
    }

    catena.forEach(function(pid){
        var r = document.querySelector('.cat-row[data-id="' + pid + '"]');
        if (!r) return;
        var arrow = r.querySelector('.cat-arrow');
        if (arrow) arrow.classList.add('open');
        espandiFigli(pid);
    });

    function porta(){
        var h = document.querySelector('.app-header');
        var alto = h ? h.offsetHeight + 14 : 90;
        var y = row.getBoundingClientRect().top + window.pageYOffset - alto;
        window.scrollTo(0, Math.max(0, y));
    }
    porta();
    /* le righe appena espanse animano l'ingresso: si ripassa quando e' finita */
    setTimeout(porta, 220);

    row.classList.add('cat-focus-flash');
    setTimeout(function(){ row.classList.remove('cat-focus-flash'); }, 2400);
}
fcRestoreTreeFocus();

// Helper per lo spostamento via swipe
function avviaSposta(id, name) {
    chiudiTuttiSwipe();
    document.getElementById('move_cat_id').value = id;
    csExcludeSubtree('cs-modal-moveCategory', id);
    var moveModal = document.getElementById('cs-modal-moveCategory');
    if(moveModal) {
        var titleEl = moveModal.querySelector('.cs-sheet-title');
        if(titleEl) titleEl.innerHTML = 'Sposta <span style="color:var(--blue);">"' + name + '"</span>';
    }
    document.getElementById('btnOpenMoveSelector').click();
}

</script>



</body>

</html>