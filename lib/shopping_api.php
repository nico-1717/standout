<?php
/* =====================================================================
   STANDOUT — IL «TO BUY» per l'API /api/v1/shopping (PROGETTO §15, passo 9, fetta 4)

   Come lib/todo_core.php per le attivita': qui c'e' solo il controllo dei tipi
   e la modifica «a campi» (PATCH). Le regole del to buy — categorie di
   partenza, prezzo in centesimi, indirizzo solo http/https, «memoria» degli
   articoli comprati, blocco sul planner — sono le funzioni td_* spostate in
   lib/todo_core.php: la pagina e l'app usano lo stesso codice.

   Gli articoli sono righe di `todos` con list_type='buy'. Una riga di un altro
   elenco si comporta come se non esistesse (404): il to do ha le sue rotte
   (/api/v1/tasks) e non si passa da una all'altra per sbaglio.

   Fuori da questa fetta, di proposito: le immagini degli articoli (file
   caricati: una fetta a parte) e «sposta in un altro elenco».

   Ogni funzione prende l'utente come PRIMO argomento e filtra ogni riga per
   user_id. Errori: ['errore' => ['codice', 'campo', 'testo']].
   Caricato da public/api/v1/index.php.
   ===================================================================== */
require_once __DIR__ . '/todo_core.php';

/* Quante categorie al massimo: la pagina non ha un tetto, ma l'app non deve poterne
   riempire la tabella con un ciclo (le scritture hanno gia' il loro limite al minuto). */
const SP_MAX_CATEGORIE = 100;
const SP_CENT_MAX = 999999999;   // 9.999.999,99: lo stesso tetto di td_price_cents (7 cifre intere)

/** Come l'app vede un articolo: come td_out() senza i campi che nel to buy non servono. */
function sp_out(array $r): array {
    $o = td_out($r);
    $o['category_id'] = $o['cat_id'];
    $o['price_cents'] = $o['cents'];
    // del file dell'immagine si dice solo se c'e': per scaricarla servira' la sua fetta
    $o['has_image'] = !empty($o['image']);
    foreach (['list_type', 'parent_id', 'source', 'cat_id', 'cents', 'image'] as $k) unset($o[$k]);
    return $o;
}

