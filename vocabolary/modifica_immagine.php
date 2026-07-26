<?php
require_once 'config.php';
require_once 'auth_check.php';
$current_user_id = $utente_corrente['id'];

$conn = mysqli_connect(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
if (!$conn) { die("Connessione fallita: " . mysqli_connect_error()); }

$categories = array();
$cq = mysqli_query($conn, "SELECT id, name FROM categories WHERE user_id = $current_user_id ORDER BY name");
if ($cq) { while ($row = mysqli_fetch_assoc($cq)) { $categories[$row['id']] = $row['name']; } }

// ============================================================
// SALVATAGGIO / ELIMINAZIONE (AJAX)
// ============================================================
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && (isset($_POST['image_id']) || isset($_POST['child_id']))) {
    header('Content-Type: application/json');

    $is_child = isset($_POST['child_id']);
    $id = $is_child ? (int)$_POST['child_id'] : (int)$_POST['image_id'];
    $table = $is_child ? "english_child_words" : "images";
    $id_col = $is_child ? "id_child" : "id";

    // --- SICUREZZA: verifica proprietà passando dalla categoria (via padre, se figlio) ---
    if ($is_child) {
        $check_q = "SELECT c.user_id, i.category_id FROM english_child_words ch
                    JOIN images i ON ch.parent_id = i.id
                    JOIN categories c ON i.category_id = c.id
                    WHERE ch.id_child = $id";
    } else {
        $check_q = "SELECT c.user_id, i.category_id FROM images i
                    JOIN categories c ON i.category_id = c.id
                    WHERE i.id = $id";
    }
    $check_res = mysqli_query($conn, $check_q);
    $check_row = $check_res ? mysqli_fetch_assoc($check_res) : null;
    if (!$check_row || (int)$check_row['user_id'] !== $current_user_id) {
        echo json_encode(['success' => false, 'error' => 'Non sei autorizzato a modificare questa parola.']);
        mysqli_close($conn);
        exit;
    }
    $prevCatId = (int)$check_row['category_id'];

    $action = $_POST['action'];

    // --- ELIMINAZIONE ---
    if ($action === 'delete') {
        $q_old = mysqli_query($conn, "SELECT image FROM $table WHERE $id_col = $id");
        $old = $q_old ? mysqli_fetch_assoc($q_old) : null;
        if ($old && !empty($old['image']) && file_exists(IMAGE_PATH_INSERT . $old['image'])) {
            unlink(IMAGE_PATH_INSERT . $old['image']);
        }
        mysqli_query($conn, "DELETE FROM $table WHERE $id_col = $id");
        echo json_encode(['success' => true, 'category_id' => $prevCatId]);
        mysqli_close($conn);
        exit;
    }

    // --- SALVATAGGIO ---
    $catID = $is_child ? $prevCatId : (isset($_POST['category']) ? (int)$_POST['category'] : $prevCatId);

    // Se il padre sta cambiando categoria, verifica che la nuova appartenga anch'essa all'utente
    if (!$is_child && $catID !== $prevCatId) {
        $cat_check = mysqli_query($conn, "SELECT user_id FROM categories WHERE id = $catID");
        $cat_row = $cat_check ? mysqli_fetch_assoc($cat_check) : null;
        if (!$cat_row || (int)$cat_row['user_id'] !== $current_user_id) {
            echo json_encode(['success' => false, 'error' => 'Non sei autorizzato sulla categoria di destinazione.']);
            mysqli_close($conn);
            exit;
        }
    }

    $description_en = ucfirst(str_replace("'", "’", trim($_POST['description_en'] ?? '')));
    $description_it = ucfirst(str_replace("'", "’", trim($_POST['description_it'] ?? '')));
    $meaning        = ucfirst(str_replace("'", "’", trim($_POST['meaning'] ?? '')));
    $example        = ucfirst(str_replace("'", "’", trim($_POST['example'] ?? '')));

    $q_old2 = mysqli_query($conn, "SELECT image FROM $table WHERE $id_col = $id");
    $old2 = $q_old2 ? mysqli_fetch_assoc($q_old2) : null;

    $imgField = "image = image"; // default: non toccare
    if (isset($_POST['remove_image']) && $_POST['remove_image'] == '1') {
        if ($old2 && !empty($old2['image']) && file_exists(IMAGE_PATH_INSERT . $old2['image'])) { unlink(IMAGE_PATH_INSERT . $old2['image']); }
        $imgField = "image = ''";
    } elseif (isset($_POST['replace_image']) && $_POST['replace_image'] == '1' && isset($_FILES['image']) && $_FILES['image']['name'] != '') {
        $newImg = time() . "_" . $_FILES['image']['name'];
        move_uploaded_file($_FILES['image']['tmp_name'], IMAGE_PATH_INSERT . $newImg);
        if ($old2 && !empty($old2['image']) && file_exists(IMAGE_PATH_INSERT . $old2['image'])) { unlink(IMAGE_PATH_INSERT . $old2['image']); }
        $imgEsc = mysqli_real_escape_string($conn, $newImg);
        $imgField = "image = '$imgEsc'";
    }

    $enEsc   = mysqli_real_escape_string($conn, $description_en);
    $itEsc   = mysqli_real_escape_string($conn, $description_it);
    $meanEsc = mysqli_real_escape_string($conn, $meaning);
    $exEsc   = mysqli_real_escape_string($conn, $example);

    if ($is_child) {
        $query = "UPDATE $table SET $imgField, description_it = '$itEsc', description_en = '$enEsc', meaning = '$meanEsc', example = '$exEsc' WHERE $id_col = $id";
    } else {
        $query = "UPDATE $table SET $imgField, description_it = '$itEsc', description_en = '$enEsc', meaning = '$meanEsc', example = '$exEsc', category_id = $catID WHERE $id_col = $id";
    }

    if (mysqli_query($conn, $query)) {
        echo json_encode(['success' => true, 'category_id' => $catID]);
    } else {
        echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
    }
    mysqli_close($conn);
    exit;
}

