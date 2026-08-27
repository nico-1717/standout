<?php
require_once 'config.php';
require_once 'auth_check.php'; // Inserito il controllo login!

$current_user_id = $utente_corrente['id'];

// Categoria da pre-selezionare in automatico (arrivo da un link tipo "gioca con questa categoria")
$preselect_category_id = sec_int($_GET['preselect_category'] ?? 0, 0);

/* sec_db() DOPO auth_check: e' quest'ultimo a caricare security_core.php. */
$conn = sec_db();

$cats = [];
$refs = []; // Usato per costruire l'albero a infiniti livelli

// SOLO le categorie di questo utente
foreach (sec_all("SELECT * FROM fc_categories WHERE user_id = ? ORDER BY name",
                 [(int)$current_user_id]) as $row) {
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
    $nameEsc = sec_h($node['name']);
    $color = (!empty($node['color']) && preg_match('/^#[0-9A-Fa-f]{6}$/', $node['color'])) ? $node['color'] : '#007AFF';
?>
<div class="cat-row" id="row-<?php echo (int)$node['id']; ?>" data-level="<?php echo (int)$level; ?>" style="--row-color:<?php echo sec_h($color); ?>;">
    <div class="cat-row-main" style="padding-left:<?php echo (int)$pad; ?>px;"<?php echo $hasChildren ? " onclick=\"toggleFolder('{$node['id']}')\"" : ""; ?>>
        <?php if ($hasChildren): ?>
        <button type="button" class="cat-arrow" id="arrow-<?php echo (int)$node['id']; ?>" onclick="event.stopPropagation(); toggleFolder('<?php echo (int)$node['id']; ?>')" aria-label="<?php te('fc.cs_espandi'); ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m9 6 6 6-6 6"/></svg>
        </button>
        <?php else: ?>
        <span class="cat-arrow-spacer"></span>
        <?php endif; ?>

        <span class="cat-icon <?php echo $level === 0 ? 'lvl0' : 'lvln'; ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z"/></svg>
        </span>

        <span class="cat-name"><?php echo $nameEsc; ?></span>
        <span class="cat-badge count-badge" data-ids="<?php echo sec_h($idsStr); ?>" id="count-<?php echo (int)$node['id']; ?>">···</span>

        <div class="row-action" onclick="event.stopPropagation();">
            <a class="play-pill play-btn" data-catid="<?php echo sec_h($idsStr); ?>" data-catname="<?php echo urlencode($node['name']); ?>" aria-label="<?php te('fc.cg_gioca'); ?>">
                <svg viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>
            </a>
            <input type="checkbox" class="cat-checkbox" value="<?php echo sec_h($idsStr); ?>" data-name="<?php echo $nameEsc; ?>" style="display:none;" onchange="updateMultiCount()" aria-label="<?php te('fc.cg_seleziona_nome', ['nome' => $node['name']]); ?>">
        </div>
    </div>

    <?php if ($hasChildren): ?>
    <div class="cat-children" id="children-<?php echo (int)$node['id']; ?>">
        <?php foreach ($node['children'] as $child) { renderGameCatRow($child, $level + 1); } ?>
    </div>
    <?php endif; ?>
</div>
<?php
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=0, viewport-fit=cover">
<title><?php te('fc.cg_titolo'); ?></title>
<link rel="stylesheet" href="<?php echo PWA_BASE; ?>categories_game_fc.css?v=<?php echo @filemtime(dirname(__FILE__) . '/categories_game_fc.css') ?: 1; ?>">
</head>
<body>

<div class="app-header">
    <div class="hdr-title"><?php te('fc.cg_nuova_partita'); ?></div>
    <div class="hdr-sub"><?php te('fc.cg_scegli_come'); ?></div>
</div>

<?php include 'sidebar.html'; ?>

<div class="page" id="pageContent">

    <div class="options-card">
        <div class="opt-section">
            <div class="opt-label"><?php te('fc.cg_ordine'); ?></div>
            <div class="segmented seg-order">
                <input type="radio" id="go_order" name="game_order" value="in_order" checked onchange="updateLinks(); updateOrderVisibility();">
                <label for="go_order"><?php te('fc.cg_in_ordine'); ?></label>
                <input type="radio" id="go_random" name="game_order" value="random" onchange="updateLinks(); updateOrderVisibility();">
                <label for="go_random"><?php te('fc.cg_random'); ?></label>
                <span class="segmented-thumb"></span>
            </div>
        </div>

        <hr class="opt-divider">

        <!-- QUESTO È IL BLOCCO RIPRISTINATO -->
        <div class="opt-section">
            <div class="opt-label"><?php te('fc.cg_stato_carte'); ?></div>
            <div class="segmented seg-status">
                <input type="radio" id="gs_tolearn" name="game_status" value="0" checked onchange="updateCounts()">
                <label for="gs_tolearn"><?php te('fc.cg_to_learn'); ?></label>
                <input type="radio" id="gs_learnt" name="game_status" value="1" onchange="updateCounts()">
                <label for="gs_learnt"><?php te('fc.cg_learnt'); ?></label>
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
                    <div class="toggle-row-label"><?php te('fc.cg_piu_categorie'); ?></div>
                    <div class="toggle-row-sub"><?php te('fc.cg_combina'); ?></div>
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

    <a href="#" class="hero-card play-btn" id="row-all" data-catid="0" data-catname="<?php echo rawurlencode(t('fc.cg_tutte')); ?>">
        <div class="hero-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M3.6 9h16.8M3.6 15h16.8M12 3a14.5 14.5 0 0 1 0 18M12 3a14.5 14.5 0 0 0 0 18"/></svg>
        </div>
        <div class="hero-text">
            <div class="hero-num" id="count-all">···</div>
            <div class="hero-label"><?php te('fc.cg_tutte'); ?></div>
        </div>
        <div class="hero-play">
            <svg viewBox="0 0 24 24" fill="#fff"><path d="M8 5v14l11-7z"/></svg>
        </div>
    </a>

    <?php foreach ($tree as $rootNode):
        $rootColor = (!empty($rootNode['color']) && preg_match('/^#[0-9A-Fa-f]{6}$/', $rootNode['color'])) ? $rootNode['color'] : '#007AFF';
    ?>
    <div class="materia-card" id="group-<?php echo sec_h($rootNode['id']); ?>" style="--accent:<?php echo sec_h($rootColor); ?>;">
        <?php renderGameCatRow($rootNode, 0); ?>
    </div>
    <?php endforeach; ?>

</div>

<div class="play-bar" id="globalPlayBar">
    <div class="play-bar-info">
        <span class="play-bar-count"><span id="multi_count">0</span> <?php te('fc.cg_carte'); ?></span>
        <span class="play-bar-label"><?php te('fc.cg_selezionate'); ?></span>
    </div>
    <a id="global_play_btn" class="play-bar-btn" style="opacity:0.4; pointer-events:none;" onclick="startMultiGame()">
        <?php te('fc.cg_gioca'); ?>
        <svg viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>
    </a>
</div>

<?php echo nisama_i18n_json(['comune', 'fc', 'vocab']); ?>
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
                    <button type="button" onclick="moveOrderItem(${index}, -1)" aria-label="${T('fc.cg_sposta_su')}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="m18 15-6-6-6 6"/></svg>
                    </button>
                    <button type="button" onclick="moveOrderItem(${index}, 1)" aria-label="${T('fc.cg_sposta_giu')}">
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