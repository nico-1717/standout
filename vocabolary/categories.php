<?php
require_once 'config.php';
$conn = mysqli_connect(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
if (!$conn) { die("Connessione fallita: " . mysqli_connect_error()); }

require_once 'auth_check.php';
$current_user_id = $utente_corrente['id'];

/* ==========================================================================
   EREDITARIETÀ COLORE (tabella `categories` — inglese, per utente)
   - color_edited = 0  -> colore "ereditato": risale la catena dei padri finché
                          non trova un colore esplicito (color_edited = 1);
                          se nessuno lo ha, usa il grigio di default #A0A0A0.
   - color_edited = 1  -> colore scelto per QUELLA categoria: quando cambia va
                          ripropagato ai discendenti che ereditano.
   ========================================================================== */
if (!function_exists('en_resolve_color')) {
    function en_resolve_color($conn, $parentId, $userId) {
        $parentId = intval($parentId);
        $guard = 0;
        while ($parentId > 0 && $guard < 50) {
            $guard++;
            $res = mysqli_query($conn, "SELECT parent_id, color, color_edited FROM categories WHERE id = " . $parentId . " AND user_id = " . intval($userId));
            $row = $res ? mysqli_fetch_assoc($res) : null;
            if (!$row) break;
            if (intval($row['color_edited']) === 1 && !empty($row['color'])) return $row['color'];
            $parentId = intval($row['parent_id']);
        }
        return '#A0A0A0';
    }
}
if (!function_exists('en_cascade_color')) {
    function en_cascade_color($conn, $categoryId, $color, $userId, $depth = 0) {
        if ($depth > 50) return;
        $res = mysqli_query($conn, "SELECT id, color_edited FROM categories WHERE parent_id = " . intval($categoryId) . " AND user_id = " . intval($userId));
        if (!$res) return;
        $children = [];
        while ($row = mysqli_fetch_assoc($res)) { $children[] = $row; }
        $colorEsc = mysqli_real_escape_string($conn, $color);
        foreach ($children as $child) {
            if (intval($child['color_edited']) === 0) {
                mysqli_query($conn, "UPDATE categories SET color = '$colorEsc' WHERE id = " . intval($child['id']) . " AND user_id = " . intval($userId));
                en_cascade_color($conn, $child['id'], $color, $userId, $depth + 1);
            }
        }
    }
}
if (!function_exists('en_apply_parent_color_update')) {
    function en_apply_parent_color_update($conn, $catId, $newParentId, $userId) {
        $res = mysqli_query($conn, "SELECT color_edited FROM categories WHERE id = " . intval($catId) . " AND user_id = " . intval($userId));
        $row = $res ? mysqli_fetch_assoc($res) : null;
        if (!$row || intval($row['color_edited']) !== 0) return;
        $newColor = en_resolve_color($conn, $newParentId, $userId);
        $colorEsc = mysqli_real_escape_string($conn, $newColor);
        mysqli_query($conn, "UPDATE categories SET color = '$colorEsc' WHERE id = " . intval($catId) . " AND user_id = " . intval($userId));
        en_cascade_color($conn, $catId, $newColor, $userId);
    }
}

// --- AZIONI (salva / elimina / sposta / colore) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $okParam = '';

    if ($action === 'save') {
        $catId    = intval($_POST['category_id']);
        $catName  = mysqli_real_escape_string($conn, trim($_POST['category_name']));
        $parentId = intval($_POST['parent_id']);
        $colorMode   = (($_POST['color_mode'] ?? 'auto') === 'manual') ? 'manual' : 'auto';
        $manualColor = trim($_POST['category_color'] ?? '');

        if ($colorMode === 'manual' && preg_match('/^#[0-9A-Fa-f]{6}$/', $manualColor)) {
            $finalColor = $manualColor; $colorEditedFlag = 1;
        } else {
            $finalColor = en_resolve_color($conn, $parentId, $current_user_id); $colorEditedFlag = 0;
        }
        $colorEsc = mysqli_real_escape_string($conn, $finalColor);

        if ($catId === 0) {
            mysqli_query($conn, "INSERT INTO categories (name, parent_id, color, color_edited, user_id) VALUES ('$catName', $parentId, '$colorEsc', $colorEditedFlag, $current_user_id)");
            $okParam = 'added';
        } else {
            mysqli_query($conn, "UPDATE categories SET name = '$catName', parent_id = $parentId, color = '$colorEsc', color_edited = $colorEditedFlag WHERE id = $catId AND user_id = $current_user_id");
            en_cascade_color($conn, $catId, $finalColor, $current_user_id);
            $okParam = 'saved';
        }
    } elseif ($action === 'delete' && isset($_POST['delete_category_id'])) {
        $catId = intval($_POST['delete_category_id']);
        mysqli_query($conn, "DELETE FROM categories WHERE id = $catId AND user_id = $current_user_id");
        $okParam = 'deleted';
    } elseif ($action === 'move' && isset($_POST['move_category_id']) && isset($_POST['target_parent_id'])) {
        $moveId = intval($_POST['move_category_id']);
        $targetParentId = intval($_POST['target_parent_id']);
        mysqli_query($conn, "UPDATE categories SET parent_id = $targetParentId WHERE id = $moveId AND user_id = $current_user_id");
        en_apply_parent_color_update($conn, $moveId, $targetParentId, $current_user_id);
        $okParam = 'moved';
    } elseif ($action === 'color' && isset($_POST['category_id']) && isset($_POST['category_color'])) {
        $catId = intval($_POST['category_id']);
        $color = trim($_POST['category_color']);
        if (preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
            $colorEsc = mysqli_real_escape_string($conn, $color);
            mysqli_query($conn, "UPDATE categories SET color = '$colorEsc', color_edited = 1 WHERE id = $catId AND user_id = $current_user_id");
            en_cascade_color($conn, $catId, $color, $current_user_id);
            $okParam = 'colored';
        }
    }

    header("Location: categories.php" . ($okParam ? "?ok=$okParam" : ""));
    exit;
}

