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
        /* auth_check_api.php PRIMA di sec_db(): e' quello a caricare
           security_core.php, dove sec_db e le altre sec_* sono definite. */
        require_once 'auth_check_api.php';
        $current_user_id = (int)$utente_corrente['id'];
        $conn = sec_db();
    }

    /* Token di sessione, origine e limite di frequenza su un endpoint che
       scrive: prima non c'erano. */
    sec_guard_write((int)$current_user_id, 'fc_cat_selector', 0, 60, 60);

        if (!function_exists('cs_resolve_color')) {
        /* $userId e' un parametro nuovo: prima la funzione leggeva qualunque
           categoria, quindi risaliva anche l'albero di un altro utente e ne
           restituiva il colore. */
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



        $name = sec_str($_POST['name'] ?? '', 120);
    $parentId = sec_int($_POST['parent_id'] ?? 0, 0);

    if ($name === '') {
        echo json_encode(['success' => false, 'error' => t('fc.cs_nome_obbligatorio')]);
        exit();
    }
    /* Il padre dev'essere suo: senza questo controllo si poteva agganciare una
       propria materia sotto quella di un altro utente. */
    if ($parentId > 0 && !sec_owns('fc_categories', $parentId, (int)$current_user_id)) {
        $parentId = 0;
    }

    $resolvedColor = cs_resolve_color($parentId, $current_user_id);
    sec_q("INSERT INTO fc_categories (name, parent_id, color, color_edited, user_id) VALUES (?, ?, ?, 0, ?)",
          [$name, $parentId, $resolvedColor, (int)$current_user_id]);
    $newId = sec_insert_id();
    if ($newId > 0) {
        echo json_encode(['success' => true, 'id' => $newId, 'name' => $name, 'parent_id' => $parentId, 'color' => $resolvedColor], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(['success' => false, 'error' => t('fc.cs_errore_salvataggio')]);
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

if (!isset($cs_placeholder)) $cs_placeholder = t('fc.cs_seleziona_categoria');

if (!isset($cs_title)) $cs_title = t('fc.cs_seleziona_materia');

if (!isset($cs_show_all_option)) $cs_show_all_option = false;

if (!isset($cs_all_option_label)) $cs_all_option_label = t('fc.cs_tutte_materie');

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
        /* $userId e' un parametro nuovo: prima la funzione leggeva qualunque
           categoria, quindi risaliva anche l'albero di un altro utente e ne
           restituiva il colore. */
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

            $res = sec_all("SELECT * FROM fc_categories WHERE user_id = ? ORDER BY name", [$uid]);

            /* sec_all() restituisce un array: "if ($res)" era vero solo con un
               risultato non vuoto, e con zero materie saltava tutto il blocco
               lasciando l'albero non inizializzato. is_array e' la condizione
               giusta ora. */
            if (is_array($res)) {

                $all = [];

                $refs = [];

                foreach ($res as $row) { $row['children'] = []; $all[] = $row; }

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

        $nameEsc = sec_h($node['name']);

        $searchKey = sec_h(cs_lower($node['name']));

        $color = (!empty($node['color']) && preg_match('/^#[0-9A-Fa-f]{6}$/', $node['color'])) ? $node['color'] : '#007AFF';

        ?>

        <div class="cs-row-wrap" style="--row-color:<?php echo sec_h($color); ?>;">

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



                <span class="cs-name<?php echo $hasChildren ? ' cs-name-strong' : ''; ?>" data-cs-select="<?php echo (int)$node['id']; ?>" data-cs-name="<?php echo $nameEsc; ?>" data-cs-color="<?php echo sec_h($color); ?>" data-cs-search="<?php echo sec_h($searchKey); ?>">

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

<link rel="stylesheet" href="<?php echo PWA_BASE; ?>category_selector.css?v=<?php echo @filemtime(dirname(__FILE__) . '/category_selector.css') ?: 1; ?>">

<?php endif; ?>



<div class="cs-widget" id="<?php echo sec_h($cs_widget_id); ?>">

    <?php if ($cs_render_trigger): ?>

    <button type="button" class="cs-trigger<?php echo $cs_has_value ? ' cs-has-value' : ''; ?>" data-cs-open="<?php echo sec_h($cs_modal_id); ?>" style="<?php echo $cs_has_value ? ('--row-color:' . $cs_selected_color . ';') : ''; ?>">

        <span class="cs-trigger-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z"/></svg></span>

        <span class="cs-trigger-text" id="<?php echo sec_h($cs_text_id); ?>"><?php echo sec_h($cs_initial_text); ?></span>

        <svg class="cs-trigger-chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>

    </button>

    <?php endif; ?>

    <input type="hidden" name="<?php echo sec_h($cs_hidden_name); ?>" id="<?php echo sec_h($cs_hidden_id); ?>" value="<?php echo (int)$cs_selected_id; ?>">

</div>



<div id="<?php echo sec_h($cs_modal_id); ?>" class="cs-modal-overlay" data-cs-uid="<?php echo sec_h($cs_uid); ?>" data-cs-hidden="<?php echo sec_h($cs_hidden_id); ?>" data-cs-text="<?php echo sec_h($cs_text_id); ?>" data-cs-widget="<?php echo sec_h($cs_widget_id); ?>" data-cs-title="<?php echo sec_h($cs_title); ?>">

    <div class="cs-sheet-panel">

        <div class="cs-sheet-head">

            <h3 class="cs-sheet-title"><?php echo sec_h($cs_title); ?></h3>

            <button type="button" class="cs-nav-btn" data-cs-mode="close" aria-label="<?php echo sec_h(t('comune.chiudi')); ?>">

                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>

            </button>

        </div>



        <div class="cs-views">

            <div class="cs-view cs-view-tree">

                <div class="cs-search-box">

                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>

                    <input type="text" class="cs-search-input" placeholder="<?php echo sec_h(t('fc.cs_cerca_materia')); ?>" autocomplete="off">

                    <button type="button" class="cs-search-clear" aria-label="<?php echo sec_h(t('fc.cs_cancella_ricerca')); ?>">

                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>

                    </button>

                </div>



                <div class="cs-tree-scroll">

                    <?php if ($cs_show_all_option): ?>

                    <button type="button" class="cs-all-option" data-cs-select="0" data-cs-name="<?php echo sec_h($cs_all_option_label); ?>" data-cs-color="#A0A0A0">

                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M3.6 9h16.8M3.6 15h16.8M12 3a14.5 14.5 0 0 1 0 18M12 3a14.5 14.5 0 0 0 0 18"/></svg>

                        <?php echo sec_h($cs_all_option_label); ?>

                    </button>

                    <?php endif; ?>



                    <?php if (!empty($cs_cat_tree)): ?>

                        <?php foreach ($cs_cat_tree as $cs_node) { cs_render_node($cs_node); } ?>

                    <?php else: ?>

                        <p style="color:var(--text-2); text-align:center; font-size:14px; padding:20px 0;"><?php te('fc.cs_nessuna_trovata'); ?></p>

                    <?php endif; ?>

                </div>



                <?php if ($cs_allow_create): ?>

                <button type="button" class="cs-fab" data-cs-fab aria-label="<?php echo sec_h(t('fc.cs_aggiungi_materia')); ?>">

                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>

                </button>

                <?php endif; ?>

            </div>



            <?php if ($cs_allow_create): ?>

            <div class="cs-view cs-view-create">

                <div class="cs-create-scroll">

                    <div class="cs-field">

                        <label><?php te('fc.cs_nome_materia'); ?></label>

                        <input type="text" class="cs-name-input" placeholder="<?php echo sec_h(t('fc.cs_es_nome')); ?>">

                    </div>



                    <div class="cs-field">

                        <label><?php te('fc.cs_categoria_padre'); ?></label>

                        <?php

                        cs_render_instance([

                            'cs_id' => $cs_nested_parent_uid,

                            'cs_placeholder' => t('fc.cs_nessuna_padre'),

                            'cs_title' => t('fc.cs_categoria_padre_t'),

                            'cs_show_all_option' => true,

                            'cs_all_option_label' => t('fc.cs_nessuna_padre'),

                            'cs_allow_create' => false,

                        ]);

                        ?>

                    </div>



                    <div class="cs-field">

                        <label><?php te('fc.cs_colore_ereditato'); ?></label>

                        <div class="cs-color-preview-row">

                            <span class="cs-color-preview-icon" style="--row-color:#A0A0A0;">

                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z"/></svg>

                            </span>

                            <span class="cs-color-preview-name"><?php te('fc.cs_nuova_materia'); ?></span>

                        </div>

                    </div>



                    <p class="cs-create-error"></p>

                    <button type="button" class="cs-btn cs-create-save" style="--btn-color:#A0A0A0;"><?php te('fc.cs_crea_seleziona'); ?></button>

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

            if (title) title.textContent = T('fc.cs_nuova_materia_t');

            if (navBtn) {

                navBtn.setAttribute('data-cs-mode', 'back');

                navBtn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="m15 6-6 6 6 6"/></svg>';

            }

            var createView = overlay.querySelector('.cs-view-create');

            var nameInput = createView ? createView.querySelector('.cs-name-input') : null;

            if (nameInput) setTimeout(function () { nameInput.focus(); }, 320);

        } else {

            views.classList.remove('cs-show-create');

            if (title) title.textContent = overlay.getAttribute('data-cs-title') || t('fc.cs_seleziona_materia');

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

        if (previewName) previewName.textContent = T('fc.cs_nuova_materia');

        csSetPreviewColor(createView, '#A0A0A0');



        var nestedHidden = createView.querySelector('input[type="hidden"]');

        if (nestedHidden) nestedHidden.value = '0';

        var nestedText = createView.querySelector('.cs-trigger-text');

        if (nestedText) {

            nestedText.textContent = T('fc.cs_nessuna_padre');

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

                arrowBtn.setAttribute('aria-label', T('fc.cs_espandi'));

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

            errorEl.textContent = T('fc.cs_serve_nome');

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

                    csShowToast(T('fc.cs_materia_creata'));

                    csClose(overlay);

                } else {

                    errorEl.textContent = (data && data.error) || T('fc.cs_errore_salvataggio');

                    errorEl.style.display = 'block';

                }

            })

            .catch(function () {

                saveBtn.disabled = false;

                errorEl.textContent = T('fc.cs_errore_rete');

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

                csShowToast(selectEl.getAttribute('data-cs-disabled-reason') || T('fc.cs_non_selezionabile'));

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

            if (previewName) previewName.textContent = nameInput.value.trim() || T('fc.cs_nuova_materia');

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