/** Una riga del to buy dell'utente (con nome e colore del progetto), o null. */
function sp_riga(int $uid, int $id): ?array {
    return sec_one("SELECT t.*, p.name AS project_name, p.color AS project_color
                    FROM todos t LEFT JOIN projects p ON p.id = t.project_id AND p.user_id = t.user_id
                    WHERE t.id = ? AND t.user_id = ? AND t.list_type = 'buy'", [$id, $uid], 'ii');
}

/**
 * L'elenco del to buy nell'ordine della pagina (prima le non comprate, poi per
 * categoria e priorita') con le categorie. Come la pagina, toglie prima gli
 * articoli comprati nei giorni scorsi (td_cleanup) e al primo accesso crea le
 * categorie di partenza (td_seed_categories).
 */
function sp_elenco(int $uid): array {
    td_cleanup($uid);
    td_seed_categories($uid);
    $righe = sec_all("SELECT t.*, p.name AS project_name, p.color AS project_color
                      FROM todos t LEFT JOIN projects p ON p.id = t.project_id AND p.user_id = t.user_id
                      WHERE t.user_id = ? AND t.list_type = 'buy'
                      ORDER BY t.is_done ASC,
                               CASE WHEN t.buy_category_id IS NULL THEN 1 ELSE 0 END,
                               t.buy_category_id ASC,
                               t.buy_priority ASC,
                               t.sort_order ASC, t.id ASC
                      LIMIT 1000", [$uid], 'i');
    return ['items' => array_map('sp_out', $righe), 'categories' => td_categories($uid)];
}

/**
 * Legge e controlla i campi che il client manda (chiave presente = si tocca;
 * null = si toglie; chiave assente = non si tocca). I campi in comune con il to do
 * (titolo, progetto, data, ora, durata, done) li controlla td_api_valida().
 * Ritorna ['campi' => [colonna => valore], 'fatto' => bool|null] oppure un errore.
 */
function sp_valida(int $uid, array $c, bool $creazione): array {
    $propri = ['category_id', 'priority', 'price_cents', 'url'];
    // parent_id non esiste nel to buy: si ignora come ogni campo sconosciuto
    $v = td_api_valida($uid, array_diff_key($c, array_flip($propri), ['parent_id' => 1]), $creazione);
    if (isset($v['errore'])) return $v;
    $campi = $v['campi'];

    if (array_key_exists('category_id', $c)) {
        $k = $c['category_id'];
        if ($k === null || $k === 0) $campi['buy_category_id'] = null;
        elseif (!is_int($k) || $k < 0) return td_api_errore('validation', 'category_id', 'category_id must be a number or null');
        elseif (!sec_owns('todo_buy_categories', $k, $uid)) return td_api_errore('not_found', 'category_id', 'Category not found');
        else $campi['buy_category_id'] = $k;
    }

    if (array_key_exists('priority', $c)) {
        if (!is_int($c['priority']) || $c['priority'] < 1 || $c['priority'] > 3) {
            return td_api_errore('validation', 'priority', 'priority must be 1 (highest), 2 or 3');
        }
        $campi['buy_priority'] = $c['priority'];
    }

    if (array_key_exists('price_cents', $c)) {
        $p = $c['price_cents'];
        if ($p === null) $campi['buy_price_cents'] = null;
        elseif (!is_int($p) || $p < 0 || $p > SP_CENT_MAX) return td_api_errore('validation', 'price_cents', 'price_cents must be a whole number of cents from 0 to ' . SP_CENT_MAX . ', or null');
        else $campi['buy_price_cents'] = $p;
    }

    if (array_key_exists('url', $c)) {
        if ($c['url'] === null) $campi['buy_url'] = null;
        else {
            /* td_url() aggiunge https:// a chi non scrive lo schema: «ftp://x» diventerebbe
               «https://ftp://x». Per l'app uno schema diverso da http/https e' un errore, non si corregge. */
            $grezzo = is_string($c['url']) ? trim($c['url']) : '';
            $schemaStrano = str_contains($grezzo, '://') && !preg_match('~^https?://~i', $grezzo);
            $u = $grezzo !== '' && !$schemaStrano && mb_strlen($grezzo) <= 2048 ? td_url($grezzo) : null;
            if ($u === null) return td_api_errore('validation', 'url', 'url must be a valid http or https address, or null');
            $campi['buy_url'] = $u;
        }
    }

    return ['campi' => $campi, 'fatto' => $v['fatto']];
}

/**
 * Comprato / non comprato. Uno stato uguale a quello di adesso non fa niente
 * (e non conta una «compera» in piu' nella memoria): la pagina alterna, l'app dice lo stato.
 */
function sp_spunta(int $uid, int $id, bool $fatto): void {
    $r = sec_one("SELECT is_done FROM todos WHERE id = ? AND user_id = ?", [$id, $uid], 'ii');
    if (!$r || ((int)$r['is_done'] === 1) === $fatto) return;
    sec_q("UPDATE todos SET is_done=?, done_at=" . ($fatto ? 'NOW()' : 'NULL') . " WHERE id=? AND user_id=?", [$fatto ? 1 : 0, $id, $uid]);
    td_sync_planner($uid, $id);
    if ($fatto) td_mem_ricorda($uid, $id, true);
}

/** Crea un articolo. Ritorna ['item' => ...] oppure un errore. */
function sp_crea(int $uid, array $c): array {
    $v = sp_valida($uid, $c, true);
    if (isset($v['errore'])) return $v;
    $f = $v['campi'];

    $next = sec_one("SELECT COALESCE(MAX(sort_order),0)+1 AS n FROM todos WHERE user_id = ?", [$uid], 'i');
    sec_q("INSERT INTO todos (user_id, list_type, parent_id, project_id, title, due_date, due_time,
                              duration_minutes, sort_order, source,
                              buy_category_id, buy_priority, buy_price_cents, buy_url)
           VALUES (?, 'buy', NULL, ?, ?, ?, ?, ?, ?, 'page', ?, ?, ?, ?)",
          [$uid, $f['project_id'] ?? null, $f['title'], $f['due_date'] ?? null, $f['due_time'] ?? null,
           $f['duration_minutes'] ?? 30, (int)($next['n'] ?? 1),
           $f['buy_category_id'] ?? null, $f['buy_priority'] ?? 3, $f['buy_price_cents'] ?? null, $f['buy_url'] ?? null]);
    $id = sec_insert_id();
    td_sync_planner($uid, $id);
    if ($v['fatto'] === true) sp_spunta($uid, $id, true);
    return ['item' => sp_out(sp_riga($uid, $id) ?? [])];
}

/** Cambia SOLO i campi nominati. Ritorna ['item' => ...] oppure un errore. */
function sp_modifica(int $uid, int $id, array $c): array {
    if (!sp_riga($uid, $id)) return td_api_errore('not_found', 'id', 'Item not found');
    $v = sp_valida($uid, $c, false);
    if (isset($v['errore'])) return $v;
    if (!$v['campi'] && $v['fatto'] === null) return td_api_errore('validation', 'body', 'Nothing to update');

    /* I nomi delle colonne vengono SOLO da sp_valida / td_api_valida (elenco fisso), mai dal client. */
    if ($v['campi']) {
        $set = []; $par = [];
        foreach ($v['campi'] as $col => $val) { $set[] = "`$col` = ?"; $par[] = $val; }
        $par[] = $id; $par[] = $uid;
        sec_q("UPDATE todos SET " . implode(', ', $set) . " WHERE id = ? AND user_id = ?", $par);
    }
    td_sync_planner($uid, $id);
    // come la pagina: cambiare categoria o prezzo aggiorna cio' che si ricorda dell'articolo (senza contarlo come comprato)
    if (array_key_exists('buy_category_id', $v['campi']) || array_key_exists('buy_price_cents', $v['campi'])) td_mem_ricorda($uid, $id, false);
    if ($v['fatto'] !== null) sp_spunta($uid, $id, $v['fatto']);
    return ['item' => sp_out(sp_riga($uid, $id) ?? [])];
}

/** Toglie un articolo, la sua immagine e il blocco sul planner. */
function sp_elimina(int $uid, int $id): bool {
    $r = sp_riga($uid, $id);
    if (!$r) return false;
    if (!empty($r['planner_event_id'])) {
        sec_q("DELETE FROM planner_events WHERE id=? AND user_id=?", [(int)$r['planner_event_id'], $uid], 'ii');
    }
    td_drop_image($r['buy_image'] ?? null);
    sec_q("DELETE FROM todos WHERE id=? AND user_id=? AND list_type='buy'", [$id, $uid], 'ii');
    return true;
}

/** Gli articoli comprati in passato (per i suggerimenti e i «da ricomprare»), i piu' comprati per primi. */
function sp_storia(int $uid): array {
    if (!td_mem_c_e()) return [];
    $righe = sec_all("SELECT chiave, title, cat_id, cents, uses, last_used FROM todo_buy_memory
                      WHERE user_id = ? ORDER BY uses DESC, last_used DESC LIMIT 300", [$uid], 'i');
    return array_map(function ($r) {
        return ['key' => $r['chiave'], 'title' => $r['title'],
                'category_id' => $r['cat_id'] !== null ? (int)$r['cat_id'] : null,
                'price_cents' => $r['cents'] !== null ? (int)$r['cents'] : null,
                'times_bought' => (int)$r['uses'], 'last_bought' => $r['last_used']];
    }, $righe);
}

/* ===================== Categorie ===================== */

/** Una categoria dell'utente come la vede l'app, o null. */
function sp_cat_riga(int $uid, int $id): ?array {
    foreach (td_categories($uid) as $c) if ($c['id'] === $id) return $c;
    return null;
}

/**
 * Nome e colore. Il nome e' unico per utente (senza badare alle maiuscole: lo
 * decide la collazione della colonna); la pagina, davanti a un nome gia' preso,
 * tiene quello esistente, l'app invece risponde con un errore perche' il client
 * sappia cos'e' successo.
 */
function sp_cat_valida(int $uid, array $c, bool $creazione, ?int $id): array {
    $campi = [];
    if (array_key_exists('name', $c)) {
        if (!is_string($c['name'])) return td_api_errore('validation', 'name', 'name must be text');
        $n = sec_str($c['name'], 60);
        if ($n === '') return td_api_errore('validation', 'name', 'name cannot be empty');
        if (mb_strlen(trim($c['name'])) > 60) return td_api_errore('validation', 'name', 'name can be at most 60 characters');
        $doppia = sec_one("SELECT id FROM todo_buy_categories WHERE user_id = ? AND name = ? AND id <> ?", [$uid, $n, (int)$id], 'isi');
        if ($doppia) return td_api_errore('validation', 'name', 'A category with this name already exists');
        $campi['name'] = $n;
    } elseif ($creazione) {
        return td_api_errore('validation', 'name', 'name is required');
    }
    if (array_key_exists('color', $c)) {
        if (!is_string($c['color']) || !preg_match('/^#([0-9A-Fa-f]{3}|[0-9A-Fa-f]{6})$/', $c['color'])) {
            return td_api_errore('validation', 'color', 'color must look like #RRGGBB');
        }
        $campi['color'] = sec_color($c['color']);
    }
    return ['campi' => $campi];
}

function sp_cat_crea(int $uid, array $c): array {
    $v = sp_cat_valida($uid, $c, true, null);
    if (isset($v['errore'])) return $v;
    $n = sec_one("SELECT COUNT(*) AS n FROM todo_buy_categories WHERE user_id = ?", [$uid], 'i');
    if ((int)($n['n'] ?? 0) >= SP_MAX_CATEGORIE) return td_api_errore('validation', 'name', 'Too many categories (at most ' . SP_MAX_CATEGORIE . ')');
    sec_q("INSERT INTO todo_buy_categories (user_id, name, color, sort_order)
           VALUES (?,?,?,(SELECT COALESCE(MAX(x.so),0)+1 FROM (SELECT sort_order AS so FROM todo_buy_categories WHERE user_id=?) x))",
          [$uid, $v['campi']['name'], $v['campi']['color'] ?? '#C8DAD3', $uid], 'issi');
    return ['category' => sp_cat_riga($uid, sec_insert_id())];
}

function sp_cat_modifica(int $uid, int $id, array $c): array {
    if (!sp_cat_riga($uid, $id)) return td_api_errore('not_found', 'id', 'Category not found');
    $v = sp_cat_valida($uid, $c, false, $id);
    if (isset($v['errore'])) return $v;
    if (!$v['campi']) return td_api_errore('validation', 'body', 'Nothing to update');
    $set = []; $par = [];
    foreach ($v['campi'] as $col => $val) { $set[] = "`$col` = ?"; $par[] = $val; }
    $par[] = $id; $par[] = $uid;
    sec_q("UPDATE todo_buy_categories SET " . implode(', ', $set) . " WHERE id = ? AND user_id = ?", $par);
    return ['category' => sp_cat_riga($uid, $id)];
}

/** Come la pagina: gli articoli restano, senza categoria. */
function sp_cat_elimina(int $uid, int $id): bool {
    if (!sp_cat_riga($uid, $id)) return false;
    sec_q("UPDATE todos SET buy_category_id=NULL WHERE user_id=? AND buy_category_id=?", [$uid, $id], 'ii');
    sec_q("DELETE FROM todo_buy_categories WHERE id=? AND user_id=?", [$id, $uid], 'ii');
    return true;
}