// Categorie (di questo utente) + conteggio parole (tabella images) per categoria
$query = "SELECT c.*, IFNULL(im.word_count, 0) AS word_count
          FROM categories AS c
          LEFT JOIN (
              SELECT category_id, COUNT(*) AS word_count FROM images GROUP BY category_id
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

// Auto-guarigione: le categorie non modificate manualmente (color_edited=0) mostrano
// sempre il colore live risolto dalla catena dei padri, ignorando eventuali valori
// "sporchi" rimasti nel DB (es. il grigio/blu di default della colonna).
foreach ($all_categories as $cid => $c) {
    if (intval($c['color_edited']) === 0) {
        $all_categories[$cid]['color'] = en_resolve_color($conn, $c['parent_id'], $current_user_id);
    }
}

$q_all = mysqli_query($conn, "SELECT COUNT(*) AS total FROM images AS i INNER JOIN categories AS c ON i.category_id = c.id WHERE c.user_id = $current_user_id");
$totalWords = mysqli_fetch_assoc($q_all)['total'];
$catCount = count($all_categories);
$rootIds = isset($categories_by_parent[0]) ? $categories_by_parent[0] : [];
mysqli_close($conn);

$colorPalette = ['#FF3B30', '#FF9500', '#FFCC00', '#34C759', '#00C7BE', '#30B0C7', '#32ADE6', '#007AFF', '#5856D6', '#AF52DE', '#FF2D55', '#A2845E'];

// Colore effettivo (già coerente grazie al cascade + auto-guarigione sopra) da usare nella mappa JS per l'ereditarietà live
$catColorMap = [];
foreach ($all_categories as $c) {
    $col = (!empty($c['color']) && preg_match('/^#[0-9A-Fa-f]{6}$/', $c['color'])) ? $c['color'] : '#A0A0A0';
    $catColorMap[(int)$c['id']] = $col;
}

function renderCatRow($id, $level, $all_cats, $cats_by_parent) {
    $cat = $all_cats[$id];
    $has_children = isset($cats_by_parent[$id]) && count($cats_by_parent[$id]) > 0;
    $can_delete = ($cat['word_count'] == 0 && !$has_children) ? 1 : 0;
    $display = ($level === 0) ? 'flex' : 'none';
    $pad = 12 + $level * 22;
    $nameEsc = htmlspecialchars($cat['name'], ENT_QUOTES);
    $color = (!empty($cat['color']) && preg_match('/^#[0-9A-Fa-f]{6}$/', $cat['color'])) ? $cat['color'] : '#A0A0A0';
    $parentId = (int)$cat['parent_id'];
    $isColorEdited = (!empty($cat['color_edited']) && $cat['color_edited'] == 1) ? 'true' : 'false';
?>
<div class="cat-row" data-id="<?php echo $id; ?>" data-parent="<?php echo $parentId; ?>" data-level="<?php echo $level; ?>" data-name="<?php echo $nameEsc; ?>" style="display:<?php echo $display; ?>; --row-color:<?php echo $color; ?>;">
    <div class="cat-swipe-actions">
        <button type="button" class="swipe-btn swipe-edit" aria-label="Modifica"
            onclick="chiudiTuttiSwipe(); apriModalSalva({id:<?php echo $id; ?>, name:'<?php echo addslashes($nameEsc); ?>', parentId:<?php echo $parentId; ?>, color:'<?php echo $color; ?>', colorEdited:<?php echo $isColorEdited; ?>})">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
        </button>
        <button type="button" class="swipe-btn swipe-move" aria-label="Sposta"
            onclick="chiudiTuttiSwipe(); apriSposta(<?php echo $id; ?>, '<?php echo addslashes($nameEsc); ?>')">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 9l-3 3 3 3M19 9l3 3-3 3M2 12h20"/></svg>
        </button>
        <?php if ($can_delete): ?>
        <button type="button" class="swipe-btn swipe-delete" aria-label="Elimina"
            onclick="chiudiTuttiSwipe(); apriConfermaElimina(<?php echo $id; ?>, '<?php echo addslashes($nameEsc); ?>')">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0-1 14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2L4 6"/></svg>
        </button>
        <?php endif; ?>
    </div>

    <div class="cat-row-main" style="padding-left:<?php echo $pad; ?>px;" data-cat-link="visualizzazione.php?category_id=<?php echo $id; ?>" onclick="catRowNavigate(event, this)">
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

        <span class="cat-name"><?php echo $nameEsc; ?></span>

        <span class="cat-badge"><?php echo (int)$cat['word_count']; ?></span>
        <button type="button" class="cat-kebab" aria-label="Altre azioni"
            data-id="<?php echo $id; ?>" data-name="<?php echo $nameEsc; ?>" data-parent="<?php echo $parentId; ?>"
            data-candelete="<?php echo $can_delete; ?>" data-color="<?php echo $color; ?>" data-color-edited="<?php echo (int)($cat['color_edited'] ?? 0); ?>"
            onclick="event.stopPropagation(); apriAzioni(this);">
            <svg viewBox="0 0 24 24" fill="currentColor"><circle cx="5" cy="12" r="1.7"/><circle cx="12" cy="12" r="1.7"/><circle cx="19" cy="12" r="1.7"/></svg>
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

// Opzioni <select> "categoria padre" con indentazione gerarchica
function renderParentOptions($parentId, $all_cats, $cats_by_parent, $level = 0) {
    if (!isset($cats_by_parent[$parentId])) return;
    foreach ($cats_by_parent[$parentId] as $id) {
        $cat = $all_cats[$id];
        $prefix = $level > 0 ? str_repeat('— ', $level) : '';
        echo "<option value='{$id}'>" . htmlspecialchars($prefix . $cat['name']) . "</option>";
        renderParentOptions($id, $all_cats, $cats_by_parent, $level + 1);
    }
}

// Lista indentata per il foglio "Sposta"
function renderMoveList($parentId, $all_cats, $cats_by_parent, $level = 0) {
    if (!isset($cats_by_parent[$parentId])) return;
    foreach ($cats_by_parent[$parentId] as $id) {
        $cat = $all_cats[$id];
        $nameEsc = htmlspecialchars($cat['name'], ENT_QUOTES);
        $color = (!empty($cat['color']) && preg_match('/^#[0-9A-Fa-f]{6}$/', $cat['color'])) ? $cat['color'] : '#A0A0A0';
        $pad = 14 + $level * 20;
        echo "<button type='button' class='cat-pick-item' data-id='{$id}' style='padding-left:{$pad}px' onclick='selezionaNuovoPadre({$id})'>";
        echo "<span class='cat-pick-dot' style='background:{$color}'></span>";
        echo "<span class='cat-pick-name'>{$nameEsc}</span>";
        echo "</button>";
        renderMoveList($id, $all_cats, $cats_by_parent, $level + 1);
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=0, viewport-fit=cover">
<meta name="theme-color" content="#F2F2F7" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#000000" media="(prefers-color-scheme: dark)">
<title>Categorie · Inglese</title>
<style>
:root{
    color-scheme: light dark;
    --bg:#F2F2F7; --surface:#FFFFFF; --surface-2:#F2F2F7;
    --text:#1C1C1E; --text-2:#8A8A8E; --text-3:#AEAEB2;
    --hairline:rgba(60,60,67,.12); --hairline-2:rgba(60,60,67,.22);
    --blue:#007AFF; --green:#34C759; --red:#FF3B30; --orange:#FF9500; --purple:#AF52DE;
    --indigo:#5856D6;
    --blue-soft:rgba(0,122,255,.09); --red-soft:rgba(255,59,48,.12);
    --header-glass:rgba(249,249,251,.82);
    --shadow-card:0 1px 1px rgba(0,0,0,.04), 0 10px 30px rgba(0,0,0,.07);
    --btn-hl:inset 0 1px 0 rgba(255,255,255,.25), inset 0 -1px 0 rgba(0,0,0,.16);
    --raise-sm:0 1px 1.5px rgba(0,0,0,.08), 0 5px 12px -4px rgba(0,0,0,.13);
    --r-card:22px; --r-btn:15px;
    --spring:cubic-bezier(.32,.72,0,1); --bounce:cubic-bezier(.34,1.56,.64,1);
    --safe-top:env(safe-area-inset-top, 0px);
    --safe-bottom:env(safe-area-inset-bottom, 0px);
}
@media (prefers-color-scheme: dark){
    :root{
        --bg:#000000; --surface:#1C1C1E; --surface-2:#2C2C2E;
        --text:#F5F5F7; --text-2:#98989F; --text-3:#636366;
        --hairline:rgba(84,84,88,.42); --hairline-2:rgba(84,84,88,.62);
        --blue:#0A84FF; --green:#30D158; --red:#FF453A; --orange:#FF9F0A; --purple:#BF5AF2;
        --indigo:#5E5CE6;
        --blue-soft:rgba(10,132,255,.16); --red-soft:rgba(255,69,58,.17);
        --header-glass:rgba(16,16,18,.78);
        --shadow-card:0 1px 1px rgba(0,0,0,.35), 0 10px 30px rgba(0,0,0,.5);
        --btn-hl:inset 0 1px 0 rgba(255,255,255,.22), inset 0 -1px 0 rgba(0,0,0,.3);
        --raise-sm:0 1px 1.5px rgba(0,0,0,.45), 0 5px 12px -4px rgba(0,0,0,.55);
    }
}
*{ box-sizing:border-box; -webkit-tap-highlight-color:transparent; }
html{ -webkit-text-size-adjust:100%; }
body{
    margin:0; min-height:100vh; background:var(--bg); color:var(--text);
    font-family:-apple-system, BlinkMacSystemFont, "SF Pro Text", "SF Pro Display", system-ui, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
    font-size:16px; line-height:1.4; -webkit-font-smoothing:antialiased; text-rendering:optimizeLegibility;
    padding-bottom:calc(24px + var(--safe-bottom)); overscroll-behavior-y:none;
}
a{ color:inherit; } button{ font-family:inherit; }
:focus-visible{ outline:2px solid var(--blue); outline-offset:2px; border-radius:8px; }

/* HEADER */
.app-header{
    position:sticky; top:0; z-index:100; padding:calc(var(--safe-top) + 12px) 16px 12px;
    background:var(--header-glass);
    -webkit-backdrop-filter:saturate(1.8) blur(20px); backdrop-filter:saturate(1.8) blur(20px);
    border-bottom:.5px solid var(--hairline);
}
.hdr-title{ font-size:28px; font-weight:800; letter-spacing:-.02em; display:flex; align-items:center; gap:9px; }
.hdr-flag{ font-size:22px; }
.hdr-sub{ margin-top:2px; font-size:13px; color:var(--text-2); font-weight:500; font-variant-numeric:tabular-nums; }
.search-box{
    margin-top:12px; display:flex; align-items:center; gap:8px; background:var(--surface-2);
    border-radius:12px; padding:10px 12px; box-shadow:inset 0 0 0 .5px var(--hairline);
}
.search-box svg{ width:17px; height:17px; color:var(--text-2); flex-shrink:0; }
.search-box input{ flex:1; min-width:0; border:none; outline:none; background:transparent; color:var(--text); font-size:16px; font-family:inherit; font-weight:500; }
.search-box input::placeholder{ color:var(--text-3); }
.search-clear{ display:none; align-items:center; justify-content:center; width:20px; height:20px; border-radius:50%; border:none; cursor:pointer; background:var(--text-3); color:var(--surface); flex-shrink:0; padding:0; }
.search-clear svg{ width:10px; height:10px; }

/* LAYOUT */
.page{ max-width:640px; margin:0 auto; padding:16px 14px calc(112px + var(--safe-bottom)); }
@keyframes cardIn{ from{ opacity:0; transform:translateY(14px) scale(.985); } to{ opacity:1; transform:none; } }

/* HERO */
.hero-card{
    display:flex; align-items:center; gap:14px; padding:20px; border-radius:var(--r-card); margin-bottom:18px;
    background:linear-gradient(135deg, var(--blue), var(--indigo)); color:#fff; text-decoration:none;
    box-shadow:0 1px 1px rgba(0,0,0,.05), 0 16px 34px -12px rgba(10,132,255,.5);
    animation:cardIn .5s var(--spring) both; transition:transform .16s var(--spring);
}
.hero-card:active{ transform:scale(.98); }
.hero-icon{ width:50px; height:50px; border-radius:14px; flex-shrink:0; background:rgba(255,255,255,.18); display:flex; align-items:center; justify-content:center; }
.hero-icon svg{ width:25px; height:25px; }
.hero-text{ flex:1; min-width:0; }
.hero-num{ font-size:25px; font-weight:800; letter-spacing:-.02em; font-variant-numeric:tabular-nums; }
.hero-label{ font-size:13.5px; font-weight:600; opacity:.88; margin-top:1px; }
.hero-chev{ flex-shrink:0; opacity:.75; display:flex; }
.hero-chev svg{ width:18px; height:18px; }

/* MATERIA CARD */
.materia-card{
    position:relative; background:var(--surface); border-radius:var(--r-card); margin-bottom:14px; overflow:hidden;
    box-shadow:var(--shadow-card); border-left:3px solid var(--accent, var(--blue)); animation:cardIn .5s var(--spring) both;
}
.cat-row{ position:relative; overflow:hidden; background:var(--surface); }
.cat-row-main{ display:flex; align-items:center; gap:10px; padding:15px 12px 15px 0; transition:background .15s; flex:1; width:100%; position:relative; z-index:2; background:var(--surface); will-change:transform; cursor:pointer; touch-action:pan-y; }
.cat-row:not([data-level="0"]) .cat-row-main{ border-top:.5px solid var(--hairline); padding:12px 12px 12px 0; }
.cat-row-main:active{ background:var(--surface-2); }
@keyframes rowIn{ from{ opacity:0; transform:translateY(-6px); } to{ opacity:1; transform:none; } }
.cat-row.row-anim{ animation:rowIn .3s var(--spring) both; }
.cat-arrow, .cat-arrow-spacer{ width:22px; height:22px; flex-shrink:0; }
.cat-arrow{ display:flex; align-items:center; justify-content:center; border:none; background:transparent; color:var(--text-3); cursor:pointer; border-radius:50%; padding:0; transition:transform .3s var(--spring), color .2s, background .15s; }
.cat-arrow svg{ width:16px; height:16px; }
.cat-arrow.open{ transform:rotate(90deg); color:var(--row-color, var(--blue)); }
.cat-arrow:active{ background:var(--surface-2); }
.cat-icon{ width:32px; height:32px; border-radius:9px; flex-shrink:0; display:flex; align-items:center; justify-content:center; }
.cat-icon svg{ width:16px; height:16px; }
.cat-icon.lvl0{ background:color-mix(in srgb, var(--row-color, var(--blue)) 18%, var(--surface)); color:var(--row-color, var(--blue)); width:36px; height:36px; border-radius:10px; transition:background .3s, color .3s; }
.cat-icon.lvl0 svg{ width:18px; height:18px; }
.cat-icon.lvln{ background:color-mix(in srgb, var(--row-color, var(--text-2)) 16%, var(--surface)); color:var(--row-color, var(--text-2)); transition:background .3s, color .3s; }
.cat-name{ flex:1; min-width:0; text-decoration:none; color:var(--text); font-size:15.5px; font-weight:600; letter-spacing:-.01em; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.cat-row[data-level="0"] .cat-name{ font-size:17px; font-weight:800; }
.cat-badge{ flex-shrink:0; background:var(--surface-2); color:var(--text-2); font-size:12px; font-weight:700; padding:4px 9px; border-radius:99px; box-shadow:inset 0 0 0 .5px var(--hairline); font-variant-numeric:tabular-nums; }
.cat-kebab{ flex-shrink:0; width:30px; height:30px; border:none; border-radius:50%; background:transparent; color:var(--text-3); cursor:pointer; padding:0; display:flex; align-items:center; justify-content:center; transition:background .15s; }
.cat-kebab svg{ width:18px; height:18px; }
.cat-kebab:active{ background:var(--surface-2); }

/* SWIPE */
.cat-swipe-actions{ position:absolute; top:0; left:0; bottom:0; display:none; align-items:stretch; z-index:1; }
.swipe-btn{ border:none; margin:0; padding:0; cursor:pointer; width:68px; display:flex; align-items:center; justify-content:center; color:#fff; }
.swipe-btn svg{ width:22px; height:22px; }
.swipe-edit{ background:var(--blue); } .swipe-move{ background:var(--orange); } .swipe-delete{ background:var(--red); }
body.touch-device .cat-swipe-actions{ display:flex; }
body.touch-device .cat-kebab{ display:none; }

/* FAB */
.fab{
    position:fixed; right:20px; bottom:calc(22px + var(--safe-bottom)); z-index:60;
    width:58px; height:58px; border-radius:50%; border:none; cursor:pointer; color:#fff; background:var(--blue);
    background:linear-gradient(180deg, color-mix(in srgb, var(--blue), #fff 16%), var(--blue) 52%, color-mix(in srgb, var(--blue), #000 10%));
    display:flex; align-items:center; justify-content:center;
    box-shadow:var(--btn-hl), 0 2px 6px rgba(0,0,0,.18), 0 14px 30px -8px rgba(10,132,255,.6);
    animation:fabIn .5s var(--spring) .35s both; transition:transform .16s var(--spring);
}
.fab svg{ width:25px; height:25px; }
.fab:active{ transform:scale(.9); }
@keyframes fabIn{ from{ opacity:0; transform:scale(.4) rotate(-25deg); } to{ opacity:1; transform:none; } }

/* EMPTY */
.empty-state{ text-align:center; padding:60px 20px 30px; animation:cardIn .5s var(--spring) both; }
.empty-icon{ width:72px; height:72px; margin:0 auto 18px; border-radius:20px; background:var(--blue-soft); color:var(--blue); display:flex; align-items:center; justify-content:center; }
.empty-icon svg{ width:32px; height:32px; }
.empty-title{ font-size:19px; font-weight:800; margin-bottom:6px; }
.empty-sub{ font-size:14.5px; color:var(--text-2); max-width:280px; margin:0 auto 20px; line-height:1.5; }
.btn-create-first{
    display:inline-flex; align-items:center; gap:8px; padding:13px 22px; border:none; border-radius:var(--r-btn); cursor:pointer; color:#fff; font-size:15.5px; font-weight:700;
    background:linear-gradient(180deg, color-mix(in srgb, var(--blue), #fff 16%), var(--blue) 52%, color-mix(in srgb, var(--blue), #000 10%));
    box-shadow:var(--btn-hl), 0 12px 26px -8px rgba(10,132,255,.55); transition:transform .16s var(--spring);
}
.btn-create-first:active{ transform:scale(.96); }

/* SHEET */
.modal-overlay{ display:none; position:fixed; inset:0; z-index:1000; justify-content:center; align-items:flex-end; background:rgba(0,0,0,.45); -webkit-backdrop-filter:blur(6px); backdrop-filter:blur(6px); }
.modal-overlay.closing{ animation:overlayOut .22s ease both; }
@keyframes overlayOut{ to{ opacity:0; } }
.sheet-panel{
    position:relative; width:100%; max-width:600px; max-height:88vh; overflow-y:auto; -webkit-overflow-scrolling:touch;
    background:var(--surface); color:var(--text); border-radius:24px 24px 0 0; padding:26px 20px calc(22px + var(--safe-bottom));
    box-shadow:0 -10px 50px rgba(0,0,0,.35); animation:sheetUp .38s var(--spring) both;
}
.modal-overlay.closing .sheet-panel{ animation:sheetDown .24s ease both; }
@keyframes sheetUp{ from{ transform:translateY(60%); opacity:.4; } to{ transform:none; opacity:1; } }
@keyframes sheetDown{ to{ transform:translateY(60%); opacity:0; } }
.sheet-panel::before{ content:""; position:absolute; top:8px; left:50%; transform:translateX(-50%); width:38px; height:5px; border-radius:99px; background:var(--hairline-2); }
.sheet-title{ margin:6px 0 18px; font-size:19px; font-weight:800; letter-spacing:-.01em; text-align:center; }
.sheet-sub{ margin:-12px 0 16px; font-size:13.5px; color:var(--text-2); text-align:center; }
@media (min-width:640px){ .modal-overlay{ align-items:center; padding:20px; } .sheet-panel{ border-radius:24px; } }

.field{ margin-bottom:16px; }
.field label{ display:block; margin-bottom:7px; font-size:11.5px; font-weight:800; letter-spacing:.07em; text-transform:uppercase; color:var(--text-2); }
.field input[type="text"], .field select{
    width:100%; padding:13px 14px; font-family:inherit; font-size:16px; font-weight:600; color:var(--text);
    background:var(--surface-2); border:.5px solid var(--hairline-2); border-radius:14px; -webkit-appearance:none; appearance:none;
}
.field select{ padding-right:38px; background-image:url("data:image/svg+xml;charset=utf-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%238A8A8E' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E"); background-repeat:no-repeat; background-position:right 13px center; }
.field input:focus, .field select:focus{ outline:none; box-shadow:0 0 0 2.5px var(--blue-soft), inset 0 0 0 1.5px var(--blue); }

.sheet-actions{ display:flex; gap:10px; margin-top:6px; }
.btn{ flex:1; display:flex; align-items:center; justify-content:center; gap:7px; padding:14px 16px; border:none; border-radius:var(--r-btn); cursor:pointer; font-family:inherit; font-size:16px; font-weight:700; letter-spacing:-.01em; text-decoration:none; transition:transform .16s var(--spring), filter .2s; }
.btn:active{ transform:scale(.97); filter:brightness(.95); }
.btn-cancel{ background:var(--surface-2); color:var(--text); }
.btn-primary{ color:#fff; background:linear-gradient(180deg, color-mix(in srgb, var(--blue), #fff 16%), var(--blue) 52%, color-mix(in srgb, var(--blue), #000 10%)); box-shadow:var(--btn-hl), 0 10px 22px -8px rgba(10,132,255,.55); }
.btn-danger{ color:#fff; background:linear-gradient(180deg, color-mix(in srgb, var(--red), #fff 16%), var(--red) 52%, color-mix(in srgb, var(--red), #000 10%)); box-shadow:var(--btn-hl), 0 10px 22px -8px rgba(255,59,48,.55); }

/* ACTION SHEET */
.action-list{ display:flex; flex-direction:column; gap:8px; margin-bottom:14px; }
.action-item{ display:flex; align-items:center; gap:12px; width:100%; padding:12px 12px; border:none; border-radius:14px; cursor:pointer; background:var(--surface-2); color:var(--text); font-family:inherit; font-size:16px; font-weight:600; text-align:left; transition:transform .15s var(--spring), background .15s; }
.action-item:active{ transform:scale(.98); background:var(--hairline); }
.action-item.danger{ color:var(--red); }
.action-item .a-ic{ width:34px; height:34px; border-radius:9px; flex-shrink:0; display:flex; align-items:center; justify-content:center; background:var(--blue-soft); color:var(--blue); }
.action-item .a-ic svg{ width:17px; height:17px; }
.action-item.danger .a-ic{ background:var(--red-soft); color:var(--red); }
.a-ic.color-wheel{ background:conic-gradient(from 180deg, #FF3B30, #FF9500, #FFCC00, #34C759, #00C7BE, #30B0C7, #32ADE6, #007AFF, #5856D6, #AF52DE, #FF2D55, #FF3B30); }

/* CONFIRM DELETE */
.confirm-icon{ width:56px; height:56px; margin:4px auto 14px; border-radius:16px; background:var(--red-soft); color:var(--red); display:flex; align-items:center; justify-content:center; }
.confirm-icon svg{ width:26px; height:26px; }
.confirm-text{ text-align:center; font-size:15px; color:var(--text-2); margin-bottom:18px; line-height:1.5; }
.confirm-text b{ color:var(--text); }

/* MOVE LIST */
.cat-pick-list{ display:flex; flex-direction:column; gap:6px; max-height:52vh; overflow-y:auto; margin-top:4px; }
.cat-pick-item{ display:flex; align-items:center; gap:10px; padding:12px 14px; border-radius:14px; cursor:pointer; background:var(--surface-2); border:none; width:100%; text-align:left; font-family:inherit; transition:transform .15s var(--spring), background .15s; }
.cat-pick-item:active{ transform:scale(.98); }
.cat-pick-dot{ width:12px; height:12px; border-radius:50%; flex-shrink:0; box-shadow:0 0 0 3px rgba(0,0,0,.04); }
.cat-pick-name{ font-size:15px; font-weight:700; color:var(--text); }
.cat-pick-root{ background:var(--blue-soft); }
.cat-pick-root .cat-pick-name{ color:var(--blue); }

/* COLOR PICKER */
.color-preview-row{ display:flex; align-items:center; gap:12px; padding:14px; margin-bottom:18px; background:var(--surface-2); border-radius:16px; }
.color-preview-icon{ width:44px; height:44px; border-radius:12px; flex-shrink:0; display:flex; align-items:center; justify-content:center; background:color-mix(in srgb, var(--row-color, var(--blue)) 18%, var(--surface)); color:var(--row-color, var(--blue)); transition:background .25s, color .25s; }
.color-preview-icon svg{ width:22px; height:22px; }
.color-preview-name{ font-size:16px; font-weight:700; flex:1; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.color-grid{ display:grid; grid-template-columns:repeat(6, 1fr); gap:10px; margin-bottom:18px; }
.color-swatch{ position:relative; width:100%; aspect-ratio:1; border-radius:50%; border:none; cursor:pointer; padding:0; background:var(--sw); box-shadow:inset 0 0 0 .5px rgba(0,0,0,.1); animation:swatchIn .4s var(--spring) both; transition:transform .18s var(--spring), box-shadow .2s; }
@keyframes swatchIn{ from{ opacity:0; transform:scale(0); } to{ opacity:1; transform:scale(1); } }
.color-swatch:active{ transform:scale(.82); }
.color-swatch.selected{ transform:scale(1.08); box-shadow:0 0 0 3px var(--surface), 0 0 0 5.5px var(--sw); }
.color-swatch .check-ic{ position:absolute; inset:0; margin:auto; width:16px; height:16px; opacity:0; transform:scale(.3); filter:drop-shadow(0 1px 2px rgba(0,0,0,.4)); transition:opacity .2s var(--spring), transform .3s var(--spring); }
.color-swatch.selected .check-ic{ opacity:1; transform:scale(1); }
.color-swatch-auto{ background:var(--surface); border:1.6px dashed var(--text-3); box-shadow:none; color:var(--text-2); display:flex; align-items:center; justify-content:center; }
.color-swatch-auto svg{ width:15px; height:15px; }
.color-swatch-auto.selected{ border:1.6px solid var(--blue); color:var(--blue); transform:scale(1.08); box-shadow:0 0 0 3px var(--surface), 0 0 0 5.5px var(--blue); }
.custom-color-row{ display:flex; align-items:center; gap:12px; padding:10px 2px 4px; margin-bottom:6px; }
.custom-color-row input[type="color"]{ width:42px; height:42px; border-radius:50%; border:none; padding:0; cursor:pointer; -webkit-appearance:none; appearance:none; background:none; flex-shrink:0; box-shadow:inset 0 0 0 .5px var(--hairline-2); }
.custom-color-row input[type="color"]::-webkit-color-swatch-wrapper{ padding:0; border-radius:50%; }
.custom-color-row input[type="color"]::-webkit-color-swatch{ border:none; border-radius:50%; }
.custom-color-row .cc-label{ font-size:14.5px; font-weight:600; color:var(--text); }
.custom-color-row .cc-hex{ margin-left:auto; font-size:14px; font-weight:700; color:var(--text-2); font-variant-numeric:tabular-nums; letter-spacing:.02em; }

/* TOAST */
.toast{ position:fixed; top:calc(var(--safe-top) + 14px); left:50%; z-index:20000; display:flex; align-items:center; gap:8px; padding:12px 18px; border-radius:99px; background:var(--text); color:var(--bg); font-size:14px; font-weight:700; box-shadow:0 12px 30px rgba(0,0,0,.28); transform:translateX(-50%) translateY(-20px); opacity:0; transition:transform .4s var(--spring), opacity .3s; }
.toast.show{ transform:translateX(-50%) translateY(0); opacity:1; }
.toast svg{ width:16px; height:16px; flex-shrink:0; }

@media (prefers-reduced-motion: reduce){ *, *::before, *::after{ animation-duration:.01ms !important; transition-duration:.01ms !important; } }
</style>
</head>
<body>

<div class="app-header">
    <div class="hdr-title"><span class="hdr-flag">🇬🇧</span> Categorie</div>
    <div class="hdr-sub"><?php echo $catCount; ?> categorie · <?php echo $totalWords; ?> parole totali</div>
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
    <a href="visualizzazione.php?category_id=0" class="hero-card">
        <div class="hero-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M3.6 9h16.8M3.6 15h16.8M12 3a14.5 14.5 0 0 1 0 18M12 3a14.5 14.5 0 0 0 0 18"/></svg>
        </div>
        <div class="hero-text">
            <div class="hero-num"><?php echo $totalWords; ?></div>
            <div class="hero-label">Tutte le parole</div>
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
            <div class="empty-sub">Crea la tua prima categoria per iniziare a organizzare le parole inglesi.</div>
            <button type="button" class="btn-create-first" onclick="apriModalSalva({id:0,name:'',parentId:0,color:'#A0A0A0',colorEdited:false})">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
                Crea categoria
            </button>
        </div>
    <?php else: ?>
        <?php foreach ($rootIds as $i => $rid):
            $rootCat = $all_categories[$rid];
            $rootColor = (!empty($rootCat['color']) && preg_match('/^#[0-9A-Fa-f]{6}$/', $rootCat['color'])) ? $rootCat['color'] : '#A0A0A0';
            $delay = min($i * 45, 300);
        ?>
        <div class="materia-card" style="--accent:<?php echo $rootColor; ?>; animation-delay:<?php echo $delay; ?>ms;">
            <?php renderCatRow($rid, 0, $all_categories, $categories_by_parent); ?>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<button type="button" class="fab" onclick="apriModalSalva({id:0,name:'',parentId:0,color:'#A0A0A0',colorEdited:false})" aria-label="Aggiungi categoria">
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
                <input type="text" name="category_name" id="form_cat_name" required placeholder="Es. Verbi irregolari">
            </div>

            <div class="field">
                <label for="form_parent_id">Categoria padre</label>
                <select name="parent_id" id="form_parent_id">
                    <option value="0">Nessuna (categoria principale)</option>
                    <?php renderParentOptions(0, $all_categories, $categories_by_parent); ?>
                </select>
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

<!-- SHEET: Azioni (kebab) -->
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

<!-- SHEET: Colore -->
<div id="sheetColor" class="modal-overlay">
    <div class="sheet-panel">
        <h3 class="sheet-title">Colore categoria</h3>
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

<!-- SHEET: Sposta -->
<div id="sheetMove" class="modal-overlay">
    <div class="sheet-panel">
        <h3 class="sheet-title">Sposta <span id="moveTargetName" style="color:var(--blue);"></span></h3>
        <p class="sheet-sub">Scegli la nuova posizione.</p>
        <div class="cat-pick-list" id="moveList">
            <button type="button" class="cat-pick-item cat-pick-root" data-id="0" onclick="selezionaNuovoPadre(0)">
                <span class="cat-pick-dot" style="background:var(--blue)"></span>
                <span class="cat-pick-name">Porta in cima (nessun padre)</span>
            </button>
            <?php renderMoveList(0, $all_categories, $categories_by_parent); ?>
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

<form id="formSposta" method="POST" style="display:none;">
    <input type="hidden" name="action" value="move">
    <input type="hidden" name="move_category_id" id="move_cat_id">
    <input type="hidden" name="target_parent_id" id="target_parent_id">
</form>
<form id="formDelete" method="POST" style="display:none;">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="delete_category_id" id="delete_cat_id">
</form>

<script>
if (window.matchMedia("(pointer:coarse)").matches) { document.body.classList.add("touch-device"); }

var CAT_COLOR_MAP = <?php echo json_encode($catColorMap); ?>;

/* Mappa figli per calcolo sottoalbero (evita cicli nello spostamento) */
var childrenMap = {};
document.querySelectorAll('.cat-row').forEach(function(r){
    var p = r.dataset.parent || '0';
    (childrenMap[p] = childrenMap[p] || []).push(r.dataset.id);
});
function subtreeIds(id){
    var out = [String(id)], stack = [String(id)];
    while (stack.length){
        var cur = stack.pop();
        (childrenMap[cur] || []).forEach(function(c){ out.push(c); stack.push(c); });
    }
    return out;
}

/* ---------- Sheet open/close ---------- */
function openSheet(el){ el.style.display='flex'; el.classList.remove('closing'); }
function closeSheet(el){ el.classList.add('closing'); setTimeout(function(){ el.style.display='none'; el.classList.remove('closing'); }, 240); }
function chiudiModals(){ document.querySelectorAll('.modal-overlay').forEach(function(ov){ if (ov.style.display==='flex') closeSheet(ov); }); }
document.querySelectorAll('.modal-overlay').forEach(function(ov){ ov.addEventListener('click', function(e){ if (e.target===ov) closeSheet(ov); }); });

/* ---------- Espandi / comprimi ---------- */
function toggleBranch(id){
    var row = document.querySelector('.cat-row[data-id="'+id+'"]');
    var arrow = row.querySelector('.cat-arrow');
    if (arrow.classList.contains('open')){ arrow.classList.remove('open'); comprimiFigli(id); }
    else { arrow.classList.add('open'); espandiFigli(id); }
}
function espandiFigli(parentId){
    document.querySelectorAll('.cat-row[data-parent="'+parentId+'"]').forEach(function(row){
        row.style.display='flex'; row.style.animation='none'; void row.offsetWidth; row.style.animation=''; row.classList.add('row-anim');
        var subArrow = row.querySelector('.cat-arrow');
        if (subArrow && subArrow.classList.contains('open')) espandiFigli(row.dataset.id);
    });
}
function comprimiFigli(parentId){
    document.querySelectorAll('.cat-row[data-parent="'+parentId+'"]').forEach(function(row){ row.style.display='none'; comprimiFigli(row.dataset.id); });
}

/* ---------- Ricerca ---------- */
function filterCategories(raw){
    var q = raw.trim().toLowerCase();
    var rows = document.querySelectorAll('.cat-row');
    document.getElementById('searchClear').style.display = q ? 'flex' : 'none';
    if (!q){
        rows.forEach(function(r){ r.style.display = (r.dataset.level==='0') ? 'flex' : 'none'; });
        document.querySelectorAll('.cat-arrow.open').forEach(function(a){ a.classList.remove('open'); });
        document.querySelectorAll('.materia-card').forEach(function(c){ c.style.display=''; });
        return;
    }
    var byId = {}; rows.forEach(function(r){ byId[r.dataset.id]=r; });
    var visible = new Set();
    rows.forEach(function(r){
        if (r.dataset.name.toLowerCase().indexOf(q)!==-1){
            var cur=r;
            while(cur){ visible.add(cur.dataset.id); var pid=cur.dataset.parent; cur=(pid && pid!=='0')?byId[pid]:null; }
        }
    });
    rows.forEach(function(r){
        var show = visible.has(r.dataset.id);
        r.style.display = show ? 'flex' : 'none';
        if (show){ var a=r.querySelector('.cat-arrow'); if (a) a.classList.add('open'); }
    });
    document.querySelectorAll('.materia-card').forEach(function(card){
        var any = Array.prototype.some.call(card.querySelectorAll('.cat-row'), function(r){ return visible.has(r.dataset.id); });
        card.style.display = any ? '' : 'none';
    });
}
function clearSearch(){ var i=document.getElementById('searchInput'); i.value=''; filterCategories(''); i.focus(); }

/* ---------- Nuova / Modifica ---------- */
var formColorManual=false, formColorValue='#A0A0A0', formParentColor='#A0A0A0';
function apriModalSalva(opts){
    document.getElementById('form_cat_id').value = opts.id;
    document.getElementById('form_cat_name').value = opts.name;
    document.getElementById('modalSalvaTitolo').textContent = (opts.id==0) ? 'Nuova categoria' : 'Modifica categoria';

    var sel = document.getElementById('form_parent_id');
    sel.value = opts.parentId;
    var excluded = (opts.id==0) ? [] : subtreeIds(opts.id);
    for (var i=0;i<sel.options.length;i++){ sel.options[i].disabled = excluded.indexOf(sel.options[i].value)!==-1 && sel.options[i].value!=='0'; }
    if (sel.selectedOptions.length && sel.selectedOptions[0].disabled){ sel.value='0'; }

    formColorManual = !!opts.colorEdited;
    formParentColor = (opts.parentId==0) ? '#A0A0A0' : (CAT_COLOR_MAP[opts.parentId] || '#A0A0A0');
    formColorValue = formColorManual ? (opts.color || '#A0A0A0') : formParentColor;
    updateFormColorUI();

    openSheet(document.getElementById('sheetForm'));
    setTimeout(function(){ document.getElementById('form_cat_name').focus(); }, 350);
}
document.getElementById('form_parent_id').addEventListener('change', function(){
    formParentColor = (this.value=='0') ? '#A0A0A0' : (CAT_COLOR_MAP[this.value] || '#A0A0A0');
    if (!formColorManual){ formColorValue = formParentColor; updateFormColorUI(); }
});
function setFormColorAuto(){ formColorManual=false; formColorValue=formParentColor; updateFormColorUI(); }
function setFormColorManual(hex){ formColorManual=true; formColorValue=hex; updateFormColorUI(); }
function updateFormColorUI(){
    document.getElementById('form_color_mode').value = formColorManual ? 'manual' : 'auto';
    document.getElementById('form_color_value').value = formColorValue;
    document.getElementById('formColorPreviewIcon').style.setProperty('--row-color', formColorValue);
    document.getElementById('formColorPreviewName').textContent = formColorManual ? 'Colore personalizzato' : 'Automatico (eredita dal padre)';
    document.getElementById('formCustomColorInput').value = formColorValue;
    document.getElementById('formCustomColorHex').textContent = formColorValue.toUpperCase();
    document.querySelectorAll('#formColorGrid .color-swatch').forEach(function(sw){
        if (sw.classList.contains('color-swatch-auto')) sw.classList.toggle('selected', !formColorManual);
        else sw.classList.toggle('selected', formColorManual && (sw.dataset.hex||'').toLowerCase()===formColorValue.toLowerCase());
    });
}

/* ---------- Azioni (kebab) ---------- */
function apriAzioni(btn){
    var id=btn.dataset.id, name=btn.dataset.name, parentId=btn.dataset.parent;
    var color=btn.dataset.color||'#007AFF', colorEdited=btn.dataset.colorEdited==='1', canDelete=btn.dataset.candelete==='1';
    document.getElementById('azioniTitolo').textContent = name;
    document.getElementById('azioneModifica').onclick = function(){ chiudiModals(); setTimeout(function(){ apriModalSalva({id:id,name:name,parentId:parentId,color:color,colorEdited:colorEdited}); }, 260); };
    document.getElementById('azioneColore').onclick = function(){ chiudiModals(); setTimeout(function(){ apriColore(id,name,color); }, 260); };
    document.getElementById('azioneSposta').onclick = function(){ chiudiModals(); setTimeout(function(){ apriSposta(id,name); }, 260); };
    var delBtn = document.getElementById('azioneElimina');
    if (canDelete){ delBtn.style.display='flex'; delBtn.onclick=function(){ chiudiModals(); setTimeout(function(){ apriConfermaElimina(id,name); }, 260); }; }
    else { delBtn.style.display='none'; }
    openSheet(document.getElementById('sheetActions'));
}

/* ---------- Sposta ---------- */
function apriSposta(id, name){
    document.getElementById('move_cat_id').value = id;
    document.getElementById('moveTargetName').textContent = '"' + name + '"';
    var excluded = subtreeIds(id);
    document.querySelectorAll('#moveList .cat-pick-item').forEach(function(it){
        var iid = it.dataset.id;
        it.style.display = (iid!=='0' && excluded.indexOf(iid)!==-1) ? 'none' : 'flex';
    });
    openSheet(document.getElementById('sheetMove'));
}
function selezionaNuovoPadre(parentId){ document.getElementById('target_parent_id').value = parentId; document.getElementById('formSposta').submit(); }

/* ---------- Colore ---------- */
var colorTargetId=null, colorOriginal='#007AFF', colorCurrent='#007AFF';
function apriColore(id, name, color){
    colorTargetId=id; colorOriginal=color; colorCurrent=color;
    document.getElementById('color_cat_id').value=id;
    document.getElementById('colorPreviewName').textContent=name;
    document.getElementById('customColorInput').value=color;
    document.getElementById('customColorHex').textContent=color.toUpperCase();
    applyColorPreview(color); highlightSwatch(color);
    openSheet(document.getElementById('sheetColor'));
}
function selectColor(hex){
    colorCurrent=hex;
    document.getElementById('customColorInput').value=hex;
    document.getElementById('customColorHex').textContent=hex.toUpperCase();
    applyColorPreview(hex); highlightSwatch(hex);
}
function highlightSwatch(hex){
    var norm=hex.toLowerCase();
    document.querySelectorAll('#colorGrid .color-swatch').forEach(function(sw){ if (sw.dataset.hex) sw.classList.toggle('selected', sw.dataset.hex.toLowerCase()===norm); });
}
function applyColorPreview(hex){
    document.getElementById('colorPreviewIcon').style.setProperty('--row-color', hex);
    var row = document.querySelector('.cat-row[data-id="'+colorTargetId+'"]');
    if (row){
        row.style.setProperty('--row-color', hex);
        if (row.dataset.level==='0'){ var card=row.closest('.materia-card'); if (card) card.style.setProperty('--accent', hex); }
    }
    var kebab = document.querySelector('.cat-kebab[data-id="'+colorTargetId+'"]');
    if (kebab) kebab.dataset.color = hex;
}
function annullaColore(){ applyColorPreview(colorOriginal); chiudiModals(); }
function salvaColore(){ document.getElementById('color_cat_value').value=colorCurrent; document.getElementById('formColor').submit(); }

/* ---------- Elimina ---------- */
function apriConfermaElimina(id, name){
    document.getElementById('delete_cat_id').value=id;
    document.getElementById('confermaEliminaNome').textContent='"'+name+'"';
    openSheet(document.getElementById('sheetConfirmDelete'));
}
function confermaEliminaSubmit(){ document.getElementById('formDelete').submit(); }

/* ---------- Toast ---------- */
function showToast(text){
    var t=document.createElement('div'); t.className='toast';
    t.innerHTML='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg><span>'+text+'</span>';
    document.body.appendChild(t);
    requestAnimationFrame(function(){ t.classList.add('show'); });
    setTimeout(function(){ t.classList.remove('show'); setTimeout(function(){ t.remove(); }, 320); }, 2200);
}
(function(){
    var ok = new URLSearchParams(window.location.search).get('ok');
    var messages = { added:'Categoria creata', saved:'Modifiche salvate', deleted:'Categoria eliminata', moved:'Categoria spostata', colored:'Colore aggiornato' };
    if (ok && messages[ok]){ showToast(messages[ok]); history.replaceState(null, '', window.location.pathname); }
})();

/* ---------- Navigazione riga ---------- */
function catRowNavigate(e, el){
    if (el.classList.contains('swiped-open')){ chiudiTuttiSwipe(); return; }
    if (document.querySelector('.cat-row-main.swiped-open')){ chiudiTuttiSwipe(); return; }
    var link = el.getAttribute('data-cat-link');
    if (link) window.location.href = link;
}

/* ---------- Swipe (touch, sinistra->destra rivela le azioni) ---------- */
window.chiudiTuttiSwipe = function(){};
(function(){
    if (!window.matchMedia("(hover:none) and (pointer:coarse)").matches) return;
    var activeRow=null, startX=0, startY=0, isDragging=false, maxSwipe=0, dirDecided=false, isHorizontal=false;
    var DEADZONE=10, RATIO=2.2; // serve un movimento chiaramente orizzontale (>2.2x quello verticale) prima di attivare lo swipe
    window.chiudiTuttiSwipe = function(){
        document.querySelectorAll('.cat-row-main.swiped-open').forEach(function(r){ r.style.transform='translateX(0)'; r.classList.remove('swiped-open'); });
        activeRow=null;
    };
    document.addEventListener('touchstart', function(e){
        if (e.target.closest('.cat-swipe-actions')) return;
        var rowMain = e.target.closest('.cat-row-main');
        if (!rowMain){ chiudiTuttiSwipe(); return; }
        if (activeRow && activeRow!==rowMain) chiudiTuttiSwipe();
        activeRow=rowMain;
        var actions = rowMain.parentElement.querySelector('.cat-swipe-actions');
        if (!actions) return;
        maxSwipe = actions.offsetWidth;
        startX=e.touches[0].clientX; startY=e.touches[0].clientY; isDragging=true; dirDecided=false; isHorizontal=false;
        rowMain.style.transition='none';
    }, {passive:true});
    document.addEventListener('touchmove', function(e){
        if (!isDragging || !activeRow) return;
        var dx=e.touches[0].clientX-startX, dy=e.touches[0].clientY-startY;
        if (!dirDecided){
            if (Math.abs(dx) < DEADZONE && Math.abs(dy) < DEADZONE) return;
            dirDecided = true;
            isHorizontal = Math.abs(dx) > Math.abs(dy) * RATIO;
            if (!isHorizontal){ isDragging=false; activeRow=null; return; }
        }
        if (!isHorizontal) return;
        if (dx>0){ var tx=Math.min(dx, maxSwipe+20); activeRow.style.transform='translateX('+tx+'px)'; e.preventDefault(); }
        else if (dx<0){ var t2=Math.max(dx,-15); activeRow.style.transform='translateX('+t2+'px)'; e.preventDefault(); }
    }, {passive:false});
    document.addEventListener('touchend', function(){
        if (!isDragging || !activeRow) return;
        isDragging=false; activeRow.style.transition='transform .3s cubic-bezier(.32,.72,0,1)';
        var tx=0, ts=activeRow.style.transform;
        if (ts && ts.indexOf('translateX')!==-1) tx=parseFloat(ts.replace('translateX(',''));
        if (tx>maxSwipe/2){ activeRow.style.transform='translateX('+maxSwipe+'px)'; activeRow.classList.add('swiped-open'); }
        else { activeRow.style.transform='translateX(0)'; activeRow.classList.remove('swiped-open'); activeRow=null; }
    });
})();
</script>
</body>
</html>