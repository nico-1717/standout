<?php
require_once 'config.php';
require_once 'auth_check.php';
$current_user_id = $utente_corrente['id'];

$conn = mysqli_connect(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
if (!$conn) { die("Connessione fallita: " . mysqli_connect_error()); }

$catID = isset($_GET['category_id']) ? intval($_GET['category_id']) : 0;

// --- SICUREZZA: verifica proprietario della categoria ---
$check_get = mysqli_query($conn, "SELECT user_id, name FROM categories WHERE id = $catID");
$row_get = $check_get ? mysqli_fetch_assoc($check_get) : null;
if (!$row_get || (int)$row_get['user_id'] !== $current_user_id) {
    die("Errore di sicurezza: non sei il proprietario di questa categoria.");
}
$catName = $row_get['name'];

// Carichiamo le parole (padri + figli) della categoria
$words = [];
$res = mysqli_query($conn, "SELECT * FROM images WHERE category_id = $catID ORDER BY id DESC");
while ($row = mysqli_fetch_assoc($res)) {
    $row['is_child'] = 0;
    $words[] = $row;
    $parentId = $row['id'];
    $resChild = mysqli_query($conn, "SELECT * FROM english_child_words WHERE parent_id = $parentId ORDER BY id_child ASC");
    if ($resChild) {
        while ($child = mysqli_fetch_assoc($resChild)) {
            $child['is_child'] = 1;
            $words[] = $child;
        }
    }
}
mysqli_close($conn);
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=0, viewport-fit=cover">
<meta name="mobile-web-app-capable" content="yes">
<meta name="theme-color" content="#F2F2F7" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#000000" media="(prefers-color-scheme: dark)">
<title>Big Edit · Vocabolario</title>
<style>
/* ================================================================
   DESIGN SYSTEM — iOS nativo · light + dark automatico
   (coerente con inserimento_big.php / big_edit_fc.php)
   ================================================================ */
:root{
    color-scheme: light dark;
    --bg:#F2F2F7; --surface:#FFFFFF; --surface-2:#F2F2F7; --surface-3:#EDEDF2;
    --text:#1C1C1E; --text-2:#8A8A8E; --text-3:#AEAEB2;
    --hairline:rgba(60,60,67,.12); --hairline-2:rgba(60,60,67,.22);
    --blue:#007AFF; --green:#34C759; --red:#FF3B30; --orange:#FF9500; --purple:#AF52DE; --indigo:#5856D6; --gray:#8E8E93;
    --blue-soft:rgba(0,122,255,.09); --green-soft:rgba(52,199,89,.13); --red-soft:rgba(255,59,48,.12); --gray-soft:rgba(142,142,147,.14);
    --g-fill:#E7F8ED; --g-ink:#1E8A44; --g-line:rgba(52,199,89,.85);
    --r-fill:#FDECEC; --r-ink:#D70015; --r-line:rgba(255,59,48,.8);
    --w-fill:#FFF3E0; --w-ink:#C77700; --w-line:rgba(255,149,0,.7);
    --p-fill:#F5E9FB; --p-ink:#8E24AA; --p-line:rgba(175,82,222,.55);
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
        --blue:#0A84FF; --green:#30D158; --red:#FF453A; --orange:#FF9F0A; --purple:#BF5AF2; --indigo:#5E5CE6; --gray:#98989F;
        --blue-soft:rgba(10,132,255,.16); --green-soft:rgba(48,209,88,.17); --red-soft:rgba(255,69,58,.17); --gray-soft:rgba(152,152,159,.18);
        --g-fill:rgba(48,209,88,.15); --g-ink:#4CE07E; --g-line:rgba(48,209,88,.75);
        --r-fill:rgba(255,69,58,.16); --r-ink:#FF6F68; --r-line:rgba(255,69,58,.75);
        --w-fill:rgba(255,159,10,.16); --w-ink:#FFB340; --w-line:rgba(255,159,10,.65);
        --p-fill:rgba(191,90,242,.16); --p-ink:#D68CFF; --p-line:rgba(191,90,242,.55);
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
    padding-bottom: env(safe-area-inset-bottom, 0px);
}
:focus-visible{ outline:2px solid var(--blue); outline-offset:2px; border-radius:8px; }
a{ color:var(--blue); }

/* HEADER */
.app-header{ position:fixed; top:0; left:0; right:0; z-index:200; display:flex; align-items:center; gap:10px; padding:calc(var(--safe-top) + 12px) 16px 12px; background:var(--header-glass); -webkit-backdrop-filter:saturate(1.8) blur(22px); backdrop-filter:saturate(1.8) blur(22px); border-bottom:.5px solid var(--hairline); }.hdr-back{ flex-shrink:0; width:36px; height:36px; border-radius:50%; border:none; cursor:pointer; background:var(--surface); color:var(--text-2); display:flex; align-items:center; justify-content:center; box-shadow:var(--pill-hl), var(--raise-sm); }
.hdr-back svg{ width:17px; height:17px; }
.hdr-text{ min-width:0; flex:1; }
.hdr-text h1{ margin:0; font-size:18px; font-weight:800; letter-spacing:-.02em; color:var(--text); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.hdr-sub{ margin-top:1px; font-size:12px; font-weight:600; color:var(--text-2); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.hdr-dirty-dot{ width:8px; height:8px; border-radius:50%; background:var(--orange); display:none; flex-shrink:0; }
body.is-dirty .hdr-dirty-dot{ display:block; }
.hdr-chip{ flex-shrink:0; display:inline-flex; align-items:center; gap:6px; padding:6px 12px; border-radius:var(--r-chip); font-size:12px; font-weight:800; background:var(--blue-soft); color:var(--blue); font-variant-numeric:tabular-nums; }

.page-wrap{ max-width:760px; margin:0 auto; padding:calc(80px + var(--safe-top)) 14px calc(24px + var(--safe-bottom)); }

/* BOTTONI */
.btn{ display:flex; align-items:center; justify-content:center; gap:8px; width:100%; padding:15px 16px; border:none; border-radius:var(--r-btn); cursor:pointer; font-family:inherit; font-size:16px; font-weight:700; letter-spacing:-.01em; color:#fff; background:var(--text); text-decoration:none; text-align:center; -webkit-user-select:none; user-select:none; --glow:rgba(0,0,0,.3); box-shadow:var(--btn-hl), 0 1px 2px rgba(0,0,0,.14), 0 12px 26px -8px var(--glow); transition:transform .16s var(--spring), box-shadow .2s var(--spring), filter .2s, opacity .2s; }
.btn:active{ transform:translateY(1.5px) scale(.985); filter:brightness(.95); }
.btn:disabled{ opacity:.55; cursor:not-allowed; transform:none !important; filter:none !important; }
.btn-primary{ --glow:rgba(10,132,255,.5); background:linear-gradient(180deg, color-mix(in srgb, var(--blue), #fff 16%), var(--blue) 52%, color-mix(in srgb, var(--blue), #000 10%)); }
.btn-success{ --glow:rgba(52,199,89,.5); background:linear-gradient(180deg, color-mix(in srgb, var(--green), #fff 18%), var(--green) 52%, color-mix(in srgb, var(--green), #000 10%)); }
.btn-warn{ --glow:rgba(255,149,0,.5); background:linear-gradient(180deg, color-mix(in srgb, var(--orange), #fff 16%), var(--orange) 52%, color-mix(in srgb, var(--orange), #000 10%)); }
.btn-ghost{ color:var(--text); background:var(--surface); box-shadow:var(--pill-hl), var(--raise); }
.btn-ghost:active{ filter:none; box-shadow:var(--pill-hl), inset 0 2px 6px rgba(0,0,0,.08), 0 1px 3px rgba(0,0,0,.08); }
.btn-sm{ padding:11px 14px; font-size:14.5px; }
.btn svg{ width:18px; height:18px; flex-shrink:0; }
.spinner{ width:17px; height:17px; border-radius:50%; border:2.5px solid rgba(255,255,255,.4); border-top-color:#fff; animation:spin .7s linear infinite; }
@keyframes spin{ to{ transform:rotate(360deg); } }

.empty-hint{ text-align:center; padding:40px 16px; color:var(--text-3); font-size:14px; font-weight:600; line-height:1.5; }
.action-stack{ display:flex; flex-direction:column; gap:10px; margin-top:8px; }

/* TOAST */
.app-toast{ position:fixed; left:50%; bottom:calc(90px + var(--safe-bottom)); z-index:11000; transform:translateX(-50%) translateY(20px); opacity:0; max-width:88%; padding:13px 20px; border-radius:16px; background:var(--text); color:var(--bg); font-size:14px; font-weight:600; text-align:center; box-shadow:0 14px 40px rgba(0,0,0,.3); transition:opacity .3s var(--spring), transform .3s var(--spring); }
.app-toast.show{ opacity:1; transform:translateX(-50%) translateY(0); }
.app-toast.toast-error{ background:var(--red); color:#fff; }

/* MODALE CONFERMA */
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

/* POPUP ERRORI IA */
.modal-overlay{ display:none; position:fixed; inset:0; z-index:10600; background:rgba(0,0,0,.5); -webkit-backdrop-filter:blur(6px); backdrop-filter:blur(6px); justify-content:center; align-items:flex-end; }
.modal-overlay.show{ display:flex; }
.modal-content{ background:var(--surface); color:var(--text); padding:22px 20px calc(24px + var(--safe-bottom)); border-radius:26px 26px 0 0; max-width:540px; width:100%; position:relative; max-height:80vh; overflow-y:auto; box-shadow:0 -12px 50px rgba(0,0,0,.4); animation:sheetUp2 .42s var(--spring) both; }
.modal-content::before{ content:""; position:absolute; top:9px; left:50%; transform:translateX(-50%); width:38px; height:5px; border-radius:99px; background:var(--hairline-2); }
@keyframes sheetUp2{ from{ transform:translateY(60%); opacity:.4; } }
@media (min-width:640px){ .modal-overlay{ align-items:center; padding:20px; } .modal-content{ border-radius:26px; } }
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
.ai-err-actions button{ flex:1; padding:13px; border:none; border-radius:14px; font-family:inherit; font-size:15px; font-weight:800; cursor:pointer; }
.ai-err-actions .btn-cancel2{ background:var(--surface-2); color:var(--text); }
.ai-err-actions .btn-retry{ color:#fff; background:linear-gradient(180deg, color-mix(in srgb, var(--indigo), #fff 16%), var(--indigo) 52%, color-mix(in srgb, var(--indigo), #000 10%)); }

/* RIGHE */
.fc-rows{ display:flex; flex-direction:column; gap:14px; margin:16px 0; }
.fc-rows:empty{ display:none; }
.fc-row{ position:relative; border-radius:var(--r-card); overflow:hidden; box-shadow:var(--shadow-card); animation:rowIn .35s var(--spring) both; }
@keyframes rowIn{ from{ opacity:0; transform:translateY(10px) scale(.98); } }
.fc-row-content{ position:relative; background:var(--surface); border-radius:var(--r-card); border:.5px solid var(--hairline); }
.fc-row.is-child{ margin-left:22px; }
.fc-row.is-child .fc-row-content{ background:color-mix(in srgb, var(--surface), var(--orange) 12%); border-left:3px solid var(--orange); }
.fc-row.is-removing{ transition:opacity .25s var(--spring), transform .25s var(--spring); opacity:0; transform:scale(.95); }

.fc-row-strip{ display:flex; align-items:center; gap:10px; padding:12px 14px 8px; }
.row-badge{ display:inline-flex; align-items:center; gap:6px; font-size:10px; font-weight:800; letter-spacing:.08em; text-transform:uppercase; color:var(--text-2); background:var(--surface-3); padding:4px 10px; border-radius:99px; }
.row-badge::before{ content:""; width:5px; height:5px; border-radius:50%; background:var(--blue); flex-shrink:0; }
.fc-row.is-child .row-badge{ background:var(--orange); color:#fff; }
.fc-row.is-child .row-badge::before{ display:none; }
.row-dirty-badge{ font-size:9.5px; font-weight:800; letter-spacing:.05em; text-transform:uppercase; color:var(--orange); background:color-mix(in srgb, var(--orange) 14%, var(--surface)); padding:3px 8px; border-radius:99px; display:none; }
.fc-row[data-status="edited"] .row-dirty-badge, .fc-row[data-status="new"] .row-dirty-badge{ display:inline-block; }
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

textarea, select, input[type="text"]{ width:100%; box-sizing:border-box; font-family:inherit; color:var(--text); background:var(--surface-2); border:1px solid var(--hairline-2); border-radius:12px; padding:11px 12px; font-size:16px; resize:vertical; }
textarea:focus, select:focus, input:focus{ outline:none; border-color:var(--blue); }

.row-field{ margin:0 14px 10px; }
.row-field .field-label{ font-size:9.5px; font-weight:800; letter-spacing:.06em; text-transform:uppercase; color:var(--text-3); margin:0 0 5px; display:block; }
.row-field input[type="text"]{ font-size:14.5px; padding:9px 11px; }

.media-row{ display:flex; gap:8px; margin:2px 14px 14px; flex-wrap:wrap; align-items:center; }
.media-chip{ display:flex; align-items:center; gap:7px; padding:8px 12px 8px 8px; border-radius:99px; background:var(--surface-2); box-shadow:var(--pill-hl), var(--raise-sm); cursor:pointer; font-size:12.5px; font-weight:700; color:var(--text-2); border:none; font-family:inherit; transition:transform .15s var(--spring), opacity .2s; }
.media-chip:active{ transform:scale(.95); }
.media-chip:disabled{ opacity:.6; pointer-events:none; }
.media-chip input[type=file]{ display:none; }
.media-chip.has-img{ background:var(--blue-soft); color:var(--blue); }
.chip-thumb{ width:22px; height:22px; border-radius:50%; overflow:hidden; flex-shrink:0; background:var(--surface-3); display:flex; align-items:center; justify-content:center; color:var(--text-3); }
.chip-thumb img{ width:100%; height:100%; object-fit:cover; }
.chip-thumb svg{ width:12px; height:12px; }
.ai-row-pro{ background:linear-gradient(180deg, color-mix(in srgb, var(--indigo), #fff 16%), var(--indigo) 55%, color-mix(in srgb, var(--indigo), #000 12%)); color:#fff; padding-left:12px; }
.ai-row-pro .chip-thumb{ background:rgba(255,255,255,.22); color:#fff; }
.ai-row-basic{ padding-left:12px; }
.ai-spinner-sm{ width:12px; height:12px; border-radius:50%; border:2px solid rgba(255,255,255,.35); border-top-color:#fff; animation:spin .7s linear infinite; display:inline-block; }
.ai-row-basic .ai-spinner-sm{ border:2px solid var(--hairline-2); border-top-color:var(--text-2); }

@media (prefers-reduced-motion: reduce){ *, *::before, *::after{ animation-duration:.01ms !important; transition-duration:.01ms !important; } }
</style>
</head>
<body>

<?php include 'sidebar.html'; ?>

<header class="app-header">
    <button type="button" class="hdr-back" onclick="goBack()" aria-label="Torna indietro">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
    </button>
    <div class="hdr-text">
        <h1>Big Edit</h1>
        <div class="hdr-sub"><?php echo htmlspecialchars($catName); ?></div>
    </div>
    <span class="hdr-dirty-dot" title="Modifiche non salvate"></span>
    <button type="button" id="hdrSaveBtn" class="btn btn-success btn-sm" style="width:auto; padding:7px 12px; font-size:13px; margin:0; box-shadow:var(--raise-sm);" onclick="saveAll()">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="width:13px;height:13px;"><path d="M5 12.5 10 17l9-10"/></svg>
        Salva
    </button>
    <div class="hdr-chip" id="row-count-chip">0 parole</div>
</header>

<div class="page-wrap">
    <div class="fc-rows" id="fcRows"></div>
    <div class="empty-hint" id="emptyHint">Nessuna parola in questa categoria — tocca "Aggiungi Parola" per iniziare.</div>
        <div id="loadMoreContainer" style="display:none; text-align:center; padding: 10px 14px 20px;">
        <button type="button" class="btn btn-ghost" style="width:auto; display:inline-flex; border:1.5px solid var(--hairline-2); font-size:14px; padding:10px 20px; box-shadow:var(--raise-sm);" onclick="renderNextBatch(20)">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;margin-right:6px;"><path d="M6 9l6 6 6-6"/></svg>
            Caricane altre 20
        </button>
    </div>
    <div class="action-stack">
        <button type="button" class="btn btn-primary" onclick="addRow()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
            Aggiungi Parola
        </button>
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

<!-- POPUP ERRORI IA -->
<div id="aiErrorOverlay" class="modal-overlay">
    <div class="modal-content ai-err-panel">
        <div class="ai-err-icon" id="aiErrIcon"></div>
        <h3 class="ai-err-title" id="aiErrTitle">Errore</h3>
        <p class="ai-err-body" id="aiErrBody"></p>
        <p class="ai-err-detail" id="aiErrDetail" style="display:none;"></p>
        <div class="ai-err-actions">
            <button type="button" class="btn-cancel2" onclick="closeAiError()">Chiudi</button>
            <button type="button" class="btn-retry" id="aiErrRetryBtn" style="display:none;">Riprova</button>
        </div>
    </div>
</div>

<script>
const CAT_ID = <?php echo (int)$catID; ?>;
const INITIAL_WORDS = <?php echo json_encode($words, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

const ICON_TRASH = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h16M9 7V5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2m2 0-1 13a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2L6 7"/></svg>';
const ICON_IMG   = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="10" r="1.6"/><path d="m21 16-5-5-4 4-3-3-6 6"/></svg>';
const ICON_SPARK = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v3M12 18v3M4.2 4.2l2.1 2.1M17.7 17.7l2.1 2.1M3 12h3M18 12h3M4.2 19.8l2.1-2.1M17.7 6.3l2.1-2.1"/><circle cx="12" cy="12" r="3.2"/></svg>';
const ICON_CHECK = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5 10 17l9-10"/></svg>';

function escAttr(s){ return String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

let isDirty = false;
function updateDirtyUI(){ document.body.classList.toggle('is-dirty', isDirty); }
function markDirty(){ isDirty = true; updateDirtyUI(); }

/* ---------- Toast & conferma ---------- */
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
        function cleanup(result){ overlay.classList.remove('show'); okBtn.removeEventListener('click', onOk); cancelBtn.removeEventListener('click', onCancel); resolve(result); }
        function onOk(){ cleanup(true); }
        function onCancel(){ cleanup(false); }
        okBtn.addEventListener('click', onOk);
        cancelBtn.addEventListener('click', onCancel);
    });
}

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
    no_image:       { title: 'Immagine non generata',           tone: 'warn',  retry: true,  body: "Il modello non è riuscito a creare un'immagine per questa richiesta. Prova a modificare la parola o l'esempio di questa riga, poi riprova." },
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
document.getElementById('aiErrRetryBtn').addEventListener('click', function(){ const fn = aiErrorRetryFn; closeAiError(); if (fn) fn(); });
document.getElementById('aiErrorOverlay').addEventListener('click', function(e){ if (e.target.id === 'aiErrorOverlay') closeAiError(); });

/* ---------- Contatori ---------- */
function updateRowCountChip(){
    const n = document.querySelectorAll('#fcRows .fc-row').length;
    document.getElementById('row-count-chip').textContent = n + (n === 1 ? ' parola' : ' parole');
}
function toggleEmptyHint(){ document.getElementById('emptyHint').style.display = document.getElementById('fcRows').children.length ? 'none' : 'block'; }

/* ---------- Creazione riga ---------- */
function rowTemplate(word){
    const isChild = !!parseInt(word.is_child);
    const id = word.__domId;
    const en = word.description_en || '', it = word.description_it || '', meaning = word.meaning || '', example = word.example || '_';
    const imgUrl = word.image ? ('<?php echo IMAGE_PATH_VIEW; ?>/' + word.image) : '';
    const thumb = imgUrl ? `<img src="${imgUrl}">` : ICON_IMG;

    const wrap = document.createElement('div');
    wrap.className = 'fc-row' + (isChild ? ' is-child' : '');
    wrap.dataset.rid = id;
    wrap.dataset.id = word.__dbId || 0;
    wrap.dataset.status = word.__status || 'clean';
    wrap.dataset.isChild = isChild ? 1 : 0;
    wrap.dataset.parentIdx = '-1';

    wrap.innerHTML = `
        <div class="fc-row-content">
            <div class="fc-row-strip">
                <span class="row-badge">${isChild ? '↳ Figlio' : 'Parola'}</span>
                <span class="row-dirty-badge">Non salvato</span>
                <span class="strip-spacer"></span>
                <button type="button" class="row-del-btn" onclick="deleteRow(this)" aria-label="Elimina">${ICON_TRASH}</button>
            </div>
            <div class="row-bilingual">
                <div class="row-col col-en">
                    <div class="row-eyebrow">🇬🇧 English</div>
                    <input type="text" class="row-en" value="${escAttr(en)}" placeholder="parola" oninput="onRowChange(this)">
                </div>
                <div class="row-col col-it">
                    <div class="row-eyebrow">🇮🇹 Italiano</div>
                    <input type="text" class="row-it" value="${escAttr(it)}" placeholder="traduzione" oninput="onRowChange(this)">
                </div>
            </div>
            <div class="row-field">
                <div class="field-label">Significato</div>
                <input type="text" class="row-meaning" value="${escAttr(meaning)}" placeholder="breve spiegazione" oninput="onRowChange(this)">
            </div>
            <div class="row-field">
                <div class="field-label">Esempio</div>
                <input type="text" class="row-example" value="${escAttr(example)}" placeholder="frase con _ al posto della parola" oninput="onRowChange(this)">
            </div>
            <div class="media-row">
                <label class="media-chip img-chip ${imgUrl ? 'has-img' : ''}">
                    <input type="file" accept="image/*" onchange="previewRowImage(this, '${id}')">
                    <span class="chip-thumb" id="img-preview-${id}">${thumb}</span>
                    <span class="img-name-tag">Immagine</span>
                </label>
                <button type="button" class="media-chip ai-row-pro" onclick="generateRowImage('${id}','pro')">
                    <span class="chip-thumb">${ICON_SPARK}</span>PRO
                </button>
                <button type="button" class="media-chip ai-row-basic" onclick="generateRowImage('${id}','basic')">
                    <span class="chip-thumb">${ICON_SPARK}</span>Basic
                </button>
            </div>
            <input type="hidden" class="row-img-name" value="${word.image ? escAttr(word.image) : ''}">
        </div>`;
    return wrap;
}

function onRowChange(input){
    const row = input.closest('.fc-row');
    if (row.dataset.status !== 'new') row.dataset.status = 'edited';
    markDirty();
}

function addRow(){
    const id = 'new_' + Date.now();
    const row = rowTemplate({ is_child: 0, description_en: '', description_it: '', meaning: '', example: '_', image: '', __domId: id, __dbId: 0, __status: 'new' });
    document.getElementById('fcRows').prepend(row);
    updateRowCountChip();
    toggleEmptyHint();
    markDirty();
    setTimeout(() => { row.scrollIntoView({ behavior: 'smooth', block: 'center' }); row.querySelector('.row-en').focus({ preventScroll: true }); }, 80);
}

async function deleteRow(btn){
    const row = btn.closest('.fc-row');
    const isNew = row.dataset.status === 'new';
    if (!isNew) {
        const ok = await showConfirm('Eliminare questa parola?', "Verrà eliminata definitivamente al momento del salvataggio. L'eventuale immagine associata verrà rimossa.", 'Elimina');
        if (!ok) return;
    }
    row.classList.add('is-removing');
    row.dataset.status = 'deleted';
    markDirty();
    setTimeout(() => { row.remove(); updateRowCountChip(); toggleEmptyHint(); }, 260);
}

/* ---------- Immagine per riga ---------- */
function previewRowImage(input, id){
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e){ document.getElementById('img-preview-' + id).innerHTML = `<img src="${e.target.result}">`; };
        reader.readAsDataURL(input.files[0]);
        const chip = input.closest('.img-chip');
        chip.classList.add('has-img');
        onRowChange(input);
    }
}

// Variabile globale per tenere traccia del tempo di attesa di 1 minuto
let aiCooldown = false;

async function generateRowImage(id, tier){
    // Se il blocco è attivo, impediamo ulteriori click
    if (aiCooldown) {
        showToast('Devi attendere 1 minuto tra una generazione e l\'altra.', 'warn');
        return;
    }

    const row = document.querySelector(`.fc-row[data-rid="${id}"]`);
    const word = row.querySelector('.row-en').value.trim();
    if (!word) { showToast('Inserisci prima la parola inglese in questa riga', 'error'); return; }
    const example = row.querySelector('.row-example').value.trim();

    // --- INIZIO BLOCCO 1 MINUTO ---
    aiCooldown = true;
    const allAiButtons = document.querySelectorAll('.ai-row-pro, .ai-row-basic');
    
    // Disattiviamo visivamente e funzionalmente tutti i bottoni IA della pagina
    allAiButtons.forEach(b => {
        b.disabled = true;
        b.style.opacity = '0.5';
        b.style.cursor = 'not-allowed';
    });

    // Impostiamo il ripristino dopo 60.000 millisecondi (1 minuto)
    setTimeout(() => {
        aiCooldown = false;
        const currentAiButtons = document.querySelectorAll('.ai-row-pro, .ai-row-basic');
        currentAiButtons.forEach(b => {
            // Se il pulsante non sta caricando attivamente in questo istante
            if (!b.querySelector('.ai-spinner-sm')) {
                b.disabled = false;
                b.style.opacity = '1';
                b.style.cursor = 'pointer';
            }
        });
    }, 60000); 
    // --- FINE BLOCCO 1 MINUTO ---

    const endpoint = tier === 'pro' ? 'generate_ai_image.php' : 'generate_ai_image_pollination.php';
    const btn = row.querySelector(tier === 'pro' ? '.ai-row-pro' : '.ai-row-basic');
    const originalHTML = btn.innerHTML;
    
    // Mostriamo lo spinner solo sul pulsante cliccato, tenendolo ben visibile
    btn.innerHTML = '<span class="ai-spinner-sm"></span>' + (tier === 'pro' ? ' PRO' : ' Basic');
    btn.style.opacity = '1'; 

    const fd = new FormData();
    fd.append('word', word);
    fd.append('example', example);
    fd.append('directions', '');
    fd.append('is_linear', '0');

    try {
        const res = await fetch(endpoint, { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success || data.status === 'success') {
            const byteCharacters = atob(data.base64);
            const byteNumbers = new Array(byteCharacters.length);
            for (let i = 0; i < byteCharacters.length; i++) byteNumbers[i] = byteCharacters.charCodeAt(i);
            const blob = new Blob([new Uint8Array(byteNumbers)], { type: 'image/jpeg' });
            const safeWord = word.replace(/\s+/g, '_').toLowerCase();
            const file = new File([blob], safeWord + '_' + Date.now() + '.jpg', { type: 'image/jpeg' });
            const dt = new DataTransfer();
            dt.items.add(file);
            const fileInput = row.querySelector('input[type=file]');
            fileInput.files = dt.files;
            document.getElementById('img-preview-' + id).innerHTML = `<img src="data:image/jpeg;base64,${data.base64}">`;
            row.querySelector('.img-chip').classList.add('has-img');
            onRowChange(fileInput);
            showToast('Immagine generata ✓');
        } else {
            showAiError(data, () => generateRowImage(id, tier));
        }
    } catch (err) {
        showAiError({ error_type: 'network', error: err && err.message }, () => generateRowImage(id, tier));
    } finally {
        // Ripristiniamo l'HTML originale del pulsante cliccato
        btn.innerHTML = originalHTML;
        
        // Se il minuto di cooldown è ancora in corso, assicuriamoci che torni opaco/disabilitato
        if (aiCooldown) {
            btn.disabled = true;
            btn.style.opacity = '0.5';
        } else {
            btn.disabled = false;
            btn.style.opacity = '1';
        }
    }
}

/* ---------- Idratazione dati iniziali (con caricamento a blocchi) ---------- */
let currentRenderIndex = 0;

function renderNextBatch(amount) {
    let count = 0;
    const container = document.getElementById('fcRows');
    
    while (currentRenderIndex < INITIAL_WORDS.length) {
        const w = INITIAL_WORDS[currentRenderIndex];
        const id = w.is_child ? ('child_' + w.id_child) : ('father_' + w.id);
        
        w.__domId = id;
        w.__dbId = w.is_child ? w.id_child : w.id;
        w.__status = 'clean';
        
        const row = rowTemplate(w);
        container.appendChild(row);
        
        currentRenderIndex++;
        count++;
        
        // Condizione di stop: fermati se abbiamo raggiunto il numero richiesto,
        // MA assicurati che la PROSSIMA parola non sia un figlio (non separare le famiglie)
        if (count >= amount) {
            if (currentRenderIndex < INITIAL_WORDS.length) {
                const nextWord = INITIAL_WORDS[currentRenderIndex];
                if (parseInt(nextWord.is_child) !== 1) {
                    break; // La prossima è un padre, possiamo fermarci sicuri
                }
            }
        }
    }
    
    updateRowCountChip();
    toggleEmptyHint();
    
    // Mostra o nascondi il bottone "Caricane altre 20"
    const btnContainer = document.getElementById('loadMoreContainer');
    if (btnContainer) {
        btnContainer.style.display = (currentRenderIndex < INITIAL_WORDS.length) ? 'block' : 'none';
    }
}

function hydrateInitialWords(){
    renderNextBatch(50); // Inizialmente ne carichiamo 50
}
hydrateInitialWords();

/* ---------- Uscita / Salvataggio ---------- */
async function goBack(){
    if (isDirty) {
        const ok = await showConfirm('Modifiche non salvate', 'Hai delle modifiche che non sono ancora state salvate. Se esci ora andranno perse.', 'Esci senza salvare');
        if (!ok) return;
    }
    window.location.href = 'visualizzazione.php?category_id=' + CAT_ID;
}
window.addEventListener('beforeunload', function(e){ if (isDirty) { e.preventDefault(); e.returnValue = ''; } });

async function saveAll(){
    const hdrBtn = document.getElementById('hdrSaveBtn');
    
    // 1. Disabilita il pulsante e mostra lo spinner
    hdrBtn.disabled = true; 
    hdrBtn.innerHTML = '<span class="spinner" style="width:13px;height:13px;border-width:2px; display:inline-block; vertical-align:middle; margin-right:5px;"></span> Salvataggio...'; 

    const fd = new FormData();
    fd.append('action', 'save_big_edit');
    fd.append('category_id', CAT_ID);

    const rows = document.querySelectorAll('#fcRows .fc-row');
    let elementiModificati = 0;

    // 2. Cicliamo tutte le righe, ma processiamo SOLO quelle non "clean"
    rows.forEach((row) => {
        const status = row.dataset.status;
        
        // Se la riga non è stata toccata, la ignoriamo completamente
        if (status === 'clean') return; 

        const isChild = row.dataset.isChild === '1' ? 1 : 0;
        
        fd.append(`cards[${elementiModificati}][id]`, row.dataset.id);
        fd.append(`cards[${elementiModificati}][status]`, status);
        fd.append(`cards[${elementiModificati}][is_child]`, isChild);
        fd.append(`cards[${elementiModificati}][en]`, row.querySelector('.row-en').value);
        fd.append(`cards[${elementiModificati}][it]`, row.querySelector('.row-it').value);
        fd.append(`cards[${elementiModificati}][meaning]`, row.querySelector('.row-meaning').value);
        fd.append(`cards[${elementiModificati}][example]`, row.querySelector('.row-example').value);
        
        const fileInput = row.querySelector('input[type=file]');
        if (fileInput && fileInput.files[0]) { 
            fd.append(`row_img[${elementiModificati}]`, fileInput.files[0]); 
        }

        elementiModificati++;
    });

    const resetButtons = () => {
        hdrBtn.disabled = false; 
        hdrBtn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="width:13px;height:13px;"><path d="M5 12.5 10 17l9-10"/></svg> Salva';
    };

    // 3. Se non ci sono modifiche, fermiamo tutto ed evitiamo chiamate inutili al server
    if (elementiModificati === 0) {
        showToast('Nessuna modifica da salvare.');
        resetButtons();
        return;
    }

    try {
        const res = await fetch('big_edit_save.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            isDirty = false;
            updateDirtyUI();
            showToast('Salvataggio completato ✓');
            setTimeout(() => window.location.reload(), 700);
        } else {
            showToast('Errore durante il salvataggio: ' + (data.error || ''), 'error');
            resetButtons();
        }
    } catch (e) {
        showToast('Errore di rete.', 'error');
        resetButtons();
    }
}
</script>
</body>
</html>