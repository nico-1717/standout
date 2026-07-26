<?php

/* ==========================================================================

   ENDPOINT AJAX: creazione rapida di una nuova materia dal popup

   (chiamato direttamente come category_selector.php?cs_ajax=create_category,

   non tramite include, quindi apre una propria connessione se necessario)



   Il colore NON viene mai accettato dal client: viene sempre risolto lato

   server dalla catena dei genitori, e color_edited viene sempre impostato a 0

   (una materia appena creata eredita sempre, finché qualcuno non le assegna

   esplicitamente un colore dal pannello "Colore" di categories_fc.php).

   ========================================================================== */

if (isset($_GET['cs_ajax']) && $_GET['cs_ajax'] === 'create_category') {

    header('Content-Type: application/json');

    if (!isset($conn) || !$conn) {

        require_once 'config.php';

        $conn = mysqli_connect(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);

        require_once 'auth_check_api.php';

        $current_user_id = $utente_corrente['id'];

    }

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



    $name = trim($_POST['name'] ?? '');

    $parentId = intval($_POST['parent_id'] ?? 0);



    if ($name === '') {

        echo json_encode(['success' => false, 'error' => 'Il nome è obbligatorio']);

        exit();

    }

    if (!$conn) {

        echo json_encode(['success' => false, 'error' => 'Connessione al database non disponibile']);

        exit();

    }

    $resolvedColor = cs_resolve_color($conn, $parentId);

    $nameEsc = mysqli_real_escape_string($conn, $name);

    $colorEsc = mysqli_real_escape_string($conn, $resolvedColor);

    mysqli_query($conn, "INSERT INTO fc_categories (name, parent_id, color, color_edited, user_id) VALUES ('$nameEsc', $parentId, '$colorEsc', 0, $current_user_id)");

    $newId = mysqli_insert_id($conn);

    if ($newId > 0) {

        echo json_encode(['success' => true, 'id' => $newId, 'name' => $name, 'parent_id' => $parentId, 'color' => $resolvedColor], JSON_UNESCAPED_UNICODE);

    } else {

        echo json_encode(['success' => false, 'error' => 'Errore durante il salvataggio']);

    }

    exit();

}

/* ==========================================================================

   CATEGORY SELECTOR — componente riutilizzabile (include in qualsiasi pagina)

   ==========================================================================

   Variabili opzionali da impostare PRIMA dell'include (tutte facoltative):



     $cs_id               (string) chiave univoca se in una pagina servono più

                                    selettori. Se NON la imposti, il componente

                                    si comporta esattamente come la vecchia

                                    versione (stessi id fissi: cat-selector-modal,

                                    category_id_input, category_btn_text) — piena

                                    retrocompatibilità con le pagine esistenti.

     $cs_selected_id      (int)    categoria preselezionata (default 0)

     $cs_selected_name    (string) nome da mostrare per la preselezione

     $cs_selected_color   (string) colore da mostrare per la preselezione

                                    (se omesso viene cercato nell'albero)

     $cs_placeholder      (string) testo del pulsante quando nulla è selezionato

     $cs_title            (string) titolo mostrato in cima al popup

     $cs_show_all_option  (bool)   se true, aggiunge in cima un'opzione jolly

     $cs_all_option_label (string) etichetta dell'opzione jolly

     $cs_render_trigger   (bool)   se mostrare il pulsante pronto all'uso

                                    (default: true se $cs_id è impostato, false

                                    in modalità legacy, dove di solito la pagina

                                    ha già un proprio pulsante che chiama

                                    openCategoryModal())

     $cs_allow_create     (bool)   se mostrare il tasto "+" per creare una nuova

                                    materia al volo (default true)



   La selezione viene comunicata in due modi, usa quello che preferisci:

     1) input nascosto con il valore dell'id (utile per i form classici)

     2) evento "categoryselected" (bubbles) con detail {uid, id, name, color} —

        ascolta con document.addEventListener('categoryselected', function(e){})

   ========================================================================== */



if (!isset($cs_id)) $cs_id = '';

if (!isset($cs_selected_id)) $cs_selected_id = 0;

if (!isset($cs_selected_name)) $cs_selected_name = '';

if (!isset($cs_selected_color)) $cs_selected_color = null;

if (!isset($cs_placeholder)) $cs_placeholder = 'Seleziona categoria';

if (!isset($cs_title)) $cs_title = 'Seleziona Materia';

if (!isset($cs_show_all_option)) $cs_show_all_option = false;

if (!isset($cs_all_option_label)) $cs_all_option_label = 'Tutte le materie';

if (!isset($cs_allow_create)) $cs_allow_create = true;

if (!isset($cs_hidden_name)) $cs_hidden_name = 'category_id';



$cs_is_legacy = ($cs_id === '');

if (!isset($cs_render_trigger)) $cs_render_trigger = !$cs_is_legacy;



$cs_uid = $cs_is_legacy ? '' : preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$cs_id);

if (!$cs_is_legacy && $cs_uid === '') $cs_uid = 'i' . substr(md5(uniqid('', true)), 0, 6);

$cs_nested_parent_uid = 'parentOf' . ($cs_uid !== '' ? $cs_uid : 'legacy');



$cs_modal_id  = $cs_is_legacy ? 'cat-selector-modal' : ('cs-modal-' . $cs_uid);

$cs_hidden_id = $cs_is_legacy ? 'category_id_input' : ('cs-value-' . $cs_uid);

$cs_text_id   = $cs_is_legacy ? 'category_btn_text' : ('cs-trigger-text-' . $cs_uid);

$cs_widget_id = $cs_is_legacy ? 'cs-widget-legacy' : ('cs-widget-' . $cs_uid);



$cs_has_value = ((int)$cs_selected_id > 0 && $cs_selected_name !== '');

$cs_initial_text = $cs_has_value ? $cs_selected_name : $cs_placeholder;



