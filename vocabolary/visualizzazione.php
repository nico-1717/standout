<?php
require_once 'config.php';
require_once 'auth_check.php';
$current_user_id = (int)$utente_corrente['id'];

/* sec_db() DOPO auth_check.php: e' quest'ultimo a caricare security_core.php,
   dove la funzione e' definita. Chiamarla prima e' un errore fatale, e la
   pagina risponde 500 senza dire altro. */
$conn = sec_db();

/* NOTA SCHEMA: la tabella `images` ha come chiave primaria `id` (vedi dump:
   AUTO_INCREMENT su `id` e FK english_child_words.parent_id -> images(id)).
   Il vecchio file usava `idimmagine`: se la tua tabella reale usa ancora quel
   nome, sostituisci `i.id`/`WHERE id` con `idimmagine`. */

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

// ==========================================
// AZIONI DI MASSA E JOIN / UNJOIN
// ==========================================
/* Filtra un elenco di id tenendo solo le parole di questo utente.
   Prima non c'era: gli id del modulo finivano nelle query senza controllo, e
   cambiando un numero si cancellavano le parole di un altro. */
function en_solo_miei(array $ids, int $userId): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (empty($ids)) return [];
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $ok = [];
    foreach (sec_all("SELECT i.id FROM images i
                      JOIN categories c ON c.id = i.category_id
                      WHERE i.id IN ($ph) AND c.user_id = ?", array_merge($ids, [$userId])) as $r) {
        $ok[] = (int)$r['id'];
    }
    return $ok;
}

/* Come sopra, ma per i figli: la proprieta' si verifica passando dal padre. */
function en_figli_miei(array $ids, int $userId): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (empty($ids)) return [];
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $ok = [];
    foreach (sec_all("SELECT ch.id_child FROM english_child_words ch
                      JOIN images i ON i.id = ch.parent_id
                      JOIN categories c ON c.id = i.category_id
                      WHERE ch.id_child IN ($ph) AND c.user_id = ?", array_merge($ids, [$userId])) as $r) {
        $ok[] = (int)$r['id_child'];
    }
    return $ok;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {
    /* Token di sessione, origine riconosciuta e limite di frequenza: prima non
       c'erano, su azioni che cancellano. */
    sec_guard_write((int)$current_user_id, 'vocab_vis', 0, 120, 60);

    $action = sec_enum($_POST['bulk_action'] ?? null, ['delete', 'move', 'join', 'unjoin'], '');
    $qs = "";
    if (isset($_GET['category_id'])) { $qs = "?category_id=" . sec_int($_GET['category_id'], 0); }
    if (isset($_GET['sort'])) { $qs .= ($qs === '' ? '?' : '&') . "sort=" . urlencode(sec_str($_GET['sort'], 20)); }

    if ($action === 'delete') {
        $miei = en_solo_miei($_POST['selected_ids'] ?? [], (int)$current_user_id);
        if (!empty($miei)) {
            $ph = implode(',', array_fill(0, count($miei), '?'));
            sec_q("DELETE FROM images WHERE id IN ($ph)", $miei);
        }
        $figli = en_figli_miei($_POST['selected_child_ids'] ?? [], (int)$current_user_id);
        if (!empty($figli)) {
            $ph = implode(',', array_fill(0, count($figli), '?'));
            sec_q("DELETE FROM english_child_words WHERE id_child IN ($ph)", $figli);
        }
        $qs .= "&ok=deleted";
    }
    elseif ($action === 'move' && isset($_POST['target_category'])) {
        $target_cat = sec_int($_POST['target_category'], 1);
        $miei = en_solo_miei($_POST['selected_ids'] ?? [], (int)$current_user_id);
        /* anche la categoria di destinazione dev'essere sua */
        if (!empty($miei) && sec_owns('categories', $target_cat, (int)$current_user_id)) {
            $ph = implode(',', array_fill(0, count($miei), '?'));
            sec_q("UPDATE images SET category_id = ? WHERE id IN ($ph)", array_merge([$target_cat], $miei));
        }
        $qs .= "&ok=moved";
    }
    elseif ($action === 'join' && isset($_POST['father_id'])) {
        $father_id = sec_int($_POST['father_id'], 1);
        $miei = en_solo_miei($_POST['selected_ids'] ?? [], (int)$current_user_id);
        /* anche il padre dev'essere una parola sua */
        if (!empty($miei) && en_solo_miei([$father_id], (int)$current_user_id)) {
            foreach ($miei as $child_id) {
                if ($child_id === $father_id) continue;
                $row = sec_one("SELECT * FROM images WHERE id = ?", [$child_id]);
                if (!$row) continue;
                sec_q("INSERT INTO english_child_words (parent_id, image, description_en, description_it, meaning, example)
                       VALUES (?, ?, ?, ?, ?, ?)",
                      [$father_id, $row['image'], $row['description_en'], $row['description_it'],
                       $row['meaning'], $row['example']]);
                sec_q("DELETE FROM images WHERE id = ?", [$child_id]);
            }
            sec_q("UPDATE images SET is_father = 1 WHERE id = ?", [$father_id]);
        }
        $qs .= "&ok=joined";
    }
    elseif ($action === 'unjoin') {
        $parents_to_check = [];
        foreach (en_figli_miei($_POST['selected_child_ids'] ?? [], (int)$current_user_id) as $child_id) {
            $row = sec_one("SELECT * FROM english_child_words WHERE id_child = ?", [$child_id]);
            if (!$row) continue;
            $parent_id = (int)$row['parent_id'];
            $parents_to_check[$parent_id] = true;
            $p_row = sec_one("SELECT category_id FROM images WHERE id = ?", [$parent_id]);
            $cat_id = $p_row ? (int)$p_row['category_id'] : 0;
            sec_q("INSERT INTO images (image, description_en, description_it, meaning, example, category_id, is_father)
                   VALUES (?, ?, ?, ?, ?, ?, 0)",
                  [$row['image'], $row['description_en'], $row['description_it'],
                   $row['meaning'], $row['example'], $cat_id]);
            sec_q("DELETE FROM english_child_words WHERE id_child = ?", [$child_id]);
        }
        foreach (array_keys($parents_to_check) as $pid) {
            $n = (int)(sec_one("SELECT COUNT(*) AS c FROM english_child_words WHERE parent_id = ?", [$pid])['c'] ?? 0);
            if ($n === 0) sec_q("UPDATE images SET is_father = 0 WHERE id = ?", [$pid]);
        }
        $qs .= "&ok=unjoined";
    }

    header("Location: visualizzazione.php" . $qs);
    exit;
}

// Mappa categorie DI QUESTO UTENTE (per breadcrumb, colori, sottocategorie, foglio "sposta")
$cat_map = [];
$cat_children = [];
foreach (sec_all("SELECT id, name, parent_id, color, color_edited FROM categories WHERE user_id = ? ORDER BY name",
                 [(int)$current_user_id]) as $c) {
    if (intval($c['color_edited']) === 0) {
        $c['color'] = en_resolve_color($c['parent_id'], $current_user_id);
    }
    $cat_map[(int)$c['id']] = $c;
    $cat_children[(int)$c['parent_id']][] = (int)$c['id'];
}

// Conteggio parole (images) per categoria — solo categorie di questo utente
$count_by_cat = [];
foreach (sec_all("SELECT i.category_id, COUNT(*) AS n FROM images AS i INNER JOIN categories AS c ON i.category_id = c.id WHERE c.user_id = ? GROUP BY i.category_id",
                 [(int)$current_user_id]) as $r) { $count_by_cat[(int)$r['category_id']] = (int)$r['n']; }

// Duplicati (per description_en, solo sulle parole di questo utente)
$global_en_counts = [];
foreach (sec_all("SELECT i.description_en FROM images AS i INNER JOIN categories AS c ON i.category_id = c.id WHERE c.user_id = ?",
                 [(int)$current_user_id]) as $r) {
    $en_k = strtolower(trim($r['description_en']));
    if (!isset($global_en_counts[$en_k])) $global_en_counts[$en_k] = 0;
    $global_en_counts[$en_k]++;
}

$categoryID = sec_int($_GET['category_id'] ?? 0, 0);

// Info categoria corrente + breadcrumb
$currentCatName = T('vocab.vi_tutte_parole');
$themeColor = '#5E5CE6';
$breadcrumb = [];
if ($categoryID != 0 && isset($cat_map[$categoryID])) {
    $currentCatName = $cat_map[$categoryID]['name'];
    $cc2 = $cat_map[$categoryID]['color'];
    if (!empty($cc2) && preg_match('/^#[0-9A-Fa-f]{6}$/', $cc2)) $themeColor = $cc2;
    $walk = $categoryID; $guard = 0;
    while ($walk != 0 && isset($cat_map[$walk]) && $guard < 50) {
        $guard++;
        array_unshift($breadcrumb, ['id' => $walk, 'name' => $cat_map[$walk]['name']]);
        $walk = (int)$cat_map[$walk]['parent_id'];
    }
}

// Sottocategorie della categoria corrente
$subcats = [];
if ($categoryID != 0 && isset($cat_children[$categoryID])) {
    foreach ($cat_children[$categoryID] as $sid) {
        $sc = $cat_map[$sid];
        $subcats[] = ['id' => $sid, 'name' => $sc['name'],
                      'color' => (!empty($sc['color']) && preg_match('/^#[0-9A-Fa-f]{6}$/', $sc['color'])) ? $sc['color'] : '#A0A0A0',
                      'count' => isset($count_by_cat[$sid]) ? $count_by_cat[$sid] : 0];
    }
}

// Query principale — query "blindata": la JOIN rigida su categories garantisce che
// vengano mostrate solo le parole delle categorie di questo utente.
$query = "SELECT i.*, c.name AS category_name, c.color AS category_color,
                 (CASE WHEN ul.image_id IS NOT NULL THEN 1 ELSE 0 END) AS is_learnt
          FROM images AS i
          INNER JOIN categories AS c ON i.category_id = c.id
          LEFT JOIN user_learnt_english ul ON i.id = ul.image_id AND ul.user_id = ?
          WHERE c.user_id = ?";
$par = [(int)$current_user_id, (int)$current_user_id];
if ($categoryID != 0) { $query .= " AND i.category_id = ?"; $par[] = $categoryID; }

/* $order_by non e' un valore ma un pezzo di SQL, e non puo' essere un
   segnaposto: nasce da questo if a quattro rami, quindi non arriva dall'esterno
   e non c'e' modo di farci passare altro. */
$sort = sec_enum($_GET['sort'] ?? null, ['id_desc', 'id_asc', 'en_asc', 'it_asc'], 'id_desc');
$order_by = "i.id DESC";
if ($sort === 'id_asc') { $order_by = "i.id ASC"; }
elseif ($sort === 'en_asc') { $order_by = "TRIM(LOWER(i.description_en)) ASC"; }
elseif ($sort === 'it_asc') { $order_by = "TRIM(LOWER(i.description_it)) ASC"; }
$query .= " ORDER BY $order_by";

$images_data = [];
$ids_array = [];
foreach (sec_all($query, $par) as $row) {
    $row['children'] = [];
    $images_data[$row['id']] = $row;
    $ids_array[] = (int)$row['id'];
}
if (count($ids_array) > 0) {
    $ph = implode(',', array_fill(0, count($ids_array), '?'));
    foreach (sec_all("SELECT * FROM english_child_words WHERE parent_id IN ($ph)", $ids_array) as $c) {
        if (isset($images_data[$c['parent_id']])) $images_data[$c['parent_id']]['children'][] = $c;
    }
}
$wordCount = count($images_data);

// Formatta testo (escape + newline). Usata per il Significato.
function eng_text($t) {
    $t = sec_h($t);
    return nl2br($t);
}
// Formatta l'Esempio: dove ci sono "___" mostra la parola inglese in grassetto,
// colorata con il colore della categoria (mescolato con --text per restare leggibile
// anche quando il colore categoria è vicino al bianco o al nero).
function eng_example_text($t, $enWord) {
    $t = sec_h($t);
    $fill = trim($enWord) !== '' ? sec_h($enWord) : '&hellip;';
    $t = preg_replace_callback('/_+/', function ($m) use ($fill) {
        return '<strong class="eng-fill">' . $fill . '</strong>';
    }, $t);
    return nl2br($t);
}
function eng_search($row) {
    return sec_h(strtolower(($row['description_en'] ?? '') . ' ' . ($row['description_it'] ?? '') . ' ' . ($row['meaning'] ?? '') . ' ' . ($row['example'] ?? '')));
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=0, viewport-fit=cover">
<meta name="theme-color" content="#F2F2F7" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#000000" media="(prefers-color-scheme: dark)">
<title><?php echo sec_h($currentCatName); ?> · <?php te('vocab.vi_inglese'); ?></title>
<link rel="stylesheet" href="<?php echo PWA_BASE; ?>visualizzazione.css?v=<?php echo @filemtime(dirname(__FILE__) . '/visualizzazione.css') ?: 1; ?>">
</head>
<body style="--cat-color:<?php echo sec_h($themeColor); ?>;">

<!-- HEADER normale -->
<div class="app-header" id="appHeader">
    <div class="header-top">
        <a href="categories.php" class="cat-pill" id="catPill">
            <span class="cat-pill-dot"></span>
            <span class="cat-pill-text">
                <span class="cat-pill-label"><?php te('vocab.ins_categoria'); ?></span>
                <span class="cat-pill-name"><?php echo sec_h($currentCatName); ?></span>
            </span>
            <span class="cat-pill-count"><?php echo (int)$wordCount; ?> <?php echo sec_h($wordCount == 1 ? 'parola' : 'parole'); ?></span>
        </a>
    </div>

    <div class="header-row2">
        <nav class="cat-path" id="catPath" aria-label="<?php te('vocab.vi_percorso'); ?>">
            <a href="categories.php" class="cat-path-home" aria-label="<?php te('vocab.vi_home'); ?>" title="<?php te('vocab.vi_torna_cat'); ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m3 10 9-7 9 7v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2Z"/></svg>
            </a>
            <span class="cat-path-sep" aria-hidden="true">&rsaquo;</span>
            <?php if ($categoryID == 0): ?>
            <span class="cat-path-item cat-path-current"><?php te('vocab.vi_tutte_parole'); ?></span>
            <?php else: foreach ($breadcrumb as $bi => $bc): ?>
                <?php if ($bi === count($breadcrumb) - 1): ?>
                <span class="cat-path-item cat-path-current"><?php echo sec_h($bc['name']); ?></span>
                <?php else: ?>
                <a class="cat-path-item" href="visualizzazione.php?category_id=<?php echo (int)$bc['id']; ?>"><?php echo sec_h($bc['name']); ?></a>
                <span class="cat-path-sep" aria-hidden="true">&rsaquo;</span>
                <?php endif; ?>
            <?php endforeach; endif; ?>
        </nav>

        <a href="inserimento.php?category_id=<?php echo (int)$categoryID; ?>" class="icon-btn quick-add-btn" aria-label="<?php te('vocab.vi_aggiungi_parola'); ?>" title="<?php te('vocab.vi_aggiungi_in_cat'); ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
        </a>
        <?php if ($categoryID != 0): ?>
        <a href="categories_game.php?preselect_category=<?php echo (int)$categoryID; ?>" class="icon-btn play-cat-btn" aria-label="Gioca con questa categoria" title="<?php te('vocab.vi_gioca'); ?>">
            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M8 5.14v13.72a1 1 0 0 0 1.53.85l11-6.86a1 1 0 0 0 0-1.7l-11-6.86A1 1 0 0 0 8 5.14Z"/></svg>
        </a>
        <a href="big_edit.php?category_id=<?php echo (int)$categoryID; ?>" class="icon-btn big-edit-btn" aria-label="Big Edit" title="<?php te('vocab.vi_big_edit'); ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
        </a>
        <?php endif; ?>
    </div>

    <div class="toolbar-row" id="normalToolbar">
        <div class="search-wrap">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
            <input id="filterText" class="search-input" type="text" placeholder="<?php te('vocab.vi_cerca'); ?>" oninput="visibleLimit=30; filterList()" autocomplete="off">
        </div>
        <div class="tb-select-wrap">
            <select id="filterSort" class="tb-select" onchange="changeSort()">
                <option value="id_desc" <?php echo sec_h($sort == 'id_desc' ? 'selected' : ''); ?>><?php te('vocab.vi_recenti'); ?></option>
                <option value="id_asc" <?php echo sec_h($sort == 'id_asc' ? 'selected' : ''); ?>><?php te('vocab.vi_meno_recenti'); ?></option>
                <option value="en_asc" <?php echo sec_h($sort == 'en_asc' ? 'selected' : ''); ?>><?php te('vocab.vi_az_en'); ?></option>
                <option value="it_asc" <?php echo sec_h($sort == 'it_asc' ? 'selected' : ''); ?>><?php te('vocab.vi_az_it'); ?></option>
            </select>
        </div>
        <div class="tb-select-wrap">
            <select id="filterStatus" class="tb-select" onchange="visibleLimit=30; filterList()">
                <option value="all"><?php te('vocab.vi_tutte'); ?></option>
                <option value="not_learnt"><?php te('vocab.vi_da_imparare'); ?></option>
                <option value="learnt"><?php te('vocab.vi_imparate'); ?></option>
                <option value="duplicates"><?php te('vocab.vi_duplicati'); ?></option>
            </select>
        </div>
        <button type="button" class="select-mode-btn" id="selectModeBtn" onclick="enterSelectMode()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m9 12 2 2 4-4"/><rect x="3" y="3" width="18" height="18" rx="6"/></svg>
            <?php te('vocab.vi_seleziona'); ?>
        </button>
    </div>
</div>

<!-- SELECT TOOLBAR -->
<div class="select-toolbar" id="selectToolbar">
    <div class="st-row">
        <button type="button" class="st-cancel" onclick="exitSelectMode()"><?php te('comune.annulla'); ?></button>
        <div class="st-count" id="stCount"><?php te('vocab.vi_selezionate', ['n' => 0]); ?></div>
        <button type="button" class="st-all" onclick="toggleSelectAll()"><?php te('vocab.vi_tutte'); ?></button>
    </div>
    <div class="st-hint" id="stHint"><?php te('vocab.vi_tocca_principale'); ?></div>
    <div class="st-actions" id="stActions">
        <button type="button" class="st-btn" id="stMove" onclick="onBulkAction('move')">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 9l-3 3 3 3M19 9l3 3-3 3M2 12h20"/></svg><?php te('vocab.cat_sposta_attr'); ?>
        </button>
        <button type="button" class="st-btn" id="stJoin" onclick="onBulkAction('join')">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 6h9a4 4 0 0 1 0 8H9M16 18H7a4 4 0 0 1 0-8h1"/></svg><?php te('vocab.vi_unisci'); ?>
        </button>
        <button type="button" class="st-btn" id="stUnjoin" onclick="onBulkAction('unjoin')">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 7h2a4 4 0 0 1 0 8h-1M9 17H7A4 4 0 0 1 7 9h1M8 12h4M2 2l20 20"/></svg><?php te('vocab.vi_separa'); ?>
        </button>
        <button type="button" class="st-btn danger" id="stDelete" onclick="onBulkAction('delete')">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0-1 14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2L4 6"/></svg>Elimina
        </button>
    </div>
</div>

<?php include 'sidebar.html'; ?>

<div class="count-line" id="countLine"></div>

<div class="page">
    <?php if (!empty($subcats)): ?>
    <div class="subcats" id="subcatsBlock">
        <div class="subcats-title"><?php te('vocab.vi_sottocategorie'); ?></div>
        <div class="subcats-grid">
            <?php foreach ($subcats as $sc): ?>
            <a href="visualizzazione.php?category_id=<?php echo (int)$sc['id']; ?>" class="subcat-tile" style="--sc:<?php echo sec_h($sc['color']); ?>;">
                <span class="subcat-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z"/></svg></span>
                <span class="subcat-info">
                    <span class="subcat-name"><?php echo sec_h($sc['name']); ?></span>
                    <span class="subcat-count"><?php echo (int)$sc['count']; ?> <?php echo sec_h($sc['count'] == 1 ? 'parola' : 'parole'); ?></span>
                </span>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <div id="wordsWrap">
    <?php if ($wordCount > 0): $ci = 0; foreach ($images_data as $row):
        $rowColor = (!empty($row['category_color']) && preg_match('/^#[0-9A-Fa-f]{6}$/', $row['category_color'])) ? $row['category_color'] : '#8E8E93';
        $isLearnt = ($row['is_learnt'] == 1);
        $enKey = strtolower(trim($row['description_en']));
        $isDup = (isset($global_en_counts[$enKey]) && $global_en_counts[$enKey] > 1);
        $delay = min($ci * 35, 350); $ci++;
        $catName = $row['category_name'] ? sec_h($row['category_name']) : 'Nessuna';
        $enWord = trim($row['description_en']) !== '' ? sec_h($row['description_en']) : '';
        $itWord = trim($row['description_it']) !== '' ? sec_h($row['description_it']) : '';
    ?>
        <div class="word-group">
            <div class="eng-card father-card <?php echo sec_h($isLearnt ? 'is-learnt' : ''); ?>"
                 id="card-img-<?php echo (int)$row['id']; ?>" data-kind="father" data-id="<?php echo (int)$row['id']; ?>"
                 data-learnt="<?php echo sec_h($isLearnt ? '1' : '0'); ?>" data-duplicate="<?php echo sec_h($isDup ? 'true' : 'false'); ?>"
                 data-search="<?php echo eng_search($row); ?>" style="--row-color:<?php echo sec_h($rowColor); ?>; animation-delay:<?php echo (int)$delay; ?>ms;">
                <div class="swipe-actions">
                    <a href="modifica_immagine.php?image_id=<?php echo (int)$row['id']; ?>&previous_catid=<?php echo (int)$categoryID; ?>" class="swipe-btn swipe-edit" aria-label="Modifica">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                    </a>
                    <button type="button" class="swipe-btn swipe-delete" aria-label="Elimina" onclick="askDeleteSingle('father', <?php echo (int)$row['id']; ?>)">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0-1 14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2L4 6"/></svg>
                    </button>
                </div>
                <div class="swipe-content" onclick="onCardTap(event, this)">
                    <div class="card-inner">
                        <div class="card-head">
                            <span class="select-circle"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg></span>
                            <?php if ($current_user_id == 3): ?>
                                <span class="learnt-static">–</span>
                            <?php else: ?>
                                <label class="learnt-wrapper" onclick="event.stopPropagation();">
                                    <input type="checkbox" <?php echo sec_h($isLearnt ? 'checked' : ''); ?> onclick="toggleLearnt(<?php echo (int)$row['id']; ?>, this)">
                                    <span class="learnt-emoji">🧐</span>
                                    <span class="learnt-check"><svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3.4" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg></span>
                                </label>
                            <?php endif; ?>
                            <span class="head-spacer"></span>
                            <?php if ($isDup): ?>
                            <span class="badge-dup"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/></svg><?php te('vocab.vi_duplicato'); ?></span>
                            <?php endif; ?>
                            <?php if ($categoryID == 0): ?>
                            <span class="badge-cat"><span class="dot"></span><span><?php echo sec_h($catName); ?></span></span>
                            <?php endif; ?>
                            <button type="button" class="card-kebab" aria-label="Azioni" onclick="event.stopPropagation(); apriAzioniParola('father', <?php echo (int)$row['id']; ?>, 'modifica_immagine.php?image_id=<?php echo (int)$row['id']; ?>&previous_catid=<?php echo (int)$categoryID; ?>');">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><circle cx="12" cy="5" r="1.4" fill="currentColor" stroke="none"/><circle cx="12" cy="12" r="1.4" fill="currentColor" stroke="none"/><circle cx="12" cy="19" r="1.4" fill="currentColor" stroke="none"/></svg>
                            </button>
                        </div>

                        <div class="eng-bilingual">
                            <div class="eng-col eng-en">
                                <div class="eng-eyebrow"><span class="eng-eyebrow-label"><span class="eng-flag">🇬🇧</span> <?php te('vocab.ins_english'); ?></span><?php if ($enWord !== ''): ?><button type="button" class="eng-speak-btn" aria-label="Ascolta in inglese" onclick="event.stopPropagation(); speakWord(this, <?php echo sec_h(json_encode($row['description_en'])); ?>, 'en-GB');"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 5 6 9H2v6h4l5 4z"/><path d="M15.5 8.5a5 5 0 0 1 0 7"/></svg></button><?php endif; ?></div>
                                <div class="eng-word <?php echo sec_h($enWord === '' ? 'empty' : ''); ?>"><?php echo sec_h($enWord !== '' ? $enWord : '—'); ?></div>
                            </div>
                            <div class="eng-col eng-it">
                                <div class="eng-eyebrow"><span class="eng-eyebrow-label"><span class="eng-flag">🇮🇹</span> <?php te('vocab.ins_italiano'); ?></span><?php if ($itWord !== ''): ?><button type="button" class="eng-speak-btn" aria-label="Ascolta in italiano" onclick="event.stopPropagation(); speakWord(this, <?php echo sec_h(json_encode($row['description_it'])); ?>, 'it-IT');"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 5 6 9H2v6h4l5 4z"/><path d="M15.5 8.5a5 5 0 0 1 0 7"/></svg></button><?php endif; ?></div>
                                <div class="eng-word <?php echo sec_h($itWord === '' ? 'empty' : ''); ?>"><?php echo sec_h($itWord !== '' ? $itWord : '—'); ?></div>
                            </div>
                        </div>

                        <?php if (!empty($row['image'])): ?>
                        <div class="eng-media" onclick="event.stopPropagation(); openZoom('<?php echo IMAGE_PATH_VIEW . '/' . sec_h($row['image']); ?>')">
                            <img src="<?php echo IMAGE_PATH_VIEW . '/' . sec_h($row['image']); ?>" alt="" loading="lazy">
                        </div>
                        <?php endif; ?>

                        <?php if (trim($row['meaning']) !== ''): ?>
                        <div class="eng-block">
                            <div class="eng-block-label"><span class="l-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 16v-4M12 8h.01"/></svg></span><?php te('vocab.ins_significato'); ?></div>
                            <div class="eng-meaning-text"><?php echo eng_text($row['meaning']); ?></div>
                        </div>
                        <?php endif; ?>

                        <?php if (trim($row['example']) !== ''): ?>
                        <div class="eng-block">
                            <div class="eng-block-label"><span class="l-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 5h16M4 12h16M4 19h10"/></svg></span><?php te('vocab.ins_esempio'); ?></div>
                            <div class="eng-example-text"><?php echo eng_example_text($row['example'], $row['description_en']); ?></div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php if (!empty($row['children'])): foreach ($row['children'] as $child):
                $cEn = trim($child['description_en']) !== '' ? sec_h($child['description_en']) : '';
                $cIt = trim($child['description_it']) !== '' ? sec_h($child['description_it']) : '';
            ?>
            <div class="eng-card is-child" id="card-child-<?php echo sec_h($child['id_child']); ?>" data-kind="child" data-id="<?php echo sec_h($child['id_child']); ?>"
                 data-learnt="0" data-duplicate="false" data-search="<?php echo eng_search($child); ?>" style="--row-color:<?php echo sec_h($rowColor); ?>;">
                <div class="swipe-actions">
                    <a href="modifica_immagine.php?child_id=<?php echo sec_h($child['id_child']); ?>&previous_catid=<?php echo (int)$row['category_id']; ?>" class="swipe-btn swipe-edit" aria-label="Modifica">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                    </a>
                    <button type="button" class="swipe-btn swipe-delete" aria-label="Elimina" onclick="askDeleteSingle('child', <?php echo sec_h($child['id_child']); ?>)">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0-1 14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2L4 6"/></svg>
                    </button>
                </div>
                <div class="swipe-content" onclick="onCardTap(event, this)">
                    <div class="card-inner">
                        <div class="card-head">
                            <span class="select-circle"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg></span>
                            <span class="child-tag"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M9 4v10a4 4 0 0 0 4 4h7M20 18l-4 4M20 18l-4-4"/></svg><?php te('vocab.vi_collegata'); ?></span>
                            <span class="head-spacer"></span>
                            <button type="button" class="card-kebab" aria-label="Azioni" onclick="event.stopPropagation(); apriAzioniParola('child', <?php echo sec_h($child['id_child']); ?>, 'modifica_immagine.php?child_id=<?php echo sec_h($child['id_child']); ?>&previous_catid=<?php echo (int)$row['category_id']; ?>');">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><circle cx="12" cy="5" r="1.4" fill="currentColor" stroke="none"/><circle cx="12" cy="12" r="1.4" fill="currentColor" stroke="none"/><circle cx="12" cy="19" r="1.4" fill="currentColor" stroke="none"/></svg>
                            </button>
                        </div>
                        <div class="eng-bilingual">
                            <div class="eng-col eng-en">
                                <div class="eng-eyebrow"><span class="eng-eyebrow-label"><span class="eng-flag">🇬🇧</span> <?php te('vocab.ins_english'); ?></span><?php if ($cEn !== ''): ?><button type="button" class="eng-speak-btn" aria-label="Ascolta in inglese" onclick="event.stopPropagation(); speakWord(this, <?php echo sec_h(json_encode($child['description_en'])); ?>, 'en-GB');"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 5 6 9H2v6h4l5 4z"/><path d="M15.5 8.5a5 5 0 0 1 0 7"/></svg></button><?php endif; ?></div>
                                <div class="eng-word <?php echo sec_h($cEn === '' ? 'empty' : ''); ?>"><?php echo sec_h($cEn !== '' ? $cEn : '—'); ?></div>
                            </div>
                            <div class="eng-col eng-it">
                                <div class="eng-eyebrow"><span class="eng-eyebrow-label"><span class="eng-flag">🇮🇹</span> <?php te('vocab.ins_italiano'); ?></span><?php if ($cIt !== ''): ?><button type="button" class="eng-speak-btn" aria-label="Ascolta in italiano" onclick="event.stopPropagation(); speakWord(this, <?php echo sec_h(json_encode($child['description_it'])); ?>, 'it-IT');"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 5 6 9H2v6h4l5 4z"/><path d="M15.5 8.5a5 5 0 0 1 0 7"/></svg></button><?php endif; ?></div>
                                <div class="eng-word <?php echo sec_h($cIt === '' ? 'empty' : ''); ?>"><?php echo sec_h($cIt !== '' ? $cIt : '—'); ?></div>
                            </div>
                        </div>
                        <?php if (!empty($child['image'])): ?>
                        <div class="eng-media" onclick="event.stopPropagation(); openZoom('<?php echo IMAGE_PATH_VIEW . '/' . sec_h($child['image']); ?>')">
                            <img src="<?php echo IMAGE_PATH_VIEW . '/' . sec_h($child['image']); ?>" alt="" loading="lazy">
                        </div>
                        <?php endif; ?>
                        <?php if (trim($child['meaning']) !== ''): ?>
                        <div class="eng-block">
                            <div class="eng-block-label"><span class="l-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 16v-4M12 8h.01"/></svg></span><?php te('vocab.ins_significato'); ?></div>
                            <div class="eng-meaning-text"><?php echo eng_text($child['meaning']); ?></div>
                        </div>
                        <?php endif; ?>
                        <?php if (trim($child['example']) !== ''): ?>
                        <div class="eng-block">
                            <div class="eng-block-label"><span class="l-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 5h16M4 12h16M4 19h10"/></svg></span><?php te('vocab.ins_esempio'); ?></div>
                            <div class="eng-example-text"><?php echo eng_example_text($child['example'], $child['description_en']); ?></div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; endif; ?>
        </div>
    <?php endforeach; endif; ?>
    </div>

    <?php if ($categoryID == 0 && $wordCount > 30): ?>
    <button type="button" id="loadMoreBtn" class="load-more-btn" style="display:none;" onclick="loadMoreCards()"><?php te('vocab.vi_carica_altre'); ?></button>
    <?php endif; ?>

    <div class="empty-state" id="emptyReal" style="<?php echo $wordCount > 0 ? 'display:none;' : ''; ?>">
        <div class="empty-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2Z"/></svg></div>
        <div class="empty-title"><?php te('vocab.vi_nessuna_parola'); ?></div>
        <div class="empty-sub"><?php te('vocab.vi_nessuna_parola_txt'); ?></div>
    </div>

    <div class="empty-state" id="emptyFilter" style="display:none;">
        <div class="empty-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg></div>
        <div class="empty-title"><?php te('vocab.vi_nessun_risultato'); ?></div>
        <div class="empty-sub"><?php te('vocab.vi_prova_cambiare'); ?></div>
    </div>
</div>

<!-- SHEET: Azioni parola (fallback desktop) -->
<div id="sheetWordActions" class="modal-overlay">
    <div class="sheet-panel">
        <h3 class="sheet-title">Azioni</h3>
        <div>
            <a href="#" id="waEditLink" class="sheet-action-item">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>Modifica
            </a>
            <button type="button" class="sheet-action-item danger" id="waDeleteBtn">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0-1 14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2L4 6"/></svg>Elimina
            </button>
        </div>
    </div>
</div>

<!-- SHEET: Sposta -->
<div id="sheetMove" class="modal-overlay">
    <div class="sheet-panel">
        <h3 class="sheet-title"><?php te('vocab.vi_sposta_in'); ?></h3>
        <p class="sheet-sub"><?php te('vocab.vi_scegli_dest'); ?></p>
        <div class="cat-pick-list">
            <?php foreach ($cat_map as $c):
                $col = (!empty($c['color']) && preg_match('/^#[0-9A-Fa-f]{6}$/', $c['color'])) ? $c['color'] : '#8E8E93'; ?>
            <button type="button" class="cat-pick-item" onclick="doMove(<?php echo (int)$c['id']; ?>)">
                <span class="cat-pick-dot" style="background:<?php echo sec_h($col); ?>"></span>
                <span class="cat-pick-name"><?php echo sec_h($c['name']); ?></span>
            </button>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- SHEET: Conferma eliminazione -->
<div id="sheetDelete" class="modal-overlay">
    <div class="sheet-panel">
        <div class="confirm-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0-1 14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2L4 6"/></svg></div>
        <h3 class="sheet-title"><?php te('vocab.vi_eliminare'); ?></h3>
        <p class="confirm-text" id="deleteText"><?php te('vocab.vi_non_annullabile'); ?></p>
        <div class="sheet-actions">
            <button type="button" class="btn btn-cancel" onclick="chiudiModals()"><?php te('comune.annulla'); ?></button>
            <button type="button" class="btn btn-danger" id="deleteConfirmBtn"><?php te('comune.elimina'); ?></button>
        </div>
    </div>
</div>

<!-- ZOOM -->
<div class="zoom-overlay" id="zoomOverlay" onclick="closeZoom()">
    <button type="button" class="zoom-close" onclick="closeZoom()" aria-label="Chiudi"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg></button>
    <img id="zoomImg" src="" alt="">
</div>

<!-- FORM azioni di massa -->
<form id="bulkForm" method="POST" style="display:none;">
    <input type="hidden" name="bulk_action" id="hiddenBulkAction">
    <input type="hidden" name="target_category" id="hiddenTargetCategory">
    <input type="hidden" name="father_id" id="hiddenFatherId">
    <div id="bulkInputs"></div>
</form>

<?php echo nisama_i18n_json(['comune', 'vocab']); ?>
<script>
if (window.matchMedia("(pointer:coarse)").matches) { document.body.classList.add("touch-device"); }
var CURRENT_USER = <?php echo (int)$current_user_id; ?>;
var IS_ANON = CURRENT_USER === 3;

/* ---------- Header height -> CSS var ---------- */
function syncHeaderHeight(){
    var h = document.body.classList.contains('select-active') ? document.getElementById('selectToolbar') : document.getElementById('appHeader');
    if (h) document.documentElement.style.setProperty('--header-h', h.offsetHeight + 'px');
}
window.addEventListener('load', syncHeaderHeight);
window.addEventListener('resize', syncHeaderHeight);

/* ---------- Navigation ---------- */
function changeSort(){ var v=document.getElementById('filterSort').value; var p=new URLSearchParams(window.location.search); p.set('sort', v); window.location.search=p.toString(); }

/* ---------- Sheets ---------- */
function openSheet(el){ el.style.display='flex'; el.classList.remove('closing'); }
function closeSheet(el){ el.classList.add('closing'); setTimeout(function(){ el.style.display='none'; el.classList.remove('closing'); }, 240); }
function chiudiModals(){ document.querySelectorAll('.modal-overlay').forEach(function(ov){ if (ov.style.display==='flex') closeSheet(ov); }); }
document.querySelectorAll('.modal-overlay').forEach(function(ov){ ov.addEventListener('click', function(e){ if (e.target===ov) closeSheet(ov); }); });

/* ---------- Toast + confetti ---------- */
function showToast(text, icon){
    var t=document.createElement('div'); t.className='toast';
    var svg = icon==='trash'
        ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>'
        : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>';
    t.innerHTML=svg+'<span>'+text+'</span>';
    document.body.appendChild(t);
    requestAnimationFrame(function(){ t.classList.add('show'); });
    setTimeout(function(){ t.classList.remove('show'); setTimeout(function(){ t.remove(); }, 320); }, 2100);
}
function miniConfetti(x, y){
    var colors=['#30D158','#34C759','#FFD60A','#0A84FF','#ffffff'];
    for (var i=0;i<12;i++){
        var p=document.createElement('div'); p.className='confetti-piece';
        p.style.background=colors[i%colors.length]; p.style.left=x+'px'; p.style.top=y+'px';
        if (i%2===0) p.style.borderRadius='50%';
        document.body.appendChild(p);
        var ang=Math.random()*Math.PI*2, dist=44+Math.random()*46;
        var dx=Math.cos(ang)*dist, dy=Math.sin(ang)*dist-24;
        p.animate([{transform:'translate(0,0) rotate(0) scale(1)', opacity:1},
                   {transform:'translate('+dx+'px,'+dy+'px) rotate('+(Math.random()*540)+'deg) scale(.3)', opacity:0}],
                  {duration:640+Math.random()*260, easing:'cubic-bezier(.15,.6,.3,1)'}).onfinish=function(){ this.effect.target.remove(); };
    }
}

/* ---------- Image zoom ---------- */
function openZoom(src){ document.getElementById('zoomImg').src=src; document.getElementById('zoomOverlay').style.display='flex'; }
function closeZoom(){ var z=document.getElementById('zoomOverlay'); z.style.display='none'; document.getElementById('zoomImg').src=''; }
document.addEventListener('keydown', function(e){ if (e.key==='Escape'){ closeZoom(); chiudiModals(); } });

/* ---------- Pronuncia (TTS): carica le voci, sceglie la migliore e legge ---------- */
var ttsVoices = [];
function loadTtsVoices(){
    if ('speechSynthesis' in window) { 
        ttsVoices = window.speechSynthesis.getVoices() || []; 
    }
}
loadTtsVoices();
if ('speechSynthesis' in window && window.speechSynthesis.onvoiceschanged !== undefined) { 
    window.speechSynthesis.onvoiceschanged = loadTtsVoices; 
}

function pickTtsVoice(lang){
    if (!ttsVoices.length) loadTtsVoices();
    if (!ttsVoices.length) return null;

    var prefix = lang.split('-')[0].toLowerCase(); // 'en' o 'it'
    
    // 1. Filtriamo in modo rigoroso: SOLO voci della lingua esatta e NIENTE Eloquence
    var validVoices = ttsVoices.filter(function(v){
        if (!v.lang) return false;
        var vLang = v.lang.replace('_', '-').toLowerCase();
        var name = v.name.toLowerCase();
        var uri = (v.voiceURI || '').toLowerCase();
        
        // Deve matchare la lingua richiesta
        if (vLang.indexOf(prefix) !== 0) return false;
        
        // Escludiamo categoricamente Eloquence e i nomi dei sintetizzatori robotici
        if (name.indexOf('eloquence') !== -1 || uri.indexOf('eloquence') !== -1) return false;
        var badNames = ['reed', 'shelley', 'sandy', 'rocko', 'flo', 'eddy', 'grandma', 'grandpa'];
        if (badNames.indexOf(name) !== -1) return false;
        
        return true;
    });

    if (!validVoices.length) return null;

    // 2. Sistema a punti intelligente
    validVoices.sort(function(a, b) {
        var scoreA = 0, scoreB = 0;
        var nA = a.name.toLowerCase();
        var nB = b.name.toLowerCase();
        
        // IL TRUCCO: Il "Default" vale 100 punti per iPhone/Android, ma vale solo 10 su PC (Microsoft)
        if (a.default) { scoreA += (nA.indexOf('microsoft') !== -1) ? 10 : 100; }
        if (b.default) { scoreB += (nB.indexOf('microsoft') !== -1) ? 10 : 100; }
        
        // Punto per la lingua esatta
        var langA = a.lang.replace('_', '-').toLowerCase();
        var langB = b.lang.replace('_', '-').toLowerCase();
        if (langA === lang.toLowerCase()) scoreA += 50;
        if (langB === lang.toLowerCase()) scoreB += 50;
        
        // Bonus per le voci di alta qualità (Google, Premium, Online)
        if (nA.indexOf('google') !== -1) scoreA += 40;
        if (nB.indexOf('google') !== -1) scoreB += 40;
        
        if (nA.indexOf('premium') !== -1 || nA.indexOf('enhanced') !== -1 || nA.indexOf('natural') !== -1 || nA.indexOf('online') !== -1) scoreA += 30;
        if (nB.indexOf('premium') !== -1 || nB.indexOf('enhanced') !== -1 || nB.indexOf('natural') !== -1 || nB.indexOf('online') !== -1) scoreB += 30;
        
        return scoreB - scoreA;
    });

    return validVoices[0]; // La vincitrice
}

function speakWord(btn, text, lang){
    if (!text || !('speechSynthesis' in window)) return;
    try { window.speechSynthesis.cancel(); } catch(e){}
    
    if (!ttsVoices.length) loadTtsVoices();
    var voice = pickTtsVoice(lang);
    
    var u1 = new SpeechSynthesisUtterance(text);
    u1.lang = lang; 
    u1.rate = 1; 
    if (voice) u1.voice = voice;
    
    var u2 = new SpeechSynthesisUtterance(text);
    u2.lang = lang; 
    u2.rate = 0.8; 
    if (voice) u2.voice = voice;
    
    if (btn) btn.classList.add('speaking');
    u2.onend = function(){ if (btn) btn.classList.remove('speaking'); };
    u2.onerror = u2.onend;
    
    window.speechSynthesis.speak(u1);
    window.speechSynthesis.speak(u2);
}

/* ---------- Learnt toggle ---------- */
function toggleLearnt(id, checkbox){
    var status = checkbox.checked ? 1 : 0;
    var card = document.getElementById('card-img-'+id);
    if (status===1 && card){
        var r=checkbox.getBoundingClientRect(); miniConfetti(r.left+r.width/2, r.top+r.height/2);
        if (navigator.vibrate) navigator.vibrate(12);
    }
    if (card){ card.classList.toggle('is-learnt', status===1); card.dataset.learnt = String(status); }
    var xhr=new XMLHttpRequest();
    xhr.open("POST","update_learnt.php",true);
    xhr.setRequestHeader("Content-Type","application/x-www-form-urlencoded");
    xhr.onreadystatechange=function(){ if (this.readyState===4){ updateCounter(); } };
    /* user_id non si manda piu': l'utente lo decide il server dalla sessione */
    xhr.send("id="+id+"&status="+status+"&type=en");
}

/* ---------- Filtri + paginazione (30 alla volta per "Tutte le parole") ---------- */
var visibleLimit = 30;
function loadMoreCards(){
    visibleLimit += 30;
    filterList();
    if (navigator.vibrate) navigator.vibrate(10);
}
function filterList(){
    var status=document.getElementById('filterStatus').value;
    var text=document.getElementById('filterText').value.toLowerCase().trim();
    var groups=document.querySelectorAll('#wordsWrap .word-group');
    var isTutteLeParole = <?php echo sec_h($categoryID == 0 ? 'true' : 'false'); ?>;
    var visible=0, matchedGroups=0;
    groups.forEach(function(g){
        var cards=g.querySelectorAll('.eng-card'); var anyVisible=false;
        var cardResults=[];
        cards.forEach(function(card){
            if (selectMode && card.classList.contains('selected')){ cardResults.push([card,true]); anyVisible=true; return; }
            var isLearnt=card.dataset.learnt==='1';
            var isDup=card.dataset.duplicate==='true';
            var okStatus = status==='all' || (status==='learnt'&&isLearnt) || (status==='not_learnt'&&!isLearnt) || (status==='duplicates'&&isDup);
            var okText = !text || (card.dataset.search||'').indexOf(text)!==-1;
            var ok = okStatus && okText;
            cardResults.push([card, ok]);
            if (ok) anyVisible=true;
        });
        if (!anyVisible){ g.style.display='none'; return; }
        matchedGroups++;
        if (isTutteLeParole && matchedGroups > visibleLimit){ g.style.display='none'; return; }
        g.style.display='';
        cardResults.forEach(function(pair){ pair[0].style.display = pair[1] ? '' : 'none'; if (pair[1]) visible++; });
    });
    var real=document.getElementById('emptyReal'), filt=document.getElementById('emptyFilter');
    if (filt){ filt.style.display = (visible===0 && <?php echo (int)$wordCount; ?> > 0) ? '' : 'none'; }
    var sb=document.getElementById('subcatsBlock'); if (sb) sb.style.display = (selectMode || text || status!=='all') ? 'none' : '';
    var lm=document.getElementById('loadMoreBtn');
    if (lm) lm.style.display = (isTutteLeParole && matchedGroups > visibleLimit) ? 'block' : 'none';
    updateCounter(visible);
}
function updateCounter(forced){
    var el=document.getElementById('countLine'); if (!el) return;
    if (selectMode){ el.style.display='none'; return; }
    el.style.display='';
    var n = (typeof forced==='number') ? forced : document.querySelectorAll('#wordsWrap .eng-card').length;
    <?php if ($wordCount == 0): ?> el.textContent=''; return; <?php endif; ?>
    el.textContent = n + (n===1 ? ' parola' : ' parole');
}

/* ---------- Select mode ---------- */
var selectMode=false, selected={father:new Set(), child:new Set()}, joinMode=false, joinFatherId=null;
function enterSelectMode(){
    selectMode=true; document.body.classList.add('select-active');
    selected.father.clear(); selected.child.clear();
    chiudiTuttiSwipe(); syncHeaderHeight(); refreshSelectUI(); filterList();
}
function exitSelectMode(){
    selectMode=false; joinMode=false; joinFatherId=null;
    document.body.classList.remove('select-active');
    document.querySelectorAll('.eng-card.selected, .eng-card.join-father').forEach(function(c){ c.classList.remove('selected','join-father'); });
    document.getElementById('stHint').style.display='none';
    syncHeaderHeight(); filterList();
}
function onCardTap(e, contentEl){
    if (contentEl.classList.contains('swiped-open')){ chiudiTuttiSwipe(); return; }
    if (document.querySelector('.swipe-content.swiped-open')){ chiudiTuttiSwipe(); return; }
    if (!selectMode) return;
    var card=contentEl.closest('.eng-card');
    if (joinMode){
        if (card.dataset.kind!=='father' || !card.classList.contains('selected')) return;
        document.querySelectorAll('.eng-card.join-father').forEach(function(c){ c.classList.remove('join-father'); });
        card.classList.add('join-father'); joinFatherId=card.dataset.id; return;
    }
    var kind=card.dataset.kind, id=card.dataset.id;
    if (card.classList.contains('selected')){ card.classList.remove('selected'); selected[kind].delete(id); }
    else { card.classList.add('selected'); selected[kind].add(id); }
    refreshSelectUI();
}
function toggleSelectAll(){
    var cards=Array.prototype.filter.call(document.querySelectorAll('#wordsWrap .eng-card'), function(c){ return c.offsetParent!==null; });
    var allSel=cards.every(function(c){ return c.classList.contains('selected'); });
    cards.forEach(function(c){
        var kind=c.dataset.kind, id=c.dataset.id;
        if (allSel){ c.classList.remove('selected'); selected[kind].delete(id); }
        else { c.classList.add('selected'); selected[kind].add(id); }
    });
    refreshSelectUI();
}
function refreshSelectUI(){
    var nf=selected.father.size, nc=selected.child.size, tot=nf+nc;
    document.getElementById('stCount').textContent = T(tot===1?'vocab.vi_selezionata':'vocab.vi_selezionate',{n:tot});
    setDisabled('stMove', nf===0 || nc>0);
    setDisabled('stJoin', nf<2 || nc>0);
    setDisabled('stUnjoin', nc===0 || nf>0);
    setDisabled('stDelete', tot===0);
}
function setDisabled(id, cond){ document.getElementById(id).classList.toggle('disabled', cond); }

/* ---------- Bulk actions ---------- */
function buildInputs(){
    var box=document.getElementById('bulkInputs'); box.innerHTML='';
    selected.father.forEach(function(id){ box.insertAdjacentHTML('beforeend', "<input type='hidden' name='selected_ids[]' value='"+id+"'>"); });
    selected.child.forEach(function(id){ box.insertAdjacentHTML('beforeend', "<input type='hidden' name='selected_child_ids[]' value='"+id+"'>"); });
}
function submitBulk(action){ document.getElementById('hiddenBulkAction').value=action; buildInputs(); document.getElementById('bulkForm').submit(); }
function onBulkAction(action){
    if (document.getElementById('st'+action.charAt(0).toUpperCase()+action.slice(1)).classList.contains('disabled')) return;
    if (action==='move'){ openSheet(document.getElementById('sheetMove')); }
    else if (action==='delete'){
        var tot=selected.father.size+selected.child.size;
        document.getElementById('deleteText').innerHTML='Stai per eliminare <b>'+tot+(tot===1?' parola':' parole')+'</b>. L\'operazione non può essere annullata.';
        document.getElementById('deleteConfirmBtn').onclick=function(){ submitBulk('delete'); };
        openSheet(document.getElementById('sheetDelete'));
    }
    else if (action==='unjoin'){ submitBulk('unjoin'); }
    else if (action==='join'){ startJoinMode(); }
}
function startJoinMode(){
    joinMode=true; joinFatherId=null;
    document.getElementById('stHint').style.display='block';
    var acts=document.getElementById('stActions');
    acts.innerHTML='<button type="button" class="st-btn" onclick="cancelJoin()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg><?php te('comune.annulla'); ?></button>'+
        '<button type="button" class="st-btn confirm" onclick="confirmJoin()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg><?php te('vocab.vi_conferma_unione'); ?></button>';
    syncHeaderHeight();
}
function cancelJoin(){ exitSelectMode(); }
function confirmJoin(){
    if (!joinFatherId){ showToast(T('vocab.vi_scegli_principale')); return; }
    document.getElementById('hiddenFatherId').value=joinFatherId;
    submitBulk('join');
}

/* ---------- Move ---------- */
function doMove(catId){ document.getElementById('hiddenTargetCategory').value=catId; submitBulk('move'); }

/* ---------- Azioni parola (kebab desktop) ---------- */
function apriAzioniParola(kind, id, editUrl){
    document.getElementById('waEditLink').href = editUrl;
    document.getElementById('waDeleteBtn').onclick = function(){ chiudiModals(); setTimeout(function(){ askDeleteSingle(kind, id); }, 260); };
    openSheet(document.getElementById('sheetWordActions'));
}

/* ---------- Single delete (AJAX) ---------- */
var pendingDelete=null;
function askDeleteSingle(kind, id){
    chiudiTuttiSwipe();
    pendingDelete={kind:kind, id:id};
    document.getElementById('deleteText').innerHTML=T('vocab.vi_vuoi_eliminare')+(kind==='child'?T('vocab.vi_parola_collegata'):'parola')+'?';
    document.getElementById('deleteConfirmBtn').onclick=performSingleDelete;
    openSheet(document.getElementById('sheetDelete'));
}
function performSingleDelete(){
    if (!pendingDelete) return;
    var kind=pendingDelete.kind, id=pendingDelete.id; pendingDelete=null;
    chiudiModals();
    var card=document.getElementById(kind==='father' ? 'card-img-'+id : 'card-child-'+id);
    var fd=new FormData(); fd.append('bulk_action','delete');
    fd.append(kind==='father' ? 'selected_ids[]' : 'selected_child_ids[]', id);
    fetch('visualizzazione.php'+window.location.search, {method:'POST', body:fd}).then(function(){
        if (card){
            if (kind==='father'){ var g=card.closest('.word-group'); (g||card).classList.add('card-fade-out'); setTimeout(function(){ (g||card).remove(); afterRemoval(); }, 380); }
            else { card.classList.add('card-fade-out'); setTimeout(function(){ card.remove(); afterRemoval(); }, 380); }
        }
        showToast(T('vocab.vi_eliminata'), 'trash');
    }).catch(function(){ showToast(T('vocab.vi_errore_rete')); });
}
function afterRemoval(){
    updateCounter();
    if (document.querySelectorAll('#wordsWrap .eng-card').length===0){
        var real=document.getElementById('emptyReal'); if (real) real.style.display='';
        var cl=document.getElementById('countLine'); if (cl) cl.textContent='';
    }
}

/* ---------- Swipe (touch) ---------- */
window.chiudiTuttiSwipe=function(){};
(function(){
    if (!window.matchMedia("(hover:none) and (pointer:coarse)").matches) return;
    var active=null, startX=0, startY=0, dragging=false, maxSwipe=0, dirDecided=false, isHorizontal=false;
    var DEADZONE=10, RATIO=2.2; // serve un movimento chiaramente orizzontale (>2.2x quello verticale) prima di attivare lo swipe
    window.chiudiTuttiSwipe=function(){
        document.querySelectorAll('.swipe-content.swiped-open').forEach(function(c){ c.style.transform='translateX(0)'; c.classList.remove('swiped-open'); });
        document.querySelectorAll('.swipe-actions').forEach(function(a){ a.style.visibility='hidden'; });
        active=null;
    };
    document.addEventListener('touchstart', function(e){
        if (selectMode) return;
        if (e.target.closest('.swipe-actions') || e.target.closest('.learnt-wrapper') || e.target.closest('.eng-media')) return;
        var content=e.target.closest('.swipe-content');
        if (!content){ chiudiTuttiSwipe(); return; }
        if (active && active!==content) chiudiTuttiSwipe();
        active=content;
        var actions=content.parentElement.querySelector('.swipe-actions');
        maxSwipe = actions ? actions.offsetWidth : 0;
        startX=e.touches[0].clientX; startY=e.touches[0].clientY; dragging=true; dirDecided=false; isHorizontal=false;
        content.style.transition='none';
    }, {passive:true});
    document.addEventListener('touchmove', function(e){
        if (!dragging || !active) return;
        var dx=e.touches[0].clientX-startX, dy=e.touches[0].clientY-startY;
        if (!dirDecided){
            if (Math.abs(dx) < DEADZONE && Math.abs(dy) < DEADZONE) return;
            dirDecided = true;
            isHorizontal = Math.abs(dx) > Math.abs(dy) * RATIO;
            if (!isHorizontal){ dragging=false; active=null; return; }
            else {
                var acts = active.parentElement.querySelector('.swipe-actions');
                if (acts) acts.style.visibility = 'visible';
            }
        }
        if (!isHorizontal) return;
        if (dx<0){ var tx=Math.max(dx, -(maxSwipe+20)); active.style.transform='translateX('+tx+'px)'; e.preventDefault(); }
        else if (dx>0){ var t2=Math.min(dx,15); active.style.transform='translateX('+t2+'px)'; e.preventDefault(); }
    }, {passive:false});
    document.addEventListener('touchend', function(){
        if (!dragging || !active) return;
        dragging=false; active.style.transition='transform .3s cubic-bezier(.32,.72,0,1)';
        var tx=0, ts=active.style.transform;
        if (ts && ts.indexOf('translateX')!==-1) tx=parseFloat(ts.replace('translateX(',''));
        if (tx < -maxSwipe/2){ active.style.transform='translateX(-'+maxSwipe+'px)'; active.classList.add('swiped-open'); }
        else { active.style.transform='translateX(0)'; active.classList.remove('swiped-open'); active=null; }
    });
})();

/* ---------- Toast da redirect ---------- */
(function(){
    var ok=new URLSearchParams(window.location.search).get('ok');
    var msg={deleted:T('vocab.vi_eliminate'), moved:T('vocab.vi_spostate'), joined:T('vocab.vi_unite'), unjoined:T('vocab.vi_separate')};
    if (ok && msg[ok]){ showToast(msg[ok], ok==='deleted'?'trash':'check'); var p=new URLSearchParams(window.location.search); p.delete('ok'); history.replaceState(null,'', window.location.pathname+(p.toString()?'?'+p.toString():'')); }
})();

window.addEventListener('load', function(){ filterList(); });
</script>
</body>
</html>