<?php

require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/auth_check.php';

$current_user_id = (int)$utente_corrente['id'];

/* sec_db() DOPO auth_check.php: e' quest'ultimo a caricare security_core.php,
   dove la funzione e' definita. Chiamarla prima e' un errore fatale e la pagina
   risponde 500 senza dire altro. */
$conn = sec_db();

/* Studio o lingua: con ?course=N questa e' la pagina dei mazzi di un corso di
   lingua, con le stesse funzioni. Vedi fc_type.php. */
require_once __DIR__ . '/../../lib/fc/fc_type.php';
fc_avvia($current_user_id);



/* ==========================================================================

   EREDITARIETÀ COLORE

   - color_edited = 0  -> il colore è "ereditato": risale la catena dei padri

                          finché non trova un colore impostato esplicitamente

                          (color_edited = 1); se nessuno lo ha, usa il grigio

                          di default #A0A0A0.

   - color_edited = 1  -> il colore è stato scelto esplicitamente per QUELLA

                          categoria: quando cambia, va ripropagato a tutti i

                          discendenti con color_edited = 0, fermandosi ai rami

                          che hanno a loro volta un colore esplicito.

   ========================================================================== */

if (!function_exists('cs_resolve_color')) {

        /* $userId e' un parametro nuovo: prima la funzione leggeva qualunque
       categoria, quindi risaliva anche l'albero di un altro utente. */
    function cs_resolve_color($parentId, $userId) {
        $parentId = intval($parentId);
        $guard = 0;
        while ($parentId > 0 && $guard < 50) {
            $guard++;
            $row = sec_one("SELECT parent_id, color, color_edited FROM " . fc_t('decks') . " WHERE id = ? AND user_id = ?",
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

if (!function_exists('cs_cascade_color')) {

        /* Questa e' la piu' pericolosa delle tre: SCRIVE. Senza "AND user_id"
       nella UPDATE, un id passato dal modulo cambiava i colori delle materie di
       un altro utente. */
    function cs_cascade_color($categoryId, $color, $userId, $depth = 0) {
        if ($depth > 50) return;
        $children = sec_all("SELECT id, color_edited FROM " . fc_t('decks') . " WHERE parent_id = ? AND user_id = ?",
                            [(int)$categoryId, (int)$userId]);
        foreach ($children as $child) {
            if (intval($child['color_edited']) === 0) {
                sec_q("UPDATE " . fc_t('decks') . " SET color = ? WHERE id = ? AND user_id = ?",
                      [$color, (int)$child['id'], (int)$userId]);
                cs_cascade_color($child['id'], $color, $userId, $depth + 1);
            }

            // color_edited === 1: questo ramo ha un colore proprio, non si tocca.

        }

    }

}

if (!function_exists('cs_apply_parent_color_update')) {

    // Da chiamare DOPO aver aggiornato il parent_id di una categoria (sposta,

    // o modifica del padre dal form di modifica): se la categoria eredita il

    // colore (color_edited = 0), lo ricalcola dal nuovo padre e lo ripropaga

    // ai suoi discendenti che a loro volta ereditano.

        function cs_apply_parent_color_update($catId, $newParentId, $userId) {
        $row = sec_one("SELECT color_edited FROM " . fc_t('decks') . " WHERE id = ? AND user_id = ?",
                       [(int)$catId, (int)$userId]);
        if (!$row || intval($row['color_edited']) !== 0) return;
        $newColor = cs_resolve_color($newParentId, $userId);
        sec_q("UPDATE " . fc_t('decks') . " SET color = ? WHERE id = ? AND user_id = ?",
              [$newColor, (int)$catId, (int)$userId]);
        cs_cascade_color($catId, $newColor, $userId);
    }

}


/* Vero se, risalendo da $target, si incontra $catId: serve a non infilare una
   categoria dentro un suo stesso discendente, che chiuderebbe un anello e
   renderebbe irraggiungibile tutto il ramo dalla radice. */
if (!function_exists('fc_cat_e_dentro')) {
    function fc_cat_e_dentro($catId, $target, $userId) {
        $risali = (int)$target; $giri = 0;
        while ($risali > 0 && $giri < 200) {
            if ($risali === (int)$catId) return true;
            $rr = sec_one("SELECT parent_id FROM " . fc_t('decks') . " WHERE id = ? AND user_id = ?",
                          [$risali, (int)$userId]);
            $risali = $rr ? (int)$rr['parent_id'] : 0;
            $giri++;
        }
        return false;
    }
}



// --- AZIONE: INSERIMENTO / MODIFICA NOME E PADRE ---

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    /* Token di sessione, origine e limite di frequenza: prima non c'erano, e un
       altro sito poteva far cancellare una materia dal browser di chi e'
       connesso. */
    sec_guard_write((int)$current_user_id, 'fc_categorie', 0, 120, 60);

    /* elenco chiuso: un'azione non prevista non arriva agli if */
    $action = sec_enum($_POST['action'] ?? null, ['save', 'delete', 'move', 'color', 'archive', 'bulk'], '');
    $okParam = '';
    if ($action === 'save') {
        $catId = sec_int($_POST['category_id'] ?? 0, 0);
        $catName = sec_str($_POST['category_name'] ?? '', 120);
        $parentId = sec_int($_POST['parent_id'] ?? 0, 0);
        $colorMode = sec_enum($_POST['color_mode'] ?? null, ['auto', 'manual'], 'auto');
        $manualColor = sec_str($_POST['category_color'] ?? '', 7);

        /* Il padre dev'essere suo, e la categoria modificata anche: senza questi
           controlli si poteva agganciare una propria materia sotto quella di un
           altro, o passare l'id di una materia altrui. */
        if ($parentId > 0 && !fc_mazzo_mio($parentId, (int)$current_user_id)) $parentId = 0;
        if ($catId > 0 && !fc_mazzo_mio($catId, (int)$current_user_id)) {
            header('Location: ' . fc_url('fc_decks.php')); exit;
        }
        /* Anche dal form di modifica si puo' cambiare il padre: se la scelta
           cadesse dentro il proprio ramo l'albero si spezzerebbe, quindi la
           si rifiuta lasciando il padre invariato. */
        if ($catId > 0 && $parentId > 0 && fc_cat_e_dentro($catId, $parentId, $current_user_id)) {
            $vecchio = sec_one("SELECT parent_id FROM " . fc_t('decks') . " WHERE id = ? AND user_id = ?",
                               [$catId, (int)$current_user_id]);
            $parentId = $vecchio ? (int)$vecchio['parent_id'] : 0;
        }

        if ($colorMode === 'manual' && preg_match('/^#[0-9A-Fa-f]{6}$/', $manualColor)) {
            $finalColor = $manualColor;
            $colorEditedFlag = 1;
        } else {
            $finalColor = cs_resolve_color($parentId, $current_user_id);
            $colorEditedFlag = 0;
        }
        if ($catId === 0) {
            [$xCol, $xPh, $xVal] = fc_mazzo_nuovo_extra();
            sec_q("INSERT INTO " . fc_t('decks') . " (name, parent_id, color, color_edited, user_id$xCol) VALUES (?, ?, ?, ?, ?$xPh)",
                  array_merge([$catName, $parentId, $finalColor, $colorEditedFlag, (int)$current_user_id], $xVal));
            $okParam = 'added';
        } else {
            sec_q("UPDATE " . fc_t('decks') . " SET name = ?, parent_id = ?, color = ?, color_edited = ? WHERE id = ? AND user_id = ?",
                  [$catName, $parentId, $finalColor, $colorEditedFlag, $catId, (int)$current_user_id]);
            cs_cascade_color($catId, $finalColor, $current_user_id);
            $okParam = 'saved';
        }
    } elseif ($action === 'delete' && isset($_POST['delete_category_id'])) {
        $catId = sec_int($_POST['delete_category_id'] ?? 0, 1);
        if (fc_mazzo_mio($catId, (int)$current_user_id)) {
            sec_q("DELETE FROM " . fc_t('decks') . " WHERE id = ? AND user_id = ?", [$catId, (int)$current_user_id]);
            $okParam = 'deleted';
        }

    /* ------------------------------------------------------------------
       ARCHIVIA / DISARCHIVIA una singola categoria.
       Si scrive solo sulla riga toccata: i discendenti seguono il padre a
       video, senza che il loro flag venga riscritto (vedi il commento in
       migrazione_categorie_archivio.sql).
       ------------------------------------------------------------------ */
    } elseif ($action === 'archive' && isset($_POST['category_id'])) {
        $catId = sec_int($_POST['category_id'] ?? 0, 1);
        $val   = sec_int($_POST['archived'] ?? 0, 0) === 1 ? 1 : 0;
        if (fc_mazzo_mio($catId, (int)$current_user_id)) {
            sec_q("UPDATE " . fc_t('decks') . " SET archived = ? WHERE id = ? AND user_id = ?",
                  [$val, $catId, (int)$current_user_id]);
            $okParam = $val ? 'archived' : 'unarchived';
        }

    /* ------------------------------------------------------------------
       AZIONI DI GRUPPO (modalita' selezione).
       Gli id arrivano in una sola stringa separata da virgole: ognuno viene
       comunque ricontrollato uno per uno con sec_owns, perche' la lista la
       compone il browser e non e' una fonte di cui fidarsi.
       ------------------------------------------------------------------ */
    } elseif ($action === 'bulk') {
        $bulkOp = sec_enum($_POST['bulk_op'] ?? null, ['move', 'delete', 'archive', 'unarchive', 'color'], '');
        $bulkIds = [];
        foreach (explode(',', (string)($_POST['bulk_ids'] ?? '')) as $rawId) {
            $n = sec_int($rawId, 0);
            if ($n > 0 && fc_mazzo_mio($n, (int)$current_user_id)) $bulkIds[$n] = $n;
        }
        $bulkIds = array_values($bulkIds);

        if (!empty($bulkIds) && $bulkOp !== '') {
            if ($bulkOp === 'archive' || $bulkOp === 'unarchive') {
                $val = ($bulkOp === 'archive') ? 1 : 0;
                foreach ($bulkIds as $bid) {
                    sec_q("UPDATE " . fc_t('decks') . " SET archived = ? WHERE id = ? AND user_id = ?",
                          [$val, $bid, (int)$current_user_id]);
                }
                $okParam = $val ? 'archived' : 'unarchived';

            } elseif ($bulkOp === 'move') {
                $targetParentId = sec_int($_POST['target_parent_id'] ?? 0, 0);
                if ($targetParentId === 0 || fc_mazzo_mio($targetParentId, (int)$current_user_id)) {
                    foreach ($bulkIds as $bid) {
                        /* Nel gruppo si salta e basta: qui non ha senso la
                           promozione dei figli fatta dallo spostamento
                           singolo, perche' con piu' categorie insieme il
                           risultato sarebbe imprevedibile per chi guarda. */
                        if ($bid === $targetParentId) continue;
                        if ($targetParentId > 0 && fc_cat_e_dentro($bid, $targetParentId, $current_user_id)) continue;
                        sec_q("UPDATE " . fc_t('decks') . " SET parent_id = ? WHERE id = ? AND user_id = ?",
                              [$targetParentId, $bid, (int)$current_user_id]);
                        cs_apply_parent_color_update($bid, $targetParentId, $current_user_id);
                    }
                    $okParam = 'moved';
                }

            } elseif ($bulkOp === 'color') {
                $colorMode = sec_enum($_POST['color_mode'] ?? null, ['auto', 'manual'], 'manual');
                foreach ($bulkIds as $bid) {
                    if ($colorMode === 'auto') {
                        $catRow = sec_one("SELECT parent_id FROM " . fc_t('decks') . " WHERE id = ? AND user_id = ?",
                                          [$bid, (int)$current_user_id]);
                        $color = cs_resolve_color($catRow ? $catRow['parent_id'] : 0, $current_user_id);
                        sec_q("UPDATE " . fc_t('decks') . " SET color = ?, color_edited = 0 WHERE id = ? AND user_id = ?",
                              [$color, $bid, (int)$current_user_id]);
                        cs_cascade_color($bid, $color, $current_user_id);
                    } else {
                        $color = sec_color($_POST['category_color'] ?? null, '');
                        if ($color !== '') {
                            sec_q("UPDATE " . fc_t('decks') . " SET color = ?, color_edited = 1 WHERE id = ? AND user_id = ?",
                                  [$color, $bid, (int)$current_user_id]);
                            cs_cascade_color($bid, $color, $current_user_id);
                        }
                    }
                }
                $okParam = 'colored';

            } elseif ($bulkOp === 'delete') {
                /* Si cancella solo quello che si potrebbe cancellare anche da
                   solo: niente carte dentro e nessun figlio. Ma selezionando
                   un padre INSIEME ai suoi figli il padre diventa cancellabile
                   appena spariscono i figli, quindi si ripassa finche' un giro
                   intero non cancella piu' niente. */
                $rimaste = $bulkIds;
                $cancellate = 0;
                do {
                    $progresso = false;
                    foreach ($rimaste as $k => $cid) {
                        $nFigli = (int)(sec_one("SELECT COUNT(*) AS c FROM " . fc_t('decks') . " WHERE parent_id = ? AND user_id = ?",
                                                [$cid, (int)$current_user_id])['c'] ?? 0);
                        $nCarte = (int)(sec_one("SELECT COUNT(*) AS c FROM " . fc_t('cards') . " WHERE category_id = ?", [$cid])['c'] ?? 0);
                        if ($nFigli === 0 && $nCarte === 0) {
                            sec_q("DELETE FROM " . fc_t('decks') . " WHERE id = ? AND user_id = ?", [$cid, (int)$current_user_id]);
                            unset($rimaste[$k]);
                            $cancellate++;
                            $progresso = true;
                        }
                    }
                } while ($progresso && !empty($rimaste));
                $okParam = $cancellate > 0 ? (empty($rimaste) ? 'deleted' : 'deleted_partial') : 'deleted_none';
            }
        }

    } elseif ($action === 'move' && isset($_POST['move_category_id']) && isset($_POST['target_parent_id'])) {

                $moveId = sec_int($_POST['move_category_id'] ?? 0, 1);
        $targetParentId = sec_int($_POST['target_parent_id'] ?? 0, 0);
        /* sia la materia spostata sia la destinazione devono essere sue */
        if (fc_mazzo_mio($moveId, (int)$current_user_id)
            && ($targetParentId === 0 || fc_mazzo_mio($targetParentId, (int)$current_user_id))) {

            /* SPOSTARE UNA MATERIA DENTRO UNA SUA DISCENDENTE
               Preso alla lettera sarebbe impossibile: B dentro E, con E gia'
               dentro B, chiude un anello — B sta in E e E sta in B, e nessuno
               dei due si raggiunge piu' partendo dalla radice. Non e' un albero
               storto, e' un albero che non c'e' piu'.
               Ma la richiesta ha un senso, e uno solo: si vuole che E diventi
               il padre di B. Perche' possa esserlo deve prima uscire da B, e
               con lui i suoi fratelli — i figli di B salgono al posto che B
               lascia, poi B si infila sotto E.
                   A(B(E(G), F), C, D)   ->   A(E(B, G), F, C, D)
               Nessuna materia si perde e nessun ramo resta appeso al vuoto:
               quello che cambia e' solo di chi sono figli, ed e' esattamente
               quello che si stava chiedendo. */
            $eDentro = fc_cat_e_dentro($moveId, $targetParentId, $current_user_id);

            if ($eDentro) {
                $suo = sec_one("SELECT parent_id FROM " . fc_t('decks') . " WHERE id = ? AND user_id = ?",
                               [$moveId, (int)$current_user_id]);
                $sopra = $suo ? (int)$suo['parent_id'] : 0;
                /* Gli id dei figli si leggono PRIMA di spostarli: dopo l'UPDATE
                   non sarebbero piu' figli di nessuno che sappiamo cercare, e
                   servono ancora per rifargli il colore ereditato. */
                $promossi = sec_all("SELECT id FROM " . fc_t('decks') . " WHERE parent_id = ? AND user_id = ?",
                                    [$moveId, (int)$current_user_id]);
                sec_q("UPDATE " . fc_t('decks') . " SET parent_id = ? WHERE parent_id = ? AND user_id = ?",
                      [$sopra, $moveId, (int)$current_user_id]);
                foreach ($promossi as $pr) {
                    cs_apply_parent_color_update((int)$pr['id'], $sopra, $current_user_id);
                }
            }

            sec_q("UPDATE " . fc_t('decks') . " SET parent_id = ? WHERE id = ? AND user_id = ?",
                  [$targetParentId, $moveId, (int)$current_user_id]);
            cs_apply_parent_color_update($moveId, $targetParentId, $current_user_id);
            $okParam = 'moved';
        }

    } elseif ($action === 'color' && isset($_POST['category_id'])) {

        $catId = sec_int($_POST['category_id'] ?? 0, 1);
        /* "auto" non e' un colore ma una rinuncia: si smette di averne uno
           proprio e si riprende quello ereditato dal padre. Senza questo modo
           il popup del colore poteva solo IMPORRE un colore, mai toglierlo. */
        $colorMode = sec_enum($_POST['color_mode'] ?? null, ['auto', 'manual'], 'manual');
        if (fc_mazzo_mio($catId, (int)$current_user_id)) {
            if ($colorMode === 'auto') {
                $catRow = sec_one("SELECT parent_id FROM " . fc_t('decks') . " WHERE id = ? AND user_id = ?",
                                  [$catId, (int)$current_user_id]);
                $color = cs_resolve_color($catRow ? $catRow['parent_id'] : 0, $current_user_id);
                sec_q("UPDATE " . fc_t('decks') . " SET color = ?, color_edited = 0 WHERE id = ? AND user_id = ?",
                      [$color, $catId, (int)$current_user_id]);
                cs_cascade_color($catId, $color, $current_user_id);
                $okParam = 'colored';
            } else {
                $color = sec_color($_POST['category_color'] ?? null, '');
                if ($color !== '') {
                    sec_q("UPDATE " . fc_t('decks') . " SET color = ?, color_edited = 1 WHERE id = ? AND user_id = ?",
                          [$color, $catId, (int)$current_user_id]);
                    cs_cascade_color($catId, $color, $current_user_id);
                    $okParam = 'colored';
                }
            }
        }

    }



    header('Location: ' . fc_url('fc_decks.php', $okParam ? ['ok' => $okParam] : []));

    exit;

}




/* ==========================================================================
   LETTURA DELL'ALBERO
   ========================================================================== */

$result = sec_all("SELECT c.*, IFNULL(im.image_count, 0) AS image_count
          FROM " . fc_t('decks') . " AS c
          LEFT JOIN (
              SELECT category_id, COUNT(*) AS image_count FROM " . fc_t('cards') . " GROUP BY category_id
          ) AS im ON c.id = im.category_id
          WHERE c.user_id = ?" . fc_filtro_corso('c')[0] . "
          ORDER BY c.name", array_merge([(int)$current_user_id], fc_filtro_corso('c')[1]));
$all_categories = [];
foreach ($result as $row) {

    $all_categories[$row['id']] = $row;

}

/* Conteggio delle SOTTO-CARTE per categoria: nella vista a griglia il numero
   di carte deve dire quanto materiale c'e' davvero dentro la cartella, e una
   carta con cinque figlie vale sei carte, non una. Sono due query sole, fuori
   da qualunque ciclo. */
$sub_card_counts = [];
foreach (sec_all("SELECT i.category_id AS cid, COUNT(*) AS c
                  FROM " . fc_t('subcards') . " ch
                  INNER JOIN " . fc_t('cards') . " i ON i.id = ch.parent_id
                  INNER JOIN " . fc_t('decks') . " cc ON cc.id = i.category_id AND cc.user_id = ?" . fc_filtro_corso('cc')[0] . "
                  GROUP BY i.category_id", array_merge([(int)$current_user_id], fc_filtro_corso('cc')[1])) as $r) {
    $sub_card_counts[(int)$r['cid']] = (int)$r['c'];
}


/* Vero se la categoria e' archiviata o ha un antenato archiviato: e' questa la
   "cascata a video" che tiene un ramo archiviato tutto insieme senza scrivere
   il flag sui figli. */
if (!function_exists('fc_in_ramo_archiviato')) {
    function fc_in_ramo_archiviato($id, $all) {
        $g = 0;
        while ($id > 0 && $g < 60) {
            $g++;
            if (!isset($all[$id])) return false;
            if ((int)$all[$id]['archived'] === 1) return true;
            $id = (int)$all[$id]['parent_id'];
        }
        return false;
    }
}

/* Due alberi separati, costruiti in un giro solo:
     - attive:   tutto quello che non sta dentro un ramo archiviato
     - archivio: i rami archiviati, ognuno appeso alla propria radice
   La "radice di un ramo archiviato" e' la categoria archiviata il cui padre
   NON e' a sua volta dentro un ramo archiviato: e' quella su cui si e'
   premuto "archivia", e nell'archivio compare come voce di primo livello
   anche se nell'albero vero e' una sottocategoria. */
$active_by_parent   = [];
$archived_by_parent = [];
$archivedRootIds    = [];

foreach ($all_categories as $cid => $crow) {
    $cid = (int)$cid;
    $pid = (int)$crow['parent_id'];
    if (!fc_in_ramo_archiviato($cid, $all_categories)) {
        $active_by_parent[$pid][] = $cid;
    } else {
        $padreDentro = ($pid > 0 && isset($all_categories[$pid]))
                       ? fc_in_ramo_archiviato($pid, $all_categories) : false;
        if ($padreDentro) $archived_by_parent[$pid][] = $cid;
        else              $archivedRootIds[] = $cid;
    }
}
$rootIds = isset($active_by_parent[0]) ? $active_by_parent[0] : [];

/* I numeri sulle due linguette contano le CATEGORIE PRINCIPALI, cioe' le voci
   che si vedono davvero in cima all'elenco sotto quella linguetta — non tutti
   i discendenti. Contando tutto, "Attive 87" diceva quante righe esistono
   nell'albero, che non e' un'informazione che serve a scegliere fra le due
   linguette; cosi' invece dice quante materie ci sono da una parte e
   dall'altra. */
$nAttive     = count($rootIds);
$nArchiviate = count($archivedRootIds);

/* Percorso testuale (dalla radice, escluso il nodo stesso): serve alla
   ricerca, che deve dire DOVE sta quello che ha trovato. */
if (!function_exists('fc_percorso_cat')) {
    function fc_percorso_cat($id, $all) {
        $out = []; $g = 0;
        $pid = isset($all[$id]) ? (int)$all[$id]['parent_id'] : 0;
        while ($pid > 0 && $g < 60) {
            $g++;
            if (!isset($all[$pid])) break;
            array_unshift($out, (string)$all[$pid]['name']);
            $pid = (int)$all[$pid]['parent_id'];
        }
        return $out;
    }
}

if (!function_exists('fc_low')) {
    function fc_low($s) {
        return function_exists('mb_strtolower') ? mb_strtolower((string)$s, 'UTF-8') : strtolower((string)$s);
    }
}

/* ==========================================================================
   ESTRARRE UN PEZZO DI CARTA DA MOSTRARE FRA I RISULTATI

   Il testo delle carte non e' testo: e' il documento di fc_parser, cioe' un
   JSON di blocchi e di "run" con i loro marchi (grassetto, colore, formule).
   Mandarlo cosi' com'e' all'elenco dei risultati vuol dire far leggere
   {"v":2,"blocks":[{"t":"p","runs":[...]}]}, che e' esattamente quello che si
   vedeva prima.

   La strada scelta e' quella di far disegnare il pezzo a fc_parser, lo stesso
   che disegna le carte vere: qui si ritaglia il DOCUMENTO (il blocco che
   contiene la parola cercata, accorciato attorno a lei conservando i marchi
   dei run superstiti) e si manda al browser un mini-documento valido, che
   parseTextForPreview() sa rendere. Cosi' il risultato e' formattato come la
   carta da cui viene, senza doppiare in PHP il disegnatore.

   Insieme viaggia sempre una versione in solo testo: e' quella che si vede se
   fc_parser non e' disponibile, ed e' anche quella su cui si controlla che la
   parola cercata stia davvero NEL TESTO e non fra le chiavi del JSON — il
   LIKE del database non sa distinguerle.
   ========================================================================== */

if (!function_exists('fc_cat_testo_blocco')) {
    function fc_cat_testo_blocco($b) {
        if (!is_array($b)) return '';
        if (($b['t'] ?? '') === 'fx') return (string)($b['tex'] ?? '');
        $out = '';
        foreach (($b['runs'] ?? []) as $r) {
            if (is_string($r)) { $out .= $r; continue; }
            if (!is_array($r)) continue;
            $out .= isset($r['x']) ? (string)$r['x'] : (string)($r['s'] ?? '');
        }
        return $out;
    }
}

if (!function_exists('fc_cat_ritaglia_blocco')) {
    /* Taglia il blocco alla finestra [$da, $a) contando i CARATTERI del testo
       che si vede, non i run: un run e' una fetta di testo con gli stessi
       marchi, e la parola cercata puo' benissimo cascare a cavallo di due.
       I run che restano dentro la finestra si portano dietro il loro 'm', per
       questo il grassetto e i colori sopravvivono al ritaglio. */
    function fc_cat_ritaglia_blocco($b, $da, $a) {
        if (($b['t'] ?? '') === 'fx') return $b;
        $nuovi = [];
        $pos = 0;
        foreach (($b['runs'] ?? []) as $r) {
            if (is_string($r)) $r = ['s' => $r];
            if (!is_array($r)) continue;

            if (isset($r['x'])) {
                /* Una formula in linea non si taglia a meta': o ci sta dentro
                   la finestra o si lascia fuori. */
                $len = mb_strlen((string)$r['x']);
                if ($pos + $len > $da && $pos < $a) $nuovi[] = $r;
                $pos += $len;
                continue;
            }

            $t = (string)($r['s'] ?? '');
            $len = mb_strlen($t);
            if ($pos + $len > $da && $pos < $a) {
                $i = max(0, $da - $pos);
                $j = min($len, $a - $pos);
                $pezzo = mb_substr($t, $i, max(0, $j - $i));
                if ($pezzo !== '') {
                    $nr = ['s' => $pezzo];
                    if (isset($r['m'])) $nr['m'] = $r['m'];
                    $nuovi[] = $nr;
                }
            }
            $pos += $len;
        }
        $b['runs'] = $nuovi;
        return $b;
    }
}

if (!function_exists('fc_cat_estratto')) {
    function fc_cat_estratto($raw, $termine, $largo = 150) {
        $vuoto = ['doc' => null, 'txt' => '', 'match' => false];
        $raw = trim((string)$raw);
        if ($raw === '') return $vuoto;

        $doc = json_decode($raw, true);

        /* Formato vecchio (o testo semplice): niente documento da ritagliare,
           si toglie la marcatura e si manda solo testo. Sono le carte
           scritte prima di fc_parser, ed e' giusto che costino meno. */
        if (!is_array($doc) || !isset($doc['blocks']) || !is_array($doc['blocks'])) {
            $t = preg_replace('#/[a-z]+\{([^}]*)\}#iu', '$1', $raw);
            $t = trim(preg_replace('/\s+/u', ' ', strip_tags($t)));
            if ($t === '') return $vuoto;
            $pos = mb_stripos($t, $termine);
            if ($pos === false) {
                return ['doc' => null,
                        'txt' => mb_strlen($t) > $largo ? mb_substr($t, 0, $largo) . '…' : $t,
                        'match' => false];
            }
            $da = max(0, $pos - intval($largo / 3));
            $out = mb_substr($t, $da, $largo);
            if ($da > 0) $out = '…' . $out;
            if ($da + $largo < mb_strlen($t)) $out .= '…';
            return ['doc' => null, 'txt' => $out, 'match' => true];
        }

        // Formato nuovo: si cerca il blocco che contiene la parola.
        $trovatoK = -1; $trovatoP = 0;
        $primoK = -1;
        foreach ($doc['blocks'] as $k => $b) {
            $t = fc_cat_testo_blocco($b);
            if (trim($t) === '') continue;
            if ($primoK < 0) $primoK = $k;
            $p = ($termine === '') ? false : mb_stripos($t, $termine);
            if ($p !== false) { $trovatoK = $k; $trovatoP = $p; break; }
        }

        $k = ($trovatoK >= 0) ? $trovatoK : $primoK;
        if ($k < 0) return $vuoto;

        $blocco = $doc['blocks'][$k];
        $testoBlocco = fc_cat_testo_blocco($blocco);
        $lungo = mb_strlen($testoBlocco);
        $da = ($trovatoK >= 0) ? max(0, $trovatoP - intval($largo / 3)) : 0;
        $a  = $da + $largo;

        $ritagliato = fc_cat_ritaglia_blocco($blocco, $da, $a);
        /* I puntini di sospensione entrano nel documento come testo normale:
           devono farsi disegnare insieme al resto, non essere incollati fuori
           dall'HTML che fc_parser produce. */
        if ($da > 0 && !empty($ritagliato['runs'])) {
            array_unshift($ritagliato['runs'], ['s' => '…']);
        }
        if ($a < $lungo && !empty($ritagliato['runs'])) {
            $ritagliato['runs'][] = ['s' => '…'];
        }

        $txt = mb_substr($testoBlocco, $da, $largo);
        if ($da > 0) $txt = '…' . $txt;
        if ($a < $lungo) $txt .= '…';

        return [
            'doc'   => json_encode(['v' => 2, 'blocks' => [$ritagliato]], JSON_UNESCAPED_UNICODE),
            'txt'   => $txt,
            'match' => ($trovatoK >= 0),
        ];
    }
}



$totalImages = (int)(sec_one("SELECT COUNT(img.id) AS total
    FROM " . fc_t('cards') . " img
    INNER JOIN " . fc_t('decks') . " cat ON img.category_id = cat.id
    WHERE cat.user_id = ?" . fc_filtro_corso('cat')[0], array_merge([(int)$current_user_id], fc_filtro_corso('cat')[1]))['total'] ?? 0);



$catCount = count($all_categories);


// Mappa categoria -> progetto collegato (per il tag "progetto" sotto al nome).
// fc_study_deck_projects collega un progetto a una categoria flashcard.
$cat_project_links = [];
/* Solo per lo studio: un corso di lingua ha il SUO progetto (fc_vocab_courses.project_id), non uno per mazzo. */
$cplRes = fc_is_vocab() ? [] : sec_all("SELECT pfc.fc_category_id, p.id AS pid, p.name AS pname, p.color AS pcolor, pp.name AS parent_name
                   FROM fc_study_deck_projects pfc
                   INNER JOIN projects p ON p.id = pfc.project_id AND p.user_id = ?
                   LEFT JOIN projects pp ON pp.id = p.parent_id AND pp.user_id = ?
                   WHERE pfc.fc_category_id IN (SELECT id FROM " . fc_t('decks') . " WHERE user_id = ?)",
                  [(int)$current_user_id, (int)$current_user_id, (int)$current_user_id]);
if ($cplRes) {
    foreach ($cplRes as $cpl) {
        $pcol = (!empty($cpl['pcolor']) && preg_match('/^#[0-9A-Fa-f]{6}$/', $cpl['pcolor'])) ? $cpl['pcolor'] : '#8E8E93';
        $cat_project_links[(int)$cpl['fc_category_id']] = ['id' => (int)$cpl['pid'], 'name' => $cpl['pname'], 'parent_name' => $cpl['parent_name'], 'color' => $pcol];
    }
}


/* ==========================================================================
   RICERCA POTENZIATA (endpoint JSON)

   Tre ambiti indipendenti, che il pannello dei filtri accende e spegne:
     cat   -> nomi delle categorie di primo livello
     sub   -> nomi a qualunque profondita', mostrati col loro percorso
     cards -> testo dentro le carte (domanda o risposta), raggruppato per
              cartella, sempre col percorso

   Il testo delle carte non e' nel DOM di questa pagina, quindi la ricerca non
   puo' essere solo lato browser come prima: qui si interroga il database una
   volta sola per ambito e si restituisce gia' tutto impaginato in JSON.
   ========================================================================== */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'search') {
    header('Content-Type: application/json; charset=utf-8');

    $q = trim((string)sec_str($_GET['q'] ?? '', 80));
    $scope    = sec_enum($_GET['scope'] ?? null, ['active', 'archived'], 'active');
    $wantCat  = ($_GET['f_cat']   ?? '1') === '1';
    $wantSub  = ($_GET['f_sub']   ?? '1') === '1';
    $wantCard = ($_GET['f_cards'] ?? '0') === '1';

    if ($q === '' || mb_strlen($q) < 2) {
        echo json_encode(['success' => true, 'categories' => [], 'groups' => [], 'totalCards' => 0]);
        exit;
    }
    $qLow = fc_low($q);

    /* Insieme delle categorie che l'ambito corrente puo' toccare: cercando
       fra le attive non devono spuntare risultati archiviati, e viceversa. */
    $ammesse = [];
    foreach ($all_categories as $cid => $crow) {
        $dentro = fc_in_ramo_archiviato((int)$cid, $all_categories);
        if (($scope === 'archived') === $dentro) $ammesse[(int)$cid] = true;
    }

    // ---- nomi di categoria ----
    $catsOut = [];
    if ($wantCat || $wantSub) {
        foreach ($all_categories as $cid => $crow) {
            $cid = (int)$cid;
            if (!isset($ammesse[$cid])) continue;
            if (mb_strpos(fc_low($crow['name']), $qLow) === false) continue;

            $percorso = fc_percorso_cat($cid, $all_categories);
            /* "primo livello" e' relativo all'ambito: nell'archivio la radice
               del ramo archiviato conta come primo livello anche se nel
               database ha un padre. */
            $eRadice = ($scope === 'archived')
                       ? in_array($cid, $archivedRootIds, true)
                       : ((int)$crow['parent_id'] === 0);
            if ($eRadice && !$wantCat) continue;
            if (!$eRadice && !$wantSub) continue;
            if ($scope === 'archived' && $eRadice) $percorso = [];

            $nFigli = 0;
            foreach ($all_categories as $ccid => $cc) { if ((int)$cc['parent_id'] === $cid) $nFigli++; }

            $catsOut[] = [
                'id'    => $cid,
                'name'  => (string)$crow['name'],
                'color' => (!empty($crow['color']) && preg_match('/^#[0-9A-Fa-f]{6}$/', $crow['color'])) ? $crow['color'] : '#007AFF',
                'path'  => $percorso,
                'cards' => (int)$crow['image_count'] + (int)($sub_card_counts[$cid] ?? 0),
                'subs'  => $nFigli,
            ];
            if (count($catsOut) >= 80) break;
        }
    }

    // ---- testo delle carte ----
    $groups = []; $totalCards = 0;
    if ($wantCard && !empty($ammesse)) {
        $ids = array_keys($ammesse);
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        /* I jolly di LIKE vanno neutralizzati: cercando "_" o "%" si otterrebbe
           altrimenti l'intero archivio invece delle carte che contengono quel
           carattere. */
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';

        $righe = sec_all(
            "SELECT i.id AS rid, i.category_id AS cid, i." . fc_col_fronte() . " AS qd, i." . fc_col_retro() . " AS ad, 0 AS is_child
             FROM " . fc_t('cards') . " i
             INNER JOIN " . fc_t('decks') . " c ON c.id = i.category_id AND c.user_id = ?
             WHERE i.category_id IN ($ph) AND (i." . fc_col_fronte() . " LIKE ? OR i." . fc_col_retro() . " LIKE ?)
             ORDER BY i.custom_order ASC, i.id ASC
             LIMIT 200",
            array_merge([(int)$current_user_id], $ids, [$like, $like]));

        $righeFigli = sec_all(
            "SELECT ch.id_child AS rid, i.category_id AS cid, ch." . fc_col_fronte() . " AS qd, ch." . fc_col_retro() . " AS ad, 1 AS is_child
             FROM " . fc_t('subcards') . " ch
             INNER JOIN " . fc_t('cards') . " i ON i.id = ch.parent_id
             INNER JOIN " . fc_t('decks') . " c ON c.id = i.category_id AND c.user_id = ?
             WHERE i.category_id IN ($ph) AND (ch." . fc_col_fronte() . " LIKE ? OR ch." . fc_col_retro() . " LIKE ?)
             ORDER BY ch.custom_order ASC, ch.id_child ASC
             LIMIT 200",
            array_merge([(int)$current_user_id], $ids, [$like, $like]));

        $perCat = [];
        foreach (array_merge($righe, $righeFigli) as $r) {
            $lq = fc_cat_estratto((string)$r['qd'], $q);
            $la = fc_cat_estratto((string)$r['ad'], $q);
            /* Il LIKE ha guardato dentro al JSON per intero, chiavi comprese:
               una carta che contiene "runs" o "blocks" solo come struttura non
               e' un risultato. Se la parola non e' nel testo che si legge, la
               riga si butta. */
            if (!$lq['match'] && !$la['match']) continue;

            $cid = (int)$r['cid'];
            $perCat[$cid][] = [
                'child' => (int)$r['is_child'] === 1,
                'qd'    => $lq['doc'], 'qt' => $lq['txt'],
                'ad'    => $la['doc'], 'at' => $la['txt'],
            ];
            $totalCards++;
        }
        foreach ($perCat as $cid => $items) {
            if (!isset($all_categories[$cid])) continue;
            $crow = $all_categories[$cid];
            $groups[] = [
                'id'    => $cid,
                'name'  => (string)$crow['name'],
                'color' => (!empty($crow['color']) && preg_match('/^#[0-9A-Fa-f]{6}$/', $crow['color'])) ? $crow['color'] : '#007AFF',
                'path'  => fc_percorso_cat($cid, $all_categories),
                'items' => array_slice($items, 0, 12),
                'n'     => count($items),
            ];
        }
    }

    echo json_encode(['success' => true, 'categories' => $catsOut, 'groups' => $groups, 'totalCards' => $totalCards],
                     JSON_UNESCAPED_UNICODE);
    exit;
}


/* Dati dell'albero per la vista a griglia: la griglia si sfoglia livello per
   livello senza ricaricare la pagina, quindi le serve tutto l'albero in
   memoria invece che il solo pezzo disegnato adesso. */
$catJs = [];
foreach ($all_categories as $cid => $crow) {
    $cid = (int)$cid;
    $catJs[] = [
        'id'    => $cid,
        'p'     => (int)$crow['parent_id'],
        'n'     => (string)$crow['name'],
        'c'     => (!empty($crow['color']) && preg_match('/^#[0-9A-Fa-f]{6}$/', $crow['color'])) ? $crow['color'] : '#007AFF',
        'arc'   => (int)$crow['archived'],
        'cards' => (int)$crow['image_count'] + (int)($sub_card_counts[$cid] ?? 0),
    ];
}


$colorPalette = ['#FF3B30', '#FF9500', '#FFCC00', '#34C759', '#00C7BE', '#30B0C7', '#32ADE6', '#007AFF', '#5856D6', '#AF52DE', '#FF2D55', '#A2845E'];


/* Le due catene di anelli: e' l'icona che in tutto il sito (inserimento_big)
   vuol dire "aggancia questa cosa a un'altra", ed e' quindi la stessa che
   deve comparire sul tasto che sposta una categoria sotto un altro padre. */
define('FC_ICON_LINK', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M9.5 14.5 14.5 9.5M8 17l-2 2a3.5 3.5 0 0 1-5-5l3-3a3.5 3.5 0 0 1 5 0M16 7l2-2a3.5 3.5 0 0 1 5 5l-3 3a3.5 3.5 0 0 1-5 0"/></svg>');
define('FC_ICON_ARCHIVE', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 8h18v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8Z"/><path d="M2 4h20v4H2z"/><path d="M10 12h4"/></svg>');
define('FC_ICON_UNARCHIVE', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 8h18v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8Z"/><path d="M2 4h20v4H2z"/><path d="m12 18-3-3h6l-3 3Z"/><path d="M12 11v6"/></svg>');


function renderCatRow($id, $level, $all_cats, $cats_by_parent, $archPane = false) {
    global $cat_project_links, $sub_card_counts;
    $cat = $all_cats[$id];
    $has_children = isset($cats_by_parent[$id]) && count($cats_by_parent[$id]) > 0;
    $can_delete = ($cat['image_count'] == 0 && !$has_children) ? 1 : 0;
    $display = ($level === 0) ? 'flex' : 'none';
    $pad = 12 + $level * 24;
    $nameEsc = sec_h($cat['name']);
    $color = (!empty($cat['color']) && preg_match('/^#[0-9A-Fa-f]{6}$/', $cat['color'])) ? $cat['color'] : '#007AFF';
    $parentId = (int)$cat['parent_id'];
    $parentName = '';
    $parentColor = '#A0A0A0';
    if ($parentId !== 0 && isset($all_cats[$parentId])) {
        $parentName = $all_cats[$parentId]['name'];
        $pColorRaw = $all_cats[$parentId]['color'];
        $parentColor = (!empty($pColorRaw) && preg_match('/^#[0-9A-Fa-f]{6}$/', $pColorRaw)) ? $pColorRaw : '#A0A0A0';
    }
    /* Nell'archivio il tasto "ripristina" sta solo sulla radice del ramo: sono
       i discendenti a seguirla, e offrire di disarchiviare una figlia il cui
       padre resta archiviato prometterebbe qualcosa che non si vedrebbe. */
    $mostraArchivio = $archPane ? ($level === 0) : true;
?>
<div class="cat-row" data-id="<?php echo (int)$id; ?>" data-parent="<?php echo (int)$cat['parent_id']; ?>" data-level="<?php echo (int)$level; ?>" data-name="<?php echo sec_h($nameEsc); ?>"
     data-parent-name="<?php echo sec_h($parentName); ?>" data-parent-color="<?php echo sec_h($parentColor); ?>"
     data-color="<?php echo sec_h($color); ?>" data-color-edited="<?php echo (int)($cat['color_edited'] ?? 0); ?>"
     data-archived="<?php echo (int)($cat['archived'] ?? 0); ?>" data-candelete="<?php echo (int)$can_delete; ?>"
     style="display:<?php echo sec_h($display); ?>; --row-color:<?php echo sec_h($color); ?>;">

    <!-- TASTI SWIPE VERSO DESTRA (Nascosti sotto, a sinistra) -->
    <div class="cat-swipe-actions">
        <button type="button" class="swipe-btn swipe-edit" aria-label="<?php te('comune.modifica'); ?>"
                        onclick="chiudiTuttiSwipe(); apriModalSalvaDaRiga(this)">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
        </button>
        <button type="button" class="swipe-btn swipe-move" aria-label="<?php te('vocab.cat_sposta_attr'); ?>"
                        onclick="avviaSpostaDaRiga(this)">
            <?php echo FC_ICON_LINK; ?>
        </button>
        <?php if ($can_delete): ?>
        <button type="button" class="swipe-btn swipe-delete" aria-label="<?php te('comune.elimina'); ?>"
                        onclick="chiudiTuttiSwipe(); apriConfermaEliminaDaRiga(this)">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0-1 14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2L4 6"/></svg>
        </button>
        <?php endif; ?>
    </div>

    <?php if ($mostraArchivio): ?>
    <!-- TASTO SWIPE VERSO SINISTRA (Nascosto sotto, a destra): archivia -->
    <div class="cat-swipe-arch">
        <button type="button" class="swipe-btn swipe-archive<?php echo $archPane ? ' is-restore' : ''; ?>"
                onclick="archiviaDaRiga(this)"
                aria-label="<?php echo $archPane ? T('fc.cfc_ripristina') : T('fc.cfc_archivia'); ?>">
            <?php echo $archPane ? FC_ICON_UNARCHIVE : FC_ICON_ARCHIVE; ?>
            <span><?php echo sec_h($archPane ? T('fc.cfc_ripristina') : T('fc.cfc_archivia')); ?></span>
        </button>
    </div>
    <?php endif; ?>

    <!-- RIGA PRINCIPALE (In primo piano) -->
    <div class="cat-row-main" style="padding-left:<?php echo (int)$pad; ?>px;" data-cat-link="<?php echo sec_h(fc_url('fc_view.php', ['category_id' => (int)$id])); ?>" onclick="fcCatRowNavigate(event, this)">
        <span class="cat-check" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
        </span>

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

        <div class="cat-name-wrap">
        <span class="cat-name"><?php echo sec_h($nameEsc); ?></span>
        <?php $catProj = $cat_project_links[$id] ?? null; if ($catProj): ?>
        <a class="cat-proj-tag" href="../projects/progetti.php?id=<?php echo (int)$catProj['id']; ?>" style="--cp-color:<?php echo sec_h($catProj['color']); ?>;" onclick="event.stopPropagation();">
            <svg viewBox="0 0 24 24" width="9" height="9" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z"/></svg>
            <?php if (!empty($catProj['parent_name'])): ?><span class="cat-proj-anc"><?php echo sec_h($catProj['parent_name']); ?></span><span class="cat-proj-sep">•</span><?php endif; ?>
            <span class="cat-proj-leaf"><?php echo sec_h($catProj['name']); ?></span>
        </a>
        <?php endif; ?>
        </div>

        <span class="cat-badge"><?php echo (int)$cat['image_count']; ?></span>
        <button type="button" class="cat-kebab" aria-label="<?php te('vocab.cat_altre_azioni'); ?>"
            data-id="<?php echo (int)$id; ?>"
            data-name="<?php echo sec_h($nameEsc); ?>"
            data-parent="<?php echo (int)$cat['parent_id']; ?>"
            data-parent-name="<?php echo sec_h($parentName); ?>"
            data-parent-color="<?php echo sec_h($parentColor); ?>"
            data-candelete="<?php echo (int)$can_delete; ?>"
            data-color="<?php echo sec_h($color); ?>"
            data-color-edited="<?php echo (int)($cat['color_edited'] ?? 0); ?>"
            data-archived="<?php echo (int)($cat['archived'] ?? 0); ?>"
            onclick="event.stopPropagation(); apriAzioni(this);">
            <svg viewBox="0 0 24 24" fill="currentColor">
                <circle cx="5" cy="12" r="1.7"/>
                <circle cx="12" cy="12" r="1.7"/>
                <circle cx="19" cy="12" r="1.7"/>
            </svg>
        </button>
    </div>
</div>
<?php
    if ($has_children) {
        foreach ($cats_by_parent[$id] as $childId) {
            renderCatRow($childId, $level + 1, $all_cats, $cats_by_parent, $archPane);
        }
        /* Il tastino "+" di coda: compare solo a ramo esploso, in fondo a
           destra sotto l'ultima figlia, e crea una categoria gia' agganciata
           a questa. Sta qui e non dentro la riga perche' deve trovarsi DOPO i
           discendenti, che nel documento sono fratelli e non figli. */
        if (!$archPane):
?>
<div class="cat-add-here" data-parent="<?php echo (int)$id; ?>" style="display:none; padding-left:<?php echo (int)($pad + 24); ?>px;">
    <button type="button" class="cat-add-btn" style="--row-color:<?php echo sec_h($color); ?>;"
            data-id="<?php echo (int)$id; ?>" data-name="<?php echo sec_h($nameEsc); ?>" data-color="<?php echo sec_h($color); ?>"
            onclick="aggiungiFiglioDaTastino(this)">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
        <span><?php te('fc.cfc_sotto_categoria'); ?></span>
    </button>
</div>
<?php
        endif;
    }
}
?>

<!DOCTYPE html>

<html lang="it">

<head>

<meta charset="UTF-8">

<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=0, viewport-fit=cover">

<title><?php te('fc.cfc_titolo'); ?></title>

<link rel="stylesheet" href="<?php echo PWA_BASE; ?>fc/fc_decks.css?v=<?php echo @filemtime(__DIR__ . '/fc_decks.css') ?: 1; ?>">

<?php /* Lo stesso disegnatore delle carte vere: i risultati della ricerca sul
         testo mostrano pezzi di documento, e devono uscire formattati come
         nella carta da cui vengono, non come il JSON che li contiene.
         Porta con se' anche le proprie regole di stile (le inietta da solo),
         quindi non serve ricopiare qui nessun CSS dei marchi. */ ?>
<?php /* fc_parser serve solo alle flashcard di studio: le parole sono testo semplice. */ ?>
<?php if (!fc_is_vocab()): ?>
<script src="<?php echo PWA_BASE; ?>fc/fc_parser.js?v=<?php echo @filemtime(__DIR__ . '/fc_parser.js') ?: 1; ?>"></script>
<?php endif; ?>
<?php echo fc_boot_json(); ?>

</head>

<body class="<?php echo fc_is_vocab() ? 'fc-vocab' : 'fc-study'; ?>">



<div class="app-header">

    <div class="hdr-top">
        <?php /* Indietro: alle lingue per un corso, alla pagina principale delle flashcard per lo studio. */ ?>
        <a href="<?php echo fc_is_vocab() ? 'fc_languages.php' : 'fc_home.php'; ?>" class="hdr-back" aria-label="<?php te('comune.indietro'); ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
        </a>
        <div class="hdr-titles">
            <?php if (fc_is_vocab()): $fcCorso = fc_corso(); ?>
            <div class="hdr-title hdr-title-lang">
                <img class="hdr-flag" src="<?php echo sec_h(fc_lingua_bandiera($fcCorso['learn_lang'])); ?>" alt="">
                <span><?php echo sec_h(fc_lingua_nome($fcCorso['learn_lang'], false)); ?></span>
            </div>
            <div class="hdr-sub"><?php te('fcv.conteggio', ['categorie' => (int)$catCount, 'carte' => (int)$totalImages]); ?></div>
            <?php else: ?>
            <div class="hdr-title"><?php te('vocab.cat_categorie'); ?></div>
            <div class="hdr-sub"><?php te('fc.cfc_conteggio', ['categorie' => (int)$catCount, 'carte' => (int)$totalImages]); ?></div>
            <?php endif; ?>
        </div>
        <?php /* Il tasto per giocare: porta DIRITTO alla scelta delle materie,
                 non allo storico - un tasto "play" che apre un elenco di
                 partite vecchie e' una promessa non mantenuta. Rotondo e
                 vistoso apposta, perche' qui in Categorie e' l'unico invito
                 a giocare — non sta dentro
                 nessuna categoria, quindi non compete con niente per
                 l'attenzione. L'anello che pulsa intorno e' un richiamo
                 gentile, non un allarme: si vede con la coda dell'occhio
                 senza dover smettere di guardare l'elenco. */ ?>
        <a href="<?php echo sec_h(fc_url('fc_history.php')); ?>" class="hdr-play-btn" aria-label="<?php te('fc.cg_gioca'); ?>" title="<?php te('fc.cg_gioca'); ?>">
            <span class="hdr-play-ring" aria-hidden="true"></span>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 4v16l13-8L7 4Z"/></svg>
        </a>
    </div>

    <div class="search-box">

        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>

        <input type="text" id="searchInput" placeholder="<?php te('vocab.cat_cerca'); ?>" oninput="avviaRicerca(this.value)" autocomplete="off">

        <button type="button" class="search-clear" id="searchClear" onclick="clearSearch()" aria-label="<?php te('vocab.cat_cancella_ricerca'); ?>">

            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>

        </button>

    </div>

    <?php /* I filtri restano fuori strada finche' non si cerca davvero: la
             barra in cima e' gia' densa, e a campo vuoto non filtrerebbero
             nulla. */ ?>
    <div class="search-filters" id="searchFilters">
        <button type="button" class="sf-chip on" data-filter="cat"><?php te('vocab.cat_categorie'); ?></button>
        <button type="button" class="sf-chip on" data-filter="sub"><?php te('fc.cfc_f_sottocategorie'); ?></button>
        <button type="button" class="sf-chip" data-filter="cards"><?php te('fc.cfc_f_testo_carte'); ?></button>
    </div>

</div>



<?php include __DIR__ . '/../partials/sidebar.html'; ?>



<div class="page">




    <!-- BARRA DEGLI STRUMENTI
         La scelta elenco/griglia sta prima di tutto, a sinistra: e' la
         decisione piu' grossa delle tre (cambia proprio come si legge la
         pagina), e le altre due valgono dentro la vista che ha scelto lei. -->
    <div class="cat-toolbar">
        <button type="button" class="tool-btn tool-btn-view" id="btnView" onclick="cambiaVista()" aria-label="<?php te('fc.cfc_cambia_vista'); ?>" title="<?php te('fc.cfc_elenco_griglia'); ?>">
            <span class="tb-list"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 6h13M8 12h13M8 18h13M3.5 6h.01M3.5 12h.01M3.5 18h.01"/></svg></span>
            <span class="tb-grid"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7.5" height="7.5" rx="2"/><rect x="13.5" y="3" width="7.5" height="7.5" rx="2"/><rect x="3" y="13.5" width="7.5" height="7.5" rx="2"/><rect x="13.5" y="13.5" width="7.5" height="7.5" rx="2"/></svg></span>
        </button>
        <div class="seg" id="segScope" role="tablist">
            <button type="button" class="seg-btn on" data-scope="active" role="tab">
                <?php te('fc.cfc_attive'); ?> <span class="seg-n"><?php echo (int)$nAttive; ?></span>
            </button>
            <button type="button" class="seg-btn" data-scope="archived" role="tab">
                <?php te('fc.cfc_archiviate'); ?> <span class="seg-n"><?php echo (int)$nArchiviate; ?></span>
            </button>
        </div>
        <div class="tool-btns">
            <button type="button" class="tool-btn" id="btnCollapse" onclick="comprimiTutto()" aria-label="<?php te('fc.cfc_comprimi_tutto'); ?>" title="<?php te('fc.cfc_comprimi_tutto'); ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m7 10 5-5 5 5M7 14l5 5 5-5"/></svg>
            </button>
            <button type="button" class="tool-btn" id="btnSelect" onclick="toggleModalitaSelezione()" aria-label="<?php te('fc.cfc_seleziona'); ?>" title="<?php te('fc.cfc_selezione_multipla'); ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
            </button>
        </div>
    </div>


    <!-- RISULTATI DELLA RICERCA (prende il posto degli elenchi mentre si cerca) -->
    <div class="search-results" id="searchResults" style="display:none;"></div>


    <!-- VISTA A GRIGLIA (disegnata dal browser: si sfoglia senza ricaricare) -->
    <div class="grid-pane" id="gridPane" style="display:none;">
        <div class="grid-crumbs" id="gridCrumbs"></div>
        <div class="fc-subcats-grid" id="gridBody"></div>
        <div class="grid-empty" id="gridEmpty" style="display:none;"><?php te('fc.cfc_griglia_vuota'); ?></div>
    </div>


    <!-- VISTA A ELENCO: ATTIVE -->
    <div class="list-pane" id="paneActive">

        <?php if (empty($rootIds)): ?>

        <div class="empty-state">

            <div class="empty-icon">

                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z"/></svg>

            </div>

            <div class="empty-title"><?php te('vocab.cat_nessuna'); ?></div>

            <div class="empty-sub"><?php te('fc.cfc_nessuna_txt'); ?></div>

            <button type="button" class="btn-create-first" onclick="apriModalSalva({id:0,name:'',parentId:0,parentName:'',parentColor:'#A0A0A0',color:'#A0A0A0',colorEdited:false})">

                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>

                <?php te('vocab.cat_crea'); ?>

            </button>

        </div>

        <?php else: ?>

            <?php foreach ($rootIds as $i => $rid):

                $rootCat = $all_categories[$rid];

                $rootColor = (!empty($rootCat['color']) && preg_match('/^#[0-9A-Fa-f]{6}$/', $rootCat['color'])) ? $rootCat['color'] : '#007AFF';

                $delay = min($i * 45, 300);

            ?>

            <div class="materia-card" style="--accent:<?php echo sec_h($rootColor); ?>; animation-delay:<?php echo sec_h($delay); ?>ms;">

                <?php renderCatRow($rid, 0, $all_categories, $active_by_parent, false); ?>

            </div>

            <?php endforeach; ?>

        <?php endif; ?>

    </div>


    <!-- VISTA A ELENCO: ARCHIVIATE -->
    <div class="list-pane" id="paneArchived" style="display:none;">

        <?php if (empty($archivedRootIds)): ?>

        <div class="empty-state">

            <div class="empty-icon archive">

                <?php echo FC_ICON_ARCHIVE; ?>

            </div>

            <div class="empty-title"><?php te('fc.cfc_archivio_vuoto'); ?></div>

            <div class="empty-sub"><?php te('fc.cfc_archivio_vuoto_txt'); ?></div>

        </div>

        <?php else: ?>

            <?php foreach ($archivedRootIds as $i => $rid):

                $rootCat = $all_categories[$rid];

                $rootColor = (!empty($rootCat['color']) && preg_match('/^#[0-9A-Fa-f]{6}$/', $rootCat['color'])) ? $rootCat['color'] : '#007AFF';

                $delay = min($i * 45, 300);

            ?>

            <div class="materia-card is-archived" style="--accent:<?php echo sec_h($rootColor); ?>; animation-delay:<?php echo sec_h($delay); ?>ms;">

                <?php renderCatRow($rid, 0, $all_categories, $archived_by_parent, true); ?>

            </div>

            <?php endforeach; ?>

        <?php endif; ?>

    </div>


    <a href="<?php echo sec_h(fc_url('fc_view.php', ['category_id' => 0])); ?>" class="hero-card hero-fondo">

        <span class="hero-icon">

            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M3.6 9h16.8M3.6 15h16.8M12 3a14.5 14.5 0 0 1 0 18M12 3a14.5 14.5 0 0 0 0 18"/></svg>

        </span>

        <span class="hero-label"><?php te(fc_is_vocab() ? 'fcv.tutte_parole' : 'fc.cs_tutte_materie'); ?></span>

        <span class="hero-num"><?php echo (int)$totalImages; ?></span>

        <span class="hero-chev">

            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="m9 6 6 6-6 6"/></svg>

        </span>

    </a>


</div>



<button type="button" class="fab" onclick="apriNuovaDaFab()" aria-label="<?php te('vocab.cat_aggiungi'); ?>">

    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>

</button>


<!-- BARRA DELLE AZIONI DI GRUPPO (modalità selezione) -->
<div class="selbar" id="selBar">
    <div class="selbar-head">
        <span class="selbar-n" id="selCount"><?php te('fc.cfc_selezionate', ['n' => 0]); ?></span>
        <button type="button" class="selbar-close" onclick="toggleModalitaSelezione(false)"><?php te('fc.cfc_fine'); ?></button>
    </div>
    <div class="selbar-acts">
        <button type="button" class="selact" onclick="bulkSposta()">
            <?php echo FC_ICON_LINK; ?><span><?php te('vocab.cat_sposta_attr'); ?></span>
        </button>
        <button type="button" class="selact" onclick="bulkColore()">
            <span class="selact-wheel"></span><span><?php te('vocab.cat_colore'); ?></span>
        </button>
        <button type="button" class="selact" id="selActArchive" onclick="bulkArchivia(1)">
            <?php echo FC_ICON_ARCHIVE; ?><span><?php te('fc.cfc_archivia'); ?></span>
        </button>
        <button type="button" class="selact" id="selActUnarchive" onclick="bulkArchivia(0)" style="display:none;">
            <?php echo FC_ICON_UNARCHIVE; ?><span><?php te('fc.cfc_ripristina'); ?></span>
        </button>
        <button type="button" class="selact danger" onclick="bulkElimina()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0-1 14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2L4 6"/></svg><span><?php te('comune.elimina'); ?></span>
        </button>
    </div>
</div>


<!-- SHEET: Nuova / Modifica categoria -->

<div id="sheetForm" class="modal-overlay">

    <div class="sheet-panel">

        <h3 class="sheet-title" id="modalSalvaTitolo"><?php te('vocab.cat_nuova'); ?></h3>

        <form id="formSalva" method="POST">

            <input type="hidden" name="csrf_token" value="<?php echo sec_h(sec_csrf_token()); ?>"><?php echo fc_campo_tipo(); ?>

            <input type="hidden" name="action" value="save">

            <input type="hidden" name="category_id" id="form_cat_id" value="0">

            <input type="hidden" name="color_mode" id="form_color_mode" value="auto">

            <input type="hidden" name="category_color" id="form_color_value" value="#A0A0A0">



            <div class="field">

                <label for="form_cat_name"><?php te('vocab.cat_nome'); ?></label>

                <input type="text" name="category_name" id="form_cat_name" required placeholder="<?php te('fc.cfc_es_nome'); ?>">

            </div>



            <div class="field">

                <label><?php te('vocab.cat_padre'); ?></label>

                <?php

                    $cs_id = 'formParent';

                    $cs_hidden_name = 'parent_id';

                    $cs_placeholder = T('vocab.cat_nessuna_padre');

                    $cs_title = T('fc.cfc_padre_titolo');

                    $cs_show_all_option = true;

                    $cs_all_option_label = T('vocab.cat_nessuna_padre');

                    $cs_allow_create = false;

                    include __DIR__ . '/fc_deck_selector.php';

                ?>

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


            <?php /* Le due scorciatoie che valgono solo per una categoria che
                     esiste gia': archiviarla e appenderle sotto una figlia
                     nuova. Sulle foglie il tastino "+" di coda non c'e' (non
                     avendo figli il ramo non si esplode), quindi questa e' la
                     via per dargliene una anche da telefono. */ ?>
            <div class="field form-extra" id="formExtra" style="display:none;">
                <label><?php te('fc.cfc_altro'); ?></label>
                <button type="button" class="row-action" id="formArchiveBtn" onclick="archiviaDalForm()">
                    <span class="ra-ic" id="formArchiveIc"><?php echo FC_ICON_ARCHIVE; ?></span>
                    <span id="formArchiveTxt"><?php te('fc.cfc_archivia_questa'); ?></span>
                </button>
                <button type="button" class="row-action" onclick="aggiungiFiglioDalForm()">
                    <span class="ra-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z"/><path d="M12 11v5M9.5 13.5h5"/></svg></span>
                    <span><?php te('fc.cfc_aggiungi_sottocategoria'); ?></span>
                </button>
            </div>


            <div class="sheet-actions">

                <button type="button" class="btn btn-cancel" onclick="chiudiModals()"><?php te('comune.annulla'); ?></button>

                <button type="submit" class="btn btn-primary"><?php te('comune.salva'); ?></button>

            </div>

        </form>

    </div>

</div>



<!-- SHEET: Sposta categoria -->

<form id="formSposta" method="POST" style="display:none;">
    <input type="hidden" name="csrf_token" value="<?php echo sec_h(sec_csrf_token()); ?>"><?php echo fc_campo_tipo(); ?>
    <input type="hidden" name="action" value="move">
    <input type="hidden" name="move_category_id" id="move_cat_id">
    <input type="hidden" name="target_parent_id" id="target_parent_id">
</form>

<!-- Archiviazione (singola) -->
<form id="formArchive" method="POST" style="display:none;">
    <input type="hidden" name="csrf_token" value="<?php echo sec_h(sec_csrf_token()); ?>"><?php echo fc_campo_tipo(); ?>
    <input type="hidden" name="action" value="archive">
    <input type="hidden" name="category_id" id="archive_cat_id">
    <input type="hidden" name="archived" id="archive_value" value="1">
</form>

<!-- Azioni di gruppo -->
<form id="formBulk" method="POST" style="display:none;">
    <input type="hidden" name="csrf_token" value="<?php echo sec_h(sec_csrf_token()); ?>"><?php echo fc_campo_tipo(); ?>
    <input type="hidden" name="action" value="bulk">
    <input type="hidden" name="bulk_op" id="bulk_op">
    <input type="hidden" name="bulk_ids" id="bulk_ids">
    <input type="hidden" name="target_parent_id" id="bulk_target_parent_id" value="0">
    <input type="hidden" name="color_mode" id="bulk_color_mode" value="manual">
    <input type="hidden" name="category_color" id="bulk_color_value" value="#007AFF">
</form>

<button type="button" id="btnOpenMoveSelector" data-cs-open="cs-modal-moveCategory" style="display:none;"></button>

<?php
    $cs_id = 'moveCategory';
    $cs_title = T('fc.cfc_sposta_titolo');
    $cs_show_all_option = true;
    $cs_all_option_label = T('vocab.cat_in_cima');
    $cs_allow_create = false; // DISABILITA IL TASTO PER CREARE UNA NUOVA MATERIA
    $cs_render_trigger = false; // Nascondiamo il bottone standard, lo apriamo via JS
    include __DIR__ . '/fc_deck_selector.php';
?>



<!-- SHEET: Azioni categoria (kebab) -->

<div id="sheetActions" class="modal-overlay">

    <div class="sheet-panel">

        <h3 class="sheet-title" id="azioniTitolo"><?php te('vocab.cat_categoria'); ?></h3>

        <div class="action-list">

            <button type="button" class="action-item" id="azioneModifica">

                <span class="a-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg></span>

                <?php te('vocab.cat_modifica_attr'); ?>

            </button>

            <button type="button" class="action-item" id="azioneAggiungiFiglia">

                <span class="a-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z"/><path d="M12 11v5M9.5 13.5h5"/></svg></span>

                <?php te('vocab.cat_aggiungi_figlia'); ?>

            </button>

            <button type="button" class="action-item" id="azioneColore">

                <span class="a-ic color-wheel"></span>

                <?php te('vocab.cat_colore'); ?>

            </button>

            <button type="button" class="action-item" id="azioneSposta">

                <span class="a-ic"><?php echo FC_ICON_LINK; ?></span>

                <?php te('vocab.cat_sposta_attr'); ?>

            </button>

            <button type="button" class="action-item" id="azioneArchivia">

                <span class="a-ic" id="azioneArchiviaIc"><?php echo FC_ICON_ARCHIVE; ?></span>

                <span id="azioneArchiviaTxt"><?php te('fc.cfc_archivia'); ?></span>

            </button>

            <button type="button" class="action-item danger" id="azioneElimina">

                <span class="a-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0-1 14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2L4 6"/></svg></span>

                <?php te('comune.elimina'); ?>

            </button>

        </div>

        <button type="button" class="btn btn-cancel" style="width:100%;" onclick="chiudiModals()"><?php te('comune.chiudi'); ?></button>

    </div>

</div>



<!-- SHEET: Colore cartella -->

<div id="sheetColor" class="modal-overlay">

    <div class="sheet-panel">

        <h3 class="sheet-title"><?php te('fc.cfc_colore_cartella'); ?></h3>



        <div class="color-preview-row">

            <span class="color-preview-icon" id="colorPreviewIcon">

                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z"/></svg>

            </span>

            <span class="color-preview-name" id="colorPreviewName"><?php te('vocab.cat_categoria'); ?></span>

        </div>



        <div class="color-grid" id="colorGrid">

            <?php /* Prima opzione sempre il colore ereditato dal padre: senza
                     questa il popup poteva solo IMPORRE un colore, mai
                     toglierlo per tornare a quello del padre. */ ?>

            <button type="button" class="color-swatch color-swatch-auto" id="colorSwatchAuto" data-auto="1" onclick="selectColorAuto()" title="<?php te('vocab.cat_auto_eredita'); ?>">

                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 9-9"/><path d="M3 4v8h8"/></svg>

            </button>

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

            <input type="hidden" name="csrf_token" value="<?php echo sec_h(sec_csrf_token()); ?>"><?php echo fc_campo_tipo(); ?>

            <input type="hidden" name="action" value="color">

            <input type="hidden" name="category_id" id="color_cat_id">

            <input type="hidden" name="color_mode" id="color_cat_mode" value="manual">

            <input type="hidden" name="category_color" id="color_cat_value">

        </form>



        <div class="sheet-actions">

            <button type="button" class="btn btn-cancel" onclick="annullaColore()"><?php te('comune.annulla'); ?></button>

            <button type="button" class="btn btn-primary" onclick="salvaColore()"><?php te('comune.salva'); ?></button>

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

            <button type="button" class="btn btn-danger" id="btnConfermaElimina" onclick="confermaEliminaSubmit()"><?php te('comune.elimina'); ?></button>

        </div>

    </div>

</div>



<form id="formDelete" method="POST" style="display:none;">

    <input type="hidden" name="csrf_token" value="<?php echo sec_h(sec_csrf_token()); ?>"><?php echo fc_campo_tipo(); ?>

    <input type="hidden" name="action" value="delete">

    <input type="hidden" name="delete_category_id" id="delete_cat_id">

</form>



<?php echo nisama_i18n_json(['comune', 'fc', 'vocab']); ?>
<script>
var CAT_DATA = <?php echo json_encode($catJs, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
</script>
<script>

/* ======================================================================
   Il nome della materia non entra piu' dentro onclick.
   Prima ci finiva passato da addslashes(), che protegge dagli apici ma non dal
   carattere "<": una materia il cui nome contenesse un tag di chiusura di
   script avrebbe chiuso il blocco, e il resto della pagina sarebbe stato
   interpretato come HTML. Ora il dato sta negli attributi data- della riga,
   scritti con sec_h(), e da li' il JavaScript lo legge come testo.
   ====================================================================== */
function rigaFcDi(el) { return el.closest('.cat-row'); }

function apriModalSalvaDaRiga(btn) {
    var r = rigaFcDi(btn);
    apriModalSalva({
        id:          parseInt(r.dataset.id, 10),
        name:        r.dataset.name,
        parentId:    parseInt(r.dataset.parent, 10),
        parentName:  r.dataset.parentName || '',
        parentColor: r.dataset.parentColor || '#A0A0A0',
        color:       r.dataset.color,
        colorEdited: r.dataset.colorEdited === '1',
        archived:    r.dataset.archived === '1'
    });
}
function avviaSpostaDaRiga(btn) {
    var r = rigaFcDi(btn);
    avviaSposta(parseInt(r.dataset.id, 10), r.dataset.name, parseInt(r.dataset.parent, 10));
}
function apriConfermaEliminaDaRiga(btn) {
    var r = rigaFcDi(btn);
    apriConfermaElimina(parseInt(r.dataset.id, 10), r.dataset.name);
}
function aggiungiFiglioDaTastino(btn) {
    apriAggiungiFiglio(parseInt(btn.dataset.id, 10), btn.dataset.name, btn.dataset.color);
}
/* La categoria toccata diventa il padre: si riusa lo stesso foglio di
   creazione, solo con il padre gia' precompilato e il nome pronto da
   scrivere (il fuoco arriva dal focus() gia' presente in apriModalSalva). */
function apriAggiungiFiglio(parentId, parentName, parentColor) {
    apriModalSalva({
        id: 0, name: '', parentId: parentId,
        parentName: parentName || '', parentColor: parentColor || '#A0A0A0',
        color: parentColor || '#A0A0A0', colorEdited: false
    });
}

    if (window.matchMedia("(pointer:coarse)").matches) {
        document.body.classList.add("touch-device");
    }


    /* ======================================================================
       STATO CHE SOPRAVVIVE ALLA CHIUSURA DELLA PAGINA
       Vista, ambito, filtri di ricerca e rami aperti stanno in localStorage e
       non in sessionStorage: riaprendo le categorie domani si deve ritrovare
       l'albero come lo si era lasciato, non richiuso da capo.
       ====================================================================== */
    var LS_VISTA   = 'nisama_cat_vista';
    var LS_AMBITO  = 'nisama_cat_ambito';
    var LS_APERTE  = 'nisama_cat_aperte';
    var LS_FILTRI  = 'nisama_cat_filtri';

    function lsGet(k, def) { try { var v = localStorage.getItem(k); return v === null ? def : v; } catch(e){ return def; } }
    function lsSet(k, v)   { try { localStorage.setItem(k, v); } catch(e){} }

    var vistaCorrente  = (lsGet(LS_VISTA, 'list') === 'grid') ? 'grid' : 'list';
    var ambitoCorrente = (lsGet(LS_AMBITO, 'active') === 'archived') ? 'archived' : 'active';
    var filtriRicerca  = { cat: true, sub: true, cards: false };
    (function(){
        try {
            var f = JSON.parse(lsGet(LS_FILTRI, '') || 'null');
            if (f) { filtriRicerca.cat = !!f.cat; filtriRicerca.sub = !!f.sub; filtriRicerca.cards = !!f.cards; }
        } catch(e){}
    })();


    /* ---------- Blocco dello scorrimento sotto ai popup ----------
       position:fixed sul corpo pagina e non overflow:hidden: su iOS il solo
       overflow non ferma il rimbalzo, e la pagina continua a scorrere sotto
       al foglio. Si tiene l'altezza dello scorrimento per rimetterla dov'era
       alla chiusura, altrimenti chiudendo il popup si torna in cima.
       Il contatore serve perche' i popup si annidano (dal foglio di modifica
       si apre il selettore di categoria): la pagina si sblocca solo quando
       l'ultimo si e' chiuso. */
    if (!window.nsScrollLock) {
        window.__nsLockN = 0;
        window.__nsLockY = 0;
        window.nsScrollLock = function(on) {
            var b = document.body;
            if (on) {
                window.__nsLockN++;
                if (window.__nsLockN > 1) return;
                window.__nsLockY = window.pageYOffset || document.documentElement.scrollTop || 0;
                b.style.position = 'fixed';
                b.style.top = (-window.__nsLockY) + 'px';
                b.style.left = '0';
                b.style.right = '0';
                b.style.width = '100%';
                b.classList.add('ns-locked');
            } else {
                window.__nsLockN = Math.max(0, window.__nsLockN - 1);
                if (window.__nsLockN > 0) return;
                b.style.position = '';
                b.style.top = '';
                b.style.left = '';
                b.style.right = '';
                b.style.width = '';
                b.classList.remove('ns-locked');
                window.scrollTo(0, window.__nsLockY);
            }
        };
    }


    /* ---------- Apertura / chiusura sheet ---------- */

    function openSheet(el){
        el.style.display = 'flex';
        el.classList.remove('closing');
        window.nsScrollLock(true);
    }

    function closeSheet(el){
        el.classList.add('closing');
        window.nsScrollLock(false);
        setTimeout(function(){
            el.style.display = 'none';
            el.classList.remove('closing');
        }, 240);
    }

    /* Toccare fuori dal foglio lo chiude, ma solo a nome ancora vuoto.
       La condizione non e' un dettaglio: chiudendo si perde quello che si e'
       scritto, e un tocco di striscio sul bordo mentre si sceglie il padre o
       il colore e' facilissimo. A campo vuoto non c'e' niente da perdere e il
       gesto e' quello che ci si aspetta ("ho aperto per sbaglio"); appena si
       scrive qualcosa il foglio si irrigidisce e si esce solo da Annulla.
       e.target === overlay e non un closest: il selettore delle categorie vive
       DENTRO questo foglio, e i suoi click salgono fin qui. */
    document.getElementById('sheetForm').addEventListener('click', function(e){
        if (e.target !== this) return;
        var nome = document.getElementById('form_cat_name');
        if (nome && nome.value.trim() !== '') return;
        chiudiModals();
    });

    function chiudiModals(){
        document.querySelectorAll('.modal-overlay').forEach(function(ov){
            if (ov.style.display === 'flex') closeSheet(ov);
        });
    }



    /* ---------- Espandi / comprimi albero categorie ---------- */

    function toggleBranch(id){
        var row = document.querySelector('.cat-row[data-id="' + id + '"]');
        if (!row) return;
        var arrow = row.querySelector('.cat-arrow');
        if (!arrow) return;
        var isOpen = arrow.classList.contains('open');
        if (isOpen) {
            arrow.classList.remove('open');
            comprimiFigli(id);
        } else {
            arrow.classList.add('open');
            espandiFigli(id);
        }
        salvaRamiAperti();
    }

    function espandiFigli(parentId){
        var children = document.querySelectorAll('.cat-row[data-parent="' + parentId + '"]');
        children.forEach(function(row){
            row.style.display = 'flex';
            row.style.animation = 'none';
            void row.offsetWidth;
            row.style.animation = '';
            row.classList.add('row-anim');
            var subId = row.getAttribute('data-id');
            var subArrow = row.querySelector('.cat-arrow');
            if (subArrow && subArrow.classList.contains('open')) espandiFigli(subId);
        });
        /* Il tastino "+" di coda vive e muore col ramo: appare quando il ramo
           si apre e sparisce quando si richiude. */
        var add = document.querySelector('.cat-add-here[data-parent="' + parentId + '"]');
        if (add) add.style.display = 'flex';
    }

    function comprimiFigli(parentId){
        var children = document.querySelectorAll('.cat-row[data-parent="' + parentId + '"]');
        children.forEach(function(row){
            row.style.display = 'none';
            var subId = row.getAttribute('data-id');
            comprimiFigli(subId);
        });
        var add = document.querySelector('.cat-add-here[data-parent="' + parentId + '"]');
        if (add) add.style.display = 'none';
    }

    function salvaRamiAperti(){
        var ids = [];
        document.querySelectorAll('.cat-arrow.open').forEach(function(a){
            var r = a.closest('.cat-row');
            if (r) ids.push(r.dataset.id);
        });
        lsSet(LS_APERTE, JSON.stringify(ids));
    }

    /* I rami si riaprono dal piu' vicino alla radice al piu' profondo: aprire
       prima un figlio non servirebbe a niente finche' il padre e' chiuso,
       perche' espandiFigli lavora sui discendenti diretti di una riga che
       dev'essere gia' a schermo. */
    function ripristinaRamiAperti(){
        var ids = [];
        try { ids = JSON.parse(lsGet(LS_APERTE, '[]')) || []; } catch(e){ ids = []; }
        if (!ids.length) return;
        var righe = [];
        ids.forEach(function(id){
            var r = document.querySelector('.cat-row[data-id="' + id + '"]');
            if (r) righe.push(r);
        });
        righe.sort(function(a, b){ return parseInt(a.dataset.level, 10) - parseInt(b.dataset.level, 10); });
        righe.forEach(function(r){
            var arrow = r.querySelector('.cat-arrow');
            if (!arrow) return;
            arrow.classList.add('open');
            espandiFigli(r.dataset.id);
        });
    }

    function comprimiTutto(){
        document.querySelectorAll('.cat-arrow.open').forEach(function(a){ a.classList.remove('open'); });
        document.querySelectorAll('.cat-row').forEach(function(r){
            r.style.display = (r.dataset.level === '0') ? 'flex' : 'none';
        });
        document.querySelectorAll('.cat-add-here').forEach(function(a){ a.style.display = 'none'; });
        lsSet(LS_APERTE, '[]');
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }


    /* ---------- Ambito: attive / archiviate ---------- */

    function applicaAmbito(){
        document.querySelectorAll('#segScope .seg-btn').forEach(function(b){
            b.classList.toggle('on', b.dataset.scope === ambitoCorrente);
        });
        var att = document.getElementById('paneActive');
        var arc = document.getElementById('paneArchived');
        var cercando = (document.getElementById('searchInput').value || '').trim().length >= 2;
        var grid = (vistaCorrente === 'grid');
        att.style.display = (!cercando && !grid && ambitoCorrente === 'active')   ? '' : 'none';
        arc.style.display = (!cercando && !grid && ambitoCorrente === 'archived') ? '' : 'none';
        /* In archivio le azioni di gruppo devono proporre il ripristino, non
           l'archiviazione: e' l'unica delle due che li' ha un effetto. */
        document.getElementById('selActArchive').style.display   = (ambitoCorrente === 'active')   ? '' : 'none';
        document.getElementById('selActUnarchive').style.display = (ambitoCorrente === 'archived') ? '' : 'none';
    }

    document.getElementById('segScope').addEventListener('click', function(e){
        var b = e.target.closest('.seg-btn');
        if (!b) return;
        ambitoCorrente = b.dataset.scope;
        lsSet(LS_AMBITO, ambitoCorrente);
        svuotaSelezione();
        gridPercorso = [];
        applicaVista();
        if ((document.getElementById('searchInput').value || '').trim().length >= 2) avviaRicerca(document.getElementById('searchInput').value);
    });


    /* ---------- Vista: elenco / griglia ---------- */

    function cambiaVista(){
        vistaCorrente = (vistaCorrente === 'list') ? 'grid' : 'list';
        lsSet(LS_VISTA, vistaCorrente);
        gridPercorso = [];
        applicaVista();
    }

    function applicaVista(){
        document.body.classList.toggle('vista-griglia', vistaCorrente === 'grid');
        document.getElementById('btnCollapse').style.display = (vistaCorrente === 'grid') ? 'none' : '';
        var cercando = (document.getElementById('searchInput').value || '').trim().length >= 2;
        document.getElementById('gridPane').style.display = (!cercando && vistaCorrente === 'grid') ? '' : 'none';
        applicaAmbito();
        if (vistaCorrente === 'grid') disegnaGriglia();
    }


    /* ======================================================================
       VISTA A GRIGLIA
       Stessa estetica dei riquadri delle sottocartelle di visualizzazione_fc:
       riquadro con la fascia del colore, cartellina, nome e, sotto, quante
       carte e quante sottocartelle ci sono in QUELLA cartella.
       Qui pero' si sfoglia: toccando un riquadro con dentro altre cartelle si
       scende di un livello senza ricaricare la pagina, e la freccia in alto a
       destra del riquadro apre invece la categoria vera e propria.
       ====================================================================== */
    var CAT_BY_ID = {}, CAT_KIDS = {};
    CAT_DATA.forEach(function(c){
        CAT_BY_ID[c.id] = c;
        if (!CAT_KIDS[c.p]) CAT_KIDS[c.p] = [];
        CAT_KIDS[c.p].push(c);
    });
    var gridPercorso = [];

    function inRamoArchiviato(id){
        var g = 0;
        while (id > 0 && g < 60) {
            g++;
            var c = CAT_BY_ID[id];
            if (!c) return false;
            if (c.arc === 1) return true;
            id = c.p;
        }
        return false;
    }
    function radiciArchiviate(){
        return CAT_DATA.filter(function(c){
            return c.arc === 1 && !(c.p > 0 && inRamoArchiviato(c.p));
        });
    }
    function figliVisibili(parentId){
        if (parentId === 0) {
            if (ambitoCorrente === 'archived') return radiciArchiviate();
            return (CAT_KIDS[0] || []).filter(function(c){ return !inRamoArchiviato(c.id); });
        }
        var kids = CAT_KIDS[parentId] || [];
        if (ambitoCorrente === 'archived') return kids.slice();
        return kids.filter(function(c){ return !inRamoArchiviato(c.id); });
    }

    function esc(s){
        return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){
            return { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c];
        });
    }

    var SVG_CARTELLA = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z"/></svg>';
    var SVG_CARTE    = '<svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="7" width="13" height="10" rx="2.2"/><path d="M7.5 7V5a2 2 0 0 1 2-2h7a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2h-2"/></svg>';
    var SVG_SOTTOC   = '<svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h3.5l1.5 2H19a2 2 0 0 1 2 2v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z"/></svg>';
    var SVG_APRI     = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="m9 6 6 6-6 6"/></svg>';

    function disegnaGriglia(){
        var corrente = gridPercorso.length ? gridPercorso[gridPercorso.length - 1] : 0;
        var figli = figliVisibili(corrente);

        // briciole di pane
        var crumbs = ['<button type="button" class="crumb" data-i="-1">' +
                      esc(ambitoCorrente === 'archived' ? T('fc.cfc_archivio') : T('fc.cfc_tutte')) + '</button>'];
        gridPercorso.forEach(function(id, i){
            var c = CAT_BY_ID[id];
            if (!c) return;
            crumbs.push('<span class="crumb-sep">&rsaquo;</span><button type="button" class="crumb" data-i="' + i + '">' + esc(c.n) + '</button>');
        });
        document.getElementById('gridCrumbs').innerHTML = crumbs.join('');

        var html = figli.map(function(c){
            var nSub = figliVisibili(c.id).length;
            var sel = selezione[c.id] ? ' is-sel' : '';
            return '<div class="fc-subcat-card' + sel + '" data-id="' + c.id + '" data-subs="' + nSub + '" style="--sc-color:' + esc(c.c) + ';">' +
                     '<span class="fc-subcat-dot">' + SVG_CARTELLA + '</span>' +
                     '<a class="fc-subcat-go" href="' + fcUrl('fc_view.php?category_id=' + c.id) + '" aria-label="' + esc(T('fc.cfc_apri')) + '">' + SVG_APRI + '</a>' +
                     '<div class="fc-subcat-name">' + esc(c.n) + '</div>' +
                     '<div class="fc-subcat-meta">' +
                       '<span>' + SVG_CARTE + c.cards + '</span>' +
                       (nSub > 0 ? '<span>' + SVG_SOTTOC + nSub + '</span>' : '') +
                     '</div>' +
                     '<span class="fc-subcat-check"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg></span>' +
                   '</div>';
        }).join('');

        document.getElementById('gridBody').innerHTML = html;
        document.getElementById('gridEmpty').style.display = figli.length ? 'none' : '';
    }

    document.getElementById('gridCrumbs').addEventListener('click', function(e){
        var b = e.target.closest('.crumb');
        if (!b) return;
        var i = parseInt(b.dataset.i, 10);
        gridPercorso = (i < 0) ? [] : gridPercorso.slice(0, i + 1);
        disegnaGriglia();
    });

    document.getElementById('gridBody').addEventListener('click', function(e){
        var card = e.target.closest('.fc-subcat-card');
        if (!card) return;
        var id = parseInt(card.dataset.id, 10);

        if (modalitaSelezione) { e.preventDefault(); toggleSelezione(id); disegnaGriglia(); return; }

        /* La freccia in alto a destra apre sempre la categoria; il resto del
           riquadro scende dentro se c'e' qualcosa sotto, altrimenti apre
           anche lui (una cartella senza sottocartelle non ha un "dentro" da
           mostrare qui). */
        if (e.target.closest('.fc-subcat-go')) return;
        e.preventDefault();
        if (parseInt(card.dataset.subs, 10) > 0) {
            gridPercorso.push(id);
            disegnaGriglia();
            window.scrollTo({ top: 0, behavior: 'smooth' });
        } else {
            window.location.href = fcUrl('fc_view.php?category_id=' + id);
        }
    });


    /* ======================================================================
       RICERCA CON FILTRI
       Il testo delle carte non sta nel documento, quindi la ricerca non puo'
       piu' essere un semplice nascondi-righe come prima: si chiede al server,
       una volta ogni 260 ms di silenzio, e si disegna un elenco di risultati
       che prende il posto degli alberi finche' il campo non si svuota.
       ====================================================================== */
    var timerRicerca = null;
    var ultimaRicerca = 0;

    document.getElementById('searchFilters').addEventListener('click', function(e){
        var chip = e.target.closest('.sf-chip');
        if (!chip) return;
        var k = chip.dataset.filter;
        filtriRicerca[k] = !filtriRicerca[k];
        /* Spegnere tutto lascerebbe un campo di ricerca che non cerca niente:
           l'ultimo acceso non si spegne. */
        if (!filtriRicerca.cat && !filtriRicerca.sub && !filtriRicerca.cards) filtriRicerca[k] = true;
        aggiornaChipFiltri();
        lsSet(LS_FILTRI, JSON.stringify(filtriRicerca));
        avviaRicerca(document.getElementById('searchInput').value);
    });

    function aggiornaChipFiltri(){
        document.querySelectorAll('#searchFilters .sf-chip').forEach(function(c){
            c.classList.toggle('on', !!filtriRicerca[c.dataset.filter]);
        });
    }

    function avviaRicerca(raw){
        var q = (raw || '').trim();
        document.getElementById('searchClear').style.display = q ? 'flex' : 'none';
        document.body.classList.toggle('sta-cercando', q.length >= 2);

        if (q.length < 2) {
            clearTimeout(timerRicerca);
            document.getElementById('searchResults').style.display = 'none';
            document.getElementById('searchResults').innerHTML = '';
            applicaVista();
            return;
        }

        document.getElementById('paneActive').style.display = 'none';
        document.getElementById('paneArchived').style.display = 'none';
        document.getElementById('gridPane').style.display = 'none';
        var box = document.getElementById('searchResults');
        box.style.display = '';

        clearTimeout(timerRicerca);
        timerRicerca = setTimeout(function(){
            var mio = ++ultimaRicerca;
            var url = fcUrl('fc_decks.php?ajax=search')
                    + '&q=' + encodeURIComponent(q)
                    + '&scope=' + ambitoCorrente
                    + '&f_cat=' + (filtriRicerca.cat ? 1 : 0)
                    + '&f_sub=' + (filtriRicerca.sub ? 1 : 0)
                    + '&f_cards=' + (filtriRicerca.cards ? 1 : 0);
            /* no-store: la ricerca e' una GET su un indirizzo che cambia poco,
               e il browser ha gia' servito copie vecchie in altre pagine del
               sito (era il bug della priorita' dei to buy). */
            fetch(url, { cache: 'no-store' })
                .then(function(r){ return r.json(); })
                .then(function(d){
                    if (mio !== ultimaRicerca) return;   // e' arrivata prima una risposta piu' recente
                    disegnaRisultati(d, q);
                })
                .catch(function(){
                    if (mio !== ultimaRicerca) return;
                    box.innerHTML = '<div class="sr-empty">' + esc(T('fc.cfc_ricerca_fallita')) + '</div>';
                });
        }, 260);
    }

    function evidenzia(testo, q){
        var t = esc(testo);
        if (!q) return t;
        var qq = q.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        try { return t.replace(new RegExp('(' + qq + ')', 'ig'), '<mark>$1</mark>'); }
        catch(e){ return t; }
    }

    function percorsoHtml(path){
        if (!path || !path.length) return '<span class="sr-path-root">' + esc(T('fc.cfc_in_cima')) + '</span>';
        return path.map(function(p){ return '<span>' + esc(p) + '</span>'; }).join('<i>&rsaquo;</i>');
    }

    function disegnaRisultati(d, q){
        var box = document.getElementById('searchResults');
        var out = [];

        if (d.categories && d.categories.length) {
            out.push('<div class="sr-label">' + esc(T('vocab.cat_categorie')) + ' <span>' + d.categories.length + '</span></div>');
            d.categories.forEach(function(c){
                out.push(
                  '<a class="sr-cat" href="' + fcUrl('fc_view.php?category_id=' + c.id) + '" style="--row-color:' + esc(c.color) + ';">' +
                    '<span class="sr-cat-ic">' + SVG_CARTELLA + '</span>' +
                    '<span class="sr-cat-body">' +
                      '<span class="sr-cat-name">' + evidenzia(c.name, q) + '</span>' +
                      '<span class="sr-path">' + percorsoHtml(c.path) + '</span>' +
                    '</span>' +
                    '<span class="sr-cat-meta">' + SVG_CARTE + c.cards + (c.subs > 0 ? SVG_SOTTOC + c.subs : '') + '</span>' +
                  '</a>');
            });
        }

        if (d.groups && d.groups.length) {
            out.push('<div class="sr-label">' + esc(T('fc.cfc_carte')) + ' <span>' + (d.totalCards || 0) + '</span></div>');
            d.groups.forEach(function(g){
                var items = g.items.map(function(it){
                    /* Il contenuto NON si scrive qui: si lascia il contenitore
                       vuoto con addosso il documento da disegnare, e ci pensa
                       riempiTestiCarte() dopo l'inserimento nella pagina. E'
                       obbligatorio farlo dopo: l'evidenziazione della parola
                       cercata cammina sui nodi di testo dell'HTML disegnato,
                       e su una stringa non ci sono nodi. */
                    return '<div class="sr-card' + (it.child ? ' is-child' : '') + '">' +
                             (it.qt ? '<div class="sr-q fc-snip" data-doc="' + esc(it.qd || '') + '" data-txt="' + esc(it.qt) + '"></div>' : '') +
                             (it.at ? '<div class="sr-a fc-snip" data-doc="' + esc(it.ad || '') + '" data-txt="' + esc(it.at) + '"></div>' : '') +
                           '</div>';
                }).join('');
                out.push(
                  '<div class="sr-group" style="--row-color:' + esc(g.color) + ';">' +
                    '<a class="sr-group-head" href="' + fcUrl('fc_view.php?category_id=' + g.id) + '">' +
                      '<span class="sr-cat-ic">' + SVG_CARTELLA + '</span>' +
                      '<span class="sr-cat-body">' +
                        '<span class="sr-cat-name">' + esc(g.name) + '</span>' +
                        '<span class="sr-path">' + percorsoHtml(g.path) + '</span>' +
                      '</span>' +
                      '<span class="sr-n">' + g.n + '</span>' +
                    '</a>' +
                    '<div class="sr-group-body">' + items + (g.n > g.items.length ? '<div class="sr-more">' + esc(T('fc.cfc_e_altre', { n: g.n - g.items.length })) + '</div>' : '') + '</div>' +
                  '</div>');
            });
        }

        if (!out.length) {
            var suggerimento = filtriRicerca.cards ? '' : '<div class="sr-hint">' + esc(T('fc.cfc_suggerimento_carte')) + '</div>';
            var vuoto = (ambitoCorrente === 'archived') ? T('fc.cfc_nessun_risultato_arch') : T('fc.cfc_nessun_risultato');
            out.push('<div class="sr-empty">' + esc(vuoto) + suggerimento + '</div>');
        }
        box.innerHTML = out.join('');
        riempiTestiCarte(box, q);
    }

    /* Disegna i pezzi di carta e ci accende sopra la parola cercata.
       parseTextForPreview e' lo stesso punto d'ingresso che usa
       visualizzazione_fc: quello che si legge qui e' formattato come nella
       carta vera. Se per qualunque motivo fc_parser non fosse arrivato, resta
       il testo piano che il server manda comunque insieme al documento. */
    function riempiTestiCarte(radice, q){
        var usaParser = (typeof parseTextForPreview === 'function');
        radice.querySelectorAll('.fc-snip').forEach(function(el){
            var doc = el.getAttribute('data-doc');
            var txt = el.getAttribute('data-txt') || '';
            if (doc && usaParser) {
                try { el.innerHTML = parseTextForPreview(doc); }
                catch(e){ el.textContent = txt; }
            } else {
                el.textContent = txt;
            }
            evidenziaNodi(el, q);
        });
    }

    /* Evidenziare dentro dell'HTML gia' disegnato si fa sui nodi di testo, uno
       per uno: una replace sulla stringa infilerebbe i <mark> anche dentro ai
       nomi dei tag e agli attributi, e basterebbe cercare "div" o "class" per
       vedere la pagina sfasciarsi. Le formule si saltano: il loro contenuto e'
       codice LaTeX, non prosa da illuminare. */
    function evidenziaNodi(radice, q){
        if (!q) return;
        var qq = q.toLowerCase();
        var nodi = [];
        var camminatore = document.createTreeWalker(radice, NodeFilter.SHOW_TEXT, null);
        var n;
        while ((n = camminatore.nextNode())) nodi.push(n);

        nodi.forEach(function(nodo){
            var padre = nodo.parentNode;
            if (!padre || (padre.closest && padre.closest('.fc-x, .fc-fx'))) return;
            var t = nodo.nodeValue || '';
            var low = t.toLowerCase();
            if (low.indexOf(qq) === -1) return;

            var frammento = document.createDocumentFragment();
            var i = 0, j;
            while ((j = low.indexOf(qq, i)) !== -1) {
                if (j > i) frammento.appendChild(document.createTextNode(t.slice(i, j)));
                var m = document.createElement('mark');
                m.textContent = t.slice(j, j + qq.length);
                frammento.appendChild(m);
                i = j + qq.length;
            }
            if (i < t.length) frammento.appendChild(document.createTextNode(t.slice(i)));
            padre.replaceChild(frammento, nodo);
        });
    }

    function clearSearch(){
        var input = document.getElementById('searchInput');
        input.value = '';
        avviaRicerca('');
        input.focus();
    }


    /* ======================================================================
       MODALITA' SELEZIONE
       ====================================================================== */
    var modalitaSelezione = false;
    var selezione = {};

    function toggleModalitaSelezione(forza){
        modalitaSelezione = (typeof forza === 'boolean') ? forza : !modalitaSelezione;
        document.body.classList.toggle('sel-mode', modalitaSelezione);
        document.getElementById('btnSelect').classList.toggle('on', modalitaSelezione);
        if (!modalitaSelezione) svuotaSelezione();
        aggiornaSelBar();
        if (vistaCorrente === 'grid') disegnaGriglia();
    }

    function svuotaSelezione(){
        selezione = {};
        document.querySelectorAll('.cat-row.is-sel').forEach(function(r){ r.classList.remove('is-sel'); });
        aggiornaSelBar();
    }

    function toggleSelezione(id){
        if (selezione[id]) delete selezione[id]; else selezione[id] = true;
        var row = document.querySelector('.cat-row[data-id="' + id + '"]');
        if (row) row.classList.toggle('is-sel', !!selezione[id]);
        aggiornaSelBar();
    }

    function idsSelezionati(){ return Object.keys(selezione); }

    function aggiornaSelBar(){
        var n = idsSelezionati().length;
        var bar = document.getElementById('selBar');
        bar.classList.toggle('show', modalitaSelezione);
        document.getElementById('selCount').textContent = (n === 1) ? T('fc.cfc_selezionata_1') : T('fc.cfc_selezionate', { n: n });
        bar.querySelectorAll('.selact').forEach(function(b){ b.disabled = (n === 0); });
    }

    function inviaBulk(op, extra){
        var ids = idsSelezionati();
        if (!ids.length) return;
        document.getElementById('bulk_op').value = op;
        document.getElementById('bulk_ids').value = ids.join(',');
        if (extra && typeof extra.target !== 'undefined') document.getElementById('bulk_target_parent_id').value = extra.target;
        if (extra && extra.colorMode) document.getElementById('bulk_color_mode').value = extra.colorMode;
        if (extra && extra.color)     document.getElementById('bulk_color_value').value = extra.color;
        document.getElementById('formBulk').submit();
    }

    function bulkArchivia(v){ inviaBulk(v ? 'archive' : 'unarchive'); }

    function bulkSposta(){
        if (!idsSelezionati().length) return;
        spostaModo = 'bulk';
        /* Le selezionate sono spente nell'elenco: una di loro come destinazione
           sarebbe o se stessa o un anello con le sorelle. */
        preparaSelettoreSposta(null, T('fc.cfc_sposta_n', { n: idsSelezionati().length }), idsSelezionati());
        document.getElementById('btnOpenMoveSelector').click();
    }

    function bulkColore(){
        if (!idsSelezionati().length) return;
        coloreModo = 'bulk';
        colorTargetId = null;
        colorManual = true;
        colorParentColor = '#A0A0A0';
        colorOriginal = '#007AFF';
        colorOriginalManual = true;
        colorCurrent = '#007AFF';
        document.getElementById('colorPreviewName').textContent = T('fc.cfc_n_categorie', { n: idsSelezionati().length });
        updateColorUI();
        openSheet(document.getElementById('sheetColor'));
    }

    function bulkElimina(){
        var n = idsSelezionati().length;
        eliminaModo = 'bulk';
        document.getElementById('confermaEliminaNome').textContent = (n === 1) ? T('fc.cfc_elimina_sel_1') : T('fc.cfc_elimina_sel_n', { n: n });
        openSheet(document.getElementById('sheetConfirmDelete'));
    }


    /* ---------- Sheet: Nuova / Modifica ---------- */

    var formColorManual = false;
    var formColorValue = '#A0A0A0';
    var formParentColor = '#A0A0A0';
    var formArchivedNow = false;

    function apriModalSalva(opts){
        document.getElementById('form_cat_id').value = opts.id;
        document.getElementById('form_cat_name').value = opts.name;
        document.getElementById('modalSalvaTitolo').textContent = (opts.id == 0) ? T('vocab.cat_nuova') : T('vocab.cat_modifica_titolo');

        formColorManual = !!opts.colorEdited;
        formParentColor = opts.parentColor || '#A0A0A0';
        formColorValue = formColorManual ? (opts.color || '#A0A0A0') : formParentColor;
        updateFormColorUI();

        /* Archiviare o aggiungere una figlia hanno senso solo su una categoria
           che esiste gia': su una da creare i due tasti restano fuori. */
        var extra = document.getElementById('formExtra');
        if (opts.id && parseInt(opts.id, 10) > 0) {
            extra.style.display = '';
            formArchivedNow = !!opts.archived;
            document.getElementById('formArchiveTxt').textContent = formArchivedNow ? T('fc.cfc_ripristina_archivio') : T('fc.cfc_archivia_questa');
            document.getElementById('formArchiveIc').innerHTML = formArchivedNow ? ICONA_DISARCHIVIA : ICONA_ARCHIVIA;
        } else {
            extra.style.display = 'none';
        }

        if (window.csSetValue) {
            var parentLabel = (opts.parentId == 0) ? T('vocab.cat_nessuna_padre') : opts.parentName;
            window.csSetValue('cs-modal-formParent', opts.parentId, parentLabel, opts.parentColor);
        }
        csExcludeSubtree('cs-modal-formParent', opts.id);

        openSheet(document.getElementById('sheetForm'));
        setTimeout(function(){ document.getElementById('form_cat_name').focus(); }, 350);
    }

    /* Nasconde dal selettore "categoria padre" la categoria che si sta
       modificando (non può essere padre di se stessa). */
    function csExcludeSubtree(modalId, excludeId){
        var overlay = document.getElementById(modalId);
        if (!overlay) return;
        overlay.querySelectorAll('.cs-row-wrap').forEach(function(w){ w.style.display = ''; });
        if (!excludeId || excludeId == 0) return;
        var nameEl = overlay.querySelector('[data-cs-select="' + excludeId + '"]');
        var wrap = nameEl ? nameEl.closest('.cs-row-wrap') : null;
        if (wrap) wrap.style.display = 'none';
    }


    /* ---------- Selettore "sposta": si apre gia' aperto sul punto giusto ----------
       Il componente sa gia' srotolare l'albero fino a una categoria e
       evidenziarla: qui gli si dice fin dove aprire (la categoria che si sta
       spostando) e quali voci non sono scegliibili — la categoria stessa,
       che non puo' essere padre di se' stessa, e il suo padre attuale, dove
       gia' si trova. Cosi' chi apre il popup vede subito da dove parte e non
       deve ricercarsi il ramo a mano. */
    var spostaModo = 'single';

    function preparaSelettoreSposta(catId, titolo, disabilitati){
        var modal = document.getElementById('cs-modal-moveCategory');
        if (!modal) return;
        var titleEl = modal.querySelector('.cs-sheet-title');
        if (titleEl) titleEl.textContent = titolo;

        if (window.csSetExpandTo) window.csSetExpandTo('cs-modal-moveCategory', catId || 0);
        if (window.csSetDisabled) window.csSetDisabled('cs-modal-moveCategory', disabilitati || [], T('fc.cfc_non_selezionabile'));
    }

    function avviaSposta(id, name, parentId) {
        chiudiTuttiSwipe();
        spostaModo = 'single';
        document.getElementById('move_cat_id').value = id;
        csExcludeSubtree('cs-modal-moveCategory', 0);   // niente righe nascoste: si disabilitano, non si tolgono
        preparaSelettoreSposta(id, T('vocab.cat_sposta_titolo', { nome: name }), [id, parentId || 0]);
        document.getElementById('btnOpenMoveSelector').click();
    }


    /* Intercettiamo le selezioni di QUALSIASI category_selector nella pagina */
    document.addEventListener('categoryselected', function(e){
        if (!e.detail) return;

        // 1. Gestione del selettore per "Sposta" (singolo o di gruppo)
        if (e.detail.uid === 'moveCategory') {
            if (spostaModo === 'bulk') {
                inviaBulk('move', { target: e.detail.id });
            } else {
                document.getElementById('target_parent_id').value = e.detail.id;
                document.getElementById('formSposta').submit();
            }
        }

        // 2. Gestione del selettore "Categoria Padre" nel form di Creazione/Modifica
        if (e.detail.uid === 'formParent') {
            formParentColor = e.detail.color || '#A0A0A0';
            if (!formColorManual) {
                formColorValue = formParentColor;
                updateFormColorUI();
            }
        }
    });


    function setFormColorAuto(){
        formColorManual = false;
        formColorValue = formParentColor;
        updateFormColorUI();
    }
    function setFormColorManual(hex){
        formColorManual = true;
        formColorValue = hex;
        updateFormColorUI();
    }
    function updateFormColorUI(){
        document.getElementById('form_color_mode').value = formColorManual ? 'manual' : 'auto';
        document.getElementById('form_color_value').value = formColorValue;
        document.getElementById('formColorPreviewIcon').style.setProperty('--row-color', formColorValue);
        document.getElementById('formColorPreviewName').textContent = formColorManual ? T('vocab.cat_colore_pers') : T('vocab.cat_auto_eredita');
        document.getElementById('formCustomColorInput').value = formColorValue;
        document.getElementById('formCustomColorHex').textContent = formColorValue.toUpperCase();
        document.querySelectorAll('#formColorGrid .color-swatch').forEach(function(sw){
            if (sw.classList.contains('color-swatch-auto')) {
                sw.classList.toggle('selected', !formColorManual);
            } else {
                sw.classList.toggle('selected', formColorManual && (sw.dataset.hex || '').toLowerCase() === formColorValue.toLowerCase());
            }
        });
    }

    function archiviaDalForm(){
        var id = parseInt(document.getElementById('form_cat_id').value, 10);
        if (!id) return;
        archiviaCategoria(id, formArchivedNow ? 0 : 1);
    }
    function aggiungiFiglioDalForm(){
        var id = parseInt(document.getElementById('form_cat_id').value, 10);
        var nome = document.getElementById('form_cat_name').value || '';
        var colore = formColorValue;
        if (!id) return;
        chiudiModals();
        setTimeout(function(){ apriAggiungiFiglio(id, nome, colore); }, 260);
    }


    /* ---------- Archiviazione ---------- */

    var ICONA_ARCHIVIA    = <?php echo json_encode(FC_ICON_ARCHIVE, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    var ICONA_DISARCHIVIA = <?php echo json_encode(FC_ICON_UNARCHIVE, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

    function archiviaCategoria(id, valore){
        document.getElementById('archive_cat_id').value = id;
        document.getElementById('archive_value').value = valore ? 1 : 0;
        document.getElementById('formArchive').submit();
    }
    function archiviaDaRiga(btn){
        var r = rigaFcDi(btn);
        if (!r) return;
        archiviaCategoria(parseInt(r.dataset.id, 10), r.dataset.archived === '1' ? 0 : 1);
    }


    /* ---------- Sheet: Azioni (kebab) ---------- */

    function apriAzioni(btn){
        var id = btn.dataset.id;
        var name = btn.dataset.name;
        var parentId = btn.dataset.parent;
        var parentName = btn.dataset.parentName || '';
        var parentColor = btn.dataset.parentColor || '#A0A0A0';
        var color = btn.dataset.color || '#007AFF';
        var colorEdited = btn.dataset.colorEdited === '1';
        var canDelete = btn.dataset.candelete === '1';
        var archived = btn.dataset.archived === '1';

        document.getElementById('azioniTitolo').textContent = name;

        document.getElementById('azioneModifica').onclick = function(){
            chiudiModals();
            setTimeout(function(){
                apriModalSalva({
                    id: id, name: name, parentId: parentId,
                    parentName: parentName, parentColor: parentColor,
                    color: color, colorEdited: colorEdited, archived: archived
                });
            }, 260);
        };
        document.getElementById('azioneColore').onclick = function(){
            chiudiModals();
            setTimeout(function(){ apriColore(id, name, color, colorEdited, parentColor); }, 260);
        };
        document.getElementById('azioneAggiungiFiglia').onclick = function(){
            chiudiModals();
            setTimeout(function(){ apriAggiungiFiglio(id, name, color); }, 260);
        };
        document.getElementById('azioneSposta').onclick = function(){
            chiudiModals();
            setTimeout(function(){ avviaSposta(id, name, parseInt(parentId, 10)); }, 260);
        };

        document.getElementById('azioneArchiviaTxt').textContent = archived ? T('fc.cfc_ripristina_archivio') : T('fc.cfc_archivia');
        document.getElementById('azioneArchiviaIc').innerHTML = archived ? ICONA_DISARCHIVIA : ICONA_ARCHIVIA;
        document.getElementById('azioneArchivia').onclick = function(){
            archiviaCategoria(id, archived ? 0 : 1);
        };

        var delBtn = document.getElementById('azioneElimina');
        if (canDelete) {
            delBtn.style.display = 'flex';
            delBtn.onclick = function(){
                chiudiModals();
                setTimeout(function(){ apriConfermaElimina(id, name); }, 260);
            };
        } else {
            delBtn.style.display = 'none';
        }

        openSheet(document.getElementById('sheetActions'));
    }



    /* ---------- Sheet: Colore cartella (con anteprima live) ---------- */

    var colorTargetId = null;
    var colorManual = true;
    var colorParentColor = '#A0A0A0';
    var colorOriginal = '#007AFF';
    var colorOriginalManual = true;
    var colorCurrent = '#007AFF';
    var coloreModo = 'single';

    /* colorEdited/parentColor arrivano dagli stessi dati che gia' viaggiano
       per ogni riga (data-color-edited, data-parent-color): servono per
       sapere se si parte da "colore proprio" o da "eredita dal padre", e
       cosa mostrare per l'opzione automatica. */
    function apriColore(id, name, color, colorEdited, parentColor){
        coloreModo = 'single';
        colorTargetId = id;
        colorManual = !!colorEdited;
        colorParentColor = parentColor || '#A0A0A0';
        colorOriginal = colorManual ? color : colorParentColor;
        colorOriginalManual = colorManual;
        colorCurrent = colorOriginal;

        document.getElementById('color_cat_id').value = id;
        document.getElementById('colorPreviewName').textContent = name;

        updateColorUI();
        openSheet(document.getElementById('sheetColor'));
    }

    function selectColorAuto(){
        colorManual = false;
        colorCurrent = colorParentColor;
        updateColorUI();
    }

    function selectColor(hex){
        colorManual = true;
        colorCurrent = hex;
        updateColorUI();
    }

    function updateColorUI(){
        document.getElementById('customColorInput').value = colorCurrent;
        document.getElementById('customColorHex').textContent = colorCurrent.toUpperCase();
        applyColorPreview(colorCurrent);
        highlightSwatch();
    }

    function highlightSwatch(){
        var norm = colorCurrent.toLowerCase();
        document.querySelectorAll('#colorGrid .color-swatch').forEach(function(sw){
            if (sw.dataset.auto) {
                sw.classList.toggle('selected', !colorManual);
            } else if (sw.dataset.hex) {
                sw.classList.toggle('selected', colorManual && sw.dataset.hex.toLowerCase() === norm);
            }
        });
    }

    function applyColorPreview(hex){
        document.getElementById('colorPreviewIcon').style.setProperty('--row-color', hex);

        /* anteprima live sulla riga reale, dietro al foglio */
        if (colorTargetId === null) return;
        var row = document.querySelector('.cat-row[data-id="' + colorTargetId + '"]');
        if (row) {
            row.style.setProperty('--row-color', hex);
            if (row.dataset.level === '0') {
                var card = row.closest('.materia-card');
                if (card) card.style.setProperty('--accent', hex);
            }
        }
        var kebab = document.querySelector('.cat-kebab[data-id="' + colorTargetId + '"]');
        if (kebab) kebab.dataset.color = hex;
    }

    function annullaColore(){
        if (coloreModo === 'single') applyColorPreview(colorOriginal);
        chiudiModals();
    }

    function salvaColore(){
        if (coloreModo === 'bulk') {
            inviaBulk('color', { colorMode: colorManual ? 'manual' : 'auto', color: colorCurrent });
            return;
        }
        document.getElementById('color_cat_mode').value = colorManual ? 'manual' : 'auto';
        document.getElementById('color_cat_value').value = colorCurrent;
        document.getElementById('formColor').submit();
    }



    /* ---------- Sheet: Conferma eliminazione ---------- */

    var eliminaModo = 'single';

    function apriConfermaElimina(id, name){
        eliminaModo = 'single';
        document.getElementById('delete_cat_id').value = id;
        document.getElementById('confermaEliminaNome').textContent = '"' + name + '"';
        openSheet(document.getElementById('sheetConfirmDelete'));
    }
    function confermaEliminaSubmit(){
        if (eliminaModo === 'bulk') { inviaBulk('delete'); return; }
        document.getElementById('formDelete').submit();
    }


    /* ---------- Toast di conferma dopo redirect ---------- */

    function showToast(text){
        var t = document.createElement('div');
        t.className = 'toast';
        t.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg><span>' + text + '</span>';
        document.body.appendChild(t);
        requestAnimationFrame(function(){ t.classList.add('show'); });
        setTimeout(function(){
            t.classList.remove('show');
            setTimeout(function(){ t.remove(); }, 320);
        }, 2200);
    }
    (function(){
        var params = new URLSearchParams(window.location.search);
        var ok = params.get('ok');
        var messages = {
            added: T('vocab.cat_creata'), saved: T('vocab.cat_salvate'), deleted: T('vocab.cat_eliminata'),
            moved: T('vocab.cat_spostata'), colored: T('vocab.cat_colore_ok'),
            archived: T('fc.cfc_archiviata'), unarchived: T('fc.cfc_ripristinata'),
            deleted_partial: T('fc.cfc_eliminate_parziale'),
            deleted_none: T('fc.cfc_eliminate_nessuna')
        };
        if (ok && messages[ok]) {
            showToast(messages[ok]);
            history.replaceState(null, '', fcUrl(window.location.pathname));
        }
        /* ?new=1 arriva da "Primo mazzo" della pagina delle lingue: chi l'ha
           toccato vuole creare, quindi il foglio si apre da solo. */
        if (params.get('new') === '1' && typeof apriNuovaDaFab === 'function') {
            history.replaceState(null, '', fcUrl(window.location.pathname));
            setTimeout(apriNuovaDaFab, 250);
        }
    })();


    /* ---------- Gestione Swipe ----------
   Due versi, due significati:
     - da SINISTRA verso DESTRA rivela i tasti (modifica, sposta, elimina)
     - da DESTRA verso SINISTRA archivia, come nelle liste di iOS: un trascinamento
       corto rivela il tasto "Archivia", uno lungo (oltre meta' riga) esegue da
       solo senza bisogno di toccare altro.
   I tasti dietro la riga restano display:none finche' non si sente un vero
   trascinamento orizzontale: prima stavano sempre nel flusso (nascosti solo
   dall'overlap con la riga sopra) ed era facile vederli spuntare per un
   istante, specie durante lo scroll verticale della pagina. */
(function(){

    if (!window.matchMedia("(hover:none) and (pointer:coarse)").matches){
        return;
    }

    var SOGLIA = 8;        /* px minimi prima di considerarlo un vero swipe orizzontale */
    var QUOTA_PIENA = .5;  /* frazione della larghezza oltre la quale lo swipe verso sinistra archivia da solo */
    var ARCH_BASE = 96;    /* larghezza a cui il tasto archivia si ferma se lo si lascia a meta' */

    var activeRow = null;
    var activeActions = null;
    var activeArch = null;
    var startX = 0, startY = 0;
    var isDragging = false;
    var confermato = false;
    var verso = 0;            /* +1 = tasti a sinistra, -1 = archivia */
    var maxSwipe = 0, maxArch = 0;

    window.chiudiTuttiSwipe = function() {
        document.querySelectorAll('.cat-row-main.swiped-open, .cat-row-main.swiped-open-l').forEach(function(r) {
            r.style.transform = 'translateX(0)';
            r.classList.remove('swiped-open');
            r.classList.remove('swiped-open-l');
        });
        /* pulizia difensiva: qualunque riga fosse rimasta rivelata a meta'
           gesto, a prescindere da quale sia activeRow in questo momento */
        document.querySelectorAll('.cat-swipe-actions.revealing, .cat-swipe-arch.revealing').forEach(function(a){
            a.classList.remove('revealing');
            a.classList.remove('pronta');
            a.style.transition = '';
            a.style.width = '';
        });
        activeRow = null;
        activeActions = null;
        activeArch = null;
    };

    document.addEventListener('touchstart', function(e) {
        if (document.body.classList.contains('sel-mode')) return;   // in selezione lo swipe non serve
        if (e.target.closest('.cat-swipe-actions') || e.target.closest('.cat-swipe-arch')) return; // click sui tasti svelati

        var rowMain = e.target.closest('.cat-row-main');
        if (!rowMain) {
            chiudiTuttiSwipe();
            return;
        }

        if (activeRow && activeRow !== rowMain) chiudiTuttiSwipe();

        activeRow = rowMain;
        activeActions = rowMain.parentElement.querySelector('.cat-swipe-actions');
        activeArch    = rowMain.parentElement.querySelector('.cat-swipe-arch');
        if (!activeActions && !activeArch) { activeRow = null; return; }

        /* La larghezza NON si misura qui: i tasti partono display:none, e
           offsetWidth su un elemento non renderizzato vale 0. Si misura al
           momento della conferma, subito dopo averli resi visibili (vedi
           touchmove). */
        maxSwipe = 0; maxArch = 0; verso = 0;
        startX = e.touches[0].clientX;
        startY = e.touches[0].clientY;
        isDragging = true;
        if (rowMain.classList.contains('swiped-open'))        { confermato = true; verso = 1; }
        else if (rowMain.classList.contains('swiped-open-l')) { confermato = true; verso = -1; }
        else                                                   { confermato = false; }
        rowMain.style.transition = 'none';
    }, {passive: true});

    document.addEventListener('touchmove', function(e) {
        if (!isDragging || !activeRow) return;
        var dx = e.touches[0].clientX - startX;
        var dy = e.touches[0].clientY - startY;

        if (!confermato) {
            // Se lo scroll è verticale nativo, annulla lo swipe orizzontale
            if (Math.abs(dy) > Math.abs(dx) && Math.abs(dy) > 10) {
                isDragging = false;
                chiudiTuttiSwipe();
                return;
            }
            // Sotto soglia: ambiguo, si aspetta il prossimo movimento senza
            // rivelare ancora nulla (evita il lampo su un piccolo tremolio).
            if (Math.abs(dx) < SOGLIA) return;
            verso = dx > 0 ? 1 : -1;
            if (verso === 1  && !activeActions) { isDragging = false; return; }
            if (verso === -1 && !activeArch)    { isDragging = false; return; }
            confermato = true;
            if (verso === 1) { activeActions.classList.add('revealing'); maxSwipe = activeActions.offsetWidth; }
            else             { activeArch.classList.add('revealing');    maxArch  = ARCH_BASE; }
        }
        /* Riga gia' aperta da prima: i tasti sono visibili ma la misura non e'
           ancora stata presa in questo gesto. */
        if (verso === 1  && !maxSwipe && activeActions) maxSwipe = activeActions.offsetWidth;
        if (verso === -1 && !maxArch)                   maxArch  = ARCH_BASE;

        if (verso === 1) {
            var tx = (dx > 0) ? Math.min(dx, maxSwipe + 20) : Math.max(dx, -15);
            activeRow.style.transform = 'translateX(' + tx + 'px)';
            if (dx > 0) e.preventDefault();
        } else {
            /* Verso sinistra si puo' tirare ben oltre il tastino: e' proprio
               quel "tirare fino in fondo" a far scattare l'archiviazione da
               solo, quindi il movimento non va fermato al bordo del tasto. */
            var larghezza = activeRow.offsetWidth || 320;
            var tl = (dx < 0) ? Math.max(dx, -larghezza) : Math.min(dx, 15);
            activeRow.style.transform = 'translateX(' + tl + 'px)';

            /* IL TASTO CRESCE COL DITO, come nelle liste di iOS.
               Non e' un vezzo: se restasse largo 96px, tirando oltre resterebbe
               una fascia vuota fra il tasto e il bordo della riga, e il gesto
               sembrerebbe rotto proprio nel momento in cui sta per valere.
               Occupando sempre tutto lo spazio scoperto, la riga non si "apre":
               e' il tasto che avanza, ed e' esattamente quello che poi succede
               lasciando andare. */
            activeArch.style.transition = 'none';
            activeArch.style.width = Math.max(ARCH_BASE, Math.abs(tl)) + 'px';

            /* Superata meta' riga il tasto e' "armato": si scurisce e la
                scritta scivola a sinistra, che e' il modo in cui iOS dice
                "se molli adesso, vale come averlo premuto". */
            activeArch.classList.toggle('pronta', Math.abs(tl) >= larghezza * QUOTA_PIENA);
            if (dx < 0) e.preventDefault();
        }
    }, {passive: false});

    document.addEventListener('touchend', function(e) {
        if (!isDragging || !activeRow) return;
        isDragging = false;
        activeRow.style.transition = 'transform 0.3s cubic-bezier(0.32, 0.72, 0, 1)';

        var tx = 0;
        var transformStr = activeRow.style.transform;
        if (transformStr && transformStr.indexOf('translateX') !== -1) {
            tx = parseFloat(transformStr.replace('translateX(', ''));
        }
        var larghezza = activeRow.offsetWidth || 320;
        var riga = activeRow.closest('.cat-row');

        if (verso === -1) {
            if (activeArch) activeArch.style.transition = 'width .3s cubic-bezier(0.32, 0.72, 0, 1), background .18s';

            if (Math.abs(tx) >= larghezza * QUOTA_PIENA) {
                /* Tasto armato e dito alzato: vale come averlo premuto. Il
                   tasto finisce di prendersi tutta la riga e l'azione parte a
                   scivolata conclusa. Il modulo ricarica la pagina, quindi non
                   serve rimettere a posto niente qui. */
                activeRow.style.transform = 'translateX(-' + larghezza + 'px)';
                if (activeArch) activeArch.style.width = '100%';
                var idA = parseInt(riga.dataset.id, 10);
                var giaArch = riga.dataset.archived === '1';
                setTimeout(function(){ archiviaCategoria(idA, giaArch ? 0 : 1); }, 220);
                return;
            }
            if (Math.abs(tx) > ARCH_BASE / 2) {
                activeRow.style.transform = 'translateX(-' + ARCH_BASE + 'px)';
                activeRow.classList.add('swiped-open-l');
                if (activeArch) { activeArch.style.width = ARCH_BASE + 'px'; activeArch.classList.remove('pronta'); }
            } else {
                activeRow.style.transform = 'translateX(0)';
                activeRow.classList.remove('swiped-open-l');
                if (activeArch) {
                    activeArch.classList.remove('pronta');
                    activeArch.style.width = '';
                    activeArch.classList.remove('revealing');
                }
                activeRow = null; activeActions = null; activeArch = null;
            }
            return;
        }

        // Se hai superato la metà della larghezza dei tasti, scatta l'apertura
        if (tx > maxSwipe / 2) {
            activeRow.style.transform = 'translateX(' + maxSwipe + 'px)';
            activeRow.classList.add('swiped-open');
            /* resta 'revealing': i tasti devono restare visibili e toccabili
               finche' la riga e' aperta */
        } else {
            activeRow.style.transform = 'translateX(0)';
            activeRow.classList.remove('swiped-open');
            if (activeActions) activeActions.classList.remove('revealing');
            activeRow = null;
            activeActions = null;
            activeArch = null;
        }
    });
})();
if (!window.chiudiTuttiSwipe) window.chiudiTuttiSwipe = function(){};


// Navigazione dell'intera riga categoria verso visualizzazione_fc.
// Freccetta, tag progetto e kebab fermano già la propagazione, quindi qui
// arrivano solo i click sul "corpo" della riga. Se la riga è aperta in swipe,
// un tap la richiude soltanto (non naviga). In modalità selezione il tap non
// naviga mai: seleziona.
function fcCatRowNavigate(e, el){
    var row = el.closest('.cat-row');
    if (modalitaSelezione) {
        e.preventDefault();
        if (row) toggleSelezione(parseInt(row.dataset.id, 10));
        return;
    }
    if (el.classList.contains('swiped-open') || el.classList.contains('swiped-open-l')) { chiudiTuttiSwipe(); return; }
    if (document.querySelector('.cat-row-main.swiped-open, .cat-row-main.swiped-open-l')) { chiudiTuttiSwipe(); return; }
    var link = el.getAttribute('data-cat-link');
    if (!link) return;
    /* Si ricorda quale categoria si sta per aprire: se poi si torna qui
       (tasto Home dentro la categoria, o il "indietro" del browser), la si
       ritrova gia' scoperta invece di ripartire dall'albero tutto chiuso. */
    if (row) { try { sessionStorage.setItem('nisama_cat_tree_focus', row.dataset.id); } catch(err){} }
    window.location.href = link;
}


/* ---------- Il "+" grande parte dalla cartella che si sta guardando ----------
   Se c'e' una categoria aperta sotto gli occhi, la nuova nasce dentro quella:
   e' quasi sempre quello che si vuole, e toglie un passaggio nel selettore
   del padre. Fra le aperte visibili vince la piu' profonda, cioe' la piu'
   specifica: aprendo Fisica > Termodinamica si sta guardando Termodinamica. */
function categoriaContestuale(){
    if (vistaCorrente === 'grid' && gridPercorso.length) {
        var c = CAT_BY_ID[gridPercorso[gridPercorso.length - 1]];
        if (c) return { id: c.id, name: c.n, color: c.c };
    }
    var best = null;
    document.querySelectorAll('.cat-arrow.open').forEach(function(a){
        var r = a.closest('.cat-row');
        /* offsetParent nullo = la riga sta dentro un riquadro nascosto (l'altro
           ambito, o la ricerca in corso): non e' "sotto gli occhi". */
        if (!r || r.offsetParent === null) return;
        var rc = r.getBoundingClientRect();
        if (rc.bottom < 0 || rc.top > window.innerHeight) return;
        var lvl = parseInt(r.dataset.level, 10);
        if (!best || lvl > best.lvl || (lvl === best.lvl && rc.top < best.top)) best = { lvl: lvl, top: rc.top, row: r };
    });
    if (best) return { id: parseInt(best.row.dataset.id, 10), name: best.row.dataset.name, color: best.row.dataset.color };
    return null;
}
function apriNuovaDaFab(){
    var c = categoriaContestuale();
    if (c) apriAggiungiFiglio(c.id, c.name, c.color);
    else   apriModalSalva({ id:0, name:'', parentId:0, parentName:'', parentColor:'#A0A0A0', color:'#A0A0A0', colorEdited:false });
}


/* ---------- Ritorno da una categoria: la si ritrova gia' scoperta ----------
   La chiave la scrive sia questa pagina (al clic su una riga) sia
   fc_view.php (a ogni sua apertura, cosi' vale anche se dentro ci
   si e' spostati su una sotto-cartella senza passare di qui). E' un
   ricordo "usa e getta": una volta applicato si toglie, cosi' un giro
   successivo per l'albero non continua a riportare sempre li'. */
function fcRestoreTreeFocus(){
    var id = null;
    try { id = sessionStorage.getItem('nisama_cat_tree_focus'); } catch(err){}
    if (!id) return;
    try { sessionStorage.removeItem('nisama_cat_tree_focus'); } catch(err){}

    var row = document.querySelector('.cat-row[data-id="' + id + '"]');
    if (!row || row.offsetParent === null) return;

    // Catena degli antenati, dal piu' lontano (radice) al piu' vicino.
    var catena = [];
    var cur = row;
    var guardia = 0;
    while (cur && guardia < 60) {
        guardia++;
        var pid = cur.dataset.parent;
        if (!pid || pid === '0') break;
        catena.unshift(pid);
        cur = document.querySelector('.cat-row[data-id="' + pid + '"]');
    }

    catena.forEach(function(pid){
        var r = document.querySelector('.cat-row[data-id="' + pid + '"]');
        if (!r) return;
        var arrow = r.querySelector('.cat-arrow');
        if (arrow) arrow.classList.add('open');
        espandiFigli(pid);
    });
    salvaRamiAperti();

    function porta(){
        var h = document.querySelector('.app-header');
        var alto = h ? h.offsetHeight + 14 : 90;
        var y = row.getBoundingClientRect().top + window.pageYOffset - alto;
        window.scrollTo(0, Math.max(0, y));
    }
    porta();
    /* le righe appena espanse animano l'ingresso: si ripassa quando e' finita */
    setTimeout(porta, 220);

    row.classList.add('cat-focus-flash');
    setTimeout(function(){ row.classList.remove('cat-focus-flash'); }, 2400);
}


/* ---------- Avvio ---------- */
aggiornaChipFiltri();
ripristinaRamiAperti();
applicaVista();
aggiornaSelBar();
fcRestoreTreeFocus();

</script>



</body>
</html>