if (!function_exists('cs_lower')) {

    function cs_lower($s) {

        return function_exists('mb_strtolower') ? mb_strtolower((string)$s, 'UTF-8') : strtolower((string)$s);

    }

}



/* Stessa logica di risoluzione colore usata dall'endpoint AJAX qui sopra:

   dichiarata di nuovo con la guardia function_exists nel caso in cui questo

   file venga incluso senza mai passare dal ramo AJAX. */

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



/* Albero categorie con cache statica: se il file viene incluso più volte

   nella stessa pagina (anche annidato), la query gira una sola volta. */

if (!function_exists('cs_get_category_tree')) {

    function cs_get_category_tree($conn) {

        static $tree = null;

        if ($tree !== null) return $tree;

        $tree = [];

        if ($conn) {

            global $utente_corrente;

            $uid = (int) $utente_corrente['id'];

            $res = @mysqli_query($conn, "SELECT * FROM fc_categories WHERE user_id = $uid ORDER BY name");

            if ($res) {

                $all = [];

                $refs = [];

                while ($row = mysqli_fetch_assoc($res)) { $row['children'] = []; $all[] = $row; }

                foreach ($all as &$c) { $refs[$c['id']] = &$c; }

                unset($c);

                foreach ($all as &$c) {

                    $pid = isset($c['parent_id']) ? (int)$c['parent_id'] : 0;

                    if ($pid !== 0 && isset($refs[$pid])) { $refs[$pid]['children'][] = &$c; }

                    else { $tree[] = &$c; }

                }

                unset($c);

            }

        }

        return $tree;

    }

}



if (!function_exists('cs_find_color_in_tree')) {

    function cs_find_color_in_tree($nodes, $id) {

        foreach ($nodes as $n) {

            if ((int)$n['id'] === (int)$id) {

                return (!empty($n['color']) && preg_match('/^#[0-9A-Fa-f]{6}$/', $n['color'])) ? $n['color'] : '#007AFF';

            }

            if (!empty($n['children'])) {

                $found = cs_find_color_in_tree($n['children'], $id);

                if ($found !== null) return $found;

            }

        }

        return null;

    }

}



if (!function_exists('cs_render_node')) {

    function cs_render_node($node) {

        $hasChildren = !empty($node['children']);

        $nameEsc = htmlspecialchars($node['name'], ENT_QUOTES);

        $searchKey = htmlspecialchars(cs_lower($node['name']), ENT_QUOTES);

        $color = (!empty($node['color']) && preg_match('/^#[0-9A-Fa-f]{6}$/', $node['color'])) ? $node['color'] : '#007AFF';

        ?>

        <div class="cs-row-wrap" style="--row-color:<?php echo $color; ?>;">

            <div class="cs-row">

                <?php if ($hasChildren): ?>

                <button type="button" class="cs-arrow collapsed" data-cs-toggle aria-label="Espandi">

                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m9 6 6 6-6 6"/></svg>

                </button>

                <?php else: ?>

                <span class="cs-spacer"></span>

                <?php endif; ?>



                <span class="cs-icon">

                    <?php if ($hasChildren): ?>

                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z"/></svg>

                    <?php else: ?>

                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3v4a1 1 0 0 0 1 1h4"/><path d="M17 21H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h7l5 5v11a2 2 0 0 1-2 2Z"/></svg>

                    <?php endif; ?>

                </span>



                <span class="cs-name<?php echo $hasChildren ? ' cs-name-strong' : ''; ?>" data-cs-select="<?php echo (int)$node['id']; ?>" data-cs-name="<?php echo $nameEsc; ?>" data-cs-color="<?php echo $color; ?>" data-cs-search="<?php echo $searchKey; ?>">

                    <?php echo $nameEsc; ?>

                </span>

            </div>

            <?php if ($hasChildren): ?>

            <div class="cs-children collapsed">

                <?php foreach ($node['children'] as $child) { cs_render_node($child); } ?>

            </div>

            <?php endif; ?>

        </div>

        <?php

    }

}



/* Rende un'istanza del selettore in uno scope PHP isolato: usata per il

   selettore "categoria padre" annidato dentro il form di creazione, così le

   variabili $cs_* dell'istanza annidata non toccano quelle dell'istanza che

   la contiene. */

if (!function_exists('cs_render_instance')) {

    function cs_render_instance($config) {

        global $conn;

        foreach ($config as $cs_key => $cs_val) { $$cs_key = $cs_val; }

        include __DIR__ . '/category_selector.php';

    }

}



$cs_cat_tree = cs_get_category_tree(isset($conn) ? $conn : null);



if ($cs_has_value && $cs_selected_color === null) {

    $cs_selected_color = cs_find_color_in_tree($cs_cat_tree, $cs_selected_id);

}

if ($cs_selected_color === null) $cs_selected_color = '#007AFF';

?>

<?php if (!defined('CS_ASSETS_EMITTED')): define('CS_ASSETS_EMITTED', true); ?>

<style>

/* ================================================================

   DESIGN SYSTEM — fallback coerente con categories_fc.php / history_fc.php

   (se la pagina host definisce già questi stessi token su :root,

   i valori combaciano e non cambia nulla)

   ================================================================ */

:root{

    color-scheme: light dark;

    --bg:#F2F2F7; --surface:#FFFFFF; --surface-2:#F2F2F7;

    --text:#1C1C1E; --text-2:#8A8A8E; --text-3:#AEAEB2;

    --hairline:rgba(60,60,67,.12); --hairline-2:rgba(60,60,67,.22);

    --blue:#007AFF; --red:#FF3B30;

    --blue-soft:rgba(0,122,255,.09); --red-soft:rgba(255,59,48,.12);

    --btn-hl:inset 0 1px 0 rgba(255,255,255,.25), inset 0 -1px 0 rgba(0,0,0,.16);

    --spring:cubic-bezier(.32,.72,0,1);

    --safe-bottom:env(safe-area-inset-bottom, 0px);

}

