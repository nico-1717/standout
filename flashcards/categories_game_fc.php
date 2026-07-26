<?php
require_once 'config.php';
require_once 'auth_check.php'; // Inserito il controllo login!

$current_user_id = $utente_corrente['id'];

// Categoria da pre-selezionare in automatico (arrivo da un link tipo "gioca con questa categoria")
$preselect_category_id = isset($_GET['preselect_category']) ? (int)$_GET['preselect_category'] : 0;

$conn = mysqli_connect(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
if (!$conn) { die("Connessione fallita: " . mysqli_connect_error()); }

// Query modificata: carica SOLO le categorie di questo utente
$query = "SELECT * FROM fc_categories WHERE user_id = $current_user_id ORDER BY name";
$categories_res = mysqli_query($conn, $query);

$cats = [];
$refs = []; // Usato per costruire l'albero a infiniti livelli

while ($row = mysqli_fetch_assoc($categories_res)) {
    $row['children'] = [];
    $cats[] = $row;
}

// Assegnazione per referenza
foreach ($cats as &$c) {
    $refs[$c['id']] = &$c;
}

$tree = [];
foreach ($cats as &$c) {
    $pid = isset($c['parent_id']) ? (int)$c['parent_id'] : 0;
    if ($pid == 0) {
        $tree[$c['id']] = &$c;
    } else {
        if (isset($refs[$pid])) {
            $refs[$pid]['children'][] = &$c;
        } else {
            $tree[$c['id']] = &$c; // Fallback se il padre è sparito
        }
    }
}

// Helper: Ottiene tutti gli ID annidati per sommare i conteggi
function getAllCategoryIds($node) {
    $ids = [$node['id']];
    foreach ($node['children'] as $child) {
        $ids = array_merge($ids, getAllCategoryIds($child));
    }
    return $ids;
}

// Funzione ricorsiva per stampare l'HTML a N-livelli (righe annidate realmente nel DOM)
function renderGameCatRow($node, $level = 0) {
    $hasChildren = !empty($node['children']);
    $allIds = getAllCategoryIds($node);
    $idsStr = implode(',', $allIds);
    $pad = 12 + $level * 24;
    $nameEsc = htmlspecialchars($node['name'], ENT_QUOTES);
    $color = (!empty($node['color']) && preg_match('/^#[0-9A-Fa-f]{6}$/', $node['color'])) ? $node['color'] : '#007AFF';
?>
<div class="cat-row" id="row-<?php echo $node['id']; ?>" data-level="<?php echo $level; ?>" style="--row-color:<?php echo $color; ?>;">
    <div class="cat-row-main" style="padding-left:<?php echo $pad; ?>px;"<?php echo $hasChildren ? " onclick=\"toggleFolder('{$node['id']}')\"" : ""; ?>>
        <?php if ($hasChildren): ?>
        <button type="button" class="cat-arrow" id="arrow-<?php echo $node['id']; ?>" onclick="event.stopPropagation(); toggleFolder('<?php echo $node['id']; ?>')" aria-label="Espandi">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m9 6 6 6-6 6"/></svg>
        </button>
        <?php else: ?>
        <span class="cat-arrow-spacer"></span>
        <?php endif; ?>

        <span class="cat-icon <?php echo $level === 0 ? 'lvl0' : 'lvln'; ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z"/></svg>
        </span>

        <span class="cat-name"><?php echo $nameEsc; ?></span>
        <span class="cat-badge count-badge" data-ids="<?php echo $idsStr; ?>" id="count-<?php echo $node['id']; ?>">···</span>

        <div class="row-action" onclick="event.stopPropagation();">
            <a class="play-pill play-btn" data-catid="<?php echo $idsStr; ?>" data-catname="<?php echo urlencode($node['name']); ?>" aria-label="Gioca">
                <svg viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>
            </a>
            <input type="checkbox" class="cat-checkbox" value="<?php echo $idsStr; ?>" data-name="<?php echo $nameEsc; ?>" style="display:none;" onchange="updateMultiCount()" aria-label="Seleziona <?php echo $nameEsc; ?>">
        </div>
    </div>

    <?php if ($hasChildren): ?>
    <div class="cat-children" id="children-<?php echo $node['id']; ?>">
        <?php foreach ($node['children'] as $child) { renderGameCatRow($child, $level + 1); } ?>
    </div>
    <?php endif; ?>
</div>
<?php
}

mysqli_close($conn);
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=0, viewport-fit=cover">
<title>Nuova Partita · Flashcards</title>
<style>
/* ================================================================
   DESIGN SYSTEM — coerente con categories_fc.php / game_fc.php
   ================================================================ */
:root{
    color-scheme: light dark;
    --bg:#F2F2F7; --surface:#FFFFFF; --surface-2:#F2F2F7;
    --text:#1C1C1E; --text-2:#8A8A8E; --text-3:#AEAEB2;
    --hairline:rgba(60,60,67,.12); --hairline-2:rgba(60,60,67,.22);
    --blue:#007AFF; --green:#34C759; --red:#FF3B30; --orange:#FF9500; --purple:#AF52DE;
    --teal:#30B0C7; --pink:#FF2D55; --indigo:#5856D6;
    --blue-soft:rgba(0,122,255,.09); --green-soft:rgba(52,199,89,.13); --red-soft:rgba(255,59,48,.12);
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
    -webkit-font-smoothing:antialiased;
    padding-bottom:calc(24px + var(--safe-bottom));
}
a{ color:inherit; }
button{ font-family:inherit; }
:focus-visible{ outline:2px solid var(--blue); outline-offset:2px; border-radius:8px; }

@keyframes cardIn{ from{ opacity:0; transform:translateY(14px) scale(.985); } to{ opacity:1; transform:none; } }
@keyframes rowIn{ from{ opacity:0; transform:translateY(-6px); } to{ opacity:1; transform:none; } }

/* ================================================================
   HEADER
   ================================================================ */
.app-header{
    position:sticky; top:0; z-index:100;
    padding:calc(var(--safe-top) + 12px) 16px 14px;
    background:var(--header-glass);
    -webkit-backdrop-filter:saturate(1.8) blur(20px); backdrop-filter:saturate(1.8) blur(20px);
    border-bottom:.5px solid var(--hairline);
}
.hdr-title{ font-size:28px; font-weight:800; letter-spacing:-.02em; }
.hdr-sub{ margin-top:2px; font-size:13px; color:var(--text-2); font-weight:500; }

.page{ max-width:640px; margin:0 auto; padding:16px 14px calc(28px + var(--safe-bottom)); transition:padding-bottom .3s; }
.page.has-playbar{ padding-bottom:calc(108px + var(--safe-bottom)); }

/* ================================================================
   CARD OPZIONI
   ================================================================ */
.options-card{
    background:var(--surface); border-radius:var(--r-card); padding:18px;
    box-shadow:var(--shadow-card); margin-bottom:18px;
    animation:cardIn .5s var(--spring) both;
}
.opt-section{ margin-bottom:18px; }
.opt-section:last-child{ margin-bottom:0; }
.opt-label{
    font-size:11.5px; font-weight:800; letter-spacing:.07em; text-transform:uppercase;
    color:var(--text-2); margin-bottom:9px;
}
.opt-divider{ border:none; border-top:.5px solid var(--hairline); margin:18px 0; }

/* Segmented control animato */
.segmented{
    position:relative; display:flex; background:var(--surface-2); border-radius:12px; padding:3px;
    box-shadow:inset 0 0 0 .5px var(--hairline);
}
.segmented input{ position:absolute; opacity:0; width:0; height:0; pointer-events:none; }
.segmented label{
    position:relative; z-index:2; flex:1; text-align:center; padding:9px 6px;
    font-size:14.5px; font-weight:700; color:var(--text-2); border-radius:9px;
    cursor:pointer; transition:color .3s; user-select:none; white-space:nowrap;
}
.segmented input:checked + label{ color:var(--text); }
.segmented-thumb{
    position:absolute; top:3px; bottom:3px; left:3px; border-radius:9px;
    background:var(--surface); box-shadow:var(--raise-sm);
    transition:transform .4s var(--spring); z-index:1;
}
.seg-order .segmented-thumb{ width:calc(50% - 3px); }
.seg-order:has(#go_random:checked) .segmented-thumb{ transform:translateX(100%); }
.seg-status .segmented-thumb{ width:calc(33.333% - 4px); }
.seg-status:has(#gs_learnt:checked) .segmented-thumb{ transform:translateX(100%); }
.seg-status:has(#gs_all:checked) .segmented-thumb{ transform:translateX(200%); }

/* Riga toggle multi-categoria */
.toggle-row{ display:flex; align-items:center; justify-content:space-between; gap:12px; }
.toggle-row-label{ font-size:15.5px; font-weight:700; color:var(--text); }
.toggle-row-sub{ font-size:12.5px; color:var(--text-2); font-weight:500; margin-top:1px; }

.ios-switch{ position:relative; display:inline-flex; align-items:center; cursor:pointer; flex-shrink:0; }
.ios-switch input{ position:absolute; opacity:0; width:0; height:0; }
.switch-track{ width:50px; height:30px; border-radius:99px; background:var(--hairline-2); position:relative; display:block; transition:background .3s; }
.switch-thumb{
    position:absolute; top:2px; left:2px; width:26px; height:26px; border-radius:50%; background:#fff;
    box-shadow:0 2px 5px rgba(0,0,0,.25), 0 0 0 .5px rgba(0,0,0,.04);
    transition:transform .35s var(--spring), width .15s var(--spring);
}
.ios-switch input:checked + .switch-track{ background:var(--green); }
.ios-switch input:checked + .switch-track .switch-thumb{ transform:translateX(20px); }
.ios-switch input:active + .switch-track .switch-thumb{ width:30px; }
.ios-switch input:checked:active + .switch-track .switch-thumb{ transform:translateX(16px); }

/* Lista ordine di gioco */
.order-list-box{ margin-top:16px; overflow:hidden; max-height:0; transition:max-height .35s var(--spring); }
.order-list-box.open{ max-height:600px; }
.order-list-inner{ background:var(--surface-2); border-radius:14px; padding:6px; }
.order-item{
    display:flex; align-items:center; gap:10px; padding:9px 10px;
    animation:rowIn .3s var(--spring) both;
}
.order-item + .order-item{ border-top:.5px solid var(--hairline); }
.order-num{
    width:22px; height:22px; border-radius:50%; background:var(--blue-soft); color:var(--blue);
    font-size:12px; font-weight:800; display:flex; align-items:center; justify-content:center; flex-shrink:0;
}
.order-name{ flex:1; min-width:0; font-size:14.5px; font-weight:600; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.order-controls{ display:flex; gap:4px; }
.order-controls button{
    width:26px; height:26px; border-radius:50%; border:none; background:var(--surface); color:var(--text-2);
    display:flex; align-items:center; justify-content:center; cursor:pointer;
    box-shadow:inset 0 0 0 .5px var(--hairline); transition:transform .15s var(--spring), color .15s;
}
.order-controls button:active{ transform:scale(.85); color:var(--blue); }
.order-controls svg{ width:13px; height:13px; }

/* Select utente */
.field-select{ position:relative; }
.field-select select{
    width:100%; padding:13px 38px 13px 14px; font-family:inherit; font-size:16px; font-weight:700;
    color:var(--text); background:var(--surface-2); border:.5px solid var(--hairline-2);
    border-radius:14px; -webkit-appearance:none; appearance:none; text-align:center; cursor:pointer;
    background-image:url("data:image/svg+xml;charset=utf-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%238A8A8E' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E");
    background-repeat:no-repeat; background-position:right 13px center;
}
.field-select select:focus{ outline:none; box-shadow:0 0 0 2.5px var(--blue-soft), inset 0 0 0 1.5px var(--blue); }

/* ================================================================
   HERO: Tutte le categorie
   ================================================================ */
.hero-card{
    display:flex; align-items:center; gap:14px;
    padding:20px; border-radius:var(--r-card); margin-bottom:18px;
    background:linear-gradient(135deg, var(--blue), var(--indigo));
    color:#fff; text-decoration:none;
    box-shadow:0 1px 1px rgba(0,0,0,.05), 0 16px 34px -12px rgba(10,132,255,.5);
    animation:cardIn .5s var(--spring) .05s both;
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
.hero-play{
    flex-shrink:0; width:44px; height:44px; border-radius:50%;
    background:rgba(255,255,255,.22); display:flex; align-items:center; justify-content:center;
    transition:transform .16s var(--spring);
}
.hero-play svg{ width:18px; height:18px; margin-left:2px; }

/* ================================================================
   ALBERO CATEGORIE (stesso linguaggio di categories_fc.php)
   ================================================================ */
.materia-card{
    position:relative; background:var(--surface); border-radius:var(--r-card);
    margin-bottom:14px; overflow:hidden;
    box-shadow:var(--shadow-card);
    border-left:3px solid var(--accent, var(--blue));
    animation:cardIn .5s var(--spring) both;
}
.cat-row{ display:block; }
.cat-row-main{
    display:flex; align-items:center; gap:10px; padding:15px 12px 15px 0;
    transition:background .15s;
}
.cat-row[data-level="0"] .cat-row-main{ cursor:pointer; }
.cat-row:not([data-level="0"]) .cat-row-main{ border-top:.5px solid var(--hairline); padding:12px 12px 12px 0; cursor:pointer; }
.cat-row-main:active{ background:var(--surface-2); }

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

.cat-icon{ width:32px; height:32px; border-radius:9px; flex-shrink:0; display:flex; align-items:center; justify-content:center; }
.cat-icon svg{ width:16px; height:16px; }
.cat-icon.lvl0{ background:color-mix(in srgb, var(--row-color, var(--blue)) 18%, var(--surface)); color:var(--row-color, var(--blue)); width:36px; height:36px; border-radius:10px; }
.cat-icon.lvl0 svg{ width:18px; height:18px; }
.cat-icon.lvln{ background:color-mix(in srgb, var(--row-color, var(--text-2)) 16%, var(--surface)); color:var(--row-color, var(--text-2)); }

.cat-name{
    flex:1; min-width:0; font-size:15.5px; font-weight:600; letter-spacing:-.01em;
    white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
}
.cat-row[data-level="0"] .cat-name{ font-size:17px; font-weight:800; }

.cat-badge{
    flex-shrink:0; background:var(--surface-2); color:var(--text-2);
    font-size:12px; font-weight:700; padding:4px 9px; border-radius:99px;
    box-shadow:inset 0 0 0 .5px var(--hairline); font-variant-numeric:tabular-nums;
    transition:opacity .2s;
}

.row-action{ flex-shrink:0; width:34px; height:34px; display:flex; align-items:center; justify-content:center; }

.play-pill{
    width:34px; height:34px; border-radius:50%; flex-shrink:0;
    display:flex; align-items:center; justify-content:center;
    background:linear-gradient(180deg, color-mix(in srgb, var(--blue), #fff 16%), var(--blue) 52%, color-mix(in srgb, var(--blue), #000 10%));
    color:#fff; text-decoration:none;
    box-shadow:var(--btn-hl), 0 6px 14px -4px rgba(10,132,255,.5);
    transition:transform .16s var(--spring);
}
.play-pill svg{ width:14px; height:14px; margin-left:2px; }
.play-pill:active{ transform:scale(.86); }

/* Checkbox animata (selezione multi-categoria) */
input.cat-checkbox{
    appearance:none; -webkit-appearance:none; margin:0;
    width:27px; height:27px; border-radius:50%; flex-shrink:0;
    border:2px solid var(--hairline-2); background:var(--surface);
    position:relative; cursor:pointer;
    transition:background .2s, border-color .2s, transform .15s var(--spring);
}
input.cat-checkbox:active{ transform:scale(.82); }
input.cat-checkbox:checked{
    background:var(--blue); border-color:var(--blue);
    animation:checkPop .35s var(--spring);
}
input.cat-checkbox:checked::after{
    content:""; position:absolute; left:50%; top:45%; width:7px; height:12px;
    border:solid #fff; border-width:0 2.4px 2.4px 0;
    transform:translate(-50%,-58%) rotate(45deg);
}
@keyframes checkPop{ 0%{ transform:scale(.65); } 55%{ transform:scale(1.18); } 100%{ transform:scale(1); } }

.cat-children{ max-height:0; overflow:hidden; transition:max-height .35s var(--spring); }

@keyframes preselectPulse{
    0%{ box-shadow:inset 0 0 0 2px var(--row-color, var(--blue)); background:color-mix(in srgb, var(--row-color, var(--blue)) 16%, transparent); }
    100%{ box-shadow:inset 0 0 0 0 transparent; background:transparent; }
}
.cat-row-main.preselect-flash{ border-radius:14px; animation:preselectPulse 1.5s ease-out; }
.cat-row.hidden-count{ display:none !important; }
.materia-card.hidden-count{ display:none !important; }

/* ================================================================
   BARRA PLAY FLOTTANTE (multi-categoria)
   ================================================================ */
.play-bar{
    position:fixed; left:0; right:0; bottom:0; z-index:70;
    display:flex; align-items:center; justify-content:space-between; gap:14px;
    padding:14px 18px calc(14px + var(--safe-bottom));
    background:var(--header-glass);
    -webkit-backdrop-filter:saturate(1.8) blur(20px); backdrop-filter:saturate(1.8) blur(20px);
    border-top:.5px solid var(--hairline);
    transform:translateY(100%);
    transition:transform .4s var(--spring);
}
.play-bar.visible{ transform:translateY(0); }
.play-bar-info{ display:flex; flex-direction:column; }
.play-bar-count{ font-size:21px; font-weight:800; font-variant-numeric:tabular-nums; letter-spacing:-.01em; }
.play-bar-label{ font-size:12px; color:var(--text-2); font-weight:600; }
.play-bar-btn{
    display:flex; align-items:center; gap:8px; padding:13px 26px; border-radius:99px;
    background:linear-gradient(180deg, color-mix(in srgb, var(--green), #fff 16%), var(--green) 52%, color-mix(in srgb, var(--green), #000 10%));
    color:#fff; font-weight:800; font-size:16px; text-decoration:none; cursor:pointer;
    box-shadow:var(--btn-hl), 0 12px 26px -8px rgba(52,199,89,.55);
    transition:opacity .25s, transform .16s var(--spring);
}
.play-bar-btn svg{ width:16px; height:16px; }
.play-bar-btn:active{ transform:scale(.96); }
.play-bar-btn.pulse{ animation:playReady .4s var(--spring); }
@keyframes playReady{ 0%{ transform:scale(.9); } 60%{ transform:scale(1.07); } 100%{ transform:scale(1); } }

@media (prefers-reduced-motion: reduce){
    *, *::before, *::after{ animation-duration:.01ms !important; transition-duration:.01ms !important; }
}
</style>
</head>
<body>

<div class="app-header">
    <div class="hdr-title">Nuova Partita</div>
    <div class="hdr-sub">Scegli come vuoi giocare</div>
</div>

<?php include 'sidebar.html'; ?>

<div class="page" id="pageContent">

    <div class="options-card">
        <div class="opt-section">
            <div class="opt-label">Ordine di gioco</div>
            <div class="segmented seg-order">
                <input type="radio" id="go_order" name="game_order" value="in_order" checked onchange="updateLinks(); updateOrderVisibility();">
                <label for="go_order">In ordine</label>
                <input type="radio" id="go_random" name="game_order" value="random" onchange="updateLinks(); updateOrderVisibility();">
                <label for="go_random">Random</label>
                <span class="segmented-thumb"></span>
            </div>
        </div>

        <hr class="opt-divider">

        <!-- QUESTO È IL BLOCCO RIPRISTINATO -->
        <div class="opt-section">
            <div class="opt-label">Stato carte</div>
            <div class="segmented seg-status">
                <input type="radio" id="gs_tolearn" name="game_status" value="0" checked onchange="updateCounts()">
                <label for="gs_tolearn">To Learn</label>
                <input type="radio" id="gs_learnt" name="game_status" value="1" onchange="updateCounts()">
                <label for="gs_learnt">Learnt</label>
                <input type="radio" id="gs_all" name="game_status" value="all" onchange="updateCounts()">
                <label for="gs_all">All</label>
                <span class="segmented-thumb"></span>
            </div>
        </div>
        <!-- FINE BLOCCO RIPRISTINATO -->

        <hr class="opt-divider">

        <div class="opt-section" style="margin-bottom:0;">
            <div class="toggle-row">
                <div>
                    <div class="toggle-row-label">Più categorie</div>
                    <div class="toggle-row-sub">Combina più materie in una partita</div>
                </div>
                <label class="ios-switch">
                    <input type="checkbox" id="multi_cat_toggle" onchange="toggleMulti()">
                    <span class="switch-track"><span class="switch-thumb"></span></span>
                </label>
            </div>

            <div class="order-list-box" id="ordered_categories_list">
                <div class="order-list-inner" id="list_container"></div>
            </div>
        </div>
    </div>

    <a href="#" class="hero-card play-btn" id="row-all" data-catid="0" data-catname="All">
        <div class="hero-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M3.6 9h16.8M3.6 15h16.8M12 3a14.5 14.5 0 0 1 0 18M12 3a14.5 14.5 0 0 0 0 18"/></svg>
        </div>
        <div class="hero-text">
            <div class="hero-num" id="count-all">···</div>
            <div class="hero-label">Tutte le categorie</div>
        </div>
        <div class="hero-play">
            <svg viewBox="0 0 24 24" fill="#fff"><path d="M8 5v14l11-7z"/></svg>
        </div>
    </a>

    <?php foreach ($tree as $rootNode):
        $rootColor = (!empty($rootNode['color']) && preg_match('/^#[0-9A-Fa-f]{6}$/', $rootNode['color'])) ? $rootNode['color'] : '#007AFF';
    ?>
    <div class="materia-card" id="group-<?php echo $rootNode['id']; ?>" style="--accent:<?php echo $rootColor; ?>;">
        <?php renderGameCatRow($rootNode, 0); ?>
    </div>
    <?php endforeach; ?>

</div>

<div class="play-bar" id="globalPlayBar">
    <div class="play-bar-info">
        <span class="play-bar-count"><span id="multi_count">0</span> carte</span>
        <span class="play-bar-label">selezionate</span>
    </div>
    <a id="global_play_btn" class="play-bar-btn" style="opacity:0.4; pointer-events:none;" onclick="startMultiGame()">
        Gioca
        <svg viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>
    </a>
</div>

<script>
    // Inietto l'ID dell'utente loggato in modo sicuro tramite PHP
    const NISAMA_CURRENT_USER_ID = <?php echo (int)$current_user_id; ?>;
    // Categoria da pre-selezionare in automatico (0 = nessuna)
    const PRESELECT_CATEGORY_ID = <?php echo (int)$preselect_category_id; ?>;

    /* ---------- Espandi / comprimi cartelle (nidificazione reale nel DOM) ---------- */
    function toggleFolder(groupId){
        var childrenDiv = document.getElementById('children-' + groupId);
        var arrow = document.getElementById('arrow-' + groupId);
        if (!childrenDiv) return;
        if (childrenDiv.classList.contains('open')) {
            collapseNode(childrenDiv, arrow);
        } else {
            expandNode(childrenDiv, arrow);
        }
    }

    function expandNode(childrenDiv, arrow){
        childrenDiv.classList.add('open');
        if (arrow) arrow.classList.add('open');
        
        // Imposta l'altezza fissa temporaneamente per l'animazione
        childrenDiv.style.maxHeight = childrenDiv.scrollHeight + 'px';
        
        // Rimuove il limite quando l'animazione finisce, così i sottomenù possono espandersi
        setTimeout(function() {
            if (childrenDiv.classList.contains('open')) {
                childrenDiv.style.maxHeight = 'none';
            }
        }, 360); // Attende la fine della transizione CSS
    }

    function collapseNode(childrenDiv, arrow){
        // Prima di chiudere, se era su 'none', dobbiamo rimettere l'altezza reale in pixel
        // per permettere alla transizione CSS (da X pixel a 0) di funzionare correttamente
        childrenDiv.style.maxHeight = childrenDiv.scrollHeight + 'px';
        
        // Forza il reflow del browser per fargli registrare il cambio da 'none' a 'px'
        void childrenDiv.offsetHeight; 
        
        // Adesso animiamo verso lo zero
        childrenDiv.classList.remove('open');
        if (arrow) arrow.classList.remove('open');
        childrenDiv.style.maxHeight = '0px';
    }

    /* ---------- Multi-categoria ---------- */
    let orderedCategories = [];

    function toggleMulti(){
        const isMulti = document.getElementById('multi_cat_toggle').checked;
        const playBtns = document.querySelectorAll('.cat-row .play-btn');
        const checkboxes = document.querySelectorAll('.cat-checkbox');
        const bar = document.getElementById('globalPlayBar');
        const rowAll = document.getElementById('row-all');
        const page = document.getElementById('pageContent');

        updateOrderVisibility();

        if (isMulti) {
            playBtns.forEach(btn => btn.style.display = 'none');
            checkboxes.forEach(cb => { cb.style.display = 'inline-block'; });
            bar.classList.add('visible');
            page.classList.add('has-playbar');
            rowAll.style.display = 'none';
        } else {
            playBtns.forEach(btn => btn.style.display = 'flex');
            checkboxes.forEach(cb => { cb.style.display = 'none'; cb.checked = false; });
            bar.classList.remove('visible');
            page.classList.remove('has-playbar');
            rowAll.style.display = '';
            orderedCategories = [];
            renderOrderedList();
        }
    }

    document.querySelectorAll('.cat-checkbox').forEach(cb => {
        cb.addEventListener('change', function() {
            const id = this.value;
            const name = this.getAttribute('data-name');

            if (this.checked) {
                orderedCategories.push({id: id, name: name});
            } else {
                orderedCategories = orderedCategories.filter(cat => cat.id !== id);
            }
            renderOrderedList();
            updateMultiCount();
        });
    });

    function renderOrderedList(){
        const container = document.getElementById('list_container');
        container.innerHTML = '';
        orderedCategories.forEach((cat, index) => {
            const div = document.createElement('div');
            div.className = 'order-item';
            div.innerHTML = `
                <span class="order-num">${index + 1}</span>
                <div class="order-name">${cat.name}</div>
                <div class="order-controls">
                    <button type="button" onclick="moveOrderItem(${index}, -1)" aria-label="Sposta su">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="m18 15-6-6-6 6"/></svg>
                    </button>
                    <button type="button" onclick="moveOrderItem(${index}, 1)" aria-label="Sposta giù">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                    </button>
                </div>
            `;
            container.appendChild(div);
        });
    }

    function moveOrderItem(index, direction){
        const newIndex = index + direction;
        if (newIndex >= 0 && newIndex < orderedCategories.length) {
            const element = orderedCategories.splice(index, 1)[0];
            orderedCategories.splice(newIndex, 0, element);
            renderOrderedList();
        }
    }

    function startMultiGame(){
        if (orderedCategories.length === 0) return;

        let finalCatIds = orderedCategories.map(c => c.id).join('|');

        const status = document.querySelector('input[name="game_status"]:checked').value;
        const order = document.querySelector('input[name="game_order"]:checked').value;
        const userId = NISAMA_CURRENT_USER_ID; // Usa la costante sicura

        window.location.href = `start_game_fc.php?category_id=${encodeURIComponent(finalCatIds)}&status=${status}&order=${order}&user_id=${userId}`;
    }

    async function updateCounts(){
        const status = document.querySelector('input[name="game_status"]:checked').value;
        const userId = NISAMA_CURRENT_USER_ID; // Usa la costante sicura

        try {
            const response = await fetch(`api_get_counts_fc.php?status=${status}&user_id=${userId}`);
            const data = await response.json();

            document.getElementById('count-all').innerText = data['all'] || 0;

            document.querySelectorAll('.count-badge').forEach(badge => {
                const ids = badge.getAttribute('data-ids').split(',');
                let totalCount = 0;
                ids.forEach(id => { totalCount += parseInt(data[`cat_${id}`]) || 0; });
                badge.innerText = totalCount;

                const rowId = ids[0];
                const rowElement = document.getElementById(`row-${rowId}`);
                if (rowElement) {
                    if (rowElement.dataset.level === '0') {
                        const card = rowElement.closest('.materia-card');
                        if (card) card.classList.toggle('hidden-count', totalCount <= 0);
                    } else {
                        rowElement.classList.toggle('hidden-count', totalCount <= 0);
                    }
                }
            });

            if (document.getElementById('multi_cat_toggle').checked) { updateMultiCount(); }
            else { updateLinks(); }
        } catch (error) { console.error(error); }
    }

    function updateLinks(){
        const status = document.querySelector('input[name="game_status"]:checked').value;
        const order = document.querySelector('input[name="game_order"]:checked').value;
        const userId = NISAMA_CURRENT_USER_ID; // Usa la costante sicura

        document.querySelectorAll('.play-btn').forEach(btn => {
            const catId = btn.getAttribute('data-catid');
            const catName = btn.getAttribute('data-catname');
            btn.href = `start_game_fc.php?category_id=${catId}&category_name=${catName}&status=${status}&order=${order}&user_id=${userId}`;
        });
    }

    function updateMultiCount(){
        const checked = document.querySelectorAll('.cat-checkbox:checked');
        const globalBtn = document.getElementById('global_play_btn');
        let total = 0;

        checked.forEach(cb => {
            const countSpan = document.getElementById(`count-${cb.value.split(',')[0]}`);
            if (countSpan) total += parseInt(countSpan.innerText) || 0;
        });

        document.getElementById('multi_count').innerText = total;

        const wasEnabled = globalBtn.style.pointerEvents === 'auto';
        if (checked.length > 0 && total > 0) {
            if (!wasEnabled) {
                globalBtn.classList.add('pulse');
                setTimeout(() => globalBtn.classList.remove('pulse'), 400);
            }
            globalBtn.style.opacity = '1';
            globalBtn.style.pointerEvents = 'auto';
        } else {
            globalBtn.style.opacity = '0.4';
            globalBtn.style.pointerEvents = 'none';
        }
    }

    function updateOrderVisibility(){
        const isMulti = document.getElementById('multi_cat_toggle').checked;
        const orderType = document.querySelector('input[name="game_order"]:checked').value;
        const listBox = document.getElementById('ordered_categories_list');

        if (isMulti && orderType === 'in_order') {
            listBox.classList.add('open');
        } else {
            listBox.classList.remove('open');
        }
    }

    /* ---------- Pre-selezione categoria da link esterno (es. da "Gioca con questa categoria") ---------- */
    function applyPreselectCategory(){
        if (!PRESELECT_CATEGORY_ID) return;

        var checkbox = null;
        document.querySelectorAll('.cat-checkbox').forEach(function(cb){
            if (!checkbox && cb.value.split(',')[0] === String(PRESELECT_CATEGORY_ID)) checkbox = cb;
        });
        if (!checkbox) return;

        var toggle = document.getElementById('multi_cat_toggle');
        if (toggle && !toggle.checked) { toggle.checked = true; toggleMulti(); }

        // Espande la catena di cartelle antenate, cosi' la riga risulta visibile
        var ancestor = checkbox.closest('.cat-row');
        ancestor = ancestor ? ancestor.parentElement : null;
        while (ancestor && ancestor.classList && ancestor.classList.contains('cat-children')) {
            var gId = ancestor.id.replace('children-', '');
            expandNode(ancestor, document.getElementById('arrow-' + gId));
            var upperRow = ancestor.closest('.cat-row');
            ancestor = upperRow ? upperRow.parentElement : null;
        }

        checkbox.checked = true;
        checkbox.dispatchEvent(new Event('change'));

        setTimeout(function(){
            var mainRow = checkbox.closest('.cat-row-main');
            if (!mainRow) return;
            mainRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
            mainRow.classList.add('preselect-flash');
            setTimeout(function(){ mainRow.classList.remove('preselect-flash'); }, 1500);
        }, 400);
    }

    window.onload = function(){ updateCounts().then(applyPreselectCategory); };
</script>

</body>
</html>