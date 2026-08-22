<?php
require_once 'config.php';
require_once 'auth_check.php';
/* sec_db() riusa la connessione gia' aperta e la configura da sola. */
$conn = sec_db();
$current_user_id = $utente_corrente['id'];

/* ==========================================================================
   EREDITARIETÀ COLORE (tabella `categories` — inglese, per utente)
   - color_edited = 0  -> colore "ereditato": risale la catena dei padri finché
                          non trova un colore esplicito (color_edited = 1);
                          se nessuno lo ha, usa il grigio di default #A0A0A0.
   - color_edited = 1  -> colore scelto per QUELLA categoria: quando cambia va
                          ripropagato ai discendenti che ereditano.
   ========================================================================== */
if (!function_exists('en_resolve_color')) {
    function en_resolve_color($parentId, $userId) {
        $parentId = intval($parentId);
        $guard = 0;
        while ($parentId > 0 && $guard < 50) {
            $guard++;
            $row = sec_one("SELECT parent_id, color, color_edited FROM categories WHERE id = ? AND user_id = ?",
                           [$parentId, (int)$userId]);
            if (!$row) break;
            if (intval($row['color_edited']) === 1 && !empty($row['color'])) return $row['color'];
            $parentId = intval($row['parent_id']);
        }
        return '#A0A0A0';
    }
}
if (!function_exists('en_cascade_color')) {
    function en_cascade_color($categoryId, $color, $userId, $depth = 0) {
        if ($depth > 50) return;
        $children = sec_all("SELECT id, color_edited FROM categories WHERE parent_id = ? AND user_id = ?",
                            [(int)$categoryId, (int)$userId]);
        foreach ($children as $child) {
            if (intval($child['color_edited']) === 0) {
                sec_q("UPDATE categories SET color = ? WHERE id = ? AND user_id = ?",
                      [$color, (int)$child['id'], (int)$userId]);
                en_cascade_color($child['id'], $color, $userId, $depth + 1);
            }
        }
    }
}
if (!function_exists('en_apply_parent_color_update')) {
    function en_apply_parent_color_update($catId, $newParentId, $userId) {
        $row = sec_one("SELECT color_edited FROM categories WHERE id = ? AND user_id = ?", [(int)$catId, (int)$userId]);
        if (!$row || intval($row['color_edited']) !== 0) return;
        $newColor = en_resolve_color($newParentId, $userId);
        sec_q("UPDATE categories SET color = ? WHERE id = ? AND user_id = ?", [$newColor, (int)$catId, (int)$userId]);
        en_cascade_color($catId, $newColor, $userId);
    }
}

// --- AZIONI (salva / elimina / sposta / colore) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    /* Token di sessione, origine riconosciuta e limite di frequenza. Prima non
       c'era niente: un altro sito poteva far partire dal browser di chi e'
       connesso una richiesta che cancella una categoria. */
    sec_guard_write((int)$current_user_id, 'categorie', 0, 120, 60);

    /* elenco chiuso: un'azione non prevista non arriva agli if */
    $action = sec_enum($_POST['action'] ?? null, ['save', 'delete', 'move', 'color'], '');
    $okParam = '';

    if ($action === 'save') {
        $catId    = sec_int($_POST['category_id'] ?? 0, 0);
        $catName  = sec_str($_POST['category_name'] ?? '', 120);
        $parentId = sec_int($_POST['parent_id'] ?? 0, 0);
        $colorMode   = sec_enum($_POST['color_mode'] ?? null, ['auto', 'manual'], 'auto');
        $manualColor = sec_str($_POST['category_color'] ?? '', 7);

        /* Il padre dev'essere suo: senza questo controllo si poteva agganciare
           una propria categoria sotto quella di un altro utente. */
        if ($parentId > 0 && !sec_owns('categories', $parentId, (int)$current_user_id)) $parentId = 0;
        if ($catId > 0 && !sec_owns('categories', $catId, (int)$current_user_id)) {
            header('Location: categories.php'); exit;
        }

        if ($colorMode === 'manual' && preg_match('/^#[0-9A-Fa-f]{6}$/', $manualColor)) {
            $finalColor = $manualColor; $colorEditedFlag = 1;
        } else {
            $finalColor = en_resolve_color($parentId, $current_user_id); $colorEditedFlag = 0;
        }

        if ($catId === 0) {
            sec_q("INSERT INTO categories (name, parent_id, color, color_edited, user_id) VALUES (?, ?, ?, ?, ?)",
                  [$catName, $parentId, $finalColor, $colorEditedFlag, (int)$current_user_id]);
            $okParam = 'added';
        } else {
            sec_q("UPDATE categories SET name = ?, parent_id = ?, color = ?, color_edited = ? WHERE id = ? AND user_id = ?",
                  [$catName, $parentId, $finalColor, $colorEditedFlag, $catId, (int)$current_user_id]);
            en_cascade_color($catId, $finalColor, $current_user_id);
            $okParam = 'saved';
        }
    } elseif ($action === 'delete' && isset($_POST['delete_category_id'])) {
        $catId = sec_int($_POST['delete_category_id'] ?? 0, 1);
        if (sec_owns('categories', $catId, (int)$current_user_id)) {
            sec_q("DELETE FROM categories WHERE id = ? AND user_id = ?", [$catId, (int)$current_user_id]);
            $okParam = 'deleted';
        }
    } elseif ($action === 'move' && isset($_POST['move_category_id']) && isset($_POST['target_parent_id'])) {
        $moveId = sec_int($_POST['move_category_id'] ?? 0, 1);
        $targetParentId = sec_int($_POST['target_parent_id'] ?? 0, 0);
        /* sia la categoria spostata sia la destinazione devono essere sue */
        if (sec_owns('categories', $moveId, (int)$current_user_id)
            && ($targetParentId === 0 || sec_owns('categories', $targetParentId, (int)$current_user_id))) {
            sec_q("UPDATE categories SET parent_id = ? WHERE id = ? AND user_id = ?",
                  [$targetParentId, $moveId, (int)$current_user_id]);
            en_apply_parent_color_update($moveId, $targetParentId, $current_user_id);
            $okParam = 'moved';
        }
    } elseif ($action === 'color' && isset($_POST['category_id']) && isset($_POST['category_color'])) {
        $catId = sec_int($_POST['category_id'] ?? 0, 1);
        $color = sec_color($_POST['category_color'] ?? null, '');
        if ($color !== '' && sec_owns('categories', $catId, (int)$current_user_id)) {
            sec_q("UPDATE categories SET color = ?, color_edited = 1 WHERE id = ? AND user_id = ?",
                  [$color, $catId, (int)$current_user_id]);
            en_cascade_color($catId, $color, $current_user_id);
            $okParam = 'colored';
        }
    }

    header("Location: categories.php" . ($okParam ? "?ok=$okParam" : ""));
    exit;
}