@media (prefers-color-scheme: dark){

    :root{

        --bg:#000000; --surface:#1C1C1E; --surface-2:#2C2C2E;

        --text:#F5F5F7; --text-2:#98989F; --text-3:#636366;

        --hairline:rgba(84,84,88,.42); --hairline-2:rgba(84,84,88,.62);

        --blue:#0A84FF; --red:#FF453A;

        --blue-soft:rgba(10,132,255,.16); --red-soft:rgba(255,69,58,.17);

        --btn-hl:inset 0 1px 0 rgba(255,255,255,.22), inset 0 -1px 0 rgba(0,0,0,.3);

    }

}



/* ---------------- Pulsante trigger ---------------- */

.cs-widget{ display:block; }

.cs-trigger{

    width:100%; display:flex; align-items:center; gap:10px;

    padding:12px 14px; border:none; border-radius:13px; cursor:pointer;

    background:var(--surface-2); color:var(--text); font-family:inherit;

    box-shadow:inset 0 0 0 .5px var(--hairline-2);

    transition:transform .16s var(--spring), background .35s, box-shadow .35s;

    -webkit-tap-highlight-color:transparent;

}

.cs-trigger:active{ transform:scale(.985); }

.cs-trigger.cs-has-value{

    color:#fff;

    background:linear-gradient(180deg, color-mix(in srgb, var(--row-color, var(--blue)), #fff 20%), var(--row-color, var(--blue)) 55%, color-mix(in srgb, var(--row-color, var(--blue)), #000 8%));

    box-shadow:var(--btn-hl), 0 1px 2px rgba(0,0,0,.14), 0 10px 24px -10px color-mix(in srgb, var(--row-color, var(--blue)) 55%, transparent);

}

.cs-trigger.cs-has-value:active{ filter:brightness(.95); }

.cs-trigger-icon{

    flex-shrink:0; width:26px; height:26px; border-radius:8px;

    display:flex; align-items:center; justify-content:center;

    background:color-mix(in srgb, var(--row-color, var(--blue)) 16%, var(--surface));

    color:var(--row-color, var(--blue));

    transition:background .3s, color .3s;

}

.cs-trigger-icon svg{ width:14px; height:14px; }

.cs-trigger.cs-has-value .cs-trigger-icon{ background:rgba(255,255,255,.22); color:#fff; }

.cs-trigger-text{ flex:1; min-width:0; text-align:left; font-size:15px; font-weight:600; color:var(--text-3);

    white-space:nowrap; overflow:hidden; text-overflow:ellipsis; transition:color .3s; }

.cs-trigger.cs-has-value .cs-trigger-text{ color:#fff; font-weight:700; }

.cs-trigger-chev{ flex-shrink:0; width:14px; height:14px; color:var(--text-3); transition:color .3s; }

.cs-trigger.cs-has-value .cs-trigger-chev{ color:rgba(255,255,255,.85); }



/* ---------------- Overlay / Sheet ---------------- */

.cs-modal-overlay{

    display:none; position:fixed; inset:0; z-index:99999;

    justify-content:center; align-items:flex-end;

    background:rgba(0,0,0,.45);

    -webkit-backdrop-filter:blur(6px); backdrop-filter:blur(6px);

}

.cs-modal-overlay.closing{ animation:csOverlayOut .22s ease both; }

@keyframes csOverlayOut{ to{ opacity:0; } }



.cs-sheet-panel{

    position:relative; width:100%; max-width:440px;

    height:min(560px, 78vh); max-height:78vh; overflow:hidden; display:flex; flex-direction:column;

    background:var(--surface); color:var(--text);

    border-radius:22px 22px 0 0;

    padding:22px 16px calc(16px + var(--safe-bottom));

    box-shadow:0 -10px 50px rgba(0,0,0,.35);

    animation:csSheetUp .36s var(--spring) both;

}

.cs-modal-overlay.closing .cs-sheet-panel{ animation:csSheetDown .22s ease both; }

@keyframes csSheetUp{ from{ transform:translateY(55%); opacity:.4; } to{ transform:none; opacity:1; } }

@keyframes csSheetDown{ to{ transform:translateY(55%); opacity:0; } }

.cs-sheet-panel::before{

    content:""; position:absolute; top:8px; left:50%; transform:translateX(-50%);

    width:36px; height:5px; border-radius:99px; background:var(--hairline-2);

}

@media (min-width:640px){

    .cs-modal-overlay{ align-items:center; padding:20px; }

    .cs-sheet-panel{ border-radius:22px; }

}



.cs-sheet-head{ display:flex; align-items:center; justify-content:space-between; margin-bottom:12px; flex-shrink:0; }

.cs-sheet-title{ margin:4px 0 0; font-size:17px; font-weight:800; letter-spacing:-.01em; }

.cs-nav-btn{

    flex-shrink:0; width:28px; height:28px; border-radius:50%; border:none; cursor:pointer;

    background:var(--surface-2); color:var(--text-2); font-size:0;

    display:flex; align-items:center; justify-content:center;

    transition:transform .18s var(--spring);

}

.cs-nav-btn svg{ width:12px; height:12px; }

.cs-nav-btn:active{ background:var(--hairline); transform:scale(.88); }



/* ---------------- Ricerca ---------------- */

.cs-search-box{

    display:flex; align-items:center; gap:7px; margin-bottom:10px; flex-shrink:0;

    background:var(--surface-2); border-radius:11px; padding:9px 11px;

    box-shadow:inset 0 0 0 .5px var(--hairline);

}

.cs-search-box svg{ width:15px; height:15px; color:var(--text-2); flex-shrink:0; }

.cs-search-input{

    flex:1; min-width:0; border:none; outline:none; background:transparent; color:var(--text);

    font-size:14.5px; font-family:inherit; font-weight:500;

}

.cs-search-input::placeholder{ color:var(--text-3); }

.cs-search-clear{

    display:none; align-items:center; justify-content:center;

    width:18px; height:18px; border-radius:50%; border:none; cursor:pointer;

    background:var(--text-3); color:var(--surface); flex-shrink:0; padding:0;

}

.cs-search-clear svg{ width:9px; height:9px; }



/* ---------------- Opzione jolly ("Tutte" / "Nessuna") ---------------- */

.cs-all-option{

    display:flex; align-items:center; gap:9px; width:100%; flex-shrink:0;

    padding:11px 11px; margin-bottom:8px; border:none; border-radius:12px; cursor:pointer;

    background:var(--blue-soft); color:var(--blue); font-weight:700; font-size:14px;

    transition:transform .15s var(--spring);

}

.cs-all-option:active{ transform:scale(.98); }

.cs-all-option svg{ width:16px; height:16px; flex-shrink:0; }



/* ---------------- Albero ---------------- */

.cs-tree-scroll{ overflow-y:auto; -webkit-overflow-scrolling:touch; flex:1; min-height:0; padding-bottom:52px; }

.cs-row-wrap > .cs-children{ list-style:none; padding-left:20px; margin:0; overflow:hidden; max-height:2000px; transition:max-height .32s var(--spring), opacity .25s; opacity:1; }

.cs-row-wrap > .cs-children.collapsed{ max-height:0; opacity:0; }

.cs-row{ display:flex; align-items:center; gap:8px; padding:8px 4px; }

.cs-arrow, .cs-spacer{ width:18px; height:18px; flex-shrink:0; }

.cs-arrow{

    display:flex; align-items:center; justify-content:center; border:none; background:transparent;

    color:var(--text-3); cursor:pointer; border-radius:50%; padding:0;

    transform:rotate(90deg);

    transition:transform .25s var(--spring), color .2s;

}

.cs-arrow svg{ width:14px; height:14px; }

.cs-arrow.collapsed{ transform:rotate(0deg); }

.cs-arrow:not(.collapsed){ color:var(--row-color, var(--blue)); }

.cs-icon{

    width:24px; height:24px; border-radius:7px; flex-shrink:0; display:flex; align-items:center; justify-content:center;

    background:color-mix(in srgb, var(--row-color, var(--blue)) 18%, var(--surface-2));

    color:var(--row-color, var(--blue));

    transition:background .3s, color .3s;

}

.cs-icon svg{ width:13px; height:13px; }

.cs-name{ flex:1; min-width:0; font-size:14.5px; font-weight:500; color:var(--text); cursor:pointer; padding:6px 4px; border-radius:7px; }

.cs-name-strong{ font-weight:700; }

.cs-name:active{ background:var(--hairline); }



/* ---------------- Riga non selezionabile (già collegata altrove) ---------------- */

.cs-name.cs-row-disabled{ color:var(--text-3); cursor:not-allowed; }

.cs-name.cs-row-disabled:active{ background:transparent; }

.cs-row-wrap-disabled > .cs-row > .cs-name{ opacity:.55; }

.cs-row-wrap-disabled > .cs-row > .cs-icon{ background:var(--red-soft); color:var(--red); }



/* ================================================================

   Viste impilate (albero <-> nuova materia)

   Entrambe le viste occupano ESATTAMENTE lo stesso riquadro (inset:0) e la

   scelta di quale mostrare avviene solo con transform/opacity: non c'è mai

   un contenitore più largo del visibile, quindi non può "sbordare".

   ================================================================ */

.cs-views{ position:relative; flex:1; min-height:0; overflow:hidden; }

.cs-view{

    position:absolute; top:0; left:0; right:0; bottom:0;

    display:flex; flex-direction:column; min-height:0;

    transform:translateX(0); opacity:1;

    transition:transform .38s var(--spring), opacity .3s var(--spring);

}

.cs-view-create{ transform:translateX(100%); opacity:0; pointer-events:none; }

.cs-views.cs-show-create .cs-view-tree{ transform:translateX(-100%); opacity:0; pointer-events:none; }

.cs-views.cs-show-create .cs-view-create{ transform:translateX(0); opacity:1; pointer-events:auto; }



/* ---------------- FAB "aggiungi materia" ---------------- */

.cs-fab{

    position:absolute; right:12px; bottom:12px; z-index:5;

    width:46px; height:46px; border-radius:50%; border:none; cursor:pointer;

    color:#fff; background:linear-gradient(180deg, color-mix(in srgb, var(--blue), #fff 16%), var(--blue) 52%, color-mix(in srgb, var(--blue), #000 10%));

    display:flex; align-items:center; justify-content:center;

    box-shadow:var(--btn-hl), 0 2px 6px rgba(0,0,0,.18), 0 12px 26px -8px rgba(10,132,255,.6);

    transition:transform .16s var(--spring);

}

.cs-fab svg{ width:19px; height:19px; }

.cs-fab:active{ transform:scale(.88); }



/* ---------------- Vista "Nuova materia" ---------------- */

.cs-create-scroll{ flex:1; min-height:0; overflow-y:auto; -webkit-overflow-scrolling:touch; padding:2px 2px 4px; }

.cs-field{ margin-bottom:16px; }

.cs-field label{

    display:block; margin-bottom:7px; font-size:11px; font-weight:800;

    letter-spacing:.06em; text-transform:uppercase; color:var(--text-2);

}

.cs-field input[type="text"]{

    width:100%; padding:12px 13px; font-family:inherit; font-size:15.5px; font-weight:600;

    color:var(--text); background:var(--surface-2); border:.5px solid var(--hairline-2);

    border-radius:13px; -webkit-appearance:none; appearance:none;

}

.cs-field input:focus{ outline:none; box-shadow:0 0 0 2.5px var(--blue-soft), inset 0 0 0 1.5px var(--blue); }



.cs-color-preview-row{

    display:flex; align-items:center; gap:11px; padding:12px;

    background:var(--surface-2); border-radius:14px;

}

.cs-color-preview-icon{

    width:38px; height:38px; border-radius:10px; flex-shrink:0;

    display:flex; align-items:center; justify-content:center;

    background:color-mix(in srgb, var(--row-color, var(--blue)) 18%, var(--surface));

    color:var(--row-color, var(--blue));

    transition:background .3s, color .3s;

}

.cs-color-preview-icon svg{ width:19px; height:19px; }

.cs-color-preview-name{ font-size:15px; font-weight:700; flex:1; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }



.cs-create-error{ display:none; color:var(--red); font-size:13px; font-weight:600; margin:12px 0 0; }



.cs-btn{

    display:flex; align-items:center; justify-content:center; gap:7px; width:100%;

    padding:13px 16px; border:none; border-radius:14px; cursor:pointer;

    font-family:inherit; font-size:15.5px; font-weight:700; letter-spacing:-.01em;

    color:#fff; background:linear-gradient(180deg, color-mix(in srgb, var(--btn-color, var(--blue)), #fff 16%), var(--btn-color, var(--blue)) 52%, color-mix(in srgb, var(--btn-color, var(--blue)), #000 10%));

    box-shadow:var(--btn-hl), 0 10px 22px -8px color-mix(in srgb, var(--btn-color, var(--blue)) 55%, transparent);

    transition:transform .16s var(--spring), filter .2s, box-shadow .3s, background .3s;

    margin-top:18px;

}

.cs-btn:active{ transform:scale(.97); filter:brightness(.95); }

.cs-btn:disabled{ opacity:.6; pointer-events:none; }



/* ---------------- Toast ---------------- */

.cs-toast{

    position:fixed; left:50%; bottom:calc(26px + var(--safe-bottom)); transform:translateX(-50%) translateY(20px);

    background:rgba(28,28,30,.94); color:#fff; padding:11px 18px; border-radius:14px;

    font-size:13.5px; font-weight:700; box-shadow:0 12px 30px rgba(0,0,0,.35);

    opacity:0; pointer-events:none; z-index:100000; transition:opacity .3s var(--spring), transform .3s var(--spring);

    max-width:86vw;

}

.cs-toast.show{ opacity:1; transform:translateX(-50%) translateY(0); }



@media (prefers-reduced-motion: reduce){

    *{ animation-duration:.01ms !important; transition-duration:.01ms !important; }

}

</style>

<?php endif; ?>



<div class="cs-widget" id="<?php echo $cs_widget_id; ?>">

    <?php if ($cs_render_trigger): ?>

    <button type="button" class="cs-trigger<?php echo $cs_has_value ? ' cs-has-value' : ''; ?>" data-cs-open="<?php echo $cs_modal_id; ?>" style="<?php echo $cs_has_value ? ('--row-color:' . $cs_selected_color . ';') : ''; ?>">

        <span class="cs-trigger-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z"/></svg></span>

        <span class="cs-trigger-text" id="<?php echo $cs_text_id; ?>"><?php echo htmlspecialchars($cs_initial_text, ENT_QUOTES); ?></span>

        <svg class="cs-trigger-chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>

    </button>

    <?php endif; ?>

    <input type="hidden" name="<?php echo htmlspecialchars($cs_hidden_name, ENT_QUOTES); ?>" id="<?php echo $cs_hidden_id; ?>" value="<?php echo (int)$cs_selected_id; ?>">

</div>



<div id="<?php echo $cs_modal_id; ?>" class="cs-modal-overlay" data-cs-uid="<?php echo htmlspecialchars($cs_uid, ENT_QUOTES); ?>" data-cs-hidden="<?php echo $cs_hidden_id; ?>" data-cs-text="<?php echo $cs_text_id; ?>" data-cs-widget="<?php echo $cs_widget_id; ?>" data-cs-title="<?php echo htmlspecialchars($cs_title, ENT_QUOTES); ?>">

    <div class="cs-sheet-panel">

        <div class="cs-sheet-head">

            <h3 class="cs-sheet-title"><?php echo htmlspecialchars($cs_title, ENT_QUOTES); ?></h3>

            <button type="button" class="cs-nav-btn" data-cs-mode="close" aria-label="Chiudi">

                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>

            </button>

        </div>



        <div class="cs-views">

            <div class="cs-view cs-view-tree">

                <div class="cs-search-box">

                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>

                    <input type="text" class="cs-search-input" placeholder="Cerca materia" autocomplete="off">

                    <button type="button" class="cs-search-clear" aria-label="Cancella ricerca">

                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>

                    </button>

                </div>



                <div class="cs-tree-scroll">

                    <?php if ($cs_show_all_option): ?>

                    <button type="button" class="cs-all-option" data-cs-select="0" data-cs-name="<?php echo htmlspecialchars($cs_all_option_label, ENT_QUOTES); ?>" data-cs-color="#A0A0A0">

                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M3.6 9h16.8M3.6 15h16.8M12 3a14.5 14.5 0 0 1 0 18M12 3a14.5 14.5 0 0 0 0 18"/></svg>

                        <?php echo htmlspecialchars($cs_all_option_label, ENT_QUOTES); ?>

                    </button>

                    <?php endif; ?>



                    <?php if (!empty($cs_cat_tree)): ?>

                        <?php foreach ($cs_cat_tree as $cs_node) { cs_render_node($cs_node); } ?>

                    <?php else: ?>

                        <p style="color:var(--text-2); text-align:center; font-size:14px; padding:20px 0;">Nessuna categoria trovata.</p>

                    <?php endif; ?>

                </div>



                <?php if ($cs_allow_create): ?>

                <button type="button" class="cs-fab" data-cs-fab aria-label="Aggiungi materia">

                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>

                </button>

                <?php endif; ?>

            </div>



            <?php if ($cs_allow_create): ?>

            <div class="cs-view cs-view-create">

                <div class="cs-create-scroll">

                    <div class="cs-field">

                        <label>Nome materia</label>

                        <input type="text" class="cs-name-input" placeholder="Es. Analisi Matematica">

                    </div>



                    <div class="cs-field">

                        <label>Categoria padre</label>

                        <?php

                        cs_render_instance([

                            'cs_id' => $cs_nested_parent_uid,

                            'cs_placeholder' => 'Nessuna (categoria principale)',

                            'cs_title' => 'Categoria Padre',

                            'cs_show_all_option' => true,

                            'cs_all_option_label' => 'Nessuna (categoria principale)',

                            'cs_allow_create' => false,

                        ]);

                        ?>

                    </div>



                    <div class="cs-field">

                        <label>Colore (ereditato)</label>

                        <div class="cs-color-preview-row">

                            <span class="cs-color-preview-icon" style="--row-color:#A0A0A0;">

                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z"/></svg>

                            </span>

                            <span class="cs-color-preview-name">Nuova materia</span>

                        </div>

                    </div>



                    <p class="cs-create-error"></p>

                    <button type="button" class="cs-btn cs-create-save" style="--btn-color:#A0A0A0;">Crea e seleziona</button>

                </div>

            </div>

            <?php endif; ?>

        </div>

    </div>

</div>



<?php if (!defined('CS_SCRIPT_EMITTED')): define('CS_SCRIPT_EMITTED', true); ?>

<script>

(function () {

    if (window.__csInit) return;

    window.__csInit = true;



    function csOpen(modal) {

        if (!modal) return;

        csResetCreateView(modal);

        modal.style.display = 'flex';

        modal.classList.remove('closing');

    }

    function csClose(modal) {

        if (!modal) return;

        modal.classList.add('closing');

        setTimeout(function () {

            modal.style.display = 'none';

            modal.classList.remove('closing');

        }, 220);

    }

    function csApplySelection(overlay, id, name, color) {

        if (!overlay) return;

        var hiddenId = overlay.getAttribute('data-cs-hidden');

        var textId = overlay.getAttribute('data-cs-text');

        var widgetId = overlay.getAttribute('data-cs-widget');

        var uid = overlay.getAttribute('data-cs-uid') || '';

        var finalColor = color || '#007AFF';



        if (hiddenId) { var h = document.getElementById(hiddenId); if (h) h.value = id; }

        if (textId) {

            var t = document.getElementById(textId);

            if (t) { t.textContent = name; }

        }

        var widget = widgetId && document.getElementById(widgetId);

        if (widget) {

            var triggerBtn = widget.querySelector('.cs-trigger');

            if (triggerBtn) {

                // bug: prima veniva sempre aggiunta 'cs-has-value' (con colore blu di
                // fallback) anche quando id=0, cioè "nessuna materia collegata" —
                // ora lo sfondo colorato compare solo se è davvero selezionata una materia
                if (parseInt(id, 10) > 0) {
                    triggerBtn.classList.add('cs-has-value');
                    triggerBtn.style.setProperty('--row-color', finalColor);
                } else {
                    triggerBtn.classList.remove('cs-has-value');
                    triggerBtn.style.removeProperty('--row-color');
                }

            }

        }

        var target = widget || overlay;

        try {

            target.dispatchEvent(new CustomEvent('categoryselected', { bubbles: true, detail: { uid: uid, id: id, name: name, color: finalColor } }));

        } catch (e) {}

        try { if (navigator.vibrate) navigator.vibrate(10); } catch (e) {}

    }



    /* ---------------- Vista albero <-> vista "Nuova materia" ---------------- */

    function csSwitchView(overlay, view) {

        var views = overlay.querySelector('.cs-views');

        var title = overlay.querySelector('.cs-sheet-title');

        var navBtn = overlay.querySelector('.cs-nav-btn');

        if (!views) return;

        if (view === 'create') {

            views.classList.add('cs-show-create');

            if (title) title.textContent = 'Nuova Materia';

            if (navBtn) {

                navBtn.setAttribute('data-cs-mode', 'back');

                navBtn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="m15 6-6 6 6 6"/></svg>';

            }

            var createView = overlay.querySelector('.cs-view-create');

            var nameInput = createView ? createView.querySelector('.cs-name-input') : null;

            if (nameInput) setTimeout(function () { nameInput.focus(); }, 320);

        } else {

            views.classList.remove('cs-show-create');

            if (title) title.textContent = overlay.getAttribute('data-cs-title') || 'Seleziona Materia';

            if (navBtn) {

                navBtn.setAttribute('data-cs-mode', 'close');

                navBtn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>';

            }

        }

    }

    function csSetPreviewColor(root, hex) {

        if (!root) return;

        var icon = root.querySelector('.cs-color-preview-icon');

        if (icon) icon.style.setProperty('--row-color', hex);

        var saveBtn = root.querySelector('.cs-create-save');

        if (saveBtn) saveBtn.style.setProperty('--btn-color', hex);

    }

    function csResetCreateView(overlay) {

        csSwitchView(overlay, 'tree');

        var createView = overlay.querySelector('.cs-view-create');

        if (!createView) return;

        var nameInput = createView.querySelector('.cs-name-input');

        if (nameInput) nameInput.value = '';

        var errorEl = createView.querySelector('.cs-create-error');

        if (errorEl) errorEl.style.display = 'none';

        var previewName = createView.querySelector('.cs-color-preview-name');

        if (previewName) previewName.textContent = 'Nuova materia';

        csSetPreviewColor(createView, '#A0A0A0');



        var nestedHidden = createView.querySelector('input[type="hidden"]');

        if (nestedHidden) nestedHidden.value = '0';

        var nestedText = createView.querySelector('.cs-trigger-text');

        if (nestedText) {

            nestedText.textContent = 'Nessuna (categoria principale)';

            nestedText.classList.remove('cs-has-value');

        }

        var nestedIcon = createView.querySelector('.cs-trigger-icon');

        if (nestedIcon) nestedIcon.style.setProperty('--row-color', '#A0A0A0');

    }



    /* Quando il selettore "categoria padre" annidato nella vista di creazione

       comunica una selezione, aggiorna qui l'anteprima colore della materia

       che si sta creando. */

    document.addEventListener('categoryselected', function (e) {

        var createView = e.target && e.target.closest ? e.target.closest('.cs-view-create') : null;

        if (!createView) return;

        csSetPreviewColor(createView, (e.detail && e.detail.color) || '#A0A0A0');

    });



    /* ---------------- Toast ---------------- */

    var csToastTimer = null;

    function csShowToast(msg) {

        var toast = document.querySelector('.cs-toast');

        if (!toast) {

            toast = document.createElement('div');

            toast.className = 'cs-toast';

            document.body.appendChild(toast);

        }

        toast.textContent = msg;

        toast.classList.add('show');

        clearTimeout(csToastTimer);

        csToastTimer = setTimeout(function () { toast.classList.remove('show'); }, 2200);

    }



    /* ---------------- Creazione al volo di una nuova materia ---------------- */

    function csEscapeHtml(s) {

        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {

            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];

        });

    }

    function csBuildNodeHTML(node) {

        var color = node.color || '#A0A0A0';

        var nameEsc = csEscapeHtml(node.name);

        var searchKey = nameEsc.toLowerCase();

        var iconSvg = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3v4a1 1 0 0 0 1 1h4"/><path d="M17 21H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h7l5 5v11a2 2 0 0 1-2 2Z"/></svg>';

        return '<div class="cs-row-wrap" style="--row-color:' + color + ';">' +

            '<div class="cs-row"><span class="cs-spacer"></span>' +

            '<span class="cs-icon">' + iconSvg + '</span>' +

            '<span class="cs-name" data-cs-select="' + node.id + '" data-cs-name="' + nameEsc + '" data-cs-color="' + color + '" data-cs-search="' + searchKey + '">' + nameEsc + '</span>' +

            '</div></div>';

    }

    function csInsertNodeIntoTree(overlay, node) {

        var wrapper = document.createElement('div');

        wrapper.innerHTML = csBuildNodeHTML(node);

        var newRowWrap = wrapper.firstElementChild;

        var scroll = overlay.querySelector('.cs-view-tree > .cs-tree-scroll');



        var parentId = String(node.parent_id || '0');

        if (parentId === '0') {

            if (scroll) scroll.appendChild(newRowWrap);

            return;

        }

        var parentNameEl = overlay.querySelector('.cs-view-tree [data-cs-select="' + parentId + '"]');

        var parentWrap = parentNameEl ? parentNameEl.closest('.cs-row-wrap') : null;

        if (!parentWrap) {

            if (scroll) scroll.appendChild(newRowWrap);

            return;

        }

        var kids = parentWrap.querySelector(':scope > .cs-children');

        if (!kids) {

            var row = parentWrap.querySelector(':scope > .cs-row');

            var spacer = row.querySelector(':scope > .cs-spacer');

            if (spacer) {

                var arrowBtn = document.createElement('button');

                arrowBtn.type = 'button';

                arrowBtn.className = 'cs-arrow';

                arrowBtn.setAttribute('data-cs-toggle', '');

                arrowBtn.setAttribute('aria-label', 'Espandi');

                arrowBtn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m9 6 6 6-6 6"/></svg>';

                spacer.replaceWith(arrowBtn);

            }

            var iconEl = row.querySelector(':scope > .cs-icon');

            if (iconEl) {

                iconEl.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z"/></svg>';

            }

            var nameEl = row.querySelector(':scope > .cs-name');

            if (nameEl) nameEl.classList.add('cs-name-strong');

            kids = document.createElement('div');

            kids.className = 'cs-children';

            parentWrap.appendChild(kids);

        }

        kids.classList.remove('collapsed');

        kids.appendChild(newRowWrap);

    }

    function csSubmitCreate(overlay) {

        var createView = overlay.querySelector('.cs-view-create');

        var nameInput = createView.querySelector('.cs-name-input');

        var errorEl = createView.querySelector('.cs-create-error');

        var name = (nameInput.value || '').trim();

        if (!name) {

            errorEl.textContent = 'Inserisci un nome per la materia';

            errorEl.style.display = 'block';

            nameInput.focus();

            return;

        }

        errorEl.style.display = 'none';

        var parentHidden = createView.querySelector('input[type="hidden"]');

        var parentId = parentHidden ? parentHidden.value : '0';

        var saveBtn = createView.querySelector('.cs-create-save');

        saveBtn.disabled = true;



        var body = new URLSearchParams();

        body.set('name', name);

        body.set('parent_id', parentId);



        fetch('category_selector.php?cs_ajax=create_category', { method: 'POST', body: body })

            .then(function (r) { return r.json(); })

            .then(function (data) {

                saveBtn.disabled = false;

                if (data && data.success) {

                    csInsertNodeIntoTree(overlay, data);

                    csApplySelection(overlay, data.id, data.name, data.color);

                    csShowToast('Materia creata');

                    csClose(overlay);

                } else {

                    errorEl.textContent = (data && data.error) || 'Errore durante il salvataggio';

                    errorEl.style.display = 'block';

                }

            })

            .catch(function () {

                saveBtn.disabled = false;

                errorEl.textContent = 'Errore di rete';

                errorEl.style.display = 'block';

            });

    }



    /* "Porta" ogni modal come figlio diretto di <body>: se il componente viene

       incluso dentro un pannello con transform/overflow attivi (es. un sheet

       che scorre con transition:transform), un position:fixed al suo interno

       resterebbe intrappolato in quel contenitore invece di coprire lo schermo. */

    function csPortalModals() {

        document.querySelectorAll('.cs-modal-overlay').forEach(function (m) {

            if (m.parentElement !== document.body) document.body.appendChild(m);

        });

    }

    if (document.readyState === 'loading') {

        document.addEventListener('DOMContentLoaded', csPortalModals);

    } else {

        csPortalModals();

    }



    document.addEventListener('click', function (e) {

        var openBtn = e.target.closest('[data-cs-open]');

        if (openBtn) { csOpen(document.getElementById(openBtn.getAttribute('data-cs-open'))); return; }



        var navBtn = e.target.closest('.cs-nav-btn');

        if (navBtn) {

            var overlayNav = navBtn.closest('.cs-modal-overlay');

            if (navBtn.getAttribute('data-cs-mode') === 'back') { csSwitchView(overlayNav, 'tree'); }

            else { csClose(overlayNav); }

            return;

        }



        if (e.target.classList && e.target.classList.contains('cs-modal-overlay')) { csClose(e.target); return; }



        var toggleBtn = e.target.closest('[data-cs-toggle]');

        if (toggleBtn) {

            var wrap = toggleBtn.closest('.cs-row-wrap');

            var kids = wrap ? wrap.querySelector(':scope > .cs-children') : null;

            if (kids) {

                var collapsed = kids.classList.toggle('collapsed');

                toggleBtn.classList.toggle('collapsed', collapsed);

            }

            return;

        }



        var fabBtn = e.target.closest('[data-cs-fab]');

        if (fabBtn) { csSwitchView(fabBtn.closest('.cs-modal-overlay'), 'create'); return; }



        var saveBtn = e.target.closest('.cs-create-save');

        if (saveBtn) { csSubmitCreate(saveBtn.closest('.cs-modal-overlay')); return; }



        var selectEl = e.target.closest('[data-cs-select]');

        if (selectEl) {

            if (selectEl.classList.contains('cs-row-disabled')) {

                csShowToast(selectEl.getAttribute('data-cs-disabled-reason') || 'Questa materia non è selezionabile.');

                return;

            }

            var overlay = selectEl.closest('.cs-modal-overlay');

            if (!overlay) return;

            csApplySelection(overlay, selectEl.getAttribute('data-cs-select'), selectEl.getAttribute('data-cs-name'), selectEl.getAttribute('data-cs-color'));

            csClose(overlay);

            return;

        }

    });



    document.addEventListener('input', function (e) {

        var input = e.target.closest('.cs-search-input');

        if (input) { csFilterTree(input.closest('.cs-modal-overlay'), input.value); return; }



        var nameInput = e.target.closest('.cs-name-input');

        if (nameInput) {

            var createView = nameInput.closest('.cs-view-create');

            var previewName = createView ? createView.querySelector('.cs-color-preview-name') : null;

            if (previewName) previewName.textContent = nameInput.value.trim() || 'Nuova materia';

        }

    });



    function csFilterTree(overlay, raw) {

        if (!overlay) return;

        var q = (raw || '').trim().toLowerCase();

        var panel = overlay.querySelector('.cs-view-tree');

        var clearBtn = panel ? panel.querySelector('.cs-search-clear') : null;

        if (clearBtn) clearBtn.style.display = q ? 'flex' : 'none';

        var wraps = overlay.querySelectorAll('.cs-view-tree .cs-row-wrap');



        if (!q) {

            wraps.forEach(function (w) {

                w.style.display = '';

                var kids = w.querySelector(':scope > .cs-children');

                if (kids) kids.classList.add('collapsed');

                var arrow = w.querySelector(':scope > .cs-row > .cs-arrow');

                if (arrow) arrow.classList.add('collapsed');

            });

            return;

        }



        wraps.forEach(function (w) { w.style.display = 'none'; });

        wraps.forEach(function (w) {

            var nameEl = w.querySelector(':scope > .cs-row > .cs-name');

            var match = nameEl && (nameEl.getAttribute('data-cs-search') || '').indexOf(q) !== -1;

            if (!match) return;

            var cur = w;

            while (cur) {

                cur.style.display = '';

                var kids = cur.querySelector(':scope > .cs-children');

                if (kids) kids.classList.remove('collapsed');

                var arrow = cur.querySelector(':scope > .cs-row > .cs-arrow');

                if (arrow) arrow.classList.remove('collapsed');

                cur = cur.parentElement ? cur.parentElement.closest('.cs-row-wrap') : null;

            }

        });

    }



    document.addEventListener('keydown', function (e) {

        if (e.key !== 'Escape') return;

        var open = document.querySelector('.cs-modal-overlay[style*="flex"]');

        if (open) csClose(open);

    });



    /* Valore impostabile da codice esterno, es. per resettare un filtro */

    window.csSetValue = window.csSetValue || function (modalId, id, name, color) {

        csApplySelection(document.getElementById(modalId), id, name, color);

    };



    /* Alias retrocompatibili con la vecchia versione del componente */

    window.openCategoryModal = window.openCategoryModal || function () { csOpen(document.getElementById('cat-selector-modal')); };

    window.closeCategoryModal = window.closeCategoryModal || function () { csClose(document.getElementById('cat-selector-modal')); };

    window.toggleCatTree = window.toggleCatTree || function (id, btn) {

        var wrap = btn ? btn.closest('.cs-row-wrap') : null;

        var kids = wrap ? wrap.querySelector(':scope > .cs-children') : null;

        if (kids) {

            var collapsed = kids.classList.toggle('collapsed');

            btn.classList.toggle('collapsed', collapsed);

        }

    };

    window.selectCategory = window.selectCategory || function (id, name, color) {

        var overlay = document.getElementById('cat-selector-modal');

        csApplySelection(overlay, id, name, color);

        csClose(overlay);

    };

})();

</script>

<?php endif; ?>

<?php

unset(

    $cs_id, $cs_selected_id, $cs_selected_name, $cs_selected_color, $cs_placeholder, $cs_title,

    $cs_show_all_option, $cs_all_option_label, $cs_render_trigger, $cs_allow_create, $cs_hidden_name, $cs_is_legacy,

    $cs_uid, $cs_nested_parent_uid, $cs_modal_id, $cs_hidden_id, $cs_text_id, $cs_widget_id,

    $cs_has_value, $cs_initial_text, $cs_cat_tree, $cs_node

);

?>