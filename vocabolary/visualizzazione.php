<?php
require_once 'config.php';
$conn = mysqli_connect(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
if (!$conn) { die("Connessione fallita: " . mysqli_connect_error()); }

require_once 'auth_check.php';
$current_user_id = $utente_corrente['id'];

/* NOTA SCHEMA: la tabella `images` ha come chiave primaria `id` (vedi dump:
   AUTO_INCREMENT su `id` e FK english_child_words.parent_id -> images(id)).
   Il vecchio file usava `idimmagine`: se la tua tabella reale usa ancora quel
   nome, sostituisci `i.id`/`WHERE id` con `idimmagine`. */

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

// ==========================================
// AZIONI DI MASSA E JOIN / UNJOIN
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {
    $action = $_POST['bulk_action'];
    $qs = "";
    if (isset($_GET['category_id'])) { $qs = "?category_id=" . intval($_GET['category_id']); }
    if (isset($_GET['sort'])) { $qs .= ($qs === '' ? '?' : '&') . "sort=" . urlencode($_GET['sort']); }

    if ($action === 'delete') {
        if (!empty($_POST['selected_ids']) && is_array($_POST['selected_ids'])) {
            $ids_str = implode(',', array_map('intval', $_POST['selected_ids']));
            if ($ids_str !== '') mysqli_query($conn, "DELETE FROM images WHERE id IN ($ids_str)");
        }
        if (!empty($_POST['selected_child_ids']) && is_array($_POST['selected_child_ids'])) {
            $c_ids_str = implode(',', array_map('intval', $_POST['selected_child_ids']));
            if ($c_ids_str !== '') mysqli_query($conn, "DELETE FROM english_child_words WHERE id_child IN ($c_ids_str)");
        }
        $qs .= "&ok=deleted";
    }
    elseif ($action === 'move' && isset($_POST['target_category']) && !empty($_POST['selected_ids']) && is_array($_POST['selected_ids'])) {
        $target_cat = intval($_POST['target_category']);
        $ids_str = implode(',', array_map('intval', $_POST['selected_ids']));
        if ($ids_str !== '') mysqli_query($conn, "UPDATE images SET category_id = $target_cat WHERE id IN ($ids_str)");
        $qs .= "&ok=moved";
    }
    elseif ($action === 'join' && isset($_POST['father_id']) && !empty($_POST['selected_ids'])) {
        $father_id = intval($_POST['father_id']);
        foreach ($_POST['selected_ids'] as $child_id) {
            $child_id = intval($child_id);
            if ($child_id === $father_id) continue;
            $res = mysqli_query($conn, "SELECT * FROM images WHERE id = $child_id");
            if ($row = mysqli_fetch_assoc($res)) {
                $img  = mysqli_real_escape_string($conn, $row['image']);
                $den  = mysqli_real_escape_string($conn, $row['description_en']);
                $dit  = mysqli_real_escape_string($conn, $row['description_it']);
                $mean = mysqli_real_escape_string($conn, $row['meaning']);
                $ex   = mysqli_real_escape_string($conn, $row['example']);
                mysqli_query($conn, "INSERT INTO english_child_words (parent_id, image, description_en, description_it, meaning, example) VALUES ($father_id, '$img', '$den', '$dit', '$mean', '$ex')");
                mysqli_query($conn, "DELETE FROM images WHERE id = $child_id");
            }
        }
        mysqli_query($conn, "UPDATE images SET is_father = 1 WHERE id = $father_id");
        $qs .= "&ok=joined";
    }
    elseif ($action === 'unjoin' && !empty($_POST['selected_child_ids'])) {
        $parents_to_check = [];
        foreach ($_POST['selected_child_ids'] as $child_id) {
            $child_id = intval($child_id);
            $res = mysqli_query($conn, "SELECT * FROM english_child_words WHERE id_child = $child_id");
            if ($row = mysqli_fetch_assoc($res)) {
                $parent_id = intval($row['parent_id']);
                $parents_to_check[$parent_id] = true;
                $p_res = mysqli_query($conn, "SELECT category_id FROM images WHERE id = $parent_id");
                $p_row = mysqli_fetch_assoc($p_res);
                $cat_id = $p_row ? intval($p_row['category_id']) : 0;
                $img  = mysqli_real_escape_string($conn, $row['image']);
                $den  = mysqli_real_escape_string($conn, $row['description_en']);
                $dit  = mysqli_real_escape_string($conn, $row['description_it']);
                $mean = mysqli_real_escape_string($conn, $row['meaning']);
                $ex   = mysqli_real_escape_string($conn, $row['example']);
                mysqli_query($conn, "INSERT INTO images (image, description_en, description_it, meaning, example, category_id, is_father) VALUES ('$img', '$den', '$dit', '$mean', '$ex', $cat_id, 0)");
                mysqli_query($conn, "DELETE FROM english_child_words WHERE id_child = $child_id");
            }
        }
        foreach (array_keys($parents_to_check) as $pid) {
            $c_res = mysqli_query($conn, "SELECT COUNT(*) AS c FROM english_child_words WHERE parent_id = $pid");
            if (mysqli_fetch_assoc($c_res)['c'] == 0) {
                mysqli_query($conn, "UPDATE images SET is_father = 0 WHERE id = $pid");
            }
        }
        $qs .= "&ok=unjoined";
    }

    header("Location: visualizzazione.php" . $qs);
    exit;
}

// Mappa categorie DI QUESTO UTENTE (per breadcrumb, colori, sottocategorie, foglio "sposta")
$cat_map = [];
$cat_children = [];
$cq = mysqli_query($conn, "SELECT id, name, parent_id, color, color_edited FROM categories WHERE user_id = $current_user_id ORDER BY name");
while ($c = mysqli_fetch_assoc($cq)) {
    if (intval($c['color_edited']) === 0) {
        $c['color'] = en_resolve_color($conn, $c['parent_id'], $current_user_id);
    }
    $cat_map[(int)$c['id']] = $c;
    $cat_children[(int)$c['parent_id']][] = (int)$c['id'];
}

// Conteggio parole (images) per categoria — solo categorie di questo utente
$count_by_cat = [];
$cc = mysqli_query($conn, "SELECT i.category_id, COUNT(*) AS n FROM images AS i INNER JOIN categories AS c ON i.category_id = c.id WHERE c.user_id = $current_user_id GROUP BY i.category_id");
while ($r = mysqli_fetch_assoc($cc)) { $count_by_cat[(int)$r['category_id']] = (int)$r['n']; }

// Duplicati (per description_en, solo sulle parole di questo utente)
$global_en_counts = [];
$res_all_words = mysqli_query($conn, "SELECT i.description_en FROM images AS i INNER JOIN categories AS c ON i.category_id = c.id WHERE c.user_id = $current_user_id");
while ($r = mysqli_fetch_assoc($res_all_words)) {
    $en_k = strtolower(trim($r['description_en']));
    if (!isset($global_en_counts[$en_k])) $global_en_counts[$en_k] = 0;
    $global_en_counts[$en_k]++;
}

$categoryID = isset($_GET['category_id']) ? (int)$_GET['category_id'] : 0;

// Info categoria corrente + breadcrumb
$currentCatName = 'Tutte le parole';
$themeColor = '#5E5CE6';
$breadcrumb = [];
if ($categoryID != 0 && isset($cat_map[$categoryID])) {
    $currentCatName = $cat_map[$categoryID]['name'];
    $cc2 = $cat_map[$categoryID]['color'];
    if (!empty($cc2) && preg_match('/^#[0-9A-Fa-f]{6}$/', $cc2)) $themeColor = $cc2;
    $walk = $categoryID; $guard = 0;
    while ($walk != 0 && isset($cat_map[$walk]) && $guard < 50) {
        $guard++;
        array_unshift($breadcrumb, ['id' => $walk, 'name' => $cat_map[$walk]['name']]);
        $walk = (int)$cat_map[$walk]['parent_id'];
    }
}

// Sottocategorie della categoria corrente
$subcats = [];
if ($categoryID != 0 && isset($cat_children[$categoryID])) {
    foreach ($cat_children[$categoryID] as $sid) {
        $sc = $cat_map[$sid];
        $subcats[] = ['id' => $sid, 'name' => $sc['name'],
                      'color' => (!empty($sc['color']) && preg_match('/^#[0-9A-Fa-f]{6}$/', $sc['color'])) ? $sc['color'] : '#A0A0A0',
                      'count' => isset($count_by_cat[$sid]) ? $count_by_cat[$sid] : 0];
    }
}

// Query principale — query "blindata": la JOIN rigida su categories garantisce che
// vengano mostrate solo le parole delle categorie di questo utente.
$query = "SELECT i.*, c.name AS category_name, c.color AS category_color,
                 (CASE WHEN ul.image_id IS NOT NULL THEN 1 ELSE 0 END) AS is_learnt
          FROM images AS i
          INNER JOIN categories AS c ON i.category_id = c.id
          LEFT JOIN user_learnt_english ul ON i.id = ul.image_id AND ul.user_id = $current_user_id
          WHERE c.user_id = $current_user_id";
if ($categoryID != 0) { $query .= " AND i.category_id = $categoryID"; }

$sort = isset($_GET['sort']) ? $_GET['sort'] : 'id_desc';
$order_by = "i.id DESC";
if ($sort === 'id_asc') { $order_by = "i.id ASC"; }
elseif ($sort === 'en_asc') { $order_by = "TRIM(LOWER(i.description_en)) ASC"; }
elseif ($sort === 'it_asc') { $order_by = "TRIM(LOWER(i.description_it)) ASC"; }
$query .= " ORDER BY $order_by";

$result = mysqli_query($conn, $query);
$images_data = [];
$ids_array = [];
while ($row = mysqli_fetch_assoc($result)) {
    $row['children'] = [];
    $images_data[$row['id']] = $row;
    $ids_array[] = (int)$row['id'];
}
if (count($ids_array) > 0) {
    $ids_str = implode(',', $ids_array);
    $res_children = mysqli_query($conn, "SELECT * FROM english_child_words WHERE parent_id IN ($ids_str)");
    while ($c = mysqli_fetch_assoc($res_children)) {
        if (isset($images_data[$c['parent_id']])) $images_data[$c['parent_id']]['children'][] = $c;
    }
}
$wordCount = count($images_data);
mysqli_close($conn);

// Formatta testo (escape + newline). Usata per il Significato.
function eng_text($t) {
    $t = htmlspecialchars($t, ENT_QUOTES);
    return nl2br($t);
}
// Formatta l'Esempio: dove ci sono "___" mostra la parola inglese in grassetto,
// colorata con il colore della categoria (mescolato con --text per restare leggibile
// anche quando il colore categoria è vicino al bianco o al nero).
function eng_example_text($t, $enWord) {
    $t = htmlspecialchars($t, ENT_QUOTES);
    $fill = trim($enWord) !== '' ? htmlspecialchars($enWord, ENT_QUOTES) : '&hellip;';
    $t = preg_replace_callback('/_+/', function ($m) use ($fill) {
        return '<strong class="eng-fill">' . $fill . '</strong>';
    }, $t);
    return nl2br($t);
}
function eng_search($row) {
    return htmlspecialchars(strtolower(($row['description_en'] ?? '') . ' ' . ($row['description_it'] ?? '') . ' ' . ($row['meaning'] ?? '') . ' ' . ($row['example'] ?? '')), ENT_QUOTES);
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=0, viewport-fit=cover">
<meta name="theme-color" content="#F2F2F7" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#000000" media="(prefers-color-scheme: dark)">
<title><?php echo htmlspecialchars($currentCatName); ?> · Inglese</title>
<style>
:root{
    color-scheme: light dark;
    --bg:#F2F2F7; --surface:#FFFFFF; --surface-2:#F2F2F7; --surface-3:#EBEBF0;
    --text:#1C1C1E; --text-2:#8A8A8E; --text-3:#AEAEB2;
    --hairline:rgba(60,60,67,.12); --hairline-2:rgba(60,60,67,.22);
    --blue:#007AFF; --green:#34C759; --red:#FF3B30; --orange:#FF9500; --purple:#AF52DE; --indigo:#5856D6;
    --blue-soft:rgba(0,122,255,.09); --red-soft:rgba(255,59,48,.12); --green-soft:rgba(52,199,89,.14);
    --header-glass:rgba(249,249,251,.82);
    --shadow-card:0 1px 1px rgba(0,0,0,.04), 0 10px 30px rgba(0,0,0,.07);
    --btn-hl:inset 0 1px 0 rgba(255,255,255,.25), inset 0 -1px 0 rgba(0,0,0,.16);
    --pill-hl:inset 0 0 0 .5px var(--hairline);
    --raise-sm:0 1px 1.5px rgba(0,0,0,.08), 0 5px 12px -4px rgba(0,0,0,.13);
    --r-card:22px; --r-btn:15px; --r-chip:100px;
    --spring:cubic-bezier(.32,.72,0,1); --bounce:cubic-bezier(.34,1.56,.64,1);
    --safe-top:env(safe-area-inset-top, 0px); --safe-bottom:env(safe-area-inset-bottom, 0px);
    --cat-color:#5E5CE6; --header-h:150px;
    --cat-soft: color-mix(in srgb, var(--cat-color), transparent 88%);
    --cat-soft-2: color-mix(in srgb, var(--cat-color), transparent 74%);
    --cat-line: color-mix(in srgb, var(--cat-color), transparent 35%);
    --cat-dark: color-mix(in srgb, var(--cat-color), black 22%);
    --cat-light: color-mix(in srgb, var(--cat-color), white 22%);
}
@media (prefers-color-scheme: dark){
    :root{
        --bg:#000000; --surface:#1C1C1E; --surface-2:#2C2C2E; --surface-3:#3A3A3C;
        --text:#F5F5F7; --text-2:#98989F; --text-3:#636366;
        --hairline:rgba(84,84,88,.42); --hairline-2:rgba(84,84,88,.62);
        --blue:#0A84FF; --green:#30D158; --red:#FF453A; --orange:#FF9F0A; --purple:#BF5AF2; --indigo:#5E5CE6;
        --blue-soft:rgba(10,132,255,.16); --red-soft:rgba(255,69,58,.17); --green-soft:rgba(48,209,88,.2);
        --header-glass:rgba(16,16,18,.78);
        --shadow-card:0 1px 1px rgba(0,0,0,.35), 0 10px 30px rgba(0,0,0,.5);
        --btn-hl:inset 0 1px 0 rgba(255,255,255,.22), inset 0 -1px 0 rgba(0,0,0,.3);
        --pill-hl:inset 0 0 0 .5px var(--hairline);
        --raise-sm:0 1px 1.5px rgba(0,0,0,.45), 0 5px 12px -4px rgba(0,0,0,.55);
    }
}
*{ box-sizing:border-box; -webkit-tap-highlight-color:transparent; }
html{ -webkit-text-size-adjust:100%; scroll-padding-top:calc(var(--header-h) + 12px); overflow-x:hidden; width:100%; }
body{
    margin:0; min-height:100vh; background:var(--bg); color:var(--text);
    font-family:-apple-system, BlinkMacSystemFont, "SF Pro Text", "SF Pro Display", system-ui, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
    font-size:16px; line-height:1.4; -webkit-font-smoothing:antialiased; text-rendering:optimizeLegibility;
    padding-bottom:calc(30px + var(--safe-bottom));
    overflow-x:hidden; width:100%;
}
a{ color:inherit; text-decoration:none; } button{ font-family:inherit; }
:focus-visible{ outline:2px solid var(--cat-color); outline-offset:2px; border-radius:8px; }

/* HEADER */
.app-header{
    position:sticky; top:0; z-index:100; padding-top:var(--safe-top);
    background:var(--header-glass); -webkit-backdrop-filter:saturate(1.8) blur(22px); backdrop-filter:saturate(1.8) blur(22px);
    border-bottom:.5px solid var(--hairline); box-shadow:0 1px 0 rgba(0,0,0,.02);
}
.header-top{ display:flex; align-items:center; gap:10px; padding:10px 14px 8px; }
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
.cat-pill-count{ flex-shrink:0; font-size:11.5px; font-weight:800; padding:5px 10px; border-radius:99px; background:rgba(255,255,255,.22); font-variant-numeric:tabular-nums; }

.icon-btn{
    width:38px; height:38px; border-radius:50%; border:none; cursor:pointer; padding:0; flex-shrink:0;
    background:var(--surface); color:var(--text-2); text-decoration:none;
    display:flex; align-items:center; justify-content:center;
    box-shadow:var(--pill-hl), var(--raise-sm);
    transition:transform .15s var(--spring), box-shadow .2s var(--spring);
}
.icon-btn:active{ transform:scale(.88); box-shadow:var(--pill-hl), inset 0 2px 5px rgba(0,0,0,.1); }
.icon-btn svg{ width:18px; height:18px; }

.header-row2{ display:flex; align-items:center; gap:8px; padding:0 14px 10px; }
.quick-add-btn{ background:linear-gradient(180deg, color-mix(in srgb, var(--cat-color), white 18%), var(--cat-color) 55%, color-mix(in srgb, var(--cat-color), black 10%)); color:#fff; box-shadow:inset 0 1px 0 rgba(255,255,255,.3), 0 6px 16px -6px rgba(52,199,89,.6); }
.play-cat-btn{ background:linear-gradient(180deg, var(--cat-light), var(--cat-color) 55%, var(--cat-dark)); color:#fff; box-shadow:inset 0 1px 0 rgba(255,255,255,.3), 0 6px 16px -6px var(--cat-line); }
.big-edit-btn{ background:linear-gradient(180deg, color-mix(in srgb, var(--green), white 18%), var(--green) 55%, color-mix(in srgb, var(--green), black 10%)); color:#fff; box-shadow:inset 0 1px 0 rgba(255,255,255,.3), 0 6px 16px -6px rgba(52,199,89,.6); }
/* ---- Percorso categorie (breadcrumb scorrevole in header-row2) ---- */
.cat-path{
    flex:1 1 auto; min-width:0; display:flex; align-items:center; gap:3px;
    overflow-x:auto; overflow-y:hidden; scrollbar-width:none; -webkit-overflow-scrolling:touch;
    scroll-behavior:auto; padding:2px 0; margin:0;
    -webkit-mask-image:linear-gradient(to right, transparent 0, #000 10px, #000 calc(100% - 4px), transparent 100%);
            mask-image:linear-gradient(to right, transparent 0, #000 10px, #000 calc(100% - 4px), transparent 100%);
}
.cat-path::-webkit-scrollbar{ display:none; }
.cat-path-home{ flex-shrink:0; display:flex; align-items:center; justify-content:center; width:26px; height:26px; border-radius:8px; color:var(--text-2); transition:opacity .15s var(--spring), transform .15s var(--spring); }
.cat-path-home svg{ width:15px; height:15px; }
.cat-path-home:active{ opacity:.5; transform:scale(.9); }
.cat-path-item{
    flex-shrink:0; white-space:nowrap; text-decoration:none; line-height:1;
    font-size:13.5px; font-weight:800; letter-spacing:-.01em;
    color:color-mix(in srgb, var(--cat-color) 52%, var(--text));
    padding:5px 4px; border-radius:8px;
    transition:opacity .15s var(--spring), transform .15s var(--spring);
}
.cat-path-item:active{ opacity:.5; transform:scale(.96); }
.cat-path-current{ color:color-mix(in srgb, var(--cat-color) 52%, var(--text)); background:var(--cat-soft); padding:5px 11px; border-radius:99px; }
.cat-path-sep{ flex-shrink:0; color:var(--text-3); font-size:13px; font-weight:600; padding:0 1px; -webkit-user-select:none; user-select:none; }

/* TOOLBAR-ROW — striscia con scroll orizzontale isolato (non influisce su header/body) */
.toolbar-row{ display:flex; align-items:center; gap:8px; padding:0 14px 12px; overflow-x:auto; overflow-y:hidden; scrollbar-width:none; -webkit-overflow-scrolling:touch; }
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
.tb-select-wrap::after{ content:''; position:absolute; right:9px; top:50%; width:6px; height:6px; pointer-events:none; border-right:1.6px solid var(--text-2); border-bottom:1.6px solid var(--text-2); transform:translateY(-70%) rotate(45deg); }
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

.count-line{ max-width:680px; margin:12px auto -4px; padding:0 18px; font-size:12.5px; font-weight:600; color:var(--text-2); font-variant-numeric:tabular-nums; }

/* SELECT TOOLBAR */
.select-toolbar{
    display:none; position:sticky; top:0; z-index:100; padding:calc(var(--safe-top) + 10px) 14px 12px;
    background:var(--header-glass); -webkit-backdrop-filter:saturate(1.8) blur(20px); backdrop-filter:saturate(1.8) blur(20px);
    border-bottom:.5px solid var(--hairline); animation:fadeDown .3s var(--spring);
}
@keyframes fadeDown{ from{ opacity:0; transform:translateY(-8px); } to{ opacity:1; transform:none; } }
body.select-active .app-header{ display:none; }
body.select-active .select-toolbar{ display:block; }
.st-row{ display:flex; align-items:center; gap:10px; }
.st-cancel, .st-all{ border:none; background:transparent; color:color-mix(in srgb, var(--cat-color) 52%, var(--text)); font-size:15.5px; font-weight:700; cursor:pointer; padding:4px 2px; }
.st-count{ flex:1; text-align:center; font-size:16px; font-weight:800; letter-spacing:-.01em; }
.st-hint{ margin-top:8px; font-size:13px; font-weight:700; color:var(--orange); text-align:center; display:none; }
.st-actions{ display:flex; gap:8px; margin-top:11px; }
.st-btn{ flex:1; display:flex; flex-direction:column; align-items:center; gap:3px; padding:9px 4px; border:none; border-radius:13px; cursor:pointer; background:var(--surface-2); color:var(--text); font-size:11px; font-weight:700; transition:transform .15s var(--spring), opacity .2s; }
.st-btn:active{ transform:scale(.94); }
.st-btn svg{ width:19px; height:19px; }
.st-btn.disabled{ opacity:.32; pointer-events:none; }
.st-btn.danger{ color:var(--red); }
.st-btn.confirm{ background:var(--green); color:#fff; }

/* LAYOUT */
.page{ max-width:680px; margin:0 auto; padding:18px 14px calc(40px + var(--safe-bottom)); }
@keyframes cardIn{ from{ opacity:0; transform:translateY(14px) scale(.985); } to{ opacity:1; transform:none; } }

/* SUBCATEGORIES */
.subcats{ margin-bottom:20px; }
.subcats-title{ font-size:12px; font-weight:800; letter-spacing:.06em; text-transform:uppercase; color:var(--text-2); margin:0 4px 10px; }
.subcats-grid{ display:grid; grid-template-columns:repeat(2,1fr); gap:10px; }
.subcat-tile{
    display:flex; align-items:center; gap:10px; padding:13px 12px; border-radius:16px; background:var(--surface);
    box-shadow:var(--shadow-card); border-left:3px solid var(--sc); animation:cardIn .5s var(--spring) both; transition:transform .16s var(--spring);
}
.subcat-tile:active{ transform:scale(.97); }
.subcat-ic{ width:34px; height:34px; border-radius:10px; flex-shrink:0; display:flex; align-items:center; justify-content:center; background:color-mix(in srgb, var(--sc) 18%, var(--surface)); color:var(--sc); }
.subcat-ic svg{ width:17px; height:17px; }
.subcat-info{ min-width:0; flex:1; }
.subcat-name{ font-size:14.5px; font-weight:700; color:var(--text); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.subcat-count{ font-size:11.5px; font-weight:600; color:var(--text-2); font-variant-numeric:tabular-nums; }
@media (max-width:380px){ .subcats-grid{ grid-template-columns:1fr; } }

/* WORD GROUP + CARD */
.word-group{ margin-bottom:14px; }
.eng-card{
    position:relative; background:var(--surface); border-radius:var(--r-card); overflow:hidden;
    box-shadow:var(--shadow-card); border-left:3px solid var(--row-color, var(--cat-color)); animation:cardIn .5s var(--spring) both;
}
.eng-card.is-learnt{ border-left-color:var(--green); background:linear-gradient(180deg, var(--green-soft), var(--surface) 62%); }
.eng-card.is-child{ margin:10px 0 0 22px; border-left-style:dashed; border-radius:18px; }
.eng-card.is-child::before{ content:""; position:absolute; left:-22px; top:-10px; width:22px; height:calc(50% + 10px); border-left:2px solid var(--hairline-2); border-bottom:2px solid var(--hairline-2); border-bottom-left-radius:12px; }
.card-fade-out{ animation:cardOut .38s var(--spring) forwards !important; }
@keyframes cardOut{ to{ opacity:0; transform:translateX(60px) scale(.9); max-height:0; margin:0; padding:0; } }

.swipe-actions{ position:absolute; top:0; right:0; bottom:0; display:none; align-items:stretch; z-index:1; visibility:hidden; }
.swipe-btn{ border:none; margin:0; padding:0; cursor:pointer; width:66px; display:flex; align-items:center; justify-content:center; color:#fff; }
.swipe-btn svg{ width:21px; height:21px; }
.swipe-edit{ background:var(--row-color, var(--cat-color)); } .swipe-delete{ background:var(--red); }
body.touch-device .swipe-actions{ display:flex; }
.swipe-content{ position:relative; z-index:2; background:var(--surface); will-change:transform; }
body.touch-device .swipe-content{ touch-action:pan-y; }
.eng-card.is-learnt .swipe-content{ background:transparent; }

.card-inner{ padding:14px 15px 16px; }
.card-head{ display:flex; align-items:center; gap:10px; margin-bottom:12px; min-height:30px; }
.select-circle{ display:none; width:24px; height:24px; border-radius:50%; flex-shrink:0; border:2px solid var(--hairline-2); background:var(--surface); align-items:center; justify-content:center; transition:all .2s var(--spring); }
.select-circle svg{ width:13px; height:13px; color:#fff; opacity:0; transform:scale(.4); transition:all .2s var(--spring); }
body.select-active .select-circle{ display:flex; }
.eng-card.selected .select-circle{ background:var(--cat-color); border-color:var(--cat-color); }
.eng-card.selected .select-circle svg{ opacity:1; transform:scale(1); }
.eng-card.join-father .select-circle{ background:var(--green); border-color:var(--green); border-radius:7px; }

/* learnt toggle */
.learnt-wrapper{ position:relative; width:30px; height:30px; flex-shrink:0; cursor:pointer; }
.learnt-wrapper input{ position:absolute; opacity:0; width:100%; height:100%; margin:0; cursor:pointer; }
.learnt-emoji, .learnt-check{ position:absolute; inset:0; display:flex; align-items:center; justify-content:center; transition:opacity .25s var(--spring), transform .35s var(--bounce); }
.learnt-emoji{ font-size:21px; filter:grayscale(.15); }
.learnt-check{ width:30px; height:30px; border-radius:50%; background:var(--green); opacity:0; transform:scale(0) rotate(-40deg); }
.learnt-check svg{ width:17px; height:17px; }
.learnt-wrapper input:checked ~ .learnt-emoji{ opacity:0; transform:scale(.4); }
.learnt-wrapper input:checked ~ .learnt-check{ opacity:1; transform:scale(1) rotate(0); }
.learnt-static{ width:30px; height:30px; flex-shrink:0; display:flex; align-items:center; justify-content:center; color:var(--text-3); font-size:19px; font-weight:800; }
.child-tag{ display:inline-flex; align-items:center; gap:4px; flex-shrink:0; font-size:11px; font-weight:800; letter-spacing:.02em; color:var(--orange); background:color-mix(in srgb, var(--orange) 15%, var(--surface)); padding:4px 9px; border-radius:99px; }
.child-tag svg{ width:12px; height:12px; }
.head-spacer{ flex:1; }
.card-kebab{ display:none; flex-shrink:0; width:30px; height:30px; border-radius:9px; border:none; cursor:pointer; background:var(--surface-2); color:var(--text-2); align-items:center; justify-content:center; transition:transform .15s var(--spring); }
.card-kebab svg{ width:16px; height:16px; }
.card-kebab:active{ transform:scale(.9); }
body:not(.touch-device):not(.select-active) .card-kebab{ display:flex; }
.sheet-action-item{ display:flex; align-items:center; gap:12px; width:100%; padding:13px 6px; border:none; background:transparent; cursor:pointer; font-family:inherit; font-size:16px; font-weight:600; color:var(--text); border-bottom:.5px solid var(--hairline); text-align:left; }
.sheet-action-item:last-child{ border-bottom:none; }
.sheet-action-item svg{ width:19px; height:19px; flex-shrink:0; color:var(--text-2); }
.sheet-action-item.danger{ color:var(--red); }
.sheet-action-item.danger svg{ color:var(--red); }
.badge-dup{ flex-shrink:0; display:inline-flex; align-items:center; gap:4px; font-size:11px; font-weight:800; color:var(--orange); background:color-mix(in srgb, var(--orange) 14%, var(--surface)); padding:4px 9px; border-radius:99px; }
.badge-dup svg{ width:12px; height:12px; }
.badge-cat{ flex-shrink:0; display:inline-flex; align-items:center; gap:5px; font-size:11.5px; font-weight:700; color:color-mix(in srgb, var(--row-color, var(--cat-color)) 52%, var(--text)); background:color-mix(in srgb, var(--row-color, var(--cat-color)) 13%, var(--surface)); padding:4px 10px; border-radius:99px; max-width:150px; }
.badge-cat .dot{ width:6px; height:6px; border-radius:50%; background:currentColor; flex-shrink:0; }
.badge-cat span{ white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }

/* BILINGUAL */
.eng-bilingual{ display:grid; grid-template-columns:1fr 1fr; align-items:stretch; border-radius:16px; overflow:hidden; background:var(--surface-2); box-shadow:inset 0 0 0 .5px var(--hairline); }
.eng-col{ padding:13px 14px; min-width:0; }
.eng-col.eng-en{ border-right:.5px solid var(--hairline); background:color-mix(in srgb, var(--row-color, var(--cat-color)) 6%, transparent); }
.eng-eyebrow{ display:flex; align-items:center; justify-content:space-between; gap:5px; font-size:10.5px; font-weight:800; letter-spacing:.07em; text-transform:uppercase; color:var(--text-3); margin-bottom:5px; }
.eng-eyebrow-label{ display:flex; align-items:center; gap:5px; min-width:0; }
.eng-en .eng-eyebrow{ color:color-mix(in srgb, var(--row-color, var(--cat-color)) 55%, var(--text)); }
.eng-speak-btn{ flex-shrink:0; width:22px; height:22px; border-radius:7px; border:none; cursor:pointer; background:color-mix(in srgb, var(--row-color, var(--cat-color)) 14%, var(--surface)); color:color-mix(in srgb, var(--row-color, var(--cat-color)) 55%, var(--text)); display:flex; align-items:center; justify-content:center; transition:transform .15s var(--spring), opacity .15s; }
.eng-speak-btn svg{ width:12px; height:12px; }
.eng-speak-btn:active{ transform:scale(.88); }
.eng-speak-btn.speaking{ animation:speakPulse 1s ease-in-out infinite; }
@keyframes speakPulse{ 0%,100%{ opacity:1; } 50%{ opacity:.5; } }
.eng-flag{ font-size:12px; }
.eng-word{ font-size:19px; font-weight:800; letter-spacing:-.02em; color:var(--text); line-height:1.2; word-break:break-word; }
.eng-it .eng-word{ font-weight:700; color:var(--text); }
.eng-word.empty{ color:var(--text-3); font-weight:600; font-size:15px; font-style:italic; }

/* MEDIA */
.eng-media{ margin-top:12px; border-radius:16px; overflow:hidden; background:var(--surface-2); cursor:zoom-in; box-shadow:inset 0 0 0 .5px var(--hairline); position:relative; }
.eng-media img{ display:block; width:100%; max-height:220px; object-fit:cover; }

/* BLOCKS */
.eng-block{ margin-top:14px; }
.eng-block-label{ display:flex; align-items:center; gap:8px; font-size:11px; font-weight:800; letter-spacing:.06em; text-transform:uppercase; color:var(--text-3); margin-bottom:6px; }
.eng-block-label::after{ content:""; flex:1; height:.5px; background:var(--hairline); }
.eng-block-label .l-ic{ display:flex; color:color-mix(in srgb, var(--row-color, var(--cat-color)) 55%, var(--text)); }
.eng-block-label .l-ic svg{ width:13px; height:13px; }
.eng-meaning-text{ font-size:15px; font-weight:500; color:var(--text); line-height:1.5; }
.eng-example-text{ font-size:14.5px; color:var(--text-2); line-height:1.55; font-style:italic; }
.eng-fill{ font-weight:800; padding:0 2px; color:color-mix(in srgb, var(--row-color, var(--cat-color)) 55%, var(--text)); }

/* EMPTY */
.load-more-btn{ width:100%; margin:4px 0 4px; padding:14px; background:var(--surface); color:color-mix(in srgb, var(--cat-color) 52%, var(--text)); border:none; border-radius:var(--r-card); font-size:15.5px; font-weight:800; cursor:pointer; box-shadow:var(--shadow-card), inset 0 0 0 .5px var(--hairline); transition:transform .15s var(--spring); text-align:center; }
.load-more-btn:active{ transform:scale(.97); }

.empty-state{ text-align:center; padding:64px 20px 30px; animation:cardIn .5s var(--spring) both; }
.empty-icon{ width:74px; height:74px; margin:0 auto 18px; border-radius:20px; background:color-mix(in srgb, var(--cat-color) 12%, var(--surface)); color:var(--cat-color); display:flex; align-items:center; justify-content:center; }
.empty-icon svg{ width:33px; height:33px; }
.empty-title{ font-size:19px; font-weight:800; margin-bottom:6px; }
.empty-sub{ font-size:14.5px; color:var(--text-2); max-width:280px; margin:0 auto; line-height:1.5; }

/* SHEET */
.modal-overlay{ display:none; position:fixed; inset:0; z-index:1000; justify-content:center; align-items:flex-end; background:rgba(0,0,0,.45); -webkit-backdrop-filter:blur(6px); backdrop-filter:blur(6px); }
.modal-overlay.closing{ animation:overlayOut .22s ease both; }
@keyframes overlayOut{ to{ opacity:0; } }
.sheet-panel{ position:relative; width:100%; max-width:600px; max-height:86vh; overflow-y:auto; -webkit-overflow-scrolling:touch; background:var(--surface); color:var(--text); border-radius:24px 24px 0 0; padding:26px 20px calc(22px + var(--safe-bottom)); box-shadow:0 -10px 50px rgba(0,0,0,.35); animation:sheetUp .38s var(--spring) both; }
.modal-overlay.closing .sheet-panel{ animation:sheetDown .24s ease both; }
@keyframes sheetUp{ from{ transform:translateY(60%); opacity:.4; } to{ transform:none; opacity:1; } }
@keyframes sheetDown{ to{ transform:translateY(60%); opacity:0; } }
.sheet-panel::before{ content:""; position:absolute; top:8px; left:50%; transform:translateX(-50%); width:38px; height:5px; border-radius:99px; background:var(--hairline-2); }
.sheet-title{ margin:6px 0 6px; font-size:19px; font-weight:800; letter-spacing:-.01em; text-align:center; }
.sheet-sub{ margin:0 0 16px; font-size:13.5px; color:var(--text-2); text-align:center; }
@media (min-width:640px){ .modal-overlay{ align-items:center; padding:20px; } .sheet-panel{ border-radius:24px; } }

.cat-pick-list{ display:flex; flex-direction:column; gap:6px; max-height:56vh; overflow-y:auto; margin-top:4px; }
.cat-pick-item{ display:flex; align-items:center; gap:10px; padding:12px 14px; border-radius:14px; cursor:pointer; background:var(--surface-2); border:none; width:100%; text-align:left; font-family:inherit; transition:transform .15s var(--spring); }
.cat-pick-item:active{ transform:scale(.98); }
.cat-pick-dot{ width:12px; height:12px; border-radius:50%; flex-shrink:0; }
.cat-pick-name{ font-size:15px; font-weight:700; color:var(--text); }

.btn{ width:100%; display:flex; align-items:center; justify-content:center; gap:7px; padding:14px 16px; border:none; border-radius:var(--r-btn); cursor:pointer; font-size:16px; font-weight:700; transition:transform .16s var(--spring), filter .2s; }
.btn:active{ transform:scale(.97); filter:brightness(.96); }
.btn-cancel{ background:var(--surface-2); color:var(--text); }
.btn-danger{ color:#fff; background:linear-gradient(180deg, color-mix(in srgb, var(--red), #fff 16%), var(--red) 52%, color-mix(in srgb, var(--red), #000 10%)); box-shadow:var(--btn-hl); }
.sheet-actions{ display:flex; gap:10px; margin-top:8px; }
.sheet-actions .btn{ flex:1; }
.confirm-icon{ width:56px; height:56px; margin:4px auto 14px; border-radius:16px; background:var(--red-soft); color:var(--red); display:flex; align-items:center; justify-content:center; }
.confirm-icon svg{ width:26px; height:26px; }
.confirm-text{ text-align:center; font-size:15px; color:var(--text-2); margin-bottom:18px; line-height:1.5; }
.confirm-text b{ color:var(--text); }

/* IMAGE ZOOM */
.zoom-overlay{ display:none; position:fixed; inset:0; z-index:3000; background:rgba(0,0,0,.92); align-items:center; justify-content:center; padding:20px; cursor:zoom-out; animation:zoomFade .25s ease; }
@keyframes zoomFade{ from{ opacity:0; } to{ opacity:1; } }
.zoom-overlay img{ max-width:100%; max-height:100%; border-radius:14px; box-shadow:0 20px 60px rgba(0,0,0,.6); animation:zoomIn .35s var(--spring); }
@keyframes zoomIn{ from{ transform:scale(.85); opacity:0; } to{ transform:scale(1); opacity:1; } }
.zoom-close{ position:absolute; top:calc(var(--safe-top) + 14px); right:16px; width:40px; height:40px; border-radius:50%; border:none; background:rgba(255,255,255,.16); color:#fff; cursor:pointer; display:flex; align-items:center; justify-content:center; }
.zoom-close svg{ width:20px; height:20px; }

/* TOAST + CONFETTI */
.toast{ position:fixed; top:calc(var(--safe-top) + 14px); left:50%; z-index:20000; display:flex; align-items:center; gap:8px; padding:12px 18px; border-radius:99px; background:var(--text); color:var(--bg); font-size:14px; font-weight:700; box-shadow:0 12px 30px rgba(0,0,0,.28); transform:translateX(-50%) translateY(-20px); opacity:0; transition:transform .4s var(--spring), opacity .3s; }
.toast.show{ transform:translateX(-50%) translateY(0); opacity:1; }
.toast svg{ width:16px; height:16px; flex-shrink:0; }
.confetti-piece{ position:fixed; z-index:19000; width:9px; height:9px; pointer-events:none; will-change:transform, opacity; }

@media (prefers-reduced-motion: reduce){ *, *::before, *::after{ animation-duration:.01ms !important; transition-duration:.01ms !important; } }
</style>
</head>
<body style="--cat-color:<?php echo $themeColor; ?>;">

<!-- HEADER normale -->
<div class="app-header" id="appHeader">
    <div class="header-top">
        <a href="categories.php" class="cat-pill" id="catPill">
            <span class="cat-pill-dot"></span>
            <span class="cat-pill-text">
                <span class="cat-pill-label">Categoria</span>
                <span class="cat-pill-name"><?php echo htmlspecialchars($currentCatName); ?></span>
            </span>
            <span class="cat-pill-count"><?php echo $wordCount; ?> <?php echo $wordCount == 1 ? 'parola' : 'parole'; ?></span>
        </a>
    </div>

    <div class="header-row2">
        <nav class="cat-path" id="catPath" aria-label="Percorso categorie">
            <a href="categories.php" class="cat-path-home" aria-label="Home — torna alle categorie" title="Torna alle categorie">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m3 10 9-7 9 7v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2Z"/></svg>
            </a>
            <span class="cat-path-sep" aria-hidden="true">&rsaquo;</span>
            <?php if ($categoryID == 0): ?>
            <span class="cat-path-item cat-path-current">Tutte le parole</span>
            <?php else: foreach ($breadcrumb as $bi => $bc): ?>
                <?php if ($bi === count($breadcrumb) - 1): ?>
                <span class="cat-path-item cat-path-current"><?php echo htmlspecialchars($bc['name']); ?></span>
                <?php else: ?>
                <a class="cat-path-item" href="visualizzazione.php?category_id=<?php echo $bc['id']; ?>"><?php echo htmlspecialchars($bc['name']); ?></a>
                <span class="cat-path-sep" aria-hidden="true">&rsaquo;</span>
                <?php endif; ?>
            <?php endforeach; endif; ?>
        </nav>

        <a href="inserimento.php?category_id=<?php echo $categoryID; ?>" class="icon-btn quick-add-btn" aria-label="Aggiungi parola" title="Aggiungi parola in questa categoria">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
        </a>
        <?php if ($categoryID != 0): ?>
        <a href="categories_game.php?preselect_category=<?php echo $categoryID; ?>" class="icon-btn play-cat-btn" aria-label="Gioca con questa categoria" title="Gioca con questa categoria">
            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M8 5.14v13.72a1 1 0 0 0 1.53.85l11-6.86a1 1 0 0 0 0-1.7l-11-6.86A1 1 0 0 0 8 5.14Z"/></svg>
        </a>
        <a href="big_edit.php?category_id=<?php echo $categoryID; ?>" class="icon-btn big-edit-btn" aria-label="Big Edit" title="Big Edit">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
        </a>
        <?php endif; ?>
    </div>

    <div class="toolbar-row" id="normalToolbar">
        <div class="search-wrap">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
            <input id="filterText" class="search-input" type="text" placeholder="Cerca parola, significato…" oninput="visibleLimit=30; filterList()" autocomplete="off">
        </div>
        <div class="tb-select-wrap">
            <select id="filterSort" class="tb-select" onchange="changeSort()">
                <option value="id_desc" <?php echo $sort == 'id_desc' ? 'selected' : ''; ?>>Recenti</option>
                <option value="id_asc" <?php echo $sort == 'id_asc' ? 'selected' : ''; ?>>Meno recenti</option>
                <option value="en_asc" <?php echo $sort == 'en_asc' ? 'selected' : ''; ?>>A → Z (EN)</option>
                <option value="it_asc" <?php echo $sort == 'it_asc' ? 'selected' : ''; ?>>A → Z (IT)</option>
            </select>
        </div>
        <div class="tb-select-wrap">
            <select id="filterStatus" class="tb-select" onchange="visibleLimit=30; filterList()">
                <option value="all">Tutte</option>
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
</div>

<!-- SELECT TOOLBAR -->
<div class="select-toolbar" id="selectToolbar">
    <div class="st-row">
        <button type="button" class="st-cancel" onclick="exitSelectMode()">Annulla</button>
        <div class="st-count" id="stCount">0 selezionate</div>
        <button type="button" class="st-all" onclick="toggleSelectAll()">Tutte</button>
    </div>
    <div class="st-hint" id="stHint">Tocca la scheda principale del gruppo</div>
    <div class="st-actions" id="stActions">
        <button type="button" class="st-btn" id="stMove" onclick="onBulkAction('move')">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 9l-3 3 3 3M19 9l3 3-3 3M2 12h20"/></svg>Sposta
        </button>
        <button type="button" class="st-btn" id="stJoin" onclick="onBulkAction('join')">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 6h9a4 4 0 0 1 0 8H9M16 18H7a4 4 0 0 1 0-8h1"/></svg>Unisci
        </button>
        <button type="button" class="st-btn" id="stUnjoin" onclick="onBulkAction('unjoin')">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 7h2a4 4 0 0 1 0 8h-1M9 17H7A4 4 0 0 1 7 9h1M8 12h4M2 2l20 20"/></svg>Separa
        </button>
        <button type="button" class="st-btn danger" id="stDelete" onclick="onBulkAction('delete')">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0-1 14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2L4 6"/></svg>Elimina
        </button>
    </div>
</div>

<?php include 'sidebar.html'; ?>

<div class="count-line" id="countLine"></div>

<div class="page">
    <?php if (!empty($subcats)): ?>
    <div class="subcats" id="subcatsBlock">
        <div class="subcats-title">Sottocategorie</div>
        <div class="subcats-grid">
            <?php foreach ($subcats as $sc): ?>
            <a href="visualizzazione.php?category_id=<?php echo $sc['id']; ?>" class="subcat-tile" style="--sc:<?php echo $sc['color']; ?>;">
                <span class="subcat-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z"/></svg></span>
                <span class="subcat-info">
                    <span class="subcat-name"><?php echo htmlspecialchars($sc['name']); ?></span>
                    <span class="subcat-count"><?php echo $sc['count']; ?> <?php echo $sc['count'] == 1 ? 'parola' : 'parole'; ?></span>
                </span>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <div id="wordsWrap">
    <?php if ($wordCount > 0): $ci = 0; foreach ($images_data as $row):
        $rowColor = (!empty($row['category_color']) && preg_match('/^#[0-9A-Fa-f]{6}$/', $row['category_color'])) ? $row['category_color'] : '#8E8E93';
        $isLearnt = ($row['is_learnt'] == 1);
        $enKey = strtolower(trim($row['description_en']));
        $isDup = (isset($global_en_counts[$enKey]) && $global_en_counts[$enKey] > 1);
        $delay = min($ci * 35, 350); $ci++;
        $catName = $row['category_name'] ? htmlspecialchars($row['category_name']) : 'Nessuna';
        $enWord = trim($row['description_en']) !== '' ? htmlspecialchars($row['description_en'], ENT_QUOTES) : '';
        $itWord = trim($row['description_it']) !== '' ? htmlspecialchars($row['description_it'], ENT_QUOTES) : '';
    ?>
        <div class="word-group">
            <div class="eng-card father-card <?php echo $isLearnt ? 'is-learnt' : ''; ?>"
                 id="card-img-<?php echo $row['id']; ?>" data-kind="father" data-id="<?php echo $row['id']; ?>"
                 data-learnt="<?php echo $isLearnt ? '1' : '0'; ?>" data-duplicate="<?php echo $isDup ? 'true' : 'false'; ?>"
                 data-search="<?php echo eng_search($row); ?>" style="--row-color:<?php echo $rowColor; ?>; animation-delay:<?php echo $delay; ?>ms;">
                <div class="swipe-actions">
                    <a href="modifica_immagine.php?image_id=<?php echo $row['id']; ?>&previous_catid=<?php echo $categoryID; ?>" class="swipe-btn swipe-edit" aria-label="Modifica">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                    </a>
                    <button type="button" class="swipe-btn swipe-delete" aria-label="Elimina" onclick="askDeleteSingle('father', <?php echo $row['id']; ?>)">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0-1 14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2L4 6"/></svg>
                    </button>
                </div>
                <div class="swipe-content" onclick="onCardTap(event, this)">
                    <div class="card-inner">
                        <div class="card-head">
                            <span class="select-circle"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg></span>
                            <?php if ($current_user_id == 3): ?>
                                <span class="learnt-static">–</span>
                            <?php else: ?>
                                <label class="learnt-wrapper" onclick="event.stopPropagation();">
                                    <input type="checkbox" <?php echo $isLearnt ? 'checked' : ''; ?> onclick="toggleLearnt(<?php echo $row['id']; ?>, this)">
                                    <span class="learnt-emoji">🧐</span>
                                    <span class="learnt-check"><svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3.4" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg></span>
                                </label>
                            <?php endif; ?>
                            <span class="head-spacer"></span>
                            <?php if ($isDup): ?>
                            <span class="badge-dup"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/></svg>Duplicato</span>
                            <?php endif; ?>
                            <?php if ($categoryID == 0): ?>
                            <span class="badge-cat"><span class="dot"></span><span><?php echo $catName; ?></span></span>
                            <?php endif; ?>
                            <button type="button" class="card-kebab" aria-label="Azioni" onclick="event.stopPropagation(); apriAzioniParola('father', <?php echo $row['id']; ?>, 'modifica_immagine.php?image_id=<?php echo $row['id']; ?>&previous_catid=<?php echo $categoryID; ?>');">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><circle cx="12" cy="5" r="1.4" fill="currentColor" stroke="none"/><circle cx="12" cy="12" r="1.4" fill="currentColor" stroke="none"/><circle cx="12" cy="19" r="1.4" fill="currentColor" stroke="none"/></svg>
                            </button>
                        </div>

                        <div class="eng-bilingual">
                            <div class="eng-col eng-en">
                                <div class="eng-eyebrow"><span class="eng-eyebrow-label"><span class="eng-flag">🇬🇧</span> English</span><?php if ($enWord !== ''): ?><button type="button" class="eng-speak-btn" aria-label="Ascolta in inglese" onclick="event.stopPropagation(); speakWord(this, <?php echo htmlspecialchars(json_encode($row['description_en']), ENT_QUOTES); ?>, 'en-GB');"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 5 6 9H2v6h4l5 4z"/><path d="M15.5 8.5a5 5 0 0 1 0 7"/></svg></button><?php endif; ?></div>
                                <div class="eng-word <?php echo $enWord === '' ? 'empty' : ''; ?>"><?php echo $enWord !== '' ? $enWord : '—'; ?></div>
                            </div>
                            <div class="eng-col eng-it">
                                <div class="eng-eyebrow"><span class="eng-eyebrow-label"><span class="eng-flag">🇮🇹</span> Italiano</span><?php if ($itWord !== ''): ?><button type="button" class="eng-speak-btn" aria-label="Ascolta in italiano" onclick="event.stopPropagation(); speakWord(this, <?php echo htmlspecialchars(json_encode($row['description_it']), ENT_QUOTES); ?>, 'it-IT');"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 5 6 9H2v6h4l5 4z"/><path d="M15.5 8.5a5 5 0 0 1 0 7"/></svg></button><?php endif; ?></div>
                                <div class="eng-word <?php echo $itWord === '' ? 'empty' : ''; ?>"><?php echo $itWord !== '' ? $itWord : '—'; ?></div>
                            </div>
                        </div>

                        <?php if (!empty($row['image'])): ?>
                        <div class="eng-media" onclick="event.stopPropagation(); openZoom('<?php echo IMAGE_PATH_VIEW . '/' . htmlspecialchars($row['image'], ENT_QUOTES); ?>')">
                            <img src="<?php echo IMAGE_PATH_VIEW . '/' . htmlspecialchars($row['image'], ENT_QUOTES); ?>" alt="" loading="lazy">
                        </div>
                        <?php endif; ?>

                        <?php if (trim($row['meaning']) !== ''): ?>
                        <div class="eng-block">
                            <div class="eng-block-label"><span class="l-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 16v-4M12 8h.01"/></svg></span>Significato</div>
                            <div class="eng-meaning-text"><?php echo eng_text($row['meaning']); ?></div>
                        </div>
                        <?php endif; ?>

                        <?php if (trim($row['example']) !== ''): ?>
                        <div class="eng-block">
                            <div class="eng-block-label"><span class="l-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 5h16M4 12h16M4 19h10"/></svg></span>Esempio</div>
                            <div class="eng-example-text"><?php echo eng_example_text($row['example'], $row['description_en']); ?></div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php if (!empty($row['children'])): foreach ($row['children'] as $child):
                $cEn = trim($child['description_en']) !== '' ? htmlspecialchars($child['description_en'], ENT_QUOTES) : '';
                $cIt = trim($child['description_it']) !== '' ? htmlspecialchars($child['description_it'], ENT_QUOTES) : '';
            ?>
            <div class="eng-card is-child" id="card-child-<?php echo $child['id_child']; ?>" data-kind="child" data-id="<?php echo $child['id_child']; ?>"
                 data-learnt="0" data-duplicate="false" data-search="<?php echo eng_search($child); ?>" style="--row-color:<?php echo $rowColor; ?>;">
                <div class="swipe-actions">
                    <a href="modifica_immagine.php?child_id=<?php echo $child['id_child']; ?>&previous_catid=<?php echo (int)$row['category_id']; ?>" class="swipe-btn swipe-edit" aria-label="Modifica">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                    </a>
                    <button type="button" class="swipe-btn swipe-delete" aria-label="Elimina" onclick="askDeleteSingle('child', <?php echo $child['id_child']; ?>)">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0-1 14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2L4 6"/></svg>
                    </button>
                </div>
                <div class="swipe-content" onclick="onCardTap(event, this)">
                    <div class="card-inner">
                        <div class="card-head">
                            <span class="select-circle"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg></span>
                            <span class="child-tag"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M9 4v10a4 4 0 0 0 4 4h7M20 18l-4 4M20 18l-4-4"/></svg>Collegata</span>
                            <span class="head-spacer"></span>
                            <button type="button" class="card-kebab" aria-label="Azioni" onclick="event.stopPropagation(); apriAzioniParola('child', <?php echo $child['id_child']; ?>, 'modifica_immagine.php?child_id=<?php echo $child['id_child']; ?>&previous_catid=<?php echo (int)$row['category_id']; ?>');">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><circle cx="12" cy="5" r="1.4" fill="currentColor" stroke="none"/><circle cx="12" cy="12" r="1.4" fill="currentColor" stroke="none"/><circle cx="12" cy="19" r="1.4" fill="currentColor" stroke="none"/></svg>
                            </button>
                        </div>
                        <div class="eng-bilingual">
                            <div class="eng-col eng-en">
                                <div class="eng-eyebrow"><span class="eng-eyebrow-label"><span class="eng-flag">🇬🇧</span> English</span><?php if ($cEn !== ''): ?><button type="button" class="eng-speak-btn" aria-label="Ascolta in inglese" onclick="event.stopPropagation(); speakWord(this, <?php echo htmlspecialchars(json_encode($child['description_en']), ENT_QUOTES); ?>, 'en-GB');"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 5 6 9H2v6h4l5 4z"/><path d="M15.5 8.5a5 5 0 0 1 0 7"/></svg></button><?php endif; ?></div>
                                <div class="eng-word <?php echo $cEn === '' ? 'empty' : ''; ?>"><?php echo $cEn !== '' ? $cEn : '—'; ?></div>
                            </div>
                            <div class="eng-col eng-it">
                                <div class="eng-eyebrow"><span class="eng-eyebrow-label"><span class="eng-flag">🇮🇹</span> Italiano</span><?php if ($cIt !== ''): ?><button type="button" class="eng-speak-btn" aria-label="Ascolta in italiano" onclick="event.stopPropagation(); speakWord(this, <?php echo htmlspecialchars(json_encode($child['description_it']), ENT_QUOTES); ?>, 'it-IT');"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 5 6 9H2v6h4l5 4z"/><path d="M15.5 8.5a5 5 0 0 1 0 7"/></svg></button><?php endif; ?></div>
                                <div class="eng-word <?php echo $cIt === '' ? 'empty' : ''; ?>"><?php echo $cIt !== '' ? $cIt : '—'; ?></div>
                            </div>
                        </div>
                        <?php if (!empty($child['image'])): ?>
                        <div class="eng-media" onclick="event.stopPropagation(); openZoom('<?php echo IMAGE_PATH_VIEW . '/' . htmlspecialchars($child['image'], ENT_QUOTES); ?>')">
                            <img src="<?php echo IMAGE_PATH_VIEW . '/' . htmlspecialchars($child['image'], ENT_QUOTES); ?>" alt="" loading="lazy">
                        </div>
                        <?php endif; ?>
                        <?php if (trim($child['meaning']) !== ''): ?>
                        <div class="eng-block">
                            <div class="eng-block-label"><span class="l-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 16v-4M12 8h.01"/></svg></span>Significato</div>
                            <div class="eng-meaning-text"><?php echo eng_text($child['meaning']); ?></div>
                        </div>
                        <?php endif; ?>
                        <?php if (trim($child['example']) !== ''): ?>
                        <div class="eng-block">
                            <div class="eng-block-label"><span class="l-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 5h16M4 12h16M4 19h10"/></svg></span>Esempio</div>
                            <div class="eng-example-text"><?php echo eng_example_text($child['example'], $child['description_en']); ?></div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; endif; ?>
        </div>
    <?php endforeach; endif; ?>
    </div>

    <?php if ($categoryID == 0 && $wordCount > 30): ?>
    <button type="button" id="loadMoreBtn" class="load-more-btn" style="display:none;" onclick="loadMoreCards()">Carica altre 30 parole</button>
    <?php endif; ?>

    <div class="empty-state" id="emptyReal" style="<?php echo $wordCount > 0 ? 'display:none;' : ''; ?>">
        <div class="empty-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2Z"/></svg></div>
        <div class="empty-title">Nessuna parola</div>
        <div class="empty-sub">Non ci sono parole in questa sezione. Aggiungine una con il pulsante +.</div>
    </div>

    <div class="empty-state" id="emptyFilter" style="display:none;">
        <div class="empty-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg></div>
        <div class="empty-title">Nessun risultato</div>
        <div class="empty-sub">Prova a cambiare la ricerca o i filtri.</div>
    </div>
</div>

<!-- SHEET: Azioni parola (fallback desktop) -->
<div id="sheetWordActions" class="modal-overlay">
    <div class="sheet-panel">
        <h3 class="sheet-title">Azioni</h3>
        <div>
            <a href="#" id="waEditLink" class="sheet-action-item">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>Modifica
            </a>
            <button type="button" class="sheet-action-item danger" id="waDeleteBtn">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0-1 14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2L4 6"/></svg>Elimina
            </button>
        </div>
    </div>
</div>

<!-- SHEET: Sposta -->
<div id="sheetMove" class="modal-overlay">
    <div class="sheet-panel">
        <h3 class="sheet-title">Sposta in…</h3>
        <p class="sheet-sub">Scegli la categoria di destinazione.</p>
        <div class="cat-pick-list">
            <?php foreach ($cat_map as $c):
                $col = (!empty($c['color']) && preg_match('/^#[0-9A-Fa-f]{6}$/', $c['color'])) ? $c['color'] : '#8E8E93'; ?>
            <button type="button" class="cat-pick-item" onclick="doMove(<?php echo (int)$c['id']; ?>)">
                <span class="cat-pick-dot" style="background:<?php echo $col; ?>"></span>
                <span class="cat-pick-name"><?php echo htmlspecialchars($c['name']); ?></span>
            </button>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- SHEET: Conferma eliminazione -->
<div id="sheetDelete" class="modal-overlay">
    <div class="sheet-panel">
        <div class="confirm-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0-1 14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2L4 6"/></svg></div>
        <h3 class="sheet-title">Eliminare?</h3>
        <p class="confirm-text" id="deleteText">Questa operazione non può essere annullata.</p>
        <div class="sheet-actions">
            <button type="button" class="btn btn-cancel" onclick="chiudiModals()">Annulla</button>
            <button type="button" class="btn btn-danger" id="deleteConfirmBtn">Elimina</button>
        </div>
    </div>
</div>

<!-- ZOOM -->
<div class="zoom-overlay" id="zoomOverlay" onclick="closeZoom()">
    <button type="button" class="zoom-close" onclick="closeZoom()" aria-label="Chiudi"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg></button>
    <img id="zoomImg" src="" alt="">
</div>

<!-- FORM azioni di massa -->
<form id="bulkForm" method="POST" style="display:none;">
    <input type="hidden" name="bulk_action" id="hiddenBulkAction">
    <input type="hidden" name="target_category" id="hiddenTargetCategory">
    <input type="hidden" name="father_id" id="hiddenFatherId">
    <div id="bulkInputs"></div>
</form>

<script>
if (window.matchMedia("(pointer:coarse)").matches) { document.body.classList.add("touch-device"); }
var CURRENT_USER = <?php echo (int)$current_user_id; ?>;
var IS_ANON = CURRENT_USER === 3;

/* ---------- Header height -> CSS var ---------- */
function syncHeaderHeight(){
    var h = document.body.classList.contains('select-active') ? document.getElementById('selectToolbar') : document.getElementById('appHeader');
    if (h) document.documentElement.style.setProperty('--header-h', h.offsetHeight + 'px');
}
window.addEventListener('load', syncHeaderHeight);
window.addEventListener('resize', syncHeaderHeight);

/* ---------- Navigation ---------- */
function changeSort(){ var v=document.getElementById('filterSort').value; var p=new URLSearchParams(window.location.search); p.set('sort', v); window.location.search=p.toString(); }

/* ---------- Sheets ---------- */
function openSheet(el){ el.style.display='flex'; el.classList.remove('closing'); }
function closeSheet(el){ el.classList.add('closing'); setTimeout(function(){ el.style.display='none'; el.classList.remove('closing'); }, 240); }
function chiudiModals(){ document.querySelectorAll('.modal-overlay').forEach(function(ov){ if (ov.style.display==='flex') closeSheet(ov); }); }
document.querySelectorAll('.modal-overlay').forEach(function(ov){ ov.addEventListener('click', function(e){ if (e.target===ov) closeSheet(ov); }); });

/* ---------- Toast + confetti ---------- */
function showToast(text, icon){
    var t=document.createElement('div'); t.className='toast';
    var svg = icon==='trash'
        ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>'
        : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>';
    t.innerHTML=svg+'<span>'+text+'</span>';
    document.body.appendChild(t);
    requestAnimationFrame(function(){ t.classList.add('show'); });
    setTimeout(function(){ t.classList.remove('show'); setTimeout(function(){ t.remove(); }, 320); }, 2100);
}
function miniConfetti(x, y){
    var colors=['#30D158','#34C759','#FFD60A','#0A84FF','#ffffff'];
    for (var i=0;i<12;i++){
        var p=document.createElement('div'); p.className='confetti-piece';
        p.style.background=colors[i%colors.length]; p.style.left=x+'px'; p.style.top=y+'px';
        if (i%2===0) p.style.borderRadius='50%';
        document.body.appendChild(p);
        var ang=Math.random()*Math.PI*2, dist=44+Math.random()*46;
        var dx=Math.cos(ang)*dist, dy=Math.sin(ang)*dist-24;
        p.animate([{transform:'translate(0,0) rotate(0) scale(1)', opacity:1},
                   {transform:'translate('+dx+'px,'+dy+'px) rotate('+(Math.random()*540)+'deg) scale(.3)', opacity:0}],
                  {duration:640+Math.random()*260, easing:'cubic-bezier(.15,.6,.3,1)'}).onfinish=function(){ this.effect.target.remove(); };
    }
}

/* ---------- Image zoom ---------- */
function openZoom(src){ document.getElementById('zoomImg').src=src; document.getElementById('zoomOverlay').style.display='flex'; }
function closeZoom(){ var z=document.getElementById('zoomOverlay'); z.style.display='none'; document.getElementById('zoomImg').src=''; }
document.addEventListener('keydown', function(e){ if (e.key==='Escape'){ closeZoom(); chiudiModals(); } });

/* ---------- Pronuncia (TTS): carica le voci, sceglie la migliore e legge ---------- */
var ttsVoices = [];
function loadTtsVoices(){
    if ('speechSynthesis' in window) { 
        ttsVoices = window.speechSynthesis.getVoices() || []; 
    }
}
loadTtsVoices();
if ('speechSynthesis' in window && window.speechSynthesis.onvoiceschanged !== undefined) { 
    window.speechSynthesis.onvoiceschanged = loadTtsVoices; 
}

function pickTtsVoice(lang){
    if (!ttsVoices.length) loadTtsVoices();
    if (!ttsVoices.length) return null;

    var prefix = lang.split('-')[0].toLowerCase(); // 'en' o 'it'
    
    // 1. Filtriamo in modo rigoroso: SOLO voci della lingua esatta e NIENTE Eloquence
    var validVoices = ttsVoices.filter(function(v){
        if (!v.lang) return false;
        var vLang = v.lang.replace('_', '-').toLowerCase();
        var name = v.name.toLowerCase();
        var uri = (v.voiceURI || '').toLowerCase();
        
        // Deve matchare la lingua richiesta
        if (vLang.indexOf(prefix) !== 0) return false;
        
        // Escludiamo categoricamente Eloquence e i nomi dei sintetizzatori robotici
        if (name.indexOf('eloquence') !== -1 || uri.indexOf('eloquence') !== -1) return false;
        var badNames = ['reed', 'shelley', 'sandy', 'rocko', 'flo', 'eddy', 'grandma', 'grandpa'];
        if (badNames.indexOf(name) !== -1) return false;
        
        return true;
    });

    if (!validVoices.length) return null;

    // 2. Sistema a punti intelligente
    validVoices.sort(function(a, b) {
        var scoreA = 0, scoreB = 0;
        var nA = a.name.toLowerCase();
        var nB = b.name.toLowerCase();
        
        // IL TRUCCO: Il "Default" vale 100 punti per iPhone/Android, ma vale solo 10 su PC (Microsoft)
        if (a.default) { scoreA += (nA.indexOf('microsoft') !== -1) ? 10 : 100; }
        if (b.default) { scoreB += (nB.indexOf('microsoft') !== -1) ? 10 : 100; }
        
        // Punto per la lingua esatta
        var langA = a.lang.replace('_', '-').toLowerCase();
        var langB = b.lang.replace('_', '-').toLowerCase();
        if (langA === lang.toLowerCase()) scoreA += 50;
        if (langB === lang.toLowerCase()) scoreB += 50;
        
        // Bonus per le voci di alta qualità (Google, Premium, Online)
        if (nA.indexOf('google') !== -1) scoreA += 40;
        if (nB.indexOf('google') !== -1) scoreB += 40;
        
        if (nA.indexOf('premium') !== -1 || nA.indexOf('enhanced') !== -1 || nA.indexOf('natural') !== -1 || nA.indexOf('online') !== -1) scoreA += 30;
        if (nB.indexOf('premium') !== -1 || nB.indexOf('enhanced') !== -1 || nB.indexOf('natural') !== -1 || nB.indexOf('online') !== -1) scoreB += 30;
        
        return scoreB - scoreA;
    });

    return validVoices[0]; // La vincitrice
}

function speakWord(btn, text, lang){
    if (!text || !('speechSynthesis' in window)) return;
    try { window.speechSynthesis.cancel(); } catch(e){}
    
    if (!ttsVoices.length) loadTtsVoices();
    var voice = pickTtsVoice(lang);
    
    var u1 = new SpeechSynthesisUtterance(text);
    u1.lang = lang; 
    u1.rate = 1; 
    if (voice) u1.voice = voice;
    
    var u2 = new SpeechSynthesisUtterance(text);
    u2.lang = lang; 
    u2.rate = 0.8; 
    if (voice) u2.voice = voice;
    
    if (btn) btn.classList.add('speaking');
    u2.onend = function(){ if (btn) btn.classList.remove('speaking'); };
    u2.onerror = u2.onend;
    
    window.speechSynthesis.speak(u1);
    window.speechSynthesis.speak(u2);
}

/* ---------- Learnt toggle ---------- */
function toggleLearnt(id, checkbox){
    var status = checkbox.checked ? 1 : 0;
    var card = document.getElementById('card-img-'+id);
    if (status===1 && card){
        var r=checkbox.getBoundingClientRect(); miniConfetti(r.left+r.width/2, r.top+r.height/2);
        if (navigator.vibrate) navigator.vibrate(12);
    }
    if (card){ card.classList.toggle('is-learnt', status===1); card.dataset.learnt = String(status); }
    var xhr=new XMLHttpRequest();
    xhr.open("POST","update_learnt.php",true);
    xhr.setRequestHeader("Content-Type","application/x-www-form-urlencoded");
    xhr.onreadystatechange=function(){ if (this.readyState===4){ updateCounter(); } };
    xhr.send("id="+id+"&status="+status+"&user_id="+CURRENT_USER+"&type=en");
}

/* ---------- Filtri + paginazione (30 alla volta per "Tutte le parole") ---------- */
var visibleLimit = 30;
function loadMoreCards(){
    visibleLimit += 30;
    filterList();
    if (navigator.vibrate) navigator.vibrate(10);
}
function filterList(){
    var status=document.getElementById('filterStatus').value;
    var text=document.getElementById('filterText').value.toLowerCase().trim();
    var groups=document.querySelectorAll('#wordsWrap .word-group');
    var isTutteLeParole = <?php echo $categoryID == 0 ? 'true' : 'false'; ?>;
    var visible=0, matchedGroups=0;
    groups.forEach(function(g){
        var cards=g.querySelectorAll('.eng-card'); var anyVisible=false;
        var cardResults=[];
        cards.forEach(function(card){
            if (selectMode && card.classList.contains('selected')){ cardResults.push([card,true]); anyVisible=true; return; }
            var isLearnt=card.dataset.learnt==='1';
            var isDup=card.dataset.duplicate==='true';
            var okStatus = status==='all' || (status==='learnt'&&isLearnt) || (status==='not_learnt'&&!isLearnt) || (status==='duplicates'&&isDup);
            var okText = !text || (card.dataset.search||'').indexOf(text)!==-1;
            var ok = okStatus && okText;
            cardResults.push([card, ok]);
            if (ok) anyVisible=true;
        });
        if (!anyVisible){ g.style.display='none'; return; }
        matchedGroups++;
        if (isTutteLeParole && matchedGroups > visibleLimit){ g.style.display='none'; return; }
        g.style.display='';
        cardResults.forEach(function(pair){ pair[0].style.display = pair[1] ? '' : 'none'; if (pair[1]) visible++; });
    });
    var real=document.getElementById('emptyReal'), filt=document.getElementById('emptyFilter');
    if (filt){ filt.style.display = (visible===0 && <?php echo $wordCount; ?> > 0) ? '' : 'none'; }
    var sb=document.getElementById('subcatsBlock'); if (sb) sb.style.display = (selectMode || text || status!=='all') ? 'none' : '';
    var lm=document.getElementById('loadMoreBtn');
    if (lm) lm.style.display = (isTutteLeParole && matchedGroups > visibleLimit) ? 'block' : 'none';
    updateCounter(visible);
}
function updateCounter(forced){
    var el=document.getElementById('countLine'); if (!el) return;
    if (selectMode){ el.style.display='none'; return; }
    el.style.display='';
    var n = (typeof forced==='number') ? forced : document.querySelectorAll('#wordsWrap .eng-card').length;
    <?php if ($wordCount == 0): ?> el.textContent=''; return; <?php endif; ?>
    el.textContent = n + (n===1 ? ' parola' : ' parole');
}

/* ---------- Select mode ---------- */
var selectMode=false, selected={father:new Set(), child:new Set()}, joinMode=false, joinFatherId=null;
function enterSelectMode(){
    selectMode=true; document.body.classList.add('select-active');
    selected.father.clear(); selected.child.clear();
    chiudiTuttiSwipe(); syncHeaderHeight(); refreshSelectUI(); filterList();
}
function exitSelectMode(){
    selectMode=false; joinMode=false; joinFatherId=null;
    document.body.classList.remove('select-active');
    document.querySelectorAll('.eng-card.selected, .eng-card.join-father').forEach(function(c){ c.classList.remove('selected','join-father'); });
    document.getElementById('stHint').style.display='none';
    syncHeaderHeight(); filterList();
}
function onCardTap(e, contentEl){
    if (contentEl.classList.contains('swiped-open')){ chiudiTuttiSwipe(); return; }
    if (document.querySelector('.swipe-content.swiped-open')){ chiudiTuttiSwipe(); return; }
    if (!selectMode) return;
    var card=contentEl.closest('.eng-card');
    if (joinMode){
        if (card.dataset.kind!=='father' || !card.classList.contains('selected')) return;
        document.querySelectorAll('.eng-card.join-father').forEach(function(c){ c.classList.remove('join-father'); });
        card.classList.add('join-father'); joinFatherId=card.dataset.id; return;
    }
    var kind=card.dataset.kind, id=card.dataset.id;
    if (card.classList.contains('selected')){ card.classList.remove('selected'); selected[kind].delete(id); }
    else { card.classList.add('selected'); selected[kind].add(id); }
    refreshSelectUI();
}
function toggleSelectAll(){
    var cards=Array.prototype.filter.call(document.querySelectorAll('#wordsWrap .eng-card'), function(c){ return c.offsetParent!==null; });
    var allSel=cards.every(function(c){ return c.classList.contains('selected'); });
    cards.forEach(function(c){
        var kind=c.dataset.kind, id=c.dataset.id;
        if (allSel){ c.classList.remove('selected'); selected[kind].delete(id); }
        else { c.classList.add('selected'); selected[kind].add(id); }
    });
    refreshSelectUI();
}
function refreshSelectUI(){
    var nf=selected.father.size, nc=selected.child.size, tot=nf+nc;
    document.getElementById('stCount').textContent = tot+(tot===1?' selezionata':' selezionate');
    setDisabled('stMove', nf===0 || nc>0);
    setDisabled('stJoin', nf<2 || nc>0);
    setDisabled('stUnjoin', nc===0 || nf>0);
    setDisabled('stDelete', tot===0);
}
function setDisabled(id, cond){ document.getElementById(id).classList.toggle('disabled', cond); }

/* ---------- Bulk actions ---------- */
function buildInputs(){
    var box=document.getElementById('bulkInputs'); box.innerHTML='';
    selected.father.forEach(function(id){ box.insertAdjacentHTML('beforeend', "<input type='hidden' name='selected_ids[]' value='"+id+"'>"); });
    selected.child.forEach(function(id){ box.insertAdjacentHTML('beforeend', "<input type='hidden' name='selected_child_ids[]' value='"+id+"'>"); });
}
function submitBulk(action){ document.getElementById('hiddenBulkAction').value=action; buildInputs(); document.getElementById('bulkForm').submit(); }
function onBulkAction(action){
    if (document.getElementById('st'+action.charAt(0).toUpperCase()+action.slice(1)).classList.contains('disabled')) return;
    if (action==='move'){ openSheet(document.getElementById('sheetMove')); }
    else if (action==='delete'){
        var tot=selected.father.size+selected.child.size;
        document.getElementById('deleteText').innerHTML='Stai per eliminare <b>'+tot+(tot===1?' parola':' parole')+'</b>. L\'operazione non può essere annullata.';
        document.getElementById('deleteConfirmBtn').onclick=function(){ submitBulk('delete'); };
        openSheet(document.getElementById('sheetDelete'));
    }
    else if (action==='unjoin'){ submitBulk('unjoin'); }
    else if (action==='join'){ startJoinMode(); }
}
function startJoinMode(){
    joinMode=true; joinFatherId=null;
    document.getElementById('stHint').style.display='block';
    var acts=document.getElementById('stActions');
    acts.innerHTML='<button type="button" class="st-btn" onclick="cancelJoin()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>Annulla</button>'+
        '<button type="button" class="st-btn confirm" onclick="confirmJoin()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>Conferma unione</button>';
    syncHeaderHeight();
}
function cancelJoin(){ exitSelectMode(); }
function confirmJoin(){
    if (!joinFatherId){ showToast('Scegli la scheda principale'); return; }
    document.getElementById('hiddenFatherId').value=joinFatherId;
    submitBulk('join');
}

/* ---------- Move ---------- */
function doMove(catId){ document.getElementById('hiddenTargetCategory').value=catId; submitBulk('move'); }

/* ---------- Azioni parola (kebab desktop) ---------- */
function apriAzioniParola(kind, id, editUrl){
    document.getElementById('waEditLink').href = editUrl;
    document.getElementById('waDeleteBtn').onclick = function(){ chiudiModals(); setTimeout(function(){ askDeleteSingle(kind, id); }, 260); };
    openSheet(document.getElementById('sheetWordActions'));
}

/* ---------- Single delete (AJAX) ---------- */
var pendingDelete=null;
function askDeleteSingle(kind, id){
    chiudiTuttiSwipe();
    pendingDelete={kind:kind, id:id};
    document.getElementById('deleteText').innerHTML='Vuoi eliminare questa '+(kind==='child'?'parola collegata':'parola')+'?';
    document.getElementById('deleteConfirmBtn').onclick=performSingleDelete;
    openSheet(document.getElementById('sheetDelete'));
}
function performSingleDelete(){
    if (!pendingDelete) return;
    var kind=pendingDelete.kind, id=pendingDelete.id; pendingDelete=null;
    chiudiModals();
    var card=document.getElementById(kind==='father' ? 'card-img-'+id : 'card-child-'+id);
    var fd=new FormData(); fd.append('bulk_action','delete');
    fd.append(kind==='father' ? 'selected_ids[]' : 'selected_child_ids[]', id);
    fetch('visualizzazione.php'+window.location.search, {method:'POST', body:fd}).then(function(){
        if (card){
            if (kind==='father'){ var g=card.closest('.word-group'); (g||card).classList.add('card-fade-out'); setTimeout(function(){ (g||card).remove(); afterRemoval(); }, 380); }
            else { card.classList.add('card-fade-out'); setTimeout(function(){ card.remove(); afterRemoval(); }, 380); }
        }
        showToast('Eliminata', 'trash');
    }).catch(function(){ showToast('Errore di rete'); });
}
function afterRemoval(){
    updateCounter();
    if (document.querySelectorAll('#wordsWrap .eng-card').length===0){
        var real=document.getElementById('emptyReal'); if (real) real.style.display='';
        var cl=document.getElementById('countLine'); if (cl) cl.textContent='';
    }
}

/* ---------- Swipe (touch) ---------- */
window.chiudiTuttiSwipe=function(){};
(function(){
    if (!window.matchMedia("(hover:none) and (pointer:coarse)").matches) return;
    var active=null, startX=0, startY=0, dragging=false, maxSwipe=0, dirDecided=false, isHorizontal=false;
    var DEADZONE=10, RATIO=2.2; // serve un movimento chiaramente orizzontale (>2.2x quello verticale) prima di attivare lo swipe
    window.chiudiTuttiSwipe=function(){
        document.querySelectorAll('.swipe-content.swiped-open').forEach(function(c){ c.style.transform='translateX(0)'; c.classList.remove('swiped-open'); });
        document.querySelectorAll('.swipe-actions').forEach(function(a){ a.style.visibility='hidden'; });
        active=null;
    };
    document.addEventListener('touchstart', function(e){
        if (selectMode) return;
        if (e.target.closest('.swipe-actions') || e.target.closest('.learnt-wrapper') || e.target.closest('.eng-media')) return;
        var content=e.target.closest('.swipe-content');
        if (!content){ chiudiTuttiSwipe(); return; }
        if (active && active!==content) chiudiTuttiSwipe();
        active=content;
        var actions=content.parentElement.querySelector('.swipe-actions');
        maxSwipe = actions ? actions.offsetWidth : 0;
        startX=e.touches[0].clientX; startY=e.touches[0].clientY; dragging=true; dirDecided=false; isHorizontal=false;
        content.style.transition='none';
    }, {passive:true});
    document.addEventListener('touchmove', function(e){
        if (!dragging || !active) return;
        var dx=e.touches[0].clientX-startX, dy=e.touches[0].clientY-startY;
        if (!dirDecided){
            if (Math.abs(dx) < DEADZONE && Math.abs(dy) < DEADZONE) return;
            dirDecided = true;
            isHorizontal = Math.abs(dx) > Math.abs(dy) * RATIO;
            if (!isHorizontal){ dragging=false; active=null; return; }
            else {
                var acts = active.parentElement.querySelector('.swipe-actions');
                if (acts) acts.style.visibility = 'visible';
            }
        }
        if (!isHorizontal) return;
        if (dx<0){ var tx=Math.max(dx, -(maxSwipe+20)); active.style.transform='translateX('+tx+'px)'; e.preventDefault(); }
        else if (dx>0){ var t2=Math.min(dx,15); active.style.transform='translateX('+t2+'px)'; e.preventDefault(); }
    }, {passive:false});
    document.addEventListener('touchend', function(){
        if (!dragging || !active) return;
        dragging=false; active.style.transition='transform .3s cubic-bezier(.32,.72,0,1)';
        var tx=0, ts=active.style.transform;
        if (ts && ts.indexOf('translateX')!==-1) tx=parseFloat(ts.replace('translateX(',''));
        if (tx < -maxSwipe/2){ active.style.transform='translateX(-'+maxSwipe+'px)'; active.classList.add('swiped-open'); }
        else { active.style.transform='translateX(0)'; active.classList.remove('swiped-open'); active=null; }
    });
})();

/* ---------- Toast da redirect ---------- */
(function(){
    var ok=new URLSearchParams(window.location.search).get('ok');
    var msg={deleted:'Eliminate', moved:'Spostate', joined:'Parole unite', unjoined:'Parole separate'};
    if (ok && msg[ok]){ showToast(msg[ok], ok==='deleted'?'trash':'check'); var p=new URLSearchParams(window.location.search); p.delete('ok'); history.replaceState(null,'', window.location.pathname+(p.toString()?'?'+p.toString():'')); }
})();

window.addEventListener('load', function(){ filterList(); });
</script>
</body>
</html>