// Lista indentata per il foglio "Categoria" (stessa estetica di inserimento.php)
function edit_render_cat_list($parentId, $all_cats_flat, $selected_id) {
    // qui usiamo una lista piatta (id => name) perché la tabella categories supporta
    // padre/figlio ma per semplicità di selezione mostriamo tutte in ordine alfabetico
    foreach ($all_cats_flat as $id => $name) {
        $nameEsc = htmlspecialchars($name, ENT_QUOTES);
        echo "<button type='button' class='cat-pick-item' data-id='{$id}' data-name=\"" . htmlspecialchars($name, ENT_QUOTES) . "\" onclick='selezionaCategoria({$id}, this)'>";
        echo "<span class='cat-pick-dot' style='background:#A0A0A0'></span>";
        echo "<span class='cat-pick-name'>{$nameEsc}</span>";
        echo "</button>";
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<title>Modifica Parola · Vocabolario</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="mobile-web-app-capable" content="yes">
<meta name="theme-color" content="#F2F2F7" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#000000" media="(prefers-color-scheme: dark)">
<style>
/* ================================================================
   DESIGN SYSTEM — stesso linguaggio visivo di inserimento.php
   ================================================================ */
:root{
    color-scheme: light dark;
    --bg:#F2F2F7; --surface:#FFFFFF; --surface-2:#F2F2F7; --surface-3:#EBEBF0;
    --text:#1C1C1E; --text-2:#8A8A8E; --text-3:#AEAEB2;
    --hairline:rgba(60,60,67,.12); --hairline-2:rgba(60,60,67,.22);
    --blue:#007AFF; --green:#34C759; --red:#FF3B30; --orange:#FF9500; --purple:#AF52DE; --indigo:#5856D6;
    --blue-soft:rgba(0,122,255,.09); --red-soft:rgba(255,59,48,.12); --green-soft:rgba(52,199,89,.14); --purple-soft:rgba(175,82,222,.12);
    --g-fill:#E7F8ED; --g-ink:#1E8A44; --g-line:rgba(52,199,89,.85);
    --r-fill:#FDECEC; --r-ink:#D70015; --r-line:rgba(255,59,48,.8);
    --w-fill:#FFF3E0; --w-ink:#C77700; --w-line:rgba(255,149,0,.7);
    --p-fill:#F5E9FB; --p-ink:#8E24AA; --p-line:rgba(175,82,222,.55);
    --chip-bg:#FFFFFF;
    --btn-hl:inset 0 1px 0 rgba(255,255,255,.25), inset 0 -1px 0 rgba(0,0,0,.16);
    --raise-sm:0 1px 1.5px rgba(0,0,0,.08), 0 5px 12px -4px rgba(0,0,0,.13);
    --pill-hl:inset 0 0 0 .5px var(--hairline);
    --shadow-card:0 1px 1px rgba(0,0,0,.04), 0 10px 30px rgba(0,0,0,.07);
    --header-glass:rgba(249,249,251,.82);
    --spring:cubic-bezier(.32,.72,0,1);
    --r-card:20px; --r-field:13px; --r-chip:100px;
    --safe-top:env(safe-area-inset-top, 0px);
    --safe-bottom:env(safe-area-inset-bottom, 0px);
}
@media (prefers-color-scheme: dark){
    :root{
        --bg:#000000; --surface:#1C1C1E; --surface-2:#2C2C2E; --surface-3:#3A3A3C;
        --text:#F5F5F7; --text-2:#98989F; --text-3:#636366;
        --hairline:rgba(84,84,88,.42); --hairline-2:rgba(84,84,88,.62);
        --blue:#0A84FF; --green:#30D158; --red:#FF453A; --orange:#FF9F0A; --purple:#BF5AF2; --indigo:#5E5CE6;
        --blue-soft:rgba(10,132,255,.16); --red-soft:rgba(255,69,58,.17); --green-soft:rgba(48,209,88,.2); --purple-soft:rgba(191,90,242,.18);
        --g-fill:rgba(48,209,88,.15); --g-ink:#4CE07E; --g-line:rgba(48,209,88,.75);
        --r-fill:rgba(255,69,58,.16); --r-ink:#FF6F68; --r-line:rgba(255,69,58,.75);
        --w-fill:rgba(255,159,10,.16); --w-ink:#FFB340; --w-line:rgba(255,159,10,.65);
        --p-fill:rgba(191,90,242,.16); --p-ink:#D68CFF; --p-line:rgba(191,90,242,.55);
        --chip-bg:#3A3A3C;
        --btn-hl:inset 0 1px 0 rgba(255,255,255,.22), inset 0 -1px 0 rgba(0,0,0,.3);
        --raise-sm:0 1px 1.5px rgba(0,0,0,.45), 0 5px 12px -4px rgba(0,0,0,.55);
        --pill-hl:inset 0 1px 0 rgba(255,255,255,.08), inset 0 0 0 .5px var(--hairline);
        --shadow-card:0 1px 1px rgba(0,0,0,.35), 0 10px 30px rgba(0,0,0,.5);
        --header-glass:rgba(16,16,18,.78);
    }
}
*{ box-sizing:border-box; -webkit-tap-highlight-color:transparent; }
html{ -webkit-text-size-adjust:100%; }
body{
    margin:0; background:var(--bg); color:var(--text);
    font-family:-apple-system, BlinkMacSystemFont, "SF Pro Text", "SF Pro Display", system-ui, "Segoe UI", Roboto, sans-serif;
    font-size:16px; line-height:1.45; -webkit-font-smoothing:antialiased;
}
a{ color:var(--blue); }
:focus-visible{ outline:2px solid var(--blue); outline-offset:2px; border-radius:8px; }

.app-header{
    position:sticky; top:0; z-index:400;
    padding:calc(var(--safe-top) + 12px) 16px 12px; text-align:center;
    background:var(--header-glass);
    -webkit-backdrop-filter:saturate(1.8) blur(22px); backdrop-filter:saturate(1.8) blur(22px);
    border-bottom:.5px solid var(--hairline);
}
.hd-eyebrow{ font-size:10.5px; font-weight:800; letter-spacing:.14em; text-transform:uppercase; color:var(--text-3); }
h1{ margin:2px 0 0; font-size:20px; font-weight:800; letter-spacing:-.02em; color:var(--text); }
.child-badge{ display:inline-flex; align-items:center; background:var(--orange); color:#fff; padding:3px 10px; border-radius:99px; font-size:.62em; vertical-align:middle; margin-left:6px; font-weight:800; }

.page{ max-width:620px; margin:0 auto; padding:16px 14px calc(130px + var(--safe-bottom)); }
.empty-state{ text-align:center; padding:60px 20px; color:var(--text-2); font-size:15px; }

.fc-card{ background:var(--surface); border-radius:var(--r-card); border:.5px solid var(--hairline); box-shadow:var(--shadow-card); padding:16px; margin-bottom:14px; }
.fc-card-tight{ padding:12px 16px; }
.fc-card-head{ display:flex; align-items:center; justify-content:space-between; gap:10px; margin-bottom:12px; }
.fc-card-title{ font-size:15.5px; font-weight:800; letter-spacing:-.01em; color:var(--text); }
.field-label{ font-size:10.5px; font-weight:800; letter-spacing:.06em; text-transform:uppercase; color:var(--text-3); margin:0 0 6px; display:block; }

textarea, select, input[type="text"]{
    width:100%; box-sizing:border-box; font-family:inherit; color:var(--text);
    background:var(--surface-2); border:1px solid var(--hairline-2); border-radius:var(--r-field);
    padding:12px 13px; font-size:16px; resize:vertical;
}
textarea:focus, select:focus, input:focus{ outline:none; border-color:var(--blue); }
input[type="text"]::placeholder{ color:var(--text-3); }

/* Bilingue */
.bilingual-grid{ display:grid; grid-template-columns:1fr 1fr; border-radius:16px; overflow:hidden; box-shadow:inset 0 0 0 .5px var(--hairline); }
.bilingual-col{ padding:12px 13px; background:var(--surface-2); min-width:0; }
.bilingual-col.col-en{ border-right:.5px solid var(--hairline); background:var(--blue-soft); }
.bilingual-eyebrow{ display:flex; align-items:center; gap:5px; font-size:10px; font-weight:800; letter-spacing:.07em; text-transform:uppercase; color:var(--text-3); margin-bottom:6px; }
.col-en .bilingual-eyebrow{ color:var(--blue); }
.bilingual-col input[type="text"]{ background:var(--surface); border-color:transparent; box-shadow:inset 0 0 0 1px var(--hairline-2); padding:10px 11px; font-weight:700; font-size:15.5px; }
.bilingual-col input[type="text"]:focus{ box-shadow:inset 0 0 0 1.5px var(--blue); }
@media (max-width:380px){ .bilingual-grid{ grid-template-columns:1fr; } .bilingual-col.col-en{ border-right:none; border-bottom:.5px solid var(--hairline); } }

.field-hint{ margin:8px 2px 0; font-size:12px; font-weight:600; color:var(--text-3); }
.field-hint.err{ color:var(--r-ink); display:none; align-items:center; gap:5px; }
.field-hint.err.show{ display:flex; }
.field-hint.err svg{ width:13px; height:13px; flex-shrink:0; }
#example.err-state{ border-color:var(--r-line); }

/* Immagini */
.img-compare{ display:flex; gap:10px; margin-bottom:10px; }
.img-box{ flex:1; min-width:0; border-radius:14px; padding:10px; text-align:center; background:var(--surface-2); box-shadow:inset 0 0 0 .5px var(--hairline); }
.img-box-new{ box-shadow:inset 0 0 0 1.5px rgba(0,122,255,.4); background:var(--blue-soft); }
.img-tag{ display:block; font-size:10.5px; font-weight:800; letter-spacing:.06em; text-transform:uppercase; color:var(--text-3); margin-bottom:6px; }
.img-tag-new{ color:var(--blue); }
.img-box img{ max-width:100%; max-height:96px; border-radius:9px; object-fit:contain; display:block; margin:0 auto; }
.img-empty{ color:var(--text-3); font-size:12.5px; font-style:italic; padding:18px 4px; }
.img-empty-warn{ color:var(--r-ink); }
.file-row{ margin-bottom:10px; }
.file-btn{ display:inline-flex; align-items:center; gap:7px; cursor:pointer; background:var(--surface-2); color:var(--text); font-weight:700; font-size:13.5px; padding:9px 14px; border-radius:11px; box-shadow:inset 0 0 0 .5px var(--hairline); transition:transform .15s var(--spring); }
.file-btn:active{ transform:scale(.96); }
.control-panel{ display:flex; flex-direction:column; gap:9px; }
.chk-row{ display:flex; align-items:center; gap:9px; font-size:13.5px; font-weight:600; cursor:pointer; color:var(--text); }
.chk-row input[type="checkbox"]{ width:19px; height:19px; accent-color:var(--blue); flex-shrink:0; margin:0; }
.chk-blue{ color:var(--blue); }
.chk-red{ color:var(--r-ink); }
.chk-red input[type="checkbox"]{ accent-color:var(--red); }

/* Assistente IA — stesso stile di inserimento.php */
.ai-card{ background:linear-gradient(180deg, var(--surface), color-mix(in srgb, var(--indigo) 4%, var(--surface))); border:.5px solid var(--hairline); }
.ai-card-head{ display:flex; align-items:center; gap:10px; margin-bottom:14px; }
.ai-card-ic{ width:32px; height:32px; border-radius:10px; flex-shrink:0; background:color-mix(in srgb, var(--indigo) 14%, var(--surface)); color:var(--indigo); display:flex; align-items:center; justify-content:center; }
.ai-card-ic svg{ width:16px; height:16px; }
.ai-card-title{ font-size:15.5px; font-weight:800; letter-spacing:-.01em; color:var(--text); }
.ai-card-sub{ font-size:12px; font-weight:600; color:var(--text-2); margin-top:1px; }
.ai-toggles{ display:flex; gap:8px; margin-top:12px; }
.ai-toggle{ flex:1; display:flex; align-items:center; justify-content:center; gap:7px; padding:9px 10px; border-radius:12px; background:var(--surface-2); box-shadow:inset 0 0 0 .5px var(--hairline); font-size:12.5px; font-weight:700; color:var(--text-2); cursor:pointer; transition:background .2s, color .2s, box-shadow .2s; -webkit-user-select:none; user-select:none; }
.ai-toggle input{ display:none; }
.ai-toggle svg{ width:14px; height:14px; flex-shrink:0; opacity:.55; transition:opacity .2s; }
.ai-toggle.on{ background:color-mix(in srgb, var(--indigo) 14%, var(--surface)); color:var(--indigo); box-shadow:inset 0 0 0 1px color-mix(in srgb, var(--indigo) 45%, transparent); }
.ai-toggle.on svg{ opacity:1; }
.ai-action{ margin-top:16px; padding-top:14px; border-top:.5px solid var(--hairline); }
.ai-action-label{ font-size:12.5px; font-weight:700; color:var(--text-2); margin-bottom:9px; }
.ai-btn-pair{ display:flex; gap:8px; }
.ai-btn{ flex:1; display:flex; align-items:center; justify-content:center; gap:6px; padding:11px 10px; border:none; border-radius:12px; cursor:pointer; font-family:inherit; font-size:13px; font-weight:800; letter-spacing:-.005em; transition:transform .15s var(--spring), filter .2s, opacity .2s; }
.ai-btn:active{ transform:scale(.96); }
.ai-btn:disabled{ opacity:.6; pointer-events:none; }
.ai-btn svg{ width:15px; height:15px; flex-shrink:0; }
.ai-btn-pro{ color:#fff; background:linear-gradient(180deg, color-mix(in srgb, var(--indigo), #fff 16%), var(--indigo) 55%, color-mix(in srgb, var(--indigo), #000 12%)); box-shadow:var(--btn-hl), 0 6px 16px -6px rgba(88,86,214,.55); }
.ai-btn-basic{ color:var(--text); background:var(--surface-2); box-shadow:inset 0 0 0 .5px var(--hairline); }
.ai-btn-badge{ font-size:9px; font-weight:900; letter-spacing:.06em; background:rgba(255,255,255,.24); padding:2px 6px; border-radius:99px; }
.ai-spinner{ width:13px; height:13px; border-radius:50%; border:2px solid rgba(255,255,255,.35); border-top-color:#fff; animation:spin .7s linear infinite; flex-shrink:0; }
.ai-btn-basic .ai-spinner{ border:2px solid var(--hairline-2); border-top-color:var(--text-2); }
@keyframes spin{ to{ transform:rotate(360deg); } }

/* Categoria */
.cat-btn{ width:100%; display:flex; align-items:center; justify-content:space-between; gap:10px; background:var(--surface-2); border:none; border-radius:13px; padding:14px 15px; cursor:pointer; font-family:inherit; font-size:15px; font-weight:700; color:var(--text); box-shadow:inset 0 0 0 .5px var(--hairline); transition:transform .15s var(--spring); }
.cat-btn:active{ transform:scale(.98); }
.cb-left{ display:flex; align-items:center; gap:10px; min-width:0; }
.cb-ic{ width:32px; height:32px; border-radius:9px; flex-shrink:0; background:var(--surface); box-shadow:var(--pill-hl), var(--raise-sm); display:flex; align-items:center; justify-content:center; color:var(--blue); }
.cb-ic svg{ width:15px; height:15px; }
#categoryBtnLabel{ white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.cb-chev{ color:var(--text-3); flex-shrink:0; display:flex; }
.cb-chev svg{ width:16px; height:16px; }
.inherited-note{ text-align:center; padding:14px; border-radius:13px; background:var(--surface-2); color:var(--text-2); font-size:13.5px; font-style:italic; box-shadow:inset 0 0 0 .5px var(--hairline); }

/* Barra azioni */
.bottom-bar{ position:fixed; left:0; right:0; bottom:0; z-index:500; padding:12px 16px calc(10px + var(--safe-bottom)); background:var(--header-glass); -webkit-backdrop-filter:saturate(1.8) blur(22px); backdrop-filter:saturate(1.8) blur(22px); border-top:.5px solid var(--hairline); }
.bottom-bar-row{ display:flex; gap:10px; max-width:620px; margin:0 auto; }
.btn-cancel{ flex:0 0 auto; display:flex; align-items:center; justify-content:center; padding:15px 18px; border:none; border-radius:15px; cursor:pointer; font-family:inherit; font-size:15.5px; font-weight:700; color:var(--text-2); background:var(--chip-bg); box-shadow:var(--pill-hl), var(--raise-sm); transition:transform .15s var(--spring); }
.btn-cancel:active{ transform:scale(.96); }
.btn-save{ flex:1; display:flex; align-items:center; justify-content:center; gap:8px; padding:15px; border:none; border-radius:15px; cursor:pointer; font-family:inherit; font-size:16px; font-weight:800; letter-spacing:-.01em; color:#fff; background:linear-gradient(180deg, color-mix(in srgb, var(--green), #fff 16%), var(--green) 52%, color-mix(in srgb, var(--green), #000 10%)); box-shadow:var(--btn-hl), 0 1px 2px rgba(0,0,0,.14), 0 12px 26px -8px rgba(52,199,89,.55); transition:transform .16s var(--spring), filter .2s; }
.btn-save:active{ transform:translateY(1px) scale(.99); filter:brightness(.97); }
.btn-save:disabled{ opacity:.7; }
.btn-delete-link{ display:block; width:100%; text-align:center; background:none; border:none; color:var(--r-ink); font-family:inherit; font-size:13.5px; font-weight:700; padding:10px 0 0; cursor:pointer; }
.max-wrap{ max-width:620px; margin:0 auto; }

.alert-banner{ display:flex; align-items:center; gap:8px; background:var(--r-fill); color:var(--r-ink); border-radius:13px; padding:11px 14px; font-size:13px; font-weight:700; margin-bottom:10px; box-shadow:inset 0 0 0 1px var(--r-line); animation:bannerIn .32s var(--spring); }
.alert-banner svg{ flex-shrink:0; }
@keyframes bannerIn{ from{ opacity:0; transform:translateY(6px); } }
@keyframes shakeX{ 10%,90%{ transform:translateX(-1px); } 20%,80%{ transform:translateX(2px); } 30%,50%,70%{ transform:translateX(-4px); } 40%,60%{ transform:translateX(4px); } }
.shake{ animation:shakeX .5s; }

.success-toast{ position:fixed; top:calc(var(--safe-top) + 10px); left:50%; transform:translate(-50%,-14px); background:var(--g-fill); color:var(--g-ink); padding:11px 20px; border-radius:99px; font-weight:800; font-size:13.5px; box-shadow:var(--shadow-card), inset 0 0 0 1px var(--g-line); z-index:1000; display:flex; align-items:center; gap:8px; opacity:0; animation:toastIn .42s var(--spring) forwards, toastOut .4s var(--spring) 2.8s forwards; }
@keyframes toastIn{ to{ opacity:1; transform:translate(-50%,0); } }
@keyframes toastOut{ to{ opacity:0; transform:translate(-50%,-14px); } }

.modal-overlay{ display:none; position:fixed; inset:0; z-index:900; background:rgba(0,0,0,.5); -webkit-backdrop-filter:blur(6px); backdrop-filter:blur(6px); justify-content:center; align-items:flex-end; }
.modal-overlay.show{ display:flex; }
.modal-content{ background:var(--surface); color:var(--text); padding:22px 20px calc(24px + var(--safe-bottom)); border-radius:26px 26px 0 0; max-width:540px; width:100%; position:relative; max-height:80vh; overflow-y:auto; box-shadow:0 -12px 50px rgba(0,0,0,.4); animation:sheetUp .42s var(--spring) both; }
.modal-content::before{ content:""; position:absolute; top:9px; left:50%; transform:translateX(-50%); width:38px; height:5px; border-radius:99px; background:var(--hairline-2); }
.modal-title{ margin:6px 0 14px; font-size:19px; font-weight:800; letter-spacing:-.02em; text-align:center; }
@keyframes sheetUp{ from{ transform:translateY(60%); opacity:.4; } }
@media (min-width:640px){ .modal-overlay{ align-items:center; padding:20px; } .modal-content{ border-radius:26px; } }
.cat-pick-list{ display:flex; flex-direction:column; gap:6px; max-height:56vh; overflow-y:auto; }
.cat-pick-item{ display:flex; align-items:center; gap:10px; padding:12px 14px; border-radius:14px; cursor:pointer; background:var(--surface-2); border:none; width:100%; text-align:left; font-family:inherit; transition:transform .15s var(--spring); }
.cat-pick-item:active{ transform:scale(.98); }
.cat-pick-dot{ width:12px; height:12px; border-radius:50%; flex-shrink:0; }
.cat-pick-name{ font-size:15px; font-weight:700; color:var(--text); }

.confirm-body{ text-align:center; font-size:14.5px; color:var(--text-2); line-height:1.5; margin:0 0 18px; }
.confirm-actions{ display:flex; gap:10px; }
.confirm-actions button{ flex:1; padding:14px; border:none; border-radius:14px; font-family:inherit; font-size:15px; font-weight:800; cursor:pointer; }
.confirm-actions .btn-ghost{ background:var(--surface-2); color:var(--text); }
.confirm-actions .btn-danger{ background:linear-gradient(180deg, color-mix(in srgb, var(--red), #fff 12%), var(--red)); color:#fff; }

.ai-err-panel{ text-align:center; }
.ai-err-icon{ width:56px; height:56px; margin:6px auto 14px; border-radius:16px; display:flex; align-items:center; justify-content:center; }
.ai-err-icon.tone-warn{ background:var(--w-fill); color:var(--w-ink); }
.ai-err-icon.tone-plan{ background:var(--p-fill); color:var(--p-ink); }
.ai-err-icon.tone-error{ background:var(--r-fill); color:var(--r-ink); }
.ai-err-icon svg{ width:26px; height:26px; }
.ai-err-title{ margin:0 0 8px; font-size:18.5px; font-weight:800; letter-spacing:-.01em; }
.ai-err-body{ margin:0; font-size:14.5px; color:var(--text-2); line-height:1.5; }
.ai-err-detail{ margin:12px 0 0; font-size:11.5px; color:var(--text-3); font-family:ui-monospace,SFMono-Regular,Menlo,monospace; background:var(--surface-2); padding:8px 10px; border-radius:9px; text-align:left; word-break:break-word; }
.ai-err-actions{ display:flex; gap:10px; margin-top:20px; }
.ai-err-actions .btn-cancel, .ai-err-actions .btn-save{ flex:1; padding:13px; box-shadow:none; }
.ai-err-actions .btn-save{ background:linear-gradient(180deg, color-mix(in srgb, var(--indigo), #fff 16%), var(--indigo) 52%, color-mix(in srgb, var(--indigo), #000 10%)); box-shadow:var(--btn-hl), 0 1px 2px rgba(0,0,0,.14), 0 10px 22px -8px rgba(88,86,214,.5); }

@media (prefers-reduced-motion: reduce){ *, *::before, *::after{ animation-duration:.01ms !important; transition-duration:.01ms !important; } }
</style>
</head>
<body>

<?php include 'sidebar.html'; ?>

<?php
// ============================================================
// CARICAMENTO RECORD (GET, con verifica proprietà)
// ============================================================
if (isset($_GET['image_id']) || isset($_GET['child_id'])) {

    $is_child = isset($_GET['child_id']);
    $id = $is_child ? (int)$_GET['child_id'] : (int)$_GET['image_id'];
    $prev_cat = isset($_GET['previous_catid']) ? (int)$_GET['previous_catid'] : 0;

    if ($is_child) {
        $query_get = "SELECT t.*, i.category_id AS parent_category_id FROM english_child_words t
                      JOIN images i ON t.parent_id = i.id
                      JOIN categories c ON i.category_id = c.id
                      WHERE t.id_child = $id AND c.user_id = $current_user_id";
    } else {
        $query_get = "SELECT t.* FROM images t
                      JOIN categories c ON t.category_id = c.id
                      WHERE t.id = $id AND c.user_id = $current_user_id";
    }
    $res = mysqli_query($conn, $query_get);
    $row = $res ? mysqli_fetch_assoc($res) : null;

    if ($row) {
        $category_id = $is_child ? (int)$row['parent_category_id'] : (int)$row['category_id'];
        $cancel_url = "visualizzazione.php?category_id=" . $category_id;
        ?>
        <div class="app-header">
            <div class="hd-eyebrow">MODIFICA PAROLA</div>
            <h1>Vocabolario<?php if ($is_child) echo ' <span class="child-badge">↳ Figlio</span>'; ?></h1>
        </div>

        <div class="page">
            <form id="editForm" enctype="multipart/form-data">
                <?php if ($is_child): ?>
                    <input type="hidden" name="child_id" value="<?php echo $id; ?>">
                <?php else: ?>
                    <input type="hidden" name="image_id" value="<?php echo $id; ?>">
                <?php endif; ?>
                <input type="hidden" name="previous_catid" value="<?php echo $prev_cat ?: $category_id; ?>">

                <!-- ============== CARD PAROLA ============== -->
                <section class="fc-card">
                    <div class="fc-card-head"><span class="fc-card-title">Parola</span></div>
                    <div class="bilingual-grid">
                        <div class="bilingual-col col-en">
                            <div class="bilingual-eyebrow"><span>🇬🇧</span> English</div>
                            <input type="text" name="description_en" id="description_en" value="<?php echo htmlspecialchars($row['description_en'], ENT_QUOTES); ?>" placeholder="parola in inglese">
                        </div>
                        <div class="bilingual-col col-it">
                            <div class="bilingual-eyebrow"><span>🇮🇹</span> Italiano</div>
                            <input type="text" name="description_it" id="description_it" value="<?php echo htmlspecialchars($row['description_it'], ENT_QUOTES); ?>" placeholder="traduzione">
                        </div>
                    </div>
                </section>

                <!-- ============== CARD ASSISTENTE IA ============== -->
                <section class="fc-card ai-card">
                    <div class="ai-card-head">
                        <span class="ai-card-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v3M12 18v3M4.2 4.2l2.1 2.1M17.7 17.7l2.1 2.1M3 12h3M18 12h3M4.2 19.8l2.1-2.1M17.7 6.3l2.1-2.1"/><circle cx="12" cy="12" r="3.2"/></svg></span>
                        <div>
                            <div class="ai-card-title">Assistente IA</div>
                            <div class="ai-card-sub">Ricompila i campi o genera una nuova immagine</div>
                        </div>
                    </div>

                    <label class="field-label" for="img_directions">Indicazioni aggiuntive (opzionale)</label>
                    <input type="text" id="img_directions" placeholder="es. stile acquerello, rendila divertente…">

                    <div class="ai-toggles">
                        <label class="ai-toggle" id="toggleLinear">
                            <input type="checkbox" id="ai_linear" onchange="syncToggle('toggleLinear', this)">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 17c3-8 6 8 9 0s6-8 9 0"/></svg>
                            Stile lineare
                        </label>
                        <label class="ai-toggle" id="toggleExample">
                            <input type="checkbox" id="ai_example" onchange="syncToggle('toggleExample', this)">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 6h16M4 12h16M4 18h10"/></svg>
                            Usa l'esempio
                        </label>
                    </div>

                    <div class="ai-action">
                        <div class="ai-action-label">Ricompila traduzione, significato ed esempio</div>
                        <div class="ai-btn-pair">
                            <button type="button" class="ai-btn ai-btn-pro" onclick="generateText(this, 'pro')"><span class="ai-btn-badge">PRO</span> Compila</button>
                            <button type="button" class="ai-btn ai-btn-basic" onclick="generateText(this, 'basic')">Compila (Basic)</button>
                        </div>
                    </div>

                    <div class="ai-action">
                        <div class="ai-action-label">Genera una nuova immagine per questa parola</div>
                        <div class="ai-btn-pair">
                            <button type="button" class="ai-btn ai-btn-pro" onclick="generateImage(this, 'pro')"><span class="ai-btn-badge">PRO</span> Genera immagine</button>
                            <button type="button" class="ai-btn ai-btn-basic" onclick="generateImage(this, 'basic')">Genera (Basic)</button>
                        </div>
                    </div>
                </section>

                <!-- ============== CARD IMMAGINE ============== -->
                <section class="fc-card">
                    <div class="fc-card-head"><span class="fc-card-title">Immagine</span></div>
                    <div class="img-compare">
                        <div class="img-box">
                            <span class="img-tag">Attuale</span>
                            <?php if (!empty($row['image'])): ?>
                                <img src="<?php echo IMAGE_PATH_VIEW . '/' . htmlspecialchars($row['image']); ?>">
                            <?php else: ?>
                                <div class="img-empty">Nessuna immagine</div>
                            <?php endif; ?>
                        </div>
                        <div class="img-box img-box-new">
                            <span class="img-tag img-tag-new">Nuova</span>
                            <img id="preview-image" style="display:none;">
                            <div id="no-img-text" class="img-empty img-empty-warn" style="display:none;">Senza immagine</div>
                        </div>
                    </div>
                    <div class="file-row">
                        <input type="file" name="image" id="image" accept="image/*" style="display:none;">
                        <label for="image" class="file-btn">
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 8h3l1.6-2.2h6.8L17 8h3a1 1 0 0 1 1 1v9a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9a1 1 0 0 1 1-1z"/><circle cx="12" cy="13.2" r="3.3"/></svg>
                            Scegli foto
                        </label>
                    </div>
                    <div class="control-panel">
                        <label class="chk-row chk-blue"><input type="checkbox" name="replace_image" id="replace_image" value="1"> Sostituisci immagine</label>
                        <label class="chk-row chk-red"><input type="checkbox" name="remove_image" id="remove_image" value="1" onchange="toggleRemoveImage()"> Rimuovi immagine</label>
                    </div>
                </section>

                <!-- ============== CARD SIGNIFICATO ============== -->
                <section class="fc-card">
                    <div class="fc-card-head"><span class="fc-card-title">Significato</span></div>
                    <input type="text" name="meaning" id="meaning" value="<?php echo htmlspecialchars($row['meaning'], ENT_QUOTES); ?>" placeholder="breve spiegazione in inglese">
                </section>

                <!-- ============== CARD ESEMPIO ============== -->
                <section class="fc-card">
                    <div class="fc-card-head"><span class="fc-card-title">Esempio</span></div>
                    <input type="text" name="example" id="example" value="<?php echo htmlspecialchars($row['example'], ENT_QUOTES); ?>" placeholder="frase con un underscore al posto della parola">
                    <p class="field-hint err" id="example-error-message">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4"/><path d="M12 17h.01"/><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L14.71 3.86a2 2 0 0 0-3.42 0Z"/></svg>
                        Includi almeno un underscore (_) al posto della parola.
                    </p>
                </section>

                <!-- ============== CATEGORIA ============== -->
                <section class="fc-card">
                    <div class="fc-card-head"><span class="fc-card-title">Categoria</span></div>
                    <?php if (!$is_child): ?>
                        <button type="button" class="cat-btn" id="categoryBtn" onclick="openCatSheet()">
                            <span class="cb-left">
                                <span class="cb-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2Z"/></svg></span>
                                <span id="categoryBtnLabel"><?php echo isset($categories[$category_id]) ? htmlspecialchars($categories[$category_id]) : 'Scegli una categoria'; ?></span>
                            </span>
                            <span class="cb-chev"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m9 6 6 6-6 6"/></svg></span>
                        </button>
                        <input type="hidden" name="category" id="categoryInput" value="<?php echo (int)$category_id; ?>">
                    <?php else: ?>
                        <div class="inherited-note">Categoria ereditata dalla parola principale</div>
                    <?php endif; ?>
                </section>

                <!-- ============== BARRA AZIONI ============== -->
                <div class="bottom-bar">
                    <div class="max-wrap">
                        <div id="emptyBanner" class="alert-banner" style="display:none;">
                            <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4"/><path d="M12 17h.01"/><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L14.71 3.86a2 2 0 0 0-3.42 0Z"/></svg>
                            <span>Inserisci almeno la parola inglese e la traduzione.</span>
                        </div>
                    </div>
                    <div class="bottom-bar-row">
                        <button type="button" class="btn-cancel" onclick="window.location.href='<?php echo htmlspecialchars($cancel_url); ?>'">Annulla</button>
                        <button type="submit" id="submitBtn" class="btn-save">Salva Modifiche</button>
                    </div>
                    <div class="max-wrap">
                        <button type="button" class="btn-delete-link" onclick="openDeleteSheet()">Elimina definitivamente</button>
                    </div>
                </div>
            </form>
        </div>
        <?php
    } else {
        echo "<div class='page'><div class='empty-state'>Parola non trovata.</div></div>";
    }
} else {
    echo "<div class='page'><div class='empty-state'>ID mancante.</div></div>";
}
mysqli_close($conn);
?>

<!-- ============== FOGLIO CATEGORIA ============== -->
<div id="catSheet" class="modal-overlay">
    <div class="modal-content">
        <h3 class="modal-title">Scegli una categoria</h3>
        <div class="cat-pick-list">
            <?php if (empty($categories)): ?>
                <div class="cat-pick-empty">Non hai ancora nessuna categoria.</div>
            <?php else: ?>
                <?php edit_render_cat_list(0, $categories, null); ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ============== FOGLIO CONFERMA ELIMINAZIONE ============== -->
<div id="deleteSheet" class="modal-overlay">
    <div class="modal-content">
        <h3 class="modal-title">Eliminare questa parola?</h3>
        <p class="confirm-body">L'operazione non può essere annullata. Anche l'eventuale immagine associata verrà eliminata.</p>
        <div class="confirm-actions">
            <button type="button" class="btn-ghost" onclick="closeDeleteSheet()">Annulla</button>
            <button type="button" class="btn-danger" id="confirmDeleteBtn">Elimina</button>
        </div>
    </div>
</div>

<!-- ============== POPUP ERRORI IA ============== -->
<div id="aiErrorOverlay" class="modal-overlay">
    <div class="modal-content ai-err-panel">
        <div class="ai-err-icon" id="aiErrIcon"></div>
        <h3 class="ai-err-title" id="aiErrTitle">Errore</h3>
        <p class="ai-err-body" id="aiErrBody"></p>
        <p class="ai-err-detail" id="aiErrDetail" style="display:none;"></p>
        <div class="ai-err-actions">
            <button type="button" class="btn-cancel" onclick="closeAiError()">Chiudi</button>
            <button type="button" class="btn-save" id="aiErrRetryBtn" style="display:none;">Riprova</button>
        </div>
    </div>
</div>

<script>
/* ---------- Categoria ---------- */
document.getElementById('catSheet').addEventListener('click', function(e){ if (e.target.id === 'catSheet') closeCatSheet(); });
function openCatSheet(){ document.getElementById('catSheet').classList.add('show'); }
function closeCatSheet(){ document.getElementById('catSheet').classList.remove('show'); }
function selezionaCategoria(id, btn){
    var input = document.getElementById('categoryInput');
    if (input) input.value = id;
    var label = document.getElementById('categoryBtnLabel');
    if (label) label.textContent = btn.dataset.name;
    closeCatSheet();
}

/* ---------- Eliminazione ---------- */
document.getElementById('deleteSheet').addEventListener('click', function(e){ if (e.target.id === 'deleteSheet') closeDeleteSheet(); });
function openDeleteSheet(){ document.getElementById('deleteSheet').classList.add('show'); }
function closeDeleteSheet(){ document.getElementById('deleteSheet').classList.remove('show'); }
document.getElementById('confirmDeleteBtn').addEventListener('click', async function(){
    const btn = this;
    btn.disabled = true;
    btn.textContent = 'Eliminazione…';
    const fd = new FormData();
    fd.append('action', 'delete');
    const form = document.getElementById('editForm');
    const childInput = form.querySelector('input[name="child_id"]');
    const imageInput = form.querySelector('input[name="image_id"]');
    if (childInput) fd.append('child_id', childInput.value);
    if (imageInput) fd.append('image_id', imageInput.value);
    try {
        const res = await fetch('modifica_immagine.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            window.location.href = 'visualizzazione.php?category_id=' + data.category_id;
        } else {
            closeDeleteSheet();
            alert('Errore: ' + (data.error || 'sconosciuto'));
            btn.disabled = false;
            btn.textContent = 'Elimina';
        }
    } catch (err) {
        closeDeleteSheet();
        alert('Errore di connessione.');
        btn.disabled = false;
        btn.textContent = 'Elimina';
    }
});

/* ---------- Anteprima immagine ---------- */
function previewImage(src){
    var preview = document.getElementById('preview-image');
    preview.src = src;
    preview.style.display = 'inline-block';
    document.getElementById('no-img-text').style.display = 'none';
    document.getElementById('replace_image').checked = true;
    document.getElementById('remove_image').checked = false;
}
document.getElementById('image').addEventListener('change', function(event){
    var input = event.target;
    if (input.files && input.files[0]) {
        var reader = new FileReader();
        reader.onload = function (e) { previewImage(e.target.result); };
        reader.readAsDataURL(input.files[0]);
    }
});
function toggleRemoveImage(){
    const isRemoved = document.getElementById('remove_image').checked;
    if (isRemoved) {
        document.getElementById('preview-image').style.display = 'none';
        document.getElementById('no-img-text').style.display = 'block';
        document.getElementById('image').value = '';
        document.getElementById('replace_image').checked = false;
    } else {
        document.getElementById('no-img-text').style.display = 'none';
    }
}

/* ---------- Validazione esempio ---------- */
function validateExampleInput(){
    var exampleInput = document.getElementById('example');
    var exampleValue = exampleInput.value;
    var descriptionEnValue = document.getElementById('description_en').value.trim().toLowerCase();
    if (descriptionEnValue !== '') {
        var idx = exampleValue.toLowerCase().indexOf(descriptionEnValue);
        if (idx !== -1) {
            exampleValue = exampleValue.substring(0, idx) + '_' + exampleValue.substring(idx + descriptionEnValue.length);
        }
    }
    exampleInput.value = exampleValue;
    var isValid = exampleValue.indexOf('_') !== -1 || exampleValue.trim() === '';
    document.getElementById('example-error-message').classList.toggle('show', !isValid);
    exampleInput.classList.toggle('err-state', !isValid);
}
document.getElementById('example').addEventListener('blur', validateExampleInput);

function syncToggle(wrapId, checkbox){ document.getElementById(wrapId).classList.toggle('on', checkbox.checked); }

/* ---------- Invio form (AJAX) ---------- */
function isFormEmpty(){
    var en = document.getElementById('description_en').value.trim();
    var it = document.getElementById('description_it').value.trim();
    return !en || !it;
}
function showEmptyBanner(){
    document.getElementById('emptyBanner').style.display = 'flex';
    var submitBtn = document.getElementById('submitBtn');
    submitBtn.classList.remove('shake');
    void submitBtn.offsetWidth;
    submitBtn.classList.add('shake');
}
document.getElementById('editForm').addEventListener('submit', async function(e){
    e.preventDefault();
    validateExampleInput();
    if (isFormEmpty()) { showEmptyBanner(); return; }
    document.getElementById('emptyBanner').style.display = 'none';

    var subBtn = document.getElementById('submitBtn');
    var originalText = subBtn.innerHTML;
    subBtn.disabled = true;
    subBtn.innerHTML = 'Salvataggio in corso…';

    var fd = new FormData(this);
    fd.append('action', 'update');

    try {
        var res = await fetch('modifica_immagine.php', { method: 'POST', body: fd });
        var data = await res.json();
        if (data.success) {
            window.location.href = 'visualizzazione.php?category_id=' + data.category_id;
        } else {
            alert('Errore: ' + (data.error || 'sconosciuto'));
            subBtn.disabled = false;
            subBtn.innerHTML = originalText;
        }
    } catch (err) {
        alert('Errore di connessione.');
        subBtn.disabled = false;
        subBtn.innerHTML = originalText;
    }
});

/* ---------- Popup errori IA ---------- */
const AI_ERR_ICONS = {
    network: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 9a16 16 0 0 1 22 0M5 13a10 10 0 0 1 14 0M9 17a4 4 0 0 1 6 0"/><line x1="2" y1="2" x2="22" y2="22"/></svg>',
    timeout: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/></svg>',
    server: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4"/><path d="M12 17h.01"/><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L14.71 3.86a2 2 0 0 0-3.42 0Z"/></svg>',
    quota_service: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/></svg>',
    limite_piano: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m12 2 2.9 6.3 6.9.9-5 4.8 1.3 6.8-6.1-3.4-6.1 3.4 1.3-6.8-5-4.8 6.9-.9Z"/></svg>',
    invalid_format: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M9.5 9a2.5 2.5 0 0 1 4.9.7c0 1.6-2.4 1.8-2.4 3.3"/><path d="M12 17h.01"/></svg>',
    no_image: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m4 17 4.5-4.5 3.5 3.5 3-3L20 17"/><line x1="2.5" y1="2.5" x2="21.5" y2="21.5"/></svg>',
    unknown: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4"/><path d="M12 17h.01"/><circle cx="12" cy="12" r="9"/></svg>'
};
const AI_ERR_PRESETS = {
    network:        { title: 'Problema di connessione',        tone: 'warn',  retry: true,  body: 'Non riesco a contattare il server. Controlla la connessione internet e riprova.' },
    timeout:        { title: 'Il servizio non ha risposto',     tone: 'warn',  retry: true,  body: "Il servizio di intelligenza artificiale ci ha messo troppo a rispondere. Può essere un problema temporaneo: riprova tra qualche secondo." },
    server:         { title: 'Errore del servizio IA',          tone: 'warn',  retry: true,  body: 'Il servizio non ha risposto correttamente. Il problema è probabilmente temporaneo: riprova tra poco.' },
    quota_service:  { title: 'Servizio momentaneamente pieno',  tone: 'warn',  retry: true,  body: 'Il servizio IA ha raggiunto il limite di richieste in questo momento. Riprova tra qualche minuto.' },
    limite_piano:   { title: 'Limite del piano raggiunto',      tone: 'plan',  retry: false, body: null },
    invalid_format: { title: 'Risposta in formato imprevisto',  tone: 'warn',  retry: true,  body: "L'intelligenza artificiale ha risposto in un formato che non sono riuscito a leggere. Succede ogni tanto: riprova." },
    no_image:       { title: 'Immagine non generata',           tone: 'warn',  retry: true,  body: "Il modello non è riuscito a creare un'immagine per questa richiesta (può succedere per il contenuto della parola o dell'esempio). Prova a modificare le indicazioni aggiuntive, poi riprova." },
    unknown:        { title: 'Errore imprevisto',               tone: 'error', retry: true,  body: "Qualcosa è andato storto. Riprova; se il problema persiste, contatta l'assistenza." }
};
let aiErrorRetryFn = null;
function showAiError(data, retryFn){
    const type = (data && data.error_type) || 'unknown';
    const preset = AI_ERR_PRESETS[type] || AI_ERR_PRESETS.unknown;
    const iconEl = document.getElementById('aiErrIcon');
    iconEl.className = 'ai-err-icon tone-' + preset.tone;
    iconEl.innerHTML = AI_ERR_ICONS[type] || AI_ERR_ICONS.unknown;
    document.getElementById('aiErrTitle').textContent = preset.title;
    document.getElementById('aiErrBody').textContent = (data && data.message) || preset.body || 'Si è verificato un problema imprevisto.';
    const detailEl = document.getElementById('aiErrDetail');
    if (data && data.error) { detailEl.textContent = data.error; detailEl.style.display = 'block'; }
    else { detailEl.style.display = 'none'; }
    const retryBtn = document.getElementById('aiErrRetryBtn');
    if (preset.retry && retryFn) { retryBtn.style.display = ''; aiErrorRetryFn = retryFn; }
    else { retryBtn.style.display = 'none'; aiErrorRetryFn = null; }
    document.getElementById('aiErrorOverlay').classList.add('show');
}
function closeAiError(){ document.getElementById('aiErrorOverlay').classList.remove('show'); aiErrorRetryFn = null; }
document.getElementById('aiErrRetryBtn').addEventListener('click', function(){
    const fn = aiErrorRetryFn;
    closeAiError();
    if (fn) fn();
});
document.getElementById('aiErrorOverlay').addEventListener('click', function(e){ if (e.target.id === 'aiErrorOverlay') closeAiError(); });

/* ---------- Assistente IA ---------- */

// Funzione per il cooldown di 60 secondi
function startBtnCooldown(btn, originalHTML) {
    let timeLeft = 60;
    btn.disabled = true;
    btn.innerHTML = `⏳ ${timeLeft}s`; // Mostra il timer

    let interval = setInterval(() => {
        timeLeft--;
        if (timeLeft <= 0) {
            clearInterval(interval);
            btn.innerHTML = originalHTML; // Ripristina il testo originale
            btn.disabled = false;         // Riabilita il bottone
        } else {
            btn.innerHTML = `⏳ ${timeLeft}s`;
        }
    }, 1000); // Aggiorna ogni secondo
}

async function generateText(btn, tier){
    if (btn.disabled) return; // Evita clic ripetuti

    const word = document.getElementById('description_en').value.trim();
    if (!word) return alert("Inserisci prima una parola in inglese!");

    const endpoint = tier === 'pro' ? 'generate_ai_text.php' : 'generate_ai_text_pollination.php';
    const originalHTML = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="ai-spinner"></span> Generazione…';

    const formData = new FormData();
    formData.append('word', word);
    const exampleText = document.getElementById('example').value.trim();
    const directions = document.getElementById('img_directions').value.trim();
    if ((exampleText === '_' || exampleText === '') && directions !== '') { 
        formData.append('directions', directions); 
    }

    try {
        const response = await fetch(endpoint, { method: 'POST', body: formData });
        const data = await response.json();
        
        if (data.success) {
            document.getElementById('description_it').value = data.description_it || "";
            document.getElementById('meaning').value = data.meaning || "";
            document.getElementById('example').value = data.example || "_";
            validateExampleInput();
            
            btn.innerHTML = '✓ Fatto';
            // Aspetta 1.5 secondi per mostrare "✓ Fatto", poi fa partire il cooldown
            setTimeout(() => { startBtnCooldown(btn, originalHTML); }, 1500);
        } else {
            startBtnCooldown(btn, originalHTML);
            showAiError(data, null); // Passiamo 'null' per rimuovere il tasto "Riprova"
        }
    } catch (error) {
        startBtnCooldown(btn, originalHTML);
        showAiError({ error_type: 'network', error: error && error.message }, null);
    }
}

async function generateImage(btn, tier){
    if (btn.disabled) return; // Evita clic ripetuti

    const word = document.getElementById('description_en').value.trim();
    let example = document.getElementById('example').value.trim();
    const directions = document.getElementById('img_directions').value.trim();
    const useExample = document.getElementById('ai_example').checked;
    const isLinear = document.getElementById('ai_linear').checked;

    if (!word) return alert("Inserisci prima una parola in inglese!");
    if (!useExample) example = "";

    const endpoint = tier === 'pro' ? 'generate_ai_image.php' : 'generate_ai_image_pollination.php';
    const originalHTML = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="ai-spinner"></span> Disegno…';

    const formData = new FormData();
    formData.append('word', word);
    formData.append('example', example);
    formData.append('directions', directions);
    formData.append('is_linear', isLinear ? '1' : '0');

    try {
        const response = await fetch(endpoint, { method: 'POST', body: formData });
        const data = await response.json();
        
        if (data.success || data.status === 'success') {
            const byteCharacters = atob(data.base64);
            const byteNumbers = new Array(byteCharacters.length);
            for (let i = 0; i < byteCharacters.length; i++) byteNumbers[i] = byteCharacters.charCodeAt(i);
            const byteArray = new Uint8Array(byteNumbers);
            const blob = new Blob([byteArray], {type: 'image/jpeg'});

            const safeWord = word.replace(/\s+/g, '_').toLowerCase();
            const fileName = safeWord + "_" + Date.now() + ".jpg";
            const file = new File([blob], fileName, {type: 'image/jpeg'});

            const dataTransfer = new DataTransfer();
            dataTransfer.items.add(file);
            document.getElementById('image').files = dataTransfer.files;

            previewImage("data:image/jpeg;base64," + data.base64);

            btn.innerHTML = '✓ Creata';
            // Aspetta 1.5 secondi per mostrare "✓ Creata", poi fa partire il cooldown
            setTimeout(() => { startBtnCooldown(btn, originalHTML); }, 1500);
        } else {
            startBtnCooldown(btn, originalHTML);
            showAiError(data, null);
        }
    } catch (error) {
        startBtnCooldown(btn, originalHTML);
        showAiError({ error_type: 'network', error: error && error.message }, null);
    }
}
</script>
</body>
</html>