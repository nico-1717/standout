<?php
require_once 'config.php';
require_once 'auth_check.php';
$current_user_id = $utente_corrente['id'];

$conn = mysqli_connect(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
if (!$conn) { die("Connessione fallita: " . mysqli_connect_error()); }

// ============================================================
// RICEVITORE AJAX PER L'INSERIMENTO MULTIPLO
// ============================================================
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'insert_bulk') {
    header('Content-Type: application/json');

    if (!is_dir(IMAGE_PATH_INSERT)) { mkdir(IMAGE_PATH_INSERT, 0777, true); }

    $categoryId = intval($_POST['category_id']);

    // --- SICUREZZA: verifica che la categoria appartenga all'utente loggato ---
    $check_cat = mysqli_query($conn, "SELECT user_id FROM categories WHERE id = $categoryId");
    $row_cat = $check_cat ? mysqli_fetch_assoc($check_cat) : null;
    if (!$row_cat || (int)$row_cat['user_id'] !== $current_user_id) {
        echo json_encode(['success' => false, 'error' => 'Non sei autorizzato ad aggiungere parole a questa categoria.']);
        mysqli_close($conn);
        exit;
    }

    $rows_count = isset($_POST['en_desc']) ? count($_POST['en_desc']) : 0;
    $inserted_count = 0;

    for ($i = 0; $i < $rows_count; $i++) {
        $en      = trim($_POST['en_desc'][$i] ?? '');
        $it      = trim($_POST['it_desc'][$i] ?? '');
        $meaning = trim($_POST['meaning'][$i] ?? '');
        $example = trim($_POST['example'][$i] ?? '');
        $img_name = $_POST['img_name'][$i] ?? '';

        // --- GESTIONE IMMAGINE: prima il file caricato direttamente su questa riga,
        //     poi (fallback) il pool di immagini multiple abbinate per nome file ---
        $final_img = "";
        if (isset($_FILES['row_img']['name'][$i]) && $_FILES['row_img']['error'][$i] == 0) {
            $ext = pathinfo($_FILES['row_img']['name'][$i], PATHINFO_EXTENSION);
            $final_img = time() . "_row_$i." . $ext;
            move_uploaded_file($_FILES['row_img']['tmp_name'][$i], IMAGE_PATH_INSERT . $final_img);
        } else if (!empty($img_name) && isset($_FILES['bulk_images'])) {
            foreach ($_FILES['bulk_images']['name'] as $idx => $fname) {
                if ($fname == $img_name) {
                    $ext = pathinfo($fname, PATHINFO_EXTENSION);
                    $final_img = time() . "_bulk_$i." . $ext;
                    move_uploaded_file($_FILES['bulk_images']['tmp_name'][$idx], IMAGE_PATH_INSERT . $final_img);
                    break;
                }
            }
        }

        // Riga completamente vuota: la saltiamo
        if ($en === '' && $it === '' && empty($final_img)) { continue; }

        $en      = ucfirst(str_replace("'", "’", $en));
        $it      = ucfirst(str_replace("'", "’", $it));
        $meaning = ucfirst(str_replace("'", "’", $meaning));
        $example = ucfirst(str_replace("'", "’", $example));

        $enEsc   = mysqli_real_escape_string($conn, $en);
        $itEsc   = mysqli_real_escape_string($conn, $it);
        $meanEsc = mysqli_real_escape_string($conn, $meaning);
        $exEsc   = mysqli_real_escape_string($conn, $example);
        $imgEsc  = mysqli_real_escape_string($conn, $final_img);

        $ok = mysqli_query($conn, "INSERT INTO images (category_id, description_en, description_it, meaning, example, image, is_father)
                                    VALUES ($categoryId, '$enEsc', '$itEsc', '$meanEsc', '$exEsc', '$imgEsc', 0)");
        if ($ok) { $inserted_count++; }
    }

    echo json_encode(['success' => true, 'count' => $inserted_count, 'category_id' => $categoryId]);
    mysqli_close($conn);
    exit;
}

// ============================================================
// Categorie di questo utente (con supporto padre/figlio)
// ============================================================
$all_categories = [];
$categories_by_parent = [];
$cq = mysqli_query($conn, "SELECT id, name, parent_id, color FROM categories WHERE user_id = $current_user_id ORDER BY name");
while ($row = mysqli_fetch_assoc($cq)) {
    $all_categories[$row['id']] = $row;
    $categories_by_parent[$row['parent_id']][] = $row['id'];
}

// Categoria pre-selezionata (es. arrivo da inserimento.php con ?category_id=123)
$preselected_cat_id = 0;
$preselected_cat_name = '';
if (isset($_GET['category_id'])) {
    $preselected_cat_id = intval($_GET['category_id']);
    if ($preselected_cat_id > 0 && isset($all_categories[$preselected_cat_id])) {
        $preselected_cat_name = $all_categories[$preselected_cat_id]['name'];
    } else {
        $preselected_cat_id = 0;
    }
}
mysqli_close($conn);

// Lista indentata per il foglio "Categoria" (stessa estetica di categories.php / inserimento.php)
function big_render_cat_list($parentId, $all_cats, $cats_by_parent, $level = 0) {
    if (!isset($cats_by_parent[$parentId])) return;
    foreach ($cats_by_parent[$parentId] as $id) {
        $cat = $all_cats[$id];
        $nameEsc = htmlspecialchars($cat['name'], ENT_QUOTES);
        $color = (!empty($cat['color']) && preg_match('/^#[0-9A-Fa-f]{6}$/', $cat['color'])) ? $cat['color'] : '#A0A0A0';
        $pad = 14 + $level * 20;
        echo "<button type='button' class='cat-pick-item' data-id='{$id}' data-name=\"" . htmlspecialchars($cat['name'], ENT_QUOTES) . "\" style='padding-left:{$pad}px' onclick='selezionaCategoria({$id}, this)'>";
        echo "<span class='cat-pick-dot' style='background:{$color}'></span>";
        echo "<span class='cat-pick-name'>{$nameEsc}</span>";
        echo "</button>";
        big_render_cat_list($id, $all_cats, $cats_by_parent, $level + 1);
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=0, viewport-fit=cover">
<meta name="mobile-web-app-capable" content="yes">
<meta name="theme-color" content="#F2F2F7" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#000000" media="(prefers-color-scheme: dark)">
<title>Inserimento multiplo · Vocabolario</title>
<style>
/* ================================================================
   DESIGN SYSTEM — iOS nativo · light + dark automatico
   (coerente con inserimento.php / categories.php / visualizzazione.php)
   ================================================================ */
:root{
    color-scheme: light dark;
    --bg:#F2F2F7; --surface:#FFFFFF; --surface-2:#F2F2F7; --surface-3:#EDEDF2;
    --text:#1C1C1E; --text-2:#8A8A8E; --text-3:#AEAEB2;
    --hairline:rgba(60,60,67,.12); --hairline-2:rgba(60,60,67,.22);
    --blue:#007AFF; --green:#34C759; --red:#FF3B30; --orange:#FF9500; --purple:#AF52DE; --pink:#FF2D55; --gray:#8E8E93;
    --blue-soft:rgba(0,122,255,.09); --green-soft:rgba(52,199,89,.13); --red-soft:rgba(255,59,48,.12);
    --orange-soft:rgba(255,149,0,.14); --purple-soft:rgba(175,82,222,.12); --gray-soft:rgba(142,142,147,.14);
    --header-glass:rgba(249,249,251,.82);
    --shadow-card:0 1px 1px rgba(0,0,0,.04), 0 10px 30px rgba(0,0,0,.07);
    --chip-bg:#FFFFFF;
    --btn-hl:inset 0 1px 0 rgba(255,255,255,.25), inset 0 -1px 0 rgba(0,0,0,.16);
    --raise:0 1px 2px rgba(0,0,0,.07), 0 10px 22px -6px rgba(0,0,0,.16);
    --raise-sm:0 1px 1.5px rgba(0,0,0,.08), 0 5px 12px -4px rgba(0,0,0,.13);
    --pill-hl:inset 0 0 0 .5px var(--hairline);
    --r-card:22px; --r-btn:15px; --r-chip:100px;
    --spring:cubic-bezier(.32,.72,0,1);
    --snap:cubic-bezier(.24,1.3,.35,1);
    --safe-top:env(safe-area-inset-top, 0px);
    --safe-bottom:env(safe-area-inset-bottom, 0px);
}
@media (prefers-color-scheme: dark){
    :root{
        --bg:#000000; --surface:#1C1C1E; --surface-2:#2C2C2E; --surface-3:#333336;
        --text:#F5F5F7; --text-2:#98989F; --text-3:#636366;
        --hairline:rgba(84,84,88,.42); --hairline-2:rgba(84,84,88,.62);
        --blue:#0A84FF; --green:#30D158; --red:#FF453A; --orange:#FF9F0A; --purple:#BF5AF2; --pink:#FF375F; --gray:#98989F;
        --blue-soft:rgba(10,132,255,.16); --green-soft:rgba(48,209,88,.17); --red-soft:rgba(255,69,58,.17);
        --orange-soft:rgba(255,159,10,.18); --purple-soft:rgba(191,90,242,.18); --gray-soft:rgba(152,152,159,.18);
        --header-glass:rgba(16,16,18,.78);
        --shadow-card:0 1px 1px rgba(0,0,0,.35), 0 10px 30px rgba(0,0,0,.5);
        --chip-bg:#3A3A3C;
        --btn-hl:inset 0 1px 0 rgba(255,255,255,.22), inset 0 -1px 0 rgba(0,0,0,.3);
        --raise:0 1px 2px rgba(0,0,0,.5), 0 12px 26px -6px rgba(0,0,0,.65);
        --raise-sm:0 1px 1.5px rgba(0,0,0,.45), 0 5px 12px -4px rgba(0,0,0,.55);
        --pill-hl:inset 0 1px 0 rgba(255,255,255,.08), inset 0 0 0 .5px var(--hairline);
    }
}
*{ box-sizing:border-box; -webkit-tap-highlight-color:transparent; }
html{ -webkit-text-size-adjust:100%; }
html, body{ min-height:100%; overflow-x:hidden; }
body{
    margin:0; background:var(--bg); color:var(--text);
    font-family:-apple-system, BlinkMacSystemFont, "SF Pro Text", "SF Pro Display", system-ui, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
    font-size:16px; line-height:1.45; -webkit-font-smoothing:antialiased; text-rendering:optimizeLegibility;
    padding-bottom:calc(60px + var(--safe-bottom));
}
:focus-visible{ outline:2px solid var(--blue); outline-offset:2px; border-radius:8px; }
a{ color:var(--blue); }

/* ================================================================
   HEADER
   ================================================================ */
.app-header{
    position:sticky; top:0; left:0; right:0; z-index:200;
    display:flex; align-items:center; justify-content:space-between; gap:10px;
    padding:calc(var(--safe-top) + 12px) 16px 12px;
    background:var(--header-glass);
    -webkit-backdrop-filter:saturate(1.8) blur(22px); backdrop-filter:saturate(1.8) blur(22px);
    border-bottom:.5px solid var(--hairline);
}
.app-header .hdr-text{ min-width:0; }
.app-header h1{ margin:0; font-size:19px; font-weight:800; letter-spacing:-.02em; color:var(--text); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.app-header .hdr-sub{ margin-top:1px; font-size:12px; font-weight:600; color:var(--text-2); }
.hdr-chip{ flex-shrink:0; display:inline-flex; align-items:center; gap:6px; padding:7px 13px; border-radius:var(--r-chip); font-size:12.5px; font-weight:800; background:var(--blue-soft); color:var(--blue); font-variant-numeric:tabular-nums; }

.page-wrap{ max-width:760px; margin:0 auto; padding:16px 14px calc(24px + var(--safe-bottom)); }

/* ================================================================
   BOTTONI
   ================================================================ */
.btn{
    display:flex; align-items:center; justify-content:center; gap:8px;
    width:100%; padding:15px 16px; margin:0;
    border:none; border-radius:var(--r-btn); cursor:pointer;
    font-family:inherit; font-size:16px; font-weight:700; letter-spacing:-.01em;
    color:#fff; background:var(--text);
    text-decoration:none; text-align:center; -webkit-user-select:none; user-select:none;
    --glow:rgba(0,0,0,.3);
    box-shadow:var(--btn-hl), 0 1px 2px rgba(0,0,0,.14), 0 12px 26px -8px var(--glow);
    transition:transform .16s var(--spring), box-shadow .2s var(--spring), filter .2s, opacity .2s;
}
.btn:active{ transform:translateY(1.5px) scale(.985); filter:brightness(.95); }
.btn:disabled{ opacity:.55; cursor:not-allowed; transform:none !important; filter:none !important; }
.btn-primary{ --glow:rgba(10,132,255,.5); background:linear-gradient(180deg, color-mix(in srgb, var(--blue), #fff 16%), var(--blue) 52%, color-mix(in srgb, var(--blue), #000 10%)); }
.btn-success{ --glow:rgba(52,199,89,.5); background:linear-gradient(180deg, color-mix(in srgb, var(--green), #fff 18%), var(--green) 52%, color-mix(in srgb, var(--green), #000 10%)); }
.btn-warn{ --glow:rgba(255,149,0,.5); background:linear-gradient(180deg, color-mix(in srgb, var(--orange), #fff 16%), var(--orange) 52%, color-mix(in srgb, var(--orange), #000 10%)); }
.btn-flat-warn{ color:var(--gray); background:var(--gray-soft); box-shadow:inset 0 0 0 1.5px var(--hairline-2); --glow:transparent; }
.btn-ghost{ color:var(--text); background:var(--surface); box-shadow:var(--pill-hl), var(--raise); }
.btn-ghost:active{ filter:none; box-shadow:var(--pill-hl), inset 0 2px 6px rgba(0,0,0,.08), 0 1px 3px rgba(0,0,0,.08); }
.btn-sm{ padding:11px 14px; font-size:14.5px; }
.btn svg{ width:18px; height:18px; flex-shrink:0; }

/* ================================================================
   CARD / SETUP
   ================================================================ */
.card-surface{ background:var(--surface); border-radius:var(--r-card); border:.5px solid var(--hairline); box-shadow:var(--shadow-card); padding:18px 16px; }
.setup-grid{ display:grid; grid-template-columns:1fr; gap:12px; margin-bottom:14px; }
@media (min-width:680px){ .setup-grid{ grid-template-columns:1fr 1fr; } .setup-grid .full{ grid-column:1/-1; } }
.setup-card{ padding:16px; }
.setup-card .sc-label{ display:flex; align-items:center; gap:8px; font-size:11px; font-weight:800; letter-spacing:.09em; text-transform:uppercase; color:var(--blue); margin-bottom:12px; }
.setup-card .sc-label .ic{ width:20px; height:20px; border-radius:7px; background:var(--blue-soft); display:flex; align-items:center; justify-content:center; flex-shrink:0; }
.setup-card .sc-label .ic svg{ width:12.5px; height:12.5px; color:var(--blue); }
.mini-label{ font-size:12.5px; font-weight:700; color:var(--text-2); margin:0 0 6px; display:block; }

input[type="file"]{ font-size:13px; color:var(--text-2); }
input[type="file"]::file-selector-button{ border:none; padding:8px 12px; margin-right:8px; border-radius:10px; background:var(--blue-soft); color:var(--blue); font-weight:700; font-size:12.5px; cursor:pointer; }
textarea, select, input[type="text"]{
    width:100%; box-sizing:border-box; font-family:inherit; color:var(--text);
    background:var(--surface-2); border:1px solid var(--hairline-2); border-radius:12px;
    padding:11px 12px; font-size:16px; resize:vertical;
}
select{ -webkit-appearance:none; appearance:none; padding-right:34px;
    background-image:url("data:image/svg+xml;charset=utf-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%238A8A8E' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E");
    background-repeat:no-repeat; background-position:right 11px center;
}
textarea:focus, select:focus, input:focus{ outline:none; border-color:var(--blue); }

.category-btn{
    width:100%; display:flex; align-items:center; justify-content:space-between; gap:10px;
    padding:15px 16px; border-radius:14px; border:1.5px dashed var(--hairline-2);
    background:var(--surface-2); color:var(--text-3); font-weight:700; font-size:15.5px;
    cursor:pointer; font-family:inherit; text-align:left; transition:border-color .2s, background .2s, transform .15s var(--spring);
}
.category-btn.is-set{ color:var(--text); border-style:solid; border-color:var(--blue); background:var(--blue-soft); }
.category-btn:active{ transform:scale(.99); }
.category-btn .cb-ic{ width:34px; height:34px; border-radius:10px; background:var(--surface); box-shadow:var(--pill-hl), var(--raise-sm); display:flex; align-items:center; justify-content:center; flex-shrink:0; }
.category-btn .cb-ic svg{ width:17px; height:17px; color:var(--blue); }
.category-btn .cb-chev{ color:var(--text-3); flex-shrink:0; display:flex; }
.category-btn .cb-chev svg{ width:16px; height:16px; }

.import-row{ display:flex; gap:10px; margin-top:10px; flex-wrap:wrap; }
.import-row > div{ flex:1; min-width:130px; }
.ai-prompt-box{ background:var(--surface-2); border:1.5px dashed var(--hairline-2); border-radius:14px; margin-top:10px; overflow:hidden; position:relative; }
.ai-prompt-box textarea{ width:100%; height:150px; font-family:ui-monospace, SFMono-Regular, Menlo, monospace; font-size:12.5px; line-height:1.55; border:none; background:transparent; resize:vertical; outline:none; cursor:copy; padding:14px; }
.ai-copy-hint{ position:absolute; top:8px; right:8px; font-size:10.5px; font-weight:800; letter-spacing:.05em; text-transform:uppercase; color:var(--purple); background:var(--purple-soft); padding:4px 9px; border-radius:99px; pointer-events:none; }
.format-help{ background:var(--purple-soft); border-radius:var(--r-card); padding:16px; margin-bottom:14px; border:.5px solid transparent; }
.format-help strong{ color:var(--purple); font-size:14.5px; }
.format-help p{ margin:6px 0 0; font-size:13px; color:var(--text-2); line-height:1.5; }
#success-msg{ display:none; background:var(--green-soft); color:var(--green); padding:22px 18px; text-align:center; border-radius:var(--r-card); margin:0 0 16px; font-size:16px; font-weight:700; line-height:1.5; animation:popIn .4s var(--spring) both; }
#success-msg small{ display:block; margin-top:6px; font-weight:600; color:var(--text-2); font-size:13px; }
@keyframes popIn{ from{ opacity:0; transform:translateY(-8px) scale(.97); } }

/* ================================================================
   TOAST
   ================================================================ */
.app-toast{
    position:fixed; left:50%; bottom:calc(24px + var(--safe-bottom)); z-index:11000;
    transform:translateX(-50%) translateY(20px); opacity:0;
    max-width:88%; padding:13px 20px; border-radius:16px;
    background:var(--text); color:var(--bg); font-size:14px; font-weight:600; text-align:center;
    box-shadow:0 14px 40px rgba(0,0,0,.3); transition:opacity .3s var(--spring), transform .3s var(--spring);
}
.app-toast.show{ opacity:1; transform:translateX(-50%) translateY(0); }
.app-toast.toast-error{ background:var(--red); color:#fff; }

/* ================================================================
   MODALE CONFERMA
   ================================================================ */
.confirm-overlay{ position:fixed; inset:0; z-index:10500; display:none; align-items:flex-end; justify-content:center; background:rgba(0,0,0,.4); -webkit-backdrop-filter:blur(6px); backdrop-filter:blur(6px); }
.confirm-overlay.show{ display:flex; }
.confirm-panel{ width:100%; max-width:440px; background:var(--surface); color:var(--text); border-radius:24px 24px 0 0; padding:26px 20px calc(20px + var(--safe-bottom)); box-shadow:0 -10px 50px rgba(0,0,0,.3); animation:sheetUp .4s var(--snap) both; position:relative; }
@keyframes sheetUp{ from{ transform:translateY(40%); opacity:.3; } to{ transform:none; opacity:1; } }
.confirm-panel::before{ content:""; position:absolute; top:8px; left:50%; transform:translateX(-50%); width:38px; height:5px; border-radius:99px; background:var(--hairline-2); }
.confirm-panel h3{ margin:4px 0 8px; font-size:18.5px; font-weight:800; letter-spacing:-.01em; }
.confirm-panel p{ margin:0 0 18px; font-size:14.5px; color:var(--text-2); line-height:1.5; }
.confirm-actions{ display:flex; gap:10px; }
.confirm-actions .btn{ flex:1; }
@media (min-width:480px){ .confirm-overlay{ align-items:center; } .confirm-panel{ border-radius:24px; } }

/* ================================================================
   FOGLIO CATEGORIA (stessa estetica di categories.php / inserimento.php)
   ================================================================ */
.modal-overlay{ display:none; position:fixed; inset:0; z-index:900; background:rgba(0,0,0,.5); -webkit-backdrop-filter:blur(6px); backdrop-filter:blur(6px); justify-content:center; align-items:flex-end; }
.modal-overlay.show{ display:flex; }
.modal-content{ background:var(--surface); color:var(--text); padding:22px 20px calc(24px + var(--safe-bottom)); border-radius:26px 26px 0 0; max-width:540px; width:100%; position:relative; max-height:80vh; overflow-y:auto; box-shadow:0 -12px 50px rgba(0,0,0,.4); animation:sheetUp .42s var(--spring) both; }
.modal-content::before{ content:""; position:absolute; top:9px; left:50%; transform:translateX(-50%); width:38px; height:5px; border-radius:99px; background:var(--hairline-2); }
.modal-title{ margin:6px 0 14px; font-size:19px; font-weight:800; letter-spacing:-.02em; text-align:center; }
@media (min-width:640px){ .modal-overlay{ align-items:center; padding:20px; } .modal-content{ border-radius:26px; } }
.cat-pick-list{ display:flex; flex-direction:column; gap:6px; max-height:56vh; overflow-y:auto; }
.cat-pick-item{ display:flex; align-items:center; gap:10px; padding:12px 14px; border-radius:14px; cursor:pointer; background:var(--surface-2); border:none; width:100%; text-align:left; font-family:inherit; transition:transform .15s var(--spring); }
.cat-pick-item:active{ transform:scale(.98); }
.cat-pick-dot{ width:12px; height:12px; border-radius:50%; flex-shrink:0; }
.cat-pick-name{ font-size:15px; font-weight:700; color:var(--text); }
.cat-pick-empty{ text-align:center; padding:24px 12px; color:var(--text-2); font-size:14px; }
.cat-pick-empty a{ font-weight:700; }

/* ================================================================
   LISTA RIGHE — card eleganti, coerenti con visualizzazione.php
   ================================================================ */
.fc-rows{ display:flex; flex-direction:column; gap:14px; margin:16px 0; }
.fc-rows:empty{ display:none; }
.fc-row{ position:relative; border-radius:var(--r-card); overflow:hidden; box-shadow:var(--shadow-card); animation:rowIn .35s var(--spring) both; }
@keyframes rowIn{ from{ opacity:0; transform:translateY(10px) scale(.98); } }
.fc-row-content{ position:relative; background:var(--surface); border-radius:var(--r-card); border:.5px solid var(--hairline); }
.fc-row-strip{ display:flex; align-items:center; gap:10px; padding:12px 14px 8px; }
.row-badge{ display:inline-flex; align-items:center; gap:6px; font-size:10px; font-weight:800; letter-spacing:.08em; text-transform:uppercase; color:var(--text-2); background:var(--surface-3); padding:4px 10px; border-radius:99px; }
.row-badge::before{ content:""; width:5px; height:5px; border-radius:50%; background:var(--blue); flex-shrink:0; }
.strip-spacer{ flex:1; }
.row-del-btn{ flex-shrink:0; width:28px; height:28px; border-radius:50%; border:none; cursor:pointer; background:var(--red-soft); color:var(--red); display:flex; align-items:center; justify-content:center; transition:transform .15s var(--spring); }
.row-del-btn:active{ transform:scale(.88); }
.row-del-btn svg{ width:14px; height:14px; }

.row-bilingual{ display:grid; grid-template-columns:1fr 1fr; gap:0; margin:0 14px 10px; border-radius:14px; overflow:hidden; box-shadow:inset 0 0 0 .5px var(--hairline); }
.row-col{ padding:10px 11px; background:var(--surface-2); min-width:0; }
.row-col.col-en{ border-right:.5px solid var(--hairline); background:var(--blue-soft); }
.row-eyebrow{ display:flex; align-items:center; gap:5px; font-size:9.5px; font-weight:800; letter-spacing:.06em; text-transform:uppercase; color:var(--text-3); margin-bottom:5px; }
.col-en .row-eyebrow{ color:var(--blue); }
.row-col input[type="text"]{ background:var(--surface); border-color:transparent; box-shadow:inset 0 0 0 1px var(--hairline-2); padding:9px 10px; font-weight:700; font-size:14.5px; }
.row-col input[type="text"]:focus{ box-shadow:inset 0 0 0 1.5px var(--blue); }
@media (max-width:420px){ .row-bilingual{ grid-template-columns:1fr; } .row-col.col-en{ border-right:none; border-bottom:.5px solid var(--hairline); } }

.row-field{ margin:0 14px 10px; }
.row-field .field-label{ font-size:9.5px; font-weight:800; letter-spacing:.06em; text-transform:uppercase; color:var(--text-3); margin:0 0 5px; display:block; }
.row-field input[type="text"]{ font-size:14.5px; padding:9px 11px; }

.media-row{ display:flex; gap:8px; margin:2px 14px 14px; flex-wrap:wrap; align-items:center; }
.media-chip{ display:flex; align-items:center; gap:7px; padding:8px 12px 8px 8px; border-radius:99px; background:var(--surface-2); box-shadow:var(--pill-hl), var(--raise-sm); cursor:pointer; font-size:12.5px; font-weight:700; color:var(--text-2); border:none; font-family:inherit; transition:transform .15s var(--spring); }
.media-chip:active{ transform:scale(.95); }
.media-chip input[type=file]{ display:none; }
.media-chip.has-img{ background:var(--blue-soft); color:var(--blue); }
.chip-thumb{ width:26px; height:26px; border-radius:50%; overflow:hidden; flex-shrink:0; background:var(--surface-3); display:flex; align-items:center; justify-content:center; color:var(--text-3); }
.chip-thumb img{ width:100%; height:100%; object-fit:cover; }
.chip-thumb svg{ width:14px; height:14px; }
.img-name-tag{ max-width:120px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }

.empty-hint{ text-align:center; padding:30px 16px; color:var(--text-3); font-size:14px; font-weight:600; line-height:1.5; }
.action-stack{ display:flex; flex-direction:column; gap:10px; margin-top:8px; }

@media (prefers-reduced-motion: reduce){ *, *::before, *::after{ animation-duration:.01ms !important; transition-duration:.01ms !important; } }
</style>
</head>
<body>

<?php include 'sidebar.html'; ?>

<header class="app-header">
    <div class="hdr-text">
        <h1>Inserimento multiplo</h1>
        <div class="hdr-sub">Vocabolario</div>
    </div>
    <div class="hdr-chip" id="row-count-chip">0 parole</div>
</header>

<div class="page-wrap">

    <div id="success-msg">
        ✅ Inserimento completato — <strong id="success-count">0</strong> parole salvate.
        <small>Ritorno alla categoria in corso…</small>
    </div>

    <div class="format-help">
        <strong>🤖 Prompt IA per la generazione automatica</strong>
        <p>Tocca il riquadro sottostante per copiare il testo e incollalo su ChatGPT/Gemini per generare parole già compatibili.</p>
        <div class="ai-prompt-box">
            <span class="ai-copy-hint">Tocca per copiare</span>
            <textarea id="ai_prompt_textarea" readonly onclick="this.select(); navigator.clipboard.writeText(this.value).then(() => showToast('Copiato negli appunti')).catch(() => { document.execCommand('copy'); showToast('Copiato negli appunti'); });"></textarea>
        </div>
    </div>

    <form id="bigForm">
        <div class="setup-grid">
            <div class="card-surface setup-card full">
                <div class="sc-label"><span class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2Z"/></svg></span>Categoria di destinazione</div>
                <button type="button" class="category-btn <?php echo $preselected_cat_id ? 'is-set' : ''; ?>" id="categoryBtn" onclick="openCatSheet()">
                    <span style="display:flex; align-items:center; gap:10px; min-width:0;">
                        <span class="cb-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2Z"/></svg></span>
                        <span id="categoryBtnLabel" style="white-space:nowrap; overflow:hidden; text-overflow:ellipsis;"><?php echo $preselected_cat_name !== '' ? htmlspecialchars($preselected_cat_name) : 'Tocca per selezionare…'; ?></span>
                    </span>
                    <span class="cb-chev"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m9 6 6 6-6 6"/></svg></span>
                </button>
                <input type="hidden" id="categoryInput" value="<?php echo (int)$preselected_cat_id; ?>">
            </div>

            <div class="card-surface setup-card">
                <div class="sc-label"><span class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3.2"/><path d="M4 7h2.2L8 4h8l1.8 3H20a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V8a1 1 0 0 1 1-1Z"/></svg></span>Immagini (opzionale)</div>
                <label class="mini-label">Un'immagine per ogni parola, nello stesso ordine</label>
                <input type="file" id="bulk_images" accept="image/*" multiple>
            </div>

            <div class="card-surface setup-card">
                <div class="sc-label"><span class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 6h16M4 12h16M4 18h10"/></svg></span>Importa da testo</div>

                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; background:var(--surface-2); padding:10px 12px; border-radius:12px; border:1px solid var(--hairline-2);">
                    <label class="mini-label" style="margin:0; font-size:13.5px;">Usa separatori personalizzati (### e @@@)</label>
                    <input type="checkbox" id="format_switch" style="transform:scale(1.2); accent-color:var(--blue); cursor:pointer;" onchange="toggleImportFormat()">
                </div>

                <textarea id="import_text" rows="3" placeholder='Incolla qui l&#39;array JSON. Esempio: [{"en": "Run", "it": "Correre", ...}]' style="font-size:14.5px;"></textarea>

                <div class="import-row" id="custom_separators_row" style="display:none;">
                    <div>
                        <label class="mini-label">Separatore Parole</label>
                        <select id="import_row_separator" onchange="updatePlaceholder()">
                            <option value="@@@" selected>Tre chiocciole ( @@@ )</option>
                            <option value="@">Chiocciola singola ( @ )</option>
                            <option value="\n">A capo (Invio)</option>
                        </select>
                    </div>
                    <div>
                        <label class="mini-label">Separatore Campi</label>
                        <select id="import_separator" onchange="updatePlaceholder()">
                            <option value="###" selected>Tre hash ( ### )</option>
                            <option value="|">Pipe ( | )</option>
                            <option value=";">Punto e virgola ( ; )</option>
                        </select>
                    </div>
                </div>
                <p class="mini-label" style="margin-top:10px; font-weight:600;">Ordine campi: Inglese, Italiano, Significato, Esempio.</p>
            </div>
        </div>

        <button type="button" id="btn-generate-table" class="btn btn-warn" onclick="generateTable()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12l7 7 7-7"/></svg>
            Genera Tabella Dati
        </button>

        <div class="fc-rows" id="fcRows"></div>
        <div class="empty-hint" id="emptyHint">Nessuna parola ancora — genera dai dati importati oppure aggiungine una vuota qui sotto.</div>

        <div class="action-stack">
            <button type="button" class="btn btn-primary" onclick="addSingleRow()">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
                Aggiungi Parola Vuota
            </button>
            <button type="submit" id="submitBtn" class="btn btn-success" disabled>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5 10 17l9-10"/></svg>
                Salva Tutto nel Database
            </button>
        </div>
    </form>
</div>

<!-- ============== FOGLIO CATEGORIA ============== -->
<div id="catSheet" class="modal-overlay">
    <div class="modal-content">
        <h3 class="modal-title">Scegli una categoria</h3>
        <div class="cat-pick-list">
            <?php if (empty($all_categories)): ?>
                <div class="cat-pick-empty">Non hai ancora nessuna categoria.<br><a href="categories.php">Creane una da qui</a>.</div>
            <?php else: ?>
                <?php big_render_cat_list(0, $all_categories, $categories_by_parent); ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="confirm-overlay" id="confirmOverlay">
    <div class="confirm-panel">
        <h3 id="confirmTitle">Sei sicuro?</h3>
        <p id="confirmMessage"></p>
        <div class="confirm-actions">
            <button type="button" class="btn btn-ghost btn-sm" id="confirmCancelBtn">Annulla</button>
            <button type="button" class="btn btn-warn btn-sm" id="confirmOkBtn">Conferma</button>
        </div>
    </div>
</div>

<script>
const ICON_TRASH = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h16M9 7V5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2m2 0-1 13a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2L6 7"/></svg>';
const ICON_IMG   = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="10" r="1.6"/><path d="m21 16-5-5-4 4-3-3-6 6"/></svg>';
const ICON_GEN   = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12l7 7 7-7"/></svg>';

function escAttr(s){
    return String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

/* ============================================================
   TOAST & MODALE CONFERMA
   ============================================================ */
function showToast(msg, kind){
    const t = document.createElement('div');
    t.className = 'app-toast' + (kind === 'error' ? ' toast-error' : '');
    t.textContent = msg;
    document.body.appendChild(t);
    requestAnimationFrame(() => t.classList.add('show'));
    setTimeout(() => { t.classList.remove('show'); setTimeout(() => t.remove(), 320); }, 3200);
}
function showConfirm(title, message, okLabel){
    return new Promise(resolve => {
        const overlay = document.getElementById('confirmOverlay');
        document.getElementById('confirmTitle').textContent = title;
        document.getElementById('confirmMessage').textContent = message;
        const okBtn = document.getElementById('confirmOkBtn');
        const cancelBtn = document.getElementById('confirmCancelBtn');
        okBtn.textContent = okLabel || 'Conferma';
        overlay.classList.add('show');
        function cleanup(result){
            overlay.classList.remove('show');
            okBtn.removeEventListener('click', onOk);
            cancelBtn.removeEventListener('click', onCancel);
            resolve(result);
        }
        function onOk(){ cleanup(true); }
        function onCancel(){ cleanup(false); }
        okBtn.addEventListener('click', onOk);
        cancelBtn.addEventListener('click', onCancel);
    });
}

/* ============================================================
   CATEGORIA
   ============================================================ */
document.getElementById('catSheet').addEventListener('click', function(e){ if (e.target.id === 'catSheet') closeCatSheet(); });
function openCatSheet(){ document.getElementById('catSheet').classList.add('show'); }
function closeCatSheet(){ document.getElementById('catSheet').classList.remove('show'); }
function selezionaCategoria(id, btn){
    document.getElementById('categoryInput').value = id;
    document.getElementById('categoryBtnLabel').textContent = btn.dataset.name;
    document.getElementById('categoryBtn').classList.add('is-set');
    closeCatSheet();
}

/* ============================================================
   PROMPT IA (JSON preferito, adattato al vocabolario — nessun
   vincolo di formattazione, solo la struttura dati richiesta)
   ============================================================ */
function updateAIPrompt(){
    const isCustom = document.getElementById('format_switch').checked;
    let rule, exampleOutput;

    if (!isCustom) {
        rule = `L'output deve essere ESATTAMENTE ed UNICAMENTE un array JSON. Ogni elemento deve avere queste 4 chiavi: "en" (parola inglese), "it" (traduzione italiana), "meaning" (breve spiegazione in inglese, max 15 parole), "example" (una frase di esempio in inglese dove la parola è sostituita da un singolo underscore "_").`;
        exampleOutput = `Esempio di output perfetto:
[
{"en": "Run", "it": "Correre", "meaning": "To move fast using your legs", "example": "I like to _ every morning"},
{"en": "Table", "it": "Tavolo", "meaning": "A piece of furniture with a flat top", "example": "Put the book on the _"}
]`;
    } else {
        const rowSepVal = document.getElementById('import_row_separator').value;
        const colSepVal = document.getElementById('import_separator').value;
        const rowSepText = rowSepVal === '\\n' ? "un invio (a capo)" : `"${rowSepVal}"`;
        const colSepText = colSepVal === '\\t' ? "una tabulazione" : `"${colSepVal}"`;
        const actualRowSep = rowSepVal === '\\n' ? '\n' : rowSepVal;

        rule = `L'output deve essere un testo grezzo. Per ogni parola scrivi, nell'ordine, Inglese, Italiano, Significato ed Esempio, separati SEMPRE e SOLO da ${colSepText}. Tra una parola e la successiva inserisci SEMPRE e SOLO ${rowSepText}. Nell'Esempio sostituisci la parola con un singolo underscore "_".`;
        exampleOutput = `Esempio di output perfetto:
Run ${colSepVal} Correre ${colSepVal} To move fast using your legs ${colSepVal} I like to _ every morning${actualRowSep}Table ${colSepVal} Tavolo ${colSepVal} A piece of furniture with a flat top ${colSepVal} Put the book on the _`;
    }

    const finalPrompt = `Sto utilizzando un sito personalizzato per il mio vocabolario di inglese. Vorrei che mi generassi un elenco completo di flashcards sull'argomento che ti scrivo alla fine del prompt. Questo elenco deve essere esaustivo e completo, garantendo a chi conosce questo elenco, di potersi reputare un C1.

${rule}

${exampleOutput}

le parole che dovrai generare, dovranno riguardare questo argomento:`;

    document.getElementById('ai_prompt_textarea').value = finalPrompt;
}

function updatePlaceholder(){
    const isCustom = document.getElementById('format_switch').checked;
    const textArea = document.getElementById('import_text');
    if (isCustom) {
        let rowSep = document.getElementById('import_row_separator').value;
        let colSep = document.getElementById('import_separator').value;
        if (rowSep === '\\n') rowSep = 'Invio (a capo)';
        textArea.placeholder = `Incolla qui il testo generato dall'IA con ${rowSep} e ${colSep}…`;
    } else {
        textArea.placeholder = 'Incolla qui l\'array JSON. Esempio: [{"en": "Run", "it": "Correre", ...}]';
    }
    updateAIPrompt();
}
function toggleImportFormat(){
    const isCustom = document.getElementById('format_switch').checked;
    document.getElementById('custom_separators_row').style.display = isCustom ? 'flex' : 'none';
    updatePlaceholder();
}
window.addEventListener('DOMContentLoaded', () => { updatePlaceholder(); });

/* ============================================================
   GENERAZIONE TABELLA DA TESTO IMPORTATO
   ============================================================ */
async function generateTable(){
    const list = document.getElementById('fcRows');
    if (list.children.length > 0){
        const ok = await showConfirm('Ricreare la tabella?', 'Hai già delle parole. Continuando, tutti i dati inseriti o modificati qui sotto andranno persi per fare spazio ai nuovi.', 'Sì, ricrea');
        if (!ok) return;
    }
    list.innerHTML = '';

    const filesImg = document.getElementById('bulk_images').files;
    const textContent = document.getElementById('import_text').value.trim();
    const isCustom = document.getElementById('format_switch').checked;

    let parsedData = [];

    if (textContent !== '') {
        if (!isCustom) {
            try {
                let safeText = textContent.replace(/\n(?!\s*[\}\]\s]*\s*(?:,|\]|"en"|"it"|"meaning"|"example"|\{))/g, '\\n');
                const jsonArr = JSON.parse(safeText);
                if (!Array.isArray(jsonArr)) throw new Error("Il JSON deve essere un array.");
                parsedData = jsonArr.map(item => ({
                    en: item.en || item.english || item.word || item.description_en || '',
                    it: item.it || item.italian || item.description_it || '',
                    meaning: item.meaning || '',
                    example: item.example || '_'
                }));
            } catch (error) {
                showToast('Errore: il testo non è un JSON valido. Controlla la formattazione.', 'error');
                console.error("Dettaglio errore JSON:", error);
                return;
            }
        } else {
            let colSeparator = document.getElementById('import_separator').value;
            if (colSeparator === '\\t') colSeparator = '\t';
            let rowSeparator = document.getElementById('import_row_separator').value;
            if (rowSeparator === '\\n') rowSeparator = '\n';

            const parsedLines = textContent.split(rowSeparator).map(l => l.trim()).filter(l => l !== '');
            parsedData = parsedLines.map(line => {
                const parts = line.split(colSeparator);
                return {
                    en: (parts[0] || '').trim(),
                    it: (parts[1] || '').trim(),
                    meaning: (parts[2] || '').trim(),
                    example: (parts[3] || '_').trim()
                };
            });
        }
    }

    const maxRows = Math.max(filesImg.length, parsedData.length);
    for (let i = 0; i < maxRows; i++){
        let en = '', it = '', meaning = '', example = '_';
        if (i < parsedData.length){ en = parsedData[i].en; it = parsedData[i].it; meaning = parsedData[i].meaning; example = parsedData[i].example || '_'; }
        const imgUrl = i < filesImg.length ? URL.createObjectURL(filesImg[i]) : '';
        const imgName = i < filesImg.length ? filesImg[i].name : '';
        appendRowHTML(Date.now() + i, en, it, meaning, example, imgUrl, imgName);
    }
    updateGenerateButtonState();
}

/* ============================================================
   RIGHE — creazione, anteprima immagine, rimozione
   ============================================================ */
function addSingleRow(){
    appendRowHTML(Date.now(), '', '', '', '_', '', '');
    updateGenerateButtonState();
    const rows = document.querySelectorAll('#fcRows .fc-row');
    if (rows.length) rows[rows.length - 1].scrollIntoView({ behavior: 'smooth', block: 'center' });
}

function appendRowHTML(id, en, it, meaning, example, imgUrl, imgName){
    const list = document.getElementById('fcRows');
    const wrap = document.createElement('div');
    wrap.className = 'fc-row';
    wrap.dataset.rid = id;
    const thumb = imgUrl ? `<img src="${imgUrl}">` : ICON_IMG;
    wrap.innerHTML = `
        <div class="fc-row-content">
            <div class="fc-row-strip">
                <span class="row-badge">Parola</span>
                <span class="strip-spacer"></span>
                <button type="button" class="row-del-btn" onclick="deleteRow(this)" aria-label="Elimina">${ICON_TRASH}</button>
            </div>
            <div class="row-bilingual">
                <div class="row-col col-en">
                    <div class="row-eyebrow">🇬🇧 English</div>
                    <input type="text" class="row-en" value="${escAttr(en)}" placeholder="parola">
                </div>
                <div class="row-col col-it">
                    <div class="row-eyebrow">🇮🇹 Italiano</div>
                    <input type="text" class="row-it" value="${escAttr(it)}" placeholder="traduzione">
                </div>
            </div>
            <div class="row-field">
                <div class="field-label">Significato</div>
                <input type="text" class="row-meaning" value="${escAttr(meaning)}" placeholder="breve spiegazione">
            </div>
            <div class="row-field">
                <div class="field-label">Esempio</div>
                <input type="text" class="row-example" value="${escAttr(example || '_')}" placeholder="frase con _ al posto della parola">
            </div>
            <div class="media-row">
                <label class="media-chip img-chip ${imgUrl ? 'has-img' : ''}">
                    <input type="file" accept="image/*" onchange="previewRowImage(this, '${id}')">
                    <span class="chip-thumb" id="img-preview-${id}">${thumb}</span>
                    <span class="img-name-tag">${imgName ? escAttr(imgName) : 'Immagine'}</span>
                </label>
            </div>
            <input type="hidden" class="row-img-name" value="${imgName ? escAttr(imgName) : ''}">
        </div>`;
    list.appendChild(wrap);
}

function previewRowImage(input, id){
    if (input.files && input.files[0]){
        const reader = new FileReader();
        reader.onload = function(e){ document.getElementById('img-preview-' + id).innerHTML = `<img src="${e.target.result}">`; };
        reader.readAsDataURL(input.files[0]);
        const chip = input.closest('.img-chip');
        chip.classList.add('has-img');
        chip.querySelector('.img-name-tag').textContent = input.files[0].name;
        // Ora l'immagine di questa riga arriva dal file caricato direttamente qui,
        // non più dal pool "Immagini multiple" abbinato per nome file.
        const rowContent = input.closest('.fc-row-content');
        const hidden = rowContent ? rowContent.querySelector('.row-img-name') : null;
        if (hidden) hidden.value = '';
    }
}

function deleteRow(btn){
    const row = btn.closest('.fc-row');
    row.style.transition = 'opacity .25s var(--spring), transform .25s var(--spring)';
    row.style.opacity = '0';
    row.style.transform = 'scale(.95)';
    setTimeout(() => { row.remove(); updateGenerateButtonState(); }, 250);
}

/* ============================================================
   CONTATORI / STATO PULSANTI
   ============================================================ */
function updateRowCountChip(){
    const n = document.querySelectorAll('#fcRows .fc-row').length;
    const chip = document.getElementById('row-count-chip');
    if (chip) chip.textContent = n + (n === 1 ? ' parola' : ' parole');
}
function toggleEmptyHint(){
    const hint = document.getElementById('emptyHint');
    if (hint) hint.style.display = document.getElementById('fcRows').children.length ? 'none' : 'block';
}
function updateGenerateButtonState(){
    const list = document.getElementById('fcRows');
    const btn = document.getElementById('btn-generate-table');
    if (list.children.length > 0){
        btn.classList.remove('btn-warn'); btn.classList.add('btn-flat-warn');
        btn.innerHTML = '⚠️ Tabella piena — tocca per ricreare';
    } else {
        btn.classList.add('btn-warn'); btn.classList.remove('btn-flat-warn');
        btn.innerHTML = ICON_GEN + ' Genera Tabella Dati';
    }
    updateRowCountChip();
    toggleEmptyHint();
    document.getElementById('submitBtn').disabled = list.children.length === 0;
}
updateGenerateButtonState();

/* ============================================================
   INVIO (AJAX)
   ============================================================ */
document.getElementById('bigForm').addEventListener('submit', async function(e){
    e.preventDefault();

    const catId = document.getElementById('categoryInput').value;
    if (!catId || catId === '0') { showToast('Scegli prima una categoria', 'error'); openCatSheet(); return; }

    const rows = document.querySelectorAll('#fcRows .fc-row');
    if (rows.length === 0) { showToast('Aggiungi almeno una parola', 'error'); return; }

    const subBtn = document.getElementById('submitBtn');
    const originalHTML = subBtn.innerHTML;
    subBtn.disabled = true;
    subBtn.innerHTML = 'Salvataggio in corso…';

    const fd = new FormData();
    fd.append('action', 'insert_bulk');
    fd.append('category_id', catId);

    rows.forEach((row, i) => {
        fd.append('en_desc[]', row.querySelector('.row-en').value);
        fd.append('it_desc[]', row.querySelector('.row-it').value);
        fd.append('meaning[]', row.querySelector('.row-meaning').value);
        fd.append('example[]', row.querySelector('.row-example').value);
        fd.append('img_name[]', row.querySelector('.row-img-name').value);
        const fileInput = row.querySelector('input[type=file]');
        if (fileInput && fileInput.files[0]) {
            fd.append('row_img[' + i + ']', fileInput.files[0]);
        }
    });

    const bulkFiles = document.getElementById('bulk_images').files;
    for (let i = 0; i < bulkFiles.length; i++) { fd.append('bulk_images[]', bulkFiles[i]); }

    try {
        const res = await fetch('inserimento_big.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            document.getElementById('success-count').textContent = data.count;
            document.getElementById('success-msg').style.display = 'block';
            window.scrollTo({ top: 0, behavior: 'smooth' });
            setTimeout(() => { window.location.href = 'visualizzazione.php?category_id=' + data.category_id; }, 2200);
        } else {
            showToast('Errore: ' + (data.error || 'sconosciuto'), 'error');
            subBtn.disabled = false;
            subBtn.innerHTML = originalHTML;
        }
    } catch (err) {
        showToast('Errore di connessione', 'error');
        subBtn.disabled = false;
        subBtn.innerHTML = originalHTML;
    }
});
</script>

</body>
</html>