// Categorie (di questo utente) + conteggio parole (tabella images) per categoria
$result = sec_all("SELECT c.*, IFNULL(im.word_count, 0) AS word_count
          FROM categories AS c
          LEFT JOIN (
              SELECT category_id, COUNT(*) AS word_count FROM images GROUP BY category_id
          ) AS im ON c.id = im.category_id
          WHERE c.user_id = ?
          ORDER BY c.name", [(int)$current_user_id]);

$all_categories = [];
$categories_by_parent = [];
foreach ($result as $row) {
    $all_categories[$row['id']] = $row;
    $categories_by_parent[$row['parent_id']][] = $row['id'];
}

// Auto-guarigione: le categorie non modificate manualmente (color_edited=0) mostrano
// sempre il colore live risolto dalla catena dei padri, ignorando eventuali valori
// "sporchi" rimasti nel DB (es. il grigio/blu di default della colonna).
foreach ($all_categories as $cid => $c) {
    if (intval($c['color_edited']) === 0) {
        $all_categories[$cid]['color'] = en_resolve_color($c['parent_id'], $current_user_id);
    }
}

$totalWords = (int)(sec_one("SELECT COUNT(*) AS total FROM images AS i INNER JOIN categories AS c ON i.category_id = c.id WHERE c.user_id = ?",
                            [(int)$current_user_id])['total'] ?? 0);
$catCount = count($all_categories);
$rootIds = isset($categories_by_parent[0]) ? $categories_by_parent[0] : [];

$colorPalette = ['#FF3B30', '#FF9500', '#FFCC00', '#34C759', '#00C7BE', '#30B0C7', '#32ADE6', '#007AFF', '#5856D6', '#AF52DE', '#FF2D55', '#A2845E'];

// Colore effettivo (già coerente grazie al cascade + auto-guarigione sopra) da usare nella mappa JS per l'ereditarietà live
$catColorMap = [];
foreach ($all_categories as $c) {
    $col = (!empty($c['color']) && preg_match('/^#[0-9A-Fa-f]{6}$/', $c['color'])) ? $c['color'] : '#A0A0A0';
    $catColorMap[(int)$c['id']] = $col;
}

function renderCatRow($id, $level, $all_cats, $cats_by_parent) {
    $cat = $all_cats[$id];
    $has_children = isset($cats_by_parent[$id]) && count($cats_by_parent[$id]) > 0;
    $can_delete = ($cat['word_count'] == 0 && !$has_children) ? 1 : 0;
    $display = ($level === 0) ? 'flex' : 'none';
    $pad = 12 + $level * 22;
    $nameEsc = sec_h($cat['name']);
    $color = (!empty($cat['color']) && preg_match('/^#[0-9A-Fa-f]{6}$/', $cat['color'])) ? $cat['color'] : '#A0A0A0';
    $parentId = (int)$cat['parent_id'];
    $isColorEdited = (!empty($cat['color_edited']) && $cat['color_edited'] == 1) ? 'true' : 'false';
?>
<div class="cat-row" data-id="<?php echo (int)$id; ?>" data-parent="<?php echo (int)$parentId; ?>" data-level="<?php echo (int)$level; ?>" data-name="<?php echo $nameEsc; ?>" style="display:<?php echo sec_h($display); ?>; --row-color:<?php echo sec_h($color); ?>;">
    <div class="cat-swipe-actions">
        <button type="button" class="swipe-btn swipe-edit" aria-label="<?php te('vocab.cat_modifica_attr'); ?>"
            data-color="<?php echo sec_h($color); ?>" data-coloredited="<?php echo sec_h($isColorEdited); ?>"
            onclick="chiudiTuttiSwipe(); apriModalSalvaDaRiga(this)">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
        </button>
        <button type="button" class="swipe-btn swipe-move" aria-label="<?php te('vocab.cat_sposta_attr'); ?>"
            onclick="chiudiTuttiSwipe(); apriSpostaDaRiga(this)">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 9l-3 3 3 3M19 9l3 3-3 3M2 12h20"/></svg>
        </button>
        <?php if ($can_delete): ?>
        <button type="button" class="swipe-btn swipe-delete" aria-label="<?php te('vocab.cat_elimina_attr'); ?>"
            onclick="chiudiTuttiSwipe(); apriConfermaEliminaDaRiga(this)">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0-1 14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2L4 6"/></svg>
        </button>
        <?php endif; ?>
    </div>

    <div class="cat-row-main" style="padding-left:<?php echo (int)$pad; ?>px;" data-cat-link="visualizzazione.php?category_id=<?php echo (int)$id; ?>" onclick="catRowNavigate(event, this)">
        <?php if ($has_children): ?>
        <button type="button" class="cat-arrow" onclick="event.stopPropagation(); toggleBranch(<?php echo (int)$id; ?>)" aria-label="<?php te('vocab.cat_espandi'); ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m9 6 6 6-6 6"/></svg>
        </button>
        <?php else: ?>
        <span class="cat-arrow-spacer"></span>
        <?php endif; ?>

        <span class="cat-icon <?php echo $level === 0 ? 'lvl0' : 'lvln'; ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z"/></svg>
        </span>

        <span class="cat-name"><?php echo $nameEsc; ?></span>

        <span class="cat-badge"><?php echo (int)$cat['word_count']; ?></span>
        <button type="button" class="cat-kebab" aria-label="<?php te('vocab.cat_altre_azioni'); ?>"
            data-id="<?php echo (int)$id; ?>" data-name="<?php echo $nameEsc; ?>" data-parent="<?php echo (int)$parentId; ?>"
            data-candelete="<?php echo (int)$can_delete; ?>" data-color="<?php echo sec_h($color); ?>" data-color-edited="<?php echo (int)($cat['color_edited'] ?? 0); ?>"
            onclick="event.stopPropagation(); apriAzioni(this);">
            <svg viewBox="0 0 24 24" fill="currentColor"><circle cx="5" cy="12" r="1.7"/><circle cx="12" cy="12" r="1.7"/><circle cx="19" cy="12" r="1.7"/></svg>
        </button>
    </div>
</div>
<?php
    if ($has_children) {
        foreach ($cats_by_parent[$id] as $childId) {
            renderCatRow($childId, $level + 1, $all_cats, $cats_by_parent);
        }
    }
}

// Opzioni <select> "categoria padre" con indentazione gerarchica
function renderParentOptions($parentId, $all_cats, $cats_by_parent, $level = 0) {
    if (!isset($cats_by_parent[$parentId])) return;
    foreach ($cats_by_parent[$parentId] as $id) {
        $cat = $all_cats[$id];
        $prefix = $level > 0 ? str_repeat('— ', $level) : '';
        echo "<option value='{$id}'>" . htmlspecialchars($prefix . $cat['name']) . "</option>";
        renderParentOptions($id, $all_cats, $cats_by_parent, $level + 1);
    }
}

// Lista indentata per il foglio "Sposta"
function renderMoveList($parentId, $all_cats, $cats_by_parent, $level = 0) {
    if (!isset($cats_by_parent[$parentId])) return;
    foreach ($cats_by_parent[$parentId] as $id) {
        $cat = $all_cats[$id];
        $nameEsc = sec_h($cat['name']);
        $color = (!empty($cat['color']) && preg_match('/^#[0-9A-Fa-f]{6}$/', $cat['color'])) ? $cat['color'] : '#A0A0A0';
        $pad = 14 + $level * 20;
        echo "<button type='button' class='cat-pick-item' data-id='{$id}' style='padding-left:{$pad}px' onclick='selezionaNuovoPadre({$id})'>";
        echo "<span class='cat-pick-dot' style='background:{$color}'></span>";
        echo "<span class='cat-pick-name'>{$nameEsc}</span>";
        echo "</button>";
        renderMoveList($id, $all_cats, $cats_by_parent, $level + 1);
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=0, viewport-fit=cover">
<meta name="theme-color" content="#F2F2F7" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#000000" media="(prefers-color-scheme: dark)">
<title><?php te('vocab.cat_titolo'); ?></title>
<link rel="stylesheet" href="<?php echo PWA_BASE; ?>categories.css?v=<?php echo @filemtime(dirname(__FILE__) . '/categories.css') ?: 1; ?>">
</head>
<body>

<div class="app-header">
    <div class="hdr-title"><span class="hdr-flag">🇬🇧</span> <?php te('vocab.cat_categorie'); ?></div>
    <div class="hdr-sub"><?php te('vocab.cat_conteggio', ['categorie' => (int)$catCount, 'parole' => (int)$totalWords]); ?></div>
    <div class="search-box">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
        <input type="text" id="searchInput" placeholder="<?php te('vocab.cat_cerca'); ?>" oninput="filterCategories(this.value)" autocomplete="off">
        <button type="button" class="search-clear" id="searchClear" onclick="clearSearch()" aria-label="<?php te('vocab.cat_cancella_ricerca'); ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
        </button>
    </div>
</div>

<?php include 'sidebar.html'; ?>

<div class="page">
    <a href="visualizzazione.php?category_id=0" class="hero-card">
        <div class="hero-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M3.6 9h16.8M3.6 15h16.8M12 3a14.5 14.5 0 0 1 0 18M12 3a14.5 14.5 0 0 0 0 18"/></svg>
        </div>
        <div class="hero-text">
            <div class="hero-num"><?php echo (int)$totalWords; ?></div>
            <div class="hero-label"><?php te('vocab.cat_tutte_parole'); ?></div>
        </div>
        <div class="hero-chev">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m9 6 6 6-6 6"/></svg>
        </div>
    </a>

    <?php if (empty($rootIds)): ?>
        <div class="empty-state">
            <div class="empty-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z"/></svg>
            </div>
            <div class="empty-title"><?php te('vocab.cat_nessuna'); ?></div>
            <div class="empty-sub"><?php te('vocab.cat_nessuna_txt'); ?></div>
            <button type="button" class="btn-create-first" onclick="apriModalSalva({id:0,name:'',parentId:0,color:'#A0A0A0',colorEdited:false})">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
                <?php te('vocab.cat_crea'); ?>
            </button>
        </div>
    <?php else: ?>
        <?php foreach ($rootIds as $i => $rid):
            $rootCat = $all_categories[$rid];
            $rootColor = (!empty($rootCat['color']) && preg_match('/^#[0-9A-Fa-f]{6}$/', $rootCat['color'])) ? $rootCat['color'] : '#A0A0A0';
            $delay = min($i * 45, 300);
        ?>
        <div class="materia-card" style="--accent:<?php echo sec_h($rootColor); ?>; animation-delay:<?php echo (int)$delay; ?>ms;">
            <?php renderCatRow($rid, 0, $all_categories, $categories_by_parent); ?>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<button type="button" class="fab" onclick="apriModalSalva({id:0,name:'',parentId:0,color:'#A0A0A0',colorEdited:false})" aria-label="<?php te('vocab.cat_aggiungi'); ?>">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
</button>

<!-- SHEET: Nuova / Modifica categoria -->
<div id="sheetForm" class="modal-overlay">
    <div class="sheet-panel">
        <h3 class="sheet-title" id="modalSalvaTitolo"><?php te('vocab.cat_nuova'); ?></h3>
        <form id="formSalva" method="POST">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="category_id" id="form_cat_id" value="0">
            <input type="hidden" name="color_mode" id="form_color_mode" value="auto">
            <input type="hidden" name="category_color" id="form_color_value" value="#A0A0A0">

            <div class="field">
                <label for="form_cat_name"><?php te('vocab.cat_nome'); ?></label>
                <input type="text" name="category_name" id="form_cat_name" required placeholder="<?php te('vocab.cat_es_nome'); ?>">
            </div>

            <div class="field">
                <label for="form_parent_id"><?php te('vocab.cat_padre'); ?></label>
                <select name="parent_id" id="form_parent_id">
                    <option value="0"><?php te('vocab.cat_nessuna_padre'); ?></option>
                    <?php renderParentOptions(0, $all_categories, $categories_by_parent); ?>
                </select>
            </div>

            <div class="field">
                <label><?php te('vocab.cat_colore'); ?></label>
                <div class="color-preview-row">
                    <span class="color-preview-icon" id="formColorPreviewIcon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z"/></svg>
                    </span>
                    <span class="color-preview-name" id="formColorPreviewName"><?php te('vocab.cat_auto_eredita'); ?></span>
                </div>
                <div class="color-grid" id="formColorGrid">
                    <button type="button" class="color-swatch color-swatch-auto selected" data-auto="1" onclick="setFormColorAuto()" title="Automatico">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 9-9"/><path d="M3 4v8h8"/></svg>
                    </button>
                    <?php foreach ($colorPalette as $ci => $hex): ?>
                    <button type="button" class="color-swatch" style="--sw:<?php echo sec_h($hex); ?>; animation-delay:<?php echo (int)($ci * 22); ?>ms;" data-hex="<?php echo sec_h($hex); ?>" onclick="setFormColorManual('<?php echo sec_h($hex); ?>')">
                        <svg class="check-ic" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                    </button>
                    <?php endforeach; ?>
                </div>
                <div class="custom-color-row">
                    <input type="color" id="formCustomColorInput" value="#007AFF" oninput="setFormColorManual(this.value)">
                    <span class="cc-label"><?php te('vocab.cat_colore_pers'); ?></span>
                    <span class="cc-hex" id="formCustomColorHex">#A0A0A0</span>
                </div>
            </div>

            <div class="sheet-actions">
                <button type="button" class="btn btn-cancel" onclick="chiudiModals()"><?php te('comune.annulla'); ?></button>
                <button type="submit" class="btn btn-primary"><?php te('comune.salva'); ?></button>
            </div>
        </form>
    </div>
</div>

<!-- SHEET: Azioni (kebab) -->
<div id="sheetActions" class="modal-overlay">
    <div class="sheet-panel">
        <h3 class="sheet-title" id="azioniTitolo"><?php te('vocab.cat_categoria'); ?></h3>
        <div class="action-list">
            <button type="button" class="action-item" id="azioneModifica">
                <span class="a-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg></span>
                <?php te('vocab.cat_modifica_attr'); ?>
            </button>
            <button type="button" class="action-item" id="azioneColore">
                <span class="a-ic color-wheel"></span>
                <?php te('vocab.cat_colore'); ?>
            </button>
            <button type="button" class="action-item" id="azioneSposta">
                <span class="a-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 9l-3 3 3 3M19 9l3 3-3 3M2 12h20"/></svg></span>
                <?php te('vocab.cat_sposta_attr'); ?>
            </button>
            <button type="button" class="action-item danger" id="azioneElimina">
                <span class="a-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0-1 14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2L4 6"/></svg></span>
                <?php te('comune.elimina'); ?>
            </button>
        </div>
        <button type="button" class="btn btn-cancel" style="width:100%;" onclick="chiudiModals()"><?php te('comune.chiudi'); ?></button>
    </div>
</div>

<!-- SHEET: Colore -->
<div id="sheetColor" class="modal-overlay">
    <div class="sheet-panel">
        <h3 class="sheet-title"><?php te('vocab.cat_colore_categoria'); ?></h3>
        <div class="color-preview-row">
            <span class="color-preview-icon" id="colorPreviewIcon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z"/></svg>
            </span>
            <span class="color-preview-name" id="colorPreviewName"><?php te('vocab.cat_categoria'); ?></span>
        </div>
        <div class="color-grid" id="colorGrid">
            <?php foreach ($colorPalette as $ci => $hex): ?>
            <button type="button" class="color-swatch" style="--sw:<?php echo sec_h($hex); ?>; animation-delay:<?php echo (int)($ci * 22); ?>ms;" data-hex="<?php echo sec_h($hex); ?>" onclick="selectColor('<?php echo sec_h($hex); ?>')">
                <svg class="check-ic" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
            </button>
            <?php endforeach; ?>
        </div>
        <div class="custom-color-row">
            <input type="color" id="customColorInput" value="#007AFF" oninput="selectColor(this.value)">
            <span class="cc-label"><?php te('vocab.cat_colore_pers'); ?></span>
            <span class="cc-hex" id="customColorHex">#007AFF</span>
        </div>
        <form id="formColor" method="POST" style="display:none;">
            <input type="hidden" name="action" value="color">
            <input type="hidden" name="category_id" id="color_cat_id">
            <input type="hidden" name="category_color" id="color_cat_value">
        </form>
        <div class="sheet-actions">
            <button type="button" class="btn btn-cancel" onclick="annullaColore()"><?php te('comune.annulla'); ?></button>
            <button type="button" class="btn btn-primary" onclick="salvaColore()"><?php te('comune.salva'); ?></button>
        </div>
    </div>
</div>

<!-- SHEET: Sposta -->
<div id="sheetMove" class="modal-overlay">
    <div class="sheet-panel">
        <h3 class="sheet-title"><?php th('vocab.cat_sposta_titolo', ['nome' => '<span id="moveTargetName" style="color:var(--blue);"></span>']); ?></h3>
        <p class="sheet-sub"><?php te('vocab.cat_scegli_posizione'); ?></p>
        <div class="cat-pick-list" id="moveList">
            <button type="button" class="cat-pick-item cat-pick-root" data-id="0" onclick="selezionaNuovoPadre(0)">
                <span class="cat-pick-dot" style="background:var(--blue)"></span>
                <span class="cat-pick-name"><?php te('vocab.cat_in_cima'); ?></span>
            </button>
            <?php renderMoveList(0, $all_categories, $categories_by_parent); ?>
        </div>
    </div>
</div>

<!-- SHEET: Conferma eliminazione -->
<div id="sheetConfirmDelete" class="modal-overlay">
    <div class="sheet-panel">
        <div class="confirm-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0-1 14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2L4 6"/></svg>
        </div>
        <h3 class="sheet-title"><?php te('vocab.cat_eliminare'); ?></h3>
        <p class="confirm-text"><?php th('vocab.cat_stai_eliminando', ['nome' => '<b id="confermaEliminaNome"></b>']); ?></p>
        <div class="sheet-actions">
            <button type="button" class="btn btn-cancel" onclick="chiudiModals()"><?php te('comune.annulla'); ?></button>
            <button type="button" class="btn btn-danger" onclick="confermaEliminaSubmit()"><?php te('comune.elimina'); ?></button>
        </div>
    </div>
</div>

<form id="formSposta" method="POST" style="display:none;">
    <input type="hidden" name="action" value="move">
    <input type="hidden" name="move_category_id" id="move_cat_id">
    <input type="hidden" name="target_parent_id" id="target_parent_id">
</form>
<form id="formDelete" method="POST" style="display:none;">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="delete_category_id" id="delete_cat_id">
</form>

<?php echo nisama_i18n_json(['comune', 'vocab']); ?>
<script>

/* ======================================================================
   Il nome della categoria non entra piu' dentro onclick.
   Prima ci finiva passato da addslashes(), che protegge dagli apici ma non dal
   carattere "<": bastava chiamare una categoria con un tag di chiusura di script
   per far finire li' il blocco, e il resto della pagina veniva interpretato come
   HTML. (Per lo stesso motivo quella sequenza non si puo' scrivere nemmeno in un
   commento come questo: il browser la vede lo stesso.) Ora il dato sta nell'attributo
   data-name della riga - dove PHP lo scrive con sec_h() - e da li' il
   JavaScript lo legge come testo, senza passare dal codice.
   ====================================================================== */
function rigaDi(el) { return el.closest('.cat-row'); }

function apriModalSalvaDaRiga(btn) {
    var riga = rigaDi(btn);
    apriModalSalva({
        id: parseInt(riga.dataset.id, 10),
        name: riga.dataset.name,
        parentId: parseInt(riga.dataset.parent, 10),
        color: btn.dataset.color,
        colorEdited: btn.dataset.coloredited === 'true'
    });
}
function apriSpostaDaRiga(btn) {
    var riga = rigaDi(btn);
    apriSposta(parseInt(riga.dataset.id, 10), riga.dataset.name);
}
function apriConfermaEliminaDaRiga(btn) {
    var riga = rigaDi(btn);
    apriConfermaElimina(parseInt(riga.dataset.id, 10), riga.dataset.name);
}
if (window.matchMedia("(pointer:coarse)").matches) { document.body.classList.add("touch-device"); }

var CAT_COLOR_MAP = <?php echo json_encode($catColorMap); ?>;

/* Mappa figli per calcolo sottoalbero (evita cicli nello spostamento) */
var childrenMap = {};
document.querySelectorAll('.cat-row').forEach(function(r){
    var p = r.dataset.parent || '0';
    (childrenMap[p] = childrenMap[p] || []).push(r.dataset.id);
});
function subtreeIds(id){
    var out = [String(id)], stack = [String(id)];
    while (stack.length){
        var cur = stack.pop();
        (childrenMap[cur] || []).forEach(function(c){ out.push(c); stack.push(c); });
    }
    return out;
}

/* ---------- Sheet open/close ---------- */
function openSheet(el){ el.style.display='flex'; el.classList.remove('closing'); }
function closeSheet(el){ el.classList.add('closing'); setTimeout(function(){ el.style.display='none'; el.classList.remove('closing'); }, 240); }
function chiudiModals(){ document.querySelectorAll('.modal-overlay').forEach(function(ov){ if (ov.style.display==='flex') closeSheet(ov); }); }
document.querySelectorAll('.modal-overlay').forEach(function(ov){ ov.addEventListener('click', function(e){ if (e.target===ov) closeSheet(ov); }); });

/* ---------- Espandi / comprimi ---------- */
function toggleBranch(id){
    var row = document.querySelector('.cat-row[data-id="'+id+'"]');
    var arrow = row.querySelector('.cat-arrow');
    if (arrow.classList.contains('open')){ arrow.classList.remove('open'); comprimiFigli(id); }
    else { arrow.classList.add('open'); espandiFigli(id); }
}
function espandiFigli(parentId){
    document.querySelectorAll('.cat-row[data-parent="'+parentId+'"]').forEach(function(row){
        row.style.display='flex'; row.style.animation='none'; void row.offsetWidth; row.style.animation=''; row.classList.add('row-anim');
        var subArrow = row.querySelector('.cat-arrow');
        if (subArrow && subArrow.classList.contains('open')) espandiFigli(row.dataset.id);
    });
}
function comprimiFigli(parentId){
    document.querySelectorAll('.cat-row[data-parent="'+parentId+'"]').forEach(function(row){ row.style.display='none'; comprimiFigli(row.dataset.id); });
}

/* ---------- Ricerca ---------- */
function filterCategories(raw){
    var q = raw.trim().toLowerCase();
    var rows = document.querySelectorAll('.cat-row');
    document.getElementById('searchClear').style.display = q ? 'flex' : 'none';
    if (!q){
        rows.forEach(function(r){ r.style.display = (r.dataset.level==='0') ? 'flex' : 'none'; });
        document.querySelectorAll('.cat-arrow.open').forEach(function(a){ a.classList.remove('open'); });
        document.querySelectorAll('.materia-card').forEach(function(c){ c.style.display=''; });
        return;
    }
    var byId = {}; rows.forEach(function(r){ byId[r.dataset.id]=r; });
    var visible = new Set();
    rows.forEach(function(r){
        if (r.dataset.name.toLowerCase().indexOf(q)!==-1){
            var cur=r;
            while(cur){ visible.add(cur.dataset.id); var pid=cur.dataset.parent; cur=(pid && pid!=='0')?byId[pid]:null; }
        }
    });
    rows.forEach(function(r){
        var show = visible.has(r.dataset.id);
        r.style.display = show ? 'flex' : 'none';
        if (show){ var a=r.querySelector('.cat-arrow'); if (a) a.classList.add('open'); }
    });
    document.querySelectorAll('.materia-card').forEach(function(card){
        var any = Array.prototype.some.call(card.querySelectorAll('.cat-row'), function(r){ return visible.has(r.dataset.id); });
        card.style.display = any ? '' : 'none';
    });
}
function clearSearch(){ var i=document.getElementById('searchInput'); i.value=''; filterCategories(''); i.focus(); }

/* ---------- Nuova / Modifica ---------- */
var formColorManual=false, formColorValue='#A0A0A0', formParentColor='#A0A0A0';
function apriModalSalva(opts){
    document.getElementById('form_cat_id').value = opts.id;
    document.getElementById('form_cat_name').value = opts.name;
    document.getElementById('modalSalvaTitolo').textContent = (opts.id==0) ? T('vocab.cat_nuova') : T('vocab.cat_modifica_titolo');

    var sel = document.getElementById('form_parent_id');
    sel.value = opts.parentId;
    var excluded = (opts.id==0) ? [] : subtreeIds(opts.id);
    for (var i=0;i<sel.options.length;i++){ sel.options[i].disabled = excluded.indexOf(sel.options[i].value)!==-1 && sel.options[i].value!=='0'; }
    if (sel.selectedOptions.length && sel.selectedOptions[0].disabled){ sel.value='0'; }

    formColorManual = !!opts.colorEdited;
    formParentColor = (opts.parentId==0) ? '#A0A0A0' : (CAT_COLOR_MAP[opts.parentId] || '#A0A0A0');
    formColorValue = formColorManual ? (opts.color || '#A0A0A0') : formParentColor;
    updateFormColorUI();

    openSheet(document.getElementById('sheetForm'));
    setTimeout(function(){ document.getElementById('form_cat_name').focus(); }, 350);
}
document.getElementById('form_parent_id').addEventListener('change', function(){
    formParentColor = (this.value=='0') ? '#A0A0A0' : (CAT_COLOR_MAP[this.value] || '#A0A0A0');
    if (!formColorManual){ formColorValue = formParentColor; updateFormColorUI(); }
});
function setFormColorAuto(){ formColorManual=false; formColorValue=formParentColor; updateFormColorUI(); }
function setFormColorManual(hex){ formColorManual=true; formColorValue=hex; updateFormColorUI(); }
function updateFormColorUI(){
    document.getElementById('form_color_mode').value = formColorManual ? 'manual' : 'auto';
    document.getElementById('form_color_value').value = formColorValue;
    document.getElementById('formColorPreviewIcon').style.setProperty('--row-color', formColorValue);
    document.getElementById('formColorPreviewName').textContent = formColorManual ? T('vocab.cat_colore_pers') : T('vocab.cat_auto_eredita');
    document.getElementById('formCustomColorInput').value = formColorValue;
    document.getElementById('formCustomColorHex').textContent = formColorValue.toUpperCase();
    document.querySelectorAll('#formColorGrid .color-swatch').forEach(function(sw){
        if (sw.classList.contains('color-swatch-auto')) sw.classList.toggle('selected', !formColorManual);
        else sw.classList.toggle('selected', formColorManual && (sw.dataset.hex||'').toLowerCase()===formColorValue.toLowerCase());
    });
}

/* ---------- Azioni (kebab) ---------- */
function apriAzioni(btn){
    var id=btn.dataset.id, name=btn.dataset.name, parentId=btn.dataset.parent;
    var color=btn.dataset.color||'#007AFF', colorEdited=btn.dataset.colorEdited==='1', canDelete=btn.dataset.candelete==='1';
    document.getElementById('azioniTitolo').textContent = name;
    document.getElementById('azioneModifica').onclick = function(){ chiudiModals(); setTimeout(function(){ apriModalSalva({id:id,name:name,parentId:parentId,color:color,colorEdited:colorEdited}); }, 260); };
    document.getElementById('azioneColore').onclick = function(){ chiudiModals(); setTimeout(function(){ apriColore(id,name,color); }, 260); };
    document.getElementById('azioneSposta').onclick = function(){ chiudiModals(); setTimeout(function(){ apriSposta(id,name); }, 260); };
    var delBtn = document.getElementById('azioneElimina');
    if (canDelete){ delBtn.style.display='flex'; delBtn.onclick=function(){ chiudiModals(); setTimeout(function(){ apriConfermaElimina(id,name); }, 260); }; }
    else { delBtn.style.display='none'; }
    openSheet(document.getElementById('sheetActions'));
}

/* ---------- Sposta ---------- */
function apriSposta(id, name){
    document.getElementById('move_cat_id').value = id;
    document.getElementById('moveTargetName').textContent = '"' + name + '"';
    var excluded = subtreeIds(id);
    document.querySelectorAll('#moveList .cat-pick-item').forEach(function(it){
        var iid = it.dataset.id;
        it.style.display = (iid!=='0' && excluded.indexOf(iid)!==-1) ? 'none' : 'flex';
    });
    openSheet(document.getElementById('sheetMove'));
}
function selezionaNuovoPadre(parentId){ document.getElementById('target_parent_id').value = parentId; document.getElementById('formSposta').submit(); }

/* ---------- Colore ---------- */
var colorTargetId=null, colorOriginal='#007AFF', colorCurrent='#007AFF';
function apriColore(id, name, color){
    colorTargetId=id; colorOriginal=color; colorCurrent=color;
    document.getElementById('color_cat_id').value=id;
    document.getElementById('colorPreviewName').textContent=name;
    document.getElementById('customColorInput').value=color;
    document.getElementById('customColorHex').textContent=color.toUpperCase();
    applyColorPreview(color); highlightSwatch(color);
    openSheet(document.getElementById('sheetColor'));
}
function selectColor(hex){
    colorCurrent=hex;
    document.getElementById('customColorInput').value=hex;
    document.getElementById('customColorHex').textContent=hex.toUpperCase();
    applyColorPreview(hex); highlightSwatch(hex);
}
function highlightSwatch(hex){
    var norm=hex.toLowerCase();
    document.querySelectorAll('#colorGrid .color-swatch').forEach(function(sw){ if (sw.dataset.hex) sw.classList.toggle('selected', sw.dataset.hex.toLowerCase()===norm); });
}
function applyColorPreview(hex){
    document.getElementById('colorPreviewIcon').style.setProperty('--row-color', hex);
    var row = document.querySelector('.cat-row[data-id="'+colorTargetId+'"]');
    if (row){
        row.style.setProperty('--row-color', hex);
        if (row.dataset.level==='0'){ var card=row.closest('.materia-card'); if (card) card.style.setProperty('--accent', hex); }
    }
    var kebab = document.querySelector('.cat-kebab[data-id="'+colorTargetId+'"]');
    if (kebab) kebab.dataset.color = hex;
}
function annullaColore(){ applyColorPreview(colorOriginal); chiudiModals(); }
function salvaColore(){ document.getElementById('color_cat_value').value=colorCurrent; document.getElementById('formColor').submit(); }

/* ---------- Elimina ---------- */
function apriConfermaElimina(id, name){
    document.getElementById('delete_cat_id').value=id;
    document.getElementById('confermaEliminaNome').textContent='"'+name+'"';
    openSheet(document.getElementById('sheetConfirmDelete'));
}
function confermaEliminaSubmit(){ document.getElementById('formDelete').submit(); }

/* ---------- Toast ---------- */
function showToast(text){
    var t=document.createElement('div'); t.className='toast';
    t.innerHTML='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg><span>'+text+'</span>';
    document.body.appendChild(t);
    requestAnimationFrame(function(){ t.classList.add('show'); });
    setTimeout(function(){ t.classList.remove('show'); setTimeout(function(){ t.remove(); }, 320); }, 2200);
}
(function(){
    var ok = new URLSearchParams(window.location.search).get('ok');
    var messages = { added:T('vocab.cat_creata'), saved:T('vocab.cat_salvate'), deleted:T('vocab.cat_eliminata'), moved:T('vocab.cat_spostata'), colored:T('vocab.cat_colore_ok') };
    if (ok && messages[ok]){ showToast(messages[ok]); history.replaceState(null, '', window.location.pathname); }
})();

/* ---------- Navigazione riga ---------- */
function catRowNavigate(e, el){
    if (el.classList.contains('swiped-open')){ chiudiTuttiSwipe(); return; }
    if (document.querySelector('.cat-row-main.swiped-open')){ chiudiTuttiSwipe(); return; }
    var link = el.getAttribute('data-cat-link');
    if (link) window.location.href = link;
}

/* ---------- Swipe (touch, sinistra->destra rivela le azioni) ---------- */
window.chiudiTuttiSwipe = function(){};
(function(){
    if (!window.matchMedia("(hover:none) and (pointer:coarse)").matches) return;
    var activeRow=null, startX=0, startY=0, isDragging=false, maxSwipe=0, dirDecided=false, isHorizontal=false;
    var DEADZONE=10, RATIO=2.2; // serve un movimento chiaramente orizzontale (>2.2x quello verticale) prima di attivare lo swipe
    window.chiudiTuttiSwipe = function(){
        document.querySelectorAll('.cat-row-main.swiped-open').forEach(function(r){ r.style.transform='translateX(0)'; r.classList.remove('swiped-open'); });
        activeRow=null;
    };
    document.addEventListener('touchstart', function(e){
        if (e.target.closest('.cat-swipe-actions')) return;
        var rowMain = e.target.closest('.cat-row-main');
        if (!rowMain){ chiudiTuttiSwipe(); return; }
        if (activeRow && activeRow!==rowMain) chiudiTuttiSwipe();
        activeRow=rowMain;
        var actions = rowMain.parentElement.querySelector('.cat-swipe-actions');
        if (!actions) return;
        maxSwipe = actions.offsetWidth;
        startX=e.touches[0].clientX; startY=e.touches[0].clientY; isDragging=true; dirDecided=false; isHorizontal=false;
        rowMain.style.transition='none';
    }, {passive:true});
    document.addEventListener('touchmove', function(e){
        if (!isDragging || !activeRow) return;
        var dx=e.touches[0].clientX-startX, dy=e.touches[0].clientY-startY;
        if (!dirDecided){
            if (Math.abs(dx) < DEADZONE && Math.abs(dy) < DEADZONE) return;
            dirDecided = true;
            isHorizontal = Math.abs(dx) > Math.abs(dy) * RATIO;
            if (!isHorizontal){ isDragging=false; activeRow=null; return; }
        }
        if (!isHorizontal) return;
        if (dx>0){ var tx=Math.min(dx, maxSwipe+20); activeRow.style.transform='translateX('+tx+'px)'; e.preventDefault(); }
        else if (dx<0){ var t2=Math.max(dx,-15); activeRow.style.transform='translateX('+t2+'px)'; e.preventDefault(); }
    }, {passive:false});
    document.addEventListener('touchend', function(){
        if (!isDragging || !activeRow) return;
        isDragging=false; activeRow.style.transition='transform .3s cubic-bezier(.32,.72,0,1)';
        var tx=0, ts=activeRow.style.transform;
        if (ts && ts.indexOf('translateX')!==-1) tx=parseFloat(ts.replace('translateX(',''));
        if (tx>maxSwipe/2){ activeRow.style.transform='translateX('+maxSwipe+'px)'; activeRow.classList.add('swiped-open'); }
        else { activeRow.style.transform='translateX(0)'; activeRow.classList.remove('swiped-open'); activeRow=null; }
    });
})();
</script>
</body>
</html>