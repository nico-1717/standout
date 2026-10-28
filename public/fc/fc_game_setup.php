<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/auth_check.php'; // Inserito il controllo login!

$current_user_id = $utente_corrente['id'];

// Categoria da pre-selezionare in automatico (arrivo da un link tipo "gioca con questa categoria")
$preselect_category_id = sec_int($_GET['preselect_category'] ?? 0, 0);

/* sec_db() DOPO auth_check: e' quest'ultimo a caricare security_core.php. */
$conn = sec_db();

/* Studio o lingua (?course=N): per le lingue si scelgono i mazzi del corso
   e, in piu', il verso della partita. Vedi fc_type.php. */
require_once __DIR__ . '/../../lib/fc/fc_type.php';
require_once __DIR__ . '/../../lib/fc/fc_icone.php';
fc_avvia((int)$current_user_id);
$fc_vocab = fc_is_vocab();

$cats = [];
$refs = []; // Usato per costruire l'albero a infiniti livelli

// SOLO le categorie di questo utente
[$fcW, $fcP] = fc_filtro_corso();
foreach (sec_all("SELECT * FROM " . fc_t('decks') . " WHERE user_id = ?" . $fcW . " ORDER BY name",
                 array_merge([(int)$current_user_id], $fcP)) as $row) {
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
    <div class="cat-row-main" style="padding-left:<?php echo (int)$pad; ?>px;"<?php echo $hasChildren ? " data-on-click=\"toggleFolder('{$node['id']}')\"" : ""; ?>>
        <?php if ($hasChildren): ?>
        <button type="button" class="cat-arrow" id="arrow-<?php echo (int)$node['id']; ?>" data-on-click="event.stopPropagation(); toggleFolder('<?php echo (int)$node['id']; ?>')" aria-label="<?php te('fc.cs_espandi'); ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m9 6 6 6-6 6"/></svg>
        </button>
        <?php else: ?>
        <span class="cat-arrow-spacer"></span>
        <?php endif; ?>

        <span class="cat-icon <?php echo $level === 0 ? 'lvl0' : 'lvln'; ?>">
            <?php echo fc_icona_svg(fc_icona_mazzo_di($node)); ?>
        </span>

        <span class="cat-name"><?php echo $nameEsc; ?></span>
        <span class="cat-badge count-badge" data-ids="<?php echo sec_h($idsStr); ?>" id="count-<?php echo (int)$node['id']; ?>">···</span>

        <div class="row-action" data-on-click="event.stopPropagation();">
            <a class="play-pill play-btn" data-catid="<?php echo sec_h($idsStr); ?>" data-catname="<?php echo urlencode($node['name']); ?>" aria-label="<?php te('fc.cg_gioca'); ?>">
                <svg viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>
            </a>
            <input type="checkbox" class="cat-checkbox" value="<?php echo sec_h($idsStr); ?>" data-name="<?php echo $nameEsc; ?>" style="display:none;" data-on-change="updateMultiCount()" aria-label="<?php te('fc.cg_seleziona_nome', ['nome' => $node['name']]); ?>">
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
<html lang="<?php echo htmlspecialchars(standout_lingua(), ENT_QUOTES); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=0, viewport-fit=cover">
<title><?php te('fc.cg_titolo'); ?></title>
<?php echo fc_boot_json(); ?>
<link rel="stylesheet" href="<?php echo PWA_BASE; ?>fc/fc_game_setup.css?v=<?php echo @filemtime(__DIR__ . '/fc_game_setup.css') ?: 1; ?>">
</head>
<body>

<div class="app-header">
    <?php /* Si arriva qui dallo Storico (il tasto Gioca): la freccia ci riporta. */ ?>
    <a class="hdr-back" href="<?php echo sec_h(fc_url('fc_history.php')); ?>" aria-label="<?php te('fc.js_storico'); ?>" title="<?php te('fc.js_storico'); ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
    </a>
    <div class="hdr-testi">
    <div class="hdr-title"><?php te('fc.cg_nuova_partita'); ?></div>
    <div class="hdr-sub"><?php if ($fc_vocab): $fcC = fc_corso(); ?><img class="fcv-hdr-flag" src="<?php echo sec_h(fc_lingua_bandiera($fcC['learn_lang'])); ?>" alt=""> <?php echo sec_h(fc_lingua_nome($fcC['learn_lang'])); ?><?php else: te('fc.cg_scegli_come'); endif; ?></div>
    </div>
</div>

<?php include __DIR__ . '/../partials/sidebar.html'; ?>

<div class="page" id="pageContent">

    <div class="options-card">
        <?php if ($fc_vocab):
            /* Di partenza e' sempre "dall'italiano all'inglese" (t2w: si vede
               la traduzione, si risponde con la parola): e' il verso che si
               vuole di solito, e lo stesso vale per gli avvii rapidi che non
               passano da qui (fc_game_start.php). Gli altri due restano a un
               tocco. Il verso scelto: una volta per partita. "Misto" alterna
               le posizioni, cosi' una parola si ripassa nei due sensi. Le
               scelte sono bandiere (inglese -> italiano...): a parole non ci
               stanno in tre su un telefono, e la bandiera si legge prima. */
            $fcC = fc_corso();
            $fcBi = '<img src="' . sec_h(fc_lingua_bandiera($fcC['learn_lang'])) . '" alt="">';
            $fcBk = '<img src="' . sec_h(fc_lingua_bandiera($fcC['known_lang'])) . '" alt="">';
            $fcFr = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>'; ?>
        <div class="opt-section">
            <div class="opt-label"><?php te('fcv.verso'); ?></div>
            <div class="segmented seg-dir">
                <input type="radio" id="gd_w2t" name="game_dir" value="w2t" data-on-change="updateLinks()">
                <label for="gd_w2t" title="<?php echo sec_h(t('fcv.verso_w2t')); ?>"><span class="sr-only"><?php te('fcv.verso_w2t'); ?></span><span class="dir-flags" aria-hidden="true"><?php echo $fcBi . $fcFr . $fcBk; ?></span></label>
                <input type="radio" id="gd_t2w" name="game_dir" value="t2w" checked data-on-change="updateLinks()">
                <label for="gd_t2w" title="<?php echo sec_h(t('fcv.verso_t2w')); ?>"><span class="sr-only"><?php te('fcv.verso_t2w'); ?></span><span class="dir-flags" aria-hidden="true"><?php echo $fcBk . $fcFr . $fcBi; ?></span></label>
                <input type="radio" id="gd_mix" name="game_dir" value="mix" data-on-change="updateLinks()">
                <label for="gd_mix"><?php te('fcv.verso_mix'); ?></label>
                <span class="segmented-thumb"></span>
            </div>
        </div>

        <hr class="opt-divider">

        <?php /* Gli indizi sulla carta: gia' scoperti o coperti (si toccano
                 per vederli). E' la stessa scelta dell'ingranaggio nel gioco,
                 salvata sul profilo (lib/fc/fc_impostazioni.php). */
              require_once __DIR__ . '/../../lib/fc/fc_impostazioni.php';
              $fc_imp = fc_impostazioni((int)$current_user_id); ?>
        <div class="opt-section">
            <div class="toggle-row">
                <div>
                    <div class="toggle-row-label"><?php te('fcv.indizi_aperti'); ?></div>
                    <div class="toggle-row-sub"><?php te('fcv.indizi_aperti_sub'); ?></div>
                </div>
                <label class="ios-switch">
                    <input type="checkbox" id="fcvIndiziAperti"<?php echo ($fc_imp['significato'] === 'aperto' && $fc_imp['esempi'] === 'aperto') ? ' checked' : ''; ?>>
                    <span class="switch-track"><span class="switch-thumb"></span></span>
                </label>
            </div>
        </div>
        <script nonce="<?php echo sec_nonce(); ?>">
        (function () {
            /* Acceso = tutti e due visibili, spento = tutti e due al tocco. Nel
               gioco si possono poi regolare uno per uno, anche "con la risposta". */
            var c = document.getElementById('fcvIndiziAperti');
            var TOKEN = <?php echo json_encode(sec_csrf_token()); ?>;
            c.addEventListener('change', function () {
                var v = c.checked ? 'aperto' : 'tocco';
                var fd = new FormData();
                fd.append('significato', v);
                fd.append('esempi', v);
                fd.append('csrf_token', TOKEN);
                fetch('fc_settings_api.php', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-CSRF-Token': TOKEN } }).catch(function () {});
            });
        })();
        </script>

        <hr class="opt-divider">
        <?php endif; ?>
        <div class="opt-section">
            <div class="opt-label"><?php te('fc.cg_ordine'); ?></div>
            <div class="segmented seg-order">
                <?php /* Lingue: si parte da "casuale". Le parole in ordine si imparano
                         anche dall'ordine (la terza e' sempre dopo la seconda), e il
                         ripasso vero e' quello mescolato. Lo studio resta in ordine. */ ?>
                <input type="radio" id="go_order" name="game_order" value="in_order"<?php echo $fc_vocab ? '' : ' checked'; ?> data-on-change="updateLinks(); updateOrderVisibility();">
                <label for="go_order"><?php te('fc.cg_in_ordine'); ?></label>
                <input type="radio" id="go_random" name="game_order" value="random"<?php echo $fc_vocab ? ' checked' : ''; ?> data-on-change="updateLinks(); updateOrderVisibility();">
                <label for="go_random"><?php te('fc.cg_random'); ?></label>
                <span class="segmented-thumb"></span>
            </div>
        </div>

        <hr class="opt-divider">

        <!-- QUESTO È IL BLOCCO RIPRISTINATO -->
        <div class="opt-section">
            <div class="opt-label"><?php te('fc.cg_stato_carte'); ?></div>
            <div class="segmented seg-status">
                <input type="radio" id="gs_tolearn" name="game_status" value="0" checked data-on-change="updateCounts()">
                <label for="gs_tolearn"><?php te('fc.cg_to_learn'); ?></label>
                <input type="radio" id="gs_learnt" name="game_status" value="1" data-on-change="updateCounts()">
                <label for="gs_learnt"><?php te('fc.cg_learnt'); ?></label>
                <input type="radio" id="gs_all" name="game_status" value="all" data-on-change="updateCounts()">
                <label for="gs_all"><?php te('fc.cg_tutti_stati'); ?></label>
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
                    <input type="checkbox" id="multi_cat_toggle" data-on-change="toggleMulti()">
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
    <a id="global_play_btn" class="play-bar-btn" style="opacity:0.4; pointer-events:none;" data-on-click="startMultiGame()">
        <?php te('fc.cg_gioca'); ?>
        <svg viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>
    </a>
</div>

<?php echo standout_i18n_json(['comune', 'fc', 'vocab', 'fcv']); ?>
<script nonce="<?php echo sec_nonce(); ?>">
    // Inietto l'ID dell'utente loggato in modo sicuro tramite PHP
    const STANDOUT_CURRENT_USER_ID = <?php echo (int)$current_user_id; ?>;
    // Categoria da pre-selezionare in automatico (0 = nessuna)
    const PRESELECT_CATEGORY_ID = <?php echo (int)$preselect_category_id; ?>;

    /* Il verso scelto (solo lingue) viaggia con ogni avvio, come il corso. */
    function extraAvvio(){
        const d = document.querySelector('input[name="game_dir"]:checked');
        return d ? '&dir=' + encodeURIComponent(d.value) : '';
    }

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
                    <button type="button" data-on-click="moveOrderItem(${index}, -1)" aria-label="${T('fc.cg_sposta_su')}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="m18 15-6-6-6 6"/></svg>
                    </button>
                    <button type="button" data-on-click="moveOrderItem(${index}, 1)" aria-label="${T('fc.cg_sposta_giu')}">
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

        let finalCatIds = orderedCategories.map(c => c.id).join(',');

        const status = document.querySelector('input[name="game_status"]:checked').value;
        const order = document.querySelector('input[name="game_order"]:checked').value;
        const userId = STANDOUT_CURRENT_USER_ID; // Usa la costante sicura

        window.location.href = fcUrl(`fc_game_start.php?category_id=${encodeURIComponent(finalCatIds)}&status=${status}&order=${order}&user_id=${userId}`) + extraAvvio();
    }

    async function updateCounts(){
        const status = document.querySelector('input[name="game_status"]:checked').value;
        const userId = STANDOUT_CURRENT_USER_ID; // Usa la costante sicura

        try {
            const response = await fetch(fcUrl(`fc_api_counts.php?status=${status}&user_id=${userId}`));
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
        const userId = STANDOUT_CURRENT_USER_ID; // Usa la costante sicura

        document.querySelectorAll('.play-btn').forEach(btn => {
            const catId = btn.getAttribute('data-catid');
            const catName = btn.getAttribute('data-catname');
            btn.href = fcUrl(`fc_game_start.php?category_id=${catId}&category_name=${catName}&status=${status}&order=${order}&user_id=${userId}`) + extraAvvio();
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