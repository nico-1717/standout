<?php

require_once 'config.php';

$conn = mysqli_connect(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);

if (!$conn) { die("Connessione fallita: " . mysqli_connect_error()); }

require_once 'auth_check.php';

$current_user_id = $utente_corrente['id'];



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

    function cs_resolve_color($conn, $parentId) {

        $parentId = intval($parentId);

        $guard = 0;

        while ($parentId > 0 && $guard < 50) {

            $guard++;

            $res = mysqli_query($conn, "SELECT parent_id, color, color_edited FROM fc_categories WHERE id = " . $parentId);

            $row = $res ? mysqli_fetch_assoc($res) : null;

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

    function cs_cascade_color($conn, $categoryId, $color, $depth = 0) {

        if ($depth > 50) return;

        $res = mysqli_query($conn, "SELECT id, color_edited FROM fc_categories WHERE parent_id = " . intval($categoryId));

        if (!$res) return;

        $children = [];

        while ($row = mysqli_fetch_assoc($res)) { $children[] = $row; }

        $colorEsc = mysqli_real_escape_string($conn, $color);

        foreach ($children as $child) {

            if (intval($child['color_edited']) === 0) {

                mysqli_query($conn, "UPDATE fc_categories SET color = '$colorEsc' WHERE id = " . intval($child['id']));

                cs_cascade_color($conn, $child['id'], $color, $depth + 1);

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

    function cs_apply_parent_color_update($conn, $catId, $newParentId) {

        $res = mysqli_query($conn, "SELECT color_edited FROM fc_categories WHERE id = " . intval($catId));

        $row = $res ? mysqli_fetch_assoc($res) : null;

        if (!$row || intval($row['color_edited']) !== 0) return;

        $newColor = cs_resolve_color($conn, $newParentId);

        $colorEsc = mysqli_real_escape_string($conn, $newColor);

        mysqli_query($conn, "UPDATE fc_categories SET color = '$colorEsc' WHERE id = " . intval($catId));

        cs_cascade_color($conn, $catId, $newColor);

    }

}



// --- AZIONE: INSERIMENTO / MODIFICA NOME E PADRE ---

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    $action = $_POST['action'];

    $okParam = '';



    if ($action === 'save') {

        $catId = intval($_POST['category_id']);

        $catName = mysqli_real_escape_string($conn, trim($_POST['category_name']));

        $parentId = intval($_POST['parent_id']);

        $colorMode = (($_POST['color_mode'] ?? 'auto') === 'manual') ? 'manual' : 'auto';

        $manualColor = trim($_POST['category_color'] ?? '');



        if ($colorMode === 'manual' && preg_match('/^#[0-9A-Fa-f]{6}$/', $manualColor)) {

            $finalColor = $manualColor;

            $colorEditedFlag = 1;

        } else {

            $finalColor = cs_resolve_color($conn, $parentId);

            $colorEditedFlag = 0;

        }

        $colorEsc = mysqli_real_escape_string($conn, $finalColor);



        if ($catId === 0) {

            mysqli_query($conn, "INSERT INTO fc_categories (name, parent_id, color, color_edited, user_id) VALUES ('$catName', $parentId, '$colorEsc', $colorEditedFlag, $current_user_id)");

            $okParam = 'added';

        } else {

            mysqli_query($conn, "UPDATE fc_categories SET name = '$catName', parent_id = $parentId, color = '$colorEsc', color_edited = $colorEditedFlag WHERE id = $catId AND user_id = $current_user_id");

            cs_cascade_color($conn, $catId, $finalColor);

            $okParam = 'saved';

        }

    } elseif ($action === 'delete' && isset($_POST['delete_category_id'])) {

        $catId = intval($_POST['delete_category_id']);

        mysqli_query($conn, "DELETE FROM fc_categories WHERE id = $catId AND user_id = $current_user_id");

        $okParam = 'deleted';

    } elseif ($action === 'move' && isset($_POST['move_category_id']) && isset($_POST['target_parent_id'])) {

        $moveId = intval($_POST['move_category_id']);

        $targetParentId = intval($_POST['target_parent_id']);

        mysqli_query($conn, "UPDATE fc_categories SET parent_id = $targetParentId WHERE id = $moveId AND user_id = $current_user_id");

        cs_apply_parent_color_update($conn, $moveId, $targetParentId);

        $okParam = 'moved';

    } elseif ($action === 'color' && isset($_POST['category_id']) && isset($_POST['category_color'])) {

        $catId = intval($_POST['category_id']);

        $color = trim($_POST['category_color']);

        if (preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {

            $colorEsc = mysqli_real_escape_string($conn, $color);

            mysqli_query($conn, "UPDATE fc_categories SET color = '$colorEsc', color_edited = 1 WHERE id = $catId AND user_id = $current_user_id");

            cs_cascade_color($conn, $catId, $color);

            $okParam = 'colored';

        }

    }



    header("Location: categories_fc.php" . ($okParam ? "?ok=$okParam" : ""));

    exit;

}





$query = "SELECT c.*, IFNULL(im.image_count, 0) AS image_count

          FROM fc_categories AS c

          LEFT JOIN (

              SELECT category_id, COUNT(*) AS image_count FROM fc_images GROUP BY category_id

          ) AS im ON c.id = im.category_id

          WHERE c.user_id = $current_user_id

          ORDER BY c.name";

$result = mysqli_query($conn, $query);



$all_categories = [];

$categories_by_parent = [];

while ($row = mysqli_fetch_assoc($result)) {

    $all_categories[$row['id']] = $row;

    $categories_by_parent[$row['parent_id']][] = $row['id'];

}



$q_all = mysqli_query($conn, "
    SELECT COUNT(img.id) AS total 
    FROM fc_images img 
    INNER JOIN fc_categories cat ON img.category_id = cat.id 
    WHERE cat.user_id = $current_user_id
");

$row_all = mysqli_fetch_assoc($q_all);

$totalImages = $row_all ? $row_all['total'] : 0;



$catCount = count($all_categories);

$rootIds = isset($categories_by_parent[0]) ? $categories_by_parent[0] : [];


// Mappa categoria -> progetto collegato (per il tag "progetto" sotto al nome).
// project_fc_categories collega un progetto a una categoria flashcard.
$cat_project_links = [];
$cplRes = mysqli_query($conn, "SELECT pfc.fc_category_id, p.id AS pid, p.name AS pname, p.color AS pcolor, pp.name AS parent_name
                               FROM project_fc_categories pfc
                               INNER JOIN projects p ON p.id = pfc.project_id AND p.user_id = $current_user_id
                               LEFT JOIN projects pp ON pp.id = p.parent_id AND pp.user_id = $current_user_id
                               WHERE pfc.fc_category_id IN (SELECT id FROM fc_categories WHERE user_id = $current_user_id)");
if ($cplRes) {
    while ($cpl = mysqli_fetch_assoc($cplRes)) {
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
    $nameEsc = htmlspecialchars($cat['name'], ENT_QUOTES);
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
<div class="cat-row" data-id="<?php echo $id; ?>" data-parent="<?php echo (int)$cat['parent_id']; ?>" data-level="<?php echo $level; ?>" data-name="<?php echo $nameEsc; ?>" style="display:<?php echo $display; ?>; --row-color:<?php echo $color; ?>;">
    
    <!-- TASTI SWIPE (Nascosti sotto) -->
    <div class="cat-swipe-actions">
        <button type="button" class="swipe-btn swipe-edit" aria-label="Modifica"
            onclick="chiudiTuttiSwipe(); apriModalSalva({id:<?php echo $id; ?>, name:'<?php echo addslashes($nameEsc); ?>', parentId:<?php echo $parentId; ?>, parentName:'<?php echo addslashes($parentName); ?>', parentColor:'<?php echo $parentColor; ?>', color:'<?php echo $color; ?>', colorEdited:<?php echo $isColorEdited; ?>})">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
        </button>
        <button type="button" class="swipe-btn swipe-move" aria-label="Sposta"
            onclick="avviaSposta(<?php echo $id; ?>, '<?php echo addslashes($nameEsc); ?>')">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 9l-3 3 3 3M19 9l3 3-3 3M2 12h20"/></svg>
        </button>
        <?php if ($can_delete): ?>
        <button type="button" class="swipe-btn swipe-delete" aria-label="Elimina"
            onclick="chiudiTuttiSwipe(); apriConfermaElimina(<?php echo $id; ?>, '<?php echo addslashes($nameEsc); ?>')">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0-1 14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2L4 6"/></svg>
        </button>
        <?php endif; ?>
    </div>

    <!-- RIGA PRINCIPALE (In primo piano) -->
    <div class="cat-row-main" style="padding-left:<?php echo $pad; ?>px;" data-cat-link="visualizzazione_fc.php?category_id=<?php echo $id; ?>" onclick="fcCatRowNavigate(event, this)">
        <?php if ($has_children): ?>
        <button type="button" class="cat-arrow" onclick="event.stopPropagation(); toggleBranch(<?php echo $id; ?>)" aria-label="Espandi">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m9 6 6 6-6 6"/></svg>
        </button>
        <?php else: ?>
        <span class="cat-arrow-spacer"></span>
        <?php endif; ?>

        <span class="cat-icon <?php echo $level === 0 ? 'lvl0' : 'lvln'; ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z"/></svg>
        </span>

        <div class="cat-name-wrap">
        <span class="cat-name"><?php echo $nameEsc; ?></span>
        <?php $catProj = $cat_project_links[$id] ?? null; if ($catProj): ?>
        <a class="cat-proj-tag" href="progetti.php?id=<?php echo (int)$catProj['id']; ?>" style="--cp-color:<?php echo htmlspecialchars($catProj['color'], ENT_QUOTES); ?>;" onclick="event.stopPropagation();">
            <svg viewBox="0 0 24 24" width="9" height="9" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z"/></svg>
            <?php if (!empty($catProj['parent_name'])): ?><span class="cat-proj-anc"><?php echo htmlspecialchars($catProj['parent_name'], ENT_QUOTES); ?></span><span class="cat-proj-sep">•</span><?php endif; ?>
            <span class="cat-proj-leaf"><?php echo htmlspecialchars($catProj['name'], ENT_QUOTES); ?></span>
        </a>
        <?php endif; ?>
        </div>

        <span class="cat-badge"><?php echo (int)$cat['image_count']; ?></span>
        <button type="button" class="cat-kebab" aria-label="Altre azioni"
            data-id="<?php echo $id; ?>"
            data-name="<?php echo $nameEsc; ?>"
            data-parent="<?php echo (int)$cat['parent_id']; ?>"
            data-parent-name="<?php echo htmlspecialchars($parentName, ENT_QUOTES); ?>"
            data-parent-color="<?php echo $parentColor; ?>"
            data-candelete="<?php echo $can_delete; ?>"
            data-color="<?php echo $color; ?>"
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

<title>Categorie · Flashcards</title>

<style>

/* ================================================================

   DESIGN SYSTEM — coerente con game_fc.php (iOS nativo, light+dark)

   ================================================================ */

:root{

    color-scheme: light dark;

    --bg:#F2F2F7; --surface:#FFFFFF; --surface-2:#F2F2F7;

    --text:#1C1C1E; --text-2:#8A8A8E; --text-3:#AEAEB2;

    --hairline:rgba(60,60,67,.12); --hairline-2:rgba(60,60,67,.22);

    --blue:#007AFF; --green:#34C759; --red:#FF3B30; --orange:#FF9500; --purple:#AF52DE;

    --teal:#30B0C7; --pink:#FF2D55; --indigo:#5856D6;

    --blue-soft:rgba(0,122,255,.09); --green-soft:rgba(52,199,89,.13); --red-soft:rgba(255,59,48,.12);

    --orange-soft:rgba(255,149,0,.14); --purple-soft:rgba(175,82,222,.12);

    --teal-soft:rgba(48,176,199,.13); --pink-soft:rgba(255,45,85,.12); --indigo-soft:rgba(88,86,214,.12);

    --header-glass:rgba(249,249,251,.82);

    --shadow-card:0 1px 1px rgba(0,0,0,.04), 0 10px 30px rgba(0,0,0,.07);

    --btn-hl:inset 0 1px 0 rgba(255,255,255,.25), inset 0 -1px 0 rgba(0,0,0,.16);

    --raise-sm:0 1px 1.5px rgba(0,0,0,.08), 0 5px 12px -4px rgba(0,0,0,.13);

    --r-card:22px; --r-btn:15px;

    --spring:cubic-bezier(.32,.72,0,1);

    --safe-top:env(safe-area-inset-top, 0px);

    --safe-bottom:env(safe-area-inset-bottom, 0px);

}

@media (prefers-color-scheme: dark){

    :root{

        --bg:#000000; --surface:#1C1C1E; --surface-2:#2C2C2E;

        --text:#F5F5F7; --text-2:#98989F; --text-3:#636366;

        --hairline:rgba(84,84,88,.42); --hairline-2:rgba(84,84,88,.62);

        --blue:#0A84FF; --green:#30D158; --red:#FF453A; --orange:#FF9F0A; --purple:#BF5AF2;

        --teal:#40C8E0; --pink:#FF375F; --indigo:#5E5CE6;

        --blue-soft:rgba(10,132,255,.16); --green-soft:rgba(48,209,88,.17); --red-soft:rgba(255,69,58,.17);

        --orange-soft:rgba(255,159,10,.18); --purple-soft:rgba(191,90,242,.18);

        --teal-soft:rgba(64,200,224,.18); --pink-soft:rgba(255,55,95,.18); --indigo-soft:rgba(94,92,230,.18);

        --header-glass:rgba(16,16,18,.78);

        --shadow-card:0 1px 1px rgba(0,0,0,.35), 0 10px 30px rgba(0,0,0,.5);

        --btn-hl:inset 0 1px 0 rgba(255,255,255,.22), inset 0 -1px 0 rgba(0,0,0,.3);

        --raise-sm:0 1px 1.5px rgba(0,0,0,.45), 0 5px 12px -4px rgba(0,0,0,.55);

    }

}



*{ box-sizing:border-box; -webkit-tap-highlight-color:transparent; }

html{ -webkit-text-size-adjust:100%; }

body{

    margin:0; min-height:100vh;

    background:var(--bg); color:var(--text);

    font-family:-apple-system, BlinkMacSystemFont, "SF Pro Text", "SF Pro Display", system-ui, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;

    font-size:16px; line-height:1.4;

    -webkit-font-smoothing:antialiased; text-rendering:optimizeLegibility;

    padding-bottom:calc(24px + var(--safe-bottom));

    overscroll-behavior-y:none;

}

a{ color:inherit; }

button{ font-family:inherit; }

:focus-visible{ outline:2px solid var(--blue); outline-offset:2px; border-radius:8px; }



/* ================================================================

   HEADER — vetro smerigliato + ricerca

   ================================================================ */

.app-header{

    position:sticky; top:0; z-index:100;

    padding:calc(var(--safe-top) + 12px) 16px 12px;

    background:var(--header-glass);

    -webkit-backdrop-filter:saturate(1.8) blur(20px); backdrop-filter:saturate(1.8) blur(20px);

    border-bottom:.5px solid var(--hairline);

}

.hdr-title{ font-size:28px; font-weight:800; letter-spacing:-.02em; }

.hdr-sub{ margin-top:2px; font-size:13px; color:var(--text-2); font-weight:500; font-variant-numeric:tabular-nums; }

.search-box{

    margin-top:12px; display:flex; align-items:center; gap:8px;

    background:var(--surface-2); border-radius:12px; padding:10px 12px;

    box-shadow:inset 0 0 0 .5px var(--hairline);

}

.search-box svg{ width:17px; height:17px; color:var(--text-2); flex-shrink:0; }

.search-box input{

    flex:1; min-width:0; border:none; outline:none; background:transparent; color:var(--text);

    font-size:16px; font-family:inherit; font-weight:500;

}

.search-box input::placeholder{ color:var(--text-3); }

.search-clear{

    display:none; align-items:center; justify-content:center;

    width:20px; height:20px; border-radius:50%; border:none; cursor:pointer;

    background:var(--text-3); color:var(--surface); flex-shrink:0; padding:0;

}

.search-clear svg{ width:10px; height:10px; }



/* ================================================================

   LAYOUT

   ================================================================ */

.page{ max-width:640px; margin:0 auto; padding:16px 14px calc(112px + var(--safe-bottom)); }



@keyframes cardIn{ from{ opacity:0; transform:translateY(14px) scale(.985); } to{ opacity:1; transform:none; } }



/* Hero: Tutte le materie */

.hero-card{

    display:flex; align-items:center; gap:14px;

    padding:20px; border-radius:var(--r-card); margin-bottom:18px;

    background:linear-gradient(135deg, var(--blue), var(--indigo));

    color:#fff; text-decoration:none;

    box-shadow:0 1px 1px rgba(0,0,0,.05), 0 16px 34px -12px rgba(10,132,255,.5);

    animation:cardIn .5s var(--spring) both;

    transition:transform .16s var(--spring);

}

.hero-card:active{ transform:scale(.98); }

.hero-icon{

    width:50px; height:50px; border-radius:14px; flex-shrink:0;

    background:rgba(255,255,255,.18); display:flex; align-items:center; justify-content:center;

}

.hero-icon svg{ width:25px; height:25px; }

.hero-text{ flex:1; min-width:0; }

.hero-num{ font-size:25px; font-weight:800; letter-spacing:-.02em; font-variant-numeric:tabular-nums; }

.hero-label{ font-size:13.5px; font-weight:600; opacity:.88; margin-top:1px; }

.hero-chev{ flex-shrink:0; opacity:.75; display:flex; }

.hero-chev svg{ width:18px; height:18px; }



/* Card di ogni materia (radice + figli) */

.materia-card{

    position:relative; background:var(--surface); border-radius:var(--r-card);

    margin-bottom:14px; overflow:hidden;

    box-shadow:var(--shadow-card);

    border-left:3px solid var(--accent, var(--blue));

    animation:cardIn .5s var(--spring) both;

}



.cat-row-main{

    display:flex; align-items:center; gap:10px; padding:15px 12px 15px 0;

    transition:background .15s; flex:1; width:100%;

}

.cat-row:not([data-level="0"]) .cat-row-main{ border-top:.5px solid var(--hairline); padding:12px 12px 12px 0; }

.cat-row-main:active{ background:var(--surface-2); }



@keyframes rowIn{ from{ opacity:0; transform:translateY(-6px); } to{ opacity:1; transform:none; } }

.cat-row.row-anim{ animation:rowIn .3s var(--spring) both; }



.cat-arrow, .cat-arrow-spacer{ width:22px; height:22px; flex-shrink:0; }

.cat-arrow{

    display:flex; align-items:center; justify-content:center;

    border:none; background:transparent; color:var(--text-3); cursor:pointer;

    border-radius:50%; padding:0;

    transition:transform .3s var(--spring), color .2s, background .15s;

}

.cat-arrow svg{ width:16px; height:16px; }

.cat-arrow.open{ transform:rotate(90deg); color:var(--row-color, var(--blue)); }

.cat-arrow:active{ background:var(--surface-2); }



.cat-icon{

    width:32px; height:32px; border-radius:9px; flex-shrink:0;

    display:flex; align-items:center; justify-content:center;

}

.cat-icon svg{ width:16px; height:16px; }

.cat-icon.lvl0{ background:color-mix(in srgb, var(--row-color, var(--blue)) 18%, var(--surface)); color:var(--row-color, var(--blue)); width:36px; height:36px; border-radius:10px; transition:background .3s, color .3s; }

.cat-icon.lvl0 svg{ width:18px; height:18px; }

.cat-icon.lvln{ background:color-mix(in srgb, var(--row-color, var(--text-2)) 16%, var(--surface)); color:var(--row-color, var(--text-2)); transition:background .3s, color .3s; }



.cat-name{

    flex:1; min-width:0; text-decoration:none; color:var(--text);

    font-size:15.5px; font-weight:600; letter-spacing:-.01em;

    white-space:nowrap; overflow:hidden; text-overflow:ellipsis;

}
.cat-name-wrap{ flex:1; min-width:0; display:flex; flex-direction:column; align-items:flex-start; gap:3px; }
.cat-name-wrap .cat-name{ flex:0 1 auto; max-width:100%; }
.cat-row-main{ cursor:pointer; }
.cat-proj-tag{ display:inline-flex; align-items:center; gap:4px; text-decoration:none; padding:2px 8px 2px 6px; border-radius:999px;
    /* Sfondo, testo e bordo derivati dal colore progetto MA sempre mescolati con le
       variabili di tema (--surface/--text, che si invertono in chiaro/scuro): cosi il
       tag resta leggibile anche con colori vicini al bianco o al nero. */
    background:color-mix(in srgb, var(--cp-color, #8E8E93) 18%, color-mix(in srgb, var(--surface) 94%, var(--text)));
    color:color-mix(in srgb, var(--cp-color, #8E8E93) 52%, var(--text));
    box-shadow:inset 0 0 0 .75px color-mix(in srgb, var(--cp-color, #8E8E93) 45%, color-mix(in srgb, var(--text) 22%, transparent));
    font-size:10.5px; font-weight:800; letter-spacing:-.1px; line-height:1.35; max-width:100%; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.cat-proj-tag svg{ flex-shrink:0; }
.cat-proj-tag:active{ transform:scale(.94); }
.cat-proj-anc{ opacity:.65; font-weight:700; }
.cat-proj-sep{ opacity:.5; margin:0 1px; }
.cat-proj-leaf{ font-weight:800; }

.cat-row[data-level="0"] .cat-name{ font-size:17px; font-weight:800; }



.cat-badge{

    flex-shrink:0; background:var(--surface-2); color:var(--text-2);

    font-size:12px; font-weight:700; padding:4px 9px; border-radius:99px;

    box-shadow:inset 0 0 0 .5px var(--hairline); font-variant-numeric:tabular-nums;

}



.cat-kebab{

    flex-shrink:0; width:30px; height:30px; border:none; border-radius:50%;

    background:transparent; color:var(--text-3); cursor:pointer; padding:0;

    display:flex; align-items:center; justify-content:center;

    transition:background .15s;

}

.cat-kebab svg{ width:18px; height:18px; }

.cat-kebab:active{ background:var(--surface-2); }

/* ================================================================
   SWIPE ACTIONS
   ================================================================ */

.cat-row{
    position:relative;
    overflow:hidden;
    background:var(--surface);
}

.cat-swipe-actions{
    position:absolute;
    top:0;
    left:0;
    bottom:0;
    display:none;             /* nascosti di default */
    align-items:stretch;
    z-index:1;
}

.swipe-btn{
    border:none;
    margin:0;
    padding:0;
    cursor:pointer;
    width:68px;
    display:flex;
    align-items:center;
    justify-content:center;
    color:#fff;
}

.swipe-btn svg{
    width:22px;
    height:22px;
}

.swipe-edit{
    background:var(--blue);
}

.swipe-move{
    background:var(--orange);
}

.swipe-delete{
    background:var(--red);
}

/* La riga scorre sopra ai pulsanti */

.cat-row-main{
    position:relative;
    z-index:2;
    background:var(--surface);
    will-change:transform;
    background:var(--surface);
}

/* SOLO dispositivi touch */

.cat-swipe-actions{
    display:none;
}

body.touch-device .cat-swipe-actions{
    display:flex;
}

body.touch-device .cat-kebab{
    display:none;
}



/* ================================================================

   FAB

   ================================================================ */

.fab{

    position:fixed; right:20px; bottom:calc(22px + var(--safe-bottom)); z-index:60;

    width:58px; height:58px; border-radius:50%; border:none; cursor:pointer;

    color:#fff; background:var(--blue);

    background:linear-gradient(180deg, color-mix(in srgb, var(--blue), #fff 16%), var(--blue) 52%, color-mix(in srgb, var(--blue), #000 10%));

    display:flex; align-items:center; justify-content:center;

    box-shadow:var(--btn-hl), 0 2px 6px rgba(0,0,0,.18), 0 14px 30px -8px rgba(10,132,255,.6);

    animation:fabIn .5s var(--spring) .35s both;

    transition:transform .16s var(--spring);

}

.fab svg{ width:25px; height:25px; }

.fab:active{ transform:scale(.9); }

@keyframes fabIn{ from{ opacity:0; transform:scale(.4) rotate(-25deg); } to{ opacity:1; transform:none; } }



/* ================================================================

   STATO VUOTO

   ================================================================ */

.empty-state{ text-align:center; padding:60px 20px 30px; animation:cardIn .5s var(--spring) both; }

.empty-icon{

    width:72px; height:72px; margin:0 auto 18px; border-radius:20px;

    background:var(--blue-soft); color:var(--blue);

    display:flex; align-items:center; justify-content:center;

}

.empty-icon svg{ width:32px; height:32px; }

.empty-title{ font-size:19px; font-weight:800; margin-bottom:6px; }

.empty-sub{ font-size:14.5px; color:var(--text-2); max-width:280px; margin:0 auto 20px; line-height:1.5; }

.btn-create-first{

    display:inline-flex; align-items:center; gap:8px; padding:13px 22px;

    border:none; border-radius:var(--r-btn); cursor:pointer;

    color:#fff; font-size:15.5px; font-weight:700;

    background:linear-gradient(180deg, color-mix(in srgb, var(--blue), #fff 16%), var(--blue) 52%, color-mix(in srgb, var(--blue), #000 10%));

    box-shadow:var(--btn-hl), 0 12px 26px -8px rgba(10,132,255,.55);

    transition:transform .16s var(--spring);

}

.btn-create-first:active{ transform:scale(.96); }



/* ================================================================

   SHEET MODALI — stile foglio iOS dal basso

   ================================================================ */

.modal-overlay{

    display:none; position:fixed; inset:0; z-index:1000;

    justify-content:center; align-items:flex-end;

    background:rgba(0,0,0,.45);

    -webkit-backdrop-filter:blur(6px); backdrop-filter:blur(6px);

}

.modal-overlay.closing{ animation:overlayOut .22s ease both; }

@keyframes overlayOut{ to{ opacity:0; } }

.sheet-panel{

    position:relative; width:100%; max-width:600px;

    max-height:88vh; overflow-y:auto; -webkit-overflow-scrolling:touch;

    background:var(--surface); color:var(--text);

    border-radius:24px 24px 0 0;

    padding:26px 20px calc(22px + var(--safe-bottom));

    box-shadow:0 -10px 50px rgba(0,0,0,.35);

    animation:sheetUp .38s var(--spring) both;

}

.modal-overlay.closing .sheet-panel{ animation:sheetDown .24s ease both; }

@keyframes sheetUp{ from{ transform:translateY(60%); opacity:.4; } to{ transform:none; opacity:1; } }

@keyframes sheetDown{ to{ transform:translateY(60%); opacity:0; } }

.sheet-panel::before{

    content:""; position:absolute; top:8px; left:50%; transform:translateX(-50%);

    width:38px; height:5px; border-radius:99px; background:var(--hairline-2);

}

.sheet-title{ margin:6px 0 18px; font-size:19px; font-weight:800; letter-spacing:-.01em; text-align:center; }

.sheet-sub{ margin:-12px 0 16px; font-size:13.5px; color:var(--text-2); text-align:center; }

@media (min-width:640px){

    .modal-overlay{ align-items:center; padding:20px; }

    .sheet-panel{ border-radius:24px; }

}



/* Campi form */

.field{ margin-bottom:16px; }

.field label{

    display:block; margin-bottom:7px; font-size:11.5px; font-weight:800;

    letter-spacing:.07em; text-transform:uppercase; color:var(--text-2);

}

.field input[type="text"], .field select{

    width:100%; padding:13px 14px; font-family:inherit; font-size:16px; font-weight:600;

    color:var(--text); background:var(--surface-2); border:.5px solid var(--hairline-2);

    border-radius:14px; -webkit-appearance:none; appearance:none;

}

.field select{

    padding-right:38px;

    background-image:url("data:image/svg+xml;charset=utf-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%238A8A8E' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E");

    background-repeat:no-repeat; background-position:right 13px center;

}

.field input:focus, .field select:focus{ outline:none; box-shadow:0 0 0 2.5px var(--blue-soft), inset 0 0 0 1.5px var(--blue); }



.sheet-actions{ display:flex; gap:10px; margin-top:6px; }

.btn{

    flex:1; display:flex; align-items:center; justify-content:center; gap:7px;

    padding:14px 16px; border:none; border-radius:var(--r-btn); cursor:pointer;

    font-family:inherit; font-size:16px; font-weight:700; letter-spacing:-.01em;

    text-decoration:none; transition:transform .16s var(--spring), filter .2s;

}

.btn:active{ transform:scale(.97); filter:brightness(.95); }

.btn-cancel{ background:var(--surface-2); color:var(--text); }

.btn-primary{

    color:#fff;

    background:linear-gradient(180deg, color-mix(in srgb, var(--blue), #fff 16%), var(--blue) 52%, color-mix(in srgb, var(--blue), #000 10%));

    box-shadow:var(--btn-hl), 0 10px 22px -8px rgba(10,132,255,.55);

}

.btn-danger{

    color:#fff;

    background:linear-gradient(180deg, color-mix(in srgb, var(--red), #fff 16%), var(--red) 52%, color-mix(in srgb, var(--red), #000 10%));

    box-shadow:var(--btn-hl), 0 10px 22px -8px rgba(255,59,48,.55);

}



/* Elenco azioni (action sheet) */

.action-list{ display:flex; flex-direction:column; gap:8px; margin-bottom:14px; }

.action-item{

    display:flex; align-items:center; gap:12px; width:100%;

    padding:12px 12px; border:none; border-radius:14px; cursor:pointer;

    background:var(--surface-2); color:var(--text);

    font-family:inherit; font-size:16px; font-weight:600; text-align:left;

    transition:transform .15s var(--spring), background .15s;

}

.action-item:active{ transform:scale(.98); background:var(--hairline); }

.action-item.danger{ color:var(--red); }

.action-item .a-ic{

    width:34px; height:34px; border-radius:9px; flex-shrink:0;

    display:flex; align-items:center; justify-content:center;

    background:var(--blue-soft); color:var(--blue);

}

.action-item .a-ic svg{ width:17px; height:17px; }

.action-item.danger .a-ic{ background:var(--red-soft); color:var(--red); }



/* Conferma eliminazione */

.confirm-icon{

    width:56px; height:56px; margin:4px auto 14px; border-radius:16px;

    background:var(--red-soft); color:var(--red);

    display:flex; align-items:center; justify-content:center;

}

.confirm-icon svg{ width:26px; height:26px; }

.confirm-text{ text-align:center; font-size:15px; color:var(--text-2); margin-bottom:18px; line-height:1.5; }

.confirm-text b{ color:var(--text); }




/* ================================================================

   COLORE CARTELLA — tavolozza dinamica con anteprima live

   ================================================================ */

.color-preview-row{

    display:flex; align-items:center; gap:12px; padding:14px;

    margin-bottom:18px; background:var(--surface-2); border-radius:16px;

}

.color-preview-icon{

    width:44px; height:44px; border-radius:12px; flex-shrink:0;

    display:flex; align-items:center; justify-content:center;

    background:color-mix(in srgb, var(--row-color, var(--blue)) 18%, var(--surface));

    color:var(--row-color, var(--blue));

    transition:background .25s, color .25s;

}

.color-preview-icon svg{ width:22px; height:22px; }

.color-preview-name{ font-size:16px; font-weight:700; flex:1; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }



.color-grid{ display:grid; grid-template-columns:repeat(6, 1fr); gap:10px; margin-bottom:18px; }

.color-swatch{

    position:relative; width:100%; aspect-ratio:1; border-radius:50%; border:none; cursor:pointer;

    padding:0; background:var(--sw); box-shadow:inset 0 0 0 .5px rgba(0,0,0,.1);

    animation:swatchIn .4s var(--spring) both;

    transition:transform .18s var(--spring), box-shadow .2s;

}

@keyframes swatchIn{ from{ opacity:0; transform:scale(0); } to{ opacity:1; transform:scale(1); } }

.color-swatch:active{ transform:scale(.82); }

.color-swatch.selected{ transform:scale(1.08); box-shadow:0 0 0 3px var(--surface), 0 0 0 5.5px var(--sw); }

.color-swatch .check-ic{

    position:absolute; inset:0; margin:auto; width:16px; height:16px;

    opacity:0; transform:scale(.3);

    filter:drop-shadow(0 1px 2px rgba(0,0,0,.4));

    transition:opacity .2s var(--spring), transform .3s var(--spring);

}

.color-swatch.selected .check-ic{ opacity:1; transform:scale(1); }



.color-swatch-auto{

    background:var(--surface); border:1.6px dashed var(--text-3); box-shadow:none;

    color:var(--text-2); display:flex; align-items:center; justify-content:center;

}

.color-swatch-auto svg{ width:15px; height:15px; }

.color-swatch-auto.selected{

    border:1.6px solid var(--blue); color:var(--blue); transform:scale(1.08);

    box-shadow:0 0 0 3px var(--surface), 0 0 0 5.5px var(--blue);

}



.custom-color-row{ display:flex; align-items:center; gap:12px; padding:10px 2px 4px; margin-bottom:6px; }

.custom-color-row input[type="color"]{

    width:42px; height:42px; border-radius:50%; border:none; padding:0; cursor:pointer;

    -webkit-appearance:none; appearance:none; background:none; flex-shrink:0;

    box-shadow:inset 0 0 0 .5px var(--hairline-2);

}

.custom-color-row input[type="color"]::-webkit-color-swatch-wrapper{ padding:0; border-radius:50%; }

.custom-color-row input[type="color"]::-webkit-color-swatch{ border:none; border-radius:50%; }

.custom-color-row .cc-label{ font-size:14.5px; font-weight:600; color:var(--text); }

.custom-color-row .cc-hex{ margin-left:auto; font-size:14px; font-weight:700; color:var(--text-2); font-variant-numeric:tabular-nums; letter-spacing:.02em; }



.a-ic.color-wheel{

    background:conic-gradient(from 180deg, #FF3B30, #FF9500, #FFCC00, #34C759, #00C7BE, #30B0C7, #32ADE6, #007AFF, #5856D6, #AF52DE, #FF2D55, #FF3B30);

}



/* ================================================================

   TOAST DI CONFERMA

   ================================================================ */

.toast{

    position:fixed; top:calc(var(--safe-top) + 14px); left:50%; z-index:20000;

    display:flex; align-items:center; gap:8px;

    padding:12px 18px; border-radius:99px;

    background:var(--text); color:var(--bg);

    font-size:14px; font-weight:700;

    box-shadow:0 12px 30px rgba(0,0,0,.28);

    transform:translateX(-50%) translateY(-20px); opacity:0;

    transition:transform .4s var(--spring), opacity .3s;

}

.toast.show{ transform:translateX(-50%) translateY(0); opacity:1; }

.toast svg{ width:16px; height:16px; flex-shrink:0; }



@media (prefers-reduced-motion: reduce){

    *, *::before, *::after{ animation-duration:.01ms !important; transition-duration:.01ms !important; }

}



</style>

</head>

<body>



<div class="app-header">

    <div class="hdr-title">Categorie</div>

    <div class="hdr-sub"><?php echo $catCount; ?> categorie · <?php echo $totalImages; ?> carte totali</div>

    <div class="search-box">

        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>

        <input type="text" id="searchInput" placeholder="Cerca categoria" oninput="filterCategories(this.value)" autocomplete="off">

        <button type="button" class="search-clear" id="searchClear" onclick="clearSearch()" aria-label="Cancella ricerca">

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

            <div class="hero-num"><?php echo $totalImages; ?></div>

            <div class="hero-label">Tutte le materie</div>

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

            <div class="empty-title">Nessuna categoria</div>

            <div class="empty-sub">Crea la tua prima materia per iniziare a organizzare le flashcard.</div>

            <button type="button" class="btn-create-first" onclick="apriModalSalva({id:0,name:'',parentId:0,parentName:'',parentColor:'#A0A0A0',color:'#A0A0A0',colorEdited:false})">

                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>

                Crea categoria

            </button>

        </div>

    <?php else: ?>

        <?php foreach ($rootIds as $i => $rid):

            $rootCat = $all_categories[$rid];

            $rootColor = (!empty($rootCat['color']) && preg_match('/^#[0-9A-Fa-f]{6}$/', $rootCat['color'])) ? $rootCat['color'] : '#007AFF';

            $delay = min($i * 45, 300);

        ?>

        <div class="materia-card" style="--accent:<?php echo $rootColor; ?>; animation-delay:<?php echo $delay; ?>ms;">

            <?php renderCatRow($rid, 0, $all_categories, $categories_by_parent); ?>

        </div>

        <?php endforeach; ?>

    <?php endif; ?>



</div>



<button type="button" class="fab" onclick="apriModalSalva({id:0,name:'',parentId:0,parentName:'',parentColor:'#A0A0A0',color:'#A0A0A0',colorEdited:false})" aria-label="Aggiungi categoria">

    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>

</button>



<!-- SHEET: Nuova / Modifica categoria -->

<div id="sheetForm" class="modal-overlay">

    <div class="sheet-panel">

        <h3 class="sheet-title" id="modalSalvaTitolo">Nuova categoria</h3>

        <form id="formSalva" method="POST">

            <input type="hidden" name="action" value="save">

            <input type="hidden" name="category_id" id="form_cat_id" value="0">

            <input type="hidden" name="color_mode" id="form_color_mode" value="auto">

            <input type="hidden" name="category_color" id="form_color_value" value="#A0A0A0">



            <div class="field">

                <label for="form_cat_name">Nome</label>

                <input type="text" name="category_name" id="form_cat_name" required placeholder="Es. Elettronica Analogica">

            </div>



            <div class="field">

                <label>Categoria padre</label>

                <?php

                    $cs_id = 'formParent';

                    $cs_hidden_name = 'parent_id';

                    $cs_placeholder = 'Nessuna (categoria principale)';

                    $cs_title = 'Categoria Padre';

                    $cs_show_all_option = true;

                    $cs_all_option_label = 'Nessuna (categoria principale)';

                    $cs_allow_create = false;

                    include 'category_selector.php';

                ?>

            </div>



            <div class="field">

                <label>Colore</label>

                <div class="color-preview-row">

                    <span class="color-preview-icon" id="formColorPreviewIcon">

                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z"/></svg>

                    </span>

                    <span class="color-preview-name" id="formColorPreviewName">Automatico (eredita dal padre)</span>

                </div>



                <div class="color-grid" id="formColorGrid">

                    <button type="button" class="color-swatch color-swatch-auto selected" data-auto="1" onclick="setFormColorAuto()" title="Automatico">

                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 9-9"/><path d="M3 4v8h8"/></svg>

                    </button>

                    <?php foreach ($colorPalette as $ci => $hex): ?>

                    <button type="button" class="color-swatch" style="--sw:<?php echo $hex; ?>; animation-delay:<?php echo $ci * 22; ?>ms;" data-hex="<?php echo $hex; ?>" onclick="setFormColorManual('<?php echo $hex; ?>')">

                        <svg class="check-ic" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>

                    </button>

                    <?php endforeach; ?>

                </div>



                <div class="custom-color-row">

                    <input type="color" id="formCustomColorInput" value="#007AFF" oninput="setFormColorManual(this.value)">

                    <span class="cc-label">Colore personalizzato</span>

                    <span class="cc-hex" id="formCustomColorHex">#A0A0A0</span>

                </div>

            </div>



            <div class="sheet-actions">

                <button type="button" class="btn btn-cancel" onclick="chiudiModals()">Annulla</button>

                <button type="submit" class="btn btn-primary">Salva</button>

            </div>

        </form>

    </div>

</div>



<!-- SHEET: Sposta categoria -->

<form id="formSposta" method="POST" style="display:none;">
    <input type="hidden" name="action" value="move">
    <input type="hidden" name="move_category_id" id="move_cat_id">
    <input type="hidden" name="target_parent_id" id="target_parent_id">
</form>

<button type="button" id="btnOpenMoveSelector" data-cs-open="cs-modal-moveCategory" style="display:none;"></button>

<?php
    $cs_id = 'moveCategory';
    $cs_title = 'Sposta Categoria';
    $cs_show_all_option = true;
    $cs_all_option_label = 'Porta in cima (nessun padre)';
    $cs_allow_create = false; // DISABILITA IL TASTO PER CREARE UNA NUOVA MATERIA
    $cs_render_trigger = false; // Nascondiamo il bottone standard, lo apriamo via JS
    include 'category_selector.php';
?>



<!-- SHEET: Azioni categoria (kebab) -->

<div id="sheetActions" class="modal-overlay">

    <div class="sheet-panel">

        <h3 class="sheet-title" id="azioniTitolo">Categoria</h3>

        <div class="action-list">

            <button type="button" class="action-item" id="azioneModifica">

                <span class="a-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg></span>

                Modifica

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

        <button type="button" class="btn btn-cancel" style="width:100%;" onclick="chiudiModals()">Chiudi</button>

    </div>

</div>



<!-- SHEET: Colore cartella -->

<div id="sheetColor" class="modal-overlay">

    <div class="sheet-panel">

        <h3 class="sheet-title">Colore cartella</h3>



        <div class="color-preview-row">

            <span class="color-preview-icon" id="colorPreviewIcon">

                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z"/></svg>

            </span>

            <span class="color-preview-name" id="colorPreviewName">Categoria</span>

        </div>



        <div class="color-grid" id="colorGrid">

            <?php foreach ($colorPalette as $ci => $hex): ?>

            <button type="button" class="color-swatch" style="--sw:<?php echo $hex; ?>; animation-delay:<?php echo $ci * 22; ?>ms;" data-hex="<?php echo $hex; ?>" onclick="selectColor('<?php echo $hex; ?>')">

                <svg class="check-ic" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>

            </button>

            <?php endforeach; ?>

        </div>



        <div class="custom-color-row">

            <input type="color" id="customColorInput" value="#007AFF" oninput="selectColor(this.value)">

            <span class="cc-label">Colore personalizzato</span>

            <span class="cc-hex" id="customColorHex">#007AFF</span>

        </div>



        <form id="formColor" method="POST" style="display:none;">

            <input type="hidden" name="action" value="color">

            <input type="hidden" name="category_id" id="color_cat_id">

            <input type="hidden" name="category_color" id="color_cat_value">

        </form>



        <div class="sheet-actions">

            <button type="button" class="btn btn-cancel" onclick="annullaColore()">Annulla</button>

            <button type="button" class="btn btn-primary" onclick="salvaColore()">Salva</button>

        </div>

    </div>

</div>



<!-- SHEET: Conferma eliminazione -->

<div id="sheetConfirmDelete" class="modal-overlay">

    <div class="sheet-panel">

        <div class="confirm-icon">

            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0-1 14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2L4 6"/></svg>

        </div>

        <h3 class="sheet-title">Eliminare categoria?</h3>

        <p class="confirm-text">Stai per eliminare <b id="confermaEliminaNome"></b>. L'operazione non può essere annullata.</p>

        <div class="sheet-actions">

            <button type="button" class="btn btn-cancel" onclick="chiudiModals()">Annulla</button>

            <button type="button" class="btn btn-danger" onclick="confermaEliminaSubmit()">Elimina</button>

        </div>

    </div>

</div>



<form id="formDelete" method="POST" style="display:none;">

    <input type="hidden" name="action" value="delete">

    <input type="hidden" name="delete_category_id" id="delete_cat_id">

</form>



<script>
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

        document.getElementById('modalSalvaTitolo').textContent = (opts.id == 0) ? 'Nuova categoria' : 'Modifica categoria';



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

        document.getElementById('formColorPreviewName').textContent = formColorManual ? 'Colore personalizzato' : 'Automatico (eredita dal padre)';

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

            setTimeout(function(){ apriColore(id, name, color); }, 260);

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

    var colorOriginal = '#007AFF';

    var colorCurrent = '#007AFF';



    function apriColore(id, name, color){

        colorTargetId = id;

        colorOriginal = color;

        colorCurrent = color;



        document.getElementById('color_cat_id').value = id;

        document.getElementById('colorPreviewName').textContent = name;

        document.getElementById('customColorInput').value = color;

        document.getElementById('customColorHex').textContent = color.toUpperCase();



        applyColorPreview(color);

        highlightSwatch(color);

        openSheet(document.getElementById('sheetColor'));

    }



    function selectColor(hex){

        colorCurrent = hex;

        document.getElementById('customColorInput').value = hex;

        document.getElementById('customColorHex').textContent = hex.toUpperCase();

        applyColorPreview(hex);

        highlightSwatch(hex);

    }



    function highlightSwatch(hex){
        var norm = hex.toLowerCase();
        // Limitiamo la ricerca solo alla griglia del popup colore e aggiungiamo un controllo di sicurezza
        document.querySelectorAll('#colorGrid .color-swatch').forEach(function(sw){
            if (sw.dataset.hex) {
                sw.classList.toggle('selected', sw.dataset.hex.toLowerCase() === norm);
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

        var messages = { added: 'Categoria creata', saved: 'Modifiche salvate', deleted: 'Categoria eliminata', moved: 'Categoria spostata', colored: 'Colore aggiornato' };

        if (ok && messages[ok]) {

            showToast(messages[ok]);

            history.replaceState(null, '', window.location.pathname);

        }

    })();

    /* ---------- Gestione Swipe (Da Sinistra verso Destra) ---------- */
(function(){

    if (!window.matchMedia("(hover:none) and (pointer:coarse)").matches){
        return;
    }

    var activeRow = null;
    var startX = 0, startY = 0;
    var isDragging = false;
    var maxSwipe = 0;

    window.chiudiTuttiSwipe = function() {
        document.querySelectorAll('.cat-row-main.swiped-open').forEach(function(r) {
            r.style.transform = 'translateX(0)';
            r.classList.remove('swiped-open');
        });
        activeRow = null;
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
        var actionsContainer = rowMain.parentElement.querySelector('.cat-swipe-actions');
        if (!actionsContainer) return;

        maxSwipe = actionsContainer.offsetWidth;
        startX = e.touches[0].clientX;
        startY = e.touches[0].clientY;
        isDragging = true;
        rowMain.style.transition = 'none';
    }, {passive: true});

    document.addEventListener('touchmove', function(e) {
        if (!isDragging || !activeRow) return;
        var dx = e.touches[0].clientX - startX;
        var dy = e.touches[0].clientY - startY;

        // Se lo scroll è verticale nativo, annulla lo swipe orizzontale
        if (Math.abs(dy) > Math.abs(dx) && Math.abs(dy) > 10) {
            isDragging = false;
            chiudiTuttiSwipe();
            return;
        }

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
        } else {
            activeRow.style.transform = 'translateX(0)';
            activeRow.classList.remove('swiped-open');
            activeRow = null;
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
    if (link) window.location.href = link;
}

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