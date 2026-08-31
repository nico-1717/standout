<?php

/* ==========================================================================

   ENDPOINT AJAX: creazione rapida di un nuovo progetto dal popup

   (chiamato direttamente come project_selector.php?ps_ajax=create_project,

   non tramite include, quindi apre una propria connessione se necessario)



   Il colore NON viene mai accettato dal client: viene sempre risolto lato

   server dalla catena dei padri, e color_edited viene sempre impostato a 0

   (un progetto appena creato eredita sempre, finché l'utente non gli assegna

   esplicitamente un colore dalla pagina progetti.php).

   ========================================================================== */

if (isset($_GET['ps_ajax']) && $_GET['ps_ajax'] === 'create_project') {

    header('Content-Type: application/json');

    if (!isset($conn) || !$conn) {

        require_once 'config.php';

        $conn = mysqli_connect(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);

        require_once 'auth_check_api.php';

        $current_user_id = $utente_corrente['id'];

    }

    /* creare un progetto è una scrittura: serve il token della sessione */

    if (function_exists('sec_guard_write')) {

        sec_guard_write((int)($current_user_id ?? ($utente_corrente['id'] ?? 0)), 'selector', 0, 120, 60);

    }

    if (!function_exists('ps_resolve_color')) {

        function ps_resolve_color($conn, $parentId, $userId) {

            $parentId = intval($parentId);

            $guard = 0;

            while ($parentId > 0 && $guard < 50) {

                $guard++;

                $res = mysqli_query($conn, "SELECT parent_id, color, color_edited FROM projects WHERE id = " . $parentId . " AND user_id = " . intval($userId));

                $row = $res ? mysqli_fetch_assoc($res) : null;

                if (!$row) break;

                if (intval($row['color_edited']) === 1 && !empty($row['color'])) {

                    return $row['color'];

                }

                $parentId = intval($row['parent_id']);

            }

            return '#8E8E93';

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



    // Verifica che il padre (se indicato) appartenga davvero all'utente corrente

    if ($parentId > 0) {

        $chk = mysqli_query($conn, "SELECT id FROM projects WHERE id = $parentId AND user_id = " . intval($current_user_id));

        if (!$chk || mysqli_num_rows($chk) === 0) { $parentId = 0; }

    }



    $resolvedColor = ps_resolve_color($conn, $parentId, $current_user_id);

    $nameEsc = mysqli_real_escape_string($conn, $name);

    $colorEsc = mysqli_real_escape_string($conn, $resolvedColor);

    $parentSql = $parentId > 0 ? $parentId : 'NULL';



    $ordRes = mysqli_query($conn, "SELECT COALESCE(MAX(custom_order), -1) + 1 AS next_order FROM projects WHERE user_id = " . intval($current_user_id) . " AND " . ($parentId > 0 ? "parent_id = $parentId" : "parent_id IS NULL"));

    $nextOrder = $ordRes ? intval(mysqli_fetch_assoc($ordRes)['next_order']) : 0;



    mysqli_query($conn, "INSERT INTO projects (user_id, parent_id, name, color, color_edited, status, custom_order, created_at)

                          VALUES (" . intval($current_user_id) . ", $parentSql, '$nameEsc', '$colorEsc', 0, 'in_progress', $nextOrder, NOW())");

    $newId = mysqli_insert_id($conn);

    if ($newId > 0) {

        echo json_encode(['success' => true, 'id' => $newId, 'name' => $name, 'parent_id' => $parentId, 'color' => $resolvedColor], JSON_UNESCAPED_UNICODE);

    } else {

        echo json_encode(['success' => false, 'error' => 'Errore durante il salvataggio']);

    }

    exit();

}


/* ==========================================================================

   RESA AUTONOMA: project_selector.php?ps_render=1&...

   Serve al popup della barra del pomodoro, che vive su pagine qualunque e

   non puo' includere questo file da PHP: se lo includesse, il selettore

   finirebbe su tutte le pagine anche quando nessuno lo aprira' mai.

   Cosi' invece se lo va a prendere con una fetch al primo tocco, esattamente

   come fa con la clessidra — e resta UN solo selettore, questo, non una

   seconda copia semplificata da tenere allineata a mano.

   Come il ramo qui sopra, apre da se' quello che gli serve: chiamato

   direttamente non c'e' nessuna pagina che abbia gia' preparato $conn.

   E' una lettura e basta: nessuna scrittura, quindi nessun token da

   verificare, ma il login sì — l'albero dei progetti e' roba dell'utente.

   ========================================================================== */

if (isset($_GET['ps_render'])) {

    if (!isset($conn) || !$conn) {

        require_once 'config.php';

        $conn = mysqli_connect(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);

        require_once 'auth_check_api.php';

        $current_user_id = $utente_corrente['id'];

    }

    if (!$conn) { http_response_code(500); exit(); }

    /* I testi arrivano dall'indirizzo perche' chi chiama sa in che contesto

       sta ("Collega progetto" nel pomodoro, altro altrove) e questo file non

       deve conoscere i suoi chiamanti. Escono tutti da htmlspecialchars piu'

       in basso, come quelli passati per include. */

    $ps_id               = (string)($_GET['ps_id'] ?? 'std');

    $ps_selected_id      = (int)($_GET['ps_selected_id'] ?? 0);

    $ps_selected_name    = (string)($_GET['ps_selected_name'] ?? '');

    $ps_selected_color   = ($_GET['ps_selected_color'] ?? '') !== '' ? (string)$_GET['ps_selected_color'] : null;

    $ps_title            = (string)($_GET['ps_title'] ?? 'Seleziona progetto');

    $ps_placeholder      = (string)($_GET['ps_placeholder'] ?? $ps_title);

    $ps_show_all_option  = !empty($_GET['ps_show_all_option']);

    $ps_all_option_label = (string)($_GET['ps_all_option_label'] ?? 'Nessun progetto');

    $ps_allow_create     = !isset($_GET['ps_allow_create']) || !empty($_GET['ps_allow_create']);

    /* Niente pulsante: chi chiede la resa autonoma ha gia' il suo, e apre il

       popup con window.psOpen(). Renderne un secondo, invisibile in fondo a

       una pagina che non se lo aspetta, sarebbe solo un modo di sbagliare. */

    $ps_render_trigger   = false;

    /* Da qui in poi prosegue il file normale: stessa resa dell'include. */

}



/* ==========================================================================

   PROJECT SELECTOR — componente riutilizzabile (include in qualsiasi pagina)

   ==========================================================================

   Stesso identico contratto di category_selector.php, per coerenza:



     $ps_id               (string) chiave univoca se in una pagina servono più

                                    selettori (es. uno per il progetto della

                                    sessione pomodoro, uno per il "padre" di un

                                    progetto). Obbligatoria se usi più istanze.

     $ps_selected_id      (int)    progetto preselezionato (default 0)

     $ps_selected_name    (string) nome da mostrare per la preselezione

     $ps_selected_color   (string) colore da mostrare (se omesso, cercato in albero)

     $ps_placeholder      (string) testo del pulsante quando nulla è selezionato

     $ps_title            (string) titolo mostrato in cima al popup

     $ps_show_all_option  (bool)   se true, aggiunge in cima un'opzione jolly

     $ps_all_option_label (string) etichetta dell'opzione jolly (es. "Nessun progetto")

     $ps_render_trigger   (bool)   se mostrare il pulsante pronto all'uso (default true)

     $ps_allow_create     (bool)   se mostrare il tasto "+" per creare un progetto al volo

     $ps_exclude_id       (int)    esclude un progetto (e i suoi figli) dall'albero:

                                    serve quando scegli il PADRE di un progetto che stai

                                    spostando, per non poterlo mai rendere figlio di se stesso



   La selezione viene comunicata in due modi:

     1) input nascosto con il valore dell'id (per i form classici / fetch manuali)

     2) evento "projectselected" (bubbles) con detail {uid, id, name, color} —

        ascolta con document.addEventListener('projectselected', function(e){})

   ========================================================================== */



if (!isset($ps_id)) $ps_id = '';

if (!isset($ps_selected_id)) $ps_selected_id = 0;

if (!isset($ps_selected_name)) $ps_selected_name = '';

if (!isset($ps_selected_color)) $ps_selected_color = null;

if (!isset($ps_placeholder)) $ps_placeholder = 'Seleziona progetto';

if (!isset($ps_title)) $ps_title = 'Seleziona Progetto';

if (!isset($ps_show_all_option)) $ps_show_all_option = false;

if (!isset($ps_all_option_label)) $ps_all_option_label = 'Nessun progetto';

if (!isset($ps_allow_create)) $ps_allow_create = true;

if (!isset($ps_hidden_name)) $ps_hidden_name = 'project_id';

if (!isset($ps_exclude_id)) $ps_exclude_id = 0;

if (!isset($ps_trigger_class)) $ps_trigger_class = ''; // es. 'ps-trigger-pill' per il tasto a pillola



$ps_is_legacy = ($ps_id === '');

if (!isset($ps_render_trigger)) $ps_render_trigger = true;



$ps_uid = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$ps_id);

if ($ps_uid === '') $ps_uid = 'i' . substr(md5(uniqid('', true)), 0, 6);



$ps_modal_id  = 'ps-modal-' . $ps_uid;

$ps_hidden_id = 'ps-value-' . $ps_uid;

$ps_text_id   = 'ps-trigger-text-' . $ps_uid;

$ps_widget_id = 'ps-widget-' . $ps_uid;



$ps_has_value = ((int)$ps_selected_id > 0 && $ps_selected_name !== '');

$ps_initial_text = $ps_has_value ? $ps_selected_name : $ps_placeholder;



if (!function_exists('ps_lower')) {

    function ps_lower($s) {

        return function_exists('mb_strtolower') ? mb_strtolower((string)$s, 'UTF-8') : strtolower((string)$s);

    }

}



if (!function_exists('ps_resolve_color')) {

    function ps_resolve_color($conn, $parentId, $userId) {

        $parentId = intval($parentId);

        $guard = 0;

        while ($parentId > 0 && $guard < 50) {

            $guard++;

            $res = mysqli_query($conn, "SELECT parent_id, color, color_edited FROM projects WHERE id = " . $parentId . " AND user_id = " . intval($userId));

            $row = $res ? mysqli_fetch_assoc($res) : null;

            if (!$row) break;

            if (intval($row['color_edited']) === 1 && !empty($row['color'])) return $row['color'];

            $parentId = intval($row['parent_id']);

        }

        return '#8E8E93';

    }

}



if (!function_exists('ps_build_branch')) {

    function ps_build_branch($id, $byId, $childrenOf, $disabledIds = []) {

        if (!isset($byId[$id])) return null;

        $node = $byId[$id];

        // Controlla se questo nodo fa parte di quelli da disabilitare

        $node['is_disabled'] = in_array($id, $disabledIds);



        if (!empty($childrenOf[$id])) {

            foreach ($childrenOf[$id] as $childId) {

                $childNode = ps_build_branch($childId, $byId, $childrenOf, $disabledIds);

                if ($childNode) $node['children'][] = $childNode;

            }

        }

        return $node;

    }

}



if (!function_exists('ps_get_project_tree')) {

    function ps_get_project_tree($conn, $userId, $excludeId = 0) {

        $byId = [];

        $childrenOf = [];

        $res = mysqli_query($conn, "SELECT id, parent_id, name, color, color_edited, status AS stato FROM projects WHERE user_id = " . intval($userId) . " ORDER BY custom_order ASC, id ASC");

        while ($res && ($row = mysqli_fetch_assoc($res))) {

            $row['children'] = [];

            $byId[(int)$row['id']] = $row;

            $pid = $row['parent_id'] === null ? 0 : (int)$row['parent_id'];

            $childrenOf[$pid][] = (int)$row['id'];

        }



        // --- ABBIAMO RIMOSSO IL BLOCCO "unset($byId[$cur])" CHE CANCELLAVA I DATI ---

        // Recuperiamo comunque la lista dei nodi disabilitati (il progetto stesso e i suoi figli)

        $disabledIds = [];

        if ($excludeId > 0 && isset($byId[$excludeId])) {

            $toDrop = [$excludeId];

            while (!empty($toDrop)) {

                $cur = array_pop($toDrop);

                $disabledIds[] = $cur;

                if (!empty($childrenOf[$cur])) {

                    foreach ($childrenOf[$cur] as $childId) { $toDrop[] = $childId; }

                }

            }

        }



        $tree = [];

        foreach (($childrenOf[0] ?? []) as $rootId) {

            // Passiamo anche l'array dei disabilitati alla funzione di build

            $node = ps_build_branch($rootId, $byId, $childrenOf, $disabledIds);

            if ($node) $tree[] = $node;

        }

        return $tree;

    }

}



if (!function_exists('ps_render_node')) {

    function ps_render_node($node, $inFav = false, $path = '') {

        $color = (!empty($node['color']) && preg_match('/^#[0-9A-Fa-f]{6}$/', $node['color'])) ? $node['color'] : '#8E8E93';

        $hasChildren = !empty($node['children']);

        $nameEsc = htmlspecialchars($node['name'], ENT_QUOTES);

        $searchKey = htmlspecialchars(ps_lower($node['name']), ENT_QUOTES);

        $isDone = ($node['stato'] === 'completed');

        $isDisabled = !empty($node['is_disabled']); // <--- Controllo disabilitazione

        ?>

        <?php
        /* Dentro i preferiti lo stesso progetto compare due volte: in cima
           come scorciatoia e più sotto al posto suo nell'albero. Nella copia
           l'attributo che lo identifica cambia nome, se no psExpandTo()
           troverebbe per prima la scorciatoia e srotolerebbe quella invece del
           ramo vero. Aprire e chiudere i figli invece funziona in tutt'e due
           le copie senza fare niente, perché la freccetta lavora sul riquadro
           che ha intorno e non sull'identificativo. */
        ?>
        <div class="ps-row-wrap<?php echo $inFav ? ' ps-fav-node' : ''; ?>" <?php echo $inFav ? 'data-ps-fav-id' : 'data-ps-id'; ?>="<?php echo (int)$node['id']; ?>" style="--row-color:<?php echo $isDisabled ? '#8E8E93' : $color; ?>;">

            <div class="ps-row">

                <?php if ($hasChildren): ?>

                <button type="button" class="ps-arrow" onclick="window.psToggleTree(<?php echo (int)$node['id']; ?>, this)" aria-label="Espandi">

                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="m9 6 6 6-6 6"/></svg>

                </button>

                <?php else: ?>

                <span class="ps-spacer"></span>

                <?php endif; ?>

                <span class="ps-dot" style="<?php echo $isDisabled ? 'background:#8E8E93;' : ''; ?>"></span>

                

                <!-- Aggiunta classe ps-name-disabled se il progetto è disabilitato -->

                <span class="ps-name<?php echo $hasChildren ? ' ps-name-strong' : ''; ?><?php echo $isDone ? ' ps-name-done' : ''; ?><?php echo $isDisabled ? ' ps-name-disabled' : ''; ?>"

                      <?php if (!$isDisabled): ?>

                      data-ps-select="<?php echo (int)$node['id']; ?>"

                      data-ps-name="<?php echo $nameEsc; ?>"

                      data-ps-color="<?php echo $color; ?>"

                      <?php endif; ?>

                      data-ps-search="<?php echo $searchKey; ?>">

                    <?php echo $nameEsc; ?><?php if ($isDone): ?><span class="ps-done-check">✓</span><?php endif; ?>
                </span>
                <?php if ($path !== ''): ?>
                <span class="ps-fav-path"><?php echo htmlspecialchars($path, ENT_QUOTES); ?></span>
                <?php endif; ?>
            </div>
            <?php if ($hasChildren): ?>
            <div class="ps-children collapsed">
                <?php /* i figli di un preferito restano dentro i preferiti: se no
                          la scorciatoia e il ramo vero si mescolerebbero */ ?>
                <?php foreach ($node['children'] as $child) { ps_render_node($child, $inFav); } ?>
            </div>
            <?php endif; ?>

        </div>

        <?php

    }

}



/* Il ramo di un progetto dentro l'albero già costruito. Si riusa quello e non
   si rifà una lettura apposta: l'albero sa già chi è escluso e chi è
   disabilitato, e due letture diverse potrebbero dire due cose diverse. */
if (!function_exists('ps_find_in_tree')) {
    function ps_find_in_tree($nodi, $id) {
        foreach ($nodi as $n) {
            if ((int)$n['id'] === (int)$id) return $n;
            if (!empty($n['children'])) {
                $trovato = ps_find_in_tree($n['children'], $id);
                if ($trovato) return $trovato;
            }
        }
        return null;
    }
}

$ps_tree = ps_get_project_tree($conn, $current_user_id, $ps_exclude_id);

/* Lo stesso albero srotolato in un elenco piatto, con la profondita' di ogni
   riga. Serve alla tendina "dentro a" della creazione: una tendina non puo'
   essere annidata, quindi il rientro lo si fa con gli spazi. Si costruisce da
   $ps_tree e non con una seconda query: e' esattamente lo stesso insieme di
   progetti, comprese le esclusioni, e leggerlo due volte vorrebbe dire poterlo
   leggere in due modi diversi. */
if (!function_exists('ps_flatten')) {
    function ps_flatten(array $nodi, int $depth = 0, array $out = []): array {
        foreach ($nodi as $n) {
            $out[] = ['id' => (int)$n['id'], 'name' => (string)$n['name'], 'depth' => $depth];
            if (!empty($n['children'])) $out = ps_flatten($n['children'], $depth + 1, $out);
        }
        return $out;
    }
}
$ps_flat = ps_flatten($ps_tree);



if (!empty($ps_selected_id) && $ps_has_value === false) {

    // preselezionato ma senza nome/colore espliciti: prova a recuperarli

}

?>

<?php if (!defined('PS_ASSETS_EMITTED')): define('PS_ASSETS_EMITTED', true); ?>

<style>

.ps-widget{ display:inline-block; width:100%; }

.ps-trigger{

    display:flex; align-items:center; gap:10px; width:100%;

    background:var(--surface-2, #F5F1EA); border:none; border-radius:var(--r-btn, 15px);

    padding:13px 14px; font-size:15px; font-weight:600; color:var(--text, #4A443D);

    box-shadow:inset 0 0 0 .5px var(--hairline, rgba(74,68,61,.12)); cursor:pointer; text-align:left;

    transition:transform .18s var(--spring), background-color .2s;

}

.ps-trigger:active{ transform:scale(.98); background:var(--surface); }

.ps-trigger::before{

    content:''; width:11px; height:11px; border-radius:50%; flex-shrink:0;

    background:var(--row-color, var(--text-3, #B7AEA3));

    box-shadow:0 0 0 3px color-mix(in srgb, var(--row-color, var(--text-3, #B7AEA3)) 20%, transparent);

}

.ps-trigger:not(.ps-has-value)::before{ background:var(--text-3, #B7AEA3); box-shadow:none; }

.ps-trigger-text{ flex:1; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }

.ps-trigger:not(.ps-has-value) .ps-trigger-text{ color:var(--text-2, #8A8177); font-weight:500; }

.ps-trigger-chev{ color:var(--text-3, #B7AEA3); flex-shrink:0; width:16px; height:16px; }



/* ---------------- variante "a pillola" (riusabile ovunque: sopra al timer,

   inserimento manuale, ecc.) — attivata passando $ps_trigger_class = 'ps-trigger-pill' ---------------- */

.ps-trigger.ps-trigger-pill{

    background:color-mix(in srgb, var(--surface) 78%, transparent);

    -webkit-backdrop-filter:blur(18px) saturate(180%); backdrop-filter:blur(18px) saturate(180%);

    box-shadow:inset 0 0 0 .5px var(--hairline, rgba(74,68,61,.12)), 0 4px 14px -6px rgba(0,0,0,.18);

    border-radius:999px; padding:9px 16px 9px 12px;

}

.ps-trigger.ps-trigger-pill:active{ transform:scale(.98); background:var(--surface-2, #F5F1EA); }

.ps-trigger.ps-trigger-pill .ps-trigger-text{ font-weight:600; font-size:14.5px; }

.ps-trigger.ps-trigger-pill .ps-trigger-chev{ display:none; }



.ps-modal-overlay{

    position:fixed; inset:0; z-index:9800; display:flex; align-items:flex-end; justify-content:center;

    background:rgba(0,0,0,.4); -webkit-backdrop-filter:blur(6px); backdrop-filter:blur(6px);

    opacity:0; pointer-events:none; transition:opacity .28s var(--spring);

}

.ps-modal-overlay.ps-open{ opacity:1; pointer-events:auto; }

.ps-sheet-panel{

    /* Le variabili --surface, --text ecc. esistono in progetti e pomodoro ma

       non in planner, diario e attività: senza un valore di ripiego il

       pannello restava trasparente e si vedeva il velo scuro attraverso. */

    width:100%; max-width:640px; max-height:82vh; background:var(--surface, #FDFCF9);

    border-radius:24px 24px 0 0; box-shadow:0 -10px 40px rgba(0,0,0,.3);

    display:flex; flex-direction:column; transform:translateY(100%);

    transition:transform .38s var(--spring);

    padding-bottom:var(--safe-bottom, 0px);

}

.ps-modal-overlay.ps-open .ps-sheet-panel{ transform:translateY(0); }

.ps-sheet-head{ display:flex; align-items:center; justify-content:space-between; padding:16px 18px 10px; border-bottom:.5px solid var(--hairline, rgba(74,68,61,.12)); flex-shrink:0; }

.ps-sheet-title{ margin:0; font-size:17px; font-weight:800; }

.ps-sheet-close{ width:30px; height:30px; border-radius:50%; border:none; background:var(--surface-2, #F5F1EA); color:var(--text-2, #8A8177); display:flex; align-items:center; justify-content:center; cursor:pointer; }

.ps-sheet-close:active{ transform:scale(.9); }



.ps-search-box{ margin:12px 16px; display:flex; align-items:center; gap:8px; background:var(--surface-2, #F5F1EA); border-radius:12px; padding:10px 12px; flex-shrink:0; }

.ps-search-box svg{ width:16px; height:16px; color:var(--text-2, #8A8177); flex-shrink:0; }

.ps-search-box input{ flex:1; min-width:0; border:none; outline:none; background:transparent; color:var(--text, #4A443D); font-size:15px; font-family:inherit; }



.ps-view-tree{ display:flex; flex-direction:column; flex:1; min-height:0; }

.ps-fav-head{

    font-size:9px; font-weight:600; letter-spacing:.18em; text-transform:uppercase;

    color:var(--text-3, #B7AEA3); padding:10px 6px 6px;

}

.ps-fav-row{

    display:flex; align-items:baseline; gap:8px; width:100%; text-align:left;

    padding:9px 8px; border:none; background:none; cursor:pointer; border-radius:12px;

    font:inherit; color:var(--text, #4A443D);

}

.ps-fav-row:active{ background:var(--surface-2, #F5F1EA); }

.ps-fav-dot{ width:9px; height:9px; border-radius:50%; flex-shrink:0; align-self:center; }

.ps-fav-name{ font-size:15px; }

.ps-fav-path{

    font-size:11px; color:var(--text-3, #B7AEA3); margin-left:auto;

    overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:48%;

}

.ps-fav-sep{ height:1px; background:var(--hairline, rgba(74,68,61,.12)); margin:8px 6px 4px; }
/* Il percorso accanto al nome, dentro i preferiti: dice da dove viene quel
   progetto senza dover aprire l'albero. Va in fondo alla riga e si stringe per
   primo, perché il nome è quello che si legge. */
.ps-fav-node > .ps-row > .ps-fav-path{
    margin-left:auto; padding-left:8px; flex:0 1 auto; min-width:0;
    overflow:hidden; text-overflow:ellipsis; white-space:nowrap;
}
/* solo la riga in cima porta il percorso: sui figli sarebbe la stessa cosa
   ripetuta, e il posto lo prendono meglio i loro nomi */
.ps-fav-node .ps-children .ps-fav-path{ display:none; }



.ps-tree-scroll{ flex:1; min-height:0; overflow-y:auto; -webkit-overflow-scrolling:touch; padding:2px 10px 14px; }

.ps-all-option{

    width:100%; display:flex; align-items:center; gap:10px; padding:12px 10px; border:none; background:none;

    font-size:15px; font-weight:600; color:var(--text, #4A443D); border-radius:12px; cursor:pointer; text-align:left;

}

.ps-all-option:active{ background:var(--surface-2, #F5F1EA); }

.ps-all-option::before{ content:''; width:11px; height:11px; border-radius:50%; background:var(--text-3, #B7AEA3); flex-shrink:0; }



.ps-row-wrap{ position:relative; }

.ps-row{ display:flex; align-items:center; gap:6px; padding:2px 0; }

.ps-arrow{ width:34px; height:34px; margin:-4px 0; border:none; background:none; color:var(--text-3, #B7AEA3); display:flex; align-items:center; justify-content:center; cursor:pointer; flex-shrink:0; transition:transform .28s var(--spring); }

.ps-arrow svg{ width:13px; height:13px; flex-shrink:0; }

.ps-arrow.open{ transform:rotate(90deg); }

.ps-spacer{ width:34px; flex-shrink:0; }

.ps-dot{ width:9px; height:9px; border-radius:50%; background:var(--row-color); flex-shrink:0; }

.ps-name{ flex:1; padding:11px 8px; border-radius:11px; font-size:15px; font-weight:500; color:var(--text, #4A443D); cursor:pointer; }

.ps-name:active{ background:var(--surface-2, #F5F1EA); }

.ps-name-strong{ font-weight:700; }

.ps-name-done{ color:var(--text-2, #8A8177); }

.ps-name-disabled {

    color: var(--text-3, #8E8E93) !important;

    cursor: not-allowed !important;

    font-style: italic;

}

.ps-name-disabled:active {

    background: none !important; /* Rimuove l'effetto click grigio */

}

.ps-done-check{ color:var(--green); margin-left:6px; font-weight:700; }

.ps-children{ margin-left:26px; border-left:1.5px solid var(--hairline, rgba(74,68,61,.12)); padding-left:6px; overflow:hidden; max-height:2000px; transition:max-height .32s var(--spring), opacity .25s; }

.ps-children.collapsed{ max-height:0; opacity:0; pointer-events:none; }



.ps-create-trigger{

    display:flex; align-items:center; gap:8px; width:100%; margin-top:8px; padding:12px; border-radius:12px;

    border:1.5px dashed var(--hairline-2); background:none; color:var(--blue); font-weight:700; font-size:14px; cursor:pointer;

}

.ps-create-trigger:active{ background:var(--blue-soft); }

/* "Crea «...»" in fondo a una ricerca senza risultati. Ha il peso di un
   invito, non di un errore: quando non si trova quello che si cerca, la cosa
   utile da offrire e' farlo, non dire che non c'e'. */
.ps-crea-vuoto{
    display:flex; align-items:center; justify-content:center; gap:8px;
    width:calc(100% - 32px); margin:14px 16px; padding:13px;
    border:none; border-radius:13px; cursor:pointer; font:inherit; font-size:14.5px; font-weight:600;
    background:var(--blue-soft, rgba(0,122,255,.09)); color:var(--blue, #007AFF);
}
.ps-crea-vuoto:active{ transform:scale(.98); }
.ps-new-parent{
    width:100%; padding:12px 13px; font-family:inherit; font-size:15px; font-weight:600;
    color:var(--text, #4A443D); background:var(--surface-2, #F5F1EA);
    border:none; border-radius:13px; -webkit-appearance:none; appearance:none;
}

.ps-view-create{ display:none; flex-direction:column; padding:16px 18px calc(18px + var(--safe-bottom, 0px)); }

.ps-view-create.ps-active{ display:flex; }

.ps-view-tree.ps-hidden{ display:none; }

.ps-field label{ display:block; font-size:12px; font-weight:700; color:var(--text-2, #8A8177); text-transform:uppercase; letter-spacing:.5px; margin-bottom:6px; }

.ps-field input{ width:100%; padding:13px 14px; border-radius:13px; border:none; background:var(--surface-2, #F5F1EA); color:var(--text, #4A443D); font-size:16px; font-family:inherit; box-shadow:inset 0 0 0 .5px var(--hairline, rgba(74,68,61,.12)); }

.ps-create-actions{ display:flex; gap:10px; margin-top:16px; }

.ps-btn{ flex:1; padding:13px; border-radius:13px; border:none; font-size:15px; font-weight:700; cursor:pointer; }

.ps-btn-cancel{ background:var(--surface-2, #F5F1EA); color:var(--text, #4A443D); }

.ps-btn-save{ background:var(--blue); color:#fff; box-shadow:var(--btn-hl); }

.ps-btn-save:active, .ps-btn-cancel:active{ transform:scale(.97); }



.ps-toast{

    position:fixed; left:50%; bottom:calc(28px + var(--safe-bottom, 0px)); transform:translate(-50%, 12px);

    background:rgba(28,28,30,.92); color:#fff; padding:11px 18px; border-radius:14px; font-size:13.5px; font-weight:600;

    z-index:9900; opacity:0; pointer-events:none; transition:opacity .25s, transform .25s var(--spring);

    -webkit-backdrop-filter:blur(10px); backdrop-filter:blur(10px);

}

.ps-toast.show{ opacity:1; transform:translate(-50%, 0); }

</style>

<?php endif; ?>



<div class="ps-widget" id="<?php echo $ps_widget_id; ?>">

    <?php if ($ps_render_trigger): ?>

    <button type="button" class="ps-trigger<?php echo $ps_has_value ? ' ps-has-value' : ''; ?><?php echo $ps_trigger_class !== '' ? ' ' . htmlspecialchars($ps_trigger_class, ENT_QUOTES) : ''; ?>" data-ps-open="<?php echo $ps_modal_id; ?>" style="<?php echo $ps_has_value ? ('--row-color:' . htmlspecialchars($ps_selected_color ?: '#8E8E93', ENT_QUOTES) . ';') : ''; ?>">

        <span class="ps-trigger-text" id="<?php echo $ps_text_id; ?>"><?php echo htmlspecialchars($ps_initial_text, ENT_QUOTES); ?></span>

        <svg class="ps-trigger-chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m9 6 6 6-6 6"/></svg>

    </button>

    <?php endif; ?>

    <input type="hidden" name="<?php echo htmlspecialchars($ps_hidden_name, ENT_QUOTES); ?>" id="<?php echo $ps_hidden_id; ?>" value="<?php echo (int)$ps_selected_id; ?>">

</div>



<div id="<?php echo $ps_modal_id; ?>" class="ps-modal-overlay" data-sb-popup data-ps-uid="<?php echo htmlspecialchars($ps_uid, ENT_QUOTES); ?>" data-ps-hidden="<?php echo $ps_hidden_id; ?>" data-ps-text="<?php echo $ps_text_id; ?>" data-ps-widget="<?php echo $ps_widget_id; ?>" data-ps-title="<?php echo htmlspecialchars($ps_title, ENT_QUOTES); ?>">

    <div class="ps-sheet-panel">

        <div class="ps-sheet-head">

            <h3 class="ps-sheet-title"><?php echo htmlspecialchars($ps_title, ENT_QUOTES); ?></h3>

            <button type="button" class="ps-sheet-close" onclick="window.psClose(document.getElementById('<?php echo $ps_modal_id; ?>'))" aria-label="Chiudi">

                <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>

            </button>

        </div>



        <div class="ps-view-tree">

            <div class="ps-search-box">

                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>

                <input type="text" placeholder="Cerca progetto" oninput="window.psFilterTree(this.closest('.ps-modal-overlay'), this.value)" autocomplete="off">

            </div>



            <div class="ps-tree-scroll">

                <?php if ($ps_show_all_option): ?>

                <button type="button" class="ps-all-option" data-ps-select="0" data-ps-name="<?php echo htmlspecialchars($ps_all_option_label, ENT_QUOTES); ?>" data-ps-color="">

                    <?php echo htmlspecialchars($ps_all_option_label, ENT_QUOTES); ?>

                </button>

                <?php endif; ?>



                <?php

                /* Preferiti: in cima, con accanto il percorso in piccolo così

                   si capisce da dove vengono senza aprire tutto l'albero. */

                $ps_favs = [];

                $psFavRes = mysqli_query($conn, "SELECT id, name, color, parent_id FROM projects

                    WHERE user_id = " . intval($current_user_id) . " AND is_favorite = 1 ORDER BY name");

                if ($psFavRes) { while ($fr = mysqli_fetch_assoc($psFavRes)) $ps_favs[] = $fr; }



                if (!empty($ps_favs)):

                    /* percorso: risaliamo i genitori una volta sola per tutti */

                    $psNames = [];

                    $psParents = [];

                    $psAllRes = mysqli_query($conn, "SELECT id, name, parent_id FROM projects WHERE user_id = " . intval($current_user_id));

                    if ($psAllRes) {

                        while ($ar = mysqli_fetch_assoc($psAllRes)) {

                            $psNames[(int)$ar['id']] = $ar['name'];

                            $psParents[(int)$ar['id']] = $ar['parent_id'] !== null ? (int)$ar['parent_id'] : 0;

                        }

                    }

                ?>

                <div class="ps-fav-block">
                <div class="ps-fav-head">Preferiti</div>
                <?php foreach ($ps_favs as $fav):
                    $path = [];
                    $cur = isset($psParents[(int)$fav['id']]) ? $psParents[(int)$fav['id']] : 0;
                    $guard = 0;
                    while ($cur > 0 && $guard++ < 12) {
                        if (isset($psNames[$cur])) array_unshift($path, $psNames[$cur]);
                        $cur = isset($psParents[$cur]) ? $psParents[$cur] : 0;
                    }
                    /* Il preferito si disegna come un nodo dell'albero e non
                       come una riga piatta: così si porta dietro i suoi
                       sotto-progetti e la freccetta per aprirli. Prima la
                       scorciatoia mostrava solo il progetto in cima, e per
                       arrivare a un suo figlio bisognava chiudere i preferiti
                       e ritrovare il ramo nell'albero — cioè fare a mano
                       proprio quello che il preferito doveva evitare.
                       Se non si trova nell'albero vuol dire che è escluso
                       (di solito perché è il progetto che si sta modificando):
                       in quel caso si salta, come lo salta l'albero. */
                    $favNode = ps_find_in_tree($ps_tree, (int)$fav['id']);
                    if (!$favNode) continue;
                    ps_render_node($favNode, true, implode(' › ', $path));
                endforeach; ?>
                <div class="ps-fav-sep"></div>
                </div>
                <?php endif; ?>



                <?php if (!empty($ps_tree)): ?>

                    <?php foreach ($ps_tree as $ps_node) { ps_render_node($ps_node); } ?>

                <?php else: ?>

                    <div style="padding:24px 8px; text-align:center; color:var(--text-2, #8A8177); font-size:14px;">Nessun progetto ancora — creane uno qui sotto.</div>

                <?php endif; ?>

            </div>



            <?php if ($ps_allow_create): ?>

            <div style="padding:0 16px calc(16px + var(--safe-bottom, 0px));">

                <button type="button" class="ps-create-trigger" onclick="window.psSwitchView(this.closest('.ps-modal-overlay'), 'create')">

                    <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>

                    Nuovo progetto

                </button>

            </div>

            <?php endif; ?>

        </div>



        <?php if ($ps_allow_create): ?>

        <div class="ps-view-create">

            <div class="ps-field">

                <label>Nome progetto</label>

                <input type="text" class="ps-new-name" placeholder="Es. Tesi, Palestra, Viaggio...">

            </div>

            <!-- Dove va a finire. Prima ogni progetto creato al volo nasceva in
                 cima all'albero, e chi lo creava da dentro un contesto preciso
                 (una materia, un esame) doveva poi andarlo a spostare a mano in
                 progetti: due passaggi che, avendo fretta, si saltano — e il
                 progetto resta orfano alla radice. Qui si sceglie subito, e il
                 valore proposto e' quello che si stava guardando. -->
            <div class="ps-field">

                <label>Dentro a</label>

                <select class="ps-new-parent">
                    <option value="0"><?php echo sec_h(t('progetti.nessun_padre')); ?></option>
<?php foreach ($ps_flat as $ps_riga): ?>
                    <option value="<?php echo (int)$ps_riga['id']; ?>"><?php echo str_repeat('&nbsp;&nbsp;', (int)$ps_riga['depth']) . sec_h($ps_riga['name']); ?></option>
<?php endforeach; ?>
                </select>

            </div>

            <div class="ps-create-actions">

                <button type="button" class="ps-btn ps-btn-cancel" onclick="window.psSwitchView(this.closest('.ps-modal-overlay'), 'tree')">Annulla</button>

                <button type="button" class="ps-btn ps-btn-save" onclick="window.psSubmitCreate(this.closest('.ps-modal-overlay'))">Crea</button>

            </div>

        </div>

        <?php endif; ?>

    </div>

</div>



<?php if (!defined('PS_SCRIPT_EMITTED')): define('PS_SCRIPT_EMITTED', true); ?>

<script>

(function () {

    'use strict';



    function psOpen(modal) {

        if (!modal) return;

        psPortalModals();

        modal.classList.add('ps-open');

        document.documentElement.classList.add('sb-locked');

    }

    function psClose(modal) {

        if (!modal) return;

        modal.classList.remove('ps-open');

        setTimeout(function () {

            if (!document.querySelector('.ps-modal-overlay.ps-open')) document.documentElement.classList.remove('sb-locked');

        }, 300);

    }



    function psApplySelection(overlay, id, name, color) {

        var hiddenId = overlay.getAttribute('data-ps-hidden');

        var textId = overlay.getAttribute('data-ps-text');

        var widgetId = overlay.getAttribute('data-ps-widget');

        var hidden = document.getElementById(hiddenId);

        var text = document.getElementById(textId);

        var widget = document.getElementById(widgetId);

        if (hidden) hidden.value = id;

        /* Si tiene traccia di cosa e' selezionato adesso: e' quello che la
           creazione propone come progetto padre. Sull'overlay e non in una
           variabile perche' di selettori ce n'e' piu' d'uno per pagina, ognuno
           con la sua scelta. */
        overlay.setAttribute('data-ps-current', String(id || 0));

        if (text) text.textContent = name;

        var trigger = widget ? widget.querySelector('.ps-trigger') : null;

        if (trigger) {

            if (parseInt(id, 10) > 0) {

                trigger.classList.add('ps-has-value');

                trigger.style.setProperty('--row-color', color || '#8E8E93');

            } else {

                trigger.classList.remove('ps-has-value');

                trigger.style.removeProperty('--row-color');

            }

        }

        var detailUid = overlay.getAttribute('data-ps-uid');

        var ev = new CustomEvent('projectselected', { bubbles: true, detail: { uid: detailUid, id: parseInt(id, 10), name: name, color: color } });

        (widget || document).dispatchEvent(ev);

        psClose(overlay);

    }



    function psSwitchView(overlay, view) {

        var treeView = overlay.querySelector('.ps-view-tree');

        var createView = overlay.querySelector('.ps-view-create');

        if (!treeView || !createView) return;

        if (view === 'create') {

            treeView.classList.add('ps-hidden');

            createView.classList.add('ps-active');

            var nameInput = createView.querySelector('.ps-new-name');

            /* Quello che si stava cercando diventa il nome del progetto nuovo:
               chi cerca "Analisi 2" e non lo trova sta gia' dicendo come si
               chiama. Riscriverlo daccapo e' il passaggio che fa rinunciare. */
            var cerca = overlay.querySelector('.ps-search-box input');
            if (nameInput && !nameInput.value && cerca && cerca.value.trim()) {
                nameInput.value = cerca.value.trim();
            }
            /* Il padre proposto e' quello che si sta guardando: se il selettore
               era gia' su un progetto, il nuovo nasce dentro quello. Resta
               cambiabile — e' una proposta, non una decisione presa al posto
               dell'utente. */
            var parentSel = createView.querySelector('.ps-new-parent');
            var giaScelto = overlay.getAttribute('data-ps-current') || '0';
            if (parentSel && giaScelto !== '0' && parentSel.querySelector('option[value="' + giaScelto + '"]')) {
                parentSel.value = giaScelto;
            }

            if (nameInput) { nameInput.value = ''; setTimeout(function () { nameInput.focus(); }, 280); }

        } else {

            treeView.classList.remove('ps-hidden');

            createView.classList.remove('ps-active');

        }

    }



    var psToastTimer = null;

    function psShowToast(msg) {

        var toast = document.querySelector('.ps-toast');

        if (!toast) {

            toast = document.createElement('div');

            toast.className = 'ps-toast';

            document.body.appendChild(toast);

        }

        toast.textContent = msg;

        toast.classList.add('show');

        clearTimeout(psToastTimer);

        psToastTimer = setTimeout(function () { toast.classList.remove('show'); }, 2200);

    }



    function psEscapeHtml(s) {

        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {

            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];

        });

    }



    function psBuildNodeHTML(node) {

        var color = node.color || '#8E8E93';

        return '<div class="ps-row-wrap" style="--row-color:' + color + ';">' +

            '<div class="ps-row"><span class="ps-spacer"></span><span class="ps-dot"></span>' +

            '<span class="ps-name" data-ps-select="' + node.id + '" data-ps-name="' + psEscapeHtml(node.name) + '" data-ps-color="' + color + '" data-ps-search="' + psEscapeHtml(node.name.toLowerCase()) + '">' + psEscapeHtml(node.name) + '</span>' +

            '</div></div>';

    }



    function psSubmitCreate(overlay) {

        var createView = overlay.querySelector('.ps-view-create');

        var nameInput = createView ? createView.querySelector('.ps-new-name') : null;

        var name = nameInput ? nameInput.value.trim() : '';

        if (!name) { if (nameInput) nameInput.focus(); return; }

        var parentSel = createView ? createView.querySelector('.ps-new-parent') : null;
        var parentId = parentSel ? (parseInt(parentSel.value, 10) || 0) : 0;

        var saveBtn = overlay.querySelector('.ps-btn-save');

        if (saveBtn) { saveBtn.disabled = true; saveBtn.style.opacity = '.6'; }



        fetch('project_selector.php?ps_ajax=create_project', {

            method: 'POST',

            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },

            body: 'name=' + encodeURIComponent(name) + '&parent_id=' + parentId

        })

        .then(function (r) { return r.json(); })

        .then(function (data) {

            if (saveBtn) { saveBtn.disabled = false; saveBtn.style.opacity = '1'; }

            if (!data.success) { psShowToast(data.error || 'Errore durante la creazione'); return; }

            var scroll = overlay.querySelector('.ps-tree-scroll');

            if (scroll) scroll.insertAdjacentHTML('beforeend', psBuildNodeHTML(data));

            psSwitchView(overlay, 'tree');

            psApplySelection(overlay, data.id, data.name, data.color);

            psShowToast('Progetto creato');

        })

        .catch(function () {

            if (saveBtn) { saveBtn.disabled = false; saveBtn.style.opacity = '1'; }

            psShowToast('Errore di rete');

        });

    }



    function psPortalModals() {

        document.querySelectorAll('.ps-modal-overlay').forEach(function (m) {

            if (m.parentElement !== document.body) document.body.appendChild(m);

        });

    }



    document.addEventListener('click', function (e) {

        var openBtn = e.target.closest('[data-ps-open]');

        if (openBtn) { psOpen(document.getElementById(openBtn.getAttribute('data-ps-open'))); return; }



        var selectEl = e.target.closest('[data-ps-select]');

        if (selectEl) {

            var overlay = selectEl.closest('.ps-modal-overlay');

            if (overlay) psApplySelection(overlay, selectEl.getAttribute('data-ps-select'), selectEl.getAttribute('data-ps-name'), selectEl.getAttribute('data-ps-color'));

            return;

        }



        var arrow = e.target.closest('.ps-arrow');

        if (arrow) {

            // Lasciamo gestire l'espansione all'attributo onclick nativo

            return;

        }



        if (e.target.classList && e.target.classList.contains('ps-modal-overlay')) {

            psClose(e.target);

        }

    });



    document.addEventListener('input', function (e) {

        if (!e.target.matches('.ps-search-box input')) return;

        var overlay = e.target.closest('.ps-modal-overlay');

        if (overlay) psFilterTree(overlay, e.target.value);

    });



    /* Il blocco dei preferiti sparisce se la ricerca non ci trova niente
       dentro: da quando i preferiti sono nodi veri il filtro li nasconde uno
       a uno, e senza questo resterebbe in cima la scritta «Preferiti» con il
       vuoto sotto. */
    function psFavVisibili(overlay) {
        var blocco = overlay.querySelector('.ps-fav-block');
        if (!blocco) return;
        var vivi = blocco.querySelectorAll('.ps-row-wrap');
        var uno = false;
        vivi.forEach(function (w) { if (w.style.display !== 'none') uno = true; });
        blocco.style.display = uno ? '' : 'none';
    }

    function psFilterTree(overlay, raw) {
        var q = ps_norm(raw);
        var wraps = overlay.querySelectorAll('.ps-row-wrap');
        if (!q) {
            wraps.forEach(function (w) { w.style.display = ''; });
            overlay.querySelectorAll('.ps-children').forEach(function (c) { /* lascia lo stato collapsed com'era */ });
            psFavVisibili(overlay);
            return;
        }

        wraps.forEach(function (w) { w.style.display = 'none'; });

        wraps.forEach(function (w) {

            var nameEl = w.querySelector(':scope > .ps-row .ps-name');

            var text = nameEl ? (nameEl.getAttribute('data-ps-search') || '') : '';

            if (text.indexOf(q) !== -1) {

                w.style.display = '';

                var p = w.parentElement;

                while (p && p !== overlay) {

                    if (p.classList && p.classList.contains('ps-row-wrap')) { p.style.display = ''; }

                    if (p.classList && p.classList.contains('ps-children')) { p.classList.remove('collapsed'); }

                    p = p.parentElement;

                }
            }
        });
        psFavVisibili(overlay);
        psProponiCreazione(overlay, raw);
    }

    /* La ricerca a vuoto non e' un vicolo cieco. Se nessun progetto ha quel
       nome, sotto compare "Crea «...»": e' il momento esatto in cui l'utente
       ha scoperto che il progetto non esiste, e mandarlo a cercare il tasto
       "Nuovo progetto" in fondo alla lista, o peggio in un'altra pagina, e' il
       passaggio che fa rinunciare — e un blocco senza progetto e' un blocco
       fuori da tutte le statistiche. */
    function psProponiCreazione(overlay, raw) {
        var testo = String(raw || '').trim();
        var vecchio = overlay.querySelector('.ps-crea-vuoto');
        if (vecchio) vecchio.remove();
        if (!testo) return;
        if (!overlay.querySelector('.ps-view-create')) return;   // creazione spenta su questa pagina

        var visibili = 0;
        overlay.querySelectorAll('.ps-row-wrap').forEach(function (w) {
            if (w.style.display !== 'none') visibili++;
        });
        if (visibili) return;

        var scroll = overlay.querySelector('.ps-tree-scroll');
        if (!scroll) return;
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'ps-crea-vuoto';
        b.innerHTML = '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" '
            + 'stroke-width="2.6" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>'
            + '<span></span>';
        b.querySelector('span').textContent = 'Crea \u00ab' + testo + '\u00bb';
        b.addEventListener('click', function () { psSwitchView(overlay, 'create'); });
        scroll.appendChild(b);
    }
    function ps_norm(s) { return String(s || '').toLowerCase().trim(); }



    document.addEventListener('keydown', function (e) {

        if (e.key !== 'Escape') return;

        var open = document.querySelector('.ps-modal-overlay.ps-open');

        if (open) psClose(open);

    });



    window.psOpen = window.psOpen || psOpen;

    window.psClose = window.psClose || psClose;

    window.psSwitchView = window.psSwitchView || psSwitchView;

    window.psSubmitCreate = window.psSubmitCreate || psSubmitCreate;

    window.psFilterTree = window.psFilterTree || psFilterTree;

    window.psToggleTree = window.psToggleTree || function (id, btn) {

        var wrap = btn.closest('.ps-row-wrap');

        var kids = wrap ? wrap.querySelector('.ps-children') : null;

        if (kids) { kids.classList.toggle('collapsed'); btn.classList.toggle('open'); }

    };

    /* Espande l'albero fino a rendere visibile il nodo con quell'id: apre il suo

       padre, il padre del padre, ecc. Serve quando modifichi un sotto-progetto e

       vuoi che il selettore si apra già "srotolato" fin lì (richiesta 4). */

    window.psExpandTo = window.psExpandTo || function (overlay, id) {

        if (!overlay || !id) return;

        var target = overlay.querySelector('.ps-row-wrap[data-ps-id="' + id + '"]');

        if (!target) return;

        var node = target.parentElement;

        while (node && node !== overlay) {

            if (node.classList && node.classList.contains('ps-children')) {

                node.classList.remove('collapsed');

                // ruota anche la freccetta della riga che possiede questo gruppo di figli

                var owner = node.closest('.ps-row-wrap');

                var arrow = owner ? owner.querySelector(':scope > .ps-row > .ps-arrow') : null;

                if (arrow) arrow.classList.add('open');

            }

            node = node.parentElement;

        }

        // richiesta 4: apri anche i figli DEL nodo stesso (un livello in più),

        // così il selettore mostra il progetto e ciò che ci sta sotto

        var ownKids = target.querySelector(':scope > .ps-children');

        if (ownKids) {

            ownKids.classList.remove('collapsed');

            var ownArrow = target.querySelector(':scope > .ps-row > .ps-arrow');

            if (ownArrow) ownArrow.classList.add('open');

        }

        // porta il nodo nella vista, se la lista è scrollabile

        try {

            var scroll = overlay.querySelector('.ps-tree-scroll');

            if (scroll && target.scrollIntoView) target.scrollIntoView({ block: 'nearest' });

        } catch (e) {}

    };



    /* API pubblica per impostare la selezione via JS (es. da progetti.php quando

       arrivi già con un progetto in query string) senza dover aprire il popup */

    window.psSetValue = window.psSetValue || function (modalId, id, name, color) {

        var overlay = document.getElementById(modalId);

        if (overlay) {

            psApplySelection(overlay, id, name, color);

            if (parseInt(id, 10) > 0) window.psExpandTo(overlay, parseInt(id, 10));

        }

    };

})();

</script>

<?php endif; ?>