<?php

require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/auth_check.php';
require_once __DIR__ . '/fc_examples_box.php'; // render_examples_section(), condivisa con fc_card_edit.php
$current_user_id = (int)$utente_corrente['id'];
/* sec_db() DOPO auth_check: e' quest'ultimo a caricare security_core.php. */
$conn = sec_db();

/* Studio o lingua: con ?course=N questa pagina mostra le parole di un corso
   di lingua, con le stesse funzioni. Vedi fc_type.php. */
require_once __DIR__ . '/../../lib/fc/fc_type.php';
require_once __DIR__ . '/../../lib/fc/fc_icone.php';
fc_avvia((int)$current_user_id);

/* Una carta copiata da una tabella all'altra (unisci, separa): i valori nello
   stesso ordine di fc_colonne_contenuto(). Per le lingue parola e traduzione
   non possono essere NULL nelle carte figlie: un vuoto diventa ''. */
function fc_valori_contenuto(array $row): array {
    $out = [];
    foreach (fc_colonne_contenuto() as $c) {
        $v = $row[$c] ?? null;
        if ($v === null && fc_is_vocab() && ($c === 'word' || $c === 'translation')) $v = '';
        $out[] = $v;
    }
    return $out;
}

/* Filtra un elenco di id di carte tenendo solo quelle di questo utente.
   Prima non c'era: gli id del modulo finivano nelle query senza controllo, e
   cambiando i numeri si cancellavano le flashcard di un altro. */
function fc_solo_mie(array $ids, int $userId): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (empty($ids)) return [];
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $ok = [];
    foreach (sec_all("SELECT i.id FROM " . fc_t('cards') . " i
                      JOIN " . fc_t('decks') . " c ON c.id = i.category_id
                      WHERE i.id IN ($ph) AND c.user_id = ?", array_merge($ids, [$userId])) as $r) {
        $ok[] = (int)$r['id'];
    }
    return $ok;
}

/* Come sopra per le sotto-carte: la proprieta' si verifica passando dal padre. */
function fc_figli_miei(array $ids, int $userId): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (empty($ids)) return [];
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $ok = [];
    foreach (sec_all("SELECT ch.id_child FROM " . fc_t('subcards') . " ch
                      JOIN " . fc_t('cards') . " i ON i.id = ch.parent_id
                      JOIN " . fc_t('decks') . " c ON c.id = i.category_id
                      WHERE ch.id_child IN ($ph) AND c.user_id = ?", array_merge($ids, [$userId])) as $r) {
        $ok[] = (int)$r['id_child'];
    }
    return $ok;
}



// Prima qui si leggeva la lista di TUTTI gli utenti per poterli scegliere da un menu:

// ora l'utente è sempre e solo quello che ha fatto login, niente più menu.

$current_user_id = $utente_corrente['id'];


// ==========================================
// AJAX: collega la categoria corrente a un progetto creando un sotto-progetto
// "Flashcards" sotto al progetto scelto (richiesta: tasto "collega progetto").
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'fc_link_project') {
    header('Content-Type: application/json');
    /* Solo per lo studio: un corso di lingua ha gia' il suo progetto, che
       nasce insieme al corso (fc_languages.php). */
    if (fc_is_vocab()) { echo json_encode(['success' => false, 'error' => t('fc.vis_categoria_non_valida')]); exit(); }
        /* Token di sessione, origine e limite di frequenza. */
    sec_guard_write($current_user_id, 'fc_vis', 0, 120, 60);

    $catId = sec_int($_POST['category_id'] ?? 0, 1);
    $projectId = sec_int($_POST['project_id'] ?? 0, 1);
    // la categoria deve essere dell'utente
    if (!fc_mazzo_mio($catId, $current_user_id)) {
        echo json_encode(['success' => false, 'error' => t('fc.vis_categoria_non_valida')]); exit();
    }
    // e anche il progetto scelto
    $prjRow = sec_one("SELECT id, color, color_edited FROM projects WHERE id = ? AND user_id = ?",
                      [$projectId, $current_user_id]);
    if (!$prjRow) { echo json_encode(['success' => false, 'error' => t('fc.vis_progetto_non_valido')]); exit(); }

    // colore ereditato dal progetto scelto (catena dei padri, come fa project_selector)
    if (!function_exists('fc_resolve_project_color')) {
                function fc_resolve_project_color($parentId, $userId) {
            $parentId = intval($parentId); $guard = 0;
            while ($parentId > 0 && $guard < 50) {
                $guard++;
                $row = sec_one("SELECT parent_id, color, color_edited FROM projects WHERE id = ? AND user_id = ?",
                               [$parentId, (int)$userId]);
                if (!$row) break;
                if (intval($row['color_edited']) === 1 && !empty($row['color'])) return $row['color'];
                $parentId = intval($row['parent_id']);
            }
            return '#8E8E93';
        }
    }
        $newColor = (intval($prjRow['color_edited']) === 1 && !empty($prjRow['color']))
        ? $prjRow['color'] : fc_resolve_project_color($projectId, $current_user_id);

    // Prima di creare, controlla se esiste già un progetto "Flashcards" da riutilizzare:
    // 1) il progetto cliccato è già chiamato "Flashcards" -> lega quello
    // 2) ha un sotto-progetto chiamato "Flashcards" -> lega quello
    // 3) altrimenti crea un nuovo sotto-progetto "Flashcards"
    $targetProjectId = 0;
    $targetName = 'Flashcards';

    $selfName = '';
        $sr = sec_one("SELECT name FROM projects WHERE id = ? AND user_id = ?", [$projectId, $current_user_id]);
    if ($sr) { $selfName = $sr['name']; }

    if (mb_strtolower(trim($selfName)) === 'flashcards') {
        // il progetto scelto è già "Flashcards"
        $targetProjectId = $projectId;
        $targetName = $selfName;
    } else {
        // cerca un sotto-progetto diretto chiamato "Flashcards"
                $cr = sec_one("SELECT id, name FROM projects WHERE user_id = ? AND parent_id = ? AND LOWER(name) = 'flashcards' LIMIT 1",
                      [$current_user_id, $projectId]);
        if ($cr) {
            $targetProjectId = (int)$cr['id'];
            $targetName = $cr['name'];
        }
    }

    if ($targetProjectId === 0) {
        // nessuno esistente: crea il sotto-progetto "Flashcards"
                $nextOrder = (int)(sec_one("SELECT COALESCE(MAX(custom_order), -1) + 1 AS next_order FROM projects WHERE user_id = ? AND parent_id = ?",
                                   [$current_user_id, $projectId])['next_order'] ?? 0);
        sec_q("INSERT INTO projects (user_id, parent_id, name, color, color_edited, status, custom_order, created_at)
               VALUES (?, ?, 'Flashcards', ?, 0, 'in_progress', ?, NOW())",
              [$current_user_id, $projectId, $newColor, $nextOrder]);
        $targetProjectId = sec_insert_id();
        if ($targetProjectId <= 0) { echo json_encode(['success' => false, 'error' => t('fc.vis_creazione_fallita')]); exit(); }
        $created = true;
    } else {
        $created = false;
    }

    // collega il progetto (nuovo o riutilizzato) a questa categoria (evita doppioni)
        sec_q("DELETE FROM fc_study_deck_projects WHERE fc_category_id = ? AND project_id IN (SELECT id FROM projects WHERE user_id = ?)",
          [$catId, $current_user_id]);
    sec_q("INSERT INTO fc_study_deck_projects (project_id, fc_category_id) VALUES (?, ?)", [$targetProjectId, $catId]);

    echo json_encode(['success' => true, 'project_id' => $targetProjectId, 'name' => $targetName, 'color' => $newColor, 'created' => $created]);
    exit();
}




// ==========================================
// AJAX: crea una CARTELLA (categoria figlia) senza uscire dalla vista.
// Il tasto "+" ora chiede prima cosa si vuole aggiungere; se la risposta e'
// "cartella" si finisce qui invece che su fc_decks.php.
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'fc_create_subcat') {
    header('Content-Type: application/json; charset=utf-8');
    /* Token di sessione, origine e limite di frequenza. */
    sec_guard_write($current_user_id, 'fc_vis', 0, 60, 60);

    $nfNome   = sec_str($_POST['name'] ?? '', 120);
    $nfPadre  = sec_int($_POST['parent_id'] ?? 0, 0);
    $nfModo   = sec_enum($_POST['color_mode'] ?? null, ['auto', 'manual'], 'auto');
    $nfColore = sec_color($_POST['color'] ?? null, '');

    if (trim($nfNome) === '') {
        echo json_encode(['success' => false, 'error' => t('fc.vis_nome_mancante')]); exit;
    }
    /* Il padre dev'essere suo: altrimenti si potrebbe appendere una cartella
       propria sotto l'albero di un altro utente. parent_id 0 = radice. */
    if ($nfPadre > 0 && !fc_mazzo_mio($nfPadre, $current_user_id)) {
        echo json_encode(['success' => false, 'error' => t('fc.vis_categoria_non_valida')]); exit;
    }

    if ($nfModo === 'manual' && $nfColore !== '') {
        $nfFinale = $nfColore;
        $nfEdited = 1;
    } else {
        $nfFinale = fc_colore_ereditato($nfPadre, $current_user_id);
        $nfEdited = 0;
    }

    [$xCol, $xPh, $xVal] = fc_mazzo_nuovo_extra();
    sec_q("INSERT INTO " . fc_t('decks') . " (name, parent_id, color, color_edited, user_id$xCol) VALUES (?, ?, ?, ?, ?$xPh)",
          array_merge([$nfNome, $nfPadre, $nfFinale, $nfEdited, $current_user_id], $xVal));
    $nfId = sec_insert_id();
    if ($nfId <= 0) { echo json_encode(['success' => false, 'error' => t('fc.vis_creazione_fallita')]); exit; }

    echo json_encode(['success' => true, 'id' => (int)$nfId, 'name' => $nfNome, 'color' => $nfFinale]);
    exit;
}

/* Colore ereditato: si risale la catena dei padri finche' non si trova chi ha
   un colore scelto a mano (color_edited = 1). E' la stessa regola di
   fc_decks.php; il nome e' diverso apposta, per non azzuffarsi con
   cs_resolve_color() se un domani questa pagina includesse quel file. */
function fc_colore_ereditato($parentId, $userId) {
    $parentId = (int)$parentId;
    $guard = 0;
    while ($parentId > 0 && $guard < 50) {
        $guard++;
        $row = sec_one("SELECT parent_id, color, color_edited FROM " . fc_t('decks') . " WHERE id = ? AND user_id = ?",
                       [$parentId, (int)$userId]);
        if (!$row) break;
        if ((int)$row['color_edited'] === 1 && !empty($row['color'])) return $row['color'];
        $parentId = (int)$row['parent_id'];
    }
    return '#A0A0A0';
}


// ==========================================

// GESTIONE SWAP POSIZIONI (LEGACY - non piu' usata dalla UI, tenuta per compatibilita')

// ==========================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] == 'swap_order') {

    $id = sec_int($_POST['id'] ?? 0, 1);

    $type = $_POST['type']; // 'parent' o 'child'

        $dir = sec_enum($_POST['direction'] ?? null, ['up', 'down'], 'up');
    $padre = ($type == 'parent');

    /* La carta dev'essere sua: prima l'id arrivava dal modulo e finiva nella
       UPDATE senza nessun controllo. */
    $mie = $padre ? fc_solo_mie([$id], $current_user_id) : fc_figli_miei([$id], $current_user_id);
    $current = !empty($mie)
        ? ($padre ? sec_one("SELECT * FROM " . fc_t('cards') . " WHERE id = ?", [$id])
                  : sec_one("SELECT * FROM " . fc_t('subcards') . " WHERE id_child = ?", [$id]))
        : null;

    if ($current) {
        $current_order = (int)$current['custom_order'];
        $scope = $padre ? (int)$current['category_id'] : (int)$current['parent_id'];
        /* Quattro forme scritte per esteso invece di comporre "$table" e
           "$where": i nomi di tabella non si legano a un segnaposto, quindi
           meno se ne scrivono a runtime meglio e'. */
        if ($padre) {
            $adjacent = ($dir == 'up')
                ? sec_one("SELECT id, custom_order FROM " . fc_t('cards') . " WHERE category_id = ? AND custom_order < ? ORDER BY custom_order DESC LIMIT 1", [$scope, $current_order])
                : sec_one("SELECT id, custom_order FROM " . fc_t('cards') . " WHERE category_id = ? AND custom_order > ? ORDER BY custom_order ASC LIMIT 1", [$scope, $current_order]);
        } else {
            $adjacent = ($dir == 'up')
                ? sec_one("SELECT id_child AS id, custom_order FROM " . fc_t('subcards') . " WHERE parent_id = ? AND custom_order < ? ORDER BY custom_order DESC LIMIT 1", [$scope, $current_order])
                : sec_one("SELECT id_child AS id, custom_order FROM " . fc_t('subcards') . " WHERE parent_id = ? AND custom_order > ? ORDER BY custom_order ASC LIMIT 1", [$scope, $current_order]);
        }
        if ($adjacent) {
            $adj_id = (int)$adjacent['id'];
            $adj_order = (int)$adjacent['custom_order'];
            if ($padre) {
                sec_q("UPDATE " . fc_t('cards') . " SET custom_order = ? WHERE id = ?", [$adj_order, $id]);
                sec_q("UPDATE " . fc_t('cards') . " SET custom_order = ? WHERE id = ?", [$current_order, $adj_id]);
            } else {
                sec_q("UPDATE " . fc_t('subcards') . " SET custom_order = ? WHERE id_child = ?", [$adj_order, $id]);
                sec_q("UPDATE " . fc_t('subcards') . " SET custom_order = ? WHERE id_child = ?", [$current_order, $adj_id]);
            }
        }
    }

    exit;

}



// ==========================================

// NUOVO: RIORDINO CONTINUO (drag & drop) — riscrive l'ordine di un intero gruppo

// ==========================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] == 'reorder_batch') {

    header('Content-Type: application/json');

        sec_guard_write($current_user_id, 'fc_vis', 0, 300, 60);

    $type = sec_enum($_POST['type'] ?? null, ['parent', 'child'], '');
    $scope_id = sec_int($_POST['scope_id'] ?? -1, 0, PHP_INT_MAX, -1);
    $ordered_ids = (isset($_POST['ordered_ids']) && is_array($_POST['ordered_ids'])) ? $_POST['ordered_ids'] : [];
    $ok = false;

    if (count($ordered_ids) > 0 && $scope_id >= 0 && $type !== '') {
        /* Solo le carte davvero sue: prima gli id del modulo finivano nella
           UPDATE senza controllo. Il filtro qui e' la difesa; la clausola sullo
           scope resta come seconda rete. */
        $miei = ($type === 'parent')
            ? fc_solo_mie($ordered_ids, $current_user_id)
            : fc_figli_miei($ordered_ids, $current_user_id);
        /* si conserva l'ordine scelto dall'utente, non quello del database */
        $ordine = array_values(array_filter(array_map('intval', $ordered_ids),
                                            function ($x) use ($miei) { return in_array($x, $miei, true); }));
        $pos = 0;
        foreach ($ordine as $oid) {
            if ($type === 'parent') {
                sec_q("UPDATE " . fc_t('cards') . " SET custom_order = ? WHERE id = ? AND category_id = ?", [$pos, $oid, $scope_id]);
            } else {
                sec_q("UPDATE " . fc_t('subcards') . " SET custom_order = ? WHERE id_child = ? AND parent_id = ?", [$pos, $oid, $scope_id]);
            }
            $pos++;
        }
        $ok = true;
    }

    echo json_encode(['success' => $ok]);

    exit;

}



// ==========================================

// GESTIONE DELLE AZIONI DI MASSA E JOIN/UNJOIN
//
// NOTA: la chiave primaria di fc_study_subcards e' id_child, non id. Diverse
// query di questa pagina usavano "WHERE id = ?": non trovavano mai niente e
// uscivano in silenzio, senza errore. Da qui lo "split" (unjoin) che non
// faceva nulla, e con lui il riordino dei figli, il loro ordine
// personalizzato e la loro eliminazione di gruppo. Tutte le altre query del
// progetto usavano gia' id_child.

// ==========================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {

        sec_guard_write($current_user_id, 'fc_vis', 0, 120, 60);

    $action = sec_enum($_POST['bulk_action'] ?? null, ['delete', 'move', 'join', 'unjoin'], '');
    $cat_param = isset($_GET['category_id'])
        ? "?category_id=" . sec_int($_GET['category_id'], 0)
        : "?";

    if ($action === 'delete') {
        $miei = fc_solo_mie($_POST['selected_ids'] ?? [], $current_user_id);
        if (!empty($miei)) {
            $ph = implode(',', array_fill(0, count($miei), '?'));
            sec_q("DELETE FROM " . fc_t('cards') . " WHERE id IN ($ph)", $miei);
        }
        $figli = fc_figli_miei($_POST['selected_child_ids'] ?? [], $current_user_id);
        if (!empty($figli)) {
            $ph = implode(',', array_fill(0, count($figli), '?'));
            sec_q("DELETE FROM " . fc_t('subcards') . " WHERE id_child IN ($ph)", $figli);
        }
    }
    elseif ($action === 'move' && isset($_POST['target_category'])) {
        $target_cat = sec_int($_POST['target_category'], 1);
        $miei = fc_solo_mie($_POST['selected_ids'] ?? [], $current_user_id);
        /* anche la categoria di destinazione dev'essere sua */
        if (!empty($miei) && fc_mazzo_mio($target_cat, $current_user_id)) {
            $ph = implode(',', array_fill(0, count($miei), '?'));
            sec_q("UPDATE " . fc_t('cards') . " SET category_id = ? WHERE id IN ($ph)", array_merge([$target_cat], $miei));
        }
    } 
        elseif ($action === 'join' && isset($_POST['father_id'])) {
        $nati = [];
        $father_id = sec_int($_POST['father_id'], 1);
        $miei = fc_solo_mie($_POST['selected_ids'] ?? [], $current_user_id);
        /* anche il padre dev'essere una carta sua */
        if (!empty($miei) && fc_solo_mie([$father_id], $current_user_id)) {
            $c_order = (int)(sec_one("SELECT MAX(custom_order) AS m FROM " . fc_t('subcards') . " WHERE parent_id = ?",
                                     [$father_id])['m'] ?? 0) + 1;
            foreach ($miei as $child_id) {
                if ($child_id === $father_id) continue;
                $row = sec_one("SELECT * FROM " . fc_t('cards') . " WHERE id = ?", [$child_id]);
                if (!$row) continue;
                $cols = fc_colonne_contenuto();
                sec_q("INSERT INTO " . fc_t('subcards') . " (parent_id, " . implode(', ', $cols) . ", custom_order)
                       VALUES (?, " . implode(', ', array_fill(0, count($cols), '?')) . ", ?)",
                      array_merge([$father_id], fc_valori_contenuto($row), [$c_order]));
                $nuovo = (int)sec_insert_id();
                $nati[] = $nuovo;
                /* Gli esempi seguono la carta. Prima la riga vecchia si
                   cancellava e i suoi esempi se ne andavano con lei, per
                   cascata: unire due carte voleva dire perderne gli esempi. */
                if ($nuovo > 0) {
                    sec_q("UPDATE " . fc_t('examples') . " SET parent_id = NULL, child_id = ? WHERE parent_id = ?", [$nuovo, $child_id]);
                }
                sec_q("DELETE FROM " . fc_t('cards') . " WHERE id = ?", [$child_id]);
                $c_order++;
            }
            sec_q("UPDATE " . fc_t('cards') . " SET is_father = 1 WHERE id = ?", [$father_id]);
        }
    } 
    elseif ($action === 'unjoin') {
        /* Gli id delle carte NUOVE. Separare non e' spostare: la riga della
           sottocarta viene cancellata e ne nasce una in fc_study_cards, con un id
           nuovo. La pagina deve sapere quali sono, altrimenti dopo l'operazione
           non ha modo di riconoscerle — e il filtro della modalita' Separa
           (che tiene solo padri e figli) le fa sparire proprio nell'istante in
           cui si vuole vedere il risultato. */
        $nati = [];
        $posto = [];
        $parents_to_check = [];
        foreach (fc_figli_miei($_POST['selected_child_ids'] ?? [], $current_user_id) as $child_id) {
            $row = sec_one("SELECT * FROM " . fc_t('subcards') . " WHERE id_child = ?", [$child_id]);
            if (!$row) continue;
            $parent_id = (int)$row['parent_id'];
            $parents_to_check[$parent_id] = true;

            /* DOVE VA A FINIRE UNA CARTA SEPARATA
               In fondo alla categoria, prima: il posto piu' lontano possibile da
               dove stava. Ma una sottocarta separata resta la stessa carta sullo
               stesso argomento, e ritrovarsela in coda a duecento altre vuol
               dire doverla riportare a mano dov'era.
               Adesso si posa subito sotto il padre da cui e' uscita. Per farle
               spazio si spinge in giu' di uno tutto quello che veniva dopo:
               nell'ordine non ci sono buchi da cui pescare, il posto va creato.
               $posto tiene il conto per ogni padre: separandone tre in un colpo
               solo, la seconda va sotto la prima e la terza sotto la seconda,
               invece di infilarsi tutte nello stesso punto e uscirne in ordine
               rovesciato. */
            $p_row = sec_one("SELECT category_id, custom_order FROM " . fc_t('cards') . " WHERE id = ?", [$parent_id]);
            $cat_id = $p_row ? (int)$p_row['category_id'] : 0;
            if (!isset($posto[$parent_id])) $posto[$parent_id] = (int)($p_row['custom_order'] ?? 0);
            sec_q("UPDATE " . fc_t('cards') . " SET custom_order = custom_order + 1
                    WHERE category_id = ? AND custom_order > ?", [$cat_id, $posto[$parent_id]]);
            $p_order = ++$posto[$parent_id];

            $cols = fc_colonne_contenuto();
            sec_q("INSERT INTO " . fc_t('cards') . " (" . implode(', ', $cols) . ", category_id, is_father, custom_order)
                   VALUES (" . implode(', ', array_fill(0, count($cols), '?')) . ", ?, 0, ?)",
                  array_merge(fc_valori_contenuto($row), [$cat_id, $p_order]));
            /* sec_insert_id() e non "SELECT LAST_INSERT_ID()": quello e' un
               valore della CONNESSIONE, e chiederlo con una query a parte vuol
               dire sperare che sia la stessa connessione dell'INSERT. Era
               questo il motivo per cui la carta separata continuava a sparire:
               l'id tornava vuoto, la pagina non la riconosceva fra le altre e
               il filtro se la mangiava. */
            $nuovo = (int)sec_insert_id();
            $nati[] = $nuovo;
            /* gli esempi seguono la carta anche nel verso opposto */
            if ($nuovo > 0) {
                sec_q("UPDATE " . fc_t('examples') . " SET child_id = NULL, parent_id = ? WHERE child_id = ?", [$nuovo, $child_id]);
            }
            sec_q("DELETE FROM " . fc_t('subcards') . " WHERE id_child = ?", [$child_id]);
        }
        foreach (array_keys($parents_to_check) as $pid) {
            $n = (int)(sec_one("SELECT COUNT(*) AS c FROM " . fc_t('subcards') . " WHERE parent_id = ?", [$pid])['c'] ?? 0);
            if ($n === 0) sec_q("UPDATE " . fc_t('cards') . " SET is_father = 0 WHERE id = ?", [$pid]);
        }
    }

    /* Chiamata dalla pagina senza ricaricarla (bulk_ajax=1): si risponde con
       due righe di JSON invece di un redirect. L'AZIONE E' LA STESSA — tutto
       il codice qui sopra e' condiviso — cambia solo come si risponde. Se
       invece la richiesta arriva da un invio normale del modulo (nessun
       JavaScript, o un percorso che non ho previsto) il redirect resta dov'era
       e la pagina si ricarica come ha sempre fatto: e' la rete di sicurezza,
       non un ramo morto. */
    if (!empty($_POST['bulk_ajax'])) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => true,
            /* Le carte nate dall'operazione, e per un'unione anche il padre in
               cui sono finite: sono le uniche che la pagina deve andare a
               ripescare e tenere a schermo, invece di lasciarle sparire dietro
               il filtro della modalita'. */
            'nati'  => array_values(array_filter(array_unique($nati ?? []))),
            'padre' => isset($father_id) ? (int)$father_id : 0,
        ]);
        exit;
    }

    header('Location: ' . fc_url('fc_view.php', isset($_GET['category_id']) ? ['category_id' => sec_int($_GET['category_id'], 0)] : []));

    exit;

}



$categoryID = isset($_GET['category_id']) ? (int)$_GET['category_id'] : 0;



[$fcW, $fcP] = fc_filtro_corso();
$cat_query = sec_all("SELECT id, name, color, color_edited, parent_id, icon, notes, exam_date FROM " . fc_t('decks') . " WHERE user_id = ?" . $fcW . " ORDER BY name", array_merge([$current_user_id], $fcP));
$categories_list = [];

$current_cat_name = t('fc.vis_tutte_categorie');

$current_cat_color = '#5E5CE6'; // Colore neutro di default (vista "tutte le categorie")
$current_cat_notes = '';
$current_cat_icon = '';



foreach ($cat_query as $c) { 

    $categories_list[] = $c; 

    if ($c['id'] == $categoryID) {

        $current_cat_name = $c['name'];

        $current_cat_color = !empty($c['color']) ? $c['color'] : '#007AFF';
        $current_cat_notes = trim((string)($c['notes'] ?? ''));
        $current_cat_icon = fc_icona_mazzo_di($c);

    }

}

// ==========================================
// PERCORSO (breadcrumb) DEGLI ANTENATI DELLA CATEGORIA CORRENTE.
// Ordine per la visualizzazione (sinistra -> destra): radice ... padre.
// La 'home' e' rappresentata dalla freccetta a sinistra.
// ==========================================
$fc_cat_by_id = [];
foreach ($categories_list as $__c) { $fc_cat_by_id[(int)$__c['id']] = $__c; }
$fc_ancestors = []; // ordine: padre, nonno, bisnonno, ... (immediato per primo)
if ($categoryID != 0 && isset($fc_cat_by_id[$categoryID])) {
    $__cur = (int)($fc_cat_by_id[$categoryID]['parent_id'] ?? 0);
    $__guard = 0;
    while ($__cur > 0 && isset($fc_cat_by_id[$__cur]) && $__guard < 60) {
        $fc_ancestors[] = $fc_cat_by_id[$__cur];
        $__cur = (int)($fc_cat_by_id[$__cur]['parent_id'] ?? 0);
        $__guard++;
    }
}
// per la UI: radice a sinistra, padre a destra
$fc_path_ltr = array_reverse($fc_ancestors);

/* Il padre diretto, che serve al selettore aperto dalla matitina nel percorso:
   e' li' che deve trovarsi srotolato quando si apre. 0 = questa materia sta in
   cima all'albero. */
$fc_parent_id = ($categoryID != 0 && isset($fc_cat_by_id[$categoryID]))
    ? (int)($fc_cat_by_id[$categoryID]['parent_id'] ?? 0) : 0;

/* I discendenti di questa materia: nel selettore non devono essere
   scegliibili. Spostare una materia dentro una sua sottocategoria staccherebbe
   il ramo dall'albero — resterebbe agganciato solo a se stesso, invisibile da
   ogni percorso e irraggiungibile. Il server controlla che le due materie
   siano dell'utente, non che il giro non si chiuda: qui glielo si impedisce
   prima di chiederglielo. */
$fc_discendenti = [];
if ($categoryID != 0) {
    $__figli_di = [];
    foreach ($categories_list as $__c) { $__figli_di[(int)$__c['parent_id']][] = (int)$__c['id']; }
    $__coda = [$categoryID];
    $__giri = 0;
    while ($__coda && $__giri < 5000) {
        $__n = array_pop($__coda);
        foreach ($__figli_di[$__n] ?? [] as $__f) {
            if (isset($fc_discendenti[$__f])) continue;   // ciclo gia' esistente: non ci si gira dentro
            $fc_discendenti[$__f] = true;
            $__coda[] = $__f;
        }
        $__giri++;
    }
}

/* Serve al popup del colore: qual e' il colore che verrebbe ereditato dal
   padre (la prima opzione, "colore di default") e se quello attuale e' invece
   stato scelto a mano. */
$fc_color_edited = 0;
$fc_parent_color = '#A0A0A0';
if ($categoryID != 0 && isset($fc_cat_by_id[$categoryID])) {
    $fc_color_edited = (int)($fc_cat_by_id[$categoryID]['color_edited'] ?? 0);
    $fc_parent_color = fc_colore_ereditato((int)($fc_cat_by_id[$categoryID]['parent_id'] ?? 0), $current_user_id);
}

// ==========================================
// AJAX: RICERCA DENTRO LE CARTELLE SOTTO LA CATEGORIA CORRENTE.
// La pagina stampa in memoria solo le carte di QUESTA categoria: per cercare
// anche in quelle sotto, senza questa passata a parte bisognerebbe caricare
// tutto l'albero a ogni apertura, anche quando non si sta cercando niente.
// Torna HTML gia' pronto invece di dati grezzi perche' cosi' le carte trovate
// escono dalle stesse render_qa_side()/render_examples_section() dell'elenco
// normale: una sola descrizione della scheda, non due destinate a divergere.
// ==========================================
if (isset($_GET['fc_deep_search'])) {
    header('Content-Type: application/json; charset=utf-8');

    $fcTerm = trim((string)sec_str($_GET['q'] ?? '', 80));
    if ($fcTerm === '' || mb_strlen($fcTerm) < 2 || $categoryID == 0) {
        echo json_encode(['success' => true, 'html' => '', 'total' => 0]); exit;
    }

    /* L'albero e' gia' in memoria in $categories_list, che nasce da una query
       filtrata sull'utente: la visita in ampiezza da' insieme i discendenti e
       il percorso di ognuno a partire dalla categoria che si sta guardando. */
    $fcFigliDi = []; $fcNomeDi = []; $fcColoreDi = [];
    foreach ($categories_list as $__c) {
        $fcFigliDi[(int)($__c['parent_id'] ?? 0)][] = (int)$__c['id'];
        $fcNomeDi[(int)$__c['id']] = (string)$__c['name'];
        $fcColoreDi[(int)$__c['id']] = fc_color_or_default($__c['color']);
    }
    $fcPercorso = []; $fcOrdine = []; $fcCoda = [];
    foreach (($fcFigliDi[$categoryID] ?? []) as $__f) { $fcCoda[] = [$__f, [$fcNomeDi[$__f] ?? '']]; }
    while (!empty($fcCoda) && count($fcOrdine) < 400) {
        $__nodo = array_shift($fcCoda);
        $fcPercorso[$__nodo[0]] = $__nodo[1];
        $fcOrdine[] = $__nodo[0];
        foreach (($fcFigliDi[$__nodo[0]] ?? []) as $__f) {
            $fcCoda[] = [$__f, array_merge($__nodo[1], [$fcNomeDi[$__f] ?? ''])];
        }
    }
    if (empty($fcOrdine)) { echo json_encode(['success' => true, 'html' => '', 'total' => 0]); exit; }

    /* I jolly di LIKE vanno neutralizzati: cercando "_" o "%" si otterrebbe
       altrimenti l'intero archivio invece delle carte che contengono quel
       carattere. */
    $fcLike = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $fcTerm) . '%';
    $fcPh = implode(',', array_fill(0, count($fcOrdine), '?'));

    $fcPadri = sec_all(
        "SELECT " . fc_colonne_carta('i') . ", c.name AS category_name, c.color AS category_color,
                (CASE WHEN ul.fc_image_id IS NOT NULL THEN 1 ELSE 0 END) AS is_learnt
         FROM " . fc_t('cards') . " i
         JOIN " . fc_t('decks') . " c ON c.id = i.category_id AND c.user_id = ?
         LEFT JOIN " . fc_t('learnt') . " ul ON ul.fc_image_id = i.id AND ul.user_id = ?
         WHERE i.category_id IN ($fcPh) AND (i." . fc_col_fronte() . " LIKE ? OR i." . fc_col_retro() . " LIKE ?)
         ORDER BY i.custom_order ASC, i.id ASC
         LIMIT 300",
        array_merge([$current_user_id, $current_user_id], $fcOrdine, [$fcLike, $fcLike]));

    $fcFigli = sec_all(
        "SELECT " . fc_colonne_figlia('ch') . ", ch.id_child AS id, i.category_id AS category_id
         FROM " . fc_t('subcards') . " ch
         JOIN " . fc_t('cards') . " i ON i.id = ch.parent_id
         JOIN " . fc_t('decks') . " c ON c.id = i.category_id AND c.user_id = ?
         WHERE i.category_id IN ($fcPh) AND (ch." . fc_col_fronte() . " LIKE ? OR ch." . fc_col_retro() . " LIKE ?)
         ORDER BY ch.custom_order ASC, ch.id_child ASC
         LIMIT 300",
        array_merge([$current_user_id], $fcOrdine, [$fcLike, $fcLike]));

    if (empty($fcPadri) && empty($fcFigli)) {
        echo json_encode(['success' => true, 'html' => '', 'total' => 0]); exit;
    }

    /* Conteggio esempi con le stesse due colonne separate dell'elenco normale
       (chiave negativa = figlio, convenzione di render_examples_section). */
    $fcEsempi = [];
    $__idPadri = array_map(function ($r) { return (int)$r['id']; }, $fcPadri);
    $__idFigli = array_map(function ($r) { return (int)$r['id']; }, $fcFigli);
    if (!empty($__idPadri)) {
        $__ph = implode(',', array_fill(0, count($__idPadri), '?'));
        foreach (sec_all("SELECT parent_id, COUNT(*) AS c FROM " . fc_t('examples') . " WHERE parent_id IN ($__ph) GROUP BY parent_id", $__idPadri) as $__ex) {
            $fcEsempi[(int)$__ex['parent_id']] = (int)$__ex['c'];
        }
    }
    if (!empty($__idFigli)) {
        $__ph = implode(',', array_fill(0, count($__idFigli), '?'));
        foreach (sec_all("SELECT child_id, COUNT(*) AS c FROM " . fc_t('examples') . " WHERE child_id IN ($__ph) GROUP BY child_id", $__idFigli) as $__ex) {
            $fcEsempi[-1 * (int)$__ex['child_id']] = (int)$__ex['c'];
        }
    }

    $fcGruppi = [];
    foreach ($fcPadri as $__r) { $fcGruppi[(int)$__r['category_id']]['padri'][] = $__r; }
    foreach ($fcFigli as $__r) { $fcGruppi[(int)$__r['category_id']]['figli'][] = $__r; }

    $fcTot = 0;
    ob_start();
    /* L'ordine e' quello della visita in ampiezza: prima le cartelle piu'
       vicine alla categoria aperta, poi quelle piu' in fondo. */
    foreach ($fcOrdine as $__cid) {
        if (!isset($fcGruppi[$__cid])) continue;
        $__padri = $fcGruppi[$__cid]['padri'] ?? [];
        $__figli = $fcGruppi[$__cid]['figli'] ?? [];
        $__n = count($__padri) + count($__figli);
        $fcTot += $__n;
        $__col = $fcColoreDi[$__cid] ?? '#007AFF';
?>
<section class="fc-deep-group" data-cat-id="<?= (int)$__cid ?>" style="--row-color:<?= sec_h($__col) ?>;">
    <div class="fc-deep-head">
        <button type="button" class="fdh-main" onclick="fcDeepToggle(this)" aria-expanded="true">
            <span class="fdh-chev" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg></span>
            <span class="fdh-path">
                <?php foreach (($fcPercorso[$__cid] ?? []) as $__i => $__seg): ?>
                    <?php if ($__i > 0): ?><span class="fdh-sep" aria-hidden="true">&rsaquo;</span><?php endif; ?><span class="fdh-seg"><?= sec_h($__seg) ?></span>
                <?php endforeach; ?>
            </span>
            <span class="fdh-count"><?= (int)$__n ?></span>
        </button>
        <a class="fdh-go" href="<?= sec_h(fc_url('fc_view.php', ['category_id' => (int)$__cid])) ?>" aria-label="<?php te('fc.vis_apri_cartella'); ?>" title="<?php te('fc.vis_apri_cartella'); ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>
        </a>
    </div>
    <div class="fc-deep-body">
        <?php foreach ($__padri as $__row):
            $__learnt = ((int)$__row['is_learnt'] === 1);
            $__search = sec_h(strtolower($__row['question_desc'] . ' ' . $__row['answer_desc'] . ' ' . ($__row['meaning'] ?? '') . ' ' . ($__row['notes'] ?? '')));
        ?>
        <div class="fc-group fc-deep-item" data-parent-id="<?= (int)$__row['id'] ?>" data-category-id="<?= (int)$__cid ?>" data-reorderable="0" style="--row-color:<?= sec_h($__col) ?>;">
            <div class="fc-card fc-parent<?= $__learnt ? ' is-learnt' : '' ?>"
                 data-id="<?= (int)$__row['id'] ?>" data-type="parent"
                 data-search="<?= $__search ?>" data-duplicate="false" data-reorderable="0"
                 data-edit-url="<?= sec_h(fc_url('fc_card_edit.php', ['fc_id' => (int)$__row['id'], 'previous_catid' => (int)$__cid])) ?>"
                 data-child-count="0">
                <div class="swipe-actions">
                    <button type="button" class="swipe-act act-edit" onclick="goEditCard(this)">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>
                        <?php te('comune.modifica'); ?>
                    </button>
                    <button type="button" class="swipe-act act-delete" onclick="askDeleteCard(this)">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0-1 14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2L4 6"/></svg>
                        <?php te('comune.elimina'); ?>
                    </button>
                </div>
                <div class="swipe-content">
                    <div class="card-head">
                        <?php if ($current_user_id == 3): ?>
                            <div class="learnt-disabled">&ndash;</div>
                        <?php else: ?>
                            <label class="learnt-wrapper">
                                <input type="checkbox" class="learnt-cb" <?= $__learnt ? 'checked' : '' ?> onclick="toggleLearnt(<?= (int)$__row['id'] ?>, this)">
                                <div class="learnt-emoji">🧐</div>
                                <div class="learnt-check"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></div>
                            </label>
                        <?php endif; ?>
                        <div class="card-head-spacer"></div>
                    </div>
                    <?php fc_lati_carta($__row); ?>
                    <?php render_examples_section($__row['id'], 'parent', $__row['id'], $fcEsempi); ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>

        <?php foreach ($__figli as $__row):
            $__search = sec_h(strtolower($__row['question_desc'] . ' ' . $__row['answer_desc'] . ' ' . ($__row['meaning'] ?? '') . ' ' . ($__row['notes'] ?? '')));
        ?>
        <div class="fc-group fc-deep-item" data-reorderable="0" style="--row-color:<?= sec_h($__col) ?>;">
            <div class="fc-children">
                <div class="fc-card fc-child" data-id="<?= (int)$__row['id'] ?>" data-type="child"
                     data-parent-id="<?= (int)$__row['parent_id'] ?>" data-search="<?= $__search ?>"
                     data-duplicate="false" data-reorderable="0"
                     data-edit-url="<?= sec_h(fc_url('fc_card_edit.php', ['child_id' => (int)$__row['id'], 'previous_catid' => (int)$__cid])) ?>">
                    <div class="swipe-actions">
                        <button type="button" class="swipe-act act-edit" onclick="goEditCard(this)">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>
                            <?php te('vocab.cat_modifica_attr'); ?>
                        </button>
                        <button type="button" class="swipe-act act-delete" onclick="askDeleteCard(this)">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0-1 14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2L4 6"/></svg>
                            <?php te('comune.elimina'); ?>
                        </button>
                    </div>
                    <div class="swipe-content">
                        <div class="card-head">
                            <div class="child-tag"><span>&#8627;</span> <?php te('fc.vis_sotto_carta'); ?></div>
                            <div class="card-head-spacer"></div>
                        </div>
                        <?php fc_lati_carta($__row); ?>
                        <?php render_examples_section($__row['id'], 'child', -1 * (int)$__row['id'], $fcEsempi); ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</section>
<?php
    }
    $fcHtml = ob_get_clean();
    echo json_encode(['success' => true, 'html' => $fcHtml, 'total' => $fcTot]);
    exit;
}





// ==========================================
// AJAX: RIEPILOGO DI UN PROGETTO.
// La pastiglia del progetto non porta piu' dritto a progetti.php: prima si
// vede un riassunto, e da li' si decide se andarci. Tutti i numeri sono
// calcolati sul progetto E sui suoi discendenti, come fa progetti.php: un
// progetto padre senza tempo proprio ma con figli pieni non deve sembrare
// vuoto.
// ==========================================
if (isset($_GET['fc_project_summary'])) {
    header('Content-Type: application/json; charset=utf-8');
    $prjId = sec_int($_GET['project_id'] ?? 0, 1);
    if (!$prjId || !sec_owns('projects', $prjId, $current_user_id)) {
        echo json_encode(['success' => false, 'error' => t('fc.vis_progetto_non_valido')]); exit;
    }

    /* Un solo giro sull'albero dei progetti: da qui escono antenati,
       discendenti e figli diretti senza altre query. */
    $prjTutti = []; $prjFigliDi = [];
    foreach (sec_all("SELECT id, parent_id, name, notes, status, color, color_edited, is_favorite,
                             target_date, due_date, created_at
                      FROM projects WHERE user_id = ? ORDER BY custom_order ASC, id ASC",
                     [$current_user_id]) as $__p) {
        $prjTutti[(int)$__p['id']] = $__p;
        $prjFigliDi[(int)($__p['parent_id'] ?? 0)][] = (int)$__p['id'];
    }
    if (!isset($prjTutti[$prjId])) {
        echo json_encode(['success' => false, 'error' => t('fc.vis_progetto_non_valido')]); exit;
    }
    $prj = $prjTutti[$prjId];

    // antenati, dal piu' lontano al padre
    $prjAntenati = [];
    $__cur = (int)($prj['parent_id'] ?? 0); $__g = 0;
    while ($__cur > 0 && isset($prjTutti[$__cur]) && $__g < 60) {
        array_unshift($prjAntenati, $prjTutti[$__cur]['name']);
        $__cur = (int)($prjTutti[$__cur]['parent_id'] ?? 0);
        $__g++;
    }

    // se stesso + tutti i discendenti
    $prjRamo = []; $__stack = [$prjId];
    while (!empty($__stack) && count($prjRamo) < 500) {
        $__n = array_pop($__stack);
        $prjRamo[] = $__n;
        foreach (($prjFigliDi[$__n] ?? []) as $__c) { $__stack[] = $__c; }
    }
    $prjPh = implode(',', array_fill(0, count($prjRamo), '?'));

    // figli diretti: quanti sono e quanti chiusi
    $prjFigli = $prjFigliDi[$prjId] ?? [];
    $prjFigliChiusi = 0;
    $prjFigliElenco = [];
    foreach ($prjFigli as $__c) {
        $__chiuso = (($prjTutti[$__c]['status'] ?? '') === 'completed');
        if ($__chiuso) $prjFigliChiusi++;
        if (count($prjFigliElenco) < 6) {
            $prjFigliElenco[] = [
                'id' => $__c,
                'name' => $prjTutti[$__c]['name'],
                'done' => $__chiuso,
                'color' => (preg_match('/^#[0-9A-Fa-f]{6}$/', (string)$prjTutti[$__c]['color']))
                           ? $prjTutti[$__c]['color'] : '#8E8E93',
            ];
        }
    }

    // pomodoro: tempo e sessioni su tutto il ramo, piu' la settimana appena passata
    $__pom = sec_one("SELECT COUNT(*) AS n, COALESCE(SUM(duration_seconds), 0) AS tot
                      FROM pomodoro_sessions
                      WHERE user_id = ? AND project_id IN ($prjPh)",
                     array_merge([$current_user_id], $prjRamo));
    $__pom7 = sec_one("SELECT COALESCE(SUM(duration_seconds), 0) AS tot
                       FROM pomodoro_sessions
                       WHERE user_id = ? AND project_id IN ($prjPh)
                         AND start_time >= (CURDATE() - INTERVAL 6 DAY)",
                      array_merge([$current_user_id], $prjRamo));

    // attivita' collegate
    $__todo = sec_one("SELECT COUNT(*) AS n, COALESCE(SUM(is_done), 0) AS fatti
                       FROM todos WHERE user_id = ? AND project_id IN ($prjPh)",
                      array_merge([$current_user_id], $prjRamo));

    // mazzi di flashcard collegati al ramo
    $prjMazzi = [];
    foreach (sec_all("SELECT c.id, c.name, c.color, COUNT(i.id) AS carte
                      FROM fc_study_deck_projects pfc
                      JOIN " . fc_t('decks') . " c ON c.id = pfc.fc_category_id AND c.user_id = ?
                      LEFT JOIN " . fc_t('cards') . " i ON i.category_id = c.id
                      WHERE pfc.project_id IN ($prjPh)
                      GROUP BY c.id, c.name, c.color
                      ORDER BY c.name ASC
                      LIMIT 8",
                     array_merge([$current_user_id], $prjRamo)) as $__m) {
        $prjMazzi[] = [
            'id' => (int)$__m['id'],
            'name' => $__m['name'],
            'color' => fc_color_or_default($__m['color']),
            'carte' => (int)$__m['carte'],
            'corrente' => ((int)$__m['id'] === (int)$categoryID),
        ];
    }

    // il prossimo appuntamento gia' in calendario per questo progetto
    $__ev = sec_one("SELECT title, event_date, start_time, is_all_day
                     FROM planner_events
                     WHERE user_id = ? AND project_id IN ($prjPh)
                       AND is_cancelled = 0 AND event_date >= CURDATE()
                     ORDER BY event_date ASC, start_time ASC
                     LIMIT 1",
                    array_merge([$current_user_id], $prjRamo));

    $__oggi = new DateTime('today');
    $giorniA = function ($data) use ($__oggi) {
        if (empty($data)) return null;
        try { $d = new DateTime($data); } catch (Exception $e) { return null; }
        return (int)$__oggi->diff($d)->format('%r%a');
    };

    echo json_encode([
        'success'   => true,
        'id'        => $prjId,
        'name'      => $prj['name'],
        'notes'     => mb_substr(trim((string)$prj['notes']), 0, 400),
        'status'    => $prj['status'],
        'favorite'  => (int)$prj['is_favorite'] === 1,
        'color'     => preg_match('/^#[0-9A-Fa-f]{6}$/', (string)$prj['color']) ? $prj['color'] : '#8E8E93',
        'path'      => $prjAntenati,
        'target'    => $prj['target_date'],
        'target_in' => $giorniA($prj['target_date']),
        'due'       => $prj['due_date'],
        'due_in'    => $giorniA($prj['due_date']),
        'secondi'   => (int)($__pom['tot'] ?? 0),
        'secondi7'  => (int)($__pom7['tot'] ?? 0),
        'sessioni'  => (int)($__pom['n'] ?? 0),
        'figli'     => count($prjFigli),
        'figli_ok'  => $prjFigliChiusi,
        'figli_el'  => $prjFigliElenco,
        'todo'      => (int)($__todo['n'] ?? 0),
        'todo_ok'   => (int)($__todo['fatti'] ?? 0),
        'mazzi'     => $prjMazzi,
        'evento'    => $__ev ? [
            'title'    => $__ev['title'],
            'date'     => $__ev['event_date'],
            'time'     => ((int)$__ev['is_all_day'] === 1) ? '' : substr((string)$__ev['start_time'], 0, 5),
            'giorni'   => $giorniA($__ev['event_date']),
        ] : null,
    ]);
    exit;
}

// ==========================================
// AJAX: le note del mazzo che si sta guardando (il riquadro in cima al
// feed). Testo libero, al massimo 5000 caratteri; vuoto = nessuna nota.
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'fc_deck_notes') {
    header('Content-Type: application/json; charset=utf-8');
    sec_guard_write($current_user_id, 'fc_vis', 0, 120, 60);
    $dnId = sec_int($_POST['category_id'] ?? 0, 1);
    if (!fc_mazzo_mio($dnId, $current_user_id)) {
        echo json_encode(['success' => false, 'error' => t('fc.vis_categoria_non_valida')]); exit;
    }
    $dnTesto = trim(sec_text($_POST['notes'] ?? '', 5000));
    sec_q("UPDATE " . fc_t('decks') . " SET notes = ? WHERE id = ? AND user_id = ?",
          [$dnTesto !== '' ? $dnTesto : null, $dnId, $current_user_id]);
    echo json_encode(['success' => true, 'notes' => $dnTesto], JSON_UNESCAPED_UNICODE);
    exit;
}

// ==========================================
// AJAX: rinomina la categoria che si sta guardando (tocco sul nome nella
// barra in alto) e cambio del suo colore (tocco sulla pastiglia).
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])
    && in_array($_POST['action'], ['fc_rename_cat', 'fc_recolor_cat'], true)) {
    header('Content-Type: application/json; charset=utf-8');
    sec_guard_write($current_user_id, 'fc_vis', 0, 120, 60);

    $ccId = sec_int($_POST['category_id'] ?? 0, 1);
    if (!fc_mazzo_mio($ccId, $current_user_id)) {
        echo json_encode(['success' => false, 'error' => t('fc.vis_categoria_non_valida')]); exit;
    }

    if ($_POST['action'] === 'fc_rename_cat') {
        $ccNome = sec_str($_POST['name'] ?? '', 120);
        if (trim($ccNome) === '') {
            echo json_encode(['success' => false, 'error' => t('fc.vis_nome_mancante')]); exit;
        }
        sec_q("UPDATE " . fc_t('decks') . " SET name = ? WHERE id = ? AND user_id = ?",
              [$ccNome, $ccId, $current_user_id]);
        echo json_encode(['success' => true, 'name' => $ccNome]);
        exit;
    }

    /* Colore. "auto" non e' un colore ma una rinuncia: si azzera color_edited e
       si riprende quello del padre, poi lo si ripropaga ai discendenti che a
       loro volta ereditano. */
    $ccModo = sec_enum($_POST['color_mode'] ?? null, ['auto', 'manual'], 'auto');
    $ccCol  = sec_color($_POST['color'] ?? null, '');
    $ccRiga = sec_one("SELECT parent_id FROM " . fc_t('decks') . " WHERE id = ? AND user_id = ?",
                      [$ccId, $current_user_id]);
    $ccPadre = $ccRiga ? (int)$ccRiga['parent_id'] : 0;

    if ($ccModo === 'manual' && $ccCol !== '') {
        $ccFinale = $ccCol; $ccEdited = 1;
    } else {
        $ccFinale = fc_colore_ereditato($ccPadre, $current_user_id); $ccEdited = 0;
    }
    sec_q("UPDATE " . fc_t('decks') . " SET color = ?, color_edited = ? WHERE id = ? AND user_id = ?",
          [$ccFinale, $ccEdited, $ccId, $current_user_id]);
    fc_cascata_colore($ccId, $ccFinale, $current_user_id);

    echo json_encode(['success' => true, 'color' => $ccFinale, 'edited' => $ccEdited]);
    exit;
}

/* Ripropaga un colore ai discendenti che ereditano, fermandosi ai rami che
   hanno un colore scelto a mano: quelli non si toccano. Stessa regola di
   cs_cascade_color() in fc_decks.php. */
function fc_cascata_colore($categoryId, $color, $userId, $depth = 0) {
    if ($depth > 50) return;
    foreach (sec_all("SELECT id, color_edited FROM " . fc_t('decks') . " WHERE parent_id = ? AND user_id = ?",
                     [(int)$categoryId, (int)$userId]) as $ch) {
        if ((int)$ch['color_edited'] === 0) {
            sec_q("UPDATE " . fc_t('decks') . " SET color = ? WHERE id = ? AND user_id = ?",
                  [$color, (int)$ch['id'], (int)$userId]);
            fc_cascata_colore((int)$ch['id'], $color, $userId, $depth + 1);
        }
    }
}


// ==========================================

// SOTTOCATEGORIE della categoria corrente (per la mini-griglia "sottocartelle")

// ==========================================

$subcategories = [];

$subcat_children_count = [];

$subcat_card_counts = [];



if ($categoryID != 0) {

    foreach ($categories_list as $c) {

        $pid = isset($c['parent_id']) ? (int)$c['parent_id'] : 0;

        if ($pid == $categoryID) {

            $subcategories[] = $c;

        }

    }



    // Quante sotto-sottocategorie ha ciascuna sottocategoria (badge "cartelle")

    foreach ($categories_list as $c) {

        $pid = isset($c['parent_id']) ? (int)$c['parent_id'] : 0;

        if ($pid != 0) {

            if (!isset($subcat_children_count[$pid])) { $subcat_children_count[$pid] = 0; }

            $subcat_children_count[$pid]++;

        }

    }



    if (!empty($subcategories)) {

        $subcat_ids = array_map(function($c) { return (int)$c['id']; }, $subcategories);

        foreach ($subcat_ids as $sid) { $subcat_card_counts[$sid] = 0; }

                $subcat_ph = implode(',', array_fill(0, count($subcat_ids), '?'));



        // Carte "padre" o singole, direttamente dentro ciascuna sottocategoria

        $image_to_subcat = [];

        $all_image_ids_in_subcats = [];

                foreach (sec_all("SELECT id, category_id FROM " . fc_t('cards') . " WHERE category_id IN ($subcat_ph)", $subcat_ids) as $r) {

            $cid = (int)$r['category_id'];

            $subcat_card_counts[$cid]++;

            $image_to_subcat[(int)$r['id']] = $cid;

            $all_image_ids_in_subcats[] = (int)$r['id'];

        }



        // Carte "figlie" agganciate ai padri di quelle sottocategorie

        if (!empty($all_image_ids_in_subcats)) {

                        $img_ph = implode(',', array_fill(0, count($all_image_ids_in_subcats), '?'));
            foreach (sec_all("SELECT parent_id, COUNT(*) AS c FROM " . fc_t('subcards') . " WHERE parent_id IN ($img_ph) GROUP BY parent_id",
                             $all_image_ids_in_subcats) as $r) {

                $pid_img = (int)$r['parent_id'];

                if (isset($image_to_subcat[$pid_img])) {

                    $subcat_card_counts[$image_to_subcat[$pid_img]] += (int)$r['c'];

                }

            }

        }

    }

}



$global_fc_counts = [];

// ==========================================
// CONTEGGIO CARTE della categoria corrente (comprese TUTTE le sottocategorie).
// Prima mostrava sempre "0 carte" perche' il valore non veniva mai calcolato.
// ==========================================
$fc_total_cards = 0;
{
    $fc_children_map = [];
    foreach ($categories_list as $c) {
        $pid = isset($c['parent_id']) ? (int)$c['parent_id'] : 0;
        $fc_children_map[$pid][] = (int)$c['id'];
    }
    $fc_count_cat_ids = [];
    if ($categoryID == 0) {
        foreach ($categories_list as $c) { $fc_count_cat_ids[] = (int)$c['id']; }
    } else {
        $stack = [$categoryID];
        while (!empty($stack)) {
            $cur = array_pop($stack);
            $fc_count_cat_ids[] = $cur;
            foreach (($fc_children_map[$cur] ?? []) as $ch) { $stack[] = $ch; }
        }
    }
    if (!empty($fc_count_cat_ids)) {
                $conteggio_ids = array_map('intval', $fc_count_cat_ids);
        $conteggio_ph = implode(',', array_fill(0, count($conteggio_ids), '?'));
        $fc_total_cards += (int)(sec_one("SELECT COUNT(*) AS c FROM " . fc_t('cards') . " WHERE category_id IN ($conteggio_ph)",
                                         $conteggio_ids)['c'] ?? 0);
        $img_ids = [];
        foreach (sec_all("SELECT id FROM " . fc_t('cards') . " WHERE category_id IN ($conteggio_ph)", $conteggio_ids) as $ri) {
            $img_ids[] = (int)$ri['id'];
        }
        if (!empty($img_ids)) {
            $img_ph2 = implode(',', array_fill(0, count($img_ids), '?'));
            $fc_total_cards += (int)(sec_one("SELECT COUNT(*) AS c FROM " . fc_t('subcards') . " WHERE parent_id IN ($img_ph2)",
                                             $img_ids)['c'] ?? 0);
        }
    }
}
// ==========================================
// PROGETTO collegato alla categoria corrente (o al primo antenato collegato).
// Se una categoria non e' collegata ma lo e' il padre/nonno, mostriamo quello.
// ==========================================
$fc_linked_project = null;   // ['id','name','color','via_ancestor'=>bool]
/* Per le lingue il progetto e' quello del corso, uguale per tutti i suoi mazzi. */
if (fc_is_vocab()) {
    $fcPrj = fc_corso() && !empty(fc_corso()['project_id'])
        ? sec_one("SELECT p.id, p.name, p.color, pp.name AS parent_name FROM projects p
                    LEFT JOIN projects pp ON pp.id = p.parent_id AND pp.user_id = p.user_id
                    WHERE p.id = ? AND p.user_id = ?", [(int)fc_corso()['project_id'], (int)$current_user_id])
        : null;
    if ($fcPrj) {
        $fc_linked_project = ['id' => (int)$fcPrj['id'], 'name' => $fcPrj['name'], 'parent_name' => $fcPrj['parent_name'],
                              'color' => preg_match('/^#[0-9A-Fa-f]{6}$/', (string)$fcPrj['color']) ? $fcPrj['color'] : '#8E8E93',
                              'via_ancestor' => false];
    }
} elseif ($categoryID != 0) {
    // mappa id -> parent per risalire la catena
    $fc_parent_of = [];
    foreach ($categories_list as $c) { $fc_parent_of[(int)$c['id']] = isset($c['parent_id']) ? (int)$c['parent_id'] : 0; }
    // catena: categoria corrente, poi antenati
    $fc_chain = [];
    $cur = $categoryID; $guard = 0;
    while ($cur > 0 && $guard < 60) { $fc_chain[] = $cur; $cur = $fc_parent_of[$cur] ?? 0; $guard++; }
    if (!empty($fc_chain)) {
                $chain_ids = array_map('intval', $fc_chain);
        $chain_ph = implode(',', array_fill(0, count($chain_ids), '?'));
        $lpRes = sec_all("SELECT pfc.fc_category_id, p.id AS pid, p.name AS pname, p.color AS pcolor, pp.name AS parent_name
                          FROM fc_study_deck_projects pfc
                          INNER JOIN projects p ON p.id = pfc.project_id AND p.user_id = ?
                          LEFT JOIN projects pp ON pp.id = p.parent_id AND pp.user_id = ?
                          WHERE pfc.fc_category_id IN ($chain_ph)",
                         array_merge([$current_user_id, $current_user_id], $chain_ids));
        $by_cat = [];
        foreach ($lpRes as $lp) {
            $pcol = (!empty($lp['pcolor']) && preg_match('/^#[0-9A-Fa-f]{6}$/', $lp['pcolor'])) ? $lp['pcolor'] : '#8E8E93';
            $by_cat[(int)$lp['fc_category_id']] = ['id' => (int)$lp['pid'], 'name' => $lp['pname'], 'parent_name' => $lp['parent_name'], 'color' => $pcol];
        }
        // scegli il primo lungo la catena (categoria stessa prima, poi antenati)
        foreach ($fc_chain as $i => $cid) {
            if (isset($by_cat[$cid])) {
                $fc_linked_project = $by_cat[$cid];
                $fc_linked_project['via_ancestor'] = ($i > 0);
                break;
            }
        }
    }
}

// Cerca i duplicati SOLO tra le carte che appartengono alle categorie dell'utente loggato
foreach (sec_all("SELECT i." . fc_col_fronte() . " AS question_desc FROM " . fc_t('cards') . " i JOIN " . fc_t('decks') . " c ON i.category_id = c.id WHERE c.user_id = ?" . $fcW,
                 array_merge([$current_user_id], $fcP)) as $r) {

    $qk = strtolower(trim($r['question_desc']));

    if(!isset($global_fc_counts[$qk])) $global_fc_counts[$qk] = 0;

    $global_fc_counts[$qk]++;

}



// Query blindata: usa una JOIN rigida per assicurarsi che la categoria appartenga al current_user_id
// Query blindata: usa una JOIN rigida per assicurarsi che la categoria appartenga al current_user_id
$query = "SELECT " . fc_colonne_carta('i') . ", c.name AS category_name, c.color AS category_color, (CASE WHEN ul.fc_image_id IS NOT NULL THEN 1 ELSE 0 END) as is_learnt 
          FROM " . fc_t('cards') . " AS i 
          JOIN " . fc_t('decks') . " AS c ON i.category_id = c.id 
          LEFT JOIN " . fc_t('learnt') . " ul ON i.id = ul.fc_image_id AND ul.user_id = ? 
          WHERE c.user_id = ?" . $fcW;

// Se è selezionata una categoria specifica, aggiunge la condizione con AND invece di WHERE
$par = array_merge([$current_user_id, $current_user_id], $fcP);
if ($categoryID != 0) { $query .= " AND i.category_id = ?"; $par[] = $categoryID; }



// Modifica ordinamento: default id_desc per "tutte le materie"
if ($categoryID == 0 && !isset($_GET['sort'])) {
    $sort = 'id_desc';
} else {
        $sort = sec_enum($_GET['sort'] ?? null, ['custom_asc', 'id_desc', 'id_asc', 'q_asc', 'livello'], 'custom_asc');
}

$order_by = "i.custom_order ASC"; 
if ($sort === 'id_desc') { $order_by = "i.id DESC"; }
elseif ($sort === 'id_asc') { $order_by = "i.id ASC"; }
elseif ($sort === 'q_asc') { $order_by = "TRIM(LOWER(i." . fc_col_fronte() . ")) ASC"; }

/* $order_by non e' un valore ma un pezzo di SQL, e non puo' essere un
   segnaposto: nasce dall'if qui sopra a quattro rami, quindi non arriva
   dall'esterno. */
$query .= " ORDER BY $order_by";
$images_data = [];
$ids_array = [];
foreach (sec_all($query, $par) as $row) {

    $row['children'] = [];

    $images_data[$row['id']] = $row;

    $ids_array[] = $row['id'];

}



if (count($ids_array) > 0) {

        $ids_ph = implode(',', array_fill(0, count($ids_array), '?'));
    foreach (sec_all("SELECT " . fc_colonne_figlia() . ", id_child AS id FROM " . fc_t('subcards') . " WHERE parent_id IN ($ids_ph) ORDER BY custom_order ASC",
                     array_map('intval', $ids_array)) as $c) {

        $images_data[$c['parent_id']]['children'][] = $c;

    }

}



/* I LIVELLI DELLE PAROLE E DELLE CARTE (lingue e studio) — gli stessi del gioco: nuova,
   difficile, in corso, buona, padroneggiata, dalle risposte di sempre
   (lib/fc/fc_vocab_gioco.php). Servono all'etichetta su ogni carta, al
   filtro per livello e all'ordine "piu' difficili prima". Chiavi 'p<id>' per
   le carte e 'c<id>' per le sottocarte. */
$fcv_livelli = [];
$fcv_conta_livelli = ['difficile' => 0, 'in_corso' => 0, 'nuova' => 0, 'buona' => 0, 'padroneggiata' => 0];
if ($ids_array) { /* lingue e studio */
    require_once __DIR__ . '/../../lib/fc/fc_vocab_gioco.php';
    $fcv_figli = [];
    foreach ($images_data as $r) foreach ($r['children'] as $ch) $fcv_figli[] = (int)$ch['id'];
    $fcv_st = fcv_stats_carte($ids_array, $fcv_figli);
    $fcv_precisione = [];
    foreach ($fcv_st as $k => $st) {
        $fcv_livelli[$k] = fcv_livello($st['c'], $st['w'], $st['u']);
        $fcv_conta_livelli[$fcv_livelli[$k]]++;
        $fcv_precisione[$k] = ($st['c'] + $st['w']) ? $st['c'] / ($st['c'] + $st['w']) : 1;
    }
    /* "Piu' difficili prima": una famiglia vale quanto la sua parola piu'
       difficile (padre o sottocarta), e a parita' conta la precisione. */
    if ($sort === 'livello') {
        $fcv_rango = ['difficile' => 0, 'in_corso' => 1, 'nuova' => 2, 'buona' => 3, 'padroneggiata' => 4];
        $fcv_peso = function (array $r) use ($fcv_livelli, $fcv_precisione, $fcv_rango) {
            $chiavi = ['p' . (int)$r['id']];
            foreach ($r['children'] as $ch) $chiavi[] = 'c' . (int)$ch['id'];
            $rango = 9; $prec = 1;
            foreach ($chiavi as $k) {
                $rk = $fcv_rango[$fcv_livelli[$k] ?? 'nuova'];
                if ($rk < $rango || ($rk === $rango && ($fcv_precisione[$k] ?? 1) < $prec)) { $rango = $rk; $prec = $fcv_precisione[$k] ?? 1; }
            }
            return [$rango, $prec];
        };
        uasort($images_data, function ($a, $b) use ($fcv_peso) { return $fcv_peso($a) <=> $fcv_peso($b); });
        $ids_array = array_keys($images_data);
    }
}

/* LE PARTITE CON QUESTO MAZZO (il tasto accanto al nome): quante partite
   (senza contare i ripassi) hanno giocato questo mazzo o una sua
   sottocartella. L'elenco lo chiede il popup allo Storico. */
$fcg_n_partite = 0;
if ($categoryID != 0) {
    $fcg_figli = [];
    foreach (sec_all("SELECT id, parent_id FROM " . fc_t('decks') . " WHERE user_id = ?" . $fcW, array_merge([$current_user_id], $fcP)) as $c) {
        $fcg_figli[(int)$c['parent_id']][] = (int)$c['id'];
    }
    $fcg_ramo = []; $coda = [(int)$categoryID];
    while ($coda) { $x = array_pop($coda); if (isset($fcg_ramo[$x])) continue; $fcg_ramo[$x] = true; foreach ($fcg_figli[$x] ?? [] as $f) $coda[] = $f; }
    /* anche le cartelle che lo contengono (come il filtro dello Storico) */
    $fcg_padre = [];
    foreach ($fcg_figli as $pid => $ff) foreach ($ff as $f) $fcg_padre[$f] = (int)$pid;
    $fcg_su = $fcg_padre[(int)$categoryID] ?? 0; $fcg_g = 0;
    while ($fcg_su > 0 && $fcg_g++ < 40) { $fcg_ramo[$fcg_su] = true; $fcg_su = $fcg_padre[$fcg_su] ?? 0; }
    $ph = implode(',', array_fill(0, count($fcg_ramo), '?'));
    $fcg_n_partite = (int)(sec_one("SELECT COUNT(DISTINCT g.id) AS n FROM fc_games g
                                     JOIN " . fc_t('game_decks') . " gd ON gd.game_id = g.id
                                    WHERE g.user_id = ? AND (g.parent_id IS NULL OR g.parent_id = 0) AND gd.fc_category_id IN ($ph)",
                                   array_merge([$current_user_id], array_keys($fcg_ramo)))['n'] ?? 0);
}

/** L'etichetta del livello di una carta (vuota fuori dalle lingue). */
function fcv_etichetta_livello(string $chiave): string {
    global $fcv_livelli;
    if (!isset($fcv_livelli[$chiave])) return '';
    $liv = $fcv_livelli[$chiave];
    return '<span class="fcv-liv fcv-liv-' . $liv . '">'
         . ($liv === 'padroneggiata' ? '<svg viewBox="0 0 24 24" fill="currentColor"><path d="m12 2.5 2.9 6.2 6.8.8-5 4.7 1.3 6.7L12 17.6l-6 3.3 1.3-6.7-5-4.7 6.8-.8Z"/></svg>' : '<i></i>')
         . sec_h(t('fcv.liv_' . $liv)) . '</span>';
}

// Conteggio esempi reali: due liste separate (padri e figli), perche' ora
// sono due colonne diverse in fc_study_examples (parent_id / child_id), non piu'
// un'unica colonna con il segno a fare da spartiacque.

$examples_counts = [];

$child_ids = [];

foreach ($images_data as $row) {

    foreach ($row['children'] as $child) { $child_ids[] = intval($child['id']); }

}

if (count($ids_array) > 0) {

    $ph = implode(',', array_fill(0, count($ids_array), '?'));
    foreach (sec_all("SELECT parent_id, COUNT(*) AS c FROM " . fc_t('examples') . " WHERE parent_id IN ($ph) GROUP BY parent_id",
                     array_map('intval', $ids_array)) as $ex) {
        $examples_counts[(int)$ex['parent_id']] = (int)$ex['c'];
    }

}

if (count($child_ids) > 0) {

    $ph2 = implode(',', array_fill(0, count($child_ids), '?'));
    /* La chiave negativa resta solo come convenzione privata di QUESTO
       array PHP, per distinguere un figlio da un padre con lo stesso
       numero dentro un unico dizionario — render_examples_section() piu'
       sotto la usa gia' cosi'. Non ha piu' nulla a che fare col database:
       li' padre e figlio sono due colonne separate. */
    foreach (sec_all("SELECT child_id, COUNT(*) AS c FROM " . fc_t('examples') . " WHERE child_id IN ($ph2) GROUP BY child_id",
                     array_map('intval', $child_ids)) as $ex) {
        $examples_counts[-1 * (int)$ex['child_id']] = (int)$ex['c'];
    }

}



// Puo' il riordino dei PADRI essere attivato? Solo con ordinamento personalizzato e su una categoria precisa

// (in "Tutte le Categorie" l'ordine custom_order non e' comparabile tra categorie diverse).

$parent_reorder_enabled = ($sort === 'custom_asc' && $categoryID != 0);

$child_reorder_enabled = ($sort === 'custom_asc');

/* Stessa tavolozza di fc_decks.php: una cartella creata da qui deve
   poter avere gli stessi colori di una creata di la'. */
$colorPalette = ['#FF3B30', '#FF9500', '#FFCC00', '#34C759', '#00C7BE', '#30B0C7',
                 '#32ADE6', '#007AFF', '#5856D6', '#AF52DE', '#FF2D55', '#A2845E'];



// Sezione "Examples": visibile solo se esistono davvero esempi per quella carta

// render_examples_section() vive in fc_examples_box.php, incluso in cima a
// questo file — condivisa con fc_card_edit.php.


// ==========================================

// HELPER: normalizza un colore categoria con fallback

// ==========================================

function fc_color_or_default($color) {

    $color = trim((string)$color);

    return $color !== '' ? $color : '#007AFF';

}



// ==========================================

// HELPER: render di un lato (Domanda/Risposta) di una carta.

// Se manca il testo o l'immagine, il contenuto presente occupa tutto lo spazio.

// L'audio, se presente, e' sempre in fondo con la sua barra.

// ==========================================

/* $lato ('q' o 'a') finisce come classe sul contenitore. Serve alla vista
   compatta: li' le etichette "Domanda"/"Risposta" spariscono per far posto,
   e senza un segno che le distingua le due righe diventano due righe di
   testo uguali di cui non si sa quale sia quale. Con la classe, la domanda
   resta scura e la risposta piu' tenue — la stessa coppia che usa la vista
   compatta di fc_insert.php. */
/* QUANDO UN TESTO E' DAVVERO VUOTO
   ---------------------------------------------------------------
   Non basta guardare se la stringa e' vuota. Il testo delle carte non e' testo:
   e' un documento in JSON, e un campo lasciato in bianco arriva come
   {"v":2,"blocks":[{"t":"p","runs":[]}]} — un paragrafo senza parole dentro.
   Come stringa e' lunga quaranta caratteri; come contenuto non c'e' niente.
   E' lo stesso equivoco che faceva comparire una riga vuota sopra le immagini
   degli esempi.
   Qui si guarda dentro: qualunque parola in un "run" e' contenuto, e cosi' e'
   qualunque blocco che non sia un paragrafo o una voce di elenco (un'immagine,
   una formula, una tabella: cose che si vedono anche senza parole). Un formato
   che non si riesce a leggere come JSON viene dal vecchio testo semplice, e li'
   basta il trim di sempre. */
function fc_testo_vuoto($raw): bool {
    $raw = trim((string)$raw);
    if ($raw === '') return true;

    $doc = json_decode($raw, true);
    if (!is_array($doc) || !isset($doc['blocks']) || !is_array($doc['blocks'])) {
        return trim(strip_tags($raw)) === '';
    }

    foreach ($doc['blocks'] as $b) {
        if (!is_array($b)) continue;
        $tipo = (string)($b['t'] ?? 'p');
        /* Tutto quello che non e' testo scritto vale come contenuto: non
           conoscere il nome esatto di ogni tipo di blocco non deve portare a
           dichiarare vuota una carta che vuota non e'. */
        if ($tipo !== 'p' && $tipo !== 'li') return false;
        foreach (($b['runs'] ?? []) as $r) {
            if (is_array($r) && trim((string)($r['s'] ?? '')) !== '') return false;
            if (is_string($r) && trim($r) !== '') return false;
        }
    }
    return true;
}

/* ==========================================================================
   IL DISEGNATORE DEL DOCUMENTO, LATO SERVER

   Perche' esiste. Fino a ieri il server mandava il testo grezzo in data-raw e
   il browser lo disegnava con fc_parser: la carta arrivava con dentro un
   segnaposto in solo testo e un istante dopo veniva riscritta. Le due versioni
   non sono alte uguale — il segnaposto salta le righe vuote, il disegno vero
   le tiene come paragrafi — quindi la carta cambiava altezza sotto gli occhi,
   superava il max-height di .qa-text e si riassestava con la barra laterale.
   E' quello che si vedeva "costruirsi". Con mille carte, per giunta, erano
   mille chiamate a parseTextForPreview() tutte sul filo principale.

   La cura e' la stessa che usano le applicazioni che non si vedono mai
   costruire: mandare il markup DEFINITIVO dal server, non un provvisorio da
   sostituire. Queste funzioni sono la copia esatta, riga per riga, di
   fcdRunHTML/fcdBlockHTML in fc_parser.js: stesse classi, stessi attributi
   data-, stessi caratteri invisibili. Devono restare gemelle — se un giorno si
   tocca il disegnatore JavaScript, va toccato anche questo.

   Il formato VECCHIO (quello con /bold{...}) non passa di qui: convertirlo
   vuol dire ricopiare anche fcdFromLegacy, che e' un lettore lungo e pieno di
   casi particolari, e sono le carte scritte prima di fc_parser. Per quelle
   resta il vecchio giro col segnaposto, che continua a funzionare com'e'.
   ========================================================================== */

function fcd_php_escape($s): string {
    return str_replace(['&', '<', '>', '"'], ['&amp;', '&lt;', '&gt;', '&quot;'], (string)$s);
}

function fcd_php_mark_attrs($m): string {
    $out = '';
    if (!is_array($m)) return $out;
    foreach (['b', 'i', 'u', 'h'] as $k) {
        if (!empty($m[$k])) $out .= ' data-' . $k . '="1"';
    }
    if (!empty($m['c'])) $out .= ' data-c="' . fcd_php_escape($m['c']) . '"';
    return $out;
}

function fcd_php_run_html($r): string {
    if (is_string($r)) $r = ['s' => $r];
    if (!is_array($r)) return '';

    if (isset($r['x']) && is_string($r['x'])) {
        /* I due caratteri invisibili ai lati ci sono anche nel disegnatore
           JavaScript e servono al cursore dell'editor: qui non si usa il
           cursore, ma il markup dev'essere identico, altrimenti la carta
           cambierebbe comunque forma quando il JavaScript la ridisegna. */
        /* Il contenuto e' gia' quello che MathJax si aspetta, \( ... \), e la
           classe fc-resa dice che il lavoro di fcdDisegnaFormule qui e' gia'
           fatto. Cosi' MathJax puo' partire appena arriva, senza aspettare che
           il nostro JavaScript riscriva mille elementi uno per uno. */
        $classi = 'fc-x fc-resa fc-attesa' . (isset($r['m']) ? ' fc-t' : '');
        $attr   = isset($r['m']) ? fcd_php_mark_attrs($r['m']) : '';
        return "\u{200B}" . '<span class="' . $classi . '" data-x="1" data-tex="'
             . fcd_php_escape($r['x']) . '"' . $attr . '>'
             . '\\(' . fcd_php_escape($r['x']) . '\\)'
             . '</span>' . "\u{200B}";
    }

    $testo = str_replace("\n", '<br>' . "\u{200B}", fcd_php_escape($r['s'] ?? ''));
    if (empty($r['m'])) return $testo;
    return '<span class="fc-t"' . fcd_php_mark_attrs($r['m']) . '>' . $testo . '</span>';
}

function fcd_php_block_html($b): string {
    if (!is_array($b)) return '';
    $t = (string)($b['t'] ?? 'p');

    if ($t === 'fx') {
        $attr = '';
        $classi = 'fc-fx fc-resa fc-attesa';
        if (!empty($b['lvl'])) {
            $classi .= ' fc-fx-li';
            $attr .= ' data-lvl="' . (int)$b['lvl'] . '"';
            if (!empty($b['c'])) $attr .= ' data-c="' . fcd_php_escape($b['c']) . '"';
        }
        if (!empty($b['m'])) {
            $classi .= ' fc-t';
            /* data-c su un blocco formula e' gia' preso dal pallino
               dell'elenco: il colore del testo diventa data-mc, esattamente
               come fa fcdBlockHTML. */
            $attr .= preg_replace('/ data-c="/', ' data-mc="', fcd_php_mark_attrs($b['m']), 1);
        }
        return '<div class="' . $classi . '" data-fx="1"' . $attr
             . ' data-tex="' . fcd_php_escape($b['tex'] ?? '') . '">'
             . '\\[' . fcd_php_escape($b['tex'] ?? '') . '\\]' . '</div>';
    }

    $inner = '';
    foreach (($b['runs'] ?? []) as $r) { $inner .= fcd_php_run_html($r); }
    if ($inner === '') $inner = '<br data-fill="1">';

    if ($t === 'li') {
        $attr = ' data-lvl="' . (((int)($b['lvl'] ?? 0)) ?: 1) . '"';
        if (!empty($b['c'])) $attr .= ' data-c="' . fcd_php_escape($b['c']) . '"';
        return '<div class="fc-li"' . $attr . '>' . $inner . '</div>';
    }
    if (!empty($b['lvl'])) {
        return '<div class="fc-p fc-p-lvl" data-lvl="' . (int)$b['lvl'] . '">' . $inner . '</div>';
    }
    return '<div class="fc-p">' . $inner . '</div>';
}

/* Restituisce l'HTML finito, oppure null se il documento non e' nel formato
   nuovo: chi chiama, in quel caso, torna al segnaposto di sempre. */
function fc_doc_html($raw): ?string {
    $raw = trim((string)$raw);
    if ($raw === '') return null;
    if ($raw[0] !== '{' || strpos($raw, '"blocks"') === false) return null;

    $doc = json_decode($raw, true);
    if (!is_array($doc) || !isset($doc['blocks']) || !is_array($doc['blocks'])) return null;

    $out = '';
    foreach ($doc['blocks'] as $b) { $out .= fcd_php_block_html($b); }
    return $out;
}


/* Le parole di un campo, in chiaro. Serve a mettere QUALCOSA nella carta gia'
   nell'HTML che arriva dal server: oggi il testo viene disegnato dal
   JavaScript leggendo data-raw, quindi al primo disegno le carte sono cornici
   vuote e il contenuto ci cade dentro un istante dopo — e' quello che si vede
   "costruirsi".
   Qui non si ricostruisce la formattazione: si prendono le parole e basta. Le
   parole sono il 90% di quello che c'e' da leggere, arrivano col primo disegno
   e non costano una richiesta; il grassetto, gli elenchi e le formule li mette
   il JavaScript subito dopo, al posto di queste. */
function fc_testo_piano($raw, int $max = 400): string {
    $raw = trim((string)$raw);
    if ($raw === '') return '';

    $doc = json_decode($raw, true);
    if (!is_array($doc) || !isset($doc['blocks']) || !is_array($doc['blocks'])) {
        $piano = trim(preg_replace('/\s+/u', ' ', strip_tags($raw)));
    } else {
        $righe = [];
        foreach ($doc['blocks'] as $b) {
            if (!is_array($b)) continue;
            $pezzi = [];
            foreach (($b['runs'] ?? []) as $r) {
                if (is_array($r)) $pezzi[] = (string)($r['s'] ?? '');
                elseif (is_string($r)) $pezzi[] = $r;
            }
            $riga = trim(implode('', $pezzi));
            if ($riga !== '') $righe[] = $riga;
        }
        $piano = implode("\n", $righe);
    }

    if ($piano === '') return '';
    if (function_exists('mb_strlen') && mb_strlen($piano) > $max) {
        return mb_substr($piano, 0, $max - 1) . '…';
    }
    return $piano;
}

/* Un lato della carta e' vuoto se non ha ne' testo, ne' immagine, ne' audio. */
function fc_lato_vuoto($testo, $immagine, $audio): bool {
    return fc_testo_vuoto($testo)
        && trim((string)$immagine) === ''
        && trim((string)$audio) === '';
}

/* I due lati di una carta, del tipo giusto.
   Studio: domanda e risposta, come sempre.
   Lingua: la parola (con la sua bandiera e il tasto per sentirla) e la
   traduzione, con il significato sotto. I nomi delle colonne arrivano gia'
   tradotti nei nomi question_... e answer_... da fc_colonne_carta(), quindi qui le
   chiavi sono le stesse per i due tipi; word/translation/meaning servono solo
   a cio' che lo studio non ha. */
function fc_lati_carta(array $r): void {
    if (!fc_is_vocab()) {
        render_qa_side(T('fc.ifc_domanda'), $r['question_desc'] ?? '', $r['question_image'] ?? '', $r['question_audio'] ?? '', 'q');
        render_qa_side(T('fc.ifc_risposta'), $r['answer_desc'] ?? '', $r['answer_image'] ?? '', $r['answer_audio'] ?? '', 'a');
        return;
    }
    $c = fc_corso();
    render_qa_side(fc_lingua_nome($c['learn_lang'], false), $r['question_desc'] ?? '', $r['question_image'] ?? '', '', 'q',
                   ['speak' => 'learn', 'flag' => fc_lingua_bandiera($c['learn_lang'])]);
    render_qa_side(fc_lingua_nome($c['known_lang'], false), $r['answer_desc'] ?? '', '', '', 'a',
                   ['speak' => 'known', 'flag' => fc_lingua_bandiera($c['known_lang']), 'meaning' => $r['meaning'] ?? '', 'notes' => $r['notes'] ?? '']);
}

function render_qa_side($label, $text, $image, $audio, $lato = '', array $extra = []) {

    $text = trim((string)$text);

    $image = trim((string)$image);

    $audio = trim((string)$audio);

    /* fc_testo_vuoto e non "!== ''": un campo lasciato in bianco arriva come un
       documento JSON di un paragrafo senza parole, che per una stringa e'
       pieno. Da qui il riquadro vuoto che compariva al posto del trattino. */
    $hasText = !fc_testo_vuoto($text);

    $hasImage = $image !== '';

    $hasAudio = $audio !== '';

    $contentClass = ($hasText && $hasImage) ? 'qa-split' : 'qa-solo';

    ?>

    <div class="qa-side<?= $lato === 'q' ? ' qa-q' : ($lato === 'a' ? ' qa-a' : '') ?>">

        <?php if (!empty($extra['flag'])): ?>
        <div class="qa-label qa-label-lang">
            <img class="qa-flag" src="<?= sec_h($extra['flag']) ?>" alt="">
            <span><?= sec_h($label) ?></span>
            <?php if (!empty($extra['speak']) && $hasText): ?>
            <button type="button" class="qa-speak" data-speak="<?= sec_h($extra['speak']) ?>" data-text="<?= sec_h($text) ?>" aria-label="<?php te('fcv.ascolta'); ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 5 6 9H2v6h4l5 4z"/><path d="M15.5 8.5a5 5 0 0 1 0 7"/><path d="M18.8 5.2a9.5 9.5 0 0 1 0 13.6"/></svg>
            </button>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="qa-label"><?= sec_h($label) ?></div>
        <?php endif; ?>

        <?php if ($hasText || $hasImage): ?>

        <div class="qa-content <?= sec_h($contentClass) ?>">

            <?php if ($hasText): ?>

                <?php /* Il segnaposto e' un elemento a se', marcato: il disegnatore
                         vero (fc_parser.js) riscrive tutto il contenuto di questo
                         div, quindi appena passa lui il segnaposto sparisce da
                         solo. E se per qualche motivo non passasse, la rete in
                         fondo alla pagina se ne accorge proprio dal marchio
                         rimasto e disegna lei. In nessuno dei due casi la carta
                         resta vuota, che e' l'unica cosa che conta. */ ?>
                <?php
                    /* Il markup DEFINITIVO, gia' disegnato qui. Se il documento
                       e' nel formato nuovo esce di qui uguale a com'e' uscito
                       dal JavaScript: niente sostituzione, niente salto di
                       altezza, niente mille parseTextForPreview() al
                       caricamento. data-raw resta comunque, perche' i pezzi di
                       pagina che ridisegnano una carta (modifica, esempi,
                       carte appena inserite) continuano a leggerlo da li'.
                       Formato vecchio: si torna al segnaposto, che il
                       JavaScript sostituira' come ha sempre fatto. */
                    $qaHtml = fc_doc_html($text);
                ?>
                <?php if (fc_is_vocab()): ?>
                <?php /* Le parole sono testo semplice: arrivano gia' definitive,
                         senza segnaposto, e il JavaScript le lascia come sono. */ ?>
                <div class="qa-text raw-text-content qa-plain" data-raw="<?= sec_h($text) ?>"><?= sec_h($text) ?></div>
                <?php elseif ($qaHtml !== null): ?>
                <div class="qa-text raw-text-content" data-raw="<?= sec_h($text) ?>"><?= $qaHtml ?></div>
                <?php else: ?>
                <div class="qa-text raw-text-content" data-raw="<?= sec_h($text) ?>"><span class="qa-pre" data-fc-pre="1"><?= sec_h(fc_testo_piano($text)) ?></span></div>
                <?php endif; ?>

            <?php endif; ?>

            <?php if ($hasImage): ?>

                <?php /* width e height dichiarati: il browser conosce la
                         proporzione e tiene il posto giusto fin dal primo
                         disegno, invece di lasciare l'immagine a zero e far
                         saltare tutto quando arriva. Il CSS continua a
                         decidere le misure VERE (76px nelle carte con testo,
                         max 230px in quelle a sola immagine); questi attributi
                         servono solo a dire la forma in anticipo.
                         Se la misura non si sa (immagine di prima della
                         migrazione, non ancora recuperata) non esce niente e
                         quell'immagine si comporta come prima. */ ?>
                <img class="qa-image" src="<?= sec_h(fc_cartella_immagini() . $image) ?>"<?= function_exists('fc_misura_attr') ? fc_misura_attr($image) : '' ?> alt="" loading="lazy" onclick="event.stopPropagation(); openImageZoom(this.src);">

            <?php endif; ?>

        </div>

        <?php else: ?>

        <div class="qa-content qa-empty"><span>—</span></div>

        <?php endif; ?>

        <?php if ($hasAudio): render_audio_player('../media/flashcard_audio/' . sec_h($audio)); endif; ?>

        <?php if (!empty($extra['meaning']) && trim((string)$extra['meaning']) !== ''): ?>
        <div class="qa-meaning"><span class="qa-meaning-lab"><?php te('fcv.significato'); ?></span> <?= nl2br(sec_h(trim((string)$extra['meaning']))) ?></div>
        <?php endif; ?>

        <?php if (!empty($extra['notes']) && trim((string)$extra['notes']) !== ''): ?>
        <div class="qa-nota"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9Z"/><path d="M14 3v6h6M8 13h8M8 17h5"/></svg><span><?= nl2br(sec_h(trim((string)$extra['notes']))) ?></span></div>
        <?php endif; ?>

    </div>

    <?php

}



function render_audio_player($src) {

    ?>

    <div class="audio-player" data-src="<?= sec_h($src) ?>">

        <button type="button" class="ap-btn" aria-label="<?php te('fc.vis_riproduci'); ?>" onclick="event.stopPropagation(); toggleAudioPlayer(this);">

            <svg class="ap-ic ap-ic-play" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5.14v13.72c0 .84.91 1.36 1.63.93l11.32-6.86a1.08 1.08 0 0 0 0-1.86L9.63 4.21A1.08 1.08 0 0 0 8 5.14Z"/></svg>

            <svg class="ap-ic ap-ic-pause" viewBox="0 0 24 24" fill="currentColor" style="display:none"><path d="M7 5.5A1.5 1.5 0 0 1 8.5 4h1A1.5 1.5 0 0 1 11 5.5v13a1.5 1.5 0 0 1-1.5 1.5h-1A1.5 1.5 0 0 1 7 18.5v-13ZM13 5.5A1.5 1.5 0 0 1 14.5 4h1A1.5 1.5 0 0 1 17 5.5v13a1.5 1.5 0 0 1-1.5 1.5h-1a1.5 1.5 0 0 1-1.5-1.5v-13Z"/></svg>

        </button>

        <div class="ap-track" onclick="event.stopPropagation(); seekAudioPlayer(event, this);">

            <div class="ap-fill"></div>

            <div class="ap-knob"></div>

        </div>

        <span class="ap-time">0:00</span>

        <audio preload="none" src="<?= sec_h($src) ?>"></audio>

    </div>

    <?php

}

?>

<?php
/* ==========================================================================
   STAMPA UN BLOCCO DI CARTE

   Prima questo ciclo stava dentro il markup e stampava TUTTE le carte, poi
   nascondeva con display:none quelle oltre la trentesima. Il browser non le
   disegnava, d'accordo, ma il PHP le generava lo stesso, viaggiavano lo stesso
   sulla rete e il browser le analizzava lo stesso: su "tutte le materie" sono
   piu' di mille carte di HTML per farne vedere trenta.

   Ora e' una funzione, e la chiamano in due: il markup della pagina per il
   primo blocco, e l'endpoint fc_feed_page per quelli successivi. Il corpo e'
   rimasto identico riga per riga — l'unica differenza e' la finestra in cima
   al ciclo — proprio perche' le due strade devono produrre lo stesso HTML.

   $quanti = 0 vuol dire "da qui in fondo": serve a chi ha bisogno di tutto
   l'elenco in una volta (la ricerca, il filtro di stato).
   ========================================================================== */
function fc_stampa_gruppi($da, $quanti) {
    global $fcv_livelli;
    global $images_data, $categoryID, $global_fc_counts, $examples_counts,
           $parent_reorder_enabled, $child_reorder_enabled, $current_user_id;

    $group_counter = 0;
    $saltati = 0;
    $stampati = 0;
 
                $group_counter = 0;
                foreach ($images_data as $row):
                    /* La finestra: si scorre tutto l'elenco ma si stampa solo il
                       pezzo chiesto. Saltare con un contatore invece di usare
                       array_slice tiene il codice del corpo identico a prima —
                       $row e' ancora la riga del ciclo, e non c'e' nessuna copia
                       dell'array da fare. */
                    if ($saltati < $da) { $saltati++; continue; }
                    if ($quanti > 0 && $stampati >= $quanti) break;
                    $stampati++;
                    $group_counter++;
                    $isLearnt = $row['is_learnt'] == 1;
                    $catColor = fc_color_or_default($row['category_color']);
                    $catName = $row['category_name'] ? $row['category_name'] : T('vocab.vi_nessuna');
                    $searchString = sec_h(strtolower($row['question_desc'] . ' ' . $row['answer_desc'] . ' ' . ($row['meaning'] ?? '') . ' ' . ($row['notes'] ?? '')));
                    $qk = strtolower(trim($row['question_desc']));
                    $isDuplicate = (isset($global_fc_counts[$qk]) && $global_fc_counts[$qk] > 1);
                    $childCount = count($row['children']);

                    /* UNA CARTA CHE NON E' UNA CARTA
                       Domanda piena, risposta completamente vuota (niente testo,
                       niente immagine, niente audio) e almeno due sottocarte: non
                       e' una domanda senza risposta, e' il TITOLO delle sue
                       sottocarte. Chi l'ha scritta lo sapeva; la pagina no, e la
                       mostrava come una carta rotta — un riquadro "Risposta" con
                       dentro un trattino, ripetuto per ogni gruppo.
                       Due sottocarte e non una: con una sola figlia il dubbio
                       resta (puo' essere una carta a cui manca la risposta), con
                       due l'intenzione e' chiara. */
                    /* Le parole padre non sono titoli: "run" con dentro
                       "run out" e' una parola anche lei. */
                    $isTitolo = !fc_is_vocab() && $childCount >= 2
                             && fc_lato_vuoto($row['answer_desc'], $row['answer_image'], $row['answer_audio'])
                             && !fc_testo_vuoto($row['question_desc']);
                    
                    // Pre-nascondi via PHP per evitare rendering lag
                    /* Non esiste piu' la carta stampata e nascosta: quelle
                       oltre il blocco non vengono proprio generate, ed e' tutto
                       il punto della paginazione. La variabile resta perche' il
                       markup qui sotto la usa, ma ora e' sempre vuota. */
                    $hide_style = '';
                ?>
                <div class="fc-group <?= $isTitolo ? 'is-titolo' : '' ?>" data-parent-id="<?= (int)$row['id'] ?>" data-category-id="<?= (int)$row['category_id'] ?>" data-reorderable="<?= $parent_reorder_enabled ? '1' : '0' ?>" style="--row-color:<?= sec_h($catColor) ?>; <?= sec_h($hide_style) ?>">



                    <div class="fc-card fc-parent <?= $isTitolo ? 'is-titolo' : '' ?> <?= $isLearnt ? 'is-learnt' : '' ?> <?= $isDuplicate ? 'duplicate-flag' : '' ?>"

                         data-id="<?= (int)$row['id'] ?>" data-type="parent"

                         data-search="<?= sec_h($searchString) ?>" data-duplicate="<?= $isDuplicate ? 'true' : 'false' ?>"

                         data-edit-url="<?= sec_h(fc_url('fc_card_edit.php', ['fc_id' => (int)$row['id'], 'previous_catid' => (int)$categoryID])) ?>"

                         data-child-count="<?= (int)$childCount ?>"<?php if (isset($fcv_livelli['p' . (int)$row['id']])): ?> data-livello="<?= sec_h($fcv_livelli['p' . (int)$row['id']]) ?>"<?php endif; ?>>



                        <div class="swipe-actions">

                            <button type="button" class="swipe-act act-edit" onclick="goEditCard(this)">

                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>

                                <?php te('comune.modifica'); ?>

                            </button>

                            <button type="button" class="swipe-act act-delete" onclick="askDeleteCard(this)">

                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0-1 14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2L4 6"/></svg>

                                <?php te('comune.elimina'); ?>

                            </button>

                        </div>



                        <div class="swipe-content">

                            <div class="card-head">

                                <?php if ($current_user_id == 3): ?>

                                    <div class="learnt-disabled">–</div>

                                <?php else: ?>

                                    <label class="learnt-wrapper">

                                        <input type="checkbox" class="learnt-cb" <?= $isLearnt ? 'checked' : '' ?> onclick="toggleLearnt(<?= (int)$row['id'] ?>, this)">

                                        <div class="learnt-emoji">🧐</div>

                                        <div class="learnt-check"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></div>

                                    </label>

                                <?php endif; ?>

                                <label class="select-circle">

                                    <input type="checkbox" name="selected_ids[]" value="<?= (int)$row['id'] ?>" class="bulk-checkbox standalone-cb record-cb" onchange="onRecordCbChange(this)">

                                    <span class="cc-visual"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span>

                                </label>

                                <div class="card-head-spacer"></div><?= fcv_etichetta_livello('p' . (int)$row['id']) ?>


                                <?php if ($categoryID == 0): ?><span class="cat-badge" style="background:<?= sec_h($catColor) ?>"><?= sec_h($catName) ?></span><?php endif; ?>

                            </div>



                            <?php if ($isTitolo): ?>

                            <?php /* Il titolo, non una coppia domanda/risposta. Niente
                                     etichette "Domanda" e "Risposta", niente riquadro
                                     vuoto col trattino: quella risposta non manca, non
                                     doveva esserci.
                                     Nessun .qa-side qui dentro, ed e' voluto: il visore
                                     a flashcard costruisce il mazzo cercando proprio
                                     quelli (costruisciMazzo), quindi un titolo resta
                                     fuori dal mazzo senza bisogno di un caso a parte —
                                     e non c'e' modo che si presenti da solo come una
                                     carta da rigirare. */ ?>
                            <div class="fc-title">
                                <span class="fc-title-lab"><?php te('fc.vis_titolo'); ?></span>
                                <?php /* Anche il titolo del gruppo arriva gia' disegnato: era l'ultimo
                                         pezzo di testo che restava vuoto fino al passaggio del
                                         JavaScript, e su una carta-titolo il testo E' tutta la carta.
                                         Formato vecchio: resta vuoto e lo riempie il disegnatore, come
                                         ha sempre fatto. */
                                    $__titoloRaw  = trim((string)$row['question_desc']);
                                    $__titoloHtml = fc_doc_html($__titoloRaw);
                                ?>
                                <div class="fc-title-txt raw-text-content" data-raw="<?= sec_h($__titoloRaw) ?>"><?= $__titoloHtml !== null ? $__titoloHtml : '' ?></div>
                                <span class="fc-title-count"><?= sec_h(T('fc.vis_n_sottocarte', ['n' => (int)$childCount])) ?></span>
                            </div>

                            <?php else: ?>

                            <?php fc_lati_carta($row); ?>

                            <?php endif; ?>



                            <?php render_examples_section($row['id'], 'parent', $row['id'], $examples_counts); ?>

                            <?php /* GLI ATTREZZI DELLA VISTA COMPATTA
                                     Esistono sempre nel markup ma si vedono solo a
                                     vista compatta accesa: costruirli da JavaScript
                                     avrebbe voluto dire ricostruirli a ogni
                                     accensione, su tutte le carte della pagina.
                                     La freccia apre QUESTA carta a grandezza vera
                                     senza spegnere la vista compatta per tutte le
                                     altre: serve a controllare una carta prima di
                                     spuntarla, non a uscire dalla vista.
                                     Su e giu' salvano subito il nuovo ordine, come
                                     il trascinamento; spariscono da sole dove
                                     riordinare non si puo' (ordinamento diverso da
                                     "Posizione", o "Tutte le materie"). */ ?>
                            <div class="fc-compact-tools" aria-hidden="false">
                                <button type="button" class="ct-btn ct-up" onclick="moveCardBy(this, -1)"
                                        aria-label="<?php te('fc.vis_su'); ?>" title="<?php te('fc.vis_su'); ?>">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 19V5"/><path d="m6 11 6-6 6 6"/></svg>
                                </button>
                                <button type="button" class="ct-btn ct-down" onclick="moveCardBy(this, 1)"
                                        aria-label="<?php te('fc.vis_giu'); ?>" title="<?php te('fc.vis_giu'); ?>">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14"/><path d="m6 13 6 6 6-6"/></svg>
                                </button>
                            </div>

                            <?php /* Il tastino che rivela le sottocarte, e che si vede
                                     solo in modalita' Unisci: li' l'elenco mostra i soli
                                     padri, ma per decidere a chi attaccare una carta
                                     serve poter sbirciare cosa c'e' gia' dentro. Lo
                                     stesso tocco le richiude. */ ?>
                            <?php if ($childCount > 0): ?>
                            <button type="button" class="fc-kids-toggle" onclick="toggleKids(this)">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                                <span><?php echo sec_h(T('fc.vis_n_sottocarte', ['n' => (int)$childCount])); ?></span>
                            </button>
                            <?php endif; ?>

                        </div>

                    </div>



                    <?php if ($childCount > 0): ?>

                    <div class="fc-children" data-parent-id="<?= (int)$row['id'] ?>">

                        <?php foreach ($row['children'] as $child):

                            $searchChild = sec_h(strtolower($child['question_desc'] . ' ' . $child['answer_desc'] . ' ' . ($child['meaning'] ?? '') . ' ' . ($child['notes'] ?? '')));

                        ?>

                        <div class="fc-card fc-child" data-id="<?= (int)$child['id'] ?>" data-type="child" data-parent-id="<?= (int)$row['id'] ?>"

                             data-search="<?= sec_h($searchChild) ?>" data-duplicate="false" data-reorderable="<?= $child_reorder_enabled ? '1' : '0' ?>"<?php if (isset($fcv_livelli['c' . (int)$child['id']])): ?> data-livello="<?= sec_h($fcv_livelli['c' . (int)$child['id']]) ?>"<?php endif; ?>

                             data-edit-url="<?= sec_h(fc_url('fc_card_edit.php', ['child_id' => (int)$child['id'], 'previous_catid' => (int)$categoryID])) ?>">



                            <div class="swipe-actions">

                                <button type="button" class="swipe-act act-edit" onclick="goEditCard(this)">

                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>

                                    <?php te('vocab.cat_modifica_attr'); ?>

                                </button>

                                <button type="button" class="swipe-act act-delete" onclick="askDeleteCard(this)">

                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0-1 14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2L4 6"/></svg>

                                    <?php te('comune.elimina'); ?>

                                </button>

                            </div>



                            <div class="swipe-content">

                                <div class="card-head">

                                    <div class="child-tag"><span>↳</span> <?php te('fc.vis_sotto_carta'); ?></div>

                                    <label class="select-circle">

                                        <input type="checkbox" name="selected_child_ids[]" value="<?= (int)$child['id'] ?>" class="bulk-checkbox child-cb record-cb" onchange="onRecordCbChange(this)">

                                        <span class="cc-visual"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span>

                                    </label>

                                    <div class="card-head-spacer"></div><?= fcv_etichetta_livello('c' . (int)$child['id']) ?>

                                </div>



                                <?php fc_lati_carta($child); ?>



                                <?php render_examples_section($child['id'], 'child', -1 * intval($child['id']), $examples_counts); ?>

                                <?php /* Gli stessi attrezzi della carta padre: qui su e giu'
                                         riordinano le sottocarte dentro il loro padre. */ ?>
                                <div class="fc-compact-tools">
                                    <button type="button" class="ct-btn ct-up" onclick="moveCardBy(this, -1)"
                                            aria-label="<?php te('fc.vis_su'); ?>" title="<?php te('fc.vis_su'); ?>">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 19V5"/><path d="m6 11 6-6 6 6"/></svg>
                                    </button>
                                    <button type="button" class="ct-btn ct-down" onclick="moveCardBy(this, 1)"
                                            aria-label="<?php te('fc.vis_giu'); ?>" title="<?php te('fc.vis_giu'); ?>">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                                    </button>
                                </div>

                            </div>

                        </div>

                        <?php endforeach; ?>

                    </div>

                    <?php endif; ?>

                </div>

                <?php                 endforeach;

    return $stampati;
}
?>

<?php
/* Le misure delle immagini di questa pagina, tutte in una query.
   Una per pagina e non una per immagine: e' il motivo per cui le misure
   stanno in una tabella invece di essere lette dal disco al volo (vedi
   fc_image_sizes.php). Si raccolgono i nomi di domanda e risposta, delle
   carte padre e di quelle figlie, e si chiedono in blocco. */
$__fcNomiImg = [];
foreach ($images_data as $__r) {
    if (!empty($__r['question_image'])) $__fcNomiImg[] = $__r['question_image'];
    if (!empty($__r['answer_image']))   $__fcNomiImg[] = $__r['answer_image'];
    foreach (($__r['children'] ?? []) as $__c) {
        if (!empty($__c['question_image'])) $__fcNomiImg[] = $__c['question_image'];
        if (!empty($__c['answer_image']))   $__fcNomiImg[] = $__c['answer_image'];
    }
}
if (!empty($__fcNomiImg) && function_exists('fc_misure_carica')) fc_misure_carica($__fcNomiImg);

/* Quante carte per blocco. Trenta e' lo stesso numero che l'elenco mostrava
   gia' prima con "carica altre": cambia solo che adesso le altre non sono
   nascoste, sono da chiedere. */
define('FC_BLOCCO_CARTE', 30);

/* Si pagina solo su "tutte le materie". Non e' una scorciatoia: dentro una
   categoria le carte sono poche, il riordino a trascinamento e' acceso (e
   vuole tutte le vicine nel documento) e la ricerca locale deve poterle
   guardare tutte senza aspettare. E' anche l'unico caso in cui il problema
   esisteva davvero. */
$fcPaginato = ($categoryID == 0);

/* L'endpoint che serve i blocchi successivi. Sta qui, dopo che i dati sono
   stati letti e prima che la pagina cominci a stamparsi: risponde solo con
   l'HTML delle carte chieste e se ne va. */
if (isset($_GET['fc_feed_page'])) {
    header('Content-Type: text/html; charset=utf-8');
    $fcDa = max(0, sec_int($_GET['fc_feed_page'] ?? 0, 0));
    /* fc_feed_all=1: da qui alla fine, in una volta. Lo chiede il browser
       quando sta per fare qualcosa che ha bisogno dell'elenco intero. */
    $fcQuanti = (($_GET['fc_feed_all'] ?? '') === '1') ? 0 : FC_BLOCCO_CARTE;
    fc_stampa_gruppi($fcDa, $fcQuanti);
    exit;
}
?>

<!DOCTYPE html>

<html lang="<?php echo htmlspecialchars(standout_lingua(), ENT_QUOTES); ?>" style="--cat-color: <?= sec_h(fc_color_or_default($current_cat_color)) ?>;">

<head>

<meta charset="UTF-8">

<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=0, viewport-fit=cover">

<meta name="mobile-web-app-capable" content="yes">

<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">

<meta name="format-detection" content="telephone=no">

<meta name="theme-color" content="#F2F2F7" media="(prefers-color-scheme: light)">

<meta name="theme-color" content="#000000" media="(prefers-color-scheme: dark)">

<title><?php te('fc.vis_titolo'); ?></title>

<meta name="csrf-token" content="<?php echo sec_h(sec_csrf_token()); ?>">
<?php /* La data del file nell'indirizzo. Senza, il browser continua a servire
         la copia che ha in cache anche dopo che il file e' cambiato: e'
         esattamente cosi' che il pulsante dell'IA e' rimasto morto per giorni,
         con il file giusto sul disco e uno vecchio in esecuzione. */ ?>
<script src="../_assets/js/standout_fetch.js?v=<?php echo @filemtime(__DIR__ . '/../_assets/js/standout_fetch.js') ?: 1; ?>"></script>

<?php echo standout_i18n_json(['comune', 'fc', 'vocab', 'fcv']); ?>
<?php echo fc_boot_json(); ?>
<?php /* Formule e documento ricco esistono solo nelle flashcard di studio: le
         parole sono testo semplice, e caricare MathJax e fc_parser per niente
         vorrebbe dire rallentare ogni pagina di lingua. */ ?>
<?php if (fc_is_vocab()): ?>
<script src="<?php echo PWA_BASE; ?>fc/fc_speak.js?v=<?php echo @filemtime(__DIR__ . '/fc_speak.js') ?: 1; ?>"></script>
<?php else: ?>
<script>

    window.MathJax = {

    /* I dollari NON sono piu' delimitatori di formula.
       Col formato nuovo la formattazione non sta dentro il testo: una formula
       e' un elemento suo, e il codice LaTeX gli arriva gia' racchiuso in
       \\( \\) o \\[ \\] da fcdDisegnaFormule(). Lasciare attivo il "$"
       significherebbe che una frase come "il libro costa 5$ e il quaderno 3$"
       verrebbe presa per una formula e stampata in corsivo matematico - lo
       stesso identico bug che il formato nuovo elimina. */

    tex: {

        inlineMath: [['\\(', '\\)']],

        displayMath: [['\\[', '\\]']]

    },

    /* MathJax, appena finisce di caricarsi, disegnerebbe da solo TUTTE le
       formule del documento. Su "tutte le materie" sono migliaia, e le fa una
       dopo l'altra sul filo principale: e' per questo che restavano dei buchi
       bianchi per secondi. Qui gli si dice di non fare niente all'avvio; le
       formule gliele passiamo noi, a mano a mano che si avvicinano allo
       schermo (fcChiediDisegnoFormule). */
    startup: { typeset: false }

    };

</script>

<script id="MathJax-script" async src="https://cdn.jsdelivr.net/npm/mathjax@3/es5/tex-mml-chtml.js"></script>

<script src="fc_parser.js?v=<?php echo @filemtime(__DIR__ . '/fc_parser.js') ?: 1; ?>"></script>
<?php endif; ?>
<script src="fc_examples_modal.js?v=<?php echo @filemtime(__DIR__ . '/fc_examples_modal.js') ?: 1; ?>"></script>
<script src="<?php echo PWA_BASE; ?>fc/fc_edit_popup.js?v=<?php echo @filemtime(__DIR__ . '/fc_edit_popup.js') ?: 1; ?>"></script>



<link rel="stylesheet" href="<?php echo PWA_BASE; ?>fc/fc_edit_popup.css?v=<?php echo @filemtime(__DIR__ . '/fc_edit_popup.css') ?: 1; ?>">
<link rel="stylesheet" href="<?php echo PWA_BASE; ?>fc/fc_examples_box.css?v=<?php echo @filemtime(__DIR__ . '/fc_examples_box.css') ?: 1; ?>">
<link rel="stylesheet" href="<?php echo PWA_BASE; ?>fc/fc_view.css?v=<?php echo @filemtime(__DIR__ . '/fc_view.css') ?: 1; ?>">

</head>

<?php
/* SALTARE IL DISEGNO DI QUELLO CHE NON SI VEDE.
   Con "tutte le materie" in pagina finiscono tutte le carte dell'archivio, e
   il browser calcola l'impaginazione di ognuna anche se sta mille schermate
   piu' giu'. content-visibility gliela fa saltare finche' non serve: il
   documento resta intero (ricerca, selezione multipla e contatori continuano a
   trovare ogni carta), e' solo il disegno a diventare pigro.
   Si accende SOLO dove il riordino a trascinamento e' spento: trascinare una
   carta misura la posizione delle vicine, e una vicina mai disegnata
   risponderebbe con la propria misura di riserva invece che con quella vera. */
$fcPigra = (!$parent_reorder_enabled && count($images_data) > 60);
?>
<body class="<?= fc_is_vocab() ? 'fc-vocab' : 'fc-study' ?><?= $fcPigra ? ' fc-pigra' : '' ?>">
<script>
/* LE PREFERENZE DI VISTA, APPLICATE PRIMA CHE LA PAGINA SI DISEGNI.
   "Carte intere" toglie il ritaglio a 190px di .qa-text, e "esempi interi" fa
   lo stesso con i riquadri degli esempi: due classi sul <body> che finora
   arrivavano dentro initPage(), cioe' a pagina gia' disegnata. Con la
   preferenza accesa ogni carta nasceva ritagliata, con la sua barra laterale,
   e un istante dopo si apriva: mille carte che cambiano altezza tutte insieme
   sono ESATTAMENTE il "testo che si formatta sotto gli occhi".
   Le preferenze stanno nel browser (localStorage), quindi il server non le
   conosce e non puo' scrivere lui le classi: si leggono qui, subito dopo
   l'apertura del <body> e prima che il feed venga impaginato. E' l'unico
   punto in cui si arriva in tempo.
   fcApplicaPrefs() piu' avanti rifara' lo stesso lavoro senza conseguenze:
   toggle con un valore esplicito e' idempotente. */
(function(){
    try {
        var p = JSON.parse(localStorage.getItem('nisama_fc_vis_prefs') || '{}') || {};
        if (p.fullCards)    document.body.classList.add('fc-full-cards');
        if (p.fullExamples) document.body.classList.add('fc-full-examples');
    } catch(e){}
})();
</script>



<div id="image-overlay">

    <div id="close-overlay" onclick="closeImageZoom()">&#10005;</div>

    <img id="overlay-img" src="" alt="">

</div>



<header class="app-header" id="appHeader">

    <div class="header-top">

        <div class="cat-pill<?= $categoryID != 0 ? ' editable' : '' ?>" id="catPill" onclick="fcToccaPastiglia(event)">

            <?php if ($current_cat_icon !== '' && $current_cat_icon !== 'cartella'): ?>
            <span class="cat-pill-ico"><?= fc_icona_svg($current_cat_icon, 2.2) ?></span>
            <?php else: ?>
            <span class="cat-pill-dot"></span>
            <?php endif; ?>

            <div class="cat-pill-text">

                <div class="cat-pill-label"><?php te('vocab.cat_categoria'); ?></div>

                <div class="cat-pill-name" id="catPillName"<?php if ($categoryID != 0): ?> onclick="fcApriRinomina(event)" title="<?php te('fc.vis_rinomina'); ?>"<?php endif; ?>><?= sec_h($current_cat_name) ?></div>
                <?php if ($categoryID != 0): ?>
                <input type="text" class="cat-pill-name-input" id="catPillNameInput" maxlength="120" style="display:none;" aria-label="<?php te('fc.vis_rinomina'); ?>">
                <?php endif; ?>
                <?php if ($categoryID != 0): ?>
                    <?php if ($fc_linked_project): ?>
                    <button type="button" class="cat-proj-chip<?= !empty($fc_linked_project['via_ancestor']) ? ' via-ancestor' : '' ?>"
                            style="--cp-color:<?= sec_h($fc_linked_project['color']) ?>;"
                            onclick="event.stopPropagation(); fcApriProgetto(<?= (int)$fc_linked_project['id'] ?>);">
                        <svg viewBox="0 0 24 24" width="10" height="10" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z"/></svg>
                        <?php if (!empty($fc_linked_project['parent_name'])): ?><span class="cp-anc"><?= sec_h($fc_linked_project['parent_name']) ?></span><span class="cp-sep">•</span><?php endif; ?><span class="cp-leaf"><?= sec_h($fc_linked_project['name']) ?></span>
                    </button>
                    <?php else: ?>
                    <button type="button" class="cat-proj-chip cat-proj-link" onclick="event.stopPropagation(); fcOpenLinkProject();">
                        <svg viewBox="0 0 24 24" width="11" height="11" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
                        <?php te('fc.vis_collega_progetto'); ?>
                    </button>
                    <?php endif; ?>
                <?php endif; ?>

            </div>

            <div class="cat-pill-side">
                <span class="cat-pill-count" id="catCount"><?php if ((int)$fc_total_cards === 1) te('fc.vis_una_carta_n'); else te('fc.vis_n_carte', ['n' => (int)$fc_total_cards]); ?></span>
                <?php /* LO STORICO, DENTRO LA PASTIGLIA (20/10/2026): prima stava
                         fuori, accanto, e la testata sembrava tagliata. In un
                         mazzo apre il foglio delle sue partite; in "tutte le
                         categorie" porta allo Storico. */ ?>
                <?php if ($categoryID != 0): ?>
                <button type="button" class="hdr-partite" onclick="event.stopPropagation(); fcApriPartiteMazzo()" aria-label="<?php te('fc.vis_partite_mazzo'); ?>" title="<?php te('fc.vis_partite_mazzo'); ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                    <?php if ($fcg_n_partite > 0): ?><b><?= $fcg_n_partite > 99 ? '99+' : (int)$fcg_n_partite ?></b><?php endif; ?>
                </button>
                <?php else: ?>
                <a class="hdr-partite" href="<?= sec_h(fc_url('fc_history.php')) ?>" onclick="event.stopPropagation()" aria-label="<?php te('fc.vis_apri_storico'); ?>" title="<?php te('fc.vis_apri_storico'); ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                </a>
                <?php endif; ?>
            </div>

        </div>

    </div>



    <div class="header-row2">

        <nav class="cat-path" id="catPath" aria-label="<?php te('vocab.vi_percorso'); ?>">
            <a href="<?= sec_h(fc_url('fc_decks.php')) ?>" class="cat-path-home" aria-label="<?php te('vocab.vi_home'); ?>" title="<?php te('vocab.vi_torna_cat'); ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m3 10 9-7 9 7v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2Z"/></svg>
            </a>
            <span class="cat-path-sep" aria-hidden="true">&rsaquo;</span>
            <?php if ($categoryID == 0): ?>
            <span class="cat-path-item cat-path-current"><?php te('fc.vis_tutte_categorie'); ?></span>
            <?php else: ?>
                <?php foreach ($fc_path_ltr as $__anc): ?>
                <a class="cat-path-item" href="<?= sec_h(fc_url('fc_view.php', ['category_id' => (int)$__anc['id']])) ?>"><?= sec_h($__anc['name']) ?></a>
                <span class="cat-path-sep" aria-hidden="true">&rsaquo;</span>
                <?php endforeach; ?>
            <span class="cat-path-item cat-path-current"><?= sec_h($current_cat_name) ?></span>

            <?php /* Cambiare il padre di questa materia da qui, dove il percorso
                     si vede: e' il posto in cui ci si accorge che sta nel ramo
                     sbagliato. Prima bisognava tornare all'elenco delle materie,
                     ritrovarla e spostarla di la'. */ ?>
            <?php /* data-cs-open e non una chiamata a csOpen(): csOpen vive dentro
                     la funzione anonima di fc_deck_selector.php e da fuori NON
                     ESISTE — in console, su questa pagina, "typeof csOpen" dice
                     undefined. Il componente offre invece un gestore delegato che
                     apre il modale indicato in questo attributo, ed e' l'unico
                     modo previsto per aprirlo da un altro pezzo di pagina. */ ?>
            <button type="button" class="cat-path-edit" id="catPathEdit"
                    data-cs-open="cs-modal-catparent"
                    aria-label="<?php te('fc.vis_cambia_padre'); ?>" title="<?php te('fc.vis_cambia_padre'); ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
            </button>
            <?php endif; ?>
        </nav>

        <button type="button" class="tb-icon-btn hdr-gear" id="settingsBtn" onclick="fcApriImpostazioni()"
                aria-label="<?php te('fc.vis_impostazioni'); ?>" title="<?php te('fc.vis_impostazioni'); ?>">

            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3.1"/><path d="M19.4 15a1.6 1.6 0 0 0 .32 1.77l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.6 1.6 0 0 0-1.77-.32 1.6 1.6 0 0 0-1 1.47V21a2 2 0 1 1-4 0v-.11a1.6 1.6 0 0 0-1.05-1.46 1.6 1.6 0 0 0-1.77.32l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.6 1.6 0 0 0 .32-1.77 1.6 1.6 0 0 0-1.47-1H3a2 2 0 1 1 0-4h.11A1.6 1.6 0 0 0 4.57 9a1.6 1.6 0 0 0-.32-1.77l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.6 1.6 0 0 0 1.77.32H9a1.6 1.6 0 0 0 1-1.47V3a2 2 0 1 1 4 0v.11a1.6 1.6 0 0 0 1 1.47 1.6 1.6 0 0 0 1.77-.32l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.6 1.6 0 0 0-.32 1.77V9a1.6 1.6 0 0 0 1.47 1H21a2 2 0 1 1 0 4h-.11a1.6 1.6 0 0 0-1.47 1Z"/></svg>

        </button>

        <button type="button" class="icon-btn quick-add-btn" onclick="fcApriAggiungi()" aria-label="<?php te('fc.vis_aggiungi_fc'); ?>" title="<?php te('fc.vis_aggiungi_in_cat'); ?>">

            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>

        </button>

        <?php if ($categoryID != 0): ?>
        <?php /* Il tasto play apre il foglio "Gioca" (qui sotto, #playSheet):
                 tutto il mazzo o solo le carte difficili. Big Edit, che prima
                 aveva un tasto verde suo, sta nel foglio delle impostazioni. */ ?>
        <button type="button" class="icon-btn play-cat-btn" onclick="fcApriGioca()" aria-label="<?php te('vocab.vi_gioca'); ?>" title="<?php te('fc.vis_gioca_categoria'); ?>">
            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M8 5.14v13.72a1 1 0 0 0 1.53.85l11-6.86a1 1 0 0 0 0-1.7l-11-6.86A1 1 0 0 0 8 5.14Z"/></svg>
        </button>
        <?php endif; ?>

    </div>



    <div class="toolbar-row" id="normalToolbar">

        <div class="search-wrap">

            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>

            <input id="filterText" class="search-input" type="text" placeholder="<?php te('fc.vis_cerca'); ?>" oninput="fcOnSearchInput()">

        </div>

        <?php if (array_sum($fcv_conta_livelli) > 0): ?>
        <?php /* IL FILTRO PER LIVELLO, IN UN MENU (20/10/2026): prima era una
                 fila di pastiglie tutta sua sotto la ricerca. I livelli senza
                 carte non compaiono; il numero conta carte e sottocarte. Il
                 pallino accanto prende il colore del livello scelto. */ ?>
        <div class="tb-select-wrap tb-liv-wrap" id="fcvLivWrap" data-liv="">
            <i class="tb-liv-dot" aria-hidden="true"></i>
            <select id="fcvLivSelect" class="tb-select tb-liv" aria-label="<?php te('fcv.vis_livello'); ?>">
                <option value=""><?php te('fc.vis_tutti_livelli'); ?></option>
                <?php foreach ($fcv_conta_livelli as $liv => $n): if (!$n) continue; ?>
                <option value="<?= $liv ?>"><?php te('fcv.liv_' . $liv); ?> (<?= (int)$n ?>)</option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>

        <?php /* L'ordine e' un'icona (le frecce) con sopra il menu vero,
                 trasparente: si cambia di rado, e il testo "Posizione" toglieva
                 spazio alla ricerca. Il pallino dice che non e' quello di sempre. */ ?>
        <div class="tb-select-wrap tb-sort-ico<?= $sort !== 'custom_asc' ? ' has-dot' : '' ?>" title="<?php te('fc.vis_ordina'); ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 4v16M3.5 16.5 7 20l3.5-3.5M17 20V4M13.5 7.5 17 4l3.5 3.5"/></svg>

            <select id="filterSort" class="tb-select" onchange="changeSort()" aria-label="<?php te('fc.vis_ordina'); ?>">

                <option value="custom_asc" <?= $sort == 'custom_asc' ? 'selected' : '' ?>><?php te('fc.vis_posizione'); ?></option>

                <option value="id_desc" <?= $sort == 'id_desc' ? 'selected' : '' ?>><?php te('fc.vis_id_recenti'); ?></option>

                <option value="id_asc" <?= $sort == 'id_asc' ? 'selected' : '' ?>><?php te('fc.vis_id_meno_recenti'); ?></option>

                <option value="q_asc" <?= $sort == 'q_asc' ? 'selected' : '' ?>><?php te('fc.vis_domanda_az'); ?></option>
                <option value="livello" <?= $sort == 'livello' ? 'selected' : '' ?>><?php te('fcv.vis_piu_difficili'); ?></option>

            </select>

        </div>


        <button type="button" class="tb-icon-btn select-mode-neutro" id="selectModeBtn" onclick="enterSelectMode()"
                aria-label="<?php te('vocab.vi_seleziona'); ?>" title="<?php te('vocab.vi_seleziona'); ?>">

            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m9 12 2 2 4-4"/><rect x="3" y="3" width="18" height="18" rx="6"/></svg>

        </button>

    </div>



    <?php /* LA BARRA DELLA SELEZIONE, IN TRE GRUPPI
             Prima era una fila unica: X, Tutte, conteggio, Sposta, Unisci,
             Separa, Elimina. Sette comandi di fila, e nessun segno che
             dicesse che i primi tre parlano della SELEZIONE (chi scegliere)
             e gli altri di che cosa FARNE. Le lineette dividono le due cose,
             e dividono anche le azioni fra loro: Sposta ed Elimina agiscono
             sulle carte scelte, Unisci e Separa cambiano la struttura
             padre/figlio, che e' un altro mestiere.
             Sposta ed Elimina, con niente di selezionato, non hanno nessun
             lavoro da fare: se ne vanno e si portano dietro la loro lineetta,
             cosi' non restano due stanghette appiccicate. */ ?>
    <div class="select-toolbar" id="selectToolbar" style="display:none;">

        <button type="button" class="st-cancel" onclick="onSelectCancel()" aria-label="<?php te('fc.vis_annulla_sel'); ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
        </button>

        <button type="button" class="st-selectall" id="selectAllBtn" onclick="toggleSelectAll()"><?php te('vocab.vi_tutte'); ?></button>

        <?php /* La vista compatta e' lo stesso interruttore di
                 fc_insert.php, con le stesse tre righine: una carta
                 per riga, l'inizio della domanda e l'inizio della risposta.
                 Sta qui e non nella barra normale perche' e' una vista di
                 LAVORO — serve a vedere venti carte insieme mentre se ne
                 scelgono alcune, non a leggerle. */ ?>
        <button type="button" class="st-compact" id="compactBtn" onclick="toggleCompactMode()"
                aria-label="<?php te('fc.vis_compatta'); ?>" title="<?php te('fc.vis_compatta'); ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M4 7h16M4 12h16M4 17h16"/>
            </svg>
        </button>

        <div class="st-count" id="selectCount">0</div>

        <div class="st-actions" id="stActions">

            <?php /* PRIMA la struttura, POI le carte scelte.
                     Unisci e Separa sono i due modi in cui si ENTRA a lavorare:
                     si preme uno dei due e l'elenco cambia faccia. Sposta ed
                     Elimina invece agiscono su quello che si e' gia' scelto, e
                     con niente di scelto non ci sono nemmeno. Mettere per primo
                     il gruppo che c'e' sempre vuol dire che i pulsanti non
                     ballano: comparendo, Sposta ed Elimina si aggiungono in
                     coda invece di spingere di lato Unisci e Separa. */ ?>
            <?php /* La lineetta che c'e' SEMPRE: separa il gruppo che parla di chi
                     scegliere (X, Tutte, compatta, conteggio) da quello che parla
                     di cosa farne. L'altra, quella prima di Sposta ed Elimina, va
                     e viene con loro. */ ?>
            <span class="st-sep" aria-hidden="true"></span>

            <span class="st-group" id="stGruppoStruttura">
                <button type="button" class="st-action" data-action="join" id="btnUnisci" onclick="onBulkActionPick('join')">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><circle cx="7" cy="12" r="3"/><circle cx="17" cy="6" r="3"/><circle cx="17" cy="18" r="3"/><path d="M9.6 10.5 14.5 7.7M9.6 13.5l4.9 2.8"/></svg>
                    <?php te('vocab.vi_unisci'); ?>
                </button>

                <button type="button" class="st-action" data-action="unjoin" id="btnSepara" onclick="onBulkActionPick('unjoin')">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><circle cx="6" cy="6" r="3"/><circle cx="6" cy="18" r="3"/><path d="M20 4 8.12 15.88M14.47 14.48 20 20M8.12 8.12 12 12"/></svg>
                    <?php te('vocab.vi_separa'); ?>
                </button>
            </span>

            <span class="st-sep" id="stSepScelte" aria-hidden="true"></span>

            <span class="st-group" id="stGruppoScelte">
                <button type="button" class="st-action" data-action="move" onclick="onBulkActionPick('move')">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><path d="M21 8V6a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h6"/><path d="M17 22v-6M14 19h6"/></svg>
                    <?php te('vocab.cat_sposta_attr'); ?>
                </button>

                <button type="button" class="st-action danger" data-action="delete" onclick="onBulkActionPick('delete')">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0-1 14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2L4 6"/></svg>
                    <?php te('comune.elimina'); ?>
                </button>
            </span>

        </div>

    </div>

    <?php /* Una riga che dice cosa sta succedendo. Le modalita' Separa e
             Unisci cambiano le regole sotto i piedi — spariscono delle carte,
             certe caselle non ci sono piu' — e senza una frase che lo dica
             sembra che la pagina si sia rotta. */ ?>
    <div class="join-banner mode-banner" id="modeBanner" style="display:none;">
        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>
        <span id="modeBannerText"></span>
    </div>

    <?php /* LA RICERCA MENTRE SI SCEGLIE.
             Entrando nella selezione la barra con la ricerca spariva, e per
             unire due carte bisognava trovare il padre scorrendo tutto
             l'elenco. Adesso la ricerca resta: è la stessa di sopra (stesso
             filtro, stesso testo), e le carte già scelte restano sempre in
             vista anche se non corrispondono. */ ?>
    <div class="toolbar-row sel-search-row" id="selSearchRow" style="display:none;">
        <div class="search-wrap">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
            <input id="selSearch" class="search-input" type="text" enterkeyhint="search" autocomplete="off" placeholder="<?php te('fc.vis_cerca'); ?>" oninput="fcCercaInSelezione(this.value)">
        </div>
    </div>

</header>

<script>
/* L'ALTEZZA DELL'INTESTAZIONE, MISURATA SUBITO.
   .feed si stacca dall'alto di --header-h, che in CSS parte da un valore di
   riserva (150px) e veniva corretto solo a pagina caricata, dentro initPage().
   Quando l'intestazione vera e' piu' alta — e con il percorso della materia e
   la riga di ricerca lo e' quasi sempre — al primo disegno il contenuto stava
   troppo in su, mezzo nascosto sotto la barra, e un istante dopo scendeva
   tutto di colpo: e' per questo che il contatore "N carte mostrate" sembrava
   comparire dal nulla, quando in realta' stava li' da subito ma coperto.
   Questo blocco sta apposta QUI, subito dopo </header> e prima di <main>: il
   browser ha gia' impaginato l'intestazione e non ha ancora impaginato il
   feed, quindi la misura e' vera e arriva in tempo per il primo disegno.
   syncHeaderHeight() piu' avanti resta come correzione, per quando cambia
   qualcosa (rotazione dello schermo, un carattere che finisce di caricarsi). */
(function(){
    var h = document.getElementById('appHeader');
    if (h) document.documentElement.style.setProperty('--header-h', h.offsetHeight + 'px');
})();
</script>



<?php include __DIR__ . '/../partials/sidebar.html'; ?>



<main class="feed">

    <div class="feed-inner">

        <?php if ($categoryID != 0):
            /* LA STRISCIA IN CIMA (20/10/2026): l'esame (di questo mazzo o, se
               non ne ha, del piu' vicino fra quelli che lo contengono) e il
               tasto per giocare solo le carte difficili del ramo. */
            $fcs_esame = $fc_cat_by_id[$categoryID]['exam_date'] ?? null;
            $fcs_esame_di = '';
            if (!$fcs_esame) foreach ($fc_ancestors as $__a) { if (!empty($__a['exam_date'])) { $fcs_esame = $__a['exam_date']; $fcs_esame_di = $__a['name']; break; } }
            $fcs_giorni = null;
            if ($fcs_esame) { $fcs_giorni = (int)round((strtotime(substr($fcs_esame, 0, 10)) - strtotime(date('Y-m-d'))) / 86400); if ($fcs_giorni < 0) $fcs_giorni = null; }
            $fcs_diff = (int)($fcv_conta_livelli['difficile'] ?? 0);
            if ($fcs_giorni !== null): ?>
        <div class="deck-strip">
            <?php if ($fcs_giorni !== null): ?>
            <span class="ds-esame<?= $fcs_giorni <= 3 ? ' is-urgente' : ($fcs_giorni <= 14 ? ' is-vicino' : '') ?>" title="<?= sec_h(date('d/m/Y', strtotime($fcs_esame))) ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="16" rx="3"/><path d="M8 3v4M16 3v4M3 10h18"/></svg>
                <span><b><?= sec_h($fcs_giorni === 0 ? t('fc.dk_esame_oggi') : ($fcs_giorni === 1 ? t('fc.dk_esame_domani') : t('fc.dk_esame_tra', ['n' => $fcs_giorni]))) ?></b><?php if ($fcs_esame_di !== ''): ?> · <?= sec_h($fcs_esame_di) ?><?php endif; ?></span>
            </span>
            <?php endif; ?>
        </div>
            <?php endif; ?>
        <?php /* LE NOTE DEL MAZZO: un foglietto in cima, che si scrive al
                 tocco e si salva senza ricaricare (fc_deck_notes, piu' su).
                 Senza note resta solo un invito discreto ad aggiungerne. */ ?>
        <section class="deck-note<?= $current_cat_notes === '' ? ' is-vuota' : '' ?>" id="deckNote">
            <button type="button" class="dn-add" onclick="fcNoteMazzoApri()">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9Z"/><path d="M14 3v6h6M12 12v6M9 15h6"/></svg>
                <span><?php te('fc.dn_aggiungi'); ?></span>
            </button>
            <div class="dn-card">
                <div class="dn-head">
                    <span class="dn-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9Z"/><path d="M14 3v6h6M8 13h8M8 17h5"/></svg></span>
                    <span class="dn-title"><?php te('fc.dn_titolo'); ?></span>
                    <button type="button" class="dn-edit" onclick="fcNoteMazzoApri()" aria-label="<?php te('comune.modifica'); ?>" title="<?php te('comune.modifica'); ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                    </button>
                </div>
                <div class="dn-testo" id="deckNoteText"><?= sec_h($current_cat_notes) ?></div>
                <button type="button" class="dn-altro" id="deckNoteMore" onclick="fcNoteMazzoEspandi()"><?php te('fc.dn_mostra_tutto'); ?></button>
                <div class="dn-form">
                    <textarea id="deckNoteInput" maxlength="5000" rows="4" placeholder="<?php te('fc.dn_placeholder'); ?>"></textarea>
                    <div class="dn-azioni">
                        <span class="dn-conta" id="deckNoteCount"></span>
                        <button type="button" class="dn-btn" onclick="fcNoteMazzoChiudi()"><?php te('comune.annulla'); ?></button>
                        <button type="button" class="dn-btn primario" onclick="fcNoteMazzoSalva()"><?php te('comune.salva'); ?></button>
                    </div>
                </div>
            </div>
        </section>
        <?php endif; ?>



        <?php if (!empty($subcategories)): ?>

        <div class="fc-subcats">

            <div class="fc-subcats-label">

                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z"/></svg>

                <?php te('vocab.vi_sottocategorie'); ?>

            </div>

            <div class="fc-subcats-grid">

                <?php foreach ($subcategories as $sc):

                    $scColor = fc_color_or_default($sc['color']);

                    $scCardCount = isset($subcat_card_counts[$sc['id']]) ? (int)$subcat_card_counts[$sc['id']] : 0;

                    $scFolderCount = isset($subcat_children_count[$sc['id']]) ? (int)$subcat_children_count[$sc['id']] : 0;

                ?>

                <a href="<?= sec_h(fc_url('fc_view.php', ['category_id' => (int)$sc['id']])) ?>" class="fc-subcat-card" style="--sc-color: <?= sec_h($scColor) ?>;">

                    <span class="fc-subcat-dot">

                        <?= fc_icona_svg(fc_icona_mazzo_di($sc), 2.2) ?>

                    </span>

                    <div class="fc-subcat-name"><?= sec_h($sc['name']) ?></div>

                    <div class="fc-subcat-meta">

                        <span title="<?php te('fc.vis_n_carte', ['n' => (int)$scCardCount]); ?>">

                            <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="7" width="13" height="10" rx="2.2"/><path d="M7.5 7V5a2 2 0 0 1 2-2h7a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2h-2"/></svg>

                            <?= sec_h($scCardCount) ?>

                        </span>

                        <?php if ($scFolderCount > 0): ?>

                        <span title="<?php te('fc.vis_n_sottocategorie', ['n' => (int)$scFolderCount]); ?>">

                            <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h3.5l1.5 2H19a2 2 0 0 1 2 2v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z"/></svg>

                            <?= sec_h($scFolderCount) ?>

                        </span>

                        <?php endif; ?>

                    </div>

                </a>

                <?php endforeach; ?>

            </div>

        </div>

        <?php endif; ?>



        <?php /* Il numero parte gia' giusto. Prima era uno zero fisso che il
                 JavaScript correggeva subito dopo: per una frazione di secondo
                 la pagina diceva "0 carte mostrate" sopra un elenco che stava
                 per riempirsi — la prima cosa che si legge, ed e' falsa.
                 Il conto qui e' quello di tutte le carte della materia, che e'
                 anche quello vero all'apertura: i filtri partono tutti aperti,
                 e appena qualcuno li tocca ci pensa filterList(). */ ?>
        <div class="item-counter" id="itemCounter"><span class="ic-dot"></span> <?php
            $__mostrate = 0; $__visti = 0;
            foreach ($images_data as $__r) {
                $__visti++;
                /* Con l'elenco paginato il numero giusto all'apertura non e'
                   il totale dell'archivio ma quello del primo blocco: e'
                   quello che si sta guardando, ed e' anche quello che
                   filterList() scrivera' un istante dopo. Stampare il totale
                   voleva dire far comparire 1051 e poi vederlo crollare a 30. */
                if ($fcPaginato && $__visti > FC_BLOCCO_CARTE) break;
                $__mostrate += 1 + count($__r['children']);
            }
            th($__mostrate === 1 ? 'fc.vis_una_carta_mostrata' : 'fc.vis_n_carte_mostrate', ['n' => '<b id="itemCounterNum">' . (int)$__mostrate . '</b>']);
        ?></div>



        <form id="bulkForm" method="POST">

            <input type="hidden" name="csrf_token" value="<?php echo sec_h(sec_csrf_token()); ?>"><?php echo fc_campo_tipo(); ?>

            <input type="hidden" name="bulk_action" id="hiddenBulkAction">

            <input type="hidden" name="target_category" id="hiddenTargetCategory">

            <?php /* Il padre scelto arriva qui. Prima ci arrivava attraverso dei
                     radio creati al volo al posto delle caselle: si sceglieva, poi
                     si confermava con un pulsante in fondo allo schermo — due gesti
                     per una cosa sola. Ora si tocca la carta e basta, come in
                     fc_insert.php, e il valore lo scrive qui il
                     JavaScript. */ ?>
            <input type="hidden" name="father_id" id="hiddenFatherId">

            <?php /* L'unica maniglia prevista da fc_deck_selector.php per aprire
                     un modale da fuori e' un tocco su un elemento con
                     data-cs-open. Questo esiste solo per riceverlo: non si vede,
                     non e' raggiungibile a mano, e lo "preme" il JavaScript. */ ?>
            <button type="button" id="apriModaleMove" data-cs-open="cs-modal-move" hidden aria-hidden="true"></button>



            <?php if (count($images_data) > 0): ?>

            <div class="fc-list" id="fcList">
                <?php fc_stampa_gruppi(0, $fcPaginato ? FC_BLOCCO_CARTE : 0); ?>
            </div>
            
            <?php if ($categoryID == 0): ?>
                <button type="button" id="loadMoreBtn" style="display: none; width: calc(100% - 24px); margin: 0 auto 20px auto; padding: 14px; background: var(--surface); color: var(--blue); border: none; border-radius: var(--r-btn); font-size: 15.5px; font-weight: 800; cursor: pointer; box-shadow: var(--shadow-card), inset 0 0 0 .5px var(--hairline); transition: transform .15s var(--spring); text-align: center;" onclick="loadMoreCards()" onmousedown="this.style.transform='scale(.97)'" onmouseup="this.style.transform='scale(1)'" onmouseleave="this.style.transform='scale(1)'">
                    <?php te('fc.vis_carica_altre'); ?>
                </button>
            <?php endif; ?>

            <?php else: ?>

            <div class="empty-state">

                <div class="es-icon">🗂️</div>

                <h3><?php te('fc.vis_nessuna_carta'); ?></h3>

                <p><?php te('fc.vis_nessuna_carta_txt'); ?></p>

            </div>

            <?php endif; ?>

        </form>

        <div class="fc-deep" id="fcDeep" hidden></div>

    </div>

</main>



<?php /* La barra "Confermo il padre" non c'e' piu': il padre si sceglie
         toccando la sua carta, e un gesto che vale da solo non ha bisogno di
         essere confermato da un secondo gesto in fondo allo schermo. Restano
         le regole di prima su chi puo' diventare padre — le controlla
         onBulkActionPick prima di far partire la scelta. */ ?>



<div class="sheet-overlay" id="deleteSheet">

    <div class="sheet-panel">

        <h3 id="deleteSheetTitle"><?php te('fc.vis_eliminare_carta'); ?></h3>

        <p class="sheet-sub" id="deleteSheetSub"><?php te('fc.vis_non_annullabile'); ?></p>

        <div class="sheet-warn" id="deleteSheetWarn"></div>

        <div class="sheet-actions">

            <button type="button" class="sheet-btn" onclick="closeSheet('deleteSheet')"><?php te('comune.annulla'); ?></button>

            <button type="button" class="sheet-btn danger" id="deleteSheetConfirm"><?php te('comune.elimina'); ?></button>

        </div>

    </div>

</div>



<?php /* Lo spostamento usa ora fc_deck_selector.php invece di un elenco
         piatto: le materie sono un albero con sottocategorie, e un elenco
         piatto le mostrava tutte allo stesso livello, senza ricerca e senza
         far capire dove si stesse spostando. Il selettore parte gia' aperto
         sulla categoria in cui ci si trova, che e' quasi sempre il punto da
         cui si vuole spostare di poco. */
       $cs_id = 'move';
       $cs_selected_id = (int)$categoryID;
       $cs_selected_name = $current_cat_name ?? '';
       $cs_title = t('fc.vis_sposta_dove');
       $cs_placeholder = t('fc.vis_sposta_dove');
       $cs_hidden_name = 'cs_move_target';
       $cs_render_trigger = false;
       $cs_allow_create = true;
       include __DIR__ . '/fc_deck_selector.php';
       unset($cs_id, $cs_selected_id, $cs_selected_name, $cs_title,
             $cs_placeholder, $cs_hidden_name, $cs_render_trigger, $cs_allow_create); ?>

<?php /* Il selettore del PADRE di questa materia, aperto dalla matitina nel
         percorso. Parte gia' srotolato sul padre attuale — lo fa da se'
         fc_deck_selector.php, leggendo l'id nel campo nascosto — cosi' si
         vede subito da dove si sta spostando.
         "Nessuna" c'e' apposta: una materia deve poter tornare in cima
         all'albero, e senza quella voce l'unico modo sarebbe passare da
         un'altra pagina. */
       if ($categoryID > 0):
       $cs_id = 'catparent';
       /* Srotolato fino a QUESTA materia, non fino al padre. Chi apre il
          selettore vuole prima di tutto ritrovarsi: vedere dove si e' adesso,
          con i rami aperti fin li', e poi decidere dove andare. Fermarsi al
          padre mostra un pezzo di albero in cui la materia di cui si sta
          parlando non compare — e la voce evidenziata sarebbe il padre, cioe'
          proprio la cosa che si vuole cambiare. */
       $cs_selected_id = (int)$categoryID;
       $cs_selected_name = $current_cat_name ?? '';
       $cs_title = t('fc.cs_categoria_padre_t');
       $cs_placeholder = t('fc.cs_nessuna_padre');
       $cs_hidden_name = 'cs_cat_parent';
       $cs_render_trigger = false;
       $cs_allow_create = false;
       $cs_show_all_option = true;
       $cs_all_option_label = t('fc.cs_nessuna_padre');
       include __DIR__ . '/fc_deck_selector.php';
       unset($cs_id, $cs_selected_id, $cs_selected_name, $cs_title,
             $cs_placeholder, $cs_hidden_name, $cs_render_trigger, $cs_allow_create,
             $cs_show_all_option, $cs_all_option_label);
       endif; ?>




<!-- ============ IMPOSTAZIONI DELLA VISTA ============ -->
<?php if ($categoryID != 0): ?>
<!-- ============ PARTITE CON QUESTO MAZZO ============ -->
<div class="sheet-overlay" id="partiteSheet" data-materia="<?= (int)$categoryID ?>" data-nome="<?= sec_h($current_cat_name) ?>">
    <div class="sheet-panel">
        <h3><?php te('fc.vis_partite_mazzo'); ?></h3>
        <p class="sheet-sub"><?php te('fc.vis_partite_mazzo_sub'); ?></p>
        <div class="pm-lista" id="pmLista"><div class="pm-vuoto"><?php te('fc.vis_caricamento_carte'); ?></div></div>
        <div class="sheet-actions pm-azioni">
            <a class="sheet-btn pm-gioca" href="<?= sec_h(fc_url('fc_game_setup.php', ['preselect_category' => (int)$categoryID])) ?>">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M7 4v16l13-8z"/></svg>
                <?php te('fc.vis_gioca_mazzo'); ?>
            </a>
            <a class="sheet-btn" href="<?= sec_h(fc_url('fc_history.php', ['materia' => (int)$categoryID, 'materia_nome' => $current_cat_name])) ?>"><?php te('fc.vis_apri_storico'); ?></a>
        </div>
    </div>
</div>
<?php endif; ?>
<div class="sheet-overlay" id="settingsSheet">
    <div class="sheet-panel">
        <h3><?php te('fc.vis_impostazioni'); ?></h3>
        <p class="sheet-sub"><?php te('fc.vis_impostazioni_txt'); ?></p>

        <div class="opt-group">
            <div class="opt-group-label"><?php te('fc.vis_quali_carte'); ?></div>
            <div class="opt-row">
                <div class="opt-txt">
                    <div class="opt-title"><?php te('fc.vis_filtro'); ?></div>
                </div>
                <div class="opt-select-wrap">
                    <select id="filterStatus" class="opt-select" onchange="fcSalvaImpostazioni(); visibleLimit=30; filterList();">
                        <option value="all"><?php te('fc.vis_tutte_carte'); ?></option>
                        <option value="not_learnt"><?php te('vocab.vi_da_imparare'); ?></option>
                        <option value="learnt"><?php te('vocab.vi_imparate'); ?></option>
                        <option value="duplicates"><?php te('vocab.vi_duplicati'); ?></option>
                    </select>
                </div>
            </div>
        </div>

        <div class="opt-group">
            <div class="opt-group-label"><?php te('fc.vis_come_mostrarle'); ?></div>
            <div class="opt-row">
                <div class="opt-txt">
                    <div class="opt-title"><?php te('fc.vis_carte_intere'); ?></div>
                    <div class="opt-sub"><?php te('fc.vis_carte_intere_txt'); ?></div>
                </div>
                <label class="opt-switch">
                    <input type="checkbox" id="optFullCards" onchange="fcSalvaImpostazioni()">
                    <span class="opt-track"><span class="opt-knob"></span></span>
                </label>
            </div>
            <div class="opt-row">
                <div class="opt-txt">
                    <div class="opt-title"><?php te('fc.vis_esempi_interi'); ?></div>
                    <div class="opt-sub"><?php te('fc.vis_esempi_interi_txt'); ?></div>
                </div>
                <label class="opt-switch">
                    <input type="checkbox" id="optFullExamples" onchange="fcSalvaImpostazioni()">
                    <span class="opt-track"><span class="opt-knob"></span></span>
                </label>
            </div>
        </div>

        <?php if ($categoryID != 0): ?>
        <div class="opt-group">
            <div class="opt-group-label"><?php te('fc.vis_strumenti'); ?></div>
            <a class="opt-row opt-link" href="<?= sec_h(fc_url('fc_insert.php', ['category_id' => (int)$categoryID, 'modo' => 'edit'])) ?>">
                <span class="opt-link-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg></span>
                <div class="opt-txt">
                    <div class="opt-title"><?php te('fc.vis_big_edit'); ?></div>
                    <div class="opt-sub"><?php te('fc.vis_big_edit_txt'); ?></div>
                </div>
                <svg class="opt-link-go" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m9 6 6 6-6 6"/></svg>
            </a>
        </div>
        <?php endif; ?>

        <div class="sheet-actions">
            <button type="button" class="sheet-btn" onclick="closeSheet('settingsSheet')"><?php te('comune.chiudi'); ?></button>
        </div>
    </div>
</div>

<?php if ($categoryID != 0): $fcp_diff = (int)($fcv_conta_livelli['difficile'] ?? 0); ?>
<!-- ============ GIOCA (20/10/2026) ============
     Il tasto play della testata: tutto il mazzo (la scelta della partita,
     come prima) o subito solo le carte difficili del mazzo e dei sottomazzi. -->
<div class="sheet-overlay" id="playSheet">
    <div class="sheet-panel">
        <h3><?php te('fc.vis_gioca_titolo'); ?></h3>
        <p class="sheet-sub"><?= sec_h($current_cat_name) ?></p>
        <div class="play-list">
            <a class="play-opt" href="<?= sec_h(fc_url('fc_game_setup.php', ['preselect_category' => (int)$categoryID])) ?>">
                <span class="po-ic po-tutte"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M8 5.14v13.72a1 1 0 0 0 1.53.85l11-6.86a1 1 0 0 0 0-1.7l-11-6.86A1 1 0 0 0 8 5.14Z"/></svg></span>
                <span class="po-txt">
                    <span class="po-tit"><?php te('fc.vis_gioca_tutto'); ?></span>
                    <span class="po-sub"><?php te('fc.vis_gioca_tutto_txt'); ?></span>
                </span>
                <svg class="po-go" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m9 6 6 6-6 6"/></svg>
            </a>
            <?php if ($fcp_diff > 0): ?>
            <a class="play-opt" href="<?= sec_h(fc_url('fc_game_start.php', ['category_id' => implode(',', array_merge([(int)$categoryID], array_keys($fc_discendenti))), 'status' => 'all', 'order' => 'random', 'solo' => 'difficili'])) ?>">
                <span class="po-ic po-difficili"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.4-.5-2-1-3-1-2.1-.2-4.1 2-6 .5 2.5 2 4.9 4 6.5s3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.2.4-2.3 1-3.3a2.5 2.5 0 0 0 2.5 2.8Z"/></svg></span>
                <span class="po-txt">
                    <span class="po-tit"><?php te('fc.vis_gioca_difficili', ['n' => $fcp_diff]); ?></span>
                    <span class="po-sub"><?php te('fc.vis_gioca_difficili_txt'); ?></span>
                </span>
                <svg class="po-go" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m9 6 6 6-6 6"/></svg>
            </a>
            <?php endif; ?>
            <button type="button" class="play-opt" onclick="closeSheet('playSheet'); fcApriPartiteMazzo();">
                <span class="po-ic po-storico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg></span>
                <span class="po-txt">
                    <span class="po-tit"><?php te('fc.vis_partite_mazzo'); ?></span>
                    <span class="po-sub"><?= $fcg_n_partite === 1 ? sec_h(t('fc.dk_partite_1')) : sec_h(t('fc.dk_partite', ['n' => (int)$fcg_n_partite])) ?></span>
                </span>
                <svg class="po-go" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m9 6 6 6-6 6"/></svg>
            </button>
        </div>
        <div class="sheet-actions">
            <button type="button" class="sheet-btn" onclick="closeSheet('playSheet')"><?php te('comune.annulla'); ?></button>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ============ COSA VUOI AGGIUNGERE? ============ -->
<div class="sheet-overlay" id="addSheet">
    <div class="sheet-panel">
        <h3><?php te('fc.vis_cosa_aggiungere'); ?></h3>
        <p class="sheet-sub"><?php te('fc.vis_cosa_aggiungere_txt'); ?></p>
        <div class="pick-grid">
            <button type="button" class="pick-tile" onclick="fcAggiungiCarta()">
                <span class="pt-ic">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="7" width="13" height="10" rx="2.2"/><path d="M7.5 7V5a2 2 0 0 1 2-2h7a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2h-2"/></svg>
                </span>
                <span class="pt-lab"><?php te('fc.vis_una_carta'); ?></span>
                <span class="pt-sub"><?php te('fc.vis_una_carta_txt'); ?></span>
            </button>
            <button type="button" class="pick-tile" onclick="fcApriNuovaCartella()">
                <span class="pt-ic">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z"/><path d="M12 11v5M9.5 13.5h5"/></svg>
                </span>
                <span class="pt-lab"><?php te('fc.vis_una_cartella'); ?></span>
                <span class="pt-sub"><?php te('fc.vis_una_cartella_txt'); ?></span>
            </button>
        </div>
        <div class="sheet-actions">
            <button type="button" class="sheet-btn" onclick="closeSheet('addSheet')"><?php te('comune.annulla'); ?></button>
        </div>
    </div>
</div>

<!-- ============ NUOVA CARTELLA ============ -->
<div class="sheet-overlay" id="newFolderSheet">
    <div class="sheet-panel">
        <h3><?php te('fc.vis_nuova_cartella'); ?></h3>
        <p class="sheet-sub" id="newFolderSub"></p>

        <input type="text" id="newFolderName" class="nf-input" maxlength="120"
               placeholder="<?php te('fc.vis_nome_cartella'); ?>" autocomplete="off">

        <div class="nf-color-head">
            <span class="nf-color-dot" id="newFolderDot"></span>
            <span class="nf-color-name" id="newFolderColorName"></span>
        </div>

        <div class="nf-grid" id="newFolderGrid">
            <?php /* Prima opzione sempre il colore ereditato dal padre: e' il
                     comportamento normale delle categorie, quello scelto a mano
                     e' l'eccezione. */ ?>
            <button type="button" class="nf-sw nf-sw-auto selected" data-auto="1" onclick="fcPickerAuto('cartella')"
                    title="<?php te('fc.vis_colore_default'); ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 9-9"/><path d="M3 4v8h8"/></svg>
            </button>
            <?php foreach ($colorPalette as $__ci => $__hex): ?>
            <button type="button" class="nf-sw" style="--sw:<?= sec_h($__hex) ?>;" data-hex="<?= sec_h($__hex) ?>"
                    onclick="fcPickerManuale('cartella', '<?= sec_h($__hex) ?>')">
                <svg class="nf-check" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
            </button>
            <?php endforeach; ?>
        </div>

        <div class="nf-custom">
            <input type="color" id="newFolderCustom" value="#007AFF" oninput="fcPickerManuale('cartella', this.value)">
            <span class="nf-custom-lab"><?php te('vocab.cat_colore_pers'); ?></span>
            <span class="nf-custom-hex" id="newFolderHex">#A0A0A0</span>
        </div>

        <div class="sheet-actions">
            <button type="button" class="sheet-btn" onclick="closeSheet('newFolderSheet')"><?php te('comune.annulla'); ?></button>
            <button type="button" class="sheet-btn primary" id="newFolderSave" onclick="fcCreaCartella()"><?php te('comune.salva'); ?></button>
        </div>
    </div>
</div>



<!-- ============ COLORE DELLA CATEGORIA ============ -->
<div class="sheet-overlay" id="catColorSheet">
    <div class="sheet-panel">
        <h3><?php te('fc.vis_colore_categoria'); ?></h3>
        <p class="sheet-sub"><?php te('fc.vis_colore_categoria_txt'); ?></p>

        <div class="nf-color-head">
            <span class="nf-color-dot" id="catColorDot"></span>
            <span class="nf-color-name" id="catColorName"></span>
        </div>

        <div class="nf-grid" id="catColorGrid">
            <button type="button" class="nf-sw nf-sw-auto selected" data-auto="1" onclick="fcPickerAuto('categoria')"
                    title="<?php te('fc.vis_colore_default'); ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 9-9"/><path d="M3 4v8h8"/></svg>
            </button>
            <?php foreach ($colorPalette as $__ci2 => $__hex2): ?>
            <button type="button" class="nf-sw" style="--sw:<?= sec_h($__hex2) ?>;" data-hex="<?= sec_h($__hex2) ?>"
                    onclick="fcPickerManuale('categoria', '<?= sec_h($__hex2) ?>')">
                <svg class="nf-check" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
            </button>
            <?php endforeach; ?>
        </div>

        <div class="nf-custom">
            <input type="color" id="catColorCustom" value="#007AFF" oninput="fcPickerManuale('categoria', this.value)">
            <span class="nf-custom-lab"><?php te('vocab.cat_colore_pers'); ?></span>
            <span class="nf-custom-hex" id="catColorHex">#A0A0A0</span>
        </div>

        <div class="sheet-actions">
            <button type="button" class="sheet-btn" onclick="closeSheet('catColorSheet')"><?php te('comune.annulla'); ?></button>
            <button type="button" class="sheet-btn primary" id="catColorSave" onclick="fcSalvaColoreCategoria()"><?php te('comune.salva'); ?></button>
        </div>
    </div>
</div>

<!-- ============ RIEPILOGO DEL PROGETTO ============ -->
<div class="sheet-overlay" id="projectSheet">
    <div class="sheet-panel prj-panel">
        <div class="prj-body" id="prjBody"></div>
        <div class="sheet-actions">
            <button type="button" class="sheet-btn" onclick="closeSheet('projectSheet')"><?php te('comune.chiudi'); ?></button>
            <a class="sheet-btn primary prj-go" id="prjGo" href="../projects/progetti.php"><?php te('fc.vis_prj_apri'); ?></a>
        </div>
    </div>
</div>



<!-- ============ VISTA A FLASHCARD (una carta per volta) ============ -->
<button type="button" class="fcv-fab" id="fcvFab" onclick="fcvApri()"
        aria-label="<?php te('fc.vis_fcv_apri'); ?>" title="<?php te('fc.vis_fcv_apri'); ?>">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="7" width="14" height="13" rx="3"/><path d="M8.5 4H19a2 2 0 0 1 2 2v10.5"/></svg>
</button>

<div id="fcv" class="fcv" aria-hidden="true">
    <div class="fcv-back" id="fcvBack"></div>

    <header class="fcv-top">
        <button type="button" id="fcvClose" class="fcv-tb" aria-label="<?php te('comune.chiudi'); ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
        </button>
        <div class="fcv-count"><span id="fcvIdx">1</span> / <span id="fcvTot">1</span></div>
        <?php if ($categoryID != 0): ?>
        <a class="fcv-tb fcv-play" href="<?= sec_h(fc_url('fc_game_setup.php', ['preselect_category' => (int)$categoryID])) ?>"
           aria-label="<?php te('vocab.vi_gioca'); ?>" title="<?php te('vocab.vi_gioca'); ?>">
            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M8 5.14v13.72a1 1 0 0 0 1.53.85l11-6.86a1 1 0 0 0 0-1.7l-11-6.86A1 1 0 0 0 8 5.14Z"/></svg>
        </a>
        <?php else: ?>
        <span class="fcv-tb fcv-ghost" aria-hidden="true"></span>
        <?php endif; ?>
    </header>

    <div class="fcv-progress"><span id="fcvBar"></span></div>
    <?php /* Seconda barra, solo per le sotto-carte: dice a che punto si e'
             fra i FRATELLI, non nell'intero mazzo. Sui padri non sparisce, si
             limita a diventare invisibile (classe is-off): tolta dal flusso
             farebbe scendere e risalire le carte a ogni cambio. */ ?>
    <div class="fcv-progress fcv-progress-sib is-off" id="fcvBarSibWrap"><span id="fcvBarSib"></span></div>

    <div class="fcv-stage" id="fcvStage">
        <div class="fcv-card" id="fcvCard">
            <div class="fcv-inner" id="fcvInner">
                <div class="fcv-face fcv-front">
                    <?php /* Le prime righe della domanda del padre: uguale per
                             tutti i fratelli, fa da titolo del gruppo e riporta
                             alla carta padre. Nascosta sui padri. */ ?>
                    <button type="button" class="fcv-parent-strip" id="fcvParent" hidden>
                        <span class="fcv-parent-lab">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
                            <?php te('fc.vis_fcv_carta_padre'); ?>
                        </span>
                        <span class="fcv-parent-txt" id="fcvParentTxt"></span>
                    </button>
                    <div class="fcv-eyebrow"><?php te('fc.ifc_domanda'); ?></div>
                    <div class="fcv-body" id="fcvQ"></div>
                    <div class="fcv-hint"><?php te('fc.res_tocca_girare'); ?></div>
                </div>
                <div class="fcv-face fcv-backface">
                    <?php /* Stessa fascia della domanda, sul retro: prima compariva
                             solo davanti, e girando la carta il contesto "di chi e'
                             figlia" spariva — proprio quando serve di piu', leggendo
                             una risposta senza piu' la domanda sotto gli occhi. */ ?>
                    <button type="button" class="fcv-parent-strip" id="fcvParentA" hidden>
                        <span class="fcv-parent-lab">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
                            <?php te('fc.vis_fcv_carta_padre'); ?>
                        </span>
                        <span class="fcv-parent-txt" id="fcvParentTxtA"></span>
                    </button>
                    <div class="fcv-eyebrow ans"><?php te('fc.ifc_risposta'); ?></div>
                    <div class="fcv-body" id="fcvA"></div>
                </div>
            </div>
            <?php /* Fuori da .fcv-inner: cosi' il tastino resta fermo mentre la
                     carta gira, invece di ritrovarsi specchiato sul retro. */ ?>
            <button type="button" class="fcv-edit" id="fcvEdit"
                    aria-label="<?php te('comune.modifica'); ?>" title="<?php te('comune.modifica'); ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
            </button>
        </div>
    </div>

    <div class="fcv-nav">
        <button type="button" id="fcvPrev" class="fcv-nb" aria-label="<?php te('fc.res_precedente'); ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
        </button>
        <button type="button" id="fcvFlip" class="fcv-nb fcv-nb-main" aria-label="<?php te('fc.res_gira'); ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-2.6-6.3"/><path d="M21 3v5h-5"/></svg>
        </button>
        <button type="button" id="fcvNext" class="fcv-nb" aria-label="<?php te('fc.res_successiva'); ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>
        </button>
    </div>
</div>


<!-- ============ ESEMPIO A TUTTO SCHERMO ============ -->
<?php /* Vive fuori dal visore a flashcard e con uno z-index piu' alto: si apre
         sia dall'elenco sia da dentro il visore, e nel secondo caso deve
         starci sopra. */ ?>
<?php /* Il riquadrino del titolo, aperto dalla striscia in cima a una sottocarta
         quando il suo padre e' un titolo. Mostra il titolo e basta: non c'e'
         una risposta da girare, non c'e' niente da studiare qui: e' l'etichetta
         del gruppo. L'unica cosa che ha senso farci e' correggerla, e c'e' il
         tasto per farlo. */ ?>
<?php /* La conferma dello spostamento di una materia. Piccola apposta: e' una
         domanda sola, e una domanda sola non ha bisogno di una schermata. */ ?>
<?php /* La conferma e' uno SCHEMA, non una frase.
         Prima era un periodo con due nomi di materia infilati dentro fra
         virgolette: per capirlo bisognava leggerlo tutto e ricostruire da
         soli chi va dentro chi — proprio la cosa su cui si vuole essere
         sicuri prima di premere. Qui le due materie sono una sopra l'altra,
         ciascuna col SUO colore (lo stesso con cui si e' abituati a
         riconoscerle nell'albero), e in mezzo una freccia che dice il verso.
         Si legge in un colpo d'occhio e non si puo' invertire per sbaglio.
         La conseguenza — che si porta dietro tutto il ramo — resta scritta,
         in corsivo e sotto: e' la parte che si legge dopo aver capito il
         movimento, non prima. */ ?>
<div class="tit-modal" id="catMoveConfirm" aria-hidden="true">
    <div class="tit-modal-back" id="catMoveConfirmBack"></div>
    <div class="tit-modal-panel">
        <div class="tit-modal-lab"><?php te('fc.vis_cambia_padre'); ?></div>

        <div class="cat-move-schema">
            <span class="cat-move-verbo"><?php te('fc.vis_sposta_verbo'); ?></span>
            <span class="cat-move-chip" id="catMoveDa"></span>
            <span class="cat-move-freccia" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 4v14"/><path d="m6 12 6 6 6-6"/></svg>
                <span class="cat-move-dentro"><?php te('fc.vis_sposta_dentro'); ?></span>
            </span>
            <span class="cat-move-chip" id="catMoveA"></span>
        </div>

        <div class="cat-move-nota" id="catMoveConfirmTxt"></div>

        <div class="cat-move-azioni">
            <button type="button" class="cat-move-no" id="catMoveConfirmNo"><?php te('comune.annulla'); ?></button>
            <button type="button" class="tit-modal-edit" id="catMoveConfirmOk"><?php te('comune.conferma'); ?></button>
        </div>
    </div>
</div>

<div class="tit-modal" id="titModal" aria-hidden="true">
    <div class="tit-modal-back" id="titModalBack"></div>
    <div class="tit-modal-panel">
        <div class="tit-modal-lab"><?php te('fc.vis_titolo'); ?></div>
        <div class="tit-modal-txt" id="titModalTxt"></div>
        <button type="button" class="tit-modal-edit" id="titModalEdit">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
            <?php te('comune.modifica'); ?>
        </button>
    </div>
</div>

<div class="ex-modal" id="exModal" aria-hidden="true">
    <div class="ex-modal-back" id="exModalBack"></div>
    <div class="ex-modal-panel">
        <header class="ex-modal-head">
            <span class="ex-modal-title" id="exModalTitle"></span>
            <?php /* Da qui si va a CORREGGERE l'esempio che si sta guardando.
                     Prima il visore era di sola lettura: accorgersi di un
                     errore voleva dire chiudere, aprire la carta, aprire gli
                     esempi, trovare quello giusto e solo allora modificarlo —
                     cinque passi per una virgola.
                     Porta al modulo dell'esempio dentro il popup di modifica
                     della carta (fc_edit_popup.js, gia' caricato da questa
                     pagina): non e' una scorciatoia scritta a parte, e' la
                     stessa strada che si farebbe a mano, fatta in un tocco. */ ?>
            <button type="button" class="ex-modal-edit" id="exModalEdit" aria-label="<?php te('fc.exm_modifica'); ?>" title="<?php te('fc.exm_modifica'); ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
            </button>
            <button type="button" class="ex-modal-close" id="exModalClose" aria-label="<?php te('comune.chiudi'); ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
            </button>
        </header>
        <div class="ex-modal-body" id="exModalBody"></div>
        <div class="ex-modal-nav" id="exModalNav"></div>
    </div>
</div>

<div id="toast-host"></div>



<script>

const STANDOUT_CURRENT_USER_ID = <?php echo (int)$current_user_id; ?>;

/* ================================================================

   UTILITY

   ================================================================ */

function $all(sel, root){ return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

function buzz(ms){ try{ if (navigator.vibrate) navigator.vibrate(ms); }catch(e){} }



function showToast(msg){

    const host = document.getElementById('toast-host');

    if (!host) return;

    const t = document.createElement('div');

    t.className = 'toast';

    t.textContent = msg;

    host.appendChild(t);

    setTimeout(function(){

        t.classList.add('leaving');

        setTimeout(function(){ t.remove(); }, 320);

    }, 2200);

}



function syncHeaderHeight(){

    const h = document.getElementById('appHeader');

    if (h) document.documentElement.style.setProperty('--header-h', h.offsetHeight + 'px');

}

window.addEventListener('resize', syncHeaderHeight);
/* L'intestazione cambia altezza anche senza ridimensionare la finestra: la
   barra della selezione, la ricerca mentre si sceglie, la riga che dice in
   che modalità si è. Si misura ogni volta che cambia, invece di ricordarsi di
   farlo in ognuno di quei punti. */
if (window.ResizeObserver) {
    (function(){
        var h = document.getElementById('appHeader');
        if (h) new ResizeObserver(syncHeaderHeight).observe(h);
    })();
}



/* Confetti leggero — usato solo per il momento "Imparato" (tocco dopaminico) */

function miniConfetti(x, y){

    const colors = ['#30D158', '#34C759', '#FFD60A', '#0A84FF', '#ffffff'];

    for (let i = 0; i < 10; i++) {

        const s = document.createElement('div');

        s.style.position = 'fixed'; s.style.left = x + 'px'; s.style.top = y + 'px';

        s.style.width = '6px'; s.style.height = '6px'; s.style.borderRadius = (i % 3 === 0) ? '50%' : '2px';

        s.style.background = colors[i % colors.length]; s.style.zIndex = '9000'; s.style.pointerEvents = 'none';

        document.body.appendChild(s);

        const ang = -Math.PI/2 + (Math.random()-0.5) * Math.PI * 1.3;

        const dist = 40 + Math.random() * 70;

        const dx = Math.cos(ang) * dist;

        const dy = Math.sin(ang) * dist + 60 + Math.random() * 40;

        const rot = (Math.random()-0.5) * 500;

        try {

            const anim = s.animate(

                [{ transform:'translate(-50%,-50%) rotate(0deg)', opacity:1 },

                 { transform:'translate(calc(-50% + '+dx+'px), calc(-50% + '+dy+'px)) rotate('+rot+'deg)', opacity:0 }],

                { duration: 550 + Math.random()*250, easing:'cubic-bezier(.16,.8,.4,1)' }

            );

            anim.onfinish = function(){ s.remove(); };

        } catch(e){ s.remove(); }

    }

}



/* ================================================================

   ZOOM IMMAGINI

   ================================================================ */

var fcZoomStato = null;

function openImageZoom(src){
    var ov = document.getElementById('image-overlay');
    var img = document.getElementById('overlay-img');
    img.src = src;
    /* Si riparte sempre dall'immagine intera: uscendo mentre era ingrandita,
       riaprendone un'altra si erediterebbe lo zoom precedente. */
    img.style.transition = 'none';
    img.style.transform = '';
    ov.style.background = '';
    ov.style.display = 'flex';
    /* La pagina dietro non deve scorrere ne' rispondere ai tocchi: qui si sta
       guardando un'immagine, ogni gesto appartiene a lei. */
    document.body.classList.add('fc-zoom-aperto');
    fcZoomStato = { scala: 1, x: 0, y: 0, distIniziale: 0, scalaIniziale: 1,
                    trascina: false, x0: 0, y0: 0, dy: 0, xPart: 0, yPart: 0 };
}

function closeImageZoom(){
    var ov = document.getElementById('image-overlay');
    var img = document.getElementById('overlay-img');
    ov.style.display = 'none';
    ov.style.background = '';
    img.style.transform = '';
    img.style.transition = '';
    document.body.classList.remove('fc-zoom-aperto');
    fcZoomStato = null;
}

/* ---------- Zoom con due dita, trascinamento verso il basso per chiudere ----------
   Due gesti sullo stesso elemento: con due dita si ingrandisce e si sposta
   l'immagine per guardarla da vicino; con un dito solo, e SOLO quando non e'
   ingrandita, si trascina verso il basso per chiuderla — il gesto che su iOS
   chiude qualunque visore a tutto schermo. A immagine ingrandita il dito
   singolo serve invece a spostarla, altrimenti non si potrebbe esplorarla. */
(function () {
    var ov = null, img = null;

    function elementi() {
        if (!ov) ov = document.getElementById('image-overlay');
        if (!img) img = document.getElementById('overlay-img');
        return !!(ov && img);
    }
    function applica(z) {
        img.style.transform = 'translate(' + z.x + 'px,' + z.y + 'px) scale(' + z.scala + ')';
    }
    function distanza(t) {
        var dx = t[0].clientX - t[1].clientX, dy = t[0].clientY - t[1].clientY;
        return Math.sqrt(dx * dx + dy * dy);
    }
    function dentro(e) {
        return e.target && e.target.closest && e.target.closest('#image-overlay');
    }

    document.addEventListener('touchstart', function (e) {
        if (!fcZoomStato || !elementi() || !dentro(e)) return;
        var z = fcZoomStato;
        img.style.transition = 'none';
        ov.style.transition = 'none';
        if (e.touches.length === 2) {
            z.distIniziale = distanza(e.touches);
            z.scalaIniziale = z.scala;
            z.trascina = false;
        } else if (e.touches.length === 1) {
            z.trascina = true;
            z.x0 = e.touches[0].clientX;
            z.y0 = e.touches[0].clientY;
            z.dy = 0;
            z.xPart = z.x;
            z.yPart = z.y;
        }
    }, { passive: true });

    document.addEventListener('touchmove', function (e) {
        if (!fcZoomStato || !elementi() || !dentro(e)) return;
        var z = fcZoomStato;

        if (e.touches.length === 2 && z.distIniziale > 0) {
            e.preventDefault();
            var f = distanza(e.touches) / z.distIniziale;
            z.scala = Math.min(5, Math.max(1, z.scalaIniziale * f));
            if (z.scala === 1) { z.x = 0; z.y = 0; }
            applica(z);
            return;
        }

        if (e.touches.length === 1 && z.trascina) {
            var dx = e.touches[0].clientX - z.x0;
            var dy = e.touches[0].clientY - z.y0;

            if (z.scala > 1) {
                e.preventDefault();
                z.x = z.xPart + dx;
                z.y = z.yPart + dy;
                applica(z);
                return;
            }
            /* Non ingrandita: conta solo il trascinamento verso il basso, e
               solo se e' piu' verticale che orizzontale — altrimenti un
               movimento obliquo chiuderebbe per sbaglio. */
            if (dy > 0 && dy > Math.abs(dx)) {
                e.preventDefault();
                z.dy = dy;
                img.style.transform = 'translateY(' + dy + 'px) scale(' + Math.max(0.75, 1 - dy / 1000) + ')';
                /* Lo sfondo si schiarisce mentre si trascina: si vede che il
                   gesto sta portando alla chiusura, e si fa in tempo a
                   cambiare idea tornando su. */
                ov.style.background = 'rgba(0,0,0,' + Math.max(0.3, 0.94 - dy / 500) + ')';
            }
        }
    }, { passive: false });

    document.addEventListener('touchend', function () {
        if (!fcZoomStato || !elementi()) return;
        var z = fcZoomStato;
        z.distIniziale = 0;
        if (!z.trascina) return;
        z.trascina = false;
        if (z.scala > 1) return;

        img.style.transition = 'transform .28s cubic-bezier(.32,.72,0,1)';
        ov.style.transition = 'background .28s ease';
        if (z.dy > 110) {
            img.style.transform = 'translateY(' + window.innerHeight + 'px) scale(.7)';
            ov.style.background = 'rgba(0,0,0,0)';
            setTimeout(closeImageZoom, 220);
        } else {
            img.style.transform = '';
            ov.style.background = '';
        }
        z.dy = 0;
    });
})();



/* ================================================================

   PLAYER AUDIO CUSTOM

   ================================================================ */

let currentAudioEl = null;

function fmtTime(s){

    if (!isFinite(s) || s < 0) s = 0;

    const m = Math.floor(s / 60), sec = Math.floor(s % 60);

    return m + ':' + (sec < 10 ? '0' : '') + sec;

}

function toggleAudioPlayer(btn){

    const wrap = btn.closest('.audio-player');

    const audio = wrap.querySelector('audio');

    if (!audio) return;

    if (currentAudioEl && currentAudioEl !== audio) {

        currentAudioEl.pause();

    }

    if (audio.paused) {

        audio.play().catch(function(){});

        currentAudioEl = audio;

    } else {

        audio.pause();

    }

}

function seekAudioPlayer(evt, track){

    const wrap = track.closest('.audio-player');

    const audio = wrap.querySelector('audio');

    if (!audio || !isFinite(audio.duration)) return;

    const rect = track.getBoundingClientRect();

    const p = Math.min(1, Math.max(0, (evt.clientX - rect.left) / rect.width));

    audio.currentTime = p * audio.duration;

}

document.addEventListener('play', function(e){

    if (!e.target.matches) return;

    if (e.target.tagName === 'AUDIO' && e.target.closest('.audio-player')) {

        if (currentAudioEl && currentAudioEl !== e.target) currentAudioEl.pause();

        currentAudioEl = e.target;

    }

}, true);

$all('.audio-player').forEach(function(wrap){

    const audio = wrap.querySelector('audio');

    const fill = wrap.querySelector('.ap-fill');

    const knob = wrap.querySelector('.ap-knob');

    const time = wrap.querySelector('.ap-time');

    const playIc = wrap.querySelector('.ap-ic-play');

    const pauseIc = wrap.querySelector('.ap-ic-pause');

    if (!audio) return;

    audio.addEventListener('timeupdate', function(){

        const d = audio.duration;

        const p = (isFinite(d) && d > 0) ? (audio.currentTime / d) * 100 : 0;

        fill.style.width = p + '%';

        knob.style.left = p + '%';

        time.textContent = fmtTime(isFinite(d) && d > 0 ? (d - audio.currentTime) : audio.currentTime);

    });

    audio.addEventListener('play', function(){ wrap.classList.add('is-playing'); playIc.style.display = 'none'; pauseIc.style.display = ''; });

    audio.addEventListener('pause', function(){ wrap.classList.remove('is-playing'); playIc.style.display = ''; pauseIc.style.display = 'none'; });

    audio.addEventListener('ended', function(){ fill.style.width = '0%'; knob.style.left = '0%'; time.textContent = '0:00'; });

});



/* ================================================================

   TOGGLE "IMPARATO"

   ================================================================ */

function toggleLearnt(id, checkbox){

    const status = checkbox.checked ? 1 : 0;

    const userId = STANDOUT_CURRENT_USER_ID;

    const card = checkbox.closest('.fc-card');

    buzz(status ? 10 : 6);

    if (status && card) {

        const r = checkbox.getBoundingClientRect();

        miniConfetti(r.left + r.width/2, r.top + r.height/2);

    }

    if (card) card.classList.toggle('is-learnt', !!status);

    const xhr = new XMLHttpRequest();

    xhr.open("POST", fcUrl("fc_learnt.php"), true);

    xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");

    xhr.onreadystatechange = function(){

        if (this.readyState === XMLHttpRequest.DONE && this.status === 200) {

            filterList();

        }

    };

    xhr.send("id=" + id + "&status=" + status + "&user_id=" + userId + "&type=fc");

}



/* ================================================================

   NAVIGAZIONE (ordinamento) — changeUser() è stata rimossa insieme

   al menu a tendina: l'utente non si cambia più, è sempre quello loggato

   ================================================================ */

function changeSort(){

    const sortVal = document.getElementById('filterSort').value;

    const urlParams = new URLSearchParams(window.location.search);

    urlParams.set('sort', sortVal);

    window.location.search = urlParams.toString();

}



/* ================================================================

   FILTRO / RICERCA / CONTATORE

   ================================================================ */

/* ================================================================
   L'ELENCO ARRIVA A BLOCCHI

   Il server manda le prime FC_BLOCCO carte; le altre si chiedono a
   fc_view.php?fc_feed_page=N, che risponde con lo stesso HTML che
   avrebbe stampato la pagina (e' proprio la stessa funzione PHP).

   visibleLimit resta, ma ora non nasconde piu' niente di gia' presente: e' il
   tetto di quante se ne mostrano, e sale insieme a quelle che arrivano.

   La regola che tiene insieme tutto: le funzioni che guardano il DOCUMENTO per
   decidere qualcosa — la ricerca, il filtro di stato, "seleziona tutte" —
   devono avere davanti l'elenco intero, altrimenti darebbero una risposta
   giusta su meta' dei dati, che e' peggio di nessuna risposta. Quando una di
   loro parte e manca ancora qualcosa, si chiede tutto in un colpo
   (fc_feed_all=1) e si rifa' il lavoro appena arriva.
   ================================================================ */
var FC_BLOCCO = <?= (int)FC_BLOCCO_CARTE ?>;
var FC_PAGINATO = <?= $fcPaginato ? 'true' : 'false' ?>;
var fcGruppiCaricati = <?= (int)($fcPaginato ? min(count($images_data), FC_BLOCCO_CARTE) : count($images_data)) ?>;
var fcGruppiTotali = <?= (int)count($images_data) ?>;
var fcTutteCaricate = (!FC_PAGINATO || fcGruppiCaricati >= fcGruppiTotali);
var fcCaricamentoInCorso = false;

let visibleLimit = 30; // Limite carte visibili per "Tutte le materie"

function loadMoreCards() {
    buzz(10);
    if (fcTutteCaricate) { visibleLimit += FC_BLOCCO; filterList(); return; }
    fcCaricaAltreCarte(false);
}

function fcCaricaAltreCarte(tutto){
    if (fcCaricamentoInCorso || fcTutteCaricate) return Promise.resolve();
    fcCaricamentoInCorso = true;

    var btn = document.getElementById('loadMoreBtn');
    if (btn) { btn.disabled = true; btn.textContent = T('fc.vis_caricamento_carte'); }

    /* L'indirizzo si costruisce da quello della pagina: cosi' categoria,
       ordinamento e qualunque altro parametro arrivano identici all'endpoint,
       che deve produrre le carte NELLO STESSO ORDINE — altrimenti il blocco
       nuovo non e' il seguito di quello vecchio. */
    var q = new URLSearchParams(window.location.search);
    q.set('fc_feed_page', fcGruppiCaricati);
    if (tutto) q.set('fc_feed_all', '1');

    return fetch('fc_view.php?' + q.toString(), { cache: 'no-store' })
        .then(function(r){ return r.text(); })
        .then(function(html){
            var lista = document.getElementById('fcList');
            if (!lista) return;
            var tmp = document.createElement('div');
            tmp.innerHTML = html;
            /* I nodi nuovi si tengono da parte PRIMA di appenderli: dopo
               sarebbero mescolati a quelli gia' in elenco, e le rifiniture
               (testi rimasti grezzi, riquadri degli esempi) ripasserebbero su
               roba gia' fatta — nel caso degli esempi vuol dire chiederli al
               server una seconda volta. */
            var nuovi = Array.prototype.slice.call(tmp.querySelectorAll('.fc-group'));
            var arrivati = nuovi.length;
            while (tmp.firstChild) lista.appendChild(tmp.firstChild);

            fcGruppiCaricati += arrivati;
            if (tutto || arrivati < FC_BLOCCO || fcGruppiCaricati >= fcGruppiTotali) fcTutteCaricate = true;
            /* Quello che e' appena arrivato si deve vedere: alzare il tetto
               insieme al caricato evita di scaricare carte per poi tenerle
               nascoste, che sarebbe il difetto di prima al contrario. */
            visibleLimit = Math.max(visibleLimit, fcGruppiCaricati);

            nuovi.forEach(function(g){
                if (typeof rifinisciTestiRimasti === 'function') rifinisciTestiRimasti(g);
                if (typeof fcLoadExampleBoxes === 'function') fcLoadExampleBoxes(g);
            });
            filterList();
        })
        .catch(function(){ /* niente rete: il tasto torna com'era e si riprova */ })
        .then(function(){
            fcCaricamentoInCorso = false;
            if (btn) { btn.disabled = false; btn.textContent = T('fc.vis_carica_altre'); }
        });
}

/* Il tasto "carica altre" resta, ma di solito non lo si tocca: quando entra
   nello schermo si carica da solo. E' il tasto a fare da sentinella, cosi' non
   c'e' un secondo elemento da tenere in fondo all'elenco. */
function fcOsservaFineElenco(){
    var btn = document.getElementById('loadMoreBtn');
    if (!btn || typeof IntersectionObserver === 'undefined') return;
    new IntersectionObserver(function(voci){
        voci.forEach(function(v){
            if (v.isIntersecting && !fcCaricamentoInCorso) loadMoreCards();
        });
    }, { rootMargin: '400px 0px' }).observe(btn);
}

let displayedCount = 0;

/* "1 carta mostrata" e non "1 carte mostrate": quando il numero passa da
   uno a tanti (o il contrario) si riscrive la frase intorno al numero, che
   resta lo stesso elemento e continua ad animarsi. */
function fcEtichettaContatore(n){
    const box = document.getElementById('itemCounter');
    const num = document.getElementById('itemCounterNum');
    if (!box || !num || typeof T !== 'function') return;
    const uno = (n === 1) ? '1' : '0';
    if (box.dataset.uno === uno) return;
    box.dataset.uno = uno;
    const parti = T(uno === '1' ? 'fc.vis_una_carta_mostrata' : 'fc.vis_n_carte_mostrate', { n: '\u0001' }).split('\u0001');
    const dot = box.querySelector('.ic-dot');
    box.textContent = '';
    if (dot) { box.appendChild(dot); box.appendChild(document.createTextNode(' ')); }
    box.appendChild(document.createTextNode(parti[0] || ''));
    box.appendChild(num);
    box.appendChild(document.createTextNode(parti[1] || ''));
}

function animateCounter(target){
    const el = document.getElementById('itemCounterNum');
    if (!el) return;
    fcEtichettaContatore(target);

    const start = displayedCount;

    const diff = target - start;

    displayedCount = target;

    if (diff === 0) { el.textContent = target; return; }

    const dur = 260, t0 = performance.now();

    function step(now){

        const p = Math.min(1, (now - t0) / dur);

        const val = Math.round(start + diff * (1 - Math.pow(1 - p, 3)));

        el.textContent = val;

        if (p < 1) requestAnimationFrame(step);

    }

    requestAnimationFrame(step);

}



/* Il filtro per livello (il menu accanto alla ricerca). */
var fcvLivelloScelto = '';
document.addEventListener('change', function (e) {
    if (!e.target || e.target.id !== 'fcvLivSelect') return;
    fcvLivelloScelto = e.target.value || '';
    var w = document.getElementById('fcvLivWrap');
    if (w) w.setAttribute('data-liv', fcvLivelloScelto);
    visibleLimit = 30;
    filterList();
});

function filterList(){
    const statusFilter = document.getElementById('filterStatus').value;
    const textFilter = document.getElementById('filterText').value.toLowerCase().trim();
    const selecting = document.body.classList.contains('select-mode');
    const actionFilter = selecting ? (document.body.dataset.bulkAction || '') : '';

    function cardMatches(card){
        if (!card) return false;
        const cb = card.querySelector('.select-circle input');
        if (selecting && cb && cb.checked) return true;
        /* il livello della parola (lingue): una carta senza livello non passa */
        if (fcvLivelloScelto && card.getAttribute('data-livello') !== fcvLivelloScelto) return false;
        const isLearnt = card.classList.contains('is-learnt');
        const isDup = card.getAttribute('data-duplicate') === 'true';
        const isChild = card.classList.contains('fc-child');
        const group = card.closest('.fc-group');
        const isFather = card.classList.contains('fc-parent') && group && group.querySelector('.fc-children .fc-card');

        let byStatus = false;
        if (statusFilter === 'all') byStatus = true;
        else if (statusFilter === 'learnt') byStatus = isLearnt;
        else if (statusFilter === 'not_learnt') byStatus = !isLearnt;
        else if (statusFilter === 'duplicates') byStatus = isDup;

        /* Una carta appena separata o appena unita passa comunque: e' il
           risultato dell'azione che si e' appena fatta, e nasconderlo per
           rispettare un filtro vorrebbe dire nascondere proprio la cosa che si
           stava cercando di ottenere. */
        if (selecting && card.classList.contains('appena-cambiata')) return true;

        /* SEPARA: hanno senso solo i padri con figli e i figli stessi. Una
           carta che non sta in nessuna famiglia non ha niente da separare, e
           tenerla nell'elenco vuol dire farla scorrere per niente. */
        if (selecting && actionFilter === 'unjoin' && !isFather && !isChild) byStatus = false;

        /* UNISCI: si lavora sui padri. I figli restano nel documento — il
           tastino "n sottocarte" li rivela per far vedere cosa c'e' gia'
           dentro a un padre — ma non sono loro l'oggetto della scelta, e
           l'elenco parte senza. Li mostra il CSS quando il gruppo ha la classe
           kids-open. */
        if (selecting && actionFilter === 'join' && isChild) byStatus = true;

        if (!byStatus) return false;

        const searchText = card.getAttribute('data-search') || '';
        return searchText.indexOf(textFilter) !== -1;
    }

    /* Cercare o filtrare su meta' elenco darebbe una risposta sbagliata con
       l'aria di essere giusta. Si chiede il resto e si rifa' il conto appena
       arriva; intanto si filtra quello che c'e' gia', cosi' la pagina risponde
       subito invece di restare ferma ad aspettare. */
    if (!fcTutteCaricate && (textFilter !== '' || statusFilter !== 'all' || fcvLivelloScelto)) {
        fcCaricaAltreCarte(true);
    }

    let visibleCards = 0;
    let matchedGroups = 0;
    const isTutteLeMaterie = <?php echo $categoryID == 0 ? 'true' : 'false'; ?>;

    /* Solo i gruppi dell'elenco locale: quelli dei risultati nelle
       sottocartelle sono gia' filtrati dal server e vivono fuori da #fcList. */
    $all('#fcList > .fc-group').forEach(function(group){
        const parentCard = group.querySelector(':scope > .fc-card.fc-parent');
        const childCards = $all('.fc-children > .fc-card.fc-child', group);

        const parentMatch = cardMatches(parentCard);
        const childMatchFlags = childCards.map(cardMatches);
        const anyChildMatch = childMatchFlags.indexOf(true) !== -1;
        const groupVisible = parentMatch || anyChildMatch;

        if (groupVisible) {
            matchedGroups++;
            if (isTutteLeMaterie && matchedGroups > visibleLimit) {
                group.style.display = 'none';
                return;
            }
            group.style.display = '';
            if (parentCard) { parentCard.style.display = ''; visibleCards++; }
            childCards.forEach(function(c, i){
                const show = childMatchFlags[i];
                c.style.display = show ? '' : 'none';
                if (show) visibleCards++;
            });
        } else {
            group.style.display = 'none';
        }
    });

    animateCounter(visibleCards);
    
    const loadMoreBtn = document.getElementById('loadMoreBtn');
    if (loadMoreBtn) {
        /* Due motivi per mostrarlo: ci sono carte gia' scaricate oltre il
           tetto, oppure ce ne sono ancora da scaricare. */
        var altro = isTutteLeMaterie && (matchedGroups > visibleLimit || !fcTutteCaricate);
        loadMoreBtn.style.display = altro ? 'block' : 'none';
    }

    /* Le cartelle seguono la ricerca come le carte: se il testo non e' nel

       loro nome spariscono, e sparisce anche l'intestazione se non ne resta

       nessuna. Senza filtro tornano tutte. */

    var sezioneSub = document.querySelector('.fc-subcats');

    if (sezioneSub) {

        var restano = 0;

        $all('.fc-subcat-card', sezioneSub).forEach(function(sc){

            var nome = sc.querySelector('.fc-subcat-name');

            var testo = nome ? nome.textContent.toLowerCase() : '';

            var ok = (textFilter === '') || testo.indexOf(textFilter) !== -1;

            sc.style.display = ok ? '' : 'none';

            if (ok) restano++;

        });

        sezioneSub.style.display = restano ? '' : 'none';

    }

    if (window.fcAfterFilter) window.fcAfterFilter();
}

</script>
<script>

/* ================================================================

   RICERCA: EVIDENZIAZIONE + CARTE TROVATE NELLE SOTTOCARTELLE

   In pagina ci sono solo le carte di questa categoria. Quelle che stanno

   nelle cartelle sotto arrivano da fc_view.php?fc_deep_search=1,

   gia' come HTML: sono le stesse funzioni di render dell'elenco normale, cosi'

   una modifica alla scheda vale per tutte e due gli elenchi.

   ================================================================ */

var FC_CAT_ID = <?= (int)$categoryID ?>;

/* ======================================================================
   LE NOTE DEL MAZZO (il foglietto in cima al feed)
   Tre stati sulla sezione #deckNote: is-vuota (solo l'invito), normale (il
   testo, accorciato con "mostra tutto" se lungo) e is-edit (il campo).
   ====================================================================== */
function fcNoteMazzoMisura(){
    var box = document.getElementById('deckNote');
    var t = document.getElementById('deckNoteText');
    if (!box || !t) return;
    box.classList.remove('is-lunga');
    if (!box.classList.contains('is-aperta') && t.scrollHeight > 132) box.classList.add('is-lunga');
}
function fcNoteMazzoApri(){
    var box = document.getElementById('deckNote');
    var inp = document.getElementById('deckNoteInput');
    inp.value = document.getElementById('deckNoteText').textContent;
    box.classList.add('is-edit');
    fcNoteMazzoConta();
    setTimeout(function(){ inp.focus(); inp.setSelectionRange(inp.value.length, inp.value.length); }, 30);
}
function fcNoteMazzoChiudi(){
    document.getElementById('deckNote').classList.remove('is-edit');
}
function fcNoteMazzoEspandi(){
    var box = document.getElementById('deckNote');
    box.classList.add('is-aperta');
    box.classList.remove('is-lunga');
}
function fcNoteMazzoConta(){
    var inp = document.getElementById('deckNoteInput');
    var c = document.getElementById('deckNoteCount');
    if (c) c.textContent = inp.value.length > 4000 ? (inp.value.length + ' / 5000') : '';
}
function fcNoteMazzoSalva(){
    var box = document.getElementById('deckNote');
    var testo = document.getElementById('deckNoteInput').value.trim();
    var fd = new FormData();
    fd.append('action', 'fc_deck_notes');
    fd.append('category_id', FC_CAT_ID);
    fd.append('notes', testo);
    box.classList.add('is-salva');
    fetch(fcUrl('fc_view.php'), { method: 'POST', body: fd })
        .then(function(r){ return r.json(); })
        .then(function(d){
            box.classList.remove('is-salva');
            if (!d || !d.success) { showToast((d && d.error) || T('comune.errore_generico')); return; }
            document.getElementById('deckNoteText').textContent = d.notes;
            box.classList.toggle('is-vuota', d.notes === '');
            box.classList.remove('is-edit');
            fcNoteMazzoMisura();
            showToast(d.notes === '' ? T('fc.dn_tolte') : T('fc.dn_salvate'));
        })
        .catch(function(){ box.classList.remove('is-salva'); showToast(T('comune.errore_generico')); });
}
(function(){
    var inp = document.getElementById('deckNoteInput');
    if (!inp) return;
    inp.addEventListener('input', fcNoteMazzoConta);
    inp.addEventListener('keydown', function(e){
        if (e.key === 'Escape') fcNoteMazzoChiudi();
        if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) { e.preventDefault(); fcNoteMazzoSalva(); }
    });
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fcNoteMazzoMisura);
    else fcNoteMazzoMisura();
})();

var FC_HA_SOTTOCARTELLE = <?= !empty($subcategories) ? 'true' : 'false' ?>;

/* Stessa chiave che legge fc_decks.php: cosi', tornando li' (tasto Home

   o "indietro" del browser), l'albero si ritrova gia' aperto su questa

   categoria, anche se ci si e' arrivati scendendo per sotto-cartelle senza

   mai passare da un clic su una riga di quella pagina. */

if (FC_CAT_ID !== 0) { try { sessionStorage.setItem('nisama_cat_tree_focus', FC_CAT_ID); } catch(e){} }



/* ---------- Evidenziazione ---------- */

/* Si lavora sul testo gia' disegnato, non sul data-raw: il testo grezzo ha

   dentro la formattazione (formule comprese) e infilarci dei tag lo

   romperebbe. Qui invece si spezzano solo nodi di testo. */

/* Il dito e' partito dentro qualcosa che scorre gia' per conto suo (una

   formula larga, un testo che sborda)? Allora quel trascinamento e' suo: ne'

   i tastini a scorrimento della carta ne' la sfogliata del visore devono

   rubarglielo. Si risale fino a un limite, non fino in cima alla pagina. */

function fcScorrevoleOrizz(el, limite){

    while (el && el !== limite && el.nodeType === 1) {

        if (el.scrollWidth - el.clientWidth > 2) {

            var st = getComputedStyle(el);

            if (st.overflowX === 'auto' || st.overflowX === 'scroll') return true;

        }

        el = el.parentElement;

    }

    return false;

}



function fcTogliEvidenze(root){

    var scope = root || document;

    Array.prototype.slice.call(scope.querySelectorAll('mark.fc-hl')).forEach(function(m){

        var p = m.parentNode;

        if (!p) return;

        p.replaceChild(document.createTextNode(m.textContent), m);

        p.normalize();

    });

}



function fcEvidenziaIn(el, termine){

    if (!el || !termine) return;

    var basso = termine.toLowerCase();

    var nodi = [];

    var walker = document.createTreeWalker(el, NodeFilter.SHOW_TEXT, null, false);

    var n;

    while ((n = walker.nextNode())) {

        if (!n.nodeValue || n.nodeValue.toLowerCase().indexOf(basso) === -1) continue;

        var p = n.parentNode;

        if (!p || p.nodeType !== 1) continue;

        /* MathJax ridisegna i propri nodi da capo: metterci dentro un <mark>

           significa vederselo sparire o, peggio, rompere la formula. */

        if (p.closest('mjx-container, .MathJax, script, style, mark.fc-hl')) continue;

        nodi.push(n);

    }

    nodi.forEach(function(node){

        var txt = node.nodeValue, low = txt.toLowerCase();

        var frag = document.createDocumentFragment();

        var i = 0, j;

        while ((j = low.indexOf(basso, i)) !== -1) {

            if (j > i) frag.appendChild(document.createTextNode(txt.slice(i, j)));

            var mk = document.createElement('mark');

            mk.className = 'fc-hl';

            mk.textContent = txt.slice(j, j + basso.length);

            frag.appendChild(mk);

            i = j + basso.length;

        }

        if (i < txt.length) frag.appendChild(document.createTextNode(txt.slice(i)));

        if (node.parentNode) node.parentNode.replaceChild(frag, node);

    });

}



var fcEvidenzaAttiva = '';

function fcApplicaEvidenze(){

    var input = document.getElementById('filterText');

    var termine = input ? input.value.trim() : '';

    /* Ripulire tutto a ogni battuta costerebbe caro su elenchi lunghi: si

       rifa' solo quando il termine cambia davvero. */

    if (termine === fcEvidenzaAttiva) return;

    fcTogliEvidenze(document);

    fcEvidenzaAttiva = termine;

    if (termine.length < 1) return;

    var visibili = $all('.fc-card').filter(function(c){

        if (c.offsetParent === null) return false;

        var g = c.closest('.fc-group');

        return !(g && g.style.display === 'none');

    });

    visibili.forEach(function(card){

        $all('.qa-text, .qa-example-text', card).forEach(function(el){

            fcEvidenziaIn(el, termine);

        });

    });

    /* Anche il nome delle cartelle rimaste: se una resta a video per via del

       testo cercato, si deve vedere dov'e' quel testo. */

    $all('.fc-subcat-card .fc-subcat-name').forEach(function(el){

        if (el.closest('.fc-subcat-card').style.display !== 'none') fcEvidenziaIn(el, termine);

    });

}



/* ---------- Ricerca nelle sottocartelle ---------- */

var fcDeepTimer = null, fcDeepCtl = null, fcDeepUltimo = null;



function fcOnSearchInput(){

    visibleLimit = 30;

    filterList();

    fcDeepProgramma();

}



function fcDeepProgramma(){

    if (fcDeepTimer) clearTimeout(fcDeepTimer);

    fcDeepTimer = setTimeout(fcDeepEsegui, 320);

}



function fcDeepEsegui(){

    var wrap = document.getElementById('fcDeep');

    var input = document.getElementById('filterText');

    if (!wrap || !input) return;

    var termine = input.value.trim();

    if (termine === fcDeepUltimo) return;

    fcDeepUltimo = termine;



    if (fcDeepCtl) { try { fcDeepCtl.abort(); } catch(e){} fcDeepCtl = null; }



    if (!FC_HA_SOTTOCARTELLE || FC_CAT_ID === 0 || termine.length < 2) {

        wrap.innerHTML = ''; wrap.hidden = true;

        return;

    }



    wrap.hidden = false;

    wrap.innerHTML = '<div class="fc-deep-loading">' + fcEscapeHtml(T('fc.vis_cerco_sottocartelle')) + '</div>';



    var opt = {};

    if (window.AbortController) { fcDeepCtl = new AbortController(); opt.signal = fcDeepCtl.signal; }



    fetch(fcUrl('fc_view.php?fc_deep_search=1&category_id=' + FC_CAT_ID) + '&q=' + encodeURIComponent(termine), opt)

        .then(function(r){ return r.json(); })

        .then(function(data){

            /* Risposta di una battuta gia' superata: si butta. */

            if (input.value.trim() !== termine) return;

            if (!data || !data.success || !data.total) {

                wrap.innerHTML = ''; wrap.hidden = true;

                return;

            }

            wrap.innerHTML = '<div class="fc-deep-label">'

                + '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z"/></svg>'

                + fcEscapeHtml(T('fc.vis_anche_sottocartelle', { n: data.total }))

                + '</div>' + data.html;

            if (typeof renderAllRawTexts === 'function') renderAllRawTexts(wrap);

            if (typeof fcLoadExampleBoxes === 'function') fcLoadExampleBoxes(wrap);

            fcEvidenzaAttiva = '';   /* la roba nuova non e' ancora evidenziata */

            fcApplicaEvidenze();

        })

        .catch(function(err){

            if (err && err.name === 'AbortError') return;

            wrap.innerHTML = ''; wrap.hidden = true;

        });

}



/* Chiude/riapre una cartella dei risultati: il tocco sta sulla riga del

   percorso, come chiesto. */

function fcDeepToggle(btn){

    var g = btn.closest('.fc-deep-group');

    if (!g) return;

    var chiuso = g.classList.toggle('collapsed');

    btn.setAttribute('aria-expanded', chiuso ? 'false' : 'true');

    buzz(6);

}



/* Chiamata in fondo a filterList(): tiene allineate evidenziazione e

   ricerca profonda a ogni cambio di filtro. */

window.fcAfterFilter = function(){

    /* In selezione i risultati delle sottocartelle spariscono: non fanno parte

       del modulo delle azioni di massa, tenerli sotto gli occhi mentre si

       spunta farebbe credere che vengano toccati anche loro. */

    var wrap = document.getElementById('fcDeep');

    if (wrap) wrap.style.display = document.body.classList.contains('select-mode') ? 'none' : '';

    fcApplicaEvidenze();

};



/* ---------- Ritorno da modifica_immagine_fc: si riparte dalla carta ----------

   Il salvataggio torna qui con fc_focus / fc_focus_child nell'indirizzo:

   senza, il browser ricomincia da capo e in un elenco lungo si perde il

   punto in cui si stava lavorando. */

function fcVaiAllaCartaDelFocus(){

    var p = new URLSearchParams(window.location.search);

    var idPadre = parseInt(p.get('fc_focus') || '0', 10);

    var idFiglio = parseInt(p.get('fc_focus_child') || '0', 10);

    if (!idPadre && !idFiglio) return;



    var sel = idPadre

        ? '.fc-card[data-type="parent"][data-id="' + idPadre + '"]'

        : '.fc-card[data-type="child"][data-id="' + idFiglio + '"]';

    var card = document.querySelector(sel);

    if (!card) return;



    /* Ci si e' appena tornati da una modifica: la carta deve comparire a

       prescindere da un filtro o da una ricerca rimasti impostati da prima

       (sono preferenze della vista, non un giudizio su questa carta in

       particolare). Si azzerano solo se davvero stanno nascondendo qualcosa,

       cosi' non si rifa' il filtro per niente quando non serve. */

    var selStatus = document.getElementById('filterStatus');

    var inputSearch = document.getElementById('filterText');

    var toccato = false;

    if (selStatus && selStatus.value !== 'all') { selStatus.value = 'all'; toccato = true; }

    if (inputSearch && inputSearch.value !== '') { inputSearch.value = ''; toccato = true; }

    if (toccato) { fcEvidenzaAttiva = ''; filterList(); }



    /* In "tutte le categorie" l'elenco e' tagliato a 30 alla volta: se la

       carta e' oltre il taglio si alza il limite finche' non compare. */

    var guardia = 0;

    var gruppo = card.closest('.fc-group');

    while (gruppo && gruppo.style.display === 'none' && guardia < 80) {

        visibleLimit += 30;

        filterList();

        guardia++;

    }

    if (gruppo && gruppo.style.display === 'none') return;



    function porta(){

        var h = document.getElementById('appHeader');

        var alto = h ? h.offsetHeight + 12 : 80;

        var y = card.getBoundingClientRect().top + window.pageYOffset - alto;

        window.scrollTo(0, Math.max(0, y));

    }

    porta();

    /* Le immagini arrivano dopo e spostano tutto: si ripassa due volte. */

    setTimeout(porta, 160);

    setTimeout(porta, 520);



    card.classList.add('fc-focus-flash');

    setTimeout(function(){ card.classList.remove('fc-focus-flash'); }, 2400);



    /* Via il parametro dall'indirizzo: un aggiornamento della pagina non deve

       rifare il salto e il lampeggio. */

    try {

        p.delete('fc_focus'); p.delete('fc_focus_child');

        var q = p.toString();

        history.replaceState(null, '', window.location.pathname + (q ? '?' + q : ''));

    } catch(e){}

}
</script>

<script>

/* ================================================================

   IMPOSTAZIONI DELLA VISTA + TASTO "+" A DUE STRADE

   Il filtro (tutte / da imparare / imparate / duplicati) non e' piu' una

   tendina nella barra ma sta nelle impostazioni, insieme a quanto in lungo

   mostrare carte ed esempi. Le scelte restano nel browser: sono preferenze di

   come si guarda l'elenco, non dati da tenere sul server.

   ================================================================ */

var FC_PREFS_KEY = 'nisama_fc_vis_prefs';

var FC_COLORE_PADRE = '<?= sec_h(fc_color_or_default($current_cat_color)) ?>';

/* json_encode e non sec_h: qui il nome finisce dentro una stringa JavaScript,

   non nel testo della pagina, e le opzioni HEX chiudono la strada anche a un

   nome che contenesse una chiusura di script. */

var FC_CAT_NOME = <?= json_encode($current_cat_name, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

/* Il colore che si erediterebbe dal padre e quello attuale: servono al popup

   del colore per sapere quale casella nasce selezionata. */

var FC_COLORE_PADRE_CAT = '<?= sec_h(fc_color_or_default($fc_parent_color)) ?>';

var FC_COLORE_CORRENTE = '<?= sec_h(fc_color_or_default($current_cat_color)) ?>';

var FC_COLORE_EDITED = <?= $fc_color_edited ? 'true' : 'false' ?>;



function fcLeggiPrefs(){

    try {

        var raw = localStorage.getItem(FC_PREFS_KEY);

        return raw ? (JSON.parse(raw) || {}) : {};

    } catch(e){ return {}; }

}



function fcApplicaPrefs(p){

    document.body.classList.toggle('fc-full-cards', !!p.fullCards);

    document.body.classList.toggle('fc-full-examples', !!p.fullExamples);

    var btn = document.getElementById('settingsBtn');

    /* Pallino sul tasto quando c'e' qualcosa di diverso dal normale:

       altrimenti un filtro attivo diventa invisibile e sembra che manchino

       delle carte. */

    if (btn) btn.classList.toggle('has-dot', !!(p.fullCards || p.fullExamples || (p.status && p.status !== 'all')));

}



function fcCaricaImpostazioni(){

    var p = fcLeggiPrefs();

    var sel = document.getElementById('filterStatus');

    if (sel && p.status) {

        /* Se il valore salvato non esiste piu' fra le opzioni si torna a

           "tutte", invece di lasciare la tendina su niente. */

        sel.value = p.status;

        if (!sel.value) sel.value = 'all';

    }

    var c = document.getElementById('optFullCards');

    var e = document.getElementById('optFullExamples');

    if (c) c.checked = !!p.fullCards;

    if (e) e.checked = !!p.fullExamples;

    fcApplicaPrefs(p);

}



function fcSalvaImpostazioni(){

    var sel = document.getElementById('filterStatus');

    var c = document.getElementById('optFullCards');

    var e = document.getElementById('optFullExamples');

    var p = {

        status: sel ? sel.value : 'all',

        fullCards: !!(c && c.checked),

        fullExamples: !!(e && e.checked)

    };

    try { localStorage.setItem(FC_PREFS_KEY, JSON.stringify(p)); } catch(err){}

    fcApplicaPrefs(p);

}



/* ---------- Le partite con questo mazzo ----------
   Le chiede allo Storico (lo stesso elenco, filtrato su questo mazzo e le sue
   sottocartelle, da sempre) e le mostra in breve: data, percentuale, e l'oro
   per quelle portate al 100%. Toccandone una si apre il suo risultato. */
function fcApriPartiteMazzo(){
    var sh = document.getElementById('partiteSheet');
    if (!sh) return;
    sh.style.display = 'flex';
    buzz(8);
    if (sh._caricato) return;
    sh._caricato = true;
    var lista = document.getElementById('pmLista');
    var q = new URLSearchParams({ ajax: 'lista', da: '', a: '', stato: 'tutte', tipo: 'tutti', ordina: 'recenti', materia: sh.getAttribute('data-materia'), offset: 0 });
    fetch(fcUrl('fc_history.php?' + q.toString()), { cache: 'no-store', credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            var righe = (d && d.righe) || [];
            if (!righe.length) { lista.innerHTML = '<div class="pm-vuoto">' + fcEscapeHtmlLocale(T('fc.vis_partite_nessuna')) + '</div>'; return; }
            lista.innerHTML = righe.slice(0, 25).map(fcRigaPartita).join('');
        })
        .catch(function () { sh._caricato = false; lista.innerHTML = '<div class="pm-vuoto">' + fcEscapeHtmlLocale(T('fc.vis_partite_errore')) + '</div>'; });
}
function fcEscapeHtmlLocale(s){ var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
function fcRigaPartita(r){
    var n = r.ok + r.ko, p = n ? Math.round(r.ok / n * 100) : null;
    var oro = r.catena === 'conquistata' || r.catena === 'perfetta';
    var sospesa = r.sospesa || (r.ripassi || []).some(function (x) { return x.sospesa; });
    var d = new Date(String(r.data).replace(' ', 'T'));
    var quando = isNaN(d) ? '' : d.toLocaleDateString(document.documentElement.lang || undefined, { day: 'numeric', month: 'short', year: d.getFullYear() !== new Date().getFullYear() ? 'numeric' : undefined });
    var nRip = (r.ripassi || []).length;
    var sotto = oro ? (r.catena === 'perfetta' ? T('fc.sfc_perfetta') : T(nRip === 1 ? 'fc.sfc_conquistata_1' : 'fc.sfc_conquistata_n', { n: nRip }))
              : (r.catena === 'aperta' && r.da_sistemare > 0 ? T(r.da_sistemare === 1 ? 'fc.sfc_da_sistemare_1' : 'fc.sfc_da_sistemare_n', { n: r.da_sistemare }) : T('fc.sfc_carte_n', { c: r.chiuse, t: r.totale }));
    var cls = sospesa ? 'sospesa' : (oro ? 'oro' : (p === null ? 'nd' : (p >= 80 ? 'buona' : (p >= 50 ? 'media' : 'bassa'))));
    var pill = sospesa ? T('fc.sfc_in_sospeso') : (oro ? '★ 100%' : (p === null ? '–' : p + '%'));
    var href = sospesa && r.sospesa ? ('fc_game.php?game_id=' + r.id + '&position=' + r.pos) : ('fc_result.php?game_id=' + r.id);
    return '<a class="pm-riga' + (oro ? ' is-oro' : '') + '" href="' + href + '">'
        + '<span class="pm-data">' + fcEscapeHtmlLocale(quando) + '</span>'
        + '<span class="pm-sotto' + (r.catena === 'aperta' && r.da_sistemare > 0 && !oro ? ' da-fare' : '') + '">' + fcEscapeHtmlLocale(sotto) + '</span>'
        + '<span class="pm-pill ' + cls + '">' + fcEscapeHtmlLocale(pill) + '</span></a>';
}
document.addEventListener('click', function (e) { var sh = document.getElementById('partiteSheet'); if (sh && e.target === sh) closeSheet('partiteSheet'); });

function fcApriGioca(){
    var s = document.getElementById('playSheet');
    if (s) s.style.display = 'flex';
    buzz(8);
}

function fcApriImpostazioni(){

    document.getElementById('settingsSheet').style.display = 'flex';

    buzz(8);

}



/* ---------- Tasto "+": carta o cartella ---------- */

function fcApriAggiungi(){

    document.getElementById('addSheet').style.display = 'flex';

    buzz(8);

}




/* Ricarica riportandosi sulla carta appena creata o modificata. Prima ci
   pensava il redirect del server, che tornava con fc_focus/fc_focus_child
   nell'indirizzo; ora che il modulo vive in un popup quel redirect non
   avviene piu', quindi il parametro lo rimettiamo qui. Senza, si tornava
   sempre in cima all'elenco. */
function fcRicaricaSuCarta(dati){
    var carta = dati && dati.carta;
    if (!carta || !carta.id) { window.location.reload(); return; }
    var u = new URL(window.location.href);
    u.searchParams.delete('fc_focus');
    u.searchParams.delete('fc_focus_child');
    u.searchParams.set(carta.tipo === 'child' ? 'fc_focus_child' : 'fc_focus', carta.id);
    window.location.href = u.toString();
}

function fcAggiungiCarta(){

    /* Apre il modulo di inserimento nello stesso popup usato per la modifica:
       e' la stessa pagina (fc_card_edit.php) in modalita' insert.
       Cosi' non si lascia l'elenco per aggiungere una carta, e dopo averla
       creata si puo' restare li' e inserirne un'altra. Al ritorno si ricarica
       solo se qualcosa e' stato davvero creato: chi apre e chiude senza
       salvare ritrova l'elenco esattamente dov'era, senza uno sfarfallio. */
    var url = fcUrl('fc_card_edit.php?modo=insert&category_id=' + FC_CAT_ID);
    if (typeof apriModificaPopup === 'function') {
        apriModificaPopup(url, fcRicaricaSuCarta);
    } else {
        window.location.href = url;
    }

}



/* ---------- Nuova cartella (categoria figlia di questa) ---------- */

function fcApriNuovaCartella(){

    closeSheet('addSheet');

    /* Il selettore di colore e' quello condiviso con il popup del colore

       della categoria: qui si apre sulla scelta ereditata. */

    fcPickerApri('cartella', FC_COLORE_PADRE, false, '');

    document.getElementById('newFolderName').value = '';

    document.getElementById('newFolderSub').textContent = FC_CAT_ID === 0

        ? T('fc.vis_cartella_radice')

        : T('fc.vis_cartella_dentro', { nome: FC_CAT_NOME });

    document.getElementById('newFolderSheet').style.display = 'flex';

    setTimeout(function(){

        var i = document.getElementById('newFolderName');

        if (i) i.focus();

    }, 320);

}



function fcCreaCartella(){

    var nome = (document.getElementById('newFolderName').value || '').trim();

    if (!nome) {

        showToast(T('fc.vis_nome_mancante'));

        document.getElementById('newFolderName').focus();

        return;

    }

    var btn = document.getElementById('newFolderSave');

    btn.disabled = true;



    var fd = new FormData();

    fd.append('action', 'fc_create_subcat');

    fd.append('name', nome);

    fd.append('parent_id', FC_CAT_ID);

    fd.append('color_mode', fcPicker.cartella.manuale ? 'manual' : 'auto');

    fd.append('color', fcPicker.cartella.manuale ? fcPicker.cartella.colore : '');



    fetch(fcUrl('fc_view.php'), { method: 'POST', body: fd })

        .then(function(r){ return r.json(); })

        .then(function(data){

            btn.disabled = false;

            if (data && data.success) {

                closeSheet('newFolderSheet');

                showToast(T('fc.vis_cartella_creata', { nome: data.name }));

                /* Ricarico: la griglia delle sottocartelle e i conteggi

                   nascono lato server, rifarli a mano qui vorrebbe dire

                   scrivere due volte la stessa cosa. */

                setTimeout(function(){ window.location.reload(); }, 450);

            } else {

                showToast((data && data.error) ? data.error : T('fc.vis_creazione_fallita'));

            }

        })

        .catch(function(){

            btn.disabled = false;

            showToast(T('comune.errore_rete'));

        });

}



document.getElementById('settingsSheet').addEventListener('click', function(e){ if (e.target === this) closeSheet('settingsSheet'); });
(function(){ var ps = document.getElementById('playSheet'); if (ps) ps.addEventListener('click', function(e){ if (e.target === this) closeSheet('playSheet'); }); })();

document.getElementById('addSheet').addEventListener('click', function(e){ if (e.target === this) closeSheet('addSheet'); });

document.getElementById('newFolderSheet').addEventListener('click', function(e){ if (e.target === this) closeSheet('newFolderSheet'); });

document.getElementById('newFolderName').addEventListener('keydown', function(e){ if (e.key === 'Enter') { e.preventDefault(); fcCreaCartella(); } });

</script>

<script>

/* ================================================================

   BARRA IN ALTO: NOME MODIFICABILE, COLORE, RIEPILOGO DEL PROGETTO

   La pastiglia non porta piu' via dalla pagina. Il ritorno alle categorie

   resta la casetta nel percorso, qui sotto: da li' non si tocca niente per

   sbaglio, mentre un tocco sulla pastiglia larga capitava di continuo.

   ================================================================ */



/* ---------- Selettore di colore condiviso ----------

   Due popup (nuova cartella e colore della categoria) hanno la stessa

   griglia e la stessa prima casella "colore di default": tenerne due copie

   voleva dire vederle divergere alla prima modifica. Cambia solo dove il

   risultato viene poi letto. */

var fcPicker = {

    cartella:  { grid:'newFolderGrid', dot:'newFolderDot', nome:'newFolderColorName',

                 hex:'newFolderHex', custom:'newFolderCustom',

                 ereditato:'#A0A0A0', manuale:false, colore:'' },

    categoria: { grid:'catColorGrid', dot:'catColorDot', nome:'catColorName',

                 hex:'catColorHex', custom:'catColorCustom',

                 ereditato:'#A0A0A0', manuale:false, colore:'' }

};



function fcPickerApri(k, ereditato, manuale, colore){

    var s = fcPicker[k];

    s.ereditato = ereditato || '#A0A0A0';

    s.manuale = !!manuale;

    s.colore = s.manuale ? (colore || s.ereditato) : '';

    fcPickerDisegna(k);

}

function fcPickerAuto(k){ var s = fcPicker[k]; s.manuale = false; s.colore = ''; fcPickerDisegna(k); }

function fcPickerManuale(k, hex){ var s = fcPicker[k]; s.manuale = true; s.colore = hex; fcPickerDisegna(k); }

function fcPickerValore(k){ var s = fcPicker[k]; return s.manuale ? s.colore : s.ereditato; }



function fcPickerDisegna(k){

    var s = fcPicker[k];

    var mostrato = fcPickerValore(k);

    var el;

    if ((el = document.getElementById(s.dot))) el.style.background = mostrato;

    if ((el = document.getElementById(s.nome))) el.textContent = s.manuale ? T('vocab.cat_colore_pers') : T('fc.vis_colore_default');

    if ((el = document.getElementById(s.hex))) el.textContent = String(mostrato).toUpperCase();

    if ((el = document.getElementById(s.custom))) el.value = mostrato;

    $all('#' + s.grid + ' .nf-sw').forEach(function(sw){

        if (sw.dataset.auto) sw.classList.toggle('selected', !s.manuale);

        else sw.classList.toggle('selected', s.manuale && (sw.dataset.hex || '').toLowerCase() === (s.colore || '').toLowerCase());

    });

}



/* ---------- Tocco sulla pastiglia ---------- */

function fcToccaPastiglia(e){

    /* Il nome e la pastiglia del progetto hanno un loro comando: qui si

       raccoglie solo il tocco sul resto, il "niente". */

    if (e.target.closest && e.target.closest('.cat-pill-name, .cat-pill-name-input, .cat-proj-chip')) return;

    if (FC_CAT_ID === 0) return;

    fcApriColoreCategoria();

}



function fcApriColoreCategoria(){

    fcPickerApri('categoria', FC_COLORE_PADRE_CAT, FC_COLORE_EDITED, FC_COLORE_CORRENTE);

    document.getElementById('catColorSheet').style.display = 'flex';

    buzz(8);

}



function fcSalvaColoreCategoria(){

    var btn = document.getElementById('catColorSave');

    var s = fcPicker.categoria;

    btn.disabled = true;



    var fd = new FormData();

    fd.append('action', 'fc_recolor_cat');

    fd.append('category_id', FC_CAT_ID);

    fd.append('color_mode', s.manuale ? 'manual' : 'auto');

    fd.append('color', s.manuale ? s.colore : '');



    fetch(fcUrl('fc_view.php'), { method: 'POST', body: fd })

        .then(function(r){ return r.json(); })

        .then(function(data){

            btn.disabled = false;

            if (!data || !data.success) {

                showToast((data && data.error) ? data.error : T('comune.errore_rete'));

                return;

            }

            closeSheet('catColorSheet');

            /* Colpo d'occhio immediato: --cat-color tinge mezza pagina. */

            document.documentElement.style.setProperty('--cat-color', data.color);

            FC_COLORE_CORRENTE = data.color;

            FC_COLORE_EDITED = (data.edited === 1);

            showToast(T('fc.vis_colore_aggiornato'));

            /* Se ci sono cartelle sotto, il colore puo' essere sceso anche su

               di loro: i loro riquadri li disegna il server, quindi si

               ricarica invece di indovinare quali sono cambiate. */

            if (FC_HA_SOTTOCARTELLE) setTimeout(function(){ window.location.reload(); }, 700);

        })

        .catch(function(){ btn.disabled = false; showToast(T('comune.errore_rete')); });

}



/* ---------- Nome della categoria modificabile sul posto ---------- */

function fcApriRinomina(e){

    if (e) e.stopPropagation();

    if (FC_CAT_ID === 0) return;

    var nome = document.getElementById('catPillName');

    var input = document.getElementById('catPillNameInput');

    if (!nome || !input) return;

    input.value = nome.textContent.trim();

    nome.style.display = 'none';

    input.style.display = 'block';

    input.focus();

    input.select();

}



function fcChiudiRinomina(salva){

    var nome = document.getElementById('catPillName');

    var input = document.getElementById('catPillNameInput');

    if (!nome || !input || input.style.display === 'none') return;

    var nuovo = input.value.trim();

    var vecchio = nome.textContent.trim();

    input.style.display = 'none';

    nome.style.display = '';

    if (!salva || nuovo === '' || nuovo === vecchio) return;



    /* Si scrive subito a schermo e poi si chiede al server: se rifiuta si

       torna indietro. Aspettare la risposta per far comparire il nome nuovo

       fa sembrare la pagina lenta su una cosa che non lo e'. */

    nome.textContent = nuovo;

    var corrente = document.querySelector('.cat-path-current');

    if (corrente) corrente.textContent = nuovo;

    FC_CAT_NOME = nuovo;



    var fd = new FormData();

    fd.append('action', 'fc_rename_cat');

    fd.append('category_id', FC_CAT_ID);

    fd.append('name', nuovo);



    fetch(fcUrl('fc_view.php'), { method: 'POST', body: fd })

        .then(function(r){ return r.json(); })

        .then(function(data){

            if (data && data.success) {

                nome.textContent = data.name;

                if (corrente) corrente.textContent = data.name;

                FC_CAT_NOME = data.name;

                showToast(T('fc.vis_rinominata'));

            } else {

                nome.textContent = vecchio;

                if (corrente) corrente.textContent = vecchio;

                FC_CAT_NOME = vecchio;

                showToast((data && data.error) ? data.error : T('comune.errore_rete'));

            }

        })

        .catch(function(){

            nome.textContent = vecchio;

            if (corrente) corrente.textContent = vecchio;

            FC_CAT_NOME = vecchio;

            showToast(T('comune.errore_rete'));

        });

}



/* ---------- Riepilogo del progetto ---------- */

function fcFormattaDurata(sec){

    sec = Math.max(0, parseInt(sec || 0, 10));

    var h = Math.floor(sec / 3600), m = Math.round((sec % 3600) / 60);

    if (h && m) return h + 'h ' + m + 'm';

    if (h) return h + 'h';

    return m + 'm';

}



function fcFormattaData(iso){

    if (!iso) return '';

    var d = new Date(iso + 'T00:00:00');

    if (isNaN(d.getTime())) return iso;

    try {

        return d.toLocaleDateString(document.documentElement.lang || 'it',

                                    { day: 'numeric', month: 'short', year: 'numeric' });

    } catch(e){ return iso; }

}



/* Quanto manca, detto come lo direbbe una persona. */

function fcQuandoTesto(giorni){

    if (giorni === null || giorni === undefined) return '';

    if (giorni === 0) return T('fc.vis_prj_oggi');

    if (giorni === 1) return T('fc.vis_prj_domani');

    if (giorni > 1)  return T('fc.vis_prj_fra_giorni', { n: giorni });

    return T('fc.vis_prj_giorni_fa', { n: Math.abs(giorni) });

}



function fcApriProgetto(id){

    var sheet = document.getElementById('projectSheet');

    var body = document.getElementById('prjBody');

    document.getElementById('prjGo').href = '../projects/progetti.php?id=' + encodeURIComponent(id);

    body.innerHTML = '<div class="prj-loading">' + fcEscapeHtml(T('fc.vis_prj_caricamento')) + '</div>';

    sheet.style.display = 'flex';

    buzz(8);



    fetch(fcUrl('fc_view.php?fc_project_summary=1&project_id=' + encodeURIComponent(id)))

        .then(function(r){ return r.json(); })

        .then(function(d){

            if (!d || !d.success) {

                body.innerHTML = '<div class="prj-loading">' + fcEscapeHtml((d && d.error) ? d.error : T('comune.errore_rete')) + '</div>';

                return;

            }

            body.innerHTML = fcRiepilogoHtml(d);

        })

        .catch(function(){

            body.innerHTML = '<div class="prj-loading">' + fcEscapeHtml(T('comune.errore_rete')) + '</div>';

        });

}



function fcRiepilogoHtml(d){

    var h = '';

    var esc = fcEscapeHtml;



    h += '<div class="prj-head" style="--prj:' + esc(d.color) + ';">';

    if (d.path && d.path.length) {

        h += '<div class="prj-path">' + d.path.map(function(p){ return esc(p); }).join('<span class="prj-path-sep">›</span>') + '</div>';

    }

    h += '<div class="prj-title-row"><span class="prj-dot"></span><span class="prj-name">' + esc(d.name) + '</span></div>';



    h += '<div class="prj-badges">';

    h += '<span class="prj-badge ' + (d.status === 'completed' ? 'ok' : 'live') + '">'

       + esc(d.status === 'completed' ? T('fc.vis_prj_completato') : T('fc.vis_prj_in_corso')) + '</span>';

    if (d.favorite) h += '<span class="prj-badge fav">★ ' + esc(T('fc.vis_prj_preferito')) + '</span>';

    h += '</div></div>';



    if (d.notes) h += '<div class="prj-notes">' + esc(d.notes) + '</div>';



    /* Quattro numeri, quelli che dicono davvero a che punto e' il progetto. */

    h += '<div class="prj-stats">';

    h += fcStatBox(fcFormattaDurata(d.secondi), T('fc.vis_prj_tempo'),

                   d.secondi7 ? (T('fc.vis_prj_settimana') + ': ' + fcFormattaDurata(d.secondi7)) : '');

    h += fcStatBox(String(d.sessioni), T('fc.vis_prj_pomodori'), '');

    h += fcStatBox(d.figli ? (d.figli_ok + '/' + d.figli) : '—', T('fc.vis_prj_sottoprogetti'), '');

    h += fcStatBox(d.todo ? (d.todo_ok + '/' + d.todo) : '—', T('fc.vis_prj_attivita'), '');

    h += '</div>';



    if (d.figli || d.todo) {

        h += '<div class="prj-bars">';

        if (d.figli) h += fcBarra(T('fc.vis_prj_sottoprogetti'), d.figli_ok, d.figli, d.color);

        if (d.todo)  h += fcBarra(T('fc.vis_prj_attivita'), d.todo_ok, d.todo, d.color);

        h += '</div>';

    }



    if (d.target || d.due) {

        h += '<div class="prj-block"><div class="prj-block-lab">' + esc(T('fc.vis_prj_date')) + '</div><div class="prj-dates">';

        if (d.target) h += fcRigaData(T('fc.vis_prj_obiettivo'), d.target, d.target_in, d.status === 'completed');

        if (d.due)    h += fcRigaData(T('fc.vis_prj_scadenza'), d.due, d.due_in, d.status === 'completed');

        h += '</div></div>';

    }



    if (d.evento) {

        h += '<div class="prj-block"><div class="prj-block-lab">' + esc(T('fc.vis_prj_prossimo')) + '</div>';

        h += '<a class="prj-event" href="../planner/planner.php?date=' + esc(d.evento.date) + '">'

           + '<span class="prj-event-day">' + esc(fcFormattaData(d.evento.date)) + (d.evento.time ? ' · ' + esc(d.evento.time) : '') + '</span>'

           + '<span class="prj-event-title">' + esc(d.evento.title) + '</span>'

           + '<span class="prj-event-when">' + esc(fcQuandoTesto(d.evento.giorni)) + '</span></a>';

        h += '</div>';

    }



    if (d.figli_el && d.figli_el.length) {

        h += '<div class="prj-block"><div class="prj-block-lab">' + esc(T('fc.vis_prj_sottoprogetti')) + '</div><div class="prj-children">';

        d.figli_el.forEach(function(c){

            h += '<a class="prj-child' + (c.done ? ' done' : '') + '" href="../projects/progetti.php?id=' + encodeURIComponent(c.id) + '"'

               + ' style="--prj:' + esc(c.color) + ';"><span class="prj-child-dot"></span>' + esc(c.name) + '</a>';

        });

        if (d.figli > d.figli_el.length) {

            h += '<span class="prj-child more">+' + (d.figli - d.figli_el.length) + '</span>';

        }

        h += '</div></div>';

    }



    if (d.mazzi && d.mazzi.length) {

        h += '<div class="prj-block"><div class="prj-block-lab">' + esc(T('fc.vis_prj_mazzi')) + '</div><div class="prj-decks">';

        d.mazzi.forEach(function(m){

            h += '<a class="prj-deck' + (m.corrente ? ' qui' : '') + '" href="' + fcUrl('fc_view.php?category_id=' + encodeURIComponent(m.id)) + '"'

               + ' style="--prj:' + esc(m.color) + ';"><span class="prj-deck-dot"></span>'

               + '<span class="prj-deck-name">' + esc(m.name) + '</span>'

               + '<span class="prj-deck-n">' + m.carte + '</span>'

               + (m.corrente ? '<span class="prj-deck-qui">' + esc(T('fc.vis_prj_qui')) + '</span>' : '')

               + '</a>';

        });

        h += '</div></div>';

    }



    return h;

}



function fcStatBox(valore, etichetta, sotto){

    return '<div class="prj-stat"><div class="prj-stat-v">' + fcEscapeHtml(valore) + '</div>'

         + '<div class="prj-stat-l">' + fcEscapeHtml(etichetta) + '</div>'

         + (sotto ? '<div class="prj-stat-s">' + fcEscapeHtml(sotto) + '</div>' : '')

         + '</div>';

}



function fcBarra(etichetta, fatti, totale, colore){

    var pct = totale ? Math.round(fatti / totale * 100) : 0;

    return '<div class="prj-bar" style="--prj:' + fcEscapeHtml(colore) + ';">'

         + '<div class="prj-bar-top"><span>' + fcEscapeHtml(etichetta) + '</span><span>' + pct + '%</span></div>'

         + '<div class="prj-bar-track"><span style="width:' + pct + '%"></span></div></div>';

}



function fcRigaData(etichetta, iso, giorni, chiuso){

    /* In rosso solo se e' passata e il progetto e' ancora aperto: su un

       progetto chiuso una data passata e' la normalita', non un allarme. */

    var scaduta = (giorni !== null && giorni < 0 && !chiuso);

    return '<div class="prj-date' + (scaduta ? ' late' : '') + '">'

         + '<span class="prj-date-lab">' + fcEscapeHtml(etichetta) + '</span>'

         + '<span class="prj-date-val">' + fcEscapeHtml(fcFormattaData(iso)) + '</span>'

         + '<span class="prj-date-when">' + fcEscapeHtml(fcQuandoTesto(giorni)) + '</span></div>';

}



document.getElementById('catColorSheet').addEventListener('click', function(e){ if (e.target === this) closeSheet('catColorSheet'); });

document.getElementById('projectSheet').addEventListener('click', function(e){ if (e.target === this) closeSheet('projectSheet'); });

(function(){

    var input = document.getElementById('catPillNameInput');

    if (!input) return;

    input.addEventListener('keydown', function(e){

        if (e.key === 'Enter') { e.preventDefault(); fcChiudiRinomina(true); }

        else if (e.key === 'Escape') { e.preventDefault(); fcChiudiRinomina(false); }

    });

    input.addEventListener('blur', function(){ fcChiudiRinomina(true); });

    input.addEventListener('click', function(e){ e.stopPropagation(); });

})();

</script>

<script>

/* ================================================================

   VISTA A FLASHCARD — una carta per volta, si gira al tocco.

   Il mazzo si costruisce da quello che e' VISIBILE in pagina: filtro,

   ricerca e risultati nelle sottocartelle valgono anche qui, senza dover

   rifare da capo la stessa selezione lato server.

   ================================================================ */

(function(){

    'use strict';



    var mazzo = [], idx = 0, girata = false, occupato = false;

    var fcv, inner, qEl, aEl, cnt, tot, bar, prevB, nextB, card, edit, stage;

    var barSib, barSibWrap, parentStrip, parentTxt, parentStripA, parentTxtA;

    var idxPadre = -1;   /* indice nel mazzo della carta padre di quella corrente */

    var PASSO_EXTRA = 24;   /* aria fra una carta e l'altra nel carosello */



    function elementi(){

        fcv   = document.getElementById('fcv');

        inner = document.getElementById('fcvInner');

        qEl   = document.getElementById('fcvQ');

        aEl   = document.getElementById('fcvA');

        cnt   = document.getElementById('fcvIdx');

        tot   = document.getElementById('fcvTot');

        bar   = document.getElementById('fcvBar');

        prevB = document.getElementById('fcvPrev');

        nextB = document.getElementById('fcvNext');

        card  = document.getElementById('fcvCard');

        edit  = document.getElementById('fcvEdit');

        stage = document.getElementById('fcvStage');

        barSib     = document.getElementById('fcvBarSib');

        barSibWrap = document.getElementById('fcvBarSibWrap');

        parentStrip = document.getElementById('fcvParent');

        parentTxt   = document.getElementById('fcvParentTxt');

        parentStripA = document.getElementById('fcvParentA');

        parentTxtA   = document.getElementById('fcvParentTxtA');

    }



    /* Un lato della carta senza la sua etichetta: l'etichetta la mette gia' il

       visore, ripeterla sarebbe scriverla due volte. */

    function latoHtml(lato){

        if (!lato) return '';

        var copia = lato.cloneNode(true);

        var lab = copia.querySelector('.qa-label');

        if (lab) lab.remove();

        return copia.innerHTML;

    }



    /* I titoli non entrano nel mazzo (non hanno .qa-side, quindi il giro qui
       sotto li salta da solo), ma il loro testo serve comunque: e' quello che
       le loro sottocarte mostrano nella striscia in cima. Si raccoglie a parte,
       per id del padre. */
    var titoli = {};

    function costruisciMazzo(){

        mazzo = [];

        titoli = {};

        $all('.fc-card.fc-parent.is-titolo').forEach(function(c){
            var t = c.querySelector('.fc-title-txt');
            titoli[c.getAttribute('data-id') || ''] = t ? t.textContent.trim() : '';
        });

        $all('.fc-card').forEach(function(c){

            if (c.style.display === 'none') return;

            var g = c.closest('.fc-group');

            if (g && g.style.display === 'none') return;

            var gDeep = c.closest('.fc-deep-group');

            if (gDeep && gDeep.classList.contains('collapsed')) return;



            var lati = c.querySelectorAll(':scope > .swipe-content > .qa-side');

            if (!lati.length) return;

            var esempi = c.querySelector(':scope > .swipe-content > .qa-examples');



            /* Il testo semplice della domanda serve per la striscia "carta

               padre" mostrata sulle figlie: li' bastano le prime righe, e il

               testo nudo si taglia in modo prevedibile (l'HTML no). */

            var testoQ = lati[0].querySelector('.qa-text');



            mazzo.push({

                q: latoHtml(lati[0]),

                a: latoHtml(lati[1]) + (esempi ? esempi.outerHTML : ''),

                url: c.getAttribute('data-edit-url') || '',

                figlia: c.classList.contains('fc-child'),

                id: c.getAttribute('data-id') || '',

                idPadre: c.getAttribute('data-parent-id') || '',

                testoQ: testoQ ? testoQ.textContent.trim() : '',

                el: c   /* la carta nell'elenco: serve a capire da quale partire */

            });

        });

    }



    /* Indici dei fratelli di una sotto-carta (le figlie dello stesso padre),

       nell'ordine in cui stanno nel mazzo. */

    function fratelliDi(i){

        var d = mazzo[i];

        if (!d || !d.figlia || !d.idPadre) return [];

        var out = [];

        for (var k = 0; k < mazzo.length; k++) {

            if (mazzo[k].figlia && mazzo[k].idPadre === d.idPadre) out.push(k);

        }

        return out;

    }



    function apriRiquadroTitolo(){
        var d = mazzo[idx];
        if (!d || !d.idPadre) return;
        var carta = document.querySelector('.fc-card.fc-parent.is-titolo[data-id="' + d.idPadre + '"]');
        if (!carta) return;

        var modal = document.getElementById('titModal');
        document.getElementById('titModalTxt').textContent = titoli[d.idPadre] || '';
        /* L'indirizzo di modifica e' quello della carta, preso dalla carta
           stessa: e' gia' li' e vale anche quando la carta viene ridisegnata. */
        document.getElementById('titModalEdit').dataset.url = carta.getAttribute('data-edit-url') || '';
        modal.classList.add('on');
        modal.setAttribute('aria-hidden', 'false');
        if (window.buzz) buzz(8);
    }

    function chiudiRiquadroTitolo(){
        var modal = document.getElementById('titModal');
        modal.classList.remove('on');
        modal.setAttribute('aria-hidden', 'true');
    }

    document.addEventListener('click', function(e){
        if (!e.target || !e.target.closest) return;
        if (e.target.closest('#titModalBack')) { chiudiRiquadroTitolo(); return; }
        var ed = e.target.closest('#titModalEdit');
        if (!ed) return;
        var url = ed.dataset.url || '';
        chiudiRiquadroTitolo();
        if (!url) return;
        /* Il popup di modifica sopra il visore, senza uscire da dove si e'.
           Salvando si ricarica: il titolo cambiato deve comparire su tutte le
           sottocarte, non solo su quella che si stava guardando. */
        if (typeof apriModificaPopup === 'function') apriModificaPopup(url, function(){ window.location.reload(); });
        else window.location.href = url;
    });

    /* Il titolo del gruppo di cui questa carta e' figlia, se il padre e' un
       titolo (e quindi non sta nel mazzo). Stringa vuota se non lo e'. */
    function testoTitoloDi(i){
        var d = mazzo[i];
        if (!d || !d.figlia || !d.idPadre) return '';
        return titoli[d.idPadre] || '';
    }

    function indicePadreDi(i){

        var d = mazzo[i];

        if (!d || !d.figlia || !d.idPadre) return -1;

        for (var k = 0; k < mazzo.length; k++) {

            if (!mazzo[k].figlia && mazzo[k].id === d.idPadre) return k;

        }

        return -1;

    }



    function riempi(i){

        var d = mazzo[i];

        qEl.innerHTML = d.q;

        aEl.innerHTML = d.a;

        cnt.textContent = (i + 1);

        bar.style.width = ((i + 1) / mazzo.length * 100) + '%';

        prevB.classList.toggle('off', i === 0);

        nextB.classList.toggle('off', i === mazzo.length - 1);

        qEl.scrollTop = 0; aEl.scrollTop = 0;

        card.classList.toggle('is-child', !!d.figlia);

        edit.style.display = d.url ? '' : 'none';



        /* Barra dei fratelli: solo sulle sotto-carte, e solo se ce n'e' piu'

           di una - con un fratello solo direbbe sempre "1 di 1".

           Non si usa hidden: l'elemento resta sempre nel flusso e si limita a

           diventare invisibile, altrimenti comparendo spingerebbe le carte piu'

           in basso e a fine animazione si vedrebbe uno scatto. */

        var frat = fratelliDi(i);

        if (frat.length > 1) {

            var pos = frat.indexOf(i) + 1;

            barSibWrap.classList.remove('is-off');

            barSib.style.width = (pos / frat.length * 100) + '%';

        } else {

            barSibWrap.classList.add('is-off');

            barSib.style.width = '0%';

        }



        /* Striscia della carta padre: le prime righe della sua domanda, uguali

           per tutti i fratelli. L'indice del padre si tiene da parte qui, cosi'

           il tocco non deve ricalcolarlo. */

        idxPadre = indicePadreDi(i);

        var titolo = testoTitoloDi(i);

        /* Due sorgenti per la stessa striscia: la carta padre se e' nel mazzo,
           altrimenti il titolo del gruppo. Cambia anche cosa fa il tocco, ed e'
           per questo che lo stato finisce su un attributo: chi ascolta i click
           non deve ricalcolare niente. */
        var testoStriscia = (idxPadre >= 0) ? mazzo[idxPadre].testoQ : titolo;

        [parentStrip, parentStripA].forEach(function (strip) {
            if (!strip) return;
            if (!testoStriscia) { strip.hidden = true; return; }
            var t = strip.querySelector('.fcv-parent-txt');
            if (t) t.textContent = testoStriscia;
            strip.classList.toggle('is-titolo', idxPadre < 0);
            strip.hidden = false;
        });

    }



    /* Salto diretto a una carta (dalla striscia del padre): non e' una

       sfogliata fra vicine, quindi niente carosello - solo una dissolvenza

       breve, che dice "siamo altrove" senza fingere una continuita' che non

       c'e'. */

    function vaiAIndice(ni){

        if (occupato || ni < 0 || ni >= mazzo.length || ni === idx) return;

        occupato = true;

        pulisciVicini();

        try {

            var via = card.animate([{ opacity:1, transform:'none' },

                                    { opacity:0, transform:'scale(.96)' }],

                                   { duration:150, easing:'ease-in', fill:'forwards' });

            via.onfinish = function(){

                idx = ni; raddrizza(true); riempi(idx);

                var dentro = card.animate([{ opacity:0, transform:'scale(1.03)' },

                                           { opacity:1, transform:'none' }],

                                          { duration:260, easing:'cubic-bezier(.22,1,.36,1)', fill:'both' });

                via.cancel();

                dentro.onfinish = function(){ dentro.cancel(); occupato = false; };

                buzz(8);

            };

        } catch(e){

            idx = ni; raddrizza(true); riempi(idx); occupato = false;

        }

    }



    function raddrizza(subito){

        girata = false;

        if (subito) {

            inner.style.transition = 'none';

            inner.classList.remove('flipped');

            void inner.offsetWidth;

            inner.style.transition = '';

        } else {

            inner.classList.remove('flipped');

        }

    }



    /* ---------- Carosello: la carta accanto esiste davvero ----------

       Prima si animava la stessa carta due volte (fuori, poi di nuovo dentro

       col contenuto nuovo): fra le due animazioni ricompariva per un istante

       quella vecchia. Ora le carte vicine sono elementi veri, messi ai lati

       e mossi insieme: quella che esce e quella che entra si vedono nello

       stesso momento, come sfogliando davvero. */

    var vicinoPrev = null, vicinoNext = null, passo = 0;

    var animCard = null, animPrev = null, animNext = null;   /* animazioni in volo, da poter cancellare */



    function creaVicino(i){

        if (i < 0 || i >= mazzo.length) return null;

        var el = card.cloneNode(true);

        el.removeAttribute('id');

        el.classList.add('fcv-vicino');

        el.classList.toggle('is-child', !!mazzo[i].figlia);

        $all('[id]', el).forEach(function(n){ n.removeAttribute('id'); });

        var f = el.querySelector('.fcv-front .fcv-body');

        var b = el.querySelector('.fcv-backface .fcv-body');

        if (f) f.innerHTML = mazzo[i].q;

        if (b) b.innerHTML = mazzo[i].a;

        var inn = el.querySelector('.fcv-inner');

        /* La carta accanto e' sempre sulla domanda: si arriva sempre a una

           carta da indovinare, non a una gia' girata. */

        if (inn) { inn.classList.remove('flipped'); inn.style.transition = 'none'; }

        /* La striscia arriva dalla copia, quindi porterebbe il padre della

           carta CORRENTE: va riscritta con quello della carta accanto, o tolta

           se quella e' un padre. */

        /* Sono due, ora — una per faccia — quindi querySelectorAll e non
           querySelector: un solo elemento trovato avrebbe aggiornato solo
           la prima delle due, lasciando l'altra ferma al valore vecchio. */
        var strips = el.querySelectorAll('.fcv-parent-strip');

        if (strips.length) {

            var ip = indicePadreDi(i);

            var testoStriscia = (ip >= 0) ? mazzo[ip].testoQ : testoTitoloDi(i);

            strips.forEach(function (strip) {

                if (!testoStriscia) { strip.hidden = true; return; }

                var t = strip.querySelector('.fcv-parent-txt');

                if (t) t.textContent = testoStriscia;

                strip.classList.toggle('is-titolo', ip < 0);

                strip.hidden = false;

            });

        }

        var ed = el.querySelector('.fcv-edit');

        if (ed) ed.remove();

        stage.appendChild(el);

        return el;

    }



    function preparaVicini(){

        if (vicinoPrev || vicinoNext) return;

        passo = (card.offsetWidth || 320) + PASSO_EXTRA;

        vicinoPrev = creaVicino(idx - 1);

        vicinoNext = creaVicino(idx + 1);

        posiziona(0);

    }



    /* Il linguaggio visivo di prima, tenuto sincronizzato su piu' elementi:

       la carta al centro ruota leggermente e sfuma andandosene (si sta

       "staccando"), quella accanto cresce e si schiarisce avvicinandosi al

       centro (sta "arrivando"). Una sola funzione per il trascinamento in

       diretta e per il volo finale, cosi' il volo riparte esattamente da

       dove il dito ha lasciato la carta invece che da una posa ferma. */

    function trasfCorrente(dx){

        var rot = dx / 26;

        var op = Math.max(.5, 1 - Math.abs(dx) / 480);

        return { transform: 'translateX(' + dx + 'px) rotate(' + rot.toFixed(2) + 'deg)', opacity: op };

    }



    function trasfVicino(base, dx){

        var pos = base + dx;

        var vicinanza = Math.max(0, 1 - Math.min(1, Math.abs(pos) / passo));

        var scala = .92 + .08 * vicinanza;

        var op = .55 + .45 * vicinanza;

        var rot = pos / 90;

        return { transform: 'translateX(' + pos + 'px) scale(' + scala.toFixed(3) + ') rotate(' + rot.toFixed(2) + 'deg)', opacity: op };

    }



    function applica(el, t){

        if (!el) return;

        el.style.transform = t.transform;

        el.style.opacity = t.opacity;

    }



    function posiziona(dx){

        applica(card, trasfCorrente(dx));

        applica(vicinoPrev, trasfVicino(-passo, dx));

        applica(vicinoNext, trasfVicino(passo, dx));

    }



    function pulisciVicini(){

        if (vicinoPrev) { vicinoPrev.remove(); vicinoPrev = null; }

        if (vicinoNext) { vicinoNext.remove(); vicinoNext = null; }

        card.style.transform = '';

        card.style.opacity = '';

    }



    /* Il volo finale: non piu' una transizione CSS che ripartiva sempre dalla

       stessa posa ferma (da li' la sensazione "meccanica"), ma un'animazione

       vera che parte esattamente da dove il dito ha lasciato la carta e

       arriva con una piccola frenata elastica in fondo, la stessa curva di

       prima. verso: 1 avanti, -1 indietro, 0 torna al suo posto. */

    function concludi(verso, dxPartenza){

        if (verso && !mazzo[idx + verso]) verso = 0;

        occupato = true;



        var dx0 = dxPartenza || 0;

        /* Esattamente un passo, senza margine in piu'. Con un moltiplicatore

           (prima 1.06, per buttare la carta uscente ben oltre il bordo) tutto

           il carosello si spostava di quel 6% in eccesso: la carta ENTRANTE

           finiva il volo un po' oltre il centro, e al momento di prendere il

           posto di quella vera scattava indietro per rimettersi al suo posto.

           Un passo intero basta comunque a portare fuori schermo l'uscente:

           passo e' gia' la larghezza della carta piu' l'aria fra una e

           l'altra. */

        var dxF = verso ? (verso * -passo) : 0;

        var durata = verso ? 300 : 260;

        var easing = verso ? 'cubic-bezier(.19,.86,.32,1.04)' : 'cubic-bezier(.22,1,.36,1)';



        var partenza = trasfCorrente(dx0);

        var arrivo = trasfCorrente(dxF);

        if (verso) arrivo.opacity = 0;



        animCard = card.animate([partenza, arrivo], { duration: durata, easing: easing, fill: 'forwards' });

        if (vicinoPrev) {

            animPrev = vicinoPrev.animate(

                [trasfVicino(-passo, dx0), trasfVicino(-passo, dxF)],

                { duration: durata, easing: easing, fill: 'forwards' });

        }

        if (vicinoNext) {

            animNext = vicinoNext.animate(

                [trasfVicino(passo, dx0), trasfVicino(passo, dxF)],

                { duration: durata, easing: easing, fill: 'forwards' });

        }



        setTimeout(function(){

            /* La carta vera si porta dietro il fotogramma finale prima di

               cancellare l'animazione: cosi' lo scambio non si vede. */

            applica(card, arrivo);

            if (animCard) { animCard.cancel(); animCard = null; }

            if (animPrev) { animPrev.cancel(); animPrev = null; }

            if (animNext) { animNext.cancel(); animNext = null; }



            if (verso) {

                idx += verso;

                raddrizza(true);

                riempi(idx);

            }

            pulisciVicini();

            occupato = false;

            if (verso) buzz(7);

        }, durata + 10);

    }



    function vai(verso){

        if (occupato) return;

        if (!mazzo[idx + verso]) return;

        preparaVicini();

        concludi(verso, 0);

    }



    /* Quale carta si stava guardando nell'elenco: quella che occupa piu'

       spazio nella parte utile dello schermo (sotto l'intestazione fissa).

       Non la prima visibile in assoluto: se una carta e' tagliata a meta' in

       cima e sotto ce n'e' una intera, e' quest'ultima che si stava leggendo. */

    function indiceInVista(){

        var h = document.getElementById('appHeader');

        var alto = h ? h.offsetHeight : 0;

        var finestra = window.innerHeight;

        var migliore = 0, miglioreArea = -1;

        for (var k = 0; k < mazzo.length; k++) {

            var el = mazzo[k].el;

            if (!el || !el.getBoundingClientRect) continue;

            var r = el.getBoundingClientRect();

            var area = Math.min(r.bottom, finestra) - Math.max(r.top, alto);

            if (area > miglioreArea) { miglioreArea = area; migliore = k; }

        }

        return migliore;

    }



    window.fcvApri = function(i){

        elementi();

        costruisciMazzo();

        if (!mazzo.length) { showToast(T('fc.vis_fcv_vuoto')); return; }

        /* Senza un indice esplicito (il tasto flottante) si riparte da dove si

           stava leggendo, non dall'inizio del mazzo. */

        if (i === undefined || i === null) i = indiceInVista();

        idx = Math.max(0, Math.min(mazzo.length - 1, i || 0));

        tot.textContent = mazzo.length;

        raddrizza(true);

        riempi(idx);

        bloccaPagina(true);

        fcv.classList.add('on');

        fcv.setAttribute('aria-hidden', 'false');

        try {

            card.animate([{ opacity:0, transform:'translateY(46px) scale(.92)' }, { opacity:1, transform:'none' }],

                         { duration: 430, easing:'cubic-bezier(.24,1.2,.35,1)' });

        } catch(e){}

        buzz(8);

    };



    /* Apre il visore posizionandolo su una carta precisa invece che su un

       indice: al ritorno da una modifica si conosce la carta, non la sua

       posizione nel mazzo (che nel frattempo puo' essere cambiata). */

    window.fcvApriSuCarta = function(tipo, id){

        elementi();

        costruisciMazzo();

        if (!mazzo.length) return false;

        var vuoleFiglia = (tipo === 'child');

        for (var k = 0; k < mazzo.length; k++) {

            if (mazzo[k].id === String(id) && !!mazzo[k].figlia === vuoleFiglia) {

                window.fcvApri(k);

                return true;

            }

        }

        return false;

    };



    function chiudi(){

        if (!fcv) return;

        pulisciVicini();

        fcv.classList.remove('on');

        fcv.setAttribute('aria-hidden', 'true');

        bloccaPagina(false);

    }



    /* ---------- La pagina sotto deve stare ferma ----------

       overflow:hidden sul body non basta: il dito continua a trascinare la

       pagina dietro al visore. Si congela la posizione con position:fixed e

       la si restituisce identica alla chiusura. */

    var scrollSalvato = 0;

    function bloccaPagina(blocca){

        var b = document.body;

        if (blocca) {

            scrollSalvato = window.pageYOffset || document.documentElement.scrollTop || 0;

            b.classList.add('fcv-lock');

            b.style.top = (-scrollSalvato) + 'px';

        } else {

            b.classList.remove('fcv-lock');

            b.style.top = '';

            window.scrollTo(0, scrollSalvato);

        }

    }



    function gira(){

        if (occupato) return;

        girata = !girata;

        inner.classList.toggle('flipped', girata);

        buzz(9);

    }



    document.addEventListener('click', function(e){

        if (!e.target || !e.target.closest) return;

        if (e.target.closest('#fcvClose')) { chiudi(); return; }

        if (!fcv || !fcv.classList.contains('on')) return;

        if (e.target.closest('#fcvEdit')) {

            var d = mazzo[idx];

            if (d && d.url) {

                /* Si lascia comunque detto dov'eravamo: al ricaricamento con
                   cui si chiude il popup (qui sotto) il visore si riapre da
                   solo sulla stessa carta invece di lasciare l'elenco —
                   esattamente come faceva prima, quando si usciva davvero
                   dalla pagina per andare a modificare. */
                try { sessionStorage.setItem('nisama_fcv_riapri', (d.figlia ? 'child:' : 'parent:') + d.id); } catch(err){}

                /* Ma la navigazione vera non serve piu': il visore resta
                   aperto sotto al popup, che si apre sopra di lui. Nessun
                   trucco per "tornare dove si era": non ci si e' mai
                   spostati. */
                if (typeof apriModificaPopup === 'function') {
                    apriModificaPopup(d.url, fcRicaricaSuCarta);
                } else {
                    window.location.href = d.url;
                }

            }

            return;

        }

        var striscia = e.target.closest('#fcvParent, #fcvParentA');
        if (striscia) {
            /* Su una striscia normale si va alla carta padre, che nel mazzo c'e'.
               Su un titolo non si puo': quella carta nel mazzo non c'e' apposta,
               non e' una carta da rigirare. Portarci lo stesso vorrebbe dire
               mostrare una domanda senza risposta — cioe' esattamente la cosa da
               cui il titolo nasce. Si apre invece un riquadrino che mostra il
               titolo e offre l'unica cosa sensata da farci: correggerlo. */
            if (striscia.classList.contains('is-titolo')) apriRiquadroTitolo();
            else vaiAIndice(idxPadre);
            return;
        }

        if (e.target.closest('#fcvPrev')) { vai(-1); return; }

        if (e.target.closest('#fcvNext')) { vai(1); return; }

        if (e.target.closest('#fcvFlip')) { gira(); return; }

        if (e.target.closest('#fcvCard')) {

            /* Dentro la carta ci sono immagini che ingrandiscono, audio da far

               partire e il riquadro degli esempi che si apre a tutto schermo:

               quelli hanno la precedenza sul giro. */

            if (!e.target.closest('a, button, audio, .audio-player, .qa-image, .qa-examples-box')) gira();

            return;

        }

        if (e.target.closest('.fcv-back')) { chiudi(); return; }

    });



    /* Sfogliata orizzontale, con tutto il carosello che segue il dito. */

    (function(){

        var x0 = 0, y0 = 0, attivo = false, deciso = '', dxAttuale = 0, punti = [];

        document.addEventListener('touchstart', function(e){

            if (!fcv || !fcv.classList.contains('on') || occupato) return;

            if (!e.target.closest || !e.target.closest('#fcvStage')) return;

            if (e.touches.length !== 1) return;

            /* Il carosello degli esempi ha una sua sfogliata orizzontale (cambia

               esempio, non carta): se il gesto nasce li' dentro e' suo. */

            if (e.target.closest('.qa-examples-box')) return;

            /* Se il dito parte dentro qualcosa che scorre di suo (una formula

               larga, un testo che sborda), quel gesto e' suo: sfogliare qui

               vorrebbe dire non poter piu' leggere la formula. */

            if (fcScorrevoleOrizz(e.target, stage)) return;

            x0 = e.touches[0].clientX; y0 = e.touches[0].clientY;

            attivo = true; deciso = ''; dxAttuale = 0;

            punti = [{ x: x0, t: e.timeStamp }];

        }, { passive: true });



        document.addEventListener('touchmove', function(e){

            if (!attivo) return;

            var dx = e.touches[0].clientX - x0;

            var dy = e.touches[0].clientY - y0;

            if (!deciso) {

                if (Math.abs(dx) < 10 && Math.abs(dy) < 10) return;

                /* Un verso solo, e con un margine chiaro: se si comincia in

                   verticale si lascia scorrere il testo lungo. */

                if (Math.abs(dx) <= Math.abs(dy) * 1.3) { attivo = false; return; }

                deciso = 'x';

                preparaVicini();

            }

            /* Ai bordi del mazzo la carta si muove poco: si sente che di la'

               non c'e' piu' niente invece di sbattere contro un muro. */

            var freno = ((dx < 0 && !mazzo[idx + 1]) || (dx > 0 && !mazzo[idx - 1])) ? 0.28 : 1;

            dxAttuale = dx * freno;

            posiziona(dxAttuale);



            /* Solo gli ultimi istanti contano per la velocita' di rilascio:

               una sfogliata lunga che finisce lenta deve sentirsi lenta. */

            punti.push({ x: e.touches[0].clientX, t: e.timeStamp });

            if (punti.length > 6) punti.shift();

        }, { passive: true });



        function fine(e){

            if (!attivo) return;

            attivo = false;

            if (deciso !== 'x') return;



            var velocita = 0;

            if (punti.length >= 2) {

                var a = punti[0], b = punti[punti.length - 1];

                var dt = b.t - a.t;

                if (dt > 0) velocita = (b.x - a.x) / dt;   /* px al millisecondo */

            }



            /* Un colpo secco vale anche se il dito non ha percorso molta

               strada: e' la differenza fra una sfogliata "trascinata fino in

               fondo" e una "data con un colpetto", entrambe naturali. */

            var soglia = Math.min(90, (card.offsetWidth || 320) * 0.22);

            var scatto = Math.abs(velocita) > 0.55;

            var verso = 0;

            if (Math.abs(dxAttuale) > soglia || scatto) {

                verso = (dxAttuale !== 0 ? dxAttuale < 0 : velocita < 0) ? 1 : -1;

            }

            concludi(verso, dxAttuale);

        }

        document.addEventListener('touchend', fine);

        document.addEventListener('touchcancel', fine);

    })();



    document.addEventListener('keydown', function(e){

        if (!fcv || !fcv.classList.contains('on')) return;

        if (e.key === 'Escape') { e.preventDefault(); chiudi(); }

        else if (e.key === 'ArrowLeft') { e.preventDefault(); vai(-1); }

        else if (e.key === 'ArrowRight') { e.preventDefault(); vai(1); }

        else if (e.key === ' ' || e.key === 'ArrowUp' || e.key === 'ArrowDown') { e.preventDefault(); gira(); }

    });



    /* ---------- Il tasto flottante alla stessa altezza di quello della

       sidebar. La misura si prende a video invece di ricopiare un numero:

       la sidebar ha il suo foglio di stile e un giorno potrebbe cambiarlo,

       e due numeri uguali scritti in due posti diversi restano uguali solo

       finche' qualcuno non ne tocca uno. ---------- */

    function fabDellaSidebar(){

        var candidati = ['#sbFab', '.sb-fab', '#sidebarFab', '.sidebar-fab'];

        for (var i = 0; i < candidati.length; i++) {

            var el = document.querySelector(candidati[i]);

            if (el && el.id !== 'fcvFab') return el;

        }

        var trovato = null;

        $all('[id*="fab" i], [class*="fab" i]').forEach(function(el){

            if (trovato || el.id === 'fcvFab' || el.closest('#fcv')) return;

            var st = getComputedStyle(el);

            if (st.position === 'fixed' && el.offsetHeight > 20) trovato = el;

        });

        return trovato;

    }



    function allineaFab(){

        var mio = document.getElementById('fcvFab');

        var sb = fabDellaSidebar();

        if (!mio || !sb) return;

        var r = sb.getBoundingClientRect();

        if (!r.height) return;

        document.documentElement.style.setProperty('--fcv-fab-bottom',

            Math.max(0, Math.round(window.innerHeight - r.bottom)) + 'px');

        document.documentElement.style.setProperty('--fcv-fab-size', Math.round(r.height) + 'px');

    }



    window.fcvAllineaFab = allineaFab;

    window.addEventListener('resize', allineaFab);

})();

</script>











<script>

/* ================================================================

   SELECT MODE / AZIONI DI MASSA

   ================================================================

   Tre stati, non uno. Prima c'era solo "sto selezionando"; ora dentro la
   selezione possono aprirsi due modalita' di lavoro che cambiano le regole:

     ''       selezione normale: si scelgono carte e si fa qualcosa con loro
     'unjoin' SEPARA: restano solo i padri con i loro figli, e si spuntano
              soltanto i figli — un padre non si puo' separare da se stesso
     'join'   UNISCI: restano solo i padri, se ne sceglie uno e poi si tocca
              la carta che deve diventargli padre

   Lo stato vive in document.body.dataset.bulkAction (piu' le classi
   action-join / action-unjoin, che servono al CSS). Il CSS non sa fare "se",
   quindi tutte le decisioni su cosa mostrare passano da qui.

   ================================================================ */

/* Quale modalita' di struttura e' aperta ('', 'join', 'unjoin'). */
function modoStruttura(){ return document.body.dataset.bulkAction || ''; }

/* Siamo nel momento in cui si tocca la carta che diventera' padre? */
function stoScegliendoIlPadre(){ return document.body.classList.contains('join-picking'); }

/* La ricerca della selezione scrive nello stesso campo di quella normale:
   un filtro solo, così uscendo dalla selezione si ritrova quello che si
   stava cercando. */
function fcCercaInSelezione(testo){
    var f = document.getElementById('filterText');
    if (f) f.value = testo;
    fcOnSearchInput();
}

function enterSelectMode(){
    document.body.classList.add('select-mode');
    document.getElementById('normalToolbar').style.display = 'none';
    document.getElementById('selectToolbar').style.display = 'flex';
    var rs = document.getElementById('selSearchRow');
    if (rs) {
        rs.style.display = 'flex';
        document.getElementById('selSearch').value = document.getElementById('filterText').value;
    }
    buzz(8);
    aggiornaBarraSelezione();
    filterList();
}

function exitSelectMode(){
    document.body.classList.remove('select-mode', 'action-join', 'action-unjoin',
                                   'join-picking', 'is-compact');
    document.body.dataset.bulkAction = '';
    document.getElementById('normalToolbar').style.display = 'flex';
    document.getElementById('selectToolbar').style.display = 'none';
    var rs = document.getElementById('selSearchRow');
    if (rs) rs.style.display = 'none';
    document.getElementById('modeBanner').style.display = 'none';
    document.getElementById('stActions').style.display = 'flex';
    document.getElementById('selectAllBtn').style.display = '';
    document.getElementById('compactBtn').classList.remove('is-on');
    document.getElementById('hiddenFatherId').value = '';
    $all('.st-action').forEach(function(b){ b.classList.remove('active-pick'); });
    $all('.record-cb').forEach(function(cb){ cb.checked = false; });
    $all('.fc-card').forEach(function(c){ c.classList.remove('selected-row', 'is-open'); });
    $all('.fc-group').forEach(function(g){ g.classList.remove('kids-open'); });
    aggiornaBarraSelezione();
    filterList();
}

/* La X non chiude sempre la stessa cosa: chiude LO STRATO PIU' ESTERNO.
   Aperta la modalita' Separa, la X toglie quella e riporta alla selezione
   normale — perche' e' li' che si e' appena entrati e quasi sempre e' quello
   che si vuole disfare. Solo dalla selezione nuda la X esce del tutto.
   Un gesto solo che disfa un passo per volta, senza dover indovinare quale
   dei due gesti serviva. */
function onSelectCancel(){
    if (stoScegliendoIlPadre()) { annullaSceltaPadre(); return; }
    if (modoStruttura() !== '') { chiudiModalitaStruttura(); return; }
    exitSelectMode();
}



/* ---------------- Conteggio e barra ---------------- */

function contaSpuntate(){ return $all('.record-cb:checked').length; }

/* Decide, ogni volta che qualcosa cambia, cosa si vede nella barra. Un posto
   solo: prima queste stesse decisioni erano sparse fra enterSelectMode,
   exitSelectMode e onBulkActionPick, e bastava aggiungere un caso perche' due
   di loro si contraddicessero. */
function aggiornaBarraSelezione(){
    var n = contaSpuntate();
    var modo = modoStruttura();
    var el = document.getElementById('selectCount');
    if (el) el.textContent = n;

    /* Sposta ed Elimina hanno senso solo con qualcosa di scelto. La loro
       lineetta se ne va con loro: due lineette di fila sarebbero un divisorio
       che non divide niente.
       E dentro Separa o Unisci non ci sono comunque: li' si stanno scegliendo
       delle carte per RISTRUTTURARLE, e trovarsi accanto un cestino rosso — che
       cancella per sempre, a un tocco di distanza dal pulsante che si sta per
       premere — e' un invito a un errore che non si disfa. Quelle due azioni
       non spariscono, basta uscire dalla modalita'. */
    var conScelte = n > 0 && modo === '';
    document.getElementById('stGruppoScelte').style.display = conScelte ? 'flex' : 'none';
    document.getElementById('stSepScelte').style.display    = conScelte ? 'block' : 'none';

    var bUnisci = document.getElementById('btnUnisci');
    var bSepara = document.getElementById('btnSepara');

    /* Dentro una modalita' l'altra sparisce: in Separa l'elenco mostra i figli,
       e "Unisci" li' non avrebbe niente su cui lavorare. Il pulsante della
       modalita' in corso resta, spento, come promemoria di dove si e' — e si
       riaccende appena c'e' qualcosa da separare o da unire. */
    bUnisci.style.display = (modo === 'unjoin') ? 'none' : '';
    bSepara.style.display = (modo === 'join')   ? 'none' : '';
    bUnisci.classList.toggle('is-spento', modo === 'join'   && n === 0);
    bSepara.classList.toggle('is-spento', modo === 'unjoin' && n === 0);
    bUnisci.classList.toggle('is-pronto', modo === 'join'   && n > 0);
    bSepara.classList.toggle('is-pronto', modo === 'unjoin' && n > 0);

    /* Scegliendo il padre non c'e' piu' niente da spuntare: via tutto tranne
       la X, che e' l'unica cosa che serve se si e' cambiato idea. */
    var scegliendo = stoScegliendoIlPadre();
    document.getElementById('stActions').style.display    = scegliendo ? 'none' : 'flex';
    document.getElementById('selectAllBtn').style.display = scegliendo ? 'none' : '';
    document.getElementById('compactBtn').style.display   = scegliendo ? 'none' : '';

    /* L'intestazione e' fissa in cima e l'elenco le sta sotto grazie a un
       padding alto quanto lei (--header-h, calcolato da syncHeaderHeight). La
       barra della selezione e la riga che spiega la modalita' stanno DENTRO
       l'intestazione: comparendo la fanno crescere, e se nessuno ricalcola
       quel numero l'elenco resta dov'era e finisce coperto. Non e' il banner
       che "sta sopra": e' il posto sotto che non e' stato allargato.
       Dopo il prossimo disegno, cosi' si misura l'altezza vera e non quella
       di un istante fa. */
    requestAnimationFrame(function(){ if (typeof syncHeaderHeight === 'function') syncHeaderHeight(); });
}

/* Nome storico: lo chiamano gli onchange delle caselle nel markup. */
function updateSelectCount(){ aggiornaBarraSelezione(); }

function onRecordCbChange(cb){
    var card = cb.closest('.fc-card');
    if (card) card.classList.toggle('selected-row', cb.checked);
    aggiornaBarraSelezione();
    if (document.body.classList.contains('select-mode')) filterList();
}

/* Le caselle su cui "Tutte" ha effetto: solo quelle visibili, e solo quelle
   che nella modalita' in corso hanno senso. In Separa si spuntano i figli (i
   padri li' non hanno nemmeno la casella); in Unisci si sceglie fra i padri. */
function caselleDiQuestaModalita(){
    var modo = modoStruttura();
    var sel = '.record-cb';
    if (modo === 'unjoin') sel = '.child-cb';
    else if (modo === 'join') sel = '.standalone-cb';
    return $all(sel).filter(function(cb){
        var card = cb.closest('.fc-card');
        if (!card || card.style.display === 'none') return false;
        var gruppo = card.closest('.fc-group');
        if (gruppo && gruppo.style.display === 'none') return false;
        /* Un figlio dentro un padre chiuso e' nel documento ma non a schermo:
           spuntarlo vorrebbe dire scegliere qualcosa che non si sta vedendo. */
        if (card.classList.contains('fc-child') && !card.offsetParent) return false;
        return true;
    });
}

function toggleSelectAll(){
    /* "Tutte" deve voler dire tutte, non "tutte quelle gia' scaricate": si
       aspetta l'elenco intero e poi si spunta. */
    if (!fcTutteCaricate) {
        fcCaricaAltreCarte(true).then(toggleSelectAll);
        return;
    }
    var boxes = caselleDiQuestaModalita();
    var tutteSpuntate = boxes.length > 0 && boxes.every(function(cb){ return cb.checked; });
    boxes.forEach(function(cb){
        cb.checked = !tutteSpuntate;
        var card = cb.closest('.fc-card');
        if (card) card.classList.toggle('selected-row', cb.checked);
    });
    document.getElementById('selectAllBtn').textContent =
        tutteSpuntate ? T('vocab.vi_tutte') : T('vocab.vi_nessuna');
    aggiornaBarraSelezione();
}



/* ---------------- VISTA COMPATTA ---------------- */

/* Una carta per riga: inizio della domanda, inizio della risposta. Tutto il
   lavoro lo fa il CSS (body.is-compact): niente anteprime da costruire, il
   testo e' gia' nella pagina e si limita a una riga sola.
   E' una vista di lavoro, non una preferenza: non si salva, e si spegne da
   sola uscendo dalla selezione. */
function toggleCompactMode(forza){
    var acceso = (typeof forza === 'boolean')
        ? forza
        : !document.body.classList.contains('is-compact');
    document.body.classList.toggle('is-compact', acceso);
    document.getElementById('compactBtn').classList.toggle('is-on', acceso);
    /* Spegnendola, le carte aperte a mano tornano al passo con le altre. */
    if (!acceso) $all('.fc-card.is-open').forEach(function(c){ c.classList.remove('is-open'); });
    buzz(6);
}

/* Apre UNA carta a grandezza vera senza spegnere la vista compatta per tutte
   le altre: serve a controllare cosa c'e' dentro prima di spuntarla.

   Ad aprirla e' la RIGA STESSA, non un pulsante accanto. Il pulsante c'era, ed
   era una freccia a punta in giu' — cioe' lo stesso disegno della freccia
   "sposta giu'" che gli stava a fianco. Due icone quasi identiche, una che
   apre e una che sposta: la differenza si scopriva premendole. Toccare la riga
   che si vuole leggere per intero e' anche piu' diretto di cercare un pulsante,
   e libera le due frecce dal dover essere distinguibili da una terza.
   Il bersaglio e' grande quanto la riga, cosa che sullo schermo di un telefono
   conta piu' di qualunque icona. */
document.addEventListener('click', function(ev){
    if (!document.body.classList.contains('is-compact')) return;
    if (stoScegliendoIlPadre()) return;
    if (Date.now() - (window.fcUltimoRiordino || 0) < 400) return;
    if (!ev.target || !ev.target.closest) return;
    /* Dentro la riga possono esserci cose che hanno gia' un loro significato
       (un lettore audio, un collegamento): li' il tocco e' loro. */
    if (ev.target.closest('button, a, input, audio, .audio-player, .qa-examples')) return;
    var lato = ev.target.closest('.fc-card .qa-side');
    if (!lato) return;
    var card = lato.closest('.fc-card');
    if (!card || !card.closest('#fcList')) return;
    ev.stopPropagation();
    card.classList.toggle('is-open');
    buzz(5);
});

/* Mostra o nasconde le sottocarte di un padre. Vale in modalita' Unisci, dove
   l'elenco tiene i figli chiusi per non far scorrere per pagine. */
function toggleKids(btn){
    var gruppo = btn.closest('.fc-group');
    if (!gruppo) return;
    gruppo.classList.toggle('kids-open');
    buzz(5);
}

/* Su e giu' al posto del trascinamento: nella vista compatta le carte sono
   righe, e trascinare una riga per venti posizioni e' peggio che premere venti
   volte una freccia. Sposta l'unita' giusta — il GRUPPO intero per un padre
   (con i suoi figli appresso), la singola carta per un figlio — e salva subito
   l'ordine con la stessa funzione che usa il trascinamento. */
function moveCardBy(btn, verso){
    var card = btn.closest('.fc-card');
    if (!card) return;
    var eUnPadre = card.classList.contains('fc-parent');
    var unita = eUnPadre ? card.closest('.fc-group') : card;
    if (!unita || !unita.parentElement) return;

    if (unita.getAttribute('data-reorderable') !== '1') {
        showToast(T('fc.vis_ordine_bloccato'));
        return;
    }

    var scope = unita.parentElement;
    var selettore = eUnPadre ? '.fc-group' : '.fc-card.fc-child';
    /* Solo i vicini VISIBILI: con un filtro attivo, saltare sopra una carta
       nascosta darebbe uno spostamento che a schermo non si vede. */
    var vicini = Array.prototype.slice.call(scope.children).filter(function(el){
        return el.matches(selettore) && el.style.display !== 'none';
    });
    var i = vicini.indexOf(unita);
    var j = i + verso;
    if (i < 0 || j < 0 || j >= vicini.length) { buzz(4); return; }

    if (verso < 0) scope.insertBefore(unita, vicini[j]);
    else           scope.insertBefore(vicini[j], unita);

    buzz(8);
    if (typeof window.fcSalvaOrdine === 'function') window.fcSalvaOrdine(unita, scope);
    if (card.scrollIntoView) card.scrollIntoView({ block: 'nearest' });
}



/* ---------------- MODALITA' SEPARA / UNISCI ---------------- */

/* mantieni=true quando la modalita' si apre da una selezione gia' fatta: si e'
   scelto qualcosa e POI si e' premuto Unisci, e quelle carte sono l'oggetto
   del lavoro, non un residuo da buttare. */
function apriModalitaStruttura(modo, mantieni){
    document.body.dataset.bulkAction = modo;
    document.body.classList.remove('action-join', 'action-unjoin');
    document.body.classList.add(modo === 'join' ? 'action-join' : 'action-unjoin');

    /* Le due modalita' esistono per lavorare su molte carte insieme: la vista
       compatta non e' un di piu', e' il motivo per cui si riesce a farlo. */
    toggleCompactMode(true);

    /* Quello che era spuntato prima appartiene alle regole di prima: in Separa
       si spuntano i figli, in Unisci i padri, e portarsi dietro la selezione
       vecchia vorrebbe dire partire con una scelta illegale gia' fatta. */
    if (!mantieni) {
        $all('.record-cb:checked').forEach(function(cb){
            cb.checked = false;
            var card = cb.closest('.fc-card');
            if (card) card.classList.remove('selected-row');
        });
    }

    var banner = document.getElementById('modeBanner');
    document.getElementById('modeBannerText').textContent =
        T(modo === 'join' ? 'fc.vis_modo_unisci' : 'fc.vis_modo_separa');
    banner.style.display = 'flex';

    buzz(10);
    aggiornaBarraSelezione();
    filterList();
}

function chiudiModalitaStruttura(){
    document.body.dataset.bulkAction = '';
    document.body.classList.remove('action-join', 'action-unjoin', 'join-picking');
    document.getElementById('modeBanner').style.display = 'none';
    document.getElementById('hiddenFatherId').value = '';
    $all('.st-action').forEach(function(b){ b.classList.remove('active-pick'); });
    $all('.fc-group').forEach(function(g){ g.classList.remove('kids-open'); });
    $all('.record-cb:checked').forEach(function(cb){
        cb.checked = false;
        var card = cb.closest('.fc-card');
        if (card) card.classList.remove('selected-row');
    });
    aggiornaBarraSelezione();
    filterList();
}

function avviaSceltaPadre(){
    document.body.classList.add('join-picking');
    document.getElementById('modeBannerText').textContent = T('fc.vis_tocca_padre');
    document.getElementById('modeBanner').style.display = 'flex';
    buzz(12);
    aggiornaBarraSelezione();
    filterList();
}

function annullaSceltaPadre(){
    document.body.classList.remove('join-picking');
    document.getElementById('modeBannerText').textContent = T('fc.vis_modo_unisci');
    aggiornaBarraSelezione();
    filterList();
}

/* Il padre si sceglie toccandolo, punto: niente da spuntare e niente da
   confermare. Prima le caselle delle carte non scelte venivano sostituite al
   volo da dei radio e serviva un secondo gesto su un pulsante in fondo allo
   schermo — due passi per dire una cosa sola, e nel frattempo il markup della
   pagina veniva riscritto e poi rimesso a posto. E' lo stesso gesto che si fa
   in fc_insert.php. */
document.addEventListener('click', function(e){
    if (!stoScegliendoIlPadre()) return;
    var card = e.target.closest('.fc-card.fc-parent');
    if (!card) return;

    /* Il tastino "n sottocarte" e' l'eccezione: serve proprio ADESSO, per
       guardare cosa c'e' gia' dentro un padre prima di sceglierlo. Il suo tocco
       resta suo, e la carta non viene scelta. */
    if (e.target.closest('.fc-kids-toggle')) return;

    e.preventDefault();
    e.stopPropagation();

    var cb = card.querySelector('.standalone-cb');
    if (cb && cb.checked) { showToast(T('fc.vis_padre_e_scelta')); return; }

    document.getElementById('hiddenFatherId').value = card.getAttribute('data-id');
    buzz(14);
    /* Scelto il padre si torna alla modalita' Unisci, pronti per il prossimo:
       quasi mai se ne fa uno solo. */
    annullaSceltaPadre();
    eseguiAzioneStruttura('join').then(function(){
        document.getElementById('hiddenFatherId').value = '';
    });
}, true);



/* ---------------- Il pulsante premuto ---------------- */

function onBulkActionPick(action){
    var scelte = contaSpuntate();
    var modo = modoStruttura();

    /* SEPARA e UNISCI hanno due significati a seconda di dove si e':
       - da fuori, senza niente di scelto, APRONO la loro modalita' di lavoro
       - dentro la modalita' (o con delle carte gia' scelte), ESEGUONO
       E' la stessa idea del pulsante che apre un cassetto e poi lo conferma:
       un solo posto dove cercarlo, invece di due pulsanti quasi uguali. */
    if (action === 'unjoin' && modo !== 'unjoin' && scelte === 0) { apriModalitaStruttura('unjoin'); return; }
    if (action === 'join'   && modo !== 'join'   && scelte === 0) { apriModalitaStruttura('join');   return; }

    if (scelte === 0) { showToast(T('fc.vis_serve_carta')); return; }

    $all('.st-action').forEach(function(b){ b.classList.toggle('active-pick', b.dataset.action === action); });

    if (action === 'delete') {
        openDeleteConfirm(scelte, '', function(){ submitBulkForm('delete'); });
        return;
    }

    if (action === 'move') {
        /* Stesso equivoco del pulsante nel percorso, e qui c'era gia': csOpen
           non esiste fuori da fc_deck_selector.php, quindi questo ramo cadeva
           sempre nell'"else" e mostrava un avviso invece del selettore. Il modo
           previsto e' data-cs-open, e un tocco su un elemento che ce l'ha: qui
           lo si simula su un pulsante che sta li' apposta, invisibile. */
        var apri = document.getElementById('apriModaleMove');
        if (apri) apri.click();
        else showToast(T('fc.vis_sposta_dove'));
        return;
    }

    if (action === 'unjoin') {
        if ($all('.standalone-cb:checked').length > 0) {
            showToast(T('fc.vis_solo_sottocarte'));
            return;
        }
        /* Si resta dove si e': stessa modalita', stessa vista compatta, stesso
           punto della pagina. Separare tre sottocarte una dopo l'altra non deve
           voler dire ritrovare tre volte il posto in cui si era. */
        eseguiAzioneStruttura('unjoin');
        return;
    }

    if (action === 'join') {
        var padreConFigli = $all('.standalone-cb:checked').some(function(cb){
            var group = cb.closest('.fc-group');
            return group && group.querySelector('.fc-children .fc-card');
        });
        if (padreConFigli) { showToast(T('fc.vis_no_padre_con_figli')); return; }
        if ($all('.child-cb:checked').length > 0) { showToast(T('fc.vis_no_figlie')); return; }

        /* Si arriva qui da fuori (carte gia' scelte, poi Unisci) o da dentro la
           modalita'. Nel primo caso la modalita' si apre adesso, tenendosi le
           carte scelte: sono loro l'oggetto del lavoro. */
        if (modo !== 'join') apriModalitaStruttura('join', true);
        avviaSceltaPadre();
    }
}

/* Sposta ed Elimina continuano a passare dall'invio normale del modulo: sono
   azioni che si fanno una volta e poi si esce dalla selezione, quindi la
   pagina che si ricarica non toglie niente a nessuno. */
function submitBulkForm(actionValue){
    document.getElementById('hiddenBulkAction').value = actionValue;
    document.getElementById('bulkForm').submit();
}

/* ---------------- SEPARA E UNISCI SENZA RICARICARE ----------------
   Queste due invece si fanno a raffica: si separano tre sottocarte, poi
   un'altra, poi se ne unisce una da un'altra parte. Ricaricando la pagina si
   perdeva ogni volta tutto il contesto — la modalita', la vista compatta, il
   punto in cui si era arrivati a scorrere — e si ricominciava da capo a
   ritrovare il posto. Adesso parte la stessa richiesta di prima (stesso
   gestore PHP, stessi controlli), ma con bulk_ajax=1: il server risponde due
   righe di JSON invece di un redirect, e la pagina si limita a ridisegnare
   l'elenco.

   L'elenco si RIPRENDE DAL SERVER e non si aggiusta a mano. Non e' pigrizia:
   separando una sottocarta il server CANCELLA la riga e ne crea una nuova, con
   un id nuovo. Una carta spostata a mano nel DOM si porterebbe dietro l'id
   vecchio, e la prima azione fatta su di lei — una modifica, un'eliminazione —
   finirebbe su una riga che non esiste piu'. */
function eseguiAzioneStruttura(action){
    var form = document.getElementById('bulkForm');
    document.getElementById('hiddenBulkAction').value = action;

    var dati = new FormData(form);
    dati.set('bulk_ajax', '1');

    return fetch(window.location.href, { method: 'POST', body: dati, credentials: 'same-origin' })
        .then(function(r){ return r.json(); })
        .then(function(d){
            if (!d || !d.success) throw new Error('azione rifiutata');
            return ricaricaElenco(d);
        })
        .catch(function(err){
            /* Se qualcosa va storto si ricarica e basta: meglio una pagina che
               riparte di una pagina che mostra uno stato che non e' piu' vero. */
            console.error('[selezione] ' + action, err);
            window.location.reload();
        });
}

/* esito: quello che ha risposto il server (gli id nati, ed eventualmente il
   padre in cui sono finiti). Serve per non perderli di vista dopo il
   ridisegno. */
function ricaricaElenco(esito){
    var vecchio = document.getElementById('fcList');
    if (!vecchio) { window.location.reload(); return Promise.resolve(); }

    return fetch(window.location.href, { credentials: 'same-origin' })
        .then(function(r){ return r.text(); })
        .then(function(html){
            var doc = new DOMParser().parseFromString(html, 'text/html');
            var nuovo = doc.getElementById('fcList');
            /* Nessun elenco nella risposta vuol dire che non e' rimasta nessuna
               carta: quel caso il PHP lo disegna in un altro modo (il riquadro
               "non c'e' niente qui"), e ricostruirlo qui sarebbe copiare. */
            if (!nuovo) { window.location.reload(); return; }

            nuovo = document.importNode(nuovo, true);
            vecchio.replaceWith(nuovo);

            disegnaTestiGrezzi(nuovo);
            if (typeof fcLoadExampleBoxes === 'function') fcLoadExampleBoxes(nuovo);

            segnaAppenaCambiate(nuovo, esito);

            /* La modalita', la vista compatta e i filtri sono rimasti sul
               <body> e sulla barra: le carte nuove ci si devono solo adeguare.
               Le spunte no: quelle carte non ci sono piu', e l'azione che le
               riguardava e' appena stata fatta. */
            filterList();
            aggiornaBarraSelezione();
            portaInVistaLeCambiate(nuovo);
        })
        .catch(function(err){
            console.error('[selezione] ridisegno elenco', err);
            window.location.reload();
        });
}

/* LE CARTE APPENA SEPARATE (O UNITE) RESTANO A SCHERMO
   ---------------------------------------------------------------
   Il filtro della modalita' Separa tiene in elenco solo i padri con figli e i
   loro figli: e' giusto, sono le uniche su cui si puo' lavorare. Ma appena
   separata, una sottocarta diventa una carta come tutte le altre — e quindi
   sparisce esattamente nell'istante in cui si vuole vedere il risultato.
   Si usciva e si rientrava dalla modalita' solo per controllare cos'era
   successo.
   Marcarle risolve tutt'e due le cose: cardMatches le lascia passare
   comunque, e il segno serve anche a farle notare, con l'animazione qui sotto.
   Il marchio dura finche' si resta nella modalita': uscendo e rientrando
   l'elenco riparte pulito, che e' quello che si vuole. */
function segnaAppenaCambiate(radice, esito){
    /* Solo quelle del giro appena fatto: le precedenti hanno gia' avuto il loro
       momento e continuare a tenerle fuori dal filtro svuoterebbe il filtro. */
    $all('.appena-cambiata').forEach(function(el){ el.classList.remove('appena-cambiata'); });
    if (!esito) return;

    (esito.nati || []).forEach(function(id){
        var card = radice.querySelector('.fc-card[data-id="' + id + '"]');
        if (card) card.classList.add('appena-cambiata');
    });

    /* Unendo, le carte finiscono DENTRO un padre, che in modalita' Unisci tiene
       i figli chiusi: si apre quel padre, altrimenti il risultato e' vero ma
       invisibile. */
    if (esito.padre) {
        var gruppo = radice.querySelector('.fc-group[data-parent-id="' + esito.padre + '"]');
        if (gruppo) gruppo.classList.add('kids-open', 'appena-cambiata');
    }
}

function portaInVistaLeCambiate(radice){
    var prima = radice.querySelector('.appena-cambiata');
    if (prima && prima.scrollIntoView) prima.scrollIntoView({ block: 'center', behavior: 'smooth' });
}

/* Il testo delle carte arriva dal server come dato grezzo in data-raw e viene
   disegnato dal JavaScript. Le carte appena arrivate non sono passate da
   quel giro, quindi sarebbero righe vuote: si disegnano qui, con la stessa
   funzione che usa il pannello degli esempi.
   Solo quelle rimaste vuote: se qualcun altro le ha gia' disegnate, rifarlo
   sarebbe buttare via il lavoro e, con le formule, anche rifarle due volte. */
function disegnaTestiGrezzi(radice){
    if (typeof parseTextForPreview !== 'function') return;
    radice.querySelectorAll('.raw-text-content').forEach(function(el){
        /* Vuoto, oppure col solo segnaposto del server addosso: in tutt'e due i
           casi il testo vero non c'e' ancora. */
        if (el.innerHTML.trim() !== '' && !el.querySelector('[data-fc-pre]')) return;
        el.innerHTML = parseTextForPreview(el.getAttribute('data-raw') || '');
    });
    if (typeof fcdDisegnaFormule === 'function') fcdDisegnaFormule(radice);
}



/* ================================================================
   CAMBIARE IL PADRE DELLA MATERIA DAL PERCORSO
   ================================================================
   La matitina accanto all'ultima voce del percorso apre il selettore, gia'
   srotolato sul padre attuale. Si tocca una materia e lo spostamento parte:
   niente conferma, perche' non c'e' niente da comporre — si e' scelto un
   posto, ed e' quello.

   Chi NON si puo' scegliere e' segnato prima di aprire, non contestato dopo:
   questa materia (una materia non e' padre di se stessa) e tutte le sue
   sottocategorie. Spostare una materia dentro una sua discendente staccherebbe
   il ramo dall'albero — resterebbe agganciato a se stesso, fuori da ogni
   percorso. Un messaggio d'errore dopo il tocco spiegherebbe la stessa cosa
   facendo prima sbagliare. */
var CAT_ID_CORRENTE   = <?php echo (int)$categoryID; ?>;
var CAT_NOME_CORRENTE   = <?php echo json_encode((string)($current_cat_name ?? ''), JSON_UNESCAPED_UNICODE); ?>;
var CAT_COLORE_CORRENTE = <?php echo json_encode((string)($current_cat_color ?? '#5E5CE6')); ?>;
var CAT_ID_PADRE      = <?php echo (int)($fc_parent_id ?? 0); ?>;
/* Non scegliibili: questa materia (non e' padre di se stessa), le sue
   discendenti (il ramo si staccherebbe dall'albero) e il padre che ha GIA' —
   sceglierlo vorrebbe dire chiedere di spostarla dov'e', cioe' premere un
   pulsante che non fa niente. */
var CAT_NON_SCEGLIBILI = <?php echo json_encode(array_map('intval',
        [$categoryID, (int)($fc_parent_id ?? 0)])); ?>;
/* Le discendenti NON sono piu' bloccate: sceglierne una ha un significato
   preciso — "voglio che sia lei il mio padre" — e fc_decks.php sa
   eseguirlo, facendo prima salire i figli al posto lasciato libero. Restano
   fuori solo questa materia (non e' padre di se stessa) e il padre che ha gia'
   (sarebbe un pulsante che non fa niente). */
var CAT_DISCENDENTI = <?php echo json_encode(array_map('intval', array_keys($fc_discendenti ?? []))); ?>;

/* Le righe non scegliibili si marcano UNA VOLTA, a pagina pronta: l'albero
   delle materie e' gia' tutto nel documento e non cambia. Farlo al momento del
   tocco avrebbe voluto dire incastrarsi con il gestore del componente, che
   apre il modale allo stesso click: due gestori sullo stesso evento, e chi
   arriva primo dipende dall'ordine in cui sono stati registrati — cioe' da un
   dettaglio che non si controlla. */
function marcaMaterieNonScegliibili(){
    var modale = document.getElementById('cs-modal-catparent');
    if (!modale) return;

    modale.querySelectorAll('[data-cs-select]').forEach(function(riga){
        var id = parseInt(riga.getAttribute('data-cs-select'), 10);
        var bloccata = CAT_NON_SCEGLIBILI.indexOf(id) !== -1;
        riga.classList.toggle('cs-bloccata', bloccata);
        /* Questa materia si distingue dalle altre bloccate: le sottocategorie
           sono spente perche' sarebbe un giro chiuso, lei perche' e' il punto
           di partenza — ed e' un'informazione utile, dice "sei qui". */
        riga.classList.toggle('cs-e-questa', id === CAT_ID_CORRENTE);
    });
}

if (CAT_ID_CORRENTE > 0) marcaMaterieNonScegliibili();

document.addEventListener('categoryselected', function(e){
    if (!e.detail || e.detail.uid !== 'catparent') return;
    var nuovoPadre = parseInt(e.detail.id, 10) || 0;
    if (CAT_NON_SCEGLIBILI.indexOf(nuovoPadre) !== -1) return;

    /* Si chiede conferma, e non e' una formalita': spostare una materia muove
       tutto il suo ramo, cambia il percorso di ogni carta che contiene e i
       colori ereditati. Nel selettore basta sfiorare la voce sbagliata, e senza
       questo passaggio l'albero sarebbe gia' cambiato prima di accorgersene.
       La conferma nomina la destinazione: "sposto sotto X" e' controllabile,
       "confermi?" no. */
    chiediConfermaSpostamento(nuovoPadre, e.detail.name || T('fc.cs_nessuna_padre'), e.detail.color);
});

function chiediConfermaSpostamento(nuovoPadre, nomeDestinazione, coloreDestinazione){
    var modale = document.getElementById('catMoveConfirm');

    /* Ogni materia col suo colore: e' il colore con cui si e' abituati a
       riconoscerla nell'albero e nell'intestazione, e ritrovarlo qui vuol dire
       non dover rileggere il nome per essere sicuri di quale sia. */
    var da = document.getElementById('catMoveDa');
    da.textContent = CAT_NOME_CORRENTE;
    da.style.setProperty('--chip', CAT_COLORE_CORRENTE);

    var a = document.getElementById('catMoveA');
    a.textContent = nomeDestinazione;
    a.style.setProperty('--chip', coloreDestinazione || 'var(--text-3)');

    /* Scegliendo una discendente si sta chiedendo qualcosa di piu' grande, e la
       nota deve dirlo: non si sposta solo questa materia, i suoi figli salgono
       al posto che lascia. Chiedere "confermi?" sarebbe far firmare senza far
       leggere. */
    var dentro = CAT_DISCENDENTI.indexOf(nuovoPadre) !== -1;
    document.getElementById('catMoveConfirmTxt').textContent = dentro
        ? T('fc.vis_sposta_nota_dentro', {cat: CAT_NOME_CORRENTE})
        : T('fc.vis_sposta_nota');
    document.getElementById('catMoveConfirmOk').onclick = function(){
        modale.classList.remove('on');
        spostaSottoIlPadre(nuovoPadre);
    };
    modale.classList.add('on');
}

document.addEventListener('click', function(e){
    if (!e.target || !e.target.closest) return;
    if (e.target.closest('#catMoveConfirmBack') || e.target.closest('#catMoveConfirmNo')) {
        document.getElementById('catMoveConfirm').classList.remove('on');
    }
});

function spostaSottoIlPadre(nuovoPadre){
    /* Lo spostamento lo fa fc_decks.php, che ha gia' il suo controllo di
       proprieta' e si porta dietro l'aggiornamento dei colori ereditati
       (cs_apply_parent_color_update). Rifarlo qui vorrebbe dire avere due
       posti che spostano una materia, e prima o poi uno dei due dimentica i
       colori. */
    var dati = new FormData();
    dati.set('action', 'move');
    dati.set('move_category_id', CAT_ID_CORRENTE);
    dati.set('target_parent_id', nuovoPadre);

    fetch(fcUrl('fc_decks.php'), { method: 'POST', body: dati, credentials: 'same-origin' })
        .then(function(){ window.location.reload(); })
        .catch(function(err){
            console.error('[percorso] spostamento materia', err);
            showToast(T('fc.vis_azione_fallita'));
        });
}

function pickMoveCategory(categoryId){

    document.getElementById('hiddenTargetCategory').value = categoryId;



    submitBulkForm('move');

}

/* Il selettore scrive la categoria scelta nel proprio campo nascosto: si
   osserva quello invece di inventare una richiamata che il componente non
   prevede. */
document.addEventListener('change', function (e) {
    if (e.target && e.target.name === 'cs_move_target' && e.target.value) {
        pickMoveCategory(parseInt(e.target.value, 10) || 0);
    }
});




function closeSheet(id){

    const el = document.getElementById(id);

    if (el) el.style.display = 'none';

}



function openDeleteConfirm(count, warnText, onConfirm){

    document.getElementById('deleteSheetTitle').textContent = (count === 1) ? T('fc.vis_eliminare_carta') : T('fc.vis_eliminare_n_carte', {n: count});

    document.getElementById('deleteSheetSub').textContent = T('fc.vis_non_annullabile');

    const warnEl = document.getElementById('deleteSheetWarn');

    if (warnText) { warnEl.textContent = warnText; warnEl.style.display = 'block'; }

    else { warnEl.style.display = 'none'; }

    const btn = document.getElementById('deleteSheetConfirm');

    btn.onclick = function(){ closeSheet('deleteSheet'); onConfirm(); };

    document.getElementById('deleteSheet').style.display = 'flex';

}



document.getElementById('deleteSheet').addEventListener('click', function(e){ if (e.target === this) closeSheet('deleteSheet'); });




/* ================================================================

   AZIONI SWIPE: MODIFICA / ELIMINA (singola carta)

   ================================================================ */

function goEditCard(btn){

    const card = btn.closest('.fc-card');

    const url = card && card.getAttribute('data-edit-url');

    /* Apre il popup di modifica invece di lasciare la pagina: al salvataggio
       o all'eliminazione si ricarica, cosi' l'elenco torna coerente con
       quello che c'e' davvero nel database — piu' semplice e piu' sicuro
       che provare ad aggiornare a mano solo la carta toccata. */
    if (url && typeof apriModificaPopup === 'function') {
        apriModificaPopup(url, fcRicaricaSuCarta);
    } else if (url) {
        window.location.href = url;
    }

}



function askDeleteCard(btn){

    const card = btn.closest('.fc-card');

    const type = card.getAttribute('data-type');

    const childCount = parseInt(card.getAttribute('data-child-count') || '0', 10);

    let warn = '';

    if (type === 'parent' && childCount > 0) {

        warn = (childCount === 1)

            ? T('fc.vis_una_orfana')

            : T('fc.vis_n_orfane', {n: childCount});

    }

    openDeleteConfirm(1, warn, function(){ performSingleDelete(card); });

}



function performSingleDelete(card){

    const type = card.getAttribute('data-type');

    const id = card.getAttribute('data-id');

    const fd = new FormData();

    fd.append('bulk_action', 'delete');

    if (type === 'parent') { fd.append('selected_ids[]', id); } else { fd.append('selected_child_ids[]', id); }



    const target = (type === 'parent') ? card.closest('.fc-group') : card;

    fetch(fcUrl('fc_view.php'), { method: 'POST', body: fd })

        .then(function(){ animateCardOut(target); })

        .catch(function(){ showToast(T('fc.vis_errore_elimina')); if (window.resetSwipeCard) window.resetSwipeCard(card); });

}



function animateCardOut(el){

    if (!el) return;

    const h = el.offsetHeight;

    el.style.overflow = 'hidden';

    el.style.transition = 'none';

    el.style.height = h + 'px';

    requestAnimationFrame(function(){

        el.style.transition = 'height .32s var(--spring), opacity .28s ease-out, transform .32s var(--spring), margin .32s var(--spring)';

        el.style.height = '0px';

        el.style.opacity = '0';

        el.style.transform = 'scale(.94)';

        el.style.marginBottom = '0px';

        el.style.marginTop = '0px';

    });

    setTimeout(function(){

        const parentChildrenBox = el.closest('.fc-children');

        el.remove();

        if (parentChildrenBox && !parentChildrenBox.querySelector('.fc-card')) parentChildrenBox.remove();

        filterList();

    }, 340);

}

</script>



<script>

/* ================================================================

   MOTORE GESTUALE — porta la stessa logica di fc_insert.php:

   ghost "fixed" che segue il dito + placeholder che apre lo spazio,

   animate con la tecnica FLIP, blocco scroll dedicato su touchmove,

   auto-scroll ai bordi. Il contenimento figlio-dentro-il-padre e'

   garantito dalla struttura stessa (il placeholder vive solo dentro

   lo scope .fc-children o solo dentro #fcList, non puo' uscirne).

   ================================================================ */

(function(){

    'use strict';

    var TAP_SLOP = 10;

    var LONG_PRESS_MS = 420;

    var pend = null;

    var openSwipeContent = null;

    var gestureBlock = false;

    var activeDrag = null;

    var lastPointerY = 0;

    var autoScrollRAF = null;



    function isInteractive(el){

        if (el.closest && el.closest('button, a, input, select, textarea, audio, .ap-track, .audio-player')) return true;

        /* Una formula larga si legge scorrendola: se il gesto nasce li' dentro

           non deve aprire i tastini modifica/elimina. */

        if (typeof fcScorrevoleOrizz === 'function' && fcScorrevoleOrizz(el, el.closest('.fc-card'))) return true;

        /* Gli esempi hanno un loro swipe orizzontale (cambia esempio): se il

           gesto nasce li' dentro non deve aprire i tastini modifica/elimina. */

        if (el.closest && el.closest('.qa-examples')) return true;

        return false;

    }

    function getTranslateX(el){

        var m = el.style.transform.match(/translateX\((-?\d+(?:\.\d+)?)px\)/);

        return m ? parseFloat(m[1]) : 0;

    }

    function closeOpenSwipe(){

        if (openSwipeContent) {

            openSwipeContent.style.transition = 'transform .3s var(--spring)';

            openSwipeContent.style.transform = 'translateX(0px)';

            var chiudiCard = openSwipeContent.closest('.fc-card');

            var chiudiActions = chiudiCard && chiudiCard.querySelector('.swipe-actions');

            if (chiudiActions) chiudiActions.classList.remove('revealing');

            openSwipeContent = null;

        }

    }

    window.resetSwipeCard = function(card){

        var content = card && card.querySelector('.swipe-content');

        if (!content) return;

        content.style.transition = 'transform .3s var(--spring)';

        content.style.transform = 'translateX(0px)';

        var actionsEl = card.querySelector('.swipe-actions');

        if (actionsEl) actionsEl.classList.remove('revealing');

        if (openSwipeContent === content) openSwipeContent = null;

    };



    /* ---- FLIP: anima i figli di "container" dalla posizione pre-mutate a quella nuova ---- */

    function flip(container, mutate){

        var items = Array.prototype.slice.call(container.children);

        var first = new Map();

        items.forEach(function(el){ first.set(el, el.getBoundingClientRect()); });

        mutate();

        Array.prototype.slice.call(container.children).forEach(function(el){

            var f = first.get(el);

            if (!f) return;

            var l = el.getBoundingClientRect();

            var dx = f.left - l.left, dy = f.top - l.top;

            if (Math.abs(dx) > 0.5 || Math.abs(dy) > 0.5) {

                el.style.transition = 'none';

                el.style.transform = 'translate(' + dx + 'px,' + dy + 'px)';

                requestAnimationFrame(function(){

                    el.style.transition = 'transform .38s var(--spring)';

                    el.style.transform = '';

                });

            }

        });

    }



    /* chiude lo swipe aperto quando si tocca altrove */

    document.addEventListener('pointerdown', function(e){

        if (activeDrag) return;

        if (!openSwipeContent) return;

        if (e.target.closest && e.target.closest('.swipe-content') === openSwipeContent) return;

        if (e.target.closest && e.target.closest('.swipe-act')) return;

        closeOpenSwipe();

    }, true);



    function eligibleCard(e){

        if (!e.target || !e.target.closest) return null;

        if (isInteractive(e.target)) return null;

        var card = e.target.closest('.fc-card');

        if (!card || !card.closest('#fcList')) return null;

        return card;

    }



    document.addEventListener('pointerdown', function(e){

        /* In selezione il trascinamento era spento del tutto. Aveva senso
           finche' l'unico gesto sulle carte era lo swipe modifica/elimina, che
           li' dentro non deve partire. Ma la vista compatta esiste proprio per
           rimettere in ordine tante carte insieme, e riordinarle solo con le
           freccette vuol dire premere venti volte per spostare di venti
           posizioni. Quindi: in selezione si trascina se si e' in compatta, e
           solo per riordinare — lo swipe resta spento (ctx.soloRiordino).
           Mentre si sceglie il padre no: li' un tocco sulla carta ha gia' un
           significato, e tenerlo premuto non deve averne un secondo. */
        var inSelezione = document.body.classList.contains('select-mode');
        var inCompatta  = document.body.classList.contains('is-compact');
        if (inSelezione && !inCompatta) return;
        if (document.body.classList.contains('join-picking')) return;

        if (pend || activeDrag) return;

        if (e.pointerType === 'mouse' && e.button !== 0) return;

        var card = eligibleCard(e);

        if (!card) return;



        var content = card.querySelector('.swipe-content');

        var isParent = card.classList.contains('fc-parent');

        var dragUnit = isParent ? card.closest('.fc-group') : card;

        var scopeEl = dragUnit ? dragUnit.parentElement : null;

        var reorderable = !!(dragUnit && scopeEl && dragUnit.getAttribute('data-reorderable') === '1');



        pend = {

            card: card, content: content, dragUnit: dragUnit, scopeEl: scopeEl,

            pointerId: e.pointerId, startX: e.clientX, startY: e.clientY,

            swiping: false, reordering: false, soloRiordino: inSelezione,

            longPressTimer: reorderable ? setTimeout(function(){ if (pend && !pend.swiping) activateReorder(pend); }, LONG_PRESS_MS) : null

        };

    });



    document.addEventListener('pointermove', function(e){

        if (!pend || e.pointerId !== pend.pointerId) return;

        if (pend.reordering) { doReorderMove(pend, e); return; }

        if (pend.swiping) { doSwipeMove(pend, e); return; }



        var dx = e.clientX - pend.startX, dy = e.clientY - pend.startY;

        var adx = Math.abs(dx), ady = Math.abs(dy);



        if (adx > TAP_SLOP && adx > ady * 1.25) {

            clearTimeout(pend.longPressTimer);

            /* In selezione un movimento orizzontale non apre modifica/elimina:
               annulla e basta, come fa gia' quello verticale. */
            if (pend.soloRiordino) { pend = null; return; }

            pend.swiping = true;

            activateSwipeGesture(pend);

            doSwipeMove(pend, e);

        } else if (ady > TAP_SLOP && ady > adx * 1.25) {

            clearTimeout(pend.longPressTimer);

            pend = null;

        }

    }, { passive: true });



    document.addEventListener('pointerup', function(e){ finishGesture(e, true); });

    document.addEventListener('pointercancel', function(e){ finishGesture(e, false); });



    function finishGesture(e, committed){

        if (!pend || e.pointerId !== pend.pointerId) return;

        var p = pend; pend = null;

        clearTimeout(p.longPressTimer);

        if (p.reordering) { finalizeReorder(p); }

        else if (p.swiping) { finalizeSwipeGesture(p, committed); }

        else if (openSwipeContent === p.content && getTranslateX(p.content) < -4) {

            closeOpenSwipe();

        }

    }



    /* ---------------- SWIPE ORIZZONTALE (Modifica / Elimina) ---------------- */

    function activateSwipeGesture(ctx){

        gestureBlock = true;

        document.body.classList.add('no-select');

        if (openSwipeContent && openSwipeContent !== ctx.content) closeOpenSwipe();

        ctx.content.style.transition = 'none';

        var actionsEl = ctx.card.querySelector('.swipe-actions');

        /* Si rivela PRIMA di misurare: partono nascosti (display:none), e la

           larghezza letta su un elemento non renderizzato sarebbe 0. */

        if (actionsEl) actionsEl.classList.add('revealing');

        ctx.maxReveal = actionsEl ? actionsEl.offsetWidth : 150;

        ctx.startTranslate = getTranslateX(ctx.content);

    }

    function doSwipeMove(ctx, e){

        var x = ctx.startTranslate + (e.clientX - ctx.startX);

        if (x > 0) x = x * 0.15;

        if (x < -ctx.maxReveal) x = -ctx.maxReveal;

        ctx.content.style.transform = 'translateX(' + x + 'px)';

        ctx.currentX = x;

    }

    function finalizeSwipeGesture(ctx, committed){

        document.body.classList.remove('no-select');

        gestureBlock = false;

        ctx.content.style.transition = 'transform .32s var(--spring)';

        var target = 0;

        if (committed && ctx.currentX !== undefined && ctx.currentX < -ctx.maxReveal * 0.4) target = -ctx.maxReveal;

        ctx.content.style.transform = 'translateX(' + target + 'px)';

        if (target !== 0) { openSwipeContent = ctx.content; buzz(8); }

        else {

            if (openSwipeContent === ctx.content) openSwipeContent = null;

            var actionsEl = ctx.card.querySelector('.swipe-actions');

            if (actionsEl) actionsEl.classList.remove('revealing');

        }

    }



    /* ---------------- RIORDINO A PRESSIONE PROLUNGATA (ghost + placeholder) ---------------- */

    function activateReorder(ctx){

        ctx.reordering = true;

        gestureBlock = true;

        buzz(12);

        document.body.classList.add('no-select');



        var unit = ctx.dragUnit, scope = ctx.scopeEl;

        var startRect = unit.getBoundingClientRect();



        var placeholder = document.createElement('div');

        placeholder.className = 'fc-drag-placeholder';

        placeholder.style.height = startRect.height + 'px';

        scope.insertBefore(placeholder, unit);

        ctx.placeholder = placeholder;



        var ghost = document.createElement('div');

        ghost.className = 'fc-drag-ghost';

        ghost.style.left = startRect.left + 'px';

        ghost.style.top = startRect.top + 'px';

        ghost.style.width = startRect.width + 'px';

        try {

            var rc = getComputedStyle(unit).getPropertyValue('--row-color');

            if (rc) ghost.style.setProperty('--row-color', rc.trim());

        } catch(err){}

        ghost.appendChild(unit);

        document.body.appendChild(ghost);

        ctx.ghost = ghost;

        activeDrag = ctx;

    }



    function doReorderMove(ctx, e){

        var dy = e.clientY - ctx.startY;

        ctx.ghost.style.transform = 'translateY(' + dy + 'px) scale(1.02)';

        lastPointerY = e.clientY;

        ensureAutoScroll();

        updateReorderPlacement(ctx, e.clientY);

    }



    function updateReorderPlacement(ctx, pointerY){

        var scope = ctx.scopeEl;

        var items = Array.prototype.slice.call(scope.children);

        var idx = items.indexOf(ctx.placeholder);

        if (idx < 0) return;



        if (idx > 0) {

            var prev = items[idx - 1];

            var rPrev = prev.getBoundingClientRect();

            if (pointerY < rPrev.top + rPrev.height / 2) {

                flip(scope, function(){ scope.insertBefore(ctx.placeholder, prev); });

                buzz(4);

                return;

            }

        }

        if (idx < items.length - 1) {

            var next = items[idx + 1];

            var rNext = next.getBoundingClientRect();

            if (pointerY > rNext.top + rNext.height / 2) {

                flip(scope, function(){ scope.insertBefore(ctx.placeholder, next.nextSibling); });

                buzz(4);

                return;

            }

        }

    }



    function finalizeReorder(ctx){

        stopAutoScroll();

        document.body.classList.remove('no-select');

        gestureBlock = false;

        /* Alla fine di un trascinamento il browser manda comunque un click su
           dove si e' lasciato il dito. In vista compatta quel punto e' quasi
           sempre una riga della carta, che ora e' il comando "apri": senza
           questo, ogni riordino finirebbe con la carta spalancata. Stessa
           medicina gia' usata per lo sfogliare degli esempi
           (fcExUltimoSwipe). */
        window.fcUltimoRiordino = Date.now();

        activeDrag = null;



        var scope = ctx.scopeEl, placeholder = ctx.placeholder, unit = ctx.dragUnit;

        flip(scope, function(){

            scope.insertBefore(unit, placeholder);

            placeholder.remove();

        });

        ctx.ghost.remove();

        buzz(10);

        persistReorderFromScope(unit, scope);

    }



    /* Le freccette su/giu' della vista compatta (moveCardBy) spostano le stesse
       unita' che sposta il trascinamento, e devono salvare allo stesso modo:
       una seconda funzione che scrive l'ordine sarebbe una seconda funzione da
       tenere d'accordo con il server. */
    window.fcSalvaOrdine = function(unit, scope){ persistReorderFromScope(unit, scope); };

    function persistReorderFromScope(unit, scope){

        var isGroup = unit.classList.contains('fc-group');

        var type = isGroup ? 'parent' : 'child';

        var scopeId = isGroup ? unit.getAttribute('data-category-id') : unit.getAttribute('data-parent-id');

        var fd = new FormData();

        fd.append('action', 'reorder_batch');

        fd.append('type', type);

        fd.append('scope_id', scopeId);

        Array.prototype.slice.call(scope.children).forEach(function(el){

            var id = isGroup ? el.getAttribute('data-parent-id') : el.getAttribute('data-id');

            fd.append('ordered_ids[]', id);

        });

        fetch(fcUrl('fc_view.php'), { method: 'POST', body: fd }).catch(function(){

            showToast(T('fc.vis_ordine_non_salvato'));

        });

    }



    /* ---------------- AUTO-SCROLL AI BORDI DELLO SCHERMO ---------------- */

    function ensureAutoScroll(){ if (!autoScrollRAF) autoScrollLoop(); }

    function autoScrollLoop(){

        var margin = 110, maxSpeed = 16;

        var vh = window.innerHeight;

        var dy = 0;

        if (lastPointerY < margin) dy = -maxSpeed * (1 - lastPointerY / margin);

        else if (lastPointerY > vh - margin) dy = maxSpeed * (1 - (vh - lastPointerY) / margin);

        if (dy && activeDrag) window.scrollBy(0, dy);

        autoScrollRAF = activeDrag ? requestAnimationFrame(autoScrollLoop) : null;

    }

    function stopAutoScroll(){ if (autoScrollRAF) { cancelAnimationFrame(autoScrollRAF); autoScrollRAF = null; } }



    /* blocco dedicato dello scroll nativo: separato dal motore pointer-events */

    document.addEventListener('touchmove', function(e){ if (gestureBlock) e.preventDefault(); }, { passive: false });

})();

</script>



<script>

/* ================================================================

   ANIMAZIONI DI INGRESSO E AVVIO PAGINA

   ================================================================ */

function playEntranceAnimations(){

    /* ERA QUESTA LA COSA CHE "SI COSTRUIVA".
       Venticinque gruppi, 35ms di ritardo l'uno dall'altro, 420ms di durata:
       l'ultimo cominciava a comparire dopo quasi un secondo. E fill:'backwards'
       vuol dire che PRIMA di cominciare l'elemento sta al primo fotogramma,
       cioe' invisibile — quindi non era la pagina lenta ad assemblarsi, era la
       pagina che si assemblava apposta, davanti agli occhi, per un secondo
       intero. Un'animazione scritta per far sembrare le cose vive faceva
       sembrare l'applicazione lenta.
       Ora: solo i primi otto (gli unici che stanno a schermo), 14ms fra l'uno e
       l'altro, 220ms di durata. Tutto finito in poco piu' di 300ms, e nessun
       elemento resta invisibile abbastanza da farci caso. Il resto compare
       gia' fatto — che e' esattamente il motivo per cui le applicazioni fluide
       sembrano fluide: non fanno vedere il montaggio. */
    $all('.fc-group').forEach(function(group, i){

        if (i > 7) return;

        try {

            group.animate(

                [{ opacity:0, transform:'translateY(8px)' }, { opacity:1, transform:'none' }],

                { duration: 220, delay: i * 14, easing:'cubic-bezier(.22,1,.36,1)', fill:'backwards' }

            );

        } catch(e){}

    });

}



function scrollCatPathToEnd(){
    var cp = document.getElementById('catPath');
    if (cp) { cp.scrollLeft = cp.scrollWidth; }
}

/* LA RETE SOTTO IL SEGNAPOSTO
   Il testo in chiaro messo dal server e' una comodita' finche' arriva quello
   vero. Se il disegnatore per qualunque motivo non e' passato — un file non
   caricato, un errore prima — il segnaposto resta li' e la carta si vede lo
   stesso, ma senza grassetto ne' formule. Qui si guarda chi e' rimasto col
   marchio addosso e lo si disegna. Riconoscerli dal marchio, e non dal fatto
   che siano vuoti, e' quello che rende questo controllo innocuo: su una carta
   gia' disegnata il marchio non c'e' piu' e non si tocca niente. */
/* ================================================================
   PRECARICARE LA CARTA CHE SI STA PER APRIRE
   ================================================================
   Fra il momento in cui il dito tocca una carta e quello in cui lo si alza
   passano 80-150 millisecondi. Sono pochi per una persona e parecchi per una
   richiesta: se si comincia a scaricare la pagina di modifica al
   pointerdown, quando il popup si apre spesso e' gia' arrivata.

   Una alla volta, e solo quella toccata. Precaricarle tutte all'apertura
   dell'elenco vorrebbe dire trenta richieste a una pagina che ognuna fa le sue
   interrogazioni al database, per carte che quasi certamente non si apriranno:
   il precaricamento diventerebbe il motivo per cui la pagina e' lenta. Qui la
   carta toccata la si aprira' quasi sempre — si indovina praticamente sempre,
   e si spende una richiesta.

   Col mouse si parte dal passaggio sopra la carta, ma con un ritardo: senza,
   basterebbe attraversare l'elenco per far partire una richiesta per ognuna.

   Non c'e' niente da svuotare cambiando materia: la memoria e' quella del
   browser, e le pagine che non serviranno piu' scadono da sole. Qui si tiene
   solo l'elenco di quelle gia' chieste, per non chiederle due volte. */
var fcGiaPrecaricate = {};
var fcAttesaPrecarico = null;

function precaricaModifica(carta){
    if (!carta) return;
    var url = carta.getAttribute('data-edit-url');
    if (!url) return;
    /* Lo stesso indirizzo che usera' il popup, virgola per virgola: se
       differisse anche solo per un parametro, il browser lo tratterebbe come
       una pagina diversa e lo scaricherebbe una seconda volta. */
    if (typeof window.fcUrlPopup === 'function') url = window.fcUrlPopup(url);
    if (fcGiaPrecaricate[url]) return;
    fcGiaPrecaricate[url] = true;
    fetch(url, { credentials: 'same-origin' }).catch(function(){ delete fcGiaPrecaricate[url]; });
}

document.addEventListener('pointerdown', function(e){
    /* In selezione il tocco non apre niente: precaricare sarebbe una richiesta
       buttata a ogni spunta. */
    if (document.body.classList.contains('select-mode')) return;
    if (!e.target || !e.target.closest) return;
    precaricaModifica(e.target.closest('.fc-card[data-edit-url]'));
}, { passive: true });

document.addEventListener('pointerover', function(e){
    if (e.pointerType === 'touch') return;   /* col dito ci pensa il pointerdown */
    if (document.body.classList.contains('select-mode')) return;
    if (!e.target || !e.target.closest) return;
    var carta = e.target.closest('.fc-card[data-edit-url]');
    clearTimeout(fcAttesaPrecarico);
    if (!carta) return;
    /* 140ms: attraversare l'elenco col mouse non deve far partire una richiesta
       per ogni carta che si sfiora. Chi si ferma su una, invece, quasi sempre
       la aprira'. */
    fcAttesaPrecarico = setTimeout(function(){ precaricaModifica(carta); }, 140);
});

function rifinisciTestiRimasti(radice){
    (radice || document).querySelectorAll('.raw-text-content [data-fc-pre]').forEach(function(seg){
        var box = seg.closest('.raw-text-content');
        if (!box) { seg.remove(); return; }
        if (typeof parseTextForPreview === 'function') {
            box.innerHTML = parseTextForPreview(box.getAttribute('data-raw') || '');
        } else {
            seg.removeAttribute('data-fc-pre');   /* niente disegnatore: si tiene il chiaro */
        }
    });
    if (typeof fcdDisegnaFormule === 'function') fcdDisegnaFormule(radice || document);
    fcChiediDisegnoFormule(radice || document);
}

/* IL DISEGNO DELLE FORMULE, UNA MANCIATA ALLA VOLTA

   Le formule preparate dal server portano addosso 'fc-resa' (niente da
   riscrivere) e 'fc-attesa' (non ancora disegnate). Chiedere a MathJax di
   disegnarle tutte insieme non funziona: con un archivio intero in pagina sono
   migliaia, le fa una dopo l'altra sul filo principale, e per diversi secondi
   al loro posto c'e' un buco.

   Si disegnano quindi solo quelle che stanno per arrivare sotto gli occhi, con
   600px di anticipo: quando si scorre sono gia' pronte, e quelle in fondo alla
   pagina non costano niente finche' non ci si arriva. Finche' una e' in attesa
   il suo codice LaTeX non si vede — e' codice, non testo da leggere — ma lo
   spazio resta occupato.

   Se MathJax non arriva affatto (rete giu', CDN bloccata) si smette di
   aspettare dopo qualche secondo e si mostra il codice: meglio del buco. */
var fcOsservatoreFormule = null;

function fcChiediDisegnoFormule(radice){
    var scope = radice || document;
    if (!scope.querySelectorAll) return;
    var pendenti = scope.querySelectorAll('.fc-x.fc-attesa, .fc-fx.fc-attesa');
    if (!pendenti.length) return;

    /* Senza IntersectionObserver (browser molto vecchi) si torna al
       comportamento di prima: tutto insieme, appena si puo'. */
    if (typeof IntersectionObserver === 'undefined') {
        fcDisegnaFormuleDi(Array.prototype.slice.call(pendenti), 0);
        return;
    }

    if (!fcOsservatoreFormule) {
        fcOsservatoreFormule = new IntersectionObserver(function(voci){
            var lotto = [];
            voci.forEach(function(v){
                if (!v.isIntersecting) return;
                fcOsservatoreFormule.unobserve(v.target);
                lotto.push(v.target);
            });
            if (lotto.length) fcDisegnaFormuleDi(lotto, 0);
        }, { rootMargin: '600px 0px' });
    }
    Array.prototype.forEach.call(pendenti, function(el){ fcOsservatoreFormule.observe(el); });
}

function fcDisegnaFormuleDi(elenco, tentativo){
    function scopri(){
        elenco.forEach(function(el){ el.classList.remove('fc-attesa'); });
    }
    if (window.MathJax && MathJax.typesetPromise) {
        MathJax.typesetPromise(elenco).then(scopri).catch(scopri);
        return;
    }
    if (tentativo > 25) { scopri(); return; }   /* ~5 secondi, poi si rinuncia */
    setTimeout(function(){ fcDisegnaFormuleDi(elenco, tentativo + 1); }, 200);
}

function initPage(){

    fcCaricaImpostazioni();

    syncHeaderHeight();

    setTimeout(syncHeaderHeight, 250);

    filterList();

    playEntranceAnimations();

    scrollCatPathToEnd();
    setTimeout(scrollCatPathToEnd, 280);

    fcVaiAllaCartaDelFocus();

    if (window.fcvAllineaFab) { fcvAllineaFab(); setTimeout(fcvAllineaFab, 400); }

    fcLoadExampleBoxes();

    fcOsservaFineElenco();

    /* Dopo il disegnatore, non prima: se e' passato lui qui non c'e' piu'
       niente da fare, ed e' il caso normale. */
    rifinisciTestiRimasti(document);

    fcRiapriVisoreSeServe();

}



/* Si tornava dal modulo di modifica dopo esserci andati DAL visore a

   flashcard: si riapre il visore su quella carta, invece di lasciare a video

   l'elenco. Il ricordo e' usa e getta - una volta consumato si toglie, cosi'

   una visita successiva all'elenco non riapre il visore a sorpresa.

   L'attesa serve a fcLoadExampleBoxes(): il visore copia la sezione esempi

   com'e' in quel momento, e senza aspettare la copierebbe ancora vuota. */

function fcRiapriVisoreSeServe(){

    var ricordo = null;

    try { ricordo = sessionStorage.getItem('nisama_fcv_riapri'); } catch(e){}

    if (!ricordo) return;

    try { sessionStorage.removeItem('nisama_fcv_riapri'); } catch(e){}



    var pezzi = ricordo.split(':');

    if (pezzi.length !== 2) return;

    setTimeout(function(){

        if (window.fcvApriSuCarta) window.fcvApriSuCarta(pezzi[0], pezzi[1]);

    }, 450);

}

/* Il riquadro "N esempi" con il carosello (fcLoadExampleBoxes,
   fcDisegnaEsempio, lo swipe orizzontale, fcRenderExampleBox e le altre
   funzioni che lo compongono) e' stato estratto in fc_examples_modal.js,
   caricato da fc_examples_modal.php: fc_card_edit.php lo usa anche
   lui, per lo stesso riquadro sotto la carta che si sta modificando. Qui
   resta solo cio' che e' specifico di QUESTA pagina: il visore a schermo
   intero che si apre toccando un riquadro gia' pieno (subito sotto). */


/* ---------- Esempio a tutto schermo ----------
   Dentro la scheda l'esempio ha poco spazio (un riquadro alto 120px che
   scorre). Toccandolo si apre qui, dove ce n'e' quanto serve. Funziona
   uguale dall'elenco e da dentro il visore a flashcard: il popup lavora sul
   box da cui e' partito il tocco, quindi non gli importa dove sia. */

var fcExBox = null;                 /* riquadro da cui e' partito il popup */

function fcExApri(box) {
    if (!fcHydrateEsempioBox(box)) return;
    fcExBox = box;
    fcExDisegna();
    var modal = document.getElementById('exModal');
    modal.classList.add('on');
    modal.setAttribute('aria-hidden', 'false');
    /* Se il visore a flashcard e' gia' aperto la pagina e' bloccata da lui:
       rifarlo qui la sbloccherebbe alla chiusura del solo popup. */
    if (!document.body.classList.contains('fcv-lock')) document.body.classList.add('ex-lock');
    if (window.buzz) buzz(8);
}

/* Apre il modulo dell'esempio che si sta guardando. La strada e' quella
   normale — il popup di modifica della carta — con in piu' un parametro che
   dice a quella pagina di aprire subito l'esempio giusto. Cosi' non c'e' un
   secondo modo di modificare un esempio da tenere allineato al primo: c'e'
   sempre e solo quello, raggiunto piu' in fretta.
   Chiudendo il popup l'elenco della carta si ridisegna da solo, e con lui il
   riquadro degli esempi qui sotto. */
function fcExModifica() {
    if (!fcExBox) return;
    var esempi = fcExBox._fcExamples || [];
    var ex = esempi[fcExBox._fcIndex || 0];
    var card = fcExBox.closest('.fc-card');
    var url = card && card.getAttribute('data-edit-url');
    if (!ex || !url) return;

    fcExChiudi();
    var meta = url + (url.indexOf('?') === -1 ? '?' : '&') + 'apri_esempio=' + encodeURIComponent(ex.id);
    if (typeof apriModificaPopup === 'function') apriModificaPopup(meta, function(){ window.location.reload(); });
    else window.location.href = meta;
}

function fcExChiudi() {
    var modal = document.getElementById('exModal');
    modal.classList.remove('on');
    modal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('ex-lock');
    fcExBox = null;
}

function fcExDisegna() {
    if (!fcExBox) return;
    var esempi = fcExBox._fcExamples || [];
    var i = fcExBox._fcIndex || 0;
    if (!esempi.length) return;

    document.getElementById('exModalTitle').textContent =
        (esempi.length > 1) ? T('fc.vis_esempio_n_di', { n: i + 1, tot: esempi.length })
                            : T('fc.vis_esempio_uno');

    var body = document.getElementById('exModalBody');
    body.innerHTML = '<div class="qa-example-item">' + fcEsempioHtml(esempi[i]) + '</div>';
    body.scrollTop = 0;
    /* Dentro il visore dell'esempio l'immagine e' comunque limitata in
       altezza: toccandola si apre a tutto schermo, come gia' fa quella della
       carta. Un solo ascoltatore sul contenitore, non uno per immagine, cosi'
       vale anche per l'esempio successivo che verra' disegnato qui dentro. */
    body.onclick = function (ev) {
        var img = ev.target.closest && ev.target.closest('.qa-example-image');
        if (!img) return;
        ev.stopPropagation();
        openImageZoom(img.src);
    };
    if (typeof fcdDisegnaFormule === 'function') fcdDisegnaFormule(body);

    var nav = document.getElementById('exModalNav');
    if (esempi.length < 2) { nav.innerHTML = ''; return; }
    var html = '<button type="button" class="ex-modal-arrow" data-verso="-1" aria-label="' + fcEscapeHtml(T('fc.vis_esempio_prec')) + '">'
             + '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg></button>'
             + '<span class="qa-ex-dots">';
    for (var d = 0; d < esempi.length; d++) html += '<span class="qa-ex-dot' + (d === i ? ' active' : '') + '"></span>';
    html += '</span><button type="button" class="ex-modal-arrow" data-verso="1" aria-label="' + fcEscapeHtml(T('fc.vis_esempio_succ')) + '">'
          + '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg></button>';
    nav.innerHTML = html;
}

/* Qui le frecce fanno il giro, come lo swipe: nel popup non c'e' il riquadro
   piccolo a fare da riferimento, quindi restare bloccati ai bordi
   sembrerebbe solo un tasto rotto. Il riquadro sotto viene tenuto allineato,
   cosi' chiudendo si ritrova lo stesso esempio che si stava leggendo. */
function fcExVai(verso) {
    if (!fcExBox) return;
    var n = (fcExBox._fcExamples || []).length;
    if (n < 2) return;
    var ni = (fcExBox._fcIndex + verso + n) % n;
    fcCambiaEsempioAnimato(fcExBox, ni, verso);
    fcExDisegna();
}

(function () {
    document.addEventListener('click', function (e) {
        if (!e.target || !e.target.closest) return;

        if (e.target.closest('#exModalClose') || e.target.closest('#exModalBack')) { fcExChiudi(); return; }

        if (e.target.closest('#exModalEdit')) { fcExModifica(); return; }

        var freccia = e.target.closest('.ex-modal-arrow');
        if (freccia) { fcExVai(parseInt(freccia.dataset.verso, 10)); return; }

        /* Apertura: il tocco deve essere sul riquadro dell'esempio, non su un
           comando che ha gia' un suo significato (frecce del carosello,
           audio, immagini da ingrandire, link). */
        var box = e.target.closest('.qa-examples-box-filled');
        if (!box) return;
        /* .qa-example-image NON e' piu' esclusa: dentro il riquadro l'immagine
           e' l'esempio stesso, e toccarla non faceva niente — un esempio fatto
           di sola immagine risultava, di fatto, non apribile. L'ingrandimento
           a tutto schermo si ottiene dall'interno del popup. */
        if (e.target.closest('.qa-ex-nav, a, audio, .audio-player')) return;
        /* Fine di una sfogliata: il browser manda comunque un click, che
           altrimenti aprirebbe il popup ogni volta che si cambia esempio. */
        if (Date.now() - fcExUltimoSwipe < 400) return;
        e.stopPropagation();
        fcExApri(box);
    });

    document.addEventListener('keydown', function (e) {
        var modal = document.getElementById('exModal');
        if (!modal || !modal.classList.contains('on')) return;
        if (e.key === 'Escape') { e.preventDefault(); fcExChiudi(); }
        else if (e.key === 'ArrowLeft') { e.preventDefault(); fcExVai(-1); }
        else if (e.key === 'ArrowRight') { e.preventDefault(); fcExVai(1); }
    });
})();

/* ---------- Collega progetto (tasto nel riquadro CATEGORIA) ---------- */
/* definito PRIMA di initPage() così, anche se l'init dovesse fallire, il tasto funziona */
var FC_CURRENT_CATEGORY = <?= (int)$categoryID ?>;
window.fcOpenLinkProject = function(){
    var modal = document.getElementById('ps-modal-fclink');
    
    // 1. Controllo di sicurezza base
    if (!modal) {
        alert(T('fc.vis_errore_selettore'));
        return;
    }
    
    // 2. APERTURA FORZATA E MANUALE (Bypassa completamente window.psOpen)
    // Spostiamo il modale in fondo al body per evitare conflitti di z-index o overflow
    if (modal.parentElement !== document.body) {
        document.body.appendChild(modal);
    }
    
    // Aggiungiamo le classi CSS che rendono visibile il popup
    modal.classList.add('ps-open');
    document.documentElement.classList.add('sb-locked');
    
    // 3. Fallback per la chiusura (nel caso in cui mancasse anche window.psClose)
    var closeBtn = modal.querySelector('.ps-sheet-close');
    if (closeBtn && !closeBtn.dataset.fallbackAttached) {
        closeBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            modal.classList.remove('ps-open');
            document.documentElement.classList.remove('sb-locked');
        });
        // Evita che il listener venga aggiunto più volte
        closeBtn.dataset.fallbackAttached = "true"; 
    }
};
document.addEventListener('projectselected', function(e){
    if (!e.detail || e.detail.uid !== 'fclink') return;
    var projectId = e.detail.id;
    if (!projectId || FC_CURRENT_CATEGORY === 0) return;
    fetch(fcUrl('fc_view.php'), {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=fc_link_project&category_id=' + encodeURIComponent(FC_CURRENT_CATEGORY) + '&project_id=' + encodeURIComponent(projectId)
    })
    .then(function(r){ return r.json(); })
    .then(function(data){
        if (data && data.success) {
            if (window.showToast) showToast(data.created ? T('fc.vis_progetto_creato') : T('fc.vis_categoria_collegata'));
            setTimeout(function(){ window.location.reload(); }, 500);
        } else {
            if (window.showToast) showToast((data && data.error) ? data.error : T('fc.vis_errore_collegamento'));
        }
    })
    .catch(function(){ if (window.showToast) showToast(T('comune.errore_rete')); });
});

if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', initPage); }

else { initPage(); }

</script>

<?php
    // Selettore progetto per il tasto "Collega progetto" (nessun trigger visibile:
    // lo apriamo noi da JS). Alla scelta creiamo un sotto-progetto "Flashcards".
    $ps_id = 'fclink'; $ps_selected_id = 0; $ps_selected_name = ''; $ps_selected_color = null;
    $ps_placeholder = t('progetti.seleziona_progetto'); $ps_title = t('todo.collega_progetto');
    $ps_show_all_option = false; $ps_allow_create = true; $ps_render_trigger = false;
    $ps_hidden_name = 'fc_link_project_id'; $ps_exclude_id = 0;
    include __DIR__ . '/../projects/project_selector.php';
?>

</body>

</html>