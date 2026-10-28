<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/security_core.php';
$conn = mysqli_connect(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
if (!$conn) { error_log('[standout:db] ' . mysqli_connect_error()); die(t('comune.errore_generico')); } /* il motivo nel log, non a schermo */
require_once __DIR__ . '/../../lib/auth_check.php';
$current_user_id = $utente_corrente['id'];

/* ==========================================================================
   EREDITARIETÀ COLORE — identica a fc_decks.php, tabella progetti
   ========================================================================== */
/* ==========================================================================
   NOTA SCHEMA: collegamento 1 a 1 tra un progetto e una materia di
   categories_fc, se non l'hai ancora creata:

   CREATE TABLE progetto_categoria_fc (
       project_id INT NOT NULL,
       fc_category_id INT NOT NULL,
       creato_il DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
       PRIMARY KEY (project_id),
       UNIQUE KEY uniq_fc_category (fc_category_id),
       CONSTRAINT fk_pcf_progetto FOREIGN KEY (project_id) REFERENCES progetti(id) ON DELETE CASCADE,
       CONSTRAINT fk_pcf_categoria FOREIGN KEY (fc_category_id) REFERENCES fc_study_decks(id) ON DELETE CASCADE
   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

   project_id come PRIMARY KEY + fc_category_id UNIQUE = un progetto ha al
   massimo una materia collegata, e una materia è collegata al massimo a un
   progetto: è un vero rapporto 1 a 1 in entrambe le direzioni.
   ========================================================================== */
if (!function_exists('prog_resolve_color')) {
    /* Ripulisce una nota senza mangiarsela.
 *
 * IL PROBLEMA. Prima passava da sec_text(), che chiama strip_tags(): quella
 * butta via TUTTO quello che sta fra un `<` e un `>`. Scrivendo
 * «hello <<ciao>>» si ritrovava «hello», e — molto peggio, perché capita più
 * spesso e non si nota — «5 < 7 e 9 > 3» diventava «5  3». Qualunque nota con
 * una disuguaglianza, una freccia disegnata a mano o due parentesi angolari
 * perdeva pezzi in silenzio.
 *
 * LA REGOLA NUOVA. Si toglie solo quello che è inequivocabilmente un tag HTML:
 *   - un nome di elemento HTML vero (<b>, <script>, </div>…), perché la nota
 *     finisce dentro un renderer che potrebbe interpretarlo;
 *   - qualunque cosa fra parentesi angolari che contenga uno spazio o un `=`,
 *     cioè che abbia degli attributi: è lì che vivono `onerror=`, `src=` e
 *     tutti i modi di far eseguire qualcosa.
 * Tutto il resto resta: «<ciao>» non è un elemento HTML e non ha attributi,
 * quindi è testo, e come testo va trattato.
 *
 * Non si escapa in `&lt;` perché la nota viene mostrata da renderer diversi —
 * alcuni la stampano come testo — e in quelli si vedrebbe scritto «&lt;».
 */
/* C'è qualcosa da leggere nella nota? Spazi, a capo, spazi non separabili,
   caratteri a larghezza zero e segni di formattazione senza testo dentro non
   contano: una nota così si disegnava come un riquadro vuoto, e al posto di
   «Aggiungi una nota» restava un buco. */
/* L'ULTIMO PROGETTO APERTO, letto dal cookie che scrive la pagina stessa.
   Prima lo ricordava solo il browser (localStorage), e lo si poteva leggere
   solo a pagina gia' disegnata: si vedeva il primo progetto e subito dopo la
   pagina saltava sull'ultimo. Con il cookie il server disegna subito quello
   giusto. Vale solo se l'indirizzo non chiede gia' un progetto o una vista. */
function pr_id_ricordato(): int {
    if (isset($_GET['id']) || (string)($_GET['view'] ?? '') !== '') return 0;
    return max(0, (int)sec_int($_COOKIE['pr_last_project'] ?? 0, 0));
}

function prog_nota_vuota($s): bool {
    /* Il formato dell'editor e' un documento JSON ({"v":2,"blocks":[…]}): un
       documento senza testo ne' formule ha comunque dei caratteri (le parentesi,
       i nomi dei campi), quindi per le regole sotto sembrava una nota piena.
       Risultato: una nota svuotata restava «piena» e la pagina mostrava un
       riquadro vuoto, che si correggeva solo dopo che il JavaScript l'aveva
       disegnata. Qui si guarda cosa c'e' DENTRO il documento. */
    if (is_string($s) && isset($s[0]) && $s[0] === '{') {
        $j = json_decode($s, true);
        if (is_array($j) && isset($j['blocks']) && is_array($j['blocks'])) {
            $invisibili = '/[\s\x{00A0}\x{1680}\x{2000}-\x{200F}\x{2028}\x{2029}\x{202F}\x{205F}\x{2060}\x{3000}\x{FEFF}]+/u';
            foreach ($j['blocks'] as $b) {
                if (!is_array($b)) continue;
                if (($b['t'] ?? '') === 'fx' && trim((string)($b['tex'] ?? '')) !== '') return false;
                foreach (($b['runs'] ?? []) as $r) {
                    if (!is_array($r)) continue;
                    foreach (['s', 'x'] as $k) {
                        if (isset($r[$k]) && is_string($r[$k]) && preg_replace($invisibili, '', $r[$k]) !== '') return false;
                    }
                }
            }
            return true;
        }
    }
    $t = html_entity_decode(strip_tags((string)$s), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $t = preg_replace('/[\s\x{00A0}\x{1680}\x{2000}-\x{200F}\x{2028}\x{2029}\x{202F}\x{205F}\x{2060}\x{3000}\x{FEFF}]+/u', '', $t);
    if ($t === null) return false;
    $t = preg_replace('/[*_~`#>|=\-\[\]{}$\\\\]+/u', '', $t);
    if ($t === null) return false;
    // se una regex fallisse (null), meglio mostrare la nota che nasconderla
    return $t === '';
}
function prog_nota_pulita($grezza, int $max): string {
    $t = (string)$grezza;

    // i nomi di elemento veri: l'elenco è lungo ma è l'unico modo di
    // distinguere <b> da <ciao> senza buttare via il secondo
    $tagHtml = 'a|abbr|address|area|article|aside|audio|b|base|bdi|bdo|big|blockquote|body|br|button|canvas|caption|center|cite|code|col|colgroup|data|datalist|dd|del|details|dfn|dialog|div|dl|dt|em|embed|fieldset|figcaption|figure|font|footer|form|h1|h2|h3|h4|h5|h6|head|header|hr|html|i|iframe|img|input|ins|kbd|label|legend|li|link|main|map|mark|menu|meta|meter|nav|noscript|object|ol|optgroup|option|output|p|param|picture|pre|progress|q|rp|rt|ruby|s|samp|script|section|select|slot|small|source|span|strike|strong|style|sub|summary|sup|svg|table|tbody|td|template|textarea|tfoot|th|thead|time|title|tr|track|u|ul|var|video|wbr';

    // 1. i tag HTML veri, aperti o chiusi
    $t = preg_replace('#</?(?:' . $tagHtml . ')\b[^>]*>#i', '', $t);
    /* 2. qualunque cosa che COMINCI come un tag e abbia degli attributi:
          <x onerror=…>, <qualsiasi src="">. Due vincoli, non uno: deve
          cominciare con una lettera E contenere un `=`. Il primo salva
          «5 < 7 e 9 > 3» e «a <- b -> c», il secondo salva «x<y e y>z» — che
          senza l'uguale è una disuguaglianza, non un tag con attributi.
          Resta un caso perso, «a < b = c > d»: si può scrivere solo così o
          come un tag, e fra le due ho scelto di non far passare il tag. */
    $t = preg_replace('#</?[A-Za-z][^<>]*=[^<>]*>#', '', $t);
    // e la forma che si chiude da sola: <qualcosa />
    $t = preg_replace('#<[A-Za-z][^<>]*/>#', '', $t);
    // 3. i commenti HTML, che non hanno un nome di elemento
    $t = preg_replace('#<!--.*?-->#s', '', $t);

    // caratteri di controllo via, a capo e tabulazioni no: servono
    $t = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $t);
    $t = trim($t);
    // una nota fatta solo di spazi, a capo o caratteri invisibili è una nota vuota
    if (prog_nota_vuota($t)) return '';
    if (function_exists('mb_substr')) {
        if (mb_strlen($t, 'UTF-8') > $max) $t = mb_substr($t, 0, $max, 'UTF-8');
    } elseif (strlen($t) > $max) {
        $t = substr($t, 0, $max);
    }
    return $t;
}

function prog_resolve_color($parentId, $userId) {
        $parentId = intval($parentId);
        $guard = 0;
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
if (!function_exists('prog_cascade_color')) {
    function prog_cascade_color($projectId, $color, $userId, $depth = 0) {
        if ($depth > 40) return;
        $figli = sec_all("SELECT id FROM projects WHERE parent_id = ? AND user_id = ? AND color_edited = 0",
                         [(int)$projectId, (int)$userId]);
        foreach ($figli as $row) {
            /* aggiunto AND user_id: gli id arrivano gia' da una query filtrata per
               utente, ma una UPDATE senza quel vincolo e' il tipo di riga che, spostata
               altrove per un refactoring, diventa una falla senza che nessuno se ne
               accorga. */
            sec_q("UPDATE projects SET color=? WHERE id=? AND user_id=?",
                  [$color, (int)$row['id'], (int)$userId]);
            prog_cascade_color($row['id'], $color, $userId, $depth + 1);
        }
    }
}
if (!function_exists('prog_children_map')) {
    // id -> parent_id (0 se radice) per tutti i progetti dell'utente: la base per
    // qualunque calcolo di sotto-albero (usata da delete, bulk_delete, bulk_reparent).
    function prog_children_map($userId) {
        $childrenOf = [];
        foreach (sec_all("SELECT id, parent_id FROM projects WHERE user_id = ?", [(int)$userId]) as $row) {
            $pid = $row['parent_id'] === null ? 0 : (int)$row['parent_id'];
            $childrenOf[$pid][] = (int)$row['id'];
        }
        return $childrenOf;
    }
}
if (!function_exists('prog_subtree_ids')) {
    // $rootId e tutti i suoi discendenti, appiattiti in un unico array.
    function prog_subtree_ids($rootId, &$childrenOf) {
        $all = []; $stack = [(int)$rootId];
        while (!empty($stack)) {
            $cur = array_pop($stack);
            $all[] = $cur;
            foreach (($childrenOf[$cur] ?? []) as $c) { $stack[] = $c; }
        }
        return $all;
    }
}

/* ==========================================================================
   AJAX leggero: toggle stato checkbox (niente reload, per una UI reattiva)
   ========================================================================== */
/* Nessuna scrittura senza il token della sessione e senza origine nostra. */
if (isset($_GET['ajax']) && strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    sec_guard_write((int)($utente_corrente['id'] ?? 0), 'progetti', 0, 400, 60);
}
/* Con GET si può solo leggere l'albero dei sotto-progetti: il resto è POST. */
if (isset($_GET['ajax'])) sec_solo_post_se_scrive(sec_str($_GET['ajax'], 30), ['project_ids_subtree']);

/* Preferiti: la stellina nella modifica progetto. I preferiti compaiono in
   cima al selettore progetti e nel tastino a stella del planner. */
/* ============================================================
   LE IMPOSTAZIONI DELLA PAGINA
   Come mostrare i progetti e' una decisione che si prende una volta. Vive nel
   database e non nel browser: chi apre da telefono e da computer deve vedere
   la stessa pagina, e una preferenza dimenticata a ogni cambio di dispositivo
   e' una preferenza che nessuno imposta.
   ============================================================ */
function prog_opzioni(int $uid): array {
    $r = sec_one("SELECT progetti_opzioni FROM planner_settings WHERE user_id = ?", [$uid], 'i');
    $d = ($r && !empty($r['progetti_opzioni'])) ? json_decode((string)$r['progetti_opzioni'], true) : null;
    if (!is_array($d)) $d = [];
    return [
        /* I valori di partenza sono quelli che la pagina aveva prima che le
           impostazioni esistessero: chi non apre mai il pannello non deve
           accorgersi che e' cambiato qualcosa. */
        'completati'   => isset($d['completati'])   ? (int)!!$d['completati']   : 1,
        'ordine'       => in_array(($d['ordine'] ?? ''), ['manuale','alfabetico','recenti','scadenza'], true) ? $d['ordine'] : 'manuale',
        'ricorda_rami' => isset($d['ricorda_rami']) ? (int)!!$d['ricorda_rami'] : 0,
        'mostra_tag'   => isset($d['mostra_tag'])   ? (int)!!$d['mostra_tag']   : 1,
        'solo_attivi'  => isset($d['solo_attivi'])  ? (int)!!$d['solo_attivi']  : 0,
    ];
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'salva_opzioni') {
    header('Content-Type: application/json');
    $in = $_POST['opzioni'] ?? '';
    $d = is_string($in) ? json_decode($in, true) : null;
    if (!is_array($d)) { http_response_code(422); die(json_encode(['ok' => false])); }
    /* Si riscrive solo quello che conosciamo, non quello che arriva: un client
       modificato a mano potrebbe spedire un JSON di qualunque forma, e questa
       colonna la rileggiamo noi a ogni apertura. */
    $pulito = [
        'completati'   => (int)!!($d['completati'] ?? 1),
        'ordine'       => in_array(($d['ordine'] ?? ''), ['manuale','alfabetico','recenti','scadenza'], true) ? $d['ordine'] : 'manuale',
        'ricorda_rami' => (int)!!($d['ricorda_rami'] ?? 0),
        'mostra_tag'   => (int)!!($d['mostra_tag'] ?? 1),
        'solo_attivi'  => (int)!!($d['solo_attivi'] ?? 0),
    ];
    sec_q("INSERT INTO planner_settings (user_id, progetti_opzioni) VALUES (?, ?)
           ON DUPLICATE KEY UPDATE progetti_opzioni = VALUES(progetti_opzioni)",
          [(int)$utente_corrente['id'], json_encode($pulito, JSON_UNESCAPED_UNICODE)], 'is');
    die(json_encode(['ok' => true, 'opzioni' => $pulito]));
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'salva_nota') {
    header('Content-Type: application/json');
    $uid = (int)$utente_corrente['id'];
    $pid = sec_int($_POST['project_id'] ?? 0, 1);
    if ($pid <= 0 || !sec_owns('projects', $pid, $uid)) {
        http_response_code(404); die(json_encode(['ok' => false]));
    }
    // stessa pulizia del salvataggio dal modulo: una sola regola per le note,
    // o scrivendole da due posti si comporterebbero in due modi
    $nota = prog_nota_pulita($_POST['note'] ?? '', 8000);
    sec_q("UPDATE projects SET notes = ? WHERE id = ? AND user_id = ?", [$nota, $pid, $uid], 'sii');
    die(json_encode(['ok' => true, 'note' => $nota]));
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'rinomina') {
    header('Content-Type: application/json');
    $pid = sec_int($_POST['project_id'] ?? 0, 1);
    $nome = sec_str($_POST['name'] ?? '', 200);
    if ($pid <= 0 || !sec_owns('projects', $pid, (int)$utente_corrente['id'])) {
        http_response_code(404); die(json_encode(['ok' => false]));
    }
    // un progetto senza nome non esiste: il browser lo impedisce già, qui si
    // ricontrolla perché quel controllo si aggira
    if ($nome === '') { http_response_code(422); die(json_encode(['ok' => false])); }
    sec_q("UPDATE projects SET name = ? WHERE id = ? AND user_id = ?",
          [$nome, $pid, (int)$utente_corrente['id']], 'sii');
    die(json_encode(['ok' => true, 'name' => $nome]));
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'collega_deck') {
    header('Content-Type: application/json');
    $uid = (int)$utente_corrente['id'];
    $pid = sec_int($_POST['project_id'] ?? 0, 1);
    $cat = sec_int($_POST['fc_category_id'] ?? 0, 0);
    if ($pid <= 0 || !sec_owns('projects', $pid, $uid)) {
        http_response_code(404); die(json_encode(['ok' => false]));
    }
    /* Il collegamento è uno solo per progetto: si cancella e si riscrive
       invece di aggiornare, perché la riga può non esistere ancora e due
       strade (INSERT o UPDATE, a seconda) sono due posti dove sbagliare. */
    sec_q("DELETE FROM fc_study_deck_projects WHERE project_id = ?", [$pid], 'i');
    if ($cat > 0) {
        $ok = sec_one("SELECT id FROM fc_study_decks WHERE id = ? AND user_id = ?", [$cat, $uid], 'ii');
        if ($ok) sec_q("INSERT INTO fc_study_deck_projects (project_id, fc_category_id) VALUES (?, ?)", [$pid, $cat], 'ii');
    }
    die(json_encode(['ok' => true]));
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'salva_data') {
    header('Content-Type: application/json');
    $uid = (int)$utente_corrente['id'];
    $pid = sec_int($_POST['project_id'] ?? 0, 1);
    $campo = ($_POST['campo'] ?? '') === 'scadenza' ? 'due_date' : 'target_date';
    $val = sec_date($_POST['valore'] ?? '', '');
    if ($pid <= 0 || !sec_owns('projects', $pid, $uid)) {
        http_response_code(404); die(json_encode(['ok' => false]));
    }
    // stringa vuota = togliere la data: il campo nativo la restituisce così
    // quando si cancella, e va scritta come NULL non come ''
    sec_q("UPDATE projects SET $campo = ? WHERE id = ? AND user_id = ?",
          [($val === '' ? null : $val), $pid, $uid], 'sii');
    die(json_encode(['ok' => true]));
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'toggle_todo') {
    header('Content-Type: application/json');
    $pid = sec_int($_POST['project_id'] ?? 0, 1);
    $val = sec_bool($_POST['value'] ?? 0) ? 1 : 0;
    if ($pid <= 0 || !sec_owns('projects', $pid, (int)$utente_corrente['id'])) {
        http_response_code(404);
        die(json_encode(['ok' => false, 'error' => 'not_found']));
    }
    sec_q("UPDATE projects SET todo_enabled = ? WHERE id = ? AND user_id = ?",
          [$val, $pid, (int)$utente_corrente['id']], 'iii');
    die(json_encode(['ok' => true, 'enabled' => $val === 1]));
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'toggle_favorite') {
    header('Content-Type: application/json');
    $pid = sec_int($_POST['project_id'] ?? 0, 1);
    $val = sec_bool($_POST['value'] ?? 0) ? 1 : 0;
    if ($pid <= 0 || !sec_owns('projects', $pid, (int)$utente_corrente['id'])) {
        http_response_code(404);
        die(json_encode(['ok' => false, 'error' => 'not_found']));
    }
    sec_q("UPDATE projects SET is_favorite = ? WHERE id = ? AND user_id = ?",
          [$val, $pid, (int)$utente_corrente['id']], 'iii');
    die(json_encode(['ok' => true, 'favorite' => $val === 1]));
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'toggle_stato') {
    header('Content-Type: application/json');
    $pid = sec_int($_POST['project_id'] ?? 0, 1);
    if (!sec_owns('projects', $pid, (int)$current_user_id)) { echo json_encode(['success' => false]); exit(); }
    $row = sec_one("SELECT status FROM projects WHERE id=? AND user_id=?", [$pid, (int)$current_user_id]);
    if (!$row) { echo json_encode(['success' => false]); exit(); }
    $prevStatus = $row['status'];
    $order = ['in_progress', 'completed', 'planned'];
    $cur = array_search($prevStatus, $order);
    $next = $order[($cur === false ? 0 : $cur + 1) % 3];
    sec_q("UPDATE projects SET status=? WHERE id=? AND user_id=?", [$next, $pid, (int)$current_user_id]);

    $cascaded = [];   // id resi completati a cascata (per aggiornare la UI)
    $snapshot = [];   // stato PRECEDENTE dei discendenti, per poterli ripristinare
    $restored = [];   // id ripristinati (id => nuovo stato)

    if ($next === 'completed') {
        // entriamo in "completato": completa tutti i discendenti, ma prima
        // salviamo il loro stato attuale così, se l'utente si accorge dell'errore
        // e cambia subito stato, possiamo rimetterli com'erano (richiesta cache).
        $childrenOf = prog_children_map($current_user_id);
        $subtree = prog_subtree_ids($pid, $childrenOf);
        $others = array_values(array_filter($subtree, function ($x) use ($pid) { return $x !== $pid; }));
        if (!empty($others)) {
            $ph = implode(',', array_fill(0, count($others), '?'));
            $par = array_merge([(int)$current_user_id], $others);
            foreach (sec_all("SELECT id, status FROM projects WHERE user_id=? AND id IN ($ph)", $par) as $srow) {
                $snapshot[] = ['id' => (int)$srow['id'], 'status' => $srow['status']];
            }
            sec_q("UPDATE projects SET status='completed' WHERE user_id=? AND id IN ($ph)", $par);
            $cascaded = $others;
        }
    } elseif ($prevStatus === 'completed') {
        // stiamo USCENDO da "completato": se il client ci ha passato lo snapshot
        // salvato al momento del completamento, rimettiamo i discendenti com'erano.
        $restoreRaw = $_POST['restore'] ?? '';
        if ($restoreRaw !== '') {
            $decoded = json_decode($restoreRaw, true);
            if (is_array($decoded)) {
                foreach ($decoded as $item) {
                    $rid = intval($item['id'] ?? 0);
                    $rst = $item['status'] ?? '';
                    if ($rid > 0 && in_array($rst, $order, true)) {
                        sec_q("UPDATE projects SET status=? WHERE id=? AND user_id=?",
                              [$rst, $rid, (int)$current_user_id]);
                        $restored[$rid] = $rst;
                    }
                }
            }
        }
    }
    echo json_encode(['success' => true, 'stato' => $next, 'cascaded' => $cascaded, 'snapshot' => $snapshot, 'restored' => $restored]);
    exit();
}

/* ==========================================================================
   AJAX leggero: totale secondi pomodoro per un progetto + discendenti
   ========================================================================== */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'project_ids_subtree') {
    header('Content-Type: application/json');
    $rootId = sec_int($_GET['id'] ?? 0, 0);
    $all = [];
    $childrenOf = [];
    foreach (sec_all("SELECT id, parent_id FROM projects WHERE user_id = ?", [(int)$current_user_id]) as $row) {
        $pid = $row['parent_id'] === null ? 0 : (int)$row['parent_id'];
        $childrenOf[$pid][] = (int)$row['id'];
    }
    $stack = [$rootId];
    while (!empty($stack)) {
        $cur = array_pop($stack);
        $all[] = $cur;
        foreach (($childrenOf[$cur] ?? []) as $c) $stack[] = $c;
    }
    echo json_encode(['ids' => $all]);
    exit();
}

if (!function_exists('prog_category_link_blocked')) {
    // true se $categoryId, un suo discendente o un suo antenato sono già
    // collegati a un progetto DIVERSO da $excludeProjectId (controllo di
    // sicurezza lato server: la UI disabilita già queste materie, ma un
    // salvataggio non deve mai fidarsi solo del client)
    function prog_category_link_blocked($userId, $categoryId, $excludeProjectId) {
        $categoryId = intval($categoryId);
        if ($categoryId <= 0) return false;
        $parentOf = []; $childrenOf = [];
        foreach (sec_all("SELECT id, parent_id FROM fc_study_decks WHERE user_id=?", [(int)$userId]) as $row) {
            $id = (int)$row['id'];
            $pid = $row['parent_id'] === null ? 0 : (int)$row['parent_id'];
            $parentOf[$id] = $pid;
            $childrenOf[$pid][] = $id;
        }
        $chain = [$categoryId];
        $walk = $parentOf[$categoryId] ?? 0; $guard = 0;
        while ($walk > 0 && $guard < 100) { $chain[] = $walk; $walk = $parentOf[$walk] ?? 0; $guard++; }
        $stack = $childrenOf[$categoryId] ?? []; $guard2 = 0;
        while (!empty($stack) && $guard2 < 5000) {
            $guard2++;
            $cur = array_pop($stack);
            $chain[] = $cur;
            foreach (($childrenOf[$cur] ?? []) as $c) { $stack[] = $c; }
        }
        $chain = array_values(array_unique($chain));
        $ph = implode(',', array_fill(0, count($chain), '?'));
        $riga = sec_one("SELECT project_id FROM fc_study_deck_projects WHERE fc_category_id IN ($ph) AND project_id != ? LIMIT 1",
                        array_merge($chain, [(int)$excludeProjectId]));
        return $riga !== null;
    }
}

/* ==========================================================================
   AZIONI POST classiche (form nascosti + reload), come in fc_decks.php
   ========================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    /* Anche i moduli classici (salva, elimina, riordina) sono scritture:
       il token arriva nel body come csrf_token, aggiunto da standout_fetch.js,
       oppure nell'header X-CSRF-Token. Senza, la richiesta si ferma qui. */
    sec_guard_write((int)($utente_corrente['id'] ?? 0), 'progetti_form', 0, 200, 60);

    /* elenco chiuso: un'azione non prevista non deve arrivare fino agli if */
    $action = sec_enum($_POST['action'] ?? null,
                       ['save', 'delete', 'bulk_delete', 'bulk_set_color', 'bulk_reparent', 'reorder'], '');
    $redirectId = sec_int($_POST['redirect_id'] ?? 0, 0);

    if ($action === 'save') {
        $progId = sec_int($_POST['project_id'] ?? 0, 0);
        /* sec_str toglie tag e caratteri di controllo, cose che trim non faceva;
           sec_text conserva gli a capo della nota ma toglie i tag; sec_date
           rifiuta le date inesistenti (il 31 febbraio passava). */
        $name = sec_str($_POST['progetto_name'] ?? '', 200);
        $note = prog_nota_pulita($_POST['progetto_note'] ?? '', 8000);
        $parentId = sec_int($_POST['parent_id'] ?? 0, 0);
        $colorMode = sec_enum($_POST['color_mode'] ?? null, ['auto', 'manual'], 'auto');
        $manualColor = sec_str($_POST['progetto_color'] ?? '', 7);
        $dataObiettivo = sec_date($_POST['data_obiettivo'] ?? '', '') ?? '';
        $dataScadenza = sec_date($_POST['data_scadenza'] ?? '', '') ?? '';

        if ($parentId === $progId) $parentId = 0; 

        if ($progId > 0 && $parentId > 0) {
            $walk = $parentId; $guard = 0; $cycle = false;
            while ($walk > 0 && $guard < 60) {
                $guard++;
                if ($walk === $progId) { $cycle = true; break; }
                $arow = sec_one("SELECT parent_id FROM projects WHERE id=? AND user_id=?", [$walk, (int)$current_user_id]);
                if (!$arow) break;
                $walk = $arow['parent_id'] === null ? 0 : (int)$arow['parent_id'];
            }
            if ($cycle) {
                $curRow = sec_one("SELECT parent_id FROM projects WHERE id=? AND user_id=?", [$progId, (int)$current_user_id]);
                $parentId = ($curRow && $curRow['parent_id'] !== null) ? (int)$curRow['parent_id'] : 0;
            }
        }
        /* valori veri, non pezzi di SQL: con i segnaposto il NULL si passa come
           null di PHP, non come la stringa 'NULL' */
        $parentVal = $parentId > 0 ? $parentId : null;
        $dataObVal = ($dataObiettivo !== '') ? $dataObiettivo : null;
        $dataScVal = ($dataScadenza !== '') ? $dataScadenza : null;

        if ($colorMode === 'manual' && preg_match('/^#[0-9A-Fa-f]{6}$/', $manualColor)) {
            $finalColor = $manualColor;
            $colorEditedFlag = 1;
        } else {
            $finalColor = prog_resolve_color($parentId, $current_user_id);
            $colorEditedFlag = 0;
        }

        if ($progId === 0 && $name !== '') {
            /* Il nuovo sotto-progetto nasce PRIMO, non ultimo.
               Il campo per scriverne il nome compare in cima all'elenco: dopo
               averlo confermato lo si cercava con gli occhi dove lo si era
               scritto e lo si trovava in fondo, magari sotto altre venti
               righe. Nascere dove lo si è scritto è la sola cosa che non
               sorprende — e da lì lo si trascina dove serve, ora che
               trascinare è comodo.
               Si spostano gli altri di uno invece di usare un numero negativo:
               i negativi funzionerebbero una volta sola, e alla seconda
               aggiunta il penultimo tornerebbe davanti all'ultimo.
               "parent_id IS NULL" non si puo' scrivere come "parent_id=?" con
               null: in SQL il confronto con NULL non e' mai vero. Servono due
               forme di query. */
            /* «In fondo»: quando lo si scrive dalla riga «Aggiungi» che sta in
               fondo all'elenco, nasce lì, sotto gli altri — è dove lo si è
               scritto, per la stessa ragione di sopra. */
            $inFondo = ($_POST['posizione'] ?? '') === 'fine';
            if ($inFondo) {
                $ult = $parentId > 0
                    ? sec_one("SELECT MAX(custom_order) AS m FROM projects WHERE user_id=? AND parent_id=?", [(int)$current_user_id, $parentId])
                    : sec_one("SELECT MAX(custom_order) AS m FROM projects WHERE user_id=? AND parent_id IS NULL", [(int)$current_user_id]);
                $nextOrder = ($ult && $ult['m'] !== null) ? (int)$ult['m'] + 1 : 0;
            } else {
                if ($parentId > 0) {
                    sec_q("UPDATE projects SET custom_order = custom_order + 1 WHERE user_id=? AND parent_id=?",
                          [(int)$current_user_id, $parentId]);
                } else {
                    sec_q("UPDATE projects SET custom_order = custom_order + 1 WHERE user_id=? AND parent_id IS NULL",
                          [(int)$current_user_id]);
                }
                $nextOrder = 0;
            }
            sec_q("INSERT INTO projects (user_id, parent_id, name, notes, color, color_edited, status, custom_order, created_at, target_date, due_date)
                   VALUES (?, ?, ?, ?, ?, ?, 'in_progress', ?, NOW(), ?, ?)",
                  [(int)$current_user_id, $parentVal, $name, $note, $finalColor, $colorEditedFlag, $nextOrder, $dataObVal, $dataScVal]);
            $redirectId = sec_insert_id();
            $progId = (int)$redirectId;
        } elseif ($progId > 0 && $name !== '') {
            sec_q("UPDATE projects SET name=?, notes=?, parent_id=?, color=?, color_edited=?,
                   target_date=?, due_date=? WHERE id=? AND user_id=?",
                  [$name, $note, $parentVal, $finalColor, $colorEditedFlag, $dataObVal, $dataScVal, $progId, (int)$current_user_id]);
            // cascata sempre: sia che il colore sia stato scelto a mano, sia che sia
            // automatico (in quel caso $finalColor è già quello del NUOVO padre, così
            // spostando un progetto a colore automatico anche i figli auto lo seguono)
            prog_cascade_color($progId, $finalColor, $current_user_id);
            $redirectId = $redirectId ?: $progId;
        }

        if ($progId > 0 && $name !== '') {
            $fcCategoryId = sec_int($_POST['fc_category_id'] ?? 0, 0);
            if ($fcCategoryId > 0) {
                $catOwn = sec_one("SELECT id FROM fc_study_decks WHERE id=? AND user_id=?", [$fcCategoryId, (int)$current_user_id]);
                if ($catOwn && !prog_category_link_blocked($current_user_id, $fcCategoryId, $progId)) {
                    // materia libera (lei, i suoi figli e i suoi padri non sono di un altro progetto): ok collegare
                    sec_q("DELETE FROM fc_study_deck_projects WHERE project_id=?", [$progId]);
                    sec_q("INSERT INTO fc_study_deck_projects (project_id, fc_category_id) VALUES (?, ?)", [$progId, $fcCategoryId]);
                }
                // se bloccata, ignoriamo silenziosamente la richiesta: la UI non avrebbe dovuto permetterla
            } else {
                sec_q("DELETE FROM fc_study_deck_projects WHERE project_id=?", [$progId]);
            }
        }
    } elseif ($action === 'delete') {
        $progId = sec_int($_POST['project_id'] ?? 0, 1);
        $deleteSessions = sec_bool($_POST['delete_sessions'] ?? false);
        // dove tornare dopo (richiesta 3: restare sul progetto che si stava guardando).
        // se stai eliminando proprio quel progetto, ripiega sul suo padre.
        $parentOfDeleted = 0;
        $pRow = ($progId > 0 && sec_owns('projects', $progId, (int)$current_user_id))
                  ? sec_one("SELECT parent_id FROM projects WHERE id=? AND user_id=?", [$progId, (int)$current_user_id]) : null;
        $own = ($pRow !== null);
        if ($pRow) $parentOfDeleted = $pRow['parent_id'] === null ? 0 : (int)$pRow['parent_id'];
        if ($own) {
            if ($deleteSessions) {
                $childrenOf = prog_children_map($current_user_id);
                $subtree = prog_subtree_ids($progId, $childrenOf);
                if (!empty($subtree)) {
                    $ph = implode(',', array_fill(0, count($subtree), '?'));
                    sec_q("DELETE FROM pomodoro_sessions WHERE user_id = ? AND project_id IN ($ph)",
                          array_merge([(int)$current_user_id], $subtree));
                }
            }
            sec_q("DELETE FROM projects WHERE id=? AND user_id=?", [$progId, (int)$current_user_id]);
        }
        if ($redirectId === $progId || $redirectId === 0) $redirectId = $parentOfDeleted;
    } elseif ($action === 'bulk_delete') {
        // stessa logica del delete singolo, ma su piu' progetti fratelli in un colpo
        // solo: essendo fratelli i loro sotto-alberi non si sovrappongono mai.
        $ids = array_slice(array_values(array_unique(array_filter(array_map(
                   function ($x) { return sec_int($x, 1); },
                   explode(',', (string)($_POST['project_ids'] ?? '')))))), 0, 500);
        $deleteSessions = sec_bool($_POST['delete_sessions'] ?? false);
        if (!empty($ids)) {
            $phIds = implode(',', array_fill(0, count($ids), '?'));
            $ownIds = [];
            foreach (sec_all("SELECT id FROM projects WHERE user_id = ? AND id IN ($phIds)",
                             array_merge([(int)$current_user_id], $ids)) as $row) { $ownIds[] = (int)$row['id']; }
            if (!empty($ownIds)) {
                if ($deleteSessions) {
                    $childrenOf = prog_children_map($current_user_id);
                    $subtree = [];
                    foreach ($ownIds as $rootId) { $subtree = array_merge($subtree, prog_subtree_ids($rootId, $childrenOf)); }
                    if (!empty($subtree)) {
                        $ph = implode(',', array_fill(0, count($subtree), '?'));
                        sec_q("DELETE FROM pomodoro_sessions WHERE user_id = ? AND project_id IN ($ph)",
                              array_merge([(int)$current_user_id], $subtree));
                    }
                }
                $phOwn = implode(',', array_fill(0, count($ownIds), '?'));
                sec_q("DELETE FROM projects WHERE user_id = ? AND id IN ($phOwn)",
                      array_merge([(int)$current_user_id], $ownIds));
            }
        }
    } elseif ($action === 'bulk_set_color') {
        // "auto" azzera color_edited e ricalcola dal padre ATTUALE di ciascun progetto
        // (letto dal DB, mai dal client); un colore vero e proprio lo marca manuale.
        $ids = array_slice(array_values(array_unique(array_filter(array_map(
                   function ($x) { return sec_int($x, 1); },
                   explode(',', (string)($_POST['project_ids'] ?? '')))))), 0, 500);
        $colorInput = sec_str($_POST['color'] ?? '', 10);
        $isAuto = ($colorInput === 'auto');
        $isValidHex = (bool)preg_match('/^#[0-9A-Fa-f]{6}$/', $colorInput);
        if ($isAuto || $isValidHex) {
            foreach ($ids as $pid) {
                $ownRow = sec_one("SELECT parent_id FROM projects WHERE id=? AND user_id=?", [$pid, (int)$current_user_id]);
                if (!$ownRow) continue;
                if ($isAuto) {
                    $pidParent = $ownRow['parent_id'] === null ? 0 : (int)$ownRow['parent_id'];
                    $finalColor = prog_resolve_color($pidParent, $current_user_id);
                    $flag = 0;
                } else {
                    $finalColor = $colorInput;
                    $flag = 1;
                }
                sec_q("UPDATE projects SET color=?, color_edited=? WHERE id=? AND user_id=?",
                      [$finalColor, $flag, $pid, (int)$current_user_id]);
                prog_cascade_color($pid, $finalColor, $current_user_id);
            }
        }
    } elseif ($action === 'bulk_reparent') {
        $ids = array_slice(array_values(array_unique(array_filter(array_map(
                   function ($x) { return sec_int($x, 1); },
                   explode(',', (string)($_POST['project_ids'] ?? '')))))), 0, 500);
        $newParentId = sec_int($_POST['new_parent_id'] ?? 0, 0);

        // vietato spostare un progetto sotto se stesso o un suo discendente
        // (anche uno degli ALTRI selezionati, dato che si spostano tutti insieme):
        // stesso identico controllo che l'utente vede gia' disabilitato nell'albero.
        $childrenOf = prog_children_map($current_user_id);
        $forbidden = [];
        foreach ($ids as $rootId) { foreach (prog_subtree_ids($rootId, $childrenOf) as $fid) { $forbidden[$fid] = true; } }

        $valid = ($newParentId === 0);
        if ($newParentId > 0 && !isset($forbidden[$newParentId])) {
            $valid = sec_one("SELECT id FROM projects WHERE id=? AND user_id=?", [$newParentId, (int)$current_user_id]) !== null;
        }

        if ($valid) {
            $parentVal = $newParentId > 0 ? $newParentId : null;
            /* Chi arriva in un progetto nuovo si mette in fondo ai suoi nuovi
               fratelli: col vecchio numero d'ordine finiva in un punto a caso,
               e trascinandolo dentro un progetto lo si cercava fra gli altri. */
            $maxOrd = $newParentId > 0
                ? sec_one("SELECT MAX(custom_order) AS m FROM projects WHERE user_id=? AND parent_id=?", [(int)$current_user_id, $newParentId])
                : sec_one("SELECT MAX(custom_order) AS m FROM projects WHERE user_id=? AND parent_id IS NULL", [(int)$current_user_id]);
            $prossimoOrd = (int)($maxOrd['m'] ?? -1) + 1;
            foreach ($ids as $pid) {
                $ownRow = sec_one("SELECT color, color_edited, parent_id FROM projects WHERE id=? AND user_id=?", [$pid, (int)$current_user_id]);
                if (!$ownRow) continue;
                $giaQui = ($ownRow['parent_id'] === null ? 0 : (int)$ownRow['parent_id']) === $newParentId;
                if (!$giaQui) {
                    sec_q("UPDATE projects SET custom_order=? WHERE id=? AND user_id=?", [$prossimoOrd++, $pid, (int)$current_user_id]);
                }
                if ((int)$ownRow['color_edited'] === 1) {
                    $finalColor = $ownRow['color'];
                } else {
                    $finalColor = prog_resolve_color($newParentId, $current_user_id);
                }
                sec_q("UPDATE projects SET parent_id=?, color=? WHERE id=? AND user_id=?",
                      [$parentVal, $finalColor, $pid, (int)$current_user_id]);
                prog_cascade_color($pid, $finalColor, $current_user_id);
            }
        }
    } elseif ($action === 'reorder') {
        $ids = array_slice(array_values(array_unique(array_filter(array_map(
                   function ($x) { return sec_int($x, 1); },
                   explode(',', (string)($_POST['project_ids'] ?? '')))))), 0, 500);
        $ord = 0;
        foreach ($ids as $id) {
            sec_q("UPDATE projects SET custom_order=? WHERE id=? AND user_id=?", [$ord, $id, (int)$current_user_id]);
            $ord++;
        }
    }

    header('Location: progetti.php' . ($redirectId > 0 ? ('?id=' . $redirectId) : ''));
    exit();
}

/* ==========================================================================
   LETTURA DATI per il rendering
   ========================================================================== */
if (!function_exists('prog_build_branch')) {
    function prog_build_branch($id, &$byId, &$childrenOf) {
        if (!isset($byId[$id])) return null;
        $node = $byId[$id];
        if (!empty($childrenOf[$id])) {
            foreach ($childrenOf[$id] as $cid) {
                $c = prog_build_branch($cid, $byId, $childrenOf);
                if ($c) $node['children'][] = $c;
            }
        }
        return $node;
    }
}

$byId = [];
$childrenOf = [];
foreach (sec_all("SELECT id, parent_id, name, notes, color, color_edited, status, custom_order, created_at, is_favorite, todo_enabled, target_date AS data_obiettivo, due_date AS data_scadenza, study_event_id, study_start, study_end, study_pomodori_stimati, study_ripasso_di FROM projects WHERE user_id = ? ORDER BY custom_order ASC, id ASC",
                 [(int)$current_user_id]) as $row) {
    $row['children'] = [];
    $row['stato'] = $row['status']; // Mappa retrocompatibile per le funzioni interne
    $row['note'] = $row['notes'];   // la colonna è `notes`, ma tutto il codice legge `note` (bug: le note non comparivano in modifica)
    if (prog_nota_vuota($row['note'])) $row['note'] = '';   // vuota anche se ci sono solo spazi o invisibili
    $byId[(int)$row['id']] = $row;
    $pid = $row['parent_id'] === null ? 0 : (int)$row['parent_id'];
    $childrenOf[$pid][] = (int)$row['id'];
}

/* Quanti figli diretti ha ogni progetto e quanti sono chiusi: è il «2 di 5»
   della scheda e delle righe. Si conta qui, prima che le impostazioni tolgano
   i completati dalla vista: nascondere le cose fatte non vuol dire che non
   siano state fatte. */
$figliTot = []; $figliFatti = [];
foreach ($byId as $fid => $fp) {
    $pp = $fp['parent_id'] === null ? 0 : (int)$fp['parent_id'];
    if ($pp === 0) continue;
    $figliTot[$pp] = ($figliTot[$pp] ?? 0) + 1;
    if (($fp['status'] ?? '') === 'completed') $figliFatti[$pp] = ($figliFatti[$pp] ?? 0) + 1;
}

$secondsOwn = [];
foreach (sec_all("SELECT project_id, SUM(duration_seconds) AS tot FROM pomodoro_sessions WHERE user_id = ? AND project_id IS NOT NULL GROUP BY project_id",
                 [(int)$current_user_id]) as $srow) { $secondsOwn[(int)$srow['project_id']] = (int)$srow['tot']; }

if (!function_exists('prog_total_seconds')) {
    function prog_total_seconds($id, &$childrenOf, &$secondsOwn, &$memo) {
        if (isset($memo[$id])) return $memo[$id];
        $total = $secondsOwn[$id] ?? 0;
        foreach (($childrenOf[$id] ?? []) as $cid) { $total += prog_total_seconds($cid, $childrenOf, $secondsOwn, $memo); }
        $memo[$id] = $total;
        return $total;
    }
}
$totalMemo = [];
foreach ($byId as $pid => $p) { prog_total_seconds($pid, $childrenOf, $secondsOwn, $totalMemo); }

$countOwn = [];
foreach (sec_all("SELECT project_id, COUNT(*) AS n FROM pomodoro_sessions WHERE user_id = ? AND project_id IS NOT NULL GROUP BY project_id",
                 [(int)$current_user_id]) as $cntRow) { $countOwn[(int)$cntRow['project_id']] = (int)$cntRow['n']; }
if (!function_exists('prog_total_count')) {
    // stessa forma di prog_total_seconds ma per il numero di pomodori: serve a
    // sapere se un progetto (o un suo discendente) ne ha almeno uno collegato,
    // per decidere se chiedere all'utente cosa farne prima di eliminarlo.
    function prog_total_count($id, &$childrenOf, &$countOwn, &$memo) {
        if (isset($memo[$id])) return $memo[$id];
        $total = $countOwn[$id] ?? 0;
        foreach (($childrenOf[$id] ?? []) as $cid) { $total += prog_total_count($cid, $childrenOf, $countOwn, $memo); }
        $memo[$id] = $total;
        return $total;
    }
}
$countMemo = [];
foreach ($byId as $pid => $p) { prog_total_count($pid, $childrenOf, $countOwn, $countMemo); }

$jsProjLookup = [];
$catLinks = []; 
$clRighe = sec_all("SELECT pcf.project_id, pcf.fc_category_id, c.name, c.color
                    FROM fc_study_deck_projects pcf
                    INNER JOIN projects p ON p.id = pcf.project_id AND p.user_id = ?
                    INNER JOIN fc_study_decks c ON c.id = pcf.fc_category_id AND c.user_id = ?",
                   [(int)$current_user_id, (int)$current_user_id]);
{
    foreach ($clRighe as $clRow) {
        $ccolor = (!empty($clRow['color']) && preg_match('/^#[0-9A-Fa-f]{6}$/', $clRow['color'])) ? $clRow['color'] : '#A0A0A0';
        $catLinks[(int)$clRow['project_id']] = ['id' => (int)$clRow['fc_category_id'], 'name' => $clRow['name'], 'color' => $ccolor];
    }
}

/* ==========================================================================
   LINGUE (fc_vocab_courses): un corso di lingua nasce con il suo progetto
   (fc_languages.php). Qui il progetto mostra la lingua con la sua bandiera,
   e toccarla porta alle categorie di quella lingua.
   ========================================================================== */
require_once __DIR__ . '/../../lib/fc/fc_type.php';
$corsoLinks = [];
foreach (sec_all("SELECT k.id, k.learn_lang, k.project_id
                    FROM fc_vocab_courses k
                    JOIN projects p ON p.id = k.project_id AND p.user_id = ?
                   WHERE k.user_id = ?", [(int)$current_user_id, (int)$current_user_id]) as $kRow) {
    $corsoLinks[(int)$kRow['project_id']] = [
        'id'   => (int)$kRow['id'],
        'nome' => fc_lingua_nome((string)$kRow['learn_lang']),
        'flag' => fc_lingua_bandiera((string)$kRow['learn_lang']),
    ];
}
/** Il tag della lingua di un progetto (vuoto se il progetto non e' di una lingua). */
function prog_tag_corso(?array $k, string $tag = 'button'): string {
    if (!$k) return '';
    return '<' . $tag . ($tag === 'button' ? ' type="button"' : '') . ' class="pr-cat-tag pr-lang-tag"'
         . ' data-on-click="event.stopPropagation(); window.location.href=\'../fc/fc_decks.php?course=' . (int)$k['id'] . '\'">'
         . '<img src="' . sec_h($k['flag']) . '" alt="" width="12" height="12">' . sec_h($k['nome']) . '</' . $tag . '>';
}

/* ==========================================================================
   MATERIE (fc_study_decks): mappa padre/nome, serve lato client per calcolare
   quali materie vanno bloccate nel selettore del popup di modifica progetto
   (una materia non è selezionabile se lei stessa, un suo figlio o un suo
   padre sono già collegati a un progetto DIVERSO da quello in modifica).
   ========================================================================== */
$catParentOf = [];
$catNameById = [];
foreach (sec_all("SELECT id, parent_id, name FROM fc_study_decks WHERE user_id = ?", [(int)$current_user_id]) as $fcRow) {
    $fcId = (int)$fcRow['id'];
    $catParentOf[$fcId] = $fcRow['parent_id'] === null ? 0 : (int)$fcRow['parent_id'];
    $catNameById[$fcId] = $fcRow['name'];
}

// tutti i collegamenti progetto<->materia (non solo quelli del progetto che si
// sta aprendo: il client sceglie quali ignorare in base al progetto in modifica)
$jsCatLinks = [];
foreach ($catLinks as $clPid => $clInfo) {
    if (isset($byId[$clPid])) {
        $jsCatLinks[] = ['project_id' => $clPid, 'project_name' => $byId[$clPid]['name'], 'category_id' => $clInfo['id']];
    }
}
foreach ($byId as $pidKey => $p) {
    $jsProjLookup[$pidKey] = [
        'name' => $p['name'],
        'color' => (!empty($p['color']) && preg_match('/^#[0-9A-Fa-f]{6}$/', $p['color'])) ? $p['color'] : '#8E8E93',
        'color_edited' => (int)$p['color_edited'],
        'is_favorite'  => (int)($p['is_favorite'] ?? 0),
        'parent_id' => $p['parent_id'] === null ? 0 : (int)$p['parent_id'],
        'data_obiettivo' => $p['data_obiettivo'],
        'data_scadenza' => $p['data_scadenza'],
        'fc_category' => $catLinks[$pidKey] ?? null,
        'session_count' => $countMemo[$pidKey] ?? 0,
        'note' => $p['note'] ?? '',
        'has_note' => (trim((string)($p['note'] ?? '')) !== '') ? 1 : 0,
        /* Serve al pannello delle date per sapere se una data scaduta è un
           ritardo o una cosa già fatta: senza, il browser dovrebbe indovinarlo
           e finirebbe per disegnare rosso su quello che è chiuso. */
        'stato' => $p['stato'] ?? $p['status'] ?? '',
    ];
}

/* Le impostazioni scelte dall'utente: da qui in giù la pagina si costruisce
   secondo quelle. Si leggono una volta sola, non a ogni progetto. */
$prOpz = prog_opzioni($current_user_id);

/* «Nascondi i completati» e «solo quelli con pomodori» tolgono rami interi
   dalla vista, non singole righe: nascondere un progetto e lasciare i suoi
   figli darebbe un albero con dei rami che spuntano dal nulla. */
if (!$prOpz['completati'] || $prOpz['solo_attivi']) {
    $daTogliere = [];
    foreach ($byId as $pid => $p) {
        $fuori = false;
        if (!$prOpz['completati'] && ($p['stato'] ?? '') === 'completed') $fuori = true;
        if ($prOpz['solo_attivi'] && (int)($countMemo[$pid] ?? 0) === 0
            && (int)($totalMemo[$pid] ?? 0) === 0) $fuori = true;
        if ($fuori) $daTogliere[$pid] = true;
    }
    /* Il progetto aperto non si nasconde mai, qualunque cosa dicano le
       impostazioni: sparire dalla pagina che si sta guardando, spuntandolo, è
       la cosa più sconcertante che possa fare un elenco. Lo si legge
       dall'indirizzo perché $selectedId viene deciso più sotto, e serve qui. */
    $apertoOra = sec_int($_GET['id'] ?? 0, 0) ?: pr_id_ricordato();
    if ($apertoOra > 0) {
        unset($daTogliere[$apertoOra]);
        // e nemmeno i suoi antenati, o si perderebbe la strada per tornarci
        $risali = $byId[$apertoOra]['parent_id'] ?? null;
        $giri = 0;
        while ($risali && $giri++ < 50) {
            unset($daTogliere[(int)$risali]);
            $risali = $byId[(int)$risali]['parent_id'] ?? null;
        }
    }
    if ($daTogliere) {
        foreach ($childrenOf as $padre => $figli) {
            $childrenOf[$padre] = array_values(array_filter($figli, function ($x) use ($daTogliere) {
                return !isset($daTogliere[$x]);
            }));
        }
    }
}

/* L'ordine dei fratelli, secondo l'impostazione scelta.
   «Manuale» è l'ordine che l'utente si è costruito trascinando, e resta il
   predefinito: gli altri sono modi di guardare, non di riorganizzare — infatti
   non toccano `custom_order`, che resta lì per quando si torna a manuale. */
if ($prOpz['ordine'] !== 'manuale') {
    $ordina = function ($a, $b) use ($byId, $prOpz) {
        $pa = $byId[$a] ?? []; $pb = $byId[$b] ?? [];
        if ($prOpz['ordine'] === 'alfabetico') {
            return strnatcasecmp((string)($pa['name'] ?? ''), (string)($pb['name'] ?? ''));
        }
        if ($prOpz['ordine'] === 'recenti') {
            // il più mosso di recente in cima: è quello su cui si sta lavorando
            return strcmp((string)($pb['created_at'] ?? ''), (string)($pa['created_at'] ?? ''));
        }
        /* Per scadenza: prima quelle vicine, e chi non ne ha finisce in fondo.
           Metterle senza data in cima vorrebbe dire che l'ordinamento «per
           scadenza» comincia con tutto quello che una scadenza non ce l'ha. */
        $da = $pa['data_scadenza'] ?: $pa['data_obiettivo'] ?: '';
        $db = $pb['data_scadenza'] ?: $pb['data_obiettivo'] ?: '';
        if ($da === '' && $db === '') return 0;
        if ($da === '') return 1;
        if ($db === '') return -1;
        return strcmp($da, $db);
    };
    foreach ($childrenOf as $padre => $figli) {
        usort($figli, $ordina);
        $childrenOf[$padre] = $figli;
    }
}

$rootIds = $childrenOf[0] ?? [];
// i progetti principali (senza padre) vanno in ordine alfabetico, sia nell'elenco
// (barra laterale) sia nei chip in alto
usort($rootIds, function ($a, $b) use ($byId) {
    $na = isset($byId[$a]) ? $byId[$a]['name'] : '';
    $nb = isset($byId[$b]) ? $byId[$b]['name'] : '';
    return strcasecmp($na, $nb);
});
$rootProjects = [];
foreach ($rootIds as $rid) { if (isset($byId[$rid])) $rootProjects[] = $byId[$rid]; }

/* La vista "Preferiti": non è un progetto vero, è un elenco costruito al
   volo con i progetti segnati con la stellina. I loro sottoprogetti si
   aprono come sempre, così si arriva in fondo senza cambiare pagina. */
$vista = sec_enum($_GET['view'] ?? null, ['fav', 'all', 'cal'], '');
$viewFavorites = $vista === 'fav';
/* «Tutti»: la prima pastiglia. Un elenco calmo di ogni progetto, con quanto
   manca, la scadenza e il tempo speso. Prende il posto del cassetto laterale e
   della pastiglia dei preferiti: due cose diventano una. */
$viewAll = $vista === 'all' || $vista === 'cal';
/* Il calendario di tutti i progetti: quello che era la pagina «to study».
   Sta dentro «Tutti», accanto all'elenco, perché è lo stesso insieme di
   progetti visto nel tempo invece che in fila. */
$viewCal = $vista === 'cal';
$favProjects = [];
foreach ($byId as $fid => $fp) {
    if ((int)($fp['is_favorite'] ?? 0) === 1) $favProjects[] = (int)$fid;
}
usort($favProjects, function ($a, $b) use ($byId) {
    return strcasecmp($byId[$a]['name'] ?? '', $byId[$b]['name'] ?? '');
});

$selectedId = sec_int($_GET['id'] ?? 0, 0);
/* senza un progetto nell'indirizzo, quello aperto l'ultima volta (se esiste
   ancora), e solo dopo il primo dell'elenco */
if ($selectedId === 0 && !$viewFavorites && !$viewAll) {
    $prRic = pr_id_ricordato();
    if ($prRic > 0 && isset($byId[$prRic])) $selectedId = $prRic;
}
if ($selectedId === 0 && !$viewFavorites && !$viewAll && !empty($rootIds)) { $selectedId = $rootIds[0]; }
if ($viewAll) $selectedId = 0;
$selectedProject = $viewFavorites ? null : ($byId[$selectedId] ?? null);
$selectedTree = $selectedProject ? prog_build_branch($selectedId, $byId, $childrenOf) : null;

/* i rami dei preferiti, ciascuno completo dei suoi figli */
$favTrees = [];
if ($viewFavorites) {
    foreach ($favProjects as $fid) $favTrees[] = prog_build_branch($fid, $byId, $childrenOf);
}

$ancestorChain = [];
if ($selectedProject) {
    $walk = $selectedProject['parent_id'] === null ? 0 : (int)$selectedProject['parent_id'];
    $guard = 0;
    while ($walk > 0 && isset($byId[$walk]) && $guard < 50) {
        $guard++;
        array_unshift($ancestorChain, $byId[$walk]);
        $walk = $byId[$walk]['parent_id'] === null ? 0 : (int)$byId[$walk]['parent_id'];
    }
}

// radice del progetto attualmente visualizzato: serve per tenere "acceso" (anche
// se in sotto-progetto) il chip del progetto principale in alto (richiesta 18)
$selectedRootId = $selectedId;
if (!empty($ancestorChain)) { $selectedRootId = (int)$ancestorChain[0]['id']; }

$prColorPalette = ['#FF3B30', '#FF9500', '#FFCC00', '#34C759', '#00C7BE', '#30B0C7', '#32ADE6', '#007AFF', '#5856D6', '#AF52DE', '#FF2D55', '#A2845E'];

/* Il pomodoro che sta andando adesso, e su quale progetto. Sulla sua riga
   compare il tempo che scorre: in un elenco tutto grigio è l'unica cosa
   colorata, e si vede subito cosa sta correndo. Si passano i secondi già
   trascorsi e non l'ora d'inizio, così l'orologio del telefono, se è avanti o
   indietro, non sposta niente. */
$pomoAttivo = null;
$paRiga = sec_one("SELECT project_id, start_time, resume_time, status, duration_seconds, planned_duration_min
                   FROM pomodoro_sessions WHERE user_id = ? AND end_time IS NULL ORDER BY id DESC LIMIT 1",
                  [(int)$current_user_id]);
if ($paRiga && $paRiga['project_id'] !== null && isset($byId[(int)$paRiga['project_id']])) {
    $paFermo = ($paRiga['status'] === 'pausa');
    $paSeg = $paFermo ? 0 : max(0, time() - strtotime($paRiga['resume_time'] ?: $paRiga['start_time']));
    $pomoAttivo = [
        'id'        => (int)$paRiga['project_id'],
        'trascorsi' => (int)($paRiga['duration_seconds'] ?? 0) + $paSeg,
        'fermo'     => $paFermo,
        'previsti'  => $paRiga['planned_duration_min'] !== null ? (int)$paRiga['planned_duration_min'] * 60 : 0,
    ];
}
/* il progetto che corre e tutti quelli sopra: un ramo chiuso deve comunque
   far capire che lì dentro c'è qualcosa che va */
$pomoRamo = [];
if ($pomoAttivo) {
    $x = $pomoAttivo['id']; $g = 0;
    while ($x && isset($byId[$x]) && $g++ < 60) { $pomoRamo[$x] = true; $x = (int)($byId[$x]['parent_id'] ?? 0); }
}

$pomoInCorso = false;
if (sec_one("SELECT id FROM pomodoro_sessions WHERE user_id=? AND end_time IS NULL LIMIT 1", [(int)$current_user_id])) { $pomoInCorso = true; }
if (!$pomoInCorso) {
    if (sec_one("SELECT user_id FROM pomodoro_current_breaks WHERE user_id=? LIMIT 1", [(int)$current_user_id])) { $pomoInCorso = true; }
}

/* La stella in due versioni: piena quando è fra i preferiti, tratteggiata e
   spenta quando no. Sta in una funzione perché la stessa forma serve al PHP
   che disegna la pagina e al JavaScript che la cambia al volo. */
function prog_star_svg(bool $piena): string {
    $d = 'M12 4.2l2.3 4.9 5.2.7-3.8 3.7 1 5.3-4.7-2.6-4.7 2.6 1-5.3-3.8-3.7 5.2-.7z';
    if ($piena) {
        return '<svg class="pr-star pr-star-hero" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="' . $d . '"/></svg>';
    }
    return '<svg class="pr-star pr-star-hero pr-star-off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" stroke-dasharray="3 2.2" aria-hidden="true"><path d="' . $d . '"/></svg>';
}

function prog_fmt_duration($seconds) {
    $seconds = (int)$seconds;
    if ($seconds <= 0) return '';
    $h = intdiv($seconds, 3600); $m = intdiv($seconds % 3600, 60);
    if ($h > 0) return $h . 'h' . ($m > 0 ? ' ' . $m . 'm' : '');
    if ($m > 0) return $m . 'm';
    return '<1m';
}

/* La data corta nella lingua di chi guarda: «15 nov» o «15 Nov». Prima era
   date('d M'), che scrive i mesi sempre in inglese. L'anno solo se non è
   quello in corso. */
function prog_data_breve($ymd): string {
    $ts = strtotime((string)$ymd);
    if ($ts === false) return '';
    $s = (int)date('j', $ts) . ' ' . t('abitudini.mesb_' . (int)date('n', $ts));
    if (date('Y', $ts) !== date('Y')) $s .= ' ' . date('Y', $ts);
    return $s;
}

/* «19–30 ott» se il mese è lo stesso, «28 ott – 3 nov» altrimenti. */
function prog_intervallo_breve($da, $a): string {
    $ta = strtotime((string)$da); $tb = strtotime((string)$a);
    if ($ta === false || $tb === false) return '';
    if (date('Y-m', $ta) === date('Y-m', $tb)) {
        return (int)date('j', $ta) . '–' . prog_data_breve($a);
    }
    return prog_data_breve($da) . ' – ' . prog_data_breve($a);
}

/* Il colore del testo sopra il colore di un progetto: bianco quasi sempre,
   scuro sui colori chiari (un giallo con la scritta bianca non si legge). */
function prog_inchiostro($hex): string {
    if (!preg_match('/^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i', (string)$hex, $m)) return '#FFFFFF';
    $l = (0.299 * hexdec($m[1]) + 0.587 * hexdec($m[2]) + 0.114 * hexdec($m[3])) / 255;
    return $l > 0.72 ? '#1C1C1E' : '#FFFFFF';
}

/* Il tempo che scorre, scritto come lo scrive il pomodoro. */
function prog_tempo_vivo(array $p): string {
    $s = $p['previsti'] > 0 ? max(0, $p['previsti'] - $p['trascorsi']) : $p['trascorsi'];
    $h = intdiv($s, 3600); $m = intdiv($s % 3600, 60); $x = $s % 60;
    return $h > 0 ? sprintf('%d:%02d:%02d', $h, $m, $x) : sprintf('%d:%02d', $m, $x);
}

if (!function_exists('prog_date_tags_info')) {
    // Tag data per i sottoprogetti: uno per l'obiettivo e uno per la scadenza,
    // se presenti entrambi vengono mostrati entrambi. Ogni tag ha uno 'status'
    // ('past' = rossa, già passata; 'today' = arancio, è oggi; 'future' = blu,
    // deve ancora arrivare) calcolato confrontando solo il giorno, non l'orario.
    function prog_date_tags_info($node) {
        $out = [];
        $defs = [
            ['field' => 'data_obiettivo', 'icon' => '🎯'],
            ['field' => 'data_scadenza', 'icon' => '⏰'],
        ];
        foreach ($defs as $def) {
            $raw = $node[$def['field']] ?? null;
            if (empty($raw)) continue;
            $ts = strtotime($raw);
            if ($ts === false) continue;
            $todayYmd = date('Y-m-d');
            $dateYmd = date('Y-m-d', $ts);
            /* Il rosso è un allarme, e un allarme su una cosa già fatta è
               rumore: prima un obiettivo del mese scorso restava rosso anche
               dopo essere stato spuntato, e la pagina si riempiva di rosso che
               non chiedeva niente a nessuno.
               Ora conta anche lo stato: se il progetto è chiuso la data si
               barra — verde se è stata rispettata, grigia se si è arrivati
               dopo. Il rosso resta solo dove serve, cioè su quello che è in
               ritardo ADESSO. */
            $chiuso = ($node['status'] ?? $node['stato'] ?? '') === 'completed';
            if ($chiuso) {
                $status = ($dateYmd < $todayYmd) ? 'fatto-tardi' : 'fatto';
            } elseif ($dateYmd < $todayYmd) {
                $status = 'past';
            } elseif ($dateYmd === $todayYmd) {
                $status = 'today';
            } else {
                $status = 'future';
            }
            $out[] = ['label' => prog_data_breve($raw), 'icon' => $def['icon'], 'status' => $status,
                      'campo' => $def['field'] === 'data_scadenza' ? 'scadenza' : 'obiettivo'];
        }
        return $out;
    }
}
/* L'icona della concentrazione: un timer, perché il tasto avvia un pomodoro.
   È la stessa ovunque, e una sola definizione evita che le copie divergano. */
function prog_timer_svg(): string {
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="13.5" r="7.5"/><path d="M12 10v3.6l2.4 1.6M10 2.5h4M18.4 6.6l1.4-1.4"/></svg>';
}
function prog_piu_svg(): string {
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>';
}

/* ==========================================================================
   UNA RIGA DELL'ELENCO
   Una riga sola: la spunta, il nome e sotto una riga grigia con quello che
   serve sapere (scadenza, quanti figli sono fatti, il tempo speso, il deck, la
   nota). A destra un play discreto, grigio: prima ogni riga aveva un tondo blu
   pieno, e sei tondi blu uno sotto l'altro erano la cosa più forte della
   pagina. Colorato resta solo il pomodoro che sta andando.
   Niente maniglia: si trascina tenendo premuta la riga. Niente tasto ↳ sotto
   il nome: aggiungere dentro sta nello scorrimento (accanto a Modifica ed
   Elimina) e in fondo a ogni ramo aperto.
   ========================================================================== */
function prog_render_row($node, &$childrenOf, &$totalMemo, $depth = 0, $pomoInCorso = false) {
    global $catLinks, $corsoLinks, $pomoAttivo, $pomoRamo, $figliTot, $figliFatti, $byId;
    $id = (int)$node['id'];
    $color = (!empty($node['color']) && preg_match('/^#[0-9A-Fa-f]{6}$/', $node['color'])) ? $node['color'] : '#8E8E93';
    $hasChildren = !empty($node['children']);
    $nameEsc = sec_h($node['name']);
    $noteEsc = sec_h($node['note'] ?? '');
    $stato = $node['stato'];
    $durata = prog_fmt_duration($totalMemo[$id] ?? 0);
    $cat = $catLinks[$id] ?? null;
    $corso = $corsoLinks[$id] ?? null;
    $dateTags = prog_date_tags_info($node);
    $nTot = (int)($figliTot[$id] ?? 0);
    $nFatti = (int)($figliFatti[$id] ?? 0);
    $aperto = $hasChildren && $depth < 1;
    $corre = $pomoAttivo && $pomoAttivo['id'] === $id;
    $correDentro = !$corre && isset($pomoRamo[$id]);
    $statsAttr = ' data-stats-id="' . $id . '" data-stats-nome="' . $nameEsc . '" data-stats-colore="' . sec_h($color) . '"'
               . ' data-on-click="event.stopPropagation(); prOpenStats(+this.dataset.statsId, this.dataset.statsNome, this.dataset.statsColore)"';
    /* Le date di studio (il piano): «19–30 ott», con l'icona del calendario. */
    $studio = '';
    if (!empty($node['study_start'])) {
        $sa = (string)$node['study_start']; $sb = !empty($node['study_end']) ? (string)$node['study_end'] : $sa;
        $studio = $sa === $sb ? prog_data_breve($sa) : prog_intervallo_breve($sa, $sb);
    }
    $haMeta = $dateTags || $nTot > 0 || $cat || $corso || $noteEsc !== '' || $studio !== '';
    ?>
    <div class="pr-row-wrap" data-id="<?php echo $id; ?>" data-parent="<?php echo (int)($node['parent_id'] ?? 0); ?>" style="--row-color:<?php echo sec_h($color); ?>;">
        <div class="pr-row" data-id="<?php echo $id; ?>">
            <div class="pr-row-content">
                <?php if ($hasChildren): ?>
                <button type="button" class="pr-arrow<?php echo $aperto ? ' open' : ''; ?>" data-on-click="event.stopPropagation(); prToggleChildren(<?php echo $id; ?>, this)" aria-label="<?php te('progetti.apri_chiudi_sotto'); ?>" aria-expanded="<?php echo $aperto ? 'true' : 'false'; ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="m9 6 6 6-6 6"/></svg>
                </button>
                <?php else: ?>
                <span class="pr-arrow-spacer"></span>
                <?php endif; ?>
                <button type="button" class="pr-check pr-check-<?php echo sec_h($stato); ?>" data-id="<?php echo $id; ?>" aria-label="<?php te('progetti.cambia_stato'); ?>">
                    <svg class="pr-ic-empty" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9.5"/></svg>
                    <svg class="pr-ic-done" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10.5" fill="currentColor"/><path d="M7.6 12.4l3 3 5.8-6.3" fill="none" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    <svg class="pr-ic-planned" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9.5"/><path d="M12 7.5v4.8l3 2" stroke-linecap="round"/></svg>
                </button>
                <span class="pr-select-chk" aria-hidden="true">✓</span>
                <div class="pr-main">
                    <span class="pr-name<?php echo $stato === 'completed' ? ' pr-name-done' : ''; ?>" data-on-click="prTapRow(<?php echo $id; ?>)"><span class="pr-name-track"><?php
                        if ((int)($node['is_favorite'] ?? 0) === 1) {
                            echo '<svg class="pr-star" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 4.2l2.3 4.9 5.2.7-3.8 3.7 1 5.3-4.7-2.6-4.7 2.6 1-5.3-3.8-3.7 5.2-.7z"/></svg>';
                        }
                        echo $nameEsc;
                    ?></span></span>
                    <?php if ($haMeta): ?>
                    <div class="pr-meta">
                        <?php foreach ($dateTags as $dt): ?>
                        <span class="pr-mi pr-md-<?php echo sec_h($dt['status']); ?>"><?php
                            echo sec_h(t($dt['campo'] === 'scadenza' ? 'progetti.meta_scadenza' : 'progetti.meta_obiettivo', ['d' => $dt['label']]));
                        ?></span>
                        <?php endforeach; ?>
                        <?php if ($studio !== ''): ?><span class="pr-mi pr-mi-studio"><svg class="pr-mi-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3.5" y="5" width="17" height="15.5" rx="3"/><path d="M3.5 10h17M8 3v4M16 3v4"/></svg><?php echo sec_h($studio); ?></span><?php endif; ?>
                        <?php if ($nTot > 0): ?><span class="pr-mi"><?php echo sec_h(t('progetti.n_di_m', ['n' => $nFatti, 'm' => $nTot])); ?></span><?php endif; ?>
                        <?php if ($cat): ?>
                        <button type="button" class="pr-mi pr-cat-tag" style="--cat-color:<?php echo sec_h($cat['color']); ?>;"
                                data-on-click="event.stopPropagation(); window.location.href='../fc/fc_view.php?category_id=<?php echo (int)$cat['id']; ?>'">
                            <svg viewBox="0 0 24 24" width="11" height="11" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z"/></svg><?php echo sec_h($cat['name']); ?>
                        </button>
                        <?php endif; ?>
                        <?php if ($corso) echo str_replace('class="pr-cat-tag pr-lang-tag"', 'class="pr-mi pr-cat-tag pr-lang-tag"', prog_tag_corso($corso)); ?>
                        <?php if ($noteEsc !== ''): ?>
                        <button type="button" class="pr-mi pr-note-tag" data-note-raw="<?php echo $noteEsc; ?>" data-note-id="<?php echo $id; ?>"
                                data-on-click="event.stopPropagation(); prOpenNotePeek(<?php echo $id; ?>, this)" aria-label="<?php te('progetti.mostra_nota'); ?>">
                            <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10l6-6V5a2 2 0 0 0-2-2Z"/><path d="M15 21v-4a2 2 0 0 1 2-2h4"/></svg>
                        </button>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php if ($corre): ?>
                <button type="button" class="pr-pomo-run"<?php echo $statsAttr; ?> aria-label="<?php te('progetti.in_corso'); ?>">
                    <i></i><span data-pomo-live><?php echo sec_h(prog_tempo_vivo($pomoAttivo)); ?></span>
                </button>
                <?php else: ?>
                <?php /* il timer e, sotto, il tempo speso qui in tutto (figli compresi) */ ?>
                <span class="pr-pomo-col">
                    <button type="button" class="pr-pomo-btn<?php echo $correDentro ? ' pr-corre-dentro' : ''; ?>"<?php echo $statsAttr; ?>
                            aria-label="<?php te('progetti.concentrati'); ?>" title="<?php te('progetti.concentrati'); ?>"><?php echo prog_timer_svg(); ?></button>
                    <?php if ($durata !== ''): ?><span class="pr-pomo-t"><?php echo sec_h($durata); ?></span><?php endif; ?>
                </span>
                <?php endif; ?>
            </div>
            <div class="swipe-actions">
                <button type="button" class="swipe-btn swipe-btn-add" data-on-click="closeAllSwipes(null); prAggiungi(<?php echo $id; ?>)">
                    <?php echo prog_piu_svg(); ?>
                    <span><?php te('progetti.aggiungi_dentro'); ?></span>
                </button>
                <button type="button" class="swipe-btn swipe-btn-move" data-on-click="closeAllSwipes(null); prSpostaIn(<?php echo $id; ?>)">
                    <svg viewBox="0 0 24 24" width="19" height="19" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z"/><path d="M10 13h6M13.5 10.5 16 13l-2.5 2.5"/></svg>
                    <span><?php te('progetti.sposta'); ?></span>
                </button>
                <button type="button" class="swipe-btn swipe-btn-delete" data-on-click="prConfirmDelete(<?php echo $id; ?>, <?php echo $hasChildren ? 'true' : 'false'; ?>)">
                    <svg viewBox="0 0 24 24" width="19" height="19" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0-1 14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2L4 6"/></svg>
                    <span><?php te('comune.elimina'); ?></span>
                </button>
            </div>
        </div>
        <?php if ($hasChildren): ?>
        <div class="pr-children<?php echo $aperto ? '' : ' collapsed'; ?>">
            <?php foreach ($node['children'] as $child) { prog_render_row($child, $childrenOf, $totalMemo, $depth + 1, $pomoInCorso); } ?>
            <button type="button" class="pr-add-line pr-add-sub" data-parent="<?php echo $id; ?>" data-on-click="prAggiungi(<?php echo $id; ?>)">
                <span class="pr-add-ic"><?php echo prog_piu_svg(); ?></span><span><?php echo sec_h(t('progetti.aggiungi_a', ['nome' => $node['name']])); ?></span>
            </button>
        </div>
        <?php endif; ?>
    </div>
    <?php
}
?>
<!DOCTYPE html>
<html lang="<?php echo sec_h(standout_lingua()); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, maximum-scale=1">
<title><?php te('progetti.titolo_pagina'); ?></title>
<meta name="csrf-token" content="<?php echo sec_h(sec_csrf_token()); ?>">
<script src="../_assets/js/standout_fetch.js?v=<?php echo @filemtime(__DIR__ . '/../_assets/js/standout_fetch.js') ?: time(); ?>"></script>
<link rel="stylesheet" href="<?php echo PWA_BASE; ?>projects/progetti.css?v=<?php echo @filemtime(__DIR__ . '/progetti.css') ?: 1; ?>">
<link rel="stylesheet" href="<?php echo PWA_BASE; ?>projects/piano.css?v=<?php echo @filemtime(__DIR__ . '/piano.css') ?: 1; ?>">
<?php
/* Le stringhe che servono al JavaScript di questa pagina. */
/* 'pomodoro' serve a pomodoro_storico.php, incluso da questa pagina: senza,
   tutte le sue stringhe uscirebbero come <<chiave>>. */
/* 'todo' serve al widget attività incluso qui dentro: senza, le sue scritte
   uscivano come <<todo.niente_da_fare>>. */
echo standout_i18n_json(['comune', 'progetti', 'pomodoro', 'todo', 'piano']);
?>
<script src="../fc/fc_parser.js?v=<?php echo time(); ?>"></script>
</head>
<body<?php echo $viewCal ? ' class="pr-vista-cal"' : ''; ?>>

<div class="app-header">
    <!-- Sopra il nome della pagina e i due comandi che valgono per tutta la
         pagina (scadenze e impostazioni), sotto le pastiglie: «Tutti» e poi i
         progetti. Il tasto ☰ del cassetto non c'è più: «Tutti» fa la stessa
         cosa, meglio, e due modi per arrivare allo stesso elenco facevano solo
         esitare. -->
    <div class="hdr-row1">
        <div class="hdr-title"><?php te('progetti.progetti'); ?></div>
        <div class="hdr-tools">
            <button type="button" class="hdr-tool" data-on-click="prOpenDatesView()"
                    aria-label="<?php te('progetti.scadenze_e_obiettivi'); ?>" title="<?php te('progetti.scadenze_e_obiettivi'); ?>">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"><rect x="3.5" y="5" width="17" height="15.5" rx="2.5"/><path d="M3.5 10h17M8.5 3v4M15.5 3v4"/></svg>
            </button>
            <button type="button" class="hdr-tool" id="prOpzBtn" data-on-click="prOpenOpzioni()"
                    aria-label="<?php te('progetti.impostazioni'); ?>" title="<?php te('progetti.impostazioni'); ?>">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .34 1.87l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.7 1.7 0 0 0-1.87-.34 1.7 1.7 0 0 0-1 1.55V21a2 2 0 1 1-4 0v-.09a1.7 1.7 0 0 0-1.11-1.55 1.7 1.7 0 0 0-1.87.34l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.7 1.7 0 0 0 .34-1.87 1.7 1.7 0 0 0-1.55-1H3a2 2 0 1 1 0-4h.09a1.7 1.7 0 0 0 1.55-1.11 1.7 1.7 0 0 0-.34-1.87l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.7 1.7 0 0 0 1.87.34H9a1.7 1.7 0 0 0 1-1.55V3a2 2 0 1 1 4 0v.09a1.7 1.7 0 0 0 1 1.55 1.7 1.7 0 0 0 1.87-.34l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.7 1.7 0 0 0-.34 1.87V9a1.7 1.7 0 0 0 1.55 1H21a2 2 0 1 1 0 4h-.09a1.7 1.7 0 0 0-1.55 1z"/></svg>
            </button>
            <?php /* pallino del profilo, in fila con i pulsanti dell'intestazione */ $fr_widget_inline = true; include __DIR__ . '/../partials/notifiche_widget.php'; ?>
        </div>
    </div>
    <?php if (!empty($rootProjects)): ?>
    <nav class="hdr-root-pills" id="hdrRootPills">
        <a class="hdr-root-pill hdr-pill-all<?php echo $viewAll ? ' active' : ''; ?>" href="progetti.php?view=all"><?php te('progetti.tutti'); ?></a>
        <?php foreach ($rootProjects as $rp):
            $rpColor = (!empty($rp['color']) && preg_match('/^#[0-9A-Fa-f]{6}$/', $rp['color'])) ? $rp['color'] : '#8E8E93';
            $rpCls = ((int)$rp['id'] === $selectedId) ? ' active' : (((int)$rp['id'] === $selectedRootId && $selectedId) ? ' active-faded' : '');
        ?>
        <a class="hdr-root-pill<?php echo $rpCls; ?>" style="--pill-color:<?php echo sec_h($rpColor); ?>;" data-pill-id="<?php echo (int)$rp['id']; ?>"
           href="progetti.php?id=<?php echo (int)$rp['id']; ?>"><span class="hdr-root-pill-dot"></span><span class="pill-name"><?php echo sec_h($rp['name']); ?></span></a>
        <?php endforeach; ?>
    </nav>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../partials/sidebar.html'; ?>

<?php
/* ==========================================================================
   SU COMPUTER: l'elenco dei progetti sempre a sinistra, il progetto aperto a
   destra. Su telefono questo pezzo non si vede: lì ci sono le pastiglie e la
   pagina «Tutti».
   ========================================================================== */
?>
<aside class="pr-side" aria-label="<?php te('progetti.i_tuoi_progetti'); ?>">
    <a class="pr-side-it<?php echo $viewAll ? ' on' : ''; ?>" href="progetti.php?view=all">
        <svg class="pr-side-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
        <span class="pr-side-n"><?php te('progetti.tutti_i_progetti'); ?></span>
    </a>
    <?php if (!empty($favProjects)): ?>
    <a class="pr-side-it<?php echo $viewFavorites ? ' on' : ''; ?>" href="progetti.php?view=fav">
        <svg class="pr-side-ic pr-side-star" viewBox="0 0 24 24" fill="currentColor"><path d="M12 4.2l2.3 4.9 5.2.7-3.8 3.7 1 5.3-4.7-2.6-4.7 2.6 1-5.3-3.8-3.7 5.2-.7z"/></svg>
        <span class="pr-side-n"><?php te('progetti.preferiti'); ?></span><span class="pr-side-c"><?php echo count($favProjects); ?></span>
    </a>
    <?php endif; ?>
    <div class="pr-side-lab"><?php te('progetti.i_tuoi_progetti'); ?></div>
    <?php foreach ($rootProjects as $rp):
        $spColor = (!empty($rp['color']) && preg_match('/^#[0-9A-Fa-f]{6}$/', $rp['color'])) ? $rp['color'] : '#8E8E93';
        $spTot = (int)($figliTot[(int)$rp['id']] ?? 0); $spFatti = (int)($figliFatti[(int)$rp['id']] ?? 0);
        $spOn = !$viewAll && !$viewFavorites && (int)$rp['id'] === $selectedRootId;
    ?>
    <a class="pr-side-it<?php echo $spOn ? ' on' : ''; ?>" href="progetti.php?id=<?php echo (int)$rp['id']; ?>" data-pill-id="<?php echo (int)$rp['id']; ?>">
        <i class="pr-side-dot" style="background:<?php echo sec_h($spColor); ?>"></i>
        <span class="pr-side-n pill-name"><?php echo sec_h($rp['name']); ?></span>
        <span class="pr-side-c"><?php
            if (($rp['stato'] ?? '') === 'completed') te('progetti.fatto');
            elseif ($spTot > 0) echo $spFatti . '/' . $spTot;
        ?></span>
    </a>
    <?php endforeach; ?>
    <button type="button" class="pr-side-it pr-side-add" data-on-click="prNuovoProgetto(this)">
        <?php echo prog_piu_svg(); ?><span class="pr-side-n"><?php te('progetti.nuovo_progetto'); ?></span>
    </button>
</aside>

<div class="page">
<?php if ($viewFavorites): ?>
    <?php /* I preferiti: ognuno con il suo ramo, apribile come sempre. */ ?>
    <div class="pr-fav-head">
        <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 4.2l2.3 4.9 5.2.7-3.8 3.7 1 5.3-4.7-2.6-4.7 2.6 1-5.3-3.8-3.7 5.2-.7z"/></svg>
        <span><?php te('progetti.preferiti'); ?></span>
        <small><?php echo count($favTrees); ?></small>
    </div>
    <?php if (empty($favTrees)): ?>
        <div class="pr-empty">
            <div class="pr-empty-title"><?php te('progetti.nessun_preferito'); ?></div>
            <div><?php te('progetti.apri_un_progetto_tocca_modifica'); ?></div>
        </div>
    <?php else: ?>
        <div class="pr-list" id="prList">
            <?php foreach ($favTrees as $ft) { prog_render_row($ft, $childrenOf, $totalMemo, 0, $pomoInCorso); } ?>
        </div>
    <?php endif; ?>
<?php elseif (empty($rootProjects)): ?>
    <div class="pr-empty">
        <svg class="pr-empty-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="15" rx="2"/><path d="M3 9h18M8 3v4"/></svg>
        <div class="pr-empty-title"><?php te('progetti.nessun_progetto_ancora'); ?></div>
        <div><?php te('progetti.crea_il_tuo_primo_progetto'); ?></div>
        <button type="button" class="pr-btn-create-first" data-on-click="prNuovoProgetto(this)"><?php te('progetti.crea_progetto'); ?></button>
    </div>
<?php elseif ($viewCal): ?>
    <?php /* Elenco o calendario: lo stesso insieme di progetti, in fila o nel tempo. */ ?>
    <nav class="pr-seg" aria-label="<?php te('progetti.tutti'); ?>">
        <a class="pr-seg-it<?php echo $viewCal ? '' : ' on'; ?>" href="progetti.php?view=all"><?php te('progetti.vista_elenco'); ?></a>
        <a class="pr-seg-it<?php echo $viewCal ? ' on' : ''; ?>" href="progetti.php?view=cal"><?php te('progetti.vista_calendario'); ?></a>
    </nav>
    <?php /* Il calendario di tutti i progetti: la pagina dello studio, dentro
             i progetti. Vive in una cornice perché ha il suo modo di toccare e
             trascinare i giorni, che qui sotto non deve litigare con quello
             delle righe; per chi la usa è solo la vista «Calendario». */ ?>
    <div id="ppMaster"></div>
<?php elseif ($viewAll || !$selectedProject): ?>
    <?php /* Elenco o calendario: lo stesso insieme di progetti, in fila o nel tempo. */ ?>
    <nav class="pr-seg" aria-label="<?php te('progetti.tutti'); ?>">
        <a class="pr-seg-it<?php echo $viewCal ? '' : ' on'; ?>" href="progetti.php?view=all"><?php te('progetti.vista_elenco'); ?></a>
        <a class="pr-seg-it<?php echo $viewCal ? ' on' : ''; ?>" href="progetti.php?view=cal"><?php te('progetti.vista_calendario'); ?></a>
    </nav>
    <?php
    /* ======================================================================
       TUTTI I PROGETTI
       Ogni progetto una volta sola, su una riga: quanto è fatto, la scadenza
       e il tempo di concentrazione. In cima i preferiti, che possono essere
       anche sotto-progetti in fondo a un ramo.
       ====================================================================== */
    ?>
    <?php if (!empty($favProjects)): ?>
    <div class="pr-lab"><span><?php te('progetti.preferiti'); ?></span></div>
    <div class="pr-ov">
        <?php foreach ($favProjects as $fid):
            $fp = $byId[$fid] ?? null; if (!$fp) continue;
            $fpMeta = [];
            $fpPadre = (int)($fp['parent_id'] ?? 0);
            if ($fpPadre && isset($byId[$fpPadre])) $fpMeta[] = t('progetti.in_progetto', ['nome' => $byId[$fpPadre]['name']]);
            foreach (prog_date_tags_info($fp) as $dt) {
                if ($dt['campo'] === 'scadenza') $fpMeta[] = t('progetti.meta_scadenza', ['d' => $dt['label']]);
            }
        ?>
        <a class="pr-ov-r" href="progetti.php?id=<?php echo (int)$fid; ?>">
            <svg class="pr-ov-star" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 4.2l2.3 4.9 5.2.7-3.8 3.7 1 5.3-4.7-2.6-4.7 2.6 1-5.3-3.8-3.7 5.2-.7z"/></svg>
            <span class="pr-ov-t"><b><?php echo sec_h($fp['name']); ?></b><?php if ($fpMeta): ?><small><?php echo sec_h(implode(' · ', $fpMeta)); ?></small><?php endif; ?></span>
            <svg class="pr-ov-chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m9 6 6 6-6 6"/></svg>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <div class="pr-lab"><span><?php te('progetti.i_tuoi_progetti'); ?></span><span><?php te('progetti.concentrazione'); ?></span></div>
    <div class="pr-ov">
        <?php foreach ($rootProjects as $rp):
            $rid = (int)$rp['id'];
            $rColor = (!empty($rp['color']) && preg_match('/^#[0-9A-Fa-f]{6}$/', $rp['color'])) ? $rp['color'] : '#8E8E93';
            $rTot = (int)($figliTot[$rid] ?? 0); $rFatti = (int)($figliFatti[$rid] ?? 0);
            $rChiuso = ($rp['stato'] ?? '') === 'completed';
            $rMeta = [];
            if ($rChiuso) $rMeta[] = t('progetti.fatto');
            elseif ($rTot > 0) $rMeta[] = t('progetti.n_di_m', ['n' => $rFatti, 'm' => $rTot]);
            foreach (prog_date_tags_info($rp) as $dt) {
                $rMeta[] = t($dt['campo'] === 'scadenza' ? 'progetti.meta_scadenza' : 'progetti.meta_obiettivo', ['d' => $dt['label']]);
            }
            if (isset($corsoLinks[$rid])) $rMeta[] = $corsoLinks[$rid]['nome'];
            $rDur = prog_fmt_duration($totalMemo[$rid] ?? 0);
            $rPct = $rChiuso ? 100 : ($rTot > 0 ? (int)round($rFatti * 100 / $rTot) : 0);
        ?>
        <a class="pr-ov-r" href="progetti.php?id=<?php echo $rid; ?>" style="--row-color:<?php echo sec_h($rColor); ?>">
            <i class="pr-ov-dot"></i>
            <span class="pr-ov-t">
                <b><?php echo sec_h($rp['name']); ?></b>
                <?php if ($rMeta): ?><small><?php echo sec_h(implode(' · ', $rMeta)); ?></small><?php endif; ?>
                <?php if ($rTot > 0 || $rChiuso): ?><span class="pr-ov-bar"><i style="width:<?php echo $rPct; ?>%"></i></span><?php endif; ?>
            </span>
            <span class="pr-ov-f"><b><?php echo $rDur !== '' ? sec_h($rDur) : '–'; ?></b><small><?php te('progetti.in_tutto'); ?></small></span>
        </a>
        <?php endforeach; ?>
        <button type="button" class="pr-add-line pr-add-proj" data-on-click="prNuovoProgetto(this)">
            <span class="pr-add-ic"><?php echo prog_piu_svg(); ?></span><span><?php te('progetti.nuovo_progetto'); ?></span>
        </button>
    </div>
<?php else:
    $accent = (!empty($selectedProject['color']) && preg_match('/^#[0-9A-Fa-f]{6}$/', $selectedProject['color'])) ? $selectedProject['color'] : '#8E8E93';
    $heroNote = sec_h($selectedProject['note'] ?? '');
    $selId = (int)$selectedProject['id'];
    $selTot = (int)($figliTot[$selId] ?? 0); $selFatti = (int)($figliFatti[$selId] ?? 0);
?>
    <?php if (!empty($ancestorChain)): ?>
    <div class="pr-crumbs">
        <?php foreach ($ancestorChain as $anc):
            $ancColor = (!empty($anc['color']) && preg_match('/^#[0-9A-Fa-f]{6}$/', $anc['color'])) ? $anc['color'] : '#8E8E93';
        ?>
        <a class="pr-crumb" style="--crumb-color:<?php echo sec_h($ancColor); ?>;" href="progetti.php?id=<?php echo (int)$anc['id']; ?>" data-drop-id="<?php echo (int)$anc['id']; ?>">
            <span class="pr-crumb-dot"></span><?php echo sec_h($anc['name']); ?>
        </a>
        <span class="pr-crumb-sep">›</span>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php
/* ==========================================================================
   IL COLLEGAMENTO AL DECK, VISTO DA QUESTO PROGETTO
   Quattro stati, e sono esclusivi: un progetto puo' avere il proprio deck, o
   ereditarne uno dal padre, o essere il ramo dentro cui i figli ne hanno, o
   nessuna delle tre. Si calcolano qui, una volta, invece che nel markup:
   ficcare tre giri di ricerca dentro all'HTML avrebbe reso illeggibile sia
   l'HTML sia la regola.
   ========================================================================== */
$deckStato = 'nessuno';   // nessuno | proprio | ereditato | figli
$deckDati  = null;

$deckProprio = $catLinks[$selectedProject['id']] ?? null;
if ($deckProprio) {
    $deckStato = 'proprio';
    $deckDati  = $deckProprio;
} else {
    /* I figli, a qualunque profondita': se anche uno solo dei discendenti ha un
       deck, questo progetto non puo' averne uno suo — sarebbe un mazzo che
       contiene mazzi, e non e' cosi' che funzionano. */
    $conDeck = [];
    $pila = $childrenOf[$selectedProject['id']] ?? [];
    $giri = 0;
    while ($pila && $giri++ < 5000) {
        $x = array_pop($pila);
        if (isset($catLinks[$x])) $conDeck[] = ['nome' => $byId[$x]['name'] ?? '', 'deck' => $catLinks[$x]];
        foreach ($childrenOf[$x] ?? [] as $f) $pila[] = $f;
    }
    if ($conDeck) {
        $deckStato = 'figli';
        $deckDati  = $conDeck;
    } else {
        // il padre, risalendo: il deck si eredita da chiunque sopra ce l'abbia
        $su = $selectedProject['parent_id'] ?? null;
        $giri = 0;
        while ($su && $giri++ < 50) {
            if (isset($catLinks[(int)$su])) {
                $deckStato = 'ereditato';
                $deckDati  = $catLinks[(int)$su];
                break;
            }
            $su = $byId[(int)$su]['parent_id'] ?? null;
        }
    }
}
?>
<?php
    $heroTodo = (int)($selectedProject['todo_enabled'] ?? 0) === 1;
    $heroFav = (int)($selectedProject['is_favorite'] ?? 0) === 1;
    $corsoHero = $corsoLinks[$selId] ?? null;
    $dTag = prog_date_tags_info($selectedProject);
    $totDur = prog_fmt_duration($totalMemo[$selId] ?? 0);
    $statsHero = ' data-stats-id="' . $selId . '" data-stats-nome="' . sec_h($selectedProject['name']) . '" data-stats-colore="' . sec_h($accent) . '"'
               . ' data-on-click="prOpenStats(+this.dataset.statsId, this.dataset.statsNome, this.dataset.statsColore)"';
    $vuoiFigliAperti = false;
    foreach ($selectedTree['children'] ?? [] as $fc) { if (!empty($fc['children'])) { $vuoiFigliAperti = true; break; } }

?>
    <?php
    /* ======================================================================
       LA SCHEDA DEL PROGETTO
       Il nome, una riga grigia con quello che serve sapere, la barra di quanto
       è fatto, la nota e due pulsanti: Concentrati (l'unica cosa colorata
       della pagina) e Aggiungi. Tutto il resto — colore, preferito, date,
       deck, attività, selezione, griglia, elimina — sta nel menu ⋯.
       Prima c'erano tre etichette tratteggiate, quattro tondi, un riquadro
       con l'orologio e una barra laterale colorata: ognuno a suo modo chiedeva
       attenzione, e la pagina sembrava disordinata anche quando non lo era.
       ====================================================================== */
    ?>
    <div class="pr-hero" style="--accent:<?php echo sec_h($accent); ?>; --accent-ink:<?php echo prog_inchiostro($accent); ?>;">
        <div class="pr-hero-top">
            <button type="button" class="pr-check pr-check-<?php echo sec_h($selectedProject['stato']); ?>" data-id="<?php echo $selId; ?>" aria-label="<?php te('progetti.cambia_stato'); ?>">
                <svg class="pr-ic-empty" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9.5"/></svg>
                <svg class="pr-ic-done" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10.5" fill="currentColor"/><path d="M7.6 12.4l3 3 5.8-6.3" fill="none" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                <svg class="pr-ic-planned" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9.5"/><path d="M12 7.5v4.8l3 2" stroke-linecap="round"/></svg>
            </button>
            <div class="pr-hero-title-wrap">
                <span class="pr-hero-title<?php echo $selectedProject['stato'] === 'completed' ? ' pr-name-done' : ''; ?>"><?php
                    /* Il nome si modifica scrivendoci sopra. */
                    echo '<span class="pr-hero-name" id="prHeroName" contenteditable="true" spellcheck="false"'
                       . ' data-id="' . $selId . '"'
                       . ' data-prima="' . sec_h($selectedProject['name']) . '">'
                       . sec_h($selectedProject['name']) . '</span>';
                ?></span>
                <svg class="pr-hero-star" id="prHeroStar" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"<?php echo $heroFav ? '' : ' hidden'; ?>><path d="M12 4.2l2.3 4.9 5.2.7-3.8 3.7 1 5.3-4.7-2.6-4.7 2.6 1-5.3-3.8-3.7 5.2-.7z"/></svg>
            </div>
            <?php /* Concentrati: un tondo del colore del progetto accanto al ⋯, come
                     il play di un lettore. Quando il pomodoro di questo progetto è
                     in corso diventa una pastiglia col tempo che scorre. */ ?>
            <button type="button" class="pr-focus<?php echo isset($pomoRamo[$selId]) ? ' is-live' : ''; ?>"<?php echo $statsHero; ?> aria-label="<?php te('progetti.concentrati'); ?>" title="<?php te('progetti.concentrati'); ?>">
                <?php if (isset($pomoRamo[$selId])): ?>
                <i class="pr-act-live"></i><span data-pomo-live><?php echo sec_h(prog_tempo_vivo($pomoAttivo)); ?></span>
                <?php else: ?>
                <?php echo prog_timer_svg(); ?>
                <?php endif; ?>
            </button>
            <button type="button" class="pr-more-btn" data-on-click="prApriAltro()" aria-label="<?php te('progetti.altro'); ?>" title="<?php te('progetti.altro'); ?>">
                <svg viewBox="0 0 24 24" fill="currentColor"><circle cx="5.5" cy="12" r="1.8"/><circle cx="12" cy="12" r="1.8"/><circle cx="18.5" cy="12" r="1.8"/></svg>
            </button>
        </div>

        <?php
        /* La riga grigia: solo quello che c'è. Le date e il tempo si toccano
           per cambiarli o vederli; quello che manca si aggiunge dal menu ⋯,
           invece di stare qui come tre etichette tratteggiate vuote. */
        $haMetaHero = $selTot > 0 || $dTag || $totDur !== '' || $deckStato === 'proprio' || $deckStato === 'ereditato' || $corsoHero;
        ?>
        <?php if ($haMetaHero): ?>
        <div class="pr-hero-meta">
            <?php if ($selTot > 0): ?><span class="pr-hm"><?php echo sec_h(t('progetti.n_di_m_fatti', ['n' => $selFatti, 'm' => $selTot])); ?></span><?php endif; ?>
            <?php foreach ($dTag as $dt): ?>
            <button type="button" class="pr-hm pr-hm-link pr-md-<?php echo sec_h($dt['status']); ?>"
                    data-valore="<?php echo sec_h($selectedProject[$dt['campo'] === 'scadenza' ? 'data_scadenza' : 'data_obiettivo'] ?? ''); ?>"
                    data-on-click="prScegliData(<?php echo $selId; ?>, '<?php echo $dt['campo']; ?>', this)"><?php
                echo sec_h(t($dt['campo'] === 'scadenza' ? 'progetti.meta_scadenza' : 'progetti.meta_obiettivo', ['d' => $dt['label']]));
            ?></button>
            <?php endforeach; ?>
            <?php if ($totDur !== ''): ?><button type="button" class="pr-hm pr-hm-link"<?php echo $statsHero; ?>><?php echo sec_h(t('progetti.tempo_concentrazione', ['t' => $totDur])); ?></button><?php endif; ?>
            <?php if ($corsoHero && $deckStato === 'nessuno'): ?>
            <a class="pr-hm pr-hm-link pr-hm-deck" href="../fc/fc_decks.php?course=<?php echo (int)$corsoHero['id']; ?>"><img src="<?php echo sec_h($corsoHero['flag']); ?>" alt="" width="13" height="13"><?php echo sec_h($corsoHero['nome']); ?></a>
            <?php elseif ($deckStato === 'proprio'): ?>
            <button type="button" class="pr-hm pr-hm-link pr-hm-deck" data-on-click="prScegliDeck(<?php echo $selId; ?>, <?php echo (int)$deckDati['id']; ?>)">
                <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z"/></svg><?php echo sec_h($deckDati['name']); ?>
            </button>
            <?php elseif ($deckStato === 'ereditato'): ?>
            <span class="pr-hm pr-hm-deck pr-hm-eredit" title="<?php te('progetti.deck_dal_padre'); ?>">
                <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z"/></svg><?php echo sec_h($deckDati['name']); ?>
            </span>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php if ($selTot > 0): ?>
        <div class="pr-hero-bar" role="progressbar" aria-valuemin="0" aria-valuemax="<?php echo $selTot; ?>" aria-valuenow="<?php echo $selFatti; ?>"><i style="width:<?php echo (int)round($selFatti * 100 / $selTot); ?>%"></i></div>
        <?php endif; ?>

        <div class="pr-hero-todo<?php echo $heroTodo ? '' : ' pr-hidden'; ?>" id="prHeroTodo"></div>
        <?php /* l'interruttore delle attività vive nel menu ⋯: qui resta il suo stato */ ?>
        <button type="button" id="prTodoBtn" class="<?php echo $heroTodo ? 'is-on' : ''; ?>" data-project="<?php echo $selId; ?>" hidden></button>

        <!-- La nota: piena si legge e si apre con un tocco lungo (un tocco
             semplice a metà lettura aprirebbe la tastiera); vuota è una riga
             chiara «Aggiungi una nota» che si apre con un tocco, perché lì non
             c'è niente da leggere. -->
        <div class="pr-hero-note<?php echo $heroNote === '' ? ' pr-nota-vuota' : ' raw-text-content'; ?>"
             id="prNota" data-id="<?php echo $selId; ?>"
             data-raw="<?php echo $heroNote; ?>"><?php
            if ($heroNote === '') te('progetti.aggiungi_nota');
        ?></div>
        <div class="pr-nota-edit" id="prNotaEdit" hidden>
            <?php
                $ft_editor_id = 'prNotaEditor';
                $ft_field = 'prNotaField';
                $ft_raw = $selectedProject['note'] ?? '';
                $ft_placeholder = t('progetti.scrivi_nota');
                $ft_open = false;
                include __DIR__ . '/../partials/format_toolbar.php';
            ?>
        </div>
    </div>

    <?php /* LE TRE VISTE DEL PROGETTO: Elenco (i sotto-progetti), Calendario
             (evidenziare i giorni) e Piano (le ore e come si dividono). Prende
             il posto della vecchia scheda «Piano di studio». Le due nuove sono
             disegnate da piano.js dentro #ppHost; l'Elenco è tutto il resto
             della pagina, che il CSS nasconde mentre se ne guarda un'altra. */ ?>
    <nav class="pp-views" id="ppViews" aria-label="<?php te('piano.v_elenco'); ?>">
        <button type="button" data-pp="list" class="on"><?php te('piano.v_elenco'); ?></button>
        <button type="button" data-pp="cal"><?php te('piano.v_calendario'); ?></button>
        <button type="button" data-pp="plan"><?php te('piano.v_piano'); ?></button>
    </nav>
    <div id="ppHost" data-project="<?php echo $selId; ?>"></div>

    <?php if (!empty($selectedTree['children'])): ?>
    <?php /* L'etichetta porta anche gli strumenti dell'elenco — scegliere,
             griglia, chiudere i rami — fra il nome e il conto: prima stavano
             nel menu ⋯, dove nessuno li andava a cercare. */ ?>
    <div class="pr-lab">
        <span><?php te('progetti.sotto_progetti'); ?></span>
        <span class="pr-lab-tools">
            <?php if (count($selectedTree['children']) >= 2): ?>
            <button type="button" class="pr-lab-btn" id="prSelectSubBtn" data-on-click="prToggleSubSelecting()"
                    aria-label="<?php te('progetti.seleziona_sotto_progetti'); ?>" title="<?php te('progetti.seleziona_sotto_progetti'); ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="3.5" y="3.5" width="17" height="17" rx="4.5"/><path d="M7.8 12.2l2.9 2.9 5.6-6"/></svg>
            </button>
            <?php endif; ?>
            <button type="button" class="pr-lab-btn" id="prVistaBtn" data-on-click="prAltroVista()"
                    aria-label="<?php te('progetti.mostra_griglia'); ?>" title="<?php te('progetti.mostra_griglia'); ?>"></button>
            <?php if ($vuoiFigliAperti): ?>
            <button type="button" class="pr-lab-btn" id="prRamiBtn" data-on-click="prFoldAll()"
                    aria-label="<?php te('progetti.chiudi_tutto'); ?>" title="<?php te('progetti.chiudi_tutto'); ?>"></button>
            <?php endif; ?>
        </span>
        <span class="pr-lab-n"><?php echo $selFatti . ' / ' . $selTot; ?></span>
    </div>
    <?php endif; ?>
    <!-- La barra delle azioni della selezione resta in basso mentre si scorre. -->
    <div class="pr-sub-bulkbar" id="prSubBulkbar" data-sb-popup>
        <button type="button" class="pr-sub-bulk-x" data-on-click="prToggleSubSelecting()" aria-label="<?php te('comune.annulla'); ?>">
            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"><path d="M6 6l12 12M18 6 6 18"/></svg>
        </button>
        <span id="prSubBulkCount"><?php te('progetti.0_selezionati'); ?></span>
        <button type="button" class="pr-sub-bulk-btn" id="prSubSelectAllBtn" data-on-click="prSubSelectAll()"><?php te('progetti.seleziona_tutti'); ?></button>
        <button type="button" class="pr-sub-bulk-btn accent" data-on-click="prSubBulkColorOpen()"><?php te('progetti.colore'); ?></button>
        <button type="button" class="pr-sub-bulk-btn accent" data-on-click="prSubBulkReparentOpen()"><?php te('progetti.progetto_padre'); ?></button>
        <button type="button" class="pr-sub-bulk-btn danger" data-on-click="prSubBulkDeleteOpen()"><?php te('comune.elimina'); ?></button>
    </div>
    <div class="pr-list" id="prList" data-parent="<?php echo $selId; ?>" style="--row-color:<?php echo sec_h($accent); ?>;">
        <?php foreach ($selectedTree['children'] ?? [] as $child) { prog_render_row($child, $childrenOf, $totalMemo, 0, $pomoInCorso); } ?>
        <button type="button" class="pr-add-line" id="prAddMain" data-parent="<?php echo $selId; ?>" data-on-click="prAggiungi(<?php echo $selId; ?>)">
            <span class="pr-add-ic"><?php echo prog_piu_svg(); ?></span><span><?php te('progetti.aggiungi_un_sotto'); ?></span>
        </button>
    </div>
    <?php if (!empty($selectedTree['children'])): ?>
    <!-- La griglia: gli stessi sotto-progetti come schede. Si sceglie dal
         menu ⋯ («Mostra come griglia») e la scelta resta. -->
    <div class="pr-grid" id="prGrid" style="display:none;">
        <?php foreach ($selectedTree['children'] as $child):
            $gid = (int)$child['id'];
            $gcolor = (!empty($child['color']) && preg_match('/^#[0-9A-Fa-f]{6}$/', $child['color'])) ? $child['color'] : '#8E8E93';
            $gdur = prog_fmt_duration($totalMemo[$gid] ?? 0);
            $gTot = (int)($figliTot[$gid] ?? 0); $gFatti = (int)($figliFatti[$gid] ?? 0);
            $gCat = $catLinks[$gid] ?? null;
            $gCorso = $corsoLinks[$gid] ?? null;
            $gNote = sec_h($child['note'] ?? '');
            $gDateTags = prog_date_tags_info($child);
            $gChiuso = $child['stato'] === 'completed';
        ?>
        <a class="pr-grid-card<?php echo $gChiuso ? ' is-done' : ''; ?>" style="--row-color:<?php echo sec_h($gcolor); ?>;" href="progetti.php?id=<?php echo $gid; ?>">
            <span class="pr-grid-top">
                <i class="pr-grid-dot"></i>
                <?php if ($gNote !== ''): ?>
                <span class="pr-note-tag" data-note-raw="<?php echo $gNote; ?>" data-note-id="<?php echo $gid; ?>"
                      data-on-click="event.preventDefault(); event.stopPropagation(); prOpenNotePeek(<?php echo $gid; ?>, this)" aria-label="<?php te('progetti.mostra_nota'); ?>">
                    <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10l6-6V5a2 2 0 0 0-2-2Z"/><path d="M15 21v-4a2 2 0 0 1 2-2h4"/></svg>
                </span>
                <?php endif; ?>
            </span>
            <span class="pr-grid-name<?php echo $gChiuso ? ' pr-name-done' : ''; ?>"><?php echo sec_h($child['name']); ?></span>
            <span class="pr-grid-sub"><?php
                $gMeta = [];
                foreach ($gDateTags as $gdt) $gMeta[] = '<span class="pr-md-' . sec_h($gdt['status']) . '">' . sec_h(t($gdt['campo'] === 'scadenza' ? 'progetti.meta_scadenza' : 'progetti.meta_obiettivo', ['d' => $gdt['label']])) . '</span>';
                if ($gCat) $gMeta[] = sec_h($gCat['name']);
                if ($gCorso) $gMeta[] = sec_h($gCorso['nome']);
                echo implode(' · ', $gMeta);
            ?></span>
            <?php if ($gTot > 0): ?><span class="pr-grid-bar"><i style="width:<?php echo (int)round($gFatti * 100 / $gTot); ?>%"></i></span><?php endif; ?>
            <span class="pr-grid-meta">
                <span><?php echo $gdur !== '' ? sec_h($gdur) : '–'; ?></span>
                <?php if ($gTot > 0): ?><span><?php echo sec_h(t('progetti.n_di_m', ['n' => $gFatti, 'm' => $gTot])); ?></span><?php endif; ?>
            </span>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php
    /* ======================================================================
       IL MENU ⋯
       Tutto quello che si fa di rado, in un posto solo e con le parole: prima
       erano tondi senza scritte e etichette tratteggiate sparse nella scheda.
       ====================================================================== */
    $altroVal = function ($campo) use ($selectedProject) {
        $v = $selectedProject[$campo] ?? '';
        return $v ? prog_data_breve($v) : t('progetti.aggiungi_valore');
    };
    ?>
    <div class="pr-sheet-overlay" id="prAltroOv" data-on-click="if(event.target===this) prChiudiAltro();">
        <div class="pr-sheet pr-altro" role="dialog" aria-modal="true" aria-label="<?php te('progetti.altro'); ?>">
            <div class="pr-sheet-grabber"></div>
            <div class="pr-altro-tit"><?php echo sec_h($selectedProject['name']); ?></div>
            <div class="pr-altro-body">
                <div class="pr-altro-grp">
                    <button type="button" class="pr-altro-it" data-on-click="prChiudiAltro(); prScegliColore(<?php echo $selId; ?>)">
                        <span class="pr-altro-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><circle cx="12" cy="12" r="8.5"/></svg></span>
                        <span class="pr-altro-n"><?php te('progetti.colore'); ?></span>
                        <em><i class="pr-altro-dot" style="background:<?php echo sec_h($accent); ?>"></i></em>
                    </button>
                    <button type="button" class="pr-altro-it" id="prAltroFav" data-id="<?php echo $selId; ?>" data-on="<?php echo $heroFav ? '1' : '0'; ?>" data-on-click="prAltroPreferito(this)">
                        <span class="pr-altro-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linejoin="round"><path d="M12 4.2l2.3 4.9 5.2.7-3.8 3.7 1 5.3-4.7-2.6-4.7 2.6 1-5.3-3.8-3.7 5.2-.7z"/></svg></span>
                        <span class="pr-altro-n"><?php te('progetti.preferito'); ?></span>
                        <em class="pr-sw<?php echo $heroFav ? ' on' : ''; ?>"><i></i></em>
                    </button>
                    <button type="button" class="pr-altro-it" data-valore="<?php echo sec_h($selectedProject['data_scadenza'] ?? ''); ?>" data-on-click="prChiudiAltro(); prScegliData(<?php echo $selId; ?>, 'scadenza', this)">
                        <span class="pr-altro-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="3.5" y="5" width="17" height="15.5" rx="3.5"/><path d="M3.5 10h17M8 3v4M16 3v4"/></svg></span>
                        <span class="pr-altro-n"><?php te('progetti.data_scadenza'); ?></span>
                        <em><?php echo sec_h($altroVal('data_scadenza')); ?></em>
                    </button>
                    <button type="button" class="pr-altro-it" data-valore="<?php echo sec_h($selectedProject['data_obiettivo'] ?? ''); ?>" data-on-click="prChiudiAltro(); prScegliData(<?php echo $selId; ?>, 'obiettivo', this)">
                        <span class="pr-altro-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"><circle cx="12" cy="12" r="8.5"/><circle cx="12" cy="12" r="4.5"/></svg></span>
                        <span class="pr-altro-n"><?php te('progetti.data_obiettivo'); ?></span>
                        <em><?php echo sec_h($altroVal('data_obiettivo')); ?></em>
                    </button>
                    <?php if ($corsoHero && $deckStato === 'nessuno'): ?>
                    <a class="pr-altro-it" href="../fc/fc_decks.php?course=<?php echo (int)$corsoHero['id']; ?>">
                        <span class="pr-altro-ic"><img src="<?php echo sec_h($corsoHero['flag']); ?>" alt="" width="18" height="18"></span>
                        <span class="pr-altro-n"><?php te('progetti.flashcard'); ?></span>
                        <em><?php echo sec_h($corsoHero['nome']); ?></em>
                    </a>
                    <?php elseif ($deckStato === 'ereditato' || $deckStato === 'figli'): ?>
                    <div class="pr-altro-it is-off" title="<?php te($deckStato === 'ereditato' ? 'progetti.deck_dal_padre' : 'progetti.deck_nei_figli'); ?>">
                        <span class="pr-altro-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z"/></svg></span>
                        <span class="pr-altro-n"><?php te('progetti.flashcard'); ?><small><?php te($deckStato === 'ereditato' ? 'progetti.deck_dal_padre' : 'progetti.deck_nei_figli'); ?></small></span>
                        <em><?php echo $deckStato === 'ereditato' ? sec_h($deckDati['name']) : ''; ?></em>
                    </div>
                    <?php else: ?>
                    <button type="button" class="pr-altro-it" data-on-click="prChiudiAltro(); prScegliDeck(<?php echo $selId; ?>, <?php echo $deckStato === 'proprio' ? (int)$deckDati['id'] : 0; ?>)">
                        <span class="pr-altro-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z"/></svg></span>
                        <span class="pr-altro-n"><?php te('progetti.flashcard'); ?></span>
                        <em><?php echo $deckStato === 'proprio' ? sec_h($deckDati['name']) : sec_h(t('progetti.collega')); ?></em>
                    </button>
                    <?php endif; ?>
                    <button type="button" class="pr-altro-it" data-on-click="prChiudiAltro(); prNotaApri()">
                        <span class="pr-altro-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10l6-6V5a2 2 0 0 0-2-2Z"/><path d="M15 21v-4a2 2 0 0 1 2-2h4"/></svg></span>
                        <span class="pr-altro-n"><?php te('progetti.nota'); ?></span>
                        <em id="prAltroNota"><?php echo $heroNote === '' ? sec_h(t('progetti.aggiungi_valore')) : sec_h(t('comune.modifica')); ?></em>
                    </button>
                    <button type="button" class="pr-altro-it" id="prAltroTodo" data-on-click="prAltroAttivita(this)">
                        <span class="pr-altro-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7l2.2 2.2L10 5.5M4 17l2.2 2.2L10 15.5M13 7.5h7M13 17.5h7"/></svg></span>
                        <span class="pr-altro-n"><?php te('progetti.attivita'); ?></span>
                        <em class="pr-sw<?php echo $heroTodo ? ' on' : ''; ?>"><i></i></em>
                    </button>
                    <?php /* Spostare il progetto sotto un altro (o in cima): era l'ultima
                             cosa che si faceva solo dal popup «Modifica progetto». */
                    $padreSel = (int)($selectedProject['parent_id'] ?? 0); ?>
                    <button type="button" class="pr-altro-it" data-on-click="prChiudiAltro(); prSpostaIn(<?php echo $selId; ?>)">
                        <span class="pr-altro-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z"/><path d="M10 13h6M13.5 10.5 16 13l-2.5 2.5"/></svg></span>
                        <span class="pr-altro-n"><?php te('progetti.sposta_in'); ?></span>
                        <em><?php echo sec_h($padreSel > 0 && isset($byId[$padreSel]) ? $byId[$padreSel]['name'] : t('progetti.livello_principale')); ?></em>
                    </button>
                </div>
                <div class="pr-altro-grp">
                    <button type="button" class="pr-altro-it is-red" data-on-click="prChiudiAltro(); prConfirmDelete(<?php echo $selId; ?>, <?php echo !empty($selectedTree['children']) ? 'true' : 'false'; ?>)">
                        <span class="pr-altro-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0-1 14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2L4 6"/></svg></span>
                        <span class="pr-altro-n"><?php te('progetti.elimina_progetto'); ?></span>
                    </button>
                </div>
            </div>
        </div>
    </div>

<?php endif; ?>
</div>

<?php
    /* Il selettore del deck, senza un tasto proprio: lo apre la voce
       «Flashcard» del menu ⋯ (prScegliDeck). Prima viveva dentro il popup
       «Modifica progetto», che non c'è più: tutto quello che conteneva sta
       nel menu ⋯ o si cambia sul posto. */
    $cs_id = 'category';
    $cs_selected_id = 0; $cs_selected_name = ''; $cs_selected_color = null;
    $cs_placeholder = t('progetti.nessuna_materia');
    $cs_title = t('progetti.collega_materia');
    $cs_show_all_option = true; $cs_all_option_label = t('progetti.nessuna_materia');
    $cs_allow_create = false; $cs_render_trigger = false; $cs_hidden_name = 'fc_category_id';
    include __DIR__ . '/../fc/fc_deck_selector.php';
?>

<!-- ================= SCADENZE E OBIETTIVI ================= -->
<div class="pr-sheet-overlay" id="prDatesOverlay" data-on-click="if(event.target===this) prCloseDatesView();">
    <div class="pr-sheet">
        <div class="pr-sheet-grabber"></div>
        <div class="pr-sheet-head">
            <div class="pr-sheet-title"><?php te('progetti.scadenze_e_obiettivi'); ?></div>
            <button type="button" class="pr-sheet-close" data-on-click="prCloseDatesView()" aria-label="<?php te('comune.chiudi'); ?>">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.8" stroke-linecap="round"><path d="M6 6l12 12M18 6 6 18"/></svg>
            </button>
        </div>
        <div class="pr-sheet-body">
            <div class="pr-dates-row">
                <div class="pr-view-switch" id="prDatesFieldSwitch">
                    <button type="button" data-field="scadenza" data-on-click="prDatesSetField('scadenza')"><?php te('progetti.scadenza_2'); ?></button>
                    <button type="button" data-field="obiettivo" data-on-click="prDatesSetField('obiettivo')"><?php te('progetti.obiettivo'); ?></button>
                </div>
                <button type="button" class="pr-dates-sort-btn" id="prDatesSortBtn" data-on-click="prDatesToggleSort()">
                    <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7h18M6 12h12M10 17h4"/></svg>
                    <span id="prDatesSortLabel"><?php te('progetti.piu_vicine'); ?></span>
                </button>
            </div>
            <div class="pr-dates-filters" id="prDatesFilters">
                <button type="button" class="pr-dates-chip" data-filter="all" data-on-click="prDatesSetFilter('all')"><?php te('progetti.tutte'); ?></button>
                <button type="button" class="pr-dates-chip" data-filter="past" data-on-click="prDatesSetFilter('past')"><?php te('progetti.passate'); ?></button>
                <button type="button" class="pr-dates-chip" data-filter="today" data-on-click="prDatesSetFilter('today')"><?php te('comune.oggi'); ?></button>
                <button type="button" class="pr-dates-chip" data-filter="future" data-on-click="prDatesSetFilter('future')"><?php te('progetti.future'); ?></button>
            </div>
            <div class="pr-dates-list" id="prDatesList"></div>
        </div>
    </div>
</div>

<!-- ================= CONFERMA ELIMINAZIONE ================= -->
<div class="pr-confirm-overlay" id="prConfirmOverlay">
    <div class="pr-confirm-box">
        <div class="pr-confirm-title" id="prConfirmTitle"><?php te('progetti.eliminare_questo_progetto'); ?></div>
        <div class="pr-confirm-msg" id="prConfirmMsg"><?php te('progetti.questa_azione_non_puo_essere'); ?></div>
        <div class="pr-confirm-actions stack" id="prConfirmActions"></div>
    </div>
</div>

<!-- ================= CAMBIA COLORE (selezione multipla) ================= -->
<!-- Colore di un progetto solo. Riusa la stessa tavolozza di quella in blocco
     — stessi colori standard, stesso «automatico», stesso colore
     personalizzato — perché scegliere un colore è la stessa operazione che si
     scelga per uno o per venti, e due pannelli diversi per la stessa cosa si
     comportano diversamente al primo cambiamento. -->
<div class="pr-confirm-overlay" id="prColorOverlay">
    <div class="pr-confirm-box">
        <div class="pr-confirm-title"><?php te('progetti.cambia_colore'); ?></div>
        <div class="pr-color-grid">
            <!-- «Automatico» rimette l'eredità dal padre, non un grigio: un
                 sotto-progetto senza colore proprio segue quello della materia,
                 ed è quello che si vuole tornando indietro. -->
            <button type="button" class="pr-color-swatch pr-color-swatch-auto" data-on-click="prColorePick('auto')"
                    aria-label="<?php te('progetti.automatico_dal_padre_2'); ?>" title="<?php te('progetti.automatico_dal_padre_2'); ?>">A</button>
            <?php foreach ($prColorPalette as $hex): ?>
            <button type="button" class="pr-color-swatch" style="background:<?php echo sec_h($hex); ?>; --sw:<?php echo sec_h($hex); ?>;" data-on-click="prColorePick('<?php echo sec_h($hex); ?>')">
                <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="3.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 13l4 4L19 7"/></svg>
            </button>
            <?php endforeach; ?>
        </div>
        <div class="pr-custom-color-row">
            <input type="color" class="pr-custom-color-input" id="prColoreCustom" value="<?php echo sec_h($accent ?? '#8E8E93'); ?>" data-on-input="prColorePick(this.value)">
            <span class="pr-custom-color-label"><?php te('progetti.colore_personalizzato'); ?></span>
        </div>
        <div class="pr-confirm-actions" style="margin-top:16px;">
            <button type="button" class="pr-confirm-btn" data-on-click="document.getElementById('prColorOverlay').classList.remove('show')"><?php te('comune.annulla'); ?></button>
        </div>
    </div>
</div>

<div class="pr-confirm-overlay" id="prBulkColorOverlay">
    <div class="pr-confirm-box">
        <div class="pr-confirm-title"><?php te('progetti.cambia_colore'); ?></div>
        <div class="pr-confirm-msg"><?php te('progetti.si_applica_a_tutti_i'); ?></div>
        <div class="pr-color-grid" id="prBulkColorGrid">
            <button type="button" class="pr-color-swatch pr-color-swatch-auto" data-on-click="prSubBulkColorPick('auto')" aria-label="<?php te('progetti.automatico_dal_padre_2'); ?>" title="<?php te('progetti.automatico_dal_padre_2'); ?>">A</button>
            <?php foreach ($prColorPalette as $hex): ?>
            <button type="button" class="pr-color-swatch" style="background:<?php echo sec_h($hex); ?>; --sw:<?php echo sec_h($hex); ?>;" data-on-click="prSubBulkColorPick('<?php echo sec_h($hex); ?>')">
                <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="3.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 13l4 4L19 7"/></svg>
            </button>
            <?php endforeach; ?>
        </div>
        <div class="pr-custom-color-row">
            <input type="color" class="pr-custom-color-input" id="prBulkCustomColorInput" value="#8E8E93" data-on-input="prSubBulkColorPick(this.value)">
            <span class="pr-custom-color-label"><?php te('progetti.colore_personalizzato'); ?></span>
        </div>
        <div class="pr-confirm-actions" style="margin-top:16px;">
            <button type="button" class="pr-btn pr-btn-cancel" data-on-click="prCloseBulkColor()"><?php te('comune.annulla'); ?></button>
        </div>
    </div>
</div>

<div class="pr-toast" id="prToast"></div>

<!-- ================= POST-IT NOTA ================= -->
<div class="pr-note-peek-overlay" id="prNotePeekOverlay" data-on-click="if(event.target===this) prCloseNotePeek();">
    <div class="pr-note-peek">
        <div class="pr-note-peek-head">
            <span class="pr-note-peek-title"><?php te('progetti.nota'); ?></span>
            <div class="pr-note-peek-actions">
                <button type="button" class="pr-note-peek-btn" id="prNotePeekEdit" aria-label="<?php te('comune.modifica'); ?>">
                    <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                </button>
                <button type="button" class="pr-note-peek-btn" data-on-click="prCloseNotePeek()" aria-label="<?php te('comune.chiudi'); ?>">
                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.8" stroke-linecap="round"><path d="M6 6l12 12M18 6 6 18"/></svg>
                </button>
            </div>
        </div>
        <div class="pr-note-peek-body raw-text-content" id="prNotePeekBody"></div>
        <!-- L'editore del post-it: stessa nota, stesso posto. La barra parte
             chiusa come nel resto della pagina. -->
        <div class="pr-peek-edit" id="prPeekEdit" hidden>
            <?php
                $ft_editor_id = 'prPeekEditor';
                $ft_field = 'prPeekField';
                $ft_raw = '';
                $ft_placeholder = t('progetti.scrivi_nota');
                $ft_open = false;
                include __DIR__ . '/../partials/format_toolbar.php';
            ?>
        </div>
    </div>
</div>

<?php
    /* Selettore dedicato a "cambia progetto padre" per la selezione multipla dei
       sotto-progetti: nessun tasto proprio (lo apriamo noi da JS), e i vincoli
       (selezionati + loro discendenti non scelgibili) li applichiamo via JS,
       esattamente come fa pomodoro_import.php col proprio selettore. */
    $ps_id = 'bulkreparent'; $ps_selected_id = 0; $ps_selected_name = ''; $ps_selected_color = null;
    $ps_placeholder = t('progetti.seleziona_progetto'); $ps_title = t('progetti.sposta_sotto');
    $ps_show_all_option = true; $ps_all_option_label = t('progetti.nessuno_radice');
    $ps_allow_create = false; $ps_render_trigger = false; $ps_hidden_name = 'bulk_reparent_project_id';
    $ps_exclude_id = 0;
    include __DIR__ . '/project_selector.php';
    unset($ps_id, $ps_selected_id, $ps_selected_name, $ps_selected_color, $ps_placeholder, $ps_title,
          $ps_show_all_option, $ps_all_option_label, $ps_allow_create, $ps_render_trigger, $ps_hidden_name, $ps_exclude_id);
?>

<?php include __DIR__ . '/pomodoro_storico.php'; ?> 

<!-- ================= FORM NASCOSTI ================= -->
<form id="formSave" method="POST" style="display:none;">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="project_id" id="fProgettoId">
    <input type="hidden" name="progetto_name" id="fProgettoName">
    <input type="hidden" name="progetto_note" id="fProgettoNote">
    <input type="hidden" name="parent_id" id="fParentId">
    <input type="hidden" name="fc_category_id" id="fFcCategoryId">
    <input type="hidden" name="color_mode" id="fColorMode" value="auto">
    <input type="hidden" name="progetto_color" id="fProgettoColor">
    <input type="hidden" name="data_obiettivo" id="fDataObiettivo">
    <input type="hidden" name="data_scadenza" id="fDataScadenza">
    <input type="hidden" name="redirect_id" id="fRedirectId">
    <input type="hidden" name="posizione" id="fPosizione" value="">
</form>
<form id="formDelete" method="POST" style="display:none;">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="project_id" id="fDelId">
    <input type="hidden" name="delete_sessions" id="fDelSessions" value="0">
    <input type="hidden" name="redirect_id" id="fDelRedirect">
</form>
<form id="formReorder" method="POST" style="display:none;">
    <input type="hidden" name="action" value="reorder">
    <input type="hidden" name="project_ids" id="fReorderIds">
</form>
<form id="formBulkDelete" method="POST" style="display:none;">
    <input type="hidden" name="action" value="bulk_delete">
    <input type="hidden" name="project_ids" id="fBulkDelIds">
    <input type="hidden" name="delete_sessions" id="fBulkDelSessions" value="0">
    <input type="hidden" name="redirect_id" id="fBulkDelRedirect">
</form>
<form id="formBulkColor" method="POST" style="display:none;">
    <input type="hidden" name="action" value="bulk_set_color">
    <input type="hidden" name="project_ids" id="fBulkColorIds">
    <input type="hidden" name="color" id="fBulkColorHex">
    <input type="hidden" name="redirect_id" id="fBulkColorRedirect">
</form>
<form id="formBulkReparent" method="POST" style="display:none;">
    <input type="hidden" name="action" value="bulk_reparent">
    <input type="hidden" name="project_ids" id="fBulkReparentIds">
    <input type="hidden" name="new_parent_id" id="fBulkReparentPid">
    <input type="hidden" name="redirect_id" id="fBulkReparentRedirect">
</form>

<script nonce="<?php echo sec_nonce(); ?>">
    const PROJ_LOOKUP = <?php echo json_encode($jsProjLookup, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;
    // padre di ogni materia (0 = radice) e nome, per risalire/scendere l'albero lato client
    const CAT_PARENT = <?php echo json_encode($catParentOf, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    const CAT_NAME = <?php echo json_encode($catNameById, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;
    // ogni collegamento progetto<->materia esistente (di TUTTI i progetti)
    const CAT_LINKS = <?php echo json_encode($jsCatLinks, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;
</script>
<script nonce="<?php echo sec_nonce(); ?>">
(function () {
'use strict';

/* richiesta 18: ricorda l'ultimo progetto aperto e, se si arriva su progetti.php
   senza un id esplicito, salta subito lì (prima di disegnare troppo) */
(function(){
    try {
        var params = new URLSearchParams(window.location.search);
        var cur = <?php echo (int)($selectedProject['id'] ?? 0); ?>;
        /* I preferiti non hanno un id nell'indirizzo: senza questa guardia
           "?view=fav" veniva scambiato per "nessun progetto scelto" e la
           pagina rimbalzava subito sull'ultimo progetto aperto. Era il motivo
           per cui i preferiti non si aprivano mai. */
        var vista = params.get('view');
        if (!params.has('id') && !vista) {
            var last = localStorage.getItem('pr_last_project');
            /* solo verso un progetto che esiste ancora: dopo averlo eliminato si
               rimbalzava su un indirizzo vuoto */
            if (last && parseInt(last, 10) > 0 && parseInt(last, 10) !== cur && PROJ_LOOKUP[parseInt(last, 10)]) {
                window.location.replace('progetti.php?id=' + parseInt(last, 10));
                return;
            }
        }
        /* stando nei preferiti non si sovrascrive l'ultimo progetto aperto:
           uscendo di lì si torna dov'eri davvero */
        if (cur > 0 && !vista) {
            localStorage.setItem('pr_last_project', String(cur));
            /* lo stesso nel cookie: cosi' la prossima volta e' il server a
               scegliere il progetto, prima di disegnare (pr_id_ricordato) */
            document.cookie = 'pr_last_project=' + cur + '; path=/; max-age=31536000; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : ''); /* PERCHE': anche una preferenza non deve viaggiare in chiaro */
            /* arrivati senza id, l'indirizzo dice quale progetto si guarda */
            if (!params.has('id')) {
                params.set('id', String(cur));
                try { history.replaceState(null, '', 'progetti.php?' + params.toString()); } catch (e2) {}
            }
        }
    } catch (e) {}
})();

/* richiesta 18: porta la pillola del progetto attivo nella vista */
(function(){
    var active = document.querySelector('#hdrRootPills .hdr-root-pill.active, #hdrRootPills .hdr-root-pill.active-faded');
    if (active && active.scrollIntoView) { try { active.scrollIntoView({ inline:'center', block:'nearest' }); } catch(e){} }
})();

function buzz(ms){ try{ if (navigator.vibrate) navigator.vibrate(ms); }catch(e){} }
function toast(msg){
    var t = document.getElementById('prToast');
    t.textContent = msg; t.classList.add('show');
    clearTimeout(t._timer);
    t._timer = setTimeout(function(){ t.classList.remove('show'); }, 2200);
}


/* ---------------- checkbox stato (AJAX, niente reload) ---------------- */
// cache degli stati dei sotto-progetti PRIMA del completamento a cascata:
// permette di ripristinarli se l'utente cambia subito idea (chiave = id progetto)
var prCascadeSnapshots = {};
document.addEventListener('click', function(e){
    var chk = e.target.closest('.pr-check');
    if (!chk) return;
    var id = chk.getAttribute('data-id');
    if (!id) return;
    buzz(12);
    chk.classList.remove('pr-just-tapped'); void chk.offsetWidth; chk.classList.add('pr-just-tapped');
    var states = ['pr-check-in_progress','pr-check-completed','pr-check-planned'];
    var cur = states.findIndex(function(s){ return chk.classList.contains(s); });
    var wasCompleted = (states[cur] === 'pr-check-completed');
    var next = states[((cur < 0 ? 0 : cur) + 1) % 3];
    states.forEach(function(s){ chk.classList.remove(s); });
    chk.classList.add(next);
    // se è la checkbox dell'hero, aggiorna anche lo strikethrough del titolo
    var heroTitle = document.querySelector('.pr-hero-title');
    if (chk.closest('.pr-hero-top') && heroTitle) heroTitle.classList.toggle('pr-name-done', next === 'pr-check-completed');
    var rowName = chk.closest('.pr-row-content') ? chk.closest('.pr-row-content').querySelector('.pr-name') : null;
    if (rowName) rowName.classList.toggle('pr-name-done', next === 'pr-check-completed');

    var body = 'project_id=' + encodeURIComponent(id);
    // se stiamo uscendo da "completato" e abbiamo uno snapshot in cache, mandalo
    // al server per ripristinare i sotto-progetti com'erano prima del completamento
    if (wasCompleted && prCascadeSnapshots[id]) {
        body += '&restore=' + encodeURIComponent(JSON.stringify(prCascadeSnapshots[id]));
    }

    fetch('progetti.php?ajax=toggle_stato', {
        method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body: body
    }).then(function(r){ return r.json(); }).then(function(data){
        if (!data.success) { toast(T('comune.errore_generico')); return; }
        // completamento a cascata: aggiorna le checkbox dei sotto-progetti e
        // memorizza il loro stato precedente per un eventuale ripristino
        if (data.cascaded && data.cascaded.length) {
            if (data.snapshot) prCascadeSnapshots[id] = data.snapshot;
            data.cascaded.forEach(function(cid){
                prApplyStateToDom(cid, 'pr-check-completed');
            });
        }
        // ripristino: il server ha rimesso i sotto-progetti com'erano
        if (data.restored && Object.keys(data.restored).length) {
            Object.keys(data.restored).forEach(function(cid){
                prApplyStateToDom(cid, 'pr-check-' + data.restored[cid]);
            });
            delete prCascadeSnapshots[id]; // snapshot consumato
        }
    }).catch(function(){ toast(T('comune.errore_rete')); });
});
// applica una classe di stato a tutte le checkbox di un progetto + strikethrough
function prApplyStateToDom(cid, stateClass){
    document.querySelectorAll('.pr-check[data-id="' + cid + '"]').forEach(function(c){
        ['pr-check-in_progress','pr-check-completed','pr-check-planned'].forEach(function(s){ c.classList.remove(s); });
        c.classList.add(stateClass);
        var rn = c.closest('.pr-row-content') ? c.closest('.pr-row-content').querySelector('.pr-name') : null;
        if (rn) rn.classList.toggle('pr-name-done', stateClass === 'pr-check-completed');
    });
}

/* ---------------- espandi/collassa figli ---------------- */
window.prToggleChildren = function(id, btn){
    var wrap = btn.closest('.pr-row-wrap');
    var kids = wrap ? wrap.querySelector(':scope > .pr-children') : null;
    if (!kids) return;
    var apre = kids.classList.contains('collapsed');
    kids.classList.toggle('collapsed', !apre);
    btn.classList.toggle('open', apre);
    btn.setAttribute('aria-expanded', apre ? 'true' : 'false');
    prRamiRicorda(id, apre);
};
/* «Ricorda i rami aperti» (impostazioni): esisteva l'interruttore ma nessuno
   lo leggeva. Si tiene, per ogni progetto, quali rami sono stati aperti o
   chiusi a mano, e si rimettono così alla prossima apertura. */
function prRamiChiave(){ return 'pr_rami_' + (CURRENT_PROJECT_ID || 'fav'); }
function prRamiRicorda(id, aperto){
    if (!window.PR_OPZ || !PR_OPZ.ricorda_rami) return;
    try {
        var m = JSON.parse(localStorage.getItem(prRamiChiave()) || '{}');
        m[id] = aperto ? 1 : 0;
        localStorage.setItem(prRamiChiave(), JSON.stringify(m));
    } catch (e) {}
}
function prApriRamo(wrap, aperto){
    var kids = wrap && wrap.querySelector(':scope > .pr-children');
    var btn = wrap && wrap.querySelector(':scope > .pr-row .pr-arrow');
    if (!kids) return;
    kids.classList.toggle('collapsed', !aperto);
    if (btn) { btn.classList.toggle('open', aperto); btn.setAttribute('aria-expanded', aperto ? 'true' : 'false'); }
}
document.addEventListener('DOMContentLoaded', function(){
    var list = document.getElementById('prList');
    if (!list) return;
    var m = {};
    if (window.PR_OPZ && PR_OPZ.ricorda_rami) {
        try { m = JSON.parse(localStorage.getItem(prRamiChiave()) || '{}'); } catch (e) { m = {}; }
    }
    /* il ramo dove si è appena aggiunto qualcosa si riapre: altrimenti la cosa
       appena scritta finiva dentro un ramo chiuso, e sembrava persa */
    try {
        var nuovo = sessionStorage.getItem('pr_ramo_nuovo');
        if (nuovo) { sessionStorage.removeItem('pr_ramo_nuovo'); m[nuovo] = 1; }
    } catch (e) {}
    Object.keys(m).forEach(function(id){
        var w = list.querySelector('.pr-row-wrap[data-id="' + id + '"]');
        if (!w) return;
        prApriRamo(w, !!m[id]);
        if (m[id]) {
            // anche quelli sopra, o resterebbe aperto dentro uno chiuso
            var su = w.parentElement && w.parentElement.closest('.pr-row-wrap');
            while (su) { prApriRamo(su, true); su = su.parentElement && su.parentElement.closest('.pr-row-wrap'); }
        }
    });
});
/* Chiudi tutto / apri tutto. Alterna: si guarda se c'è ancora qualcosa di
   aperto e si fa il contrario. Guardare lo stato invece di ricordarselo in una
   variabile evita di trovarsi il tasto che dice «apri» dopo che si è chiuso a
   mano l'ultimo ramo rimasto. */
window.prFoldAll = function () {
    var rami = document.querySelectorAll('#prList .pr-children');
    if (!rami.length) return;
    var apertoQualcosa = false;
    rami.forEach(function (k) { if (!k.classList.contains('collapsed')) apertoQualcosa = true; });

    rami.forEach(function (k) {
        k.classList.toggle('collapsed', apertoQualcosa);
        var freccia = k.parentElement && k.parentElement.querySelector(':scope > .pr-row .pr-arrow');
        if (freccia) { freccia.classList.toggle('open', !apertoQualcosa); freccia.setAttribute('aria-expanded', apertoQualcosa ? 'false' : 'true'); }
        var w = k.parentElement;
        if (w && w.getAttribute('data-id')) prRamiRicorda(w.getAttribute('data-id'), !apertoQualcosa);
    });
    if (window.prAggiornaStrumenti) window.prAggiornaStrumenti();
};

window.prTapRow = function(id){
    if (prSubSelecting) return; // in modalita' selezione ci pensa il listener delegato qui sotto
    window.location.href = 'progetti.php?id=' + id;
};

/* ---------------- post-it nota (richiesta 13) ---------------- */
window.prOpenNotePeek = function(id, el){
    var raw = el ? (el.getAttribute('data-note-raw') || '') : '';
    if (!raw && PROJ_LOOKUP[id]) raw = PROJ_LOOKUP[id].note || '';
    var body = document.getElementById('prNotePeekBody');
    body.setAttribute('data-raw', raw);
    /* Una versione ancora in coda (scritta offline) vale piu' di quella del server. */
    var inCoda = window.PrNota ? PrNota.inCoda(id) : null;
    if (inCoda) raw = inCoda.note;
    if (window.PrNota) PrNota.disegna(body, raw); else body.textContent = '';
    /* Il tasto modifica del post-it rende il testo modificabile LÌ, con la
       sua barra di formattazione. Prima portava al popup di modifica del
       progetto: si usciva dalla nota per andare in una scheda con dentro dieci
       campi, di cui uno era la nota. */
    var peek = document.getElementById('prNotePeekOverlay');
    document.getElementById('prNotePeekEdit').onclick = function(){
        if (peek.classList.contains('is-editing')) { prPeekFine(); return; }
        prPeekModifica(id, raw);
    };
    document.getElementById('prNotePeekOverlay').classList.add('show');
};
window.prCloseNotePeek = function(){
    prPeekFine();
    var ov = document.getElementById('prNotePeekOverlay');
    ov.classList.remove('show');
    ov.classList.remove('is-editing');
    var e = document.getElementById('prPeekEdit');
    if (e) e.hidden = true;
    document.getElementById('prNotePeekBody').hidden = false;
};

/* Il post-it in modifica: la nota diventa un editor con la sua barra, e si
   salva da solo come quella in cima al progetto (nota_auto.js): niente tasto
   «salva». La matita, mentre si scrive, vuol dire «ho finito». */
var prPeekCtl = null, prPeekId = 0;
window.prPeekModifica = function (id, raw) {
    var ov = document.getElementById('prNotePeekOverlay');
    var box = document.getElementById('prPeekEdit');
    if (!box || !window.PrNota) return;
    document.getElementById('prNotePeekBody').hidden = true;
    box.hidden = false;
    ov.classList.add('is-editing');
    prPeekId = id;
    var wrap = box.querySelector('.fmt-editor-wrap');
    if (wrap) {
        wrap.dataset.raw = raw || '';
        var ed = document.getElementById(wrap.dataset.editorId);
        var campo = document.getElementById(wrap.dataset.field);
        if (campo) campo.value = raw || '';
        if (window.ftRender && window.fcdParse) window.ftRender(wrap, window.fcdParse(raw || ''), null);
    }
    if (!prPeekCtl) {
        prPeekCtl = PrNota.collega({
            box: box,
            id: function () { return prPeekId; },
            iniziale: function () { return box.dataset.base || ''; },
            alSalvato: function (pid, nota) {
                document.querySelectorAll('[data-note-id="' + pid + '"]').forEach(function (t) { t.setAttribute('data-note-raw', nota); });
                if (window.PROJ_LOOKUP && PROJ_LOOKUP[pid]) PROJ_LOOKUP[pid].note = nota;
                if (String(pid) === String((document.getElementById('prNota') || {}).dataset && document.getElementById('prNota').dataset.id)) {
                    var v = document.getElementById('prNota');
                    if (v && !v.hidden) { v.dataset.raw = nota; if (nota) { v.classList.remove('pr-nota-vuota'); v.classList.add('raw-text-content'); PrNota.disegna(v, nota); } }
                }
            }
        });
    }
    box.dataset.base = raw || '';
    if (prPeekCtl) prPeekCtl.apri();
};

/* fine della modifica nel post-it: si salva quel che manca e si torna a leggere */
window.prPeekFine = function () {
    var ov = document.getElementById('prNotePeekOverlay');
    var box = document.getElementById('prPeekEdit');
    if (!box || !prPeekCtl || !prPeekCtl.aperto()) return;
    var v = prPeekCtl.valore();
    prPeekCtl.chiudi();
    var body = document.getElementById('prNotePeekBody');
    box.hidden = true;
    ov.classList.remove('is-editing');
    body.hidden = false;
    PrNota.disegna(body, v);
    document.querySelectorAll('[data-note-id="' + prPeekId + '"]').forEach(function (t) { t.setAttribute('data-note-raw', v); });
    if (window.PROJ_LOOKUP && PROJ_LOOKUP[prPeekId]) PROJ_LOOKUP[prPeekId].note = v;
};

/* ---------------- selezione multipla dei sotto-progetti ---------------- */
var prSubSelecting = false;
var prSubSelectedIds = {};
var CURRENT_PROJECT_ID = <?php echo (int)($selectedProject['id'] ?? 0); ?>;

window.prToggleSubSelecting = function(){
    prSubSelecting = !prSubSelecting;
    prSubSelectedIds = {};
    var list = document.getElementById('prList');
    if (list) list.classList.toggle('pr-sub-selecting', prSubSelecting);
    var btn = document.getElementById('prSelectSubBtn');
    if (btn) btn.classList.toggle('active', prSubSelecting);
    var bar = document.getElementById('prSubBulkbar');
    if (bar) bar.classList.toggle('show', prSubSelecting);
    /* Con la barra in basso l'ultimo sottoprogetto le finirebbe sotto: si
       aggiunge spazio in fondo alla pagina finché resta aperta. */
    document.body.classList.toggle('pr-selezionando', prSubSelecting);
    if (list) list.querySelectorAll('.pr-row-wrap.pr-sub-selected, .pr-row-wrap.pr-sub-locked').forEach(function(w){ w.classList.remove('pr-sub-selected', 'pr-sub-locked'); });
    if (prSubSelecting && window.prSetViewMode) window.prSetViewMode('list'); // le checkbox esistono solo nell'elenco
    prUpdateSubBulkCount();
};
function prUpdateSubBulkCount(){
    var n = Object.keys(prSubSelectedIds).length;
    var el = document.getElementById('prSubBulkCount');
    if (el) el.textContent = T(n === 1 ? 'progetti.n_selezionato' : 'progetti.n_selezionati', { n: n });
    var allBtn = document.getElementById('prSubSelectAllBtn');
    if (allBtn) allBtn.textContent = n > 0 ? T('progetti.deseleziona_tutti') : T('progetti.seleziona_tutti');
}
// tutte le righe-discendenti (nel DOM) di una data riga di #prList
function prDomDescendantWraps(wrap){
    return Array.prototype.slice.call(wrap.querySelectorAll('.pr-row-wrap'));
}
// ridisegna lock/selezione di tutte le righe in base agli id "primari" scelti
function prRepaintSubSelection(){
    var list = document.getElementById('prList');
    if (!list) return;
    // prima pulizia
    list.querySelectorAll('.pr-row-wrap').forEach(function(w){
        w.classList.remove('pr-sub-selected', 'pr-sub-locked');
    });
    // per ogni id primario: marca lui (selected) e i suoi discendenti (locked)
    Object.keys(prSubSelectedIds).forEach(function(id){
        var wrap = list.querySelector('.pr-row-wrap[data-id="' + id + '"]');
        if (!wrap) return;
        wrap.classList.add('pr-sub-selected');
        prDomDescendantWraps(wrap).forEach(function(d){ d.classList.add('pr-sub-locked'); });
    });
}
window.prToggleSubSelect = function(id){
    id = String(id);
    var list = document.getElementById('prList');
    var wrap = list ? list.querySelector('.pr-row-wrap[data-id="' + id + '"]') : null;
    if (!wrap) return;
    // se questa riga è bloccata perché è già selezionato un suo antenato, il tap non fa nulla (richiesta 2)
    if (wrap.classList.contains('pr-sub-locked')) { buzz(4); return; }
    if (prSubSelectedIds[id]) {
        delete prSubSelectedIds[id];
    } else {
        // selezionandola, togliamo eventuali suoi discendenti già selezionati come "primari"
        // (verrebbero comunque inclusi tramite l'antenato) per non contarli due volte
        prDomDescendantWraps(wrap).forEach(function(d){
            var did = d.getAttribute('data-id');
            if (prSubSelectedIds[did]) delete prSubSelectedIds[did];
        });
        prSubSelectedIds[id] = true;
    }
    prRepaintSubSelection();
    prUpdateSubBulkCount();
    buzz(8);
};
window.prSubSelectAll = function(){
    var list = document.getElementById('prList');
    if (!list) return;
    if (Object.keys(prSubSelectedIds).length > 0) {
        prSubSelectedIds = {};
    } else {
        // "tutti" = tutte le righe di PRIMO livello; i loro discendenti si bloccano da soli
        list.querySelectorAll(':scope > .pr-row-wrap').forEach(function(w){
            prSubSelectedIds[w.getAttribute('data-id')] = true;
        });
    }
    prRepaintSubSelection();
    prUpdateSubBulkCount();
    buzz(8);
};
// tap ovunque sulla riga (non solo sul nome) in modalità selezione: ora vale per
// TUTTI i livelli (richiesta 1), non solo per i figli diretti
document.addEventListener('click', function(e){
    if (!prSubSelecting) return;
    if (e.target.closest('.pr-arrow, .pr-grip, .pr-pomo-btn, .pr-check, .pr-inline-add-btn, .pr-cat-tag, .pr-note-tag')) return;
    var wrap = e.target.closest('.pr-row-wrap');
    var list = document.getElementById('prList');
    if (!wrap || !list || !list.contains(wrap)) return;
    window.prToggleSubSelect(wrap.getAttribute('data-id'));
});

// tutti i discendenti (id compresi) di un elenco di progetti radice: serve sia
// per "vai ai pomodori" sia per disabilitare il ramo giusto nel selettore padre
function prCollectDescendants(rootIds){
    var set = {};
    rootIds.forEach(function(id){ set[String(id)] = true; });
    var changed = true;
    while (changed) {
        changed = false;
        for (var k in PROJ_LOOKUP) {
            if (set[k]) continue;
            if (set[String(PROJ_LOOKUP[k].parent_id)]) { set[k] = true; changed = true; }
        }
    }
    return Object.keys(set);
}

/* -------- elimina (in blocco): stesso flusso in due passi del singolo -------- */
var prBulkDeleteIds = [];
window.prSubBulkDeleteOpen = function(){
    var ids = Object.keys(prSubSelectedIds);
    if (!ids.length) { toast(T('progetti.seleziona_almeno_un_sotto')); return; }
    prBulkDeleteIds = ids;
    var totalSessions = 0, anyChildren = false;
    ids.forEach(function(id){
        var info = PROJ_LOOKUP[id];
        if (info) totalSessions += (info.session_count || 0);
        if (prHasChildren(parseInt(id, 10))) anyChildren = true;
    });
    var n = ids.length;
    document.getElementById('prConfirmTitle').textContent = n === 1 ? T('progetti.eliminare_n_sotto_1') : T('progetti.eliminare_n_sotto', { n: n });

    if (totalSessions === 0) {
        var plainMsg = anyChildren ? T('progetti.alcuni_hanno_sotto') : '';
        plainMsg += T('progetti.questa_azione_non_puo_essere');
        document.getElementById('prConfirmMsg').textContent = plainMsg;
        document.getElementById('prConfirmActions').innerHTML =
            '<button type="button" class="pr-btn pr-btn-danger" data-on-click="prSubBulkDeleteDo(false)">'+T('comune.elimina')+'</button>' +
            '<button type="button" class="pr-btn pr-btn-cancel" data-on-click="prCloseConfirm()">'+T('comune.annulla')+'</button>';
        document.getElementById('prConfirmOverlay').classList.add('show');
        return;
    }

    var msg = anyChildren ? T('progetti.alcuni_hanno_sotto') : '';
    msg += T('progetti.eliminare_anche_pomodori');
    document.getElementById('prConfirmMsg').textContent = msg;
    document.getElementById('prConfirmActions').innerHTML =
        '<button type="button" class="pr-btn pr-btn-danger" data-on-click="prSubBulkDeleteDo(true)">'+T('progetti.elimina_anche_pomodori')+'</button>' +
        '<button type="button" class="pr-btn pr-btn-neutral" data-on-click="prSubBulkKeepSessions()">'+T('progetti.mantieni_pomodori')+'</button>' +
        '<button type="button" class="pr-btn pr-btn-cancel" data-on-click="prCloseConfirm()">'+T('comune.annulla')+'</button>';
    document.getElementById('prConfirmOverlay').classList.add('show');
};
window.prSubBulkKeepSessions = function(){
    document.getElementById('prConfirmTitle').textContent = T('progetti.pomodori_senza_progetto');
    document.getElementById('prConfirmMsg').textContent =
        T('progetti.sotto_bulk_orfani');
    document.getElementById('prConfirmActions').innerHTML =
        '<button type="button" class="pr-btn pr-btn-neutral" data-on-click="prSubBulkGoToPomodori()">'+T('progetti.vai_ai_pomodori')+'</button>' +
        '<button type="button" class="pr-btn pr-btn-danger" data-on-click="prSubBulkDeleteDo(false)">'+T('progetti.elimina_comunque')+'</button>' +
        '<button type="button" class="pr-btn pr-btn-cancel" data-on-click="prCloseConfirm()">'+T('comune.annulla')+'</button>';
};
window.prSubBulkGoToPomodori = function(){
    var ids = prCollectDescendants(prBulkDeleteIds).map(function(x){ return parseInt(x, 10); });
    prCloseConfirm();
    if (!window.openPomodoroStorico) return;
    window.openPomodoroStorico({ scope: 'project', projectIds: ids, projectName: T('progetti.sotto_selezionati') });
    if (window.phToggleSelectMode) window.phToggleSelectMode();
};
window.prSubBulkDeleteDo = function(deleteSessions){
    document.getElementById('fBulkDelIds').value = prBulkDeleteIds.join(',');
    document.getElementById('fBulkDelSessions').value = deleteSessions ? '1' : '0';
    document.getElementById('fBulkDelRedirect').value = CURRENT_PROJECT_ID;
    document.getElementById('formBulkDelete').submit();
};

/* -------- cambia colore (in blocco) -------- */
window.prSubBulkColorOpen = function(){
    if (!Object.keys(prSubSelectedIds).length) { toast(T('progetti.seleziona_almeno_un_sotto')); return; }
    document.getElementById('prBulkColorOverlay').classList.add('show');
};
window.prCloseBulkColor = function(){ document.getElementById('prBulkColorOverlay').classList.remove('show'); };
window.prSubBulkColorPick = function(value){
    var ids = Object.keys(prSubSelectedIds);
    if (!ids.length) return;
    document.getElementById('fBulkColorIds').value = ids.join(',');
    document.getElementById('fBulkColorHex').value = value;
    document.getElementById('fBulkColorRedirect').value = CURRENT_PROJECT_ID;
    document.getElementById('formBulkColor').submit();
};

/* -------- cambia progetto padre (in blocco) -------- */
var prBulkReparentIds = [];
var prBulkTreeTagged = false;
function prTagBulkTree(){
    if (prBulkTreeTagged) return;
    var modal = document.getElementById('ps-modal-bulkreparent');
    if (!modal) return;
    modal.querySelectorAll('.ps-row-wrap').forEach(function(w){
        var nameEl = w.querySelector(':scope > .ps-row .ps-name');
        w.setAttribute('data-pr-id', nameEl ? (nameEl.getAttribute('data-ps-select') || '0') : '0');
    });
    prBulkTreeTagged = true;
}
function prBulkReparentRestrict(excludeIds){
    var modal = document.getElementById('ps-modal-bulkreparent');
    if (!modal) return;
    prTagBulkTree();
    var excludeSet = {};
    excludeIds.forEach(function(id){ excludeSet[String(id)] = true; });
    modal.querySelectorAll('.ps-row-wrap').forEach(function(w){
        var nameEl = w.querySelector(':scope > .ps-row .ps-name');
        if (!nameEl) return;
        var id = w.getAttribute('data-pr-id');
        if (excludeSet[id]) {
            nameEl.removeAttribute('data-ps-select');
            nameEl.classList.add('ps-name-disabled');
        } else {
            nameEl.setAttribute('data-ps-select', id);
            nameEl.classList.remove('ps-name-disabled');
        }
    });
}
window.prSubBulkReparentOpen = function(){
    var ids = Object.keys(prSubSelectedIds);
    if (!ids.length) { toast(T('progetti.seleziona_almeno_un_sotto')); return; }
    prBulkReparentIds = ids;
    prBulkReparentRestrict(prCollectDescendants(ids));
    var modal = document.getElementById('ps-modal-bulkreparent');
    if (modal && window.psOpen) window.psOpen(modal);
};
document.addEventListener('projectselected', function(e){
    if (!e.detail || e.detail.uid !== 'bulkreparent') return;
    document.getElementById('fBulkReparentIds').value = prBulkReparentIds.join(',');
    document.getElementById('fBulkReparentPid').value = e.detail.id;
    document.getElementById('fBulkReparentRedirect').value = CURRENT_PROJECT_ID;
    document.getElementById('formBulkReparent').submit();
});

/* ================================================================
   AGGIUNGERE SOTTO-PROGETTI
   Si scrive dove la cosa comparirà: in fondo all'elenco («Aggiungi un
   sotto-progetto»), in fondo a un ramo aperto («Aggiungi a …») o dentro una
   riga, dallo scorrimento laterale. Invio aggiunge e lascia il campo aperto e
   vuoto per il prossimo: la tastiera non si chiude e si può scrivere una lista
   di fila. Le righe nuove compaiono subito; la pagina si ricarica una volta
   sola, quando si chiude il campo, e solo se si è aggiunto qualcosa.
   ================================================================ */
var PIU_SVG = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>';
var prCodaNuovi = Promise.resolve();
var prNuoviMandati = 0;

function prRigaAggiungi(parentId){
    if (parentId === CURRENT_PROJECT_ID) return document.getElementById('prAddMain');
    var wrap = document.querySelector('#prList .pr-row-wrap[data-id="' + parentId + '"]');
    if (!wrap) return null;
    var kids = wrap.querySelector(':scope > .pr-children');
    if (!kids) {
        kids = document.createElement('div');
        kids.className = 'pr-children';
        wrap.appendChild(kids);
    }
    /* il ramo si apre, e con lui quelli sopra */
    prApriRamo(wrap, true);
    var su = wrap.parentElement && wrap.parentElement.closest('.pr-row-wrap');
    while (su) { prApriRamo(su, true); su = su.parentElement && su.parentElement.closest('.pr-row-wrap'); }
    var riga = kids.querySelector(':scope > .pr-add-line');
    if (!riga) {
        riga = document.createElement('button');
        riga.type = 'button';
        riga.className = 'pr-add-line pr-add-sub';
        riga.setAttribute('data-parent', parentId);
        riga.innerHTML = '<span class="pr-add-ic">' + PIU_SVG + '</span><span></span>';
        riga.lastChild.textContent = T('progetti.aggiungi_a', { nome: (PROJ_LOOKUP[parentId] && PROJ_LOOKUP[parentId].name) || '' });
        riga.addEventListener('click', function(){ window.prAggiungi(parentId); });
        kids.appendChild(riga);
    }
    return riga;
}

window.prAggiungi = function(parentId){
    parentId = parseInt(parentId, 10) || 0;
    if (!parentId) return;
    if (prSubSelecting) window.prToggleSubSelecting();
    if (prVista === 'grid') window.prSetViewMode('list');
    var riga = prRigaAggiungi(parentId);
    if (!riga) return;
    if (riga._input) { riga._input.focus(); return; }
    prApriInputAggiungi(riga, parentId);
};

function prApriInputAggiungi(riga, parentId){
    var box = document.createElement('div');
    box.className = 'pr-add-in' + (riga.classList.contains('pr-add-sub') ? ' pr-add-sub' : '');
    box.innerHTML = '<span class="pr-add-ic">' + PIU_SVG + '</span>';
    var inp = document.createElement('input');
    inp.type = 'text';
    inp.autocomplete = 'off';
    inp.maxLength = 200;
    inp.setAttribute('enterkeyhint', 'next');
    inp.placeholder = T('progetti.nome_nuovo_sotto');
    var ok = document.createElement('button');
    ok.type = 'button';
    ok.className = 'pr-add-ok';
    ok.setAttribute('aria-label', T('comune.salva'));
    ok.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>';
    box.appendChild(inp);
    box.appendChild(ok);
    riga.hidden = true;
    riga._input = inp;
    riga.parentNode.insertBefore(box, riga.nextSibling);

    function manda(){
        var nome = inp.value.trim();
        if (!nome) return;
        inp.value = '';
        var r = document.createElement('div');
        r.className = 'pr-row-wrap pr-nuovo';
        r.innerHTML = '<div class="pr-row"><div class="pr-row-content"><span class="pr-arrow-spacer"></span>'
            + '<span class="pr-check pr-check-in_progress"><svg class="pr-ic-empty" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9.5"/></svg></span>'
            + '<div class="pr-main"><span class="pr-name"><span class="pr-name-track"></span></span></div></div></div>';
        r.querySelector('.pr-name-track').textContent = nome;
        riga.parentNode.insertBefore(r, riga);
        buzz(8);
        prSalvaNuovo(nome, parentId, r);
    }
    function chiudi(){
        if (!riga._input) return;
        riga._input = null;
        box.remove();
        riga.hidden = false;
        prForseRicarica();
    }
    /* il tasto ✓ non deve rubare il fuoco al campo, o la tastiera si chiude */
    ok.addEventListener('pointerdown', function(e){ e.preventDefault(); });
    ok.addEventListener('click', function(){ manda(); inp.focus(); });
    inp.addEventListener('keydown', function(e){
        if (e.key === 'Enter') { e.preventDefault(); manda(); }
        else if (e.key === 'Escape') { e.preventDefault(); inp.value = ''; inp.blur(); }
    });
    inp.addEventListener('blur', function(){
        setTimeout(function(){
            if (document.activeElement === inp) return;
            if (inp.value.trim()) manda();   // uscendo con qualcosa scritto, non lo si perde
            chiudi();
        }, 150);
    });
    inp.focus();
    try { box.scrollIntoView({ block: 'center', behavior: 'smooth' }); } catch (e) {}
}

/* Un salvataggio alla volta e nell'ordine in cui si è scritto: il server mette
   ognuno in fondo, e due richieste incrociate li scambierebbero di posto. */
function prSalvaNuovo(nome, parentId, riga){
    prNuoviMandati++;
    if (parentId !== CURRENT_PROJECT_ID) { try { sessionStorage.setItem('pr_ramo_nuovo', String(parentId)); } catch (e) {} }
    prCodaNuovi = prCodaNuovi.then(function(){
        var fd = new FormData();
        fd.set('action', 'save');
        fd.set('project_id', '0');
        fd.set('progetto_name', nome);
        fd.set('progetto_note', '');
        fd.set('parent_id', String(parentId));
        fd.set('fc_category_id', '0');
        fd.set('color_mode', 'auto');
        fd.set('progetto_color', '');
        fd.set('data_obiettivo', '');
        fd.set('data_scadenza', '');
        fd.set('redirect_id', String(CURRENT_PROJECT_ID));
        fd.set('posizione', 'fine');
        return fetch('progetti.php', { method: 'POST', body: fd, credentials: 'same-origin', redirect: 'manual', keepalive: true })
            .then(function(r){
                if (r.type === 'opaqueredirect' || r.ok) { riga.classList.add('pr-nuovo-ok'); return; }
                throw new Error('http ' + r.status);
            })
            .catch(function(){
                riga.classList.add('pr-nuovo-ko');
                toast(T('progetti.errore_salvataggio'));
            });
    });
}
function prForseRicarica(){
    if (document.querySelector('.pr-add-in') || !prNuoviMandati) return;
    prCodaNuovi.then(function(){
        if (!document.querySelector('.pr-add-in')) window.location.reload();
    });
}

/* ================================================================
   IL MENU ⋯ DELLA SCHEDA
   ================================================================ */
var GRID_SVG = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg>';
var LIST_SVG = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"><path d="M8 6h13M8 12h13M8 18h13M3.5 6h.01M3.5 12h.01M3.5 18h.01"/></svg>';
window.prApriAltro = function(){
    var ov = document.getElementById('prAltroOv');
    if (!ov) return;
    ov.classList.add('show');
};
window.prChiudiAltro = function(){
    var ov = document.getElementById('prAltroOv');
    if (ov) ov.classList.remove('show');
};
document.addEventListener('keydown', function(e){ if (e.key === 'Escape') window.prChiudiAltro(); });
window.prAltroVista = function(){ window.prSetViewMode(prVista === 'grid' ? 'list' : 'grid'); };

/* ================================================================
   GLI STRUMENTI NELL'ETICHETTA «SOTTO-PROGETTI»
   Griglia/elenco e chiudi/apri i rami mostrano l'icona di quello che
   faranno, e la scritta per chi legge lo schermo cambia con loro.
   ================================================================ */
var RAMI_CHIUDI_SVG = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M8 4.5l4 4 4-4M8 19.5l4-4 4 4M4 12h16"/></svg>';
var RAMI_APRI_SVG = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M8 8l4-4 4 4M8 16l4 4 4-4M4 12h16"/></svg>';
function prEtichetta(btn, chiave){
    var testo = T(chiave);
    btn.setAttribute('aria-label', testo);
    btn.setAttribute('title', testo);
}
window.prAggiornaStrumenti = function(){
    var vb = document.getElementById('prVistaBtn');
    if (vb) {
        vb.innerHTML = prVista === 'grid' ? LIST_SVG : GRID_SVG;
        prEtichetta(vb, prVista === 'grid' ? 'progetti.mostra_elenco' : 'progetti.mostra_griglia');
    }
    var rb = document.getElementById('prRamiBtn');
    if (rb) {
        var aperto = !!document.querySelector('#prList .pr-children:not(.collapsed)');
        rb.innerHTML = aperto ? RAMI_CHIUDI_SVG : RAMI_APRI_SVG;
        prEtichetta(rb, aperto ? 'progetti.chiudi_tutto' : 'progetti.apri_tutto');
        rb.hidden = prVista === 'grid';   // nella griglia non ci sono rami
    }
};
/* un ramo aperto o chiuso a mano cambia quello che il tasto farà */
document.addEventListener('click', function(e){
    if (e.target.closest && e.target.closest('#prList .pr-arrow')) setTimeout(window.prAggiornaStrumenti, 0);
});
setTimeout(window.prAggiornaStrumenti, 0);

/* ================================================================
   SPOSTA IN…
   Lo stesso selettore del «Progetto padre» in blocco, con un progetto solo:
   lui e i suoi discendenti non si possono scegliere.
   ================================================================ */
window.prSpostaIn = function(id){
    prBulkReparentIds = [String(id)];
    prBulkReparentRestrict(prCollectDescendants([id]));
    var modal = document.getElementById('ps-modal-bulkreparent');
    if (modal && window.psOpen) window.psOpen(modal);
};

/* ================================================================
   NUOVO PROGETTO
   Basta il nome, scritto dove si è toccato. Il progetto nasce con un colore
   della tavolozza che nessun altro progetto principale usa ancora, e si apre:
   colore, date, deck e nota sono nel suo menu ⋯.
   ================================================================ */
var PR_PALETTE = <?php echo json_encode(array_values($prColorPalette)); ?>;
function prColoreLibero(){
    var usati = {}, n = 0;
    for (var k in PROJ_LOOKUP) {
        var p = PROJ_LOOKUP[k];
        if (p.parent_id) continue;
        n++;
        if (p.color) usati[String(p.color).toLowerCase()] = true;
    }
    for (var i = 0; i < PR_PALETTE.length; i++) {
        if (!usati[PR_PALETTE[i].toLowerCase()]) return PR_PALETTE[i];
    }
    return PR_PALETTE[n % PR_PALETTE.length];
}
window.prNuovoProgetto = function(btn){
    if (btn._input) { btn._input.focus(); return; }
    var box = document.createElement('div');
    box.className = 'pr-add-in pr-add-root';
    box.innerHTML = '<span class="pr-add-ic">' + PIU_SVG + '</span>';
    var inp = document.createElement('input');
    inp.type = 'text';
    inp.autocomplete = 'off';
    inp.maxLength = 200;
    inp.setAttribute('enterkeyhint', 'done');
    inp.placeholder = T('progetti.nome_nuovo_progetto');
    var ok = document.createElement('button');
    ok.type = 'button';
    ok.className = 'pr-add-ok';
    ok.setAttribute('aria-label', T('comune.salva'));
    ok.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>';
    box.appendChild(inp);
    box.appendChild(ok);
    btn.hidden = true;
    btn._input = inp;
    btn.parentNode.insertBefore(box, btn.nextSibling);
    var mandato = false;

    function crea(){
        var nome = inp.value.trim();
        if (!nome || mandato) return;
        mandato = true;
        inp.readOnly = true;
        box.classList.add('is-busy');
        document.getElementById('fProgettoId').value = '0';
        document.getElementById('fProgettoName').value = nome;
        document.getElementById('fProgettoNote').value = '';
        document.getElementById('fParentId').value = '0';
        document.getElementById('fFcCategoryId').value = '0';
        document.getElementById('fColorMode').value = 'manual';
        document.getElementById('fProgettoColor').value = prColoreLibero();
        document.getElementById('fDataObiettivo').value = '';
        document.getElementById('fDataScadenza').value = '';
        document.getElementById('fRedirectId').value = '';
        document.getElementById('fPosizione').value = 'fine';
        document.getElementById('formSave').submit();   // il server apre il progetto nuovo
    }
    function chiudi(){
        if (mandato || !btn._input) return;
        btn._input = null;
        box.remove();
        btn.hidden = false;
    }
    ok.addEventListener('pointerdown', function(e){ e.preventDefault(); });
    ok.addEventListener('click', crea);
    inp.addEventListener('keydown', function(e){
        if (e.key === 'Enter') { e.preventDefault(); crea(); }
        else if (e.key === 'Escape') { e.preventDefault(); inp.value = ''; inp.blur(); }
    });
    inp.addEventListener('blur', function(){
        setTimeout(function(){
            if (document.activeElement === inp || mandato) return;
            if (inp.value.trim()) crea(); else chiudi();
        }, 150);
    });
    inp.focus();
};

/* Preferito: cambia subito e salva dopo; se il salvataggio non riesce torna
   com'era e lo dice. La stella accanto al nome c'è solo quando è acceso. */
window.prAltroPreferito = function(btn){
    var id = parseInt(btn.dataset.id, 10) || 0;
    if (!id) return;
    var next = btn.dataset.on !== '1';
    function disegna(on){
        btn.dataset.on = on ? '1' : '0';
        var sw = btn.querySelector('.pr-sw'); if (sw) sw.classList.toggle('on', on);
        var st = document.getElementById('prHeroStar'); if (st) st.toggleAttribute('hidden', !on);   // è un svg: .hidden lì non esiste
        if (PROJ_LOOKUP[id]) PROJ_LOOKUP[id].is_favorite = on ? 1 : 0;
    }
    disegna(next);
    buzz(8);
    fetch('progetti.php?ajax=toggle_favorite', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ project_id: id, value: next ? 1 : 0 }).toString()
    }).then(function(r){ return r.json(); }).then(function(r){
        if (!r || !r.ok) { disegna(!next); toast(T('progetti.non_salvato')); }
    }).catch(function(){ disegna(!next); toast(T('comune.errore_rete')); });
};
window.prAltroAttivita = function(btn){
    var t = document.getElementById('prTodoBtn');
    if (!t) return;
    t.click();
    var sw = btn.querySelector('.pr-sw');
    if (sw) sw.classList.toggle('on', t.classList.contains('is-on'));
};

/* ================================================================
   IL POMODORO CHE STA ANDANDO
   Il tempo sulla sua riga (e sul tasto Concentrati, se è in questo progetto)
   scorre da solo. Se è un pomodoro con una durata si vede quanto manca, come
   nella pagina del pomodoro; se è un cronometro, quanto è passato.
   ================================================================ */
(function(){
    var P = <?php echo json_encode($pomoAttivo); ?>;
    var els = document.querySelectorAll('[data-pomo-live]');
    if (!P || !els.length) return;
    var t0 = Date.now();
    function due(n){ return (n < 10 ? '0' : '') + n; }
    function giro(){
        var tr = P.trascorsi + (P.fermo ? 0 : (Date.now() - t0) / 1000);
        var s = Math.max(0, Math.floor(P.previsti > 0 ? P.previsti - tr : tr));
        var h = Math.floor(s / 3600), m = Math.floor(s % 3600 / 60), x = s % 60;
        var txt = h > 0 ? h + ':' + due(m) + ':' + due(x) : m + ':' + due(x);
        els.forEach(function(el){ el.textContent = txt; });
    }
    giro();
    setInterval(giro, 1000);
})();

/* ---------------- modalità di visualizzazione elenco/griglia (bug #3) ---------------- */
var prVista = 'list';
window.prSetViewMode = function(mode){
    var list = document.getElementById('prList'), grid = document.getElementById('prGrid');
    if (!list || !grid) return;
    prVista = mode === 'grid' ? 'grid' : 'list';
    list.style.display = prVista === 'grid' ? 'none' : '';
    grid.style.display = prVista === 'grid' ? '' : 'none';
    try { localStorage.setItem('pr_view_mode', prVista); } catch (e) {}
    if (window.prAggiornaStrumenti) window.prAggiornaStrumenti();
};
(function(){
    if (!document.getElementById('prGrid')) return;
    var saved = 'list';
    try { saved = localStorage.getItem('pr_view_mode') || 'list'; } catch (e) {}
    window.prSetViewMode(saved);
})();

/* ---------------- pannello "Scadenze e obiettivi" ---------------- */
var prDatesState = { field: 'scadenza', filter: 'all', sortDir: 'asc' };

function prDatesDayStatus(dateStr){
    var d = new Date(dateStr + 'T00:00:00');
    if (isNaN(d.getTime())) return null;
    var today = new Date();
    today.setHours(0,0,0,0);
    var dOnly = new Date(d.getFullYear(), d.getMonth(), d.getDate());
    if (dOnly.getTime() < today.getTime()) return 'past';
    if (dOnly.getTime() === today.getTime()) return 'today';
    return 'future';
}

function prDatesPathFor(pid){
    var parts = [];
    var guard = 0;
    var cur = PROJ_LOOKUP[pid] ? PROJ_LOOKUP[pid].parent_id : 0;
    while (cur && cur !== 0 && PROJ_LOOKUP[cur] && guard < 50) {
        guard++;
        parts.unshift(PROJ_LOOKUP[cur].name);
        cur = PROJ_LOOKUP[cur].parent_id;
    }
    return parts.join(' › ');
}

function prDatesRender(){
    var switchEl = document.getElementById('prDatesFieldSwitch');
    Array.prototype.forEach.call(switchEl.querySelectorAll('button'), function(b){
        b.classList.toggle('active', b.getAttribute('data-field') === prDatesState.field);
    });
    var filtersEl = document.getElementById('prDatesFilters');
    Array.prototype.forEach.call(filtersEl.querySelectorAll('button'), function(b){
        b.classList.toggle('active', b.getAttribute('data-filter') === prDatesState.filter);
    });
    document.getElementById('prDatesSortLabel').textContent = (prDatesState.sortDir === 'asc') ? T('progetti.piu_vicine') : T('progetti.piu_lontane');

    var key = (prDatesState.field === 'scadenza') ? 'data_scadenza' : 'data_obiettivo';
    var icon = (prDatesState.field === 'scadenza') ? '⏰' : '🎯';
    var items = [];
    Object.keys(PROJ_LOOKUP).forEach(function(pid){
        var p = PROJ_LOOKUP[pid];
        var raw = p[key];
        if (!raw) return;
        var status = prDatesDayStatus(raw);
        if (!status) return;
        /* Stessa regola del server: un progetto chiuso non è in ritardo, è
           fatto. Il filtro però continua a ragionare sui tre stati di prima —
           chi cerca «le scadute» vuole vedere anche quelle chiuse in ritardo,
           altrimenti sparirebbero dall'elenco proprio mentre le si cerca. */
        var filtroStatus = status;
        if ((p.stato || p.status) === 'completed') {
            status = (status === 'past') ? 'fatto-tardi' : 'fatto';
        }
        if (prDatesState.filter !== 'all' && prDatesState.filter !== filtroStatus) return;
        items.push({ id: pid, name: p.name, color: p.color || '#8E8E93', raw: raw, status: status });
    });

    items.sort(function(a, b){
        var t1 = new Date(a.raw + 'T00:00:00').getTime();
        var t2 = new Date(b.raw + 'T00:00:00').getTime();
        return prDatesState.sortDir === 'asc' ? (t1 - t2) : (t2 - t1);
    });

    var listEl = document.getElementById('prDatesList');
    listEl.innerHTML = '';
    if (items.length === 0) {
        var empty = document.createElement('div');
        empty.className = 'pr-dates-empty';
        empty.textContent = T('progetti.nessun_progetto_data');
        listEl.appendChild(empty);
        return;
    }
    items.forEach(function(it){
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'pr-dates-item';
        btn.style.setProperty('--item-color', it.color);
        btn.onclick = function(){ window.location.href = 'progetti.php?id=' + it.id; };

        var dot = document.createElement('span');
        dot.className = 'pr-dates-item-dot';
        btn.appendChild(dot);

        var main = document.createElement('div');
        main.className = 'pr-dates-item-main';
        var nameEl = document.createElement('div');
        nameEl.className = 'pr-dates-item-name';
        var nameTrack = document.createElement('span');
        nameTrack.className = 'pr-name-track';
        nameTrack.textContent = it.name;
        nameEl.appendChild(nameTrack);
        main.appendChild(nameEl);
        var path = prDatesPathFor(it.id);
        if (path) {
            var pathEl = document.createElement('div');
            pathEl.className = 'pr-dates-item-path';
            pathEl.textContent = path;
            main.appendChild(pathEl);
        }
        btn.appendChild(main);

        var tag = document.createElement('span');
        tag.className = 'pr-date-tag pr-date-tag-' + it.status;
        var d = new Date(it.raw + 'T00:00:00');
        var label = d.toLocaleDateString((document.documentElement.lang || 'it'), { day: '2-digit', month: 'short' });
        tag.textContent = icon + ' ' + label;
        btn.appendChild(tag);

        listEl.appendChild(btn);
    });

    if (window.prInitNameMarquees) window.prInitNameMarquees(listEl);
}

window.prOpenDatesView = function(){
    document.getElementById('prDatesOverlay').classList.add('show');
    prDatesRender();
};
window.prCloseDatesView = function(){
    document.getElementById('prDatesOverlay').classList.remove('show');
};
window.prDatesSetField = function(field){
    prDatesState.field = field;
    prDatesRender();
};
window.prDatesSetFilter = function(filter){
    prDatesState.filter = filter;
    prDatesRender();
};
window.prDatesToggleSort = function(){
    prDatesState.sortDir = (prDatesState.sortDir === 'asc') ? 'desc' : 'asc';
    prDatesRender();
};

/* ---------------- popup pomodori (esternalizzato) ---------------- */
window.prOpenStats = function(projectId, projectName, projectColor){
    if (window.openPomodoroStorico) {
        window.openPomodoroStorico({ scope: 'project', projectId: projectId, projectName: projectName, projectColor: projectColor || '' });
    } else {
        toast(T('progetti.storico_non_incluso'));
    }
};

/* ---------------- conferma eliminazione (in due passi: prima il progetto,
   poi cosa fare dei pomodori collegati) ---------------- */
function prHasChildren(id){
    // stesso controllo che il PHP fa lato server per la riga nell'elenco:
    // qui serve per mostrare il messaggio giusto anche dal popup di modifica.
    for (var k in PROJ_LOOKUP) { if (PROJ_LOOKUP[k].parent_id === id) return true; }
    return false;
}
var deletingId = 0, deletingHasChildren = false;

window.prConfirmDelete = function(id, hasChildren){
    deletingId = id;
    deletingHasChildren = !!hasChildren;
    var info = PROJ_LOOKUP[id];
    var sessionCount = info ? (info.session_count || 0) : 0;
    document.getElementById('prConfirmTitle').textContent = T('progetti.eliminare_questo_progetto');

    if (sessionCount === 0) {
        // nessun pomodoro collegato (nemmeno nei sotto-progetti): niente da
        // chiedere, resta la semplice conferma di sempre.
        var plainMsg = hasChildren ? T('progetti.progetto_ha_sotto') : '';
        plainMsg += T('progetti.questa_azione_non_puo_essere');
        document.getElementById('prConfirmMsg').textContent = plainMsg;
        document.getElementById('prConfirmActions').innerHTML =
            '<button type="button" class="pr-btn pr-btn-danger" data-on-click="prDoDelete(false)">'+T('comune.elimina')+'</button>' +
            '<button type="button" class="pr-btn pr-btn-cancel" data-on-click="prCloseConfirm()">'+T('comune.annulla')+'</button>';
        document.getElementById('prConfirmOverlay').classList.add('show');
        return;
    }

    var msg = hasChildren ? T('progetti.progetto_ha_sotto') : '';
    msg += T(hasChildren ? 'progetti.eliminare_pomodori_con_sotto' : 'progetti.eliminare_anche_pomodori');
    document.getElementById('prConfirmMsg').textContent = msg;
    document.getElementById('prConfirmActions').innerHTML =
        '<button type="button" class="pr-btn pr-btn-danger" data-on-click="prDoDelete(true)">'+T('progetti.elimina_anche_pomodori')+'</button>' +
        '<button type="button" class="pr-btn pr-btn-neutral" data-on-click="prConfirmKeepSessions()">'+T('progetti.mantieni_pomodori')+'</button>' +
        '<button type="button" class="pr-btn pr-btn-cancel" data-on-click="prCloseConfirm()">'+T('comune.annulla')+'</button>';
    document.getElementById('prConfirmOverlay').classList.add('show');
};
// scelto "mantieni i pomodori": prima di far confermare per davvero, suggeriamo
// di andare a riassegnarli, cosi' non restano tutti orfani senza accorgersene.
window.prConfirmKeepSessions = function(){
    var info = PROJ_LOOKUP[deletingId];
    var name = info ? info.name : T('progetti.questo_progetto');
    document.getElementById('prConfirmTitle').textContent = T('progetti.pomodori_senza_progetto');
    document.getElementById('prConfirmMsg').textContent =
        T('progetti.avviso_pomodori_orfani',{progetto:name}) + ' ' +
        T(deletingHasChildren ? 'progetti.pomodori_orfani_con_sotto' : 'progetti.pomodori_orfani');
    document.getElementById('prConfirmActions').innerHTML =
        '<button type="button" class="pr-btn pr-btn-neutral" data-on-click="prGoToPomodoriBeforeDelete()">'+T('progetti.vai_ai_pomodori')+'</button>' +
        '<button type="button" class="pr-btn pr-btn-danger" data-on-click="prDoDelete(false)">'+T('progetti.elimina_comunque')+'</button>' +
        '<button type="button" class="pr-btn pr-btn-cancel" data-on-click="prCloseConfirm()">'+T('comune.annulla')+'</button>';
};
window.prGoToPomodoriBeforeDelete = function(){
    var info = PROJ_LOOKUP[deletingId];
    prCloseConfirm();
    if (!window.openPomodoroStorico) return;
    window.openPomodoroStorico({ scope: 'project', projectId: deletingId, projectName: info ? info.name : '' });
    if (window.phToggleSelectMode) window.phToggleSelectMode(); // pronto per "Seleziona tutti" + "Assegna a..."
};
window.prCloseConfirm = function(){ document.getElementById('prConfirmOverlay').classList.remove('show'); };
window.prDoDelete = function(deleteSessions){
    document.getElementById('fDelId').value = deletingId;
    document.getElementById('fDelSessions').value = deleteSessions ? '1' : '0';
    // resta sul progetto attualmente aperto dopo l'eliminazione (richiesta 3)
    document.getElementById('fDelRedirect').value = CURRENT_PROJECT_ID;
    document.getElementById('formDelete').submit();
};

/* ================================================================
   SWIPE (destra = rivela Modifica/Elimina) — gesto singolo, semplice
   ================================================================ */
(function(){
    var pend = null;
    document.addEventListener('pointerdown', function(e){
        var content = e.target.closest('.pr-row-content');
        if (!content || prSubSelecting) return;
        /* Resta escluso solo il pomodoro, perché porta via dalla pagina.
           Freccia, maniglia e spunta invece lasciano passare il gesto: sono
           tre bersagli larghi in mezzo alla riga, e partendo da lì lo
           scorrimento sembrava rotto a caso. Il tocco secco su di loro
           continua a funzionare: lo scorrimento si accorge di esistere solo
           dopo sei pixel di movimento, e il trascinamento dalla maniglia si
           annulla da solo appena il dito si sposta. */
        if (e.target.closest('.pr-pomo-btn, .pr-pomo-run, .pr-note-tag, .pr-cat-tag')) return;
        /* Il titolo NON è più escluso in blocco. Prima lo era, e siccome il
           titolo occupa quasi tutta la riga, lo scorrimento non partiva quasi
           mai: sembrava rotto. Ora si esclude solo il titolo che è davvero
           più lungo dello spazio disponibile, perché su quello il gesto
           orizzontale serve a leggere il resto del nome. Un titolo corto non
           ha niente da scorrere, quindi lascia passare lo scorrimento della
           riga come tutto il resto. */
        var nome = e.target.closest('.pr-name');
        if (nome && nome._prMarquee) return;
        /* un trascinamento in corso ha la precedenza su tutto */
        if (window.prDragging) return;
        closeAllSwipes(content.closest('.pr-row'));
        var row = content.closest('.pr-row');
        var actions = row.querySelector('.swipe-actions');
        pend = { row: row, content: content, pid: e.pointerId, startX: e.clientX, startTranslate: row._swipeX || 0, maxReveal: actions ? actions.offsetWidth : 156, moved:false };
        content.style.transition = 'none';
    });
    /* Chiamata dal trascinamento quando scatta: lo scorrimento in corso si
       annulla e la riga torna dov'era, senza animazione da inseguire mentre il
       dito la sta già portando altrove. */
    window.prCancelSwipe = function(){
        if (!pend) return;
        var p = pend; pend = null;
        p.content.style.transition = '';
        p.content.style.transform = 'translateX(' + (p.startTranslate || 0) + 'px)';
    };

    document.addEventListener('pointermove', function(e){
        if (window.prDragging) { pend = null; return; }
        if (!pend || e.pointerId !== pend.pid) return;
        var dx = e.clientX - pend.startX;
        if (!pend.moved && Math.abs(dx) < 6) return;
        pend.moved = true;
        var x = pend.startTranslate + dx;
        if (x < 0) x = x * 0.15;
        if (x > pend.maxReveal) x = pend.maxReveal;
        pend.content.style.transform = 'translateX(' + x + 'px)';
        pend.currentX = x;
    });
    function endSwipe(){
        if (!pend) return;
        var p = pend; pend = null;
        p.content.style.transition = 'transform .28s var(--spring)';
        var openIt = p.moved && p.currentX > p.maxReveal * 0.45;
        var final = openIt ? p.maxReveal : 0;
        p.content.style.transform = 'translateX(' + final + 'px)';
        p.row._swipeX = final;
        if (openIt) { window.prNoteSwipeOpen(p.row); buzz(10); }
        /* Ora che il gesto può partire dalla freccia, dalla maniglia e dalla
           spunta, al rilascio arriverebbe anche il clic su quel pulsante: si
           aprirebbero i sotto-progetti, o peggio cambierebbe lo stato del
           progetto, senza che nessuno l'abbia chiesto. Dopo uno scorrimento
           vero il primo clic si ferma qui. */
        if (p.moved) {
            var fermaClic = function(ev){ ev.stopPropagation(); ev.preventDefault(); };
            document.addEventListener('click', fermaClic, true);
            /* si toglie da solo: se il clic non arriva mai (col mouse capita),
               l'ascoltatore non deve restare lì a mangiarsi i tocchi dopo */
            setTimeout(function(){ document.removeEventListener('click', fermaClic, true); }, 320);
        }
    }
    document.addEventListener('pointerup', endSwipe);
    document.addEventListener('pointercancel', endSwipe);

    /* Le righe aperte si contano sulle dita: tenerne l'elenco costa nulla ed
       evita di passare in rassegna tutte le righe della pagina a ogni tocco e
       a ogni click (era una scansione dell'intero elenco per ogni gesto). */
    var aperte = [];
    window.prNoteSwipeOpen = function(row){ if (aperte.indexOf(row) === -1) aperte.push(row); };

    window.closeAllSwipes = function(except){
        if (!aperte.length) return;
        var restano = [];
        aperte.forEach(function(row){
            if (row === except) { restano.push(row); return; }
            var content = row.querySelector('.pr-row-content');
            if (content && row._swipeX) {
                content.style.transition = 'transform .25s var(--spring)';
                content.style.transform = 'translateX(0)';
            }
            row._swipeX = 0;
        });
        aperte = restano;
    };
    document.addEventListener('click', function(e){
        if (!e.target.closest('.pr-row')) closeAllSwipes(null);
    });
})();

/* ================================================================
   SPOSTARE DENTRO UN ALTRO PROGETTO: salvataggio e «Annulla»
   ================================================================
   Si salva con la stessa azione del selettore «Sposta in…» (bulk_reparent),
   poi la pagina si ricarica: l'albero, i conti dei figli, i colori ereditati
   e le righe «Aggiungi a…» li ridisegna il server, ed e' meglio che rifarli
   qui a mano. L'avviso con «Annulla» sopravvive al ricaricamento passando da
   sessionStorage. */
window.prSpostaSalva = function (ids, nuovoPadre, info) {
    var fd = new FormData(document.getElementById('formBulkReparent'));
    fd.set('project_ids', ids.join(','));
    fd.set('new_parent_id', String(nuovoPadre || 0));
    fd.set('redirect_id', String(CURRENT_PROJECT_ID || 0));
    return fetch(window.location.href, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { if (!r.ok) throw new Error('http ' + r.status); })
        .then(function () {
            try { if (info) sessionStorage.setItem('pr_spostato', JSON.stringify(info)); } catch (e) {}
            window.location.reload();
        })
        .catch(function () { toast(T('progetti.sposta_errore')); setTimeout(function () { window.location.reload(); }, 1200); });
};
(function () {
    var info = null;
    try { info = JSON.parse(sessionStorage.getItem('pr_spostato') || 'null'); sessionStorage.removeItem('pr_spostato'); } catch (e) {}
    if (!info || !info.testo) return;
    var t = document.createElement('div');
    t.className = 'pr-undo-toast';
    t.setAttribute('role', 'status');
    var sp = document.createElement('span'); sp.textContent = info.testo; t.appendChild(sp);
    if (info.annulla) {
        var b = document.createElement('button'); b.type = 'button'; b.textContent = T('comune.annulla');
        b.addEventListener('click', function () {
            b.disabled = true;
            prSpostaSalva(info.annulla.ids, info.annulla.padre, { testo: T('progetti.spostamento_annullato') });
        });
        t.appendChild(b);
    }
    document.body.appendChild(t);
    requestAnimationFrame(function () { t.classList.add('show'); });
    setTimeout(function () { t.classList.remove('show'); setTimeout(function () { t.remove(); }, 300); }, info.annulla ? 6000 : 2500);
})();

/* ================================================================
   DRAG REORDER — tenendo premuta la riga, tra fratelli dello stesso padre
   ================================================================
   20/10/2026: la maniglia ⋮⋮ non c'è più. Era una colonna di puntini su ogni
   riga, sempre in vista per una cosa che si fa ogni tanto. Si trascina come
   sul telefono: dito fermo sulla riga per un attimo, la riga si stacca e
   segue. Col mouse basta premere e spostarsi in verticale.
   Uno scorrimento che parte dalla riga resta uno scorrimento: se il dito si
   muove prima dello scatto, non succede niente.
   Rifatto il 19/10/2026 perché non era fluido. Prima la riga in mano veniva
   spostata nel documento a ogni passo (insertBefore): saltava di posizione in
   posizione invece di seguire il dito, e le altre righe si teletrasportavano
   senza movimento. Adesso:
   - la riga si SOLLEVA e segue il dito al pixel (position:fixed + transform,
     niente ricalcolo della pagina a ogni movimento);
   - al suo posto resta un SEGNAPOSTO della stessa altezza, ed è lui a
     cambiare posto fra i fratelli;
   - i fratelli SCIVOLANO (FLIP: si misura prima e dopo lo scambio e si
     anima la differenza), invece di saltare;
   - lasciando, la riga si POSA dolcemente dove sta il segnaposto;
   - i rami chiusi all'inizio non spostano la pagina sotto il dito: la
     differenza si compensa con lo scorrimento.
   Le posizioni si leggono con offsetTop, che non risente delle animazioni in
   corso: con getBoundingClientRect una riga a metà scivolamento sembrava
   altrove, e la scelta del posto tremolava. */
(function(){
    var drag = null;
    var dragArm = null;
    /* Mezzo secondo era tanto: la maniglia è fatta apposta per spostare, e
       l'attesa serve solo a distinguere uno scorrimento iniziato lì sopra. */
    var ATTESA = 380;
    var MOLLA = 'cubic-bezier(.2,.8,.2,1)';

    function fratelli(list) {
        return Array.prototype.filter.call(list.children, function (el) {
            return el.classList.contains('pr-row-wrap') && !el.classList.contains('dragging');
        });
    }

    /* dove NON si comincia a trascinare: i pulsanti dentro la riga fanno la
       loro cosa, e nella modalità selezione la riga si tocca per sceglierla */
    var NO_DRAG = '.pr-check, .pr-arrow, .pr-pomo-btn, .pr-pomo-run, .pr-note-tag, .pr-cat-tag, .pr-lang-tag, .swipe-actions, input, textarea';

    document.addEventListener('pointerdown', function(e){
        var content = e.target.closest('.pr-row-content');
        if (!content || e.target.closest(NO_DRAG)) return;
        if (e.pointerType === 'mouse' && e.button !== 0) return;
        if (typeof prSubSelecting !== 'undefined' && prSubSelecting) return;
        var row = content.closest('.pr-row');
        if (row && row._swipeX) return;               // riga aperta: il tocco la richiude
        var wrap = content.closest('.pr-row-wrap');
        if (!wrap || !wrap.parentElement || !wrap.parentElement.closest('#prList')) return;
        var mouse = e.pointerType === 'mouse';
        dragArm = { pid: e.pointerId, startY: e.clientY, startX: e.clientX, wrap: wrap, mouse: mouse, timer: null };
        if (mouse) return;   // col mouse si parte muovendosi, vedi sotto
        /* un'anticipazione mentre si tiene premuto: la riga si abbassa appena,
           così si capisce che sta per staccarsi */
        dragArm.timer = setTimeout(function(){
            var a = dragArm; dragArm = null;
            if (!a) return;
            wrap.classList.remove('pr-arming');
            inizia(wrap, a.startY, a.pid, a.startX);
        }, ATTESA);
        setTimeout(function(){ if (dragArm && dragArm.wrap === wrap) wrap.classList.add('pr-arming'); }, 120);
    }, { passive:true });

    function disarma() {
        if (!dragArm) return;
        clearTimeout(dragArm.timer);
        dragArm.wrap.classList.remove('pr-arming');
        dragArm = null;
    }
    /* Col dito: se scorre prima che scatti, il trascinamento si annulla.
       Col mouse: uno spostamento soprattutto verticale lo fa partire (quello
       orizzontale resta allo scorrimento laterale della riga). */
    document.addEventListener('pointermove', function(e){
        if (!dragArm || e.pointerId !== dragArm.pid) return;
        var dx = Math.abs(e.clientX - dragArm.startX), dy = Math.abs(e.clientY - dragArm.startY);
        if (dragArm.mouse) {
            if (dy > 6 && dy > dx) { var a = dragArm; dragArm = null; inizia(a.wrap, a.startY, a.pid, a.startX); }
            else if (dx > 6) disarma();
            return;
        }
        if (dy > 8 || dx > 8) disarma();
    }, { passive:true });
    /* Col dito, una volta staccata la riga, il telefono non deve scorrere la
       pagina: il primo movimento si ferma qui. Senza, al primo spostamento il
       browser prendeva il gesto per uno scorrimento e lasciava cadere la riga. */
    document.addEventListener('touchmove', function(e){
        if (window.prDragging && e.cancelable) e.preventDefault();
    }, { passive:false });
    /* il menu del tocco lungo (copia, condividi…) sulla riga darebbe due cose insieme */
    document.addEventListener('contextmenu', function(e){
        if (e.target.closest && e.target.closest('#prList .pr-row-content')) e.preventDefault();
    });
    document.addEventListener('pointerup', disarma, { passive:true });
    document.addEventListener('pointercancel', disarma, { passive:true });

    function inizia(wrap, y0, pid, x0) {
        var list = wrap.parentElement;
        document.body.style.touchAction = 'none';
        document.body.classList.add('pr-dragging-now');
        window.prDragging = true;
        if (window.prCancelSwipe) window.prCancelSwipe();
        /* il nome che scorreva torna all'inizio, se no resta a metà */
        try {
            var titolo = wrap.querySelector('.pr-name');
            if (titolo && titolo._prMarquee) {
                var m = titolo._prMarquee;
                if (m.anim) { m.anim.cancel(); m.anim = null; }
                if (m.resumeTimer) { clearTimeout(m.resumeTimer); m.resumeTimer = null; }
                m.offset = 0;
                m.track.style.transform = 'translateX(0)';
            }
        } catch (err) {}
        try { var sel = window.getSelection(); if (sel && sel.removeAllRanges) sel.removeAllRanges(); } catch (err) {}

        /* I rami che non c'entrano si chiudono (con i figli aperti, per salire
           di un posto bisognava attraversarne venti), compreso quello della
           riga stessa, che così in mano è una riga sola. Si chiudono di colpo e
           la pagina viene riallineata: la riga deve restare sotto il dito. */
        var prima = wrap.getBoundingClientRect().top;
        document.body.classList.add('pr-senza-transizioni');
        var riaprire = [];
        document.querySelectorAll('.pr-children').forEach(function (k) {
            if (k === list || k.contains(wrap)) return;
            if (k.classList.contains('collapsed')) return;
            k.classList.add('collapsed');
            riaprire.push(k);
            var freccia = k.parentElement && k.parentElement.querySelector(':scope > .pr-row .pr-arrow');
            if (freccia) freccia.classList.remove('open');
        });
        var dopo = wrap.getBoundingClientRect().top;
        if (Math.abs(dopo - prima) > 0.5) window.scrollBy(0, dopo - prima);
        void document.body.offsetHeight;
        document.body.classList.remove('pr-senza-transizioni');

        var r = wrap.getBoundingClientRect();
        var ph = document.createElement('div');
        ph.className = 'pr-drag-ph';
        ph.style.height = r.height + 'px';
        list.insertBefore(ph, wrap);

        wrap.classList.add('dragging');
        wrap.style.position = 'fixed';
        wrap.style.left = r.left + 'px';
        wrap.style.top = r.top + 'px';
        wrap.style.width = r.width + 'px';
        wrap.style.zIndex = '600';
        wrap.style.transition = 'box-shadow .2s ease';
        wrap.style.transform = 'translate3d(0,0,0) scale(1.02)';

        var prima = Array.prototype.filter.call(list.children, function(el){ return el.classList.contains('pr-row-wrap'); })
                         .map(function(el){ return el.getAttribute('data-id'); }).join(',');
        drag = { wrap: wrap, list: list, ph: ph, pid: pid, y0: y0, top0: r.top, y: y0, x: x0 || (r.left + r.width / 2), moved: false, riaprire: riaprire, prima: prima,
                 dentro: null, candidato: null, candidatoDa: 0, molla: null };
        /* la riga in mano non deve "coprire" quello che c'e' sotto il dito:
           e' li' che si cerca dove lasciarla (elementsFromPoint) */
        wrap.style.pointerEvents = 'none';
        var dove = document.createElement('span');
        dove.className = 'pr-drag-dove';
        wrap.querySelector('.pr-row').appendChild(dove);
        drag.dove = dove;
        buzz(15);
    }

    /* ---- DENTRO UN ALTRO PROGETTO ----
       Come le cartelle del Finder o i progetti di Things: si porta la riga
       SOPRA un altro progetto e la si lascia li'. Valgono:
         - una riga qualunque dell'elenco, a qualunque profondita' (non la
           riga stessa, non i suoi discendenti, non il padre che ha gia');
           fra i fratelli conta la fascia centrale della riga, tenuta un
           attimo: sopra e sotto quella fascia si continua a riordinare;
         - l'etichetta «Sotto-progetti»: al primo livello di questo progetto
           (per tirare fuori una riga da dentro un'altra);
         - le briciole in alto (i progetti che contengono questo) e le
           pastiglie dei progetti principali.
       Tenendo la riga sopra un progetto chiuso per un po', si apre (come le
       cartelle "a molla" del Finder) e si puo' scendere ancora. */
    var ATTESA_NIDO = 280, ATTESA_MOLLA = 750;

    function nomeDi(el) {
        var n = el.querySelector('.pr-name, .pill-name');
        return ((n || el).textContent || '').replace(/\s+/g, ' ').trim();
    }
    function bersaglioSotto(x, y) {
        var els = document.elementsFromPoint ? document.elementsFromPoint(x, y) : [];
        var padre = parseInt(drag.wrap.getAttribute('data-parent') || '0', 10);
        for (var i = 0; i < els.length; i++) {
            var el = els[i];
            if (drag.wrap.contains(el)) continue;
            var b = null;
            var pill = el.closest('.hdr-root-pill[data-pill-id]');
            var crumb = el.closest('.pr-crumb[data-drop-id]');
            var lab = el.closest('.pr-lab');
            var row = el.closest('#prList .pr-row');
            if (pill) b = { tipo: 'pill', el: pill, id: +pill.getAttribute('data-pill-id'), nome: nomeDi(pill) };
            else if (crumb) b = { tipo: 'crumb', el: crumb, id: +crumb.getAttribute('data-drop-id'), nome: nomeDi(crumb) };
            else if (lab && CURRENT_PROJECT_ID) b = { tipo: 'lab', el: lab, id: CURRENT_PROJECT_ID, nome: '' };
            else if (row) {
                var w = row.closest('.pr-row-wrap');
                if (!w || w === drag.wrap || drag.wrap.contains(w)) return null;
                b = { tipo: 'riga', el: row, wrap: w, id: +w.getAttribute('data-id'), nome: nomeDi(row) };
            }
            if (!b) { if (el.closest('#prList') || el.closest('.pr-drag-ph')) return null; continue; }
            if (!b.id || b.id === padre || b.id === +drag.wrap.getAttribute('data-id')) return null;
            return b;
        }
        return null;
    }
    function togliDentro() {
        if (drag.dentro) drag.dentro.el.classList.remove('pr-drop-dentro');
        drag.dentro = null;
        drag.wrap.classList.remove('pr-drag-nido');
        if (drag.dove) drag.dove.textContent = '';
        if (drag.molla) { clearTimeout(drag.molla); drag.molla = null; }
    }
    function segnaDentro(b) {
        if (drag.dentro && drag.dentro.el === b.el) return;
        togliDentro();
        drag.dentro = b;
        b.el.classList.add('pr-drop-dentro');
        drag.wrap.classList.add('pr-drag-nido');
        drag.dove.textContent = b.tipo === 'lab' ? T('progetti.drop_primo_livello') : T('progetti.drop_dentro', { nome: b.nome });
        buzz(10);
        /* il progetto ha figli chiusi: tenendolo, si apre */
        if (b.tipo === 'riga') {
            var figli = b.wrap.querySelector(':scope > .pr-children.collapsed');
            if (figli) drag.molla = setTimeout(function () {
                if (!drag || !drag.dentro || drag.dentro.el !== b.el) return;
                figli.classList.remove('collapsed');
                var fr = b.wrap.querySelector(':scope > .pr-row .pr-arrow');
                if (fr) fr.classList.add('open');
                buzz(8);
            }, ATTESA_MOLLA);
        }
    }
    /* Dove sta la fascia centrale di un fratello, in coordinate del documento. */
    function fascia(wrap) {
        var row = wrap.querySelector(':scope > .pr-row') || wrap;
        var op = row.offsetParent || document.body;
        var top = op.getBoundingClientRect().top + window.scrollY + row.offsetTop;
        return { top: top, h: row.offsetHeight };
    }

    /* Le metà delle righe, in coordinate del documento, ricavate dal layout e
       non da come appaiono (le animazioni in corso le sposterebbero). */
    function meta(el) {
        var op = el.offsetParent || document.body;
        var opTop = op.getBoundingClientRect().top + window.scrollY;
        return opTop + el.offsetTop + el.offsetHeight / 2;
    }

    /* Il segnaposto cambia posto e i fratelli scivolano a fargli spazio. */
    function spostaSegnaposto(rif) {
        var list = drag.list, ph = drag.ph;
        /* già lì? (la riga in mano, fissa, sta ancora nell'elenco: si salta) */
        var n = ph.nextElementSibling;
        while (n && (n === drag.wrap || !n.classList.contains('pr-row-wrap'))) n = n.nextElementSibling;
        if (n === rif) return;
        var sib = fratelli(list);
        var primaTop = sib.map(function (el) { return el.getBoundingClientRect().top; });
        /* in fondo, ma prima della riga «Aggiungi»: quella resta l'ultima */
        var fondo = list.querySelector(':scope > .pr-add-line');
        if (rif) list.insertBefore(ph, rif); else if (fondo) list.insertBefore(ph, fondo); else list.appendChild(ph);
        sib.forEach(function (el, i) {
            var d = primaTop[i] - el.getBoundingClientRect().top;
            if (Math.abs(d) < 0.5) return;
            el.style.transition = 'none';
            el.style.transform = 'translate3d(0,' + d + 'px,0)';
            void el.offsetHeight;
            el.style.transition = 'transform .24s ' + MOLLA;
            el.style.transform = '';
        });
        buzz(6);
    }

    function aggiorna() {
        if (!drag) return;
        drag.raf = null;
        var dy = drag.y - drag.y0;
        drag.wrap.style.transform = 'translate3d(0,' + dy + 'px,0) scale(1.02)';
        var yDoc = drag.y + window.scrollY;

        /* sopra un progetto che non e' un fratello: dentro, subito */
        var b = bersaglioSotto(drag.x, drag.y);
        var fratello = b && b.tipo === 'riga' && b.wrap.parentElement === drag.list;
        if (b && !fratello) { drag.candidato = null; segnaDentro(b); return; }

        /* fra i fratelli: la fascia centrale (tenuta un attimo) vuol dire
           dentro, sopra e sotto si riordina come sempre */
        if (fratello) {
            var f = fascia(b.wrap);
            var rel = (yDoc - f.top) / Math.max(1, f.h);
            if (rel > 0.27 && rel < 0.73) {
                if (drag.dentro && drag.dentro.el === b.el) return;
                if (!drag.candidato || drag.candidato.el !== b.el) {
                    drag.candidato = b; drag.candidatoDa = Date.now();
                    setTimeout(function () { if (drag && drag.candidato === b) aggiorna(); }, ATTESA_NIDO + 20);
                    return;
                }
                if (Date.now() - drag.candidatoDa >= ATTESA_NIDO) { segnaDentro(b); return; }
                return;
            }
        }
        drag.candidato = null;
        togliDentro();
        var sib = fratelli(drag.list), rif = null;
        for (var i = 0; i < sib.length; i++) {
            if (yDoc < meta(sib[i])) { rif = sib[i]; break; }
        }
        spostaSegnaposto(rif);
    }

    /* Scorrimento automatico ai bordi: piano vicino alla soglia, veloce
       sull'orlo. La riga in mano è fissa sullo schermo e resta sotto il dito;
       il segnaposto si ricalcola a ogni passo. */
    function scorriSeAlBordo() {
        if (!drag) return;
        if (!drag.scrollV) {
            if (drag.scrollRaf) { cancelAnimationFrame(drag.scrollRaf); drag.scrollRaf = null; }
            return;
        }
        if (drag.scrollRaf) return;
        (function passo() {
            if (!drag || !drag.scrollV) { if (drag) drag.scrollRaf = null; return; }
            window.scrollBy(0, drag.scrollV);
            aggiorna();
            drag.scrollRaf = requestAnimationFrame(passo);
        })();
    }

    document.addEventListener('pointermove', function(e){
        if (!drag || e.pointerId !== drag.pid) return;
        e.preventDefault();
        drag.moved = true;
        drag.y = e.clientY;
        drag.x = e.clientX;
        var soglia = 90, y = e.clientY;
        var alto = y - soglia, basso = (window.innerHeight - soglia) - y;
        drag.scrollV = (alto < 0) ? Math.max(-18, alto / 5)
                     : ((basso < 0) ? Math.min(18, -basso / 5) : 0);
        scorriSeAlBordo();
        /* un solo aggiornamento per fotogramma */
        if (!drag.raf) drag.raf = requestAnimationFrame(aggiorna);
    }, { passive:false });

    function endDrag(){
        if (!drag) return;
        var d = drag; drag = null;
        if (d.raf) cancelAnimationFrame(d.raf);
        if (d.scrollRaf) cancelAnimationFrame(d.scrollRaf);
        if (d.molla) clearTimeout(d.molla);
        document.body.style.touchAction = '';
        window.prDragging = false;
        d.wrap.style.pointerEvents = '';
        if (d.dove) d.dove.remove();
        /* Lasciando la riga arriverebbe anche il clic sul nome, che apre il
           sotto-progetto: dopo un trascinamento il primo clic si ferma qui. */
        var fermaClic = function(ev){ ev.stopPropagation(); ev.preventDefault(); };
        document.addEventListener('click', fermaClic, true);
        setTimeout(function(){ document.removeEventListener('click', fermaClic, true); }, 350);

        /* LASCIATA SOPRA UN PROGETTO: ci entra. La riga vola dentro il
           bersaglio e sparisce, si salva, e la pagina si ridisegna con
           l'albero nuovo; un avviso con «Annulla» la riporta dov'era. */
        if (d.dentro) {
            var b = d.dentro;
            var rt = b.el.getBoundingClientRect(), rw = d.wrap.getBoundingClientRect();
            var vx = (rt.left + rt.width / 2) - (rw.left + rw.width / 2);
            var vy = (rt.top + rt.height / 2) - (rw.top + rw.height / 2);
            var dy0 = parseFloat((d.wrap.style.transform.match(/translate3d\(0(?:px)?,\s*(-?[\d.]+)px/) || [0, 0])[1]) || 0;
            d.wrap.style.transition = 'transform .32s cubic-bezier(.4,0,.2,1), opacity .32s ease';
            d.wrap.style.transform = 'translate3d(' + vx + 'px,' + (dy0 + vy) + 'px,0) scale(.25)';
            d.wrap.style.opacity = '0';
            b.el.classList.remove('pr-drop-dentro');
            b.el.classList.add('pr-drop-preso');
            buzz([10, 30, 16]);
            var id = d.wrap.getAttribute('data-id');
            var vecchioPadre = parseInt(d.wrap.getAttribute('data-parent') || '0', 10);
            var nome = nomeDi(d.wrap.querySelector('.pr-row'));
            prSpostaSalva([id], b.id, { annulla: { ids: [id], padre: vecchioPadre }, testo: b.tipo === 'lab'
                ? T('progetti.spostato_primo_livello', { nome: nome })
                : T('progetti.spostato_dentro', { nome: nome, dove: b.nome }) });
            return;
        }

        /* la riga si posa dove sta il segnaposto, poi torna al suo posto vero */
        var dest = d.ph.getBoundingClientRect().top;
        d.wrap.style.transition = 'transform .22s ' + MOLLA + ', box-shadow .22s ease';
        d.wrap.style.transform = 'translate3d(0,' + (dest - d.top0) + 'px,0) scale(1)';
        d.wrap.classList.add('pr-posando');

        setTimeout(function () {
            d.list.insertBefore(d.wrap, d.ph);
            d.ph.remove();
            ['position', 'left', 'top', 'width', 'zIndex', 'transition', 'transform'].forEach(function (p) { d.wrap.style[p] = ''; });
            d.wrap.classList.remove('dragging', 'pr-posando');
            fratelli(d.list).forEach(function (el) { el.style.transition = ''; el.style.transform = ''; });
            document.body.classList.remove('pr-dragging-now');
            // i rami tornano com'erano prima che li chiudessimo noi
            d.riaprire.forEach(function (k) {
                k.classList.remove('collapsed');
                var freccia = k.parentElement && k.parentElement.querySelector(':scope > .pr-row .pr-arrow');
                if (freccia) freccia.classList.add('open');
            });
            if (!d.moved) return;
            var ids = Array.prototype.filter.call(d.list.children, function(el){ return el.classList.contains('pr-row-wrap'); })
                            .map(function(el){ return el.getAttribute('data-id'); });
            if (ids.join(',') === d.prima) return;
            document.getElementById('fReorderIds').value = ids.join(',');
            /* il nuovo ordine si salva in background: la pagina resta dov'è */
            var form = document.getElementById('formReorder');
            fetch(window.location.href, { method:'POST', body:new FormData(form), credentials:'same-origin' })
                .then(function(){ toast(T('progetti.ordine_aggiornato')); })
                .catch(function(){
                    toast(T('progetti.ordine_non_salvato'));
                    window.location.reload();
                });
        }, 230);
    }
    document.addEventListener('pointerup', endDrag);
    document.addEventListener('pointercancel', endDrag);
})();

/* ================================================================
   SCORRIMENTO AUTOMATICO DEI TITOLI (marquee) — per i nomi troncati
   su schermi stretti. Ogni titolo resta fermo qualche secondo,
   scorre lentamente fino a mostrarsi per intero, resta fermo alla
   fine, poi torna dolcemente all'inizio e ricomincia in loop.
   L'utente può sempre trascinare il titolo a mano (senza che questo
   valga come swipe della riga): mentre lo tocca l'animazione
   automatica si ferma e riparte da sola poco dopo.

   Nota tecnica: l'animazione è affidata alla Web Animations API su
   una proprietà "transform" (thread di composizione del browser),
   non a un nostro loop che sposta scrollLeft ad ogni frame: così
   resta fluida anche con moltissime righe in pagina.
   ================================================================ */
(function(){
    var PAUSE_START = 1800;   // ms fermo all'inizio
    var PAUSE_END = 1800;     // ms fermo alla fine
    var RESET_MS = 700;       // ms per il ritorno morbido all'inizio
    var SPEED = 34;           // px al secondo durante lo scorrimento
    var MIN_SCROLL_MS = 2200;
    var MAX_SCROLL_MS = 14000;
    var RESUME_DELAY = 1800;  // ms di inattività prima che l'auto-scroll riparta

    function currentTranslateX(el){
        var tr = getComputedStyle(el).transform;
        if (!tr || tr === 'none') return 0;
        var m = tr.match(/matrix3d\(([^)]+)\)/);
        if (m) { var p = m[1].split(',').map(Number); return p[12] || 0; }
        m = tr.match(/matrix\(([^)]+)\)/);
        if (m) { var q = m[1].split(',').map(Number); return q[4] || 0; }
        return 0;
    }

    /* (la vecchia buildEntry è sparita: misurava una riga alla volta, ed è
       proprio quello che si voleva smettere di fare. Ora misura in blocco
       misuraInBlocco, più sotto.) */

    function startAutoAnim(entry){
        var total = entry.total, max = entry.max;
        var p1 = PAUSE_START / total;
        var p2 = (PAUSE_START + entry.scrollMs) / total;
        var p3 = (PAUSE_START + entry.scrollMs + PAUSE_END) / total;
        entry.track.style.transform = '';
        entry.anim = entry.track.animate([
            { transform: 'translateX(0px)',        offset: 0,  easing: 'linear' },
            { transform: 'translateX(0px)',        offset: p1, easing: 'ease-in-out' },
            { transform: 'translateX(' + (-max) + 'px)', offset: p2, easing: 'linear' },
            { transform: 'translateX(' + (-max) + 'px)', offset: p3, easing: 'ease-in-out' },
            { transform: 'translateX(0px)',        offset: 1 }
        ], { duration: total, iterations: Infinity, delay: Math.random() * 400 });
    }

    function pauseForUser(entry){
        if (entry.resumeTimer) clearTimeout(entry.resumeTimer);
        var curX = currentTranslateX(entry.track);
        if (entry.anim) { entry.anim.cancel(); entry.anim = null; }
        entry.track.style.transform = 'translateX(' + curX + 'px)';
        entry.offset = curX;
    }

    function scheduleResume(entry){
        if (entry.resumeTimer) clearTimeout(entry.resumeTimer);
        entry.resumeTimer = setTimeout(function(){
            var from = entry.offset;
            if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
            if (Math.abs(from) < 1) { startAutoAnim(entry); return; }
            var back = entry.track.animate(
                [{ transform: 'translateX(' + from + 'px)' }, { transform: 'translateX(0px)' }],
                { duration: RESET_MS, easing: 'ease-in-out', fill: 'forwards' }
            );
            back.onfinish = function(){
                entry.track.style.transform = 'translateX(0px)';
                entry.offset = 0;
                startAutoAnim(entry);
            };
        }, RESUME_DELAY);
    }

    function attachDrag(entry){
        var el = entry.el, track = entry.track;
        var dragging = false, pid = null, startX = 0, startOffset = 0;

        el.addEventListener('pointerdown', function(e){
            pauseForUser(entry);
            dragging = true;
            pid = e.pointerId;
            startX = e.clientX;
            startOffset = entry.offset;
            entry.justDragged = false;
            if (el.setPointerCapture) { try { el.setPointerCapture(pid); } catch(_){} }
        });
        el.addEventListener('pointermove', function(e){
            if (!dragging || e.pointerId !== pid) return;
            var dx = e.clientX - startX;
            if (Math.abs(dx) > 4) entry.justDragged = true;
            var next = startOffset + dx;
            if (next > 0) next = 0;
            if (next < -entry.max) next = -entry.max;
            entry.offset = next;
            track.style.transform = 'translateX(' + next + 'px)';
        });
        function stopDrag(e){
            if (!dragging || (e && e.pointerId !== pid)) return;
            dragging = false;
            scheduleResume(entry);
        }
        el.addEventListener('pointerup', stopDrag);
        el.addEventListener('pointercancel', stopDrag);
        // evita che il rilascio dopo un trascinamento venga letto anche come "tap" di apertura progetto
        el.addEventListener('click', function(e){
            if (entry.justDragged) { e.stopImmediatePropagation(); e.preventDefault(); entry.justDragged = false; }
        }, true);
    }

    /* ------------------------------------------------------------------
       PERCHÉ QUESTA PARTE È FATTA COSÌ (era la causa della lentezza)

       Prima, al caricamento, si passava su OGNI titolo della pagina e per
       ognuno si leggevano offsetWidth e clientWidth: ogni lettura obbliga il
       browser a ricalcolare il layout lì per lì, e con un elenco lungo sono
       centinaia di ricalcoli in fila prima ancora che la pagina si veda.
       Poi ogni titolo troncato si portava dietro un'animazione infinita e
       cinque ascoltatori, anche a chilometri sotto lo schermo: decine di
       animazioni sempre in moto, per niente.

       Ora: nessuno tocca un titolo finché non entra davvero in vista. Chi
       entra viene misurato insieme agli altri in un colpo solo (prima tutte
       le letture, poi tutte le scritture: nessun ricalcolo a catena), e chi
       esce dallo schermo mette in pausa la sua animazione invece di
       continuare a girare al buio.
       ------------------------------------------------------------------ */

    var ridotto = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var osservatore = null;
    var inAttesa = [];      // entrati in vista, ancora da misurare
    var misuraProgrammata = false;

    function misuraInBlocco(){
        misuraProgrammata = false;
        var lotto = inAttesa;
        inAttesa = [];
        if (!lotto.length) return;

        /* 1) SOLO letture: il layout si calcola una volta per tutto il lotto */
        var misure = lotto.map(function(el){
            var track = el.querySelector('.pr-name-track');
            if (!track) return null;
            return { el: el, track: track, max: track.offsetWidth - el.clientWidth };
        });

        /* 2) SOLO scritture: da qui in poi nessuno rilegge più niente */
        misure.forEach(function(m){
            if (!m || m.max <= 2) {
                /* non serve scorrere: si smette di guardarlo del tutto,
                   altrimenti l'osservatore continuerebbe a riferire di una
                   riga su cui non c'è più niente da fare */
                if (m) {
                    m.el._prMarqueeSkip = true;
                    if (osservatore) osservatore.unobserve(m.el);
                }
                return;
            }
            var scrollMs = Math.min(MAX_SCROLL_MS, Math.max(MIN_SCROLL_MS, (m.max / SPEED) * 1000));
            var entry = { el: m.el, track: m.track, max: m.max, scrollMs: scrollMs,
                          total: PAUSE_START + scrollMs + PAUSE_END + RESET_MS,
                          anim: null, resumeTimer: null, offset: 0, justDragged: false };
            m.el._prMarquee = entry;
            attachDrag(entry);
            if (!ridotto) startAutoAnim(entry);
        });
    }

    function programmaMisura(el){
        if (el._prMarqueeSkip || el._prMarquee) return;
        inAttesa.push(el);
        if (misuraProgrammata) return;
        misuraProgrammata = true;
        requestAnimationFrame(misuraInBlocco);
    }

    function creaOsservatore(){
        if (osservatore || !('IntersectionObserver' in window)) return osservatore;
        osservatore = new IntersectionObserver(function(voci){
            voci.forEach(function(v){
                var el = v.target;
                if (v.isIntersecting) {
                    if (el._prMarquee) {
                        /* già misurato: riparte da dov'era */
                        if (el._prMarquee.anim && el._prMarquee.anim.playState === 'paused') el._prMarquee.anim.play();
                    } else {
                        programmaMisura(el);
                    }
                } else if (el._prMarquee && el._prMarquee.anim) {
                    /* fuori dallo schermo non ha senso animare: si mette in
                       pausa, non si annulla, così tornando riprende il filo */
                    el._prMarquee.anim.pause();
                }
            });
        }, { rootMargin: '200px 0px' });   // un po' di anticipo: entra già in moto
        return osservatore;
    }

    window.prInitNameMarquees = function(root){
        var scope = root || document;
        var els = scope.querySelectorAll('.pr-name, .pr-dates-item-name');
        var io = creaOsservatore();
        Array.prototype.forEach.call(els, function(el){
            if (el._prMarqueeInit) return;
            el._prMarqueeInit = true;
            if (io) { io.observe(el); return; }
            /* senza IntersectionObserver (browser vecchi) si torna al modo di
               prima, ma solo per i titoli già nello schermo */
            var r = el.getBoundingClientRect();
            if (r.top < window.innerHeight + 200 && r.bottom > -200) programmaMisura(el);
        });
    };

    /* il ripasso serve solo a chi non ha l'osservatore */
    if (!('IntersectionObserver' in window)) {
        var ripassoTimer = null;
        window.addEventListener('scroll', function(){
            if (ripassoTimer) return;
            ripassoTimer = setTimeout(function(){
                ripassoTimer = null;
                var els = document.querySelectorAll('.pr-name, .pr-dates-item-name');
                Array.prototype.forEach.call(els, function(el){
                    if (el._prMarquee || el._prMarqueeSkip) return;
                    var r = el.getBoundingClientRect();
                    if (r.top < window.innerHeight + 200 && r.bottom > -200) programmaMisura(el);
                });
            }, 260);
        }, { passive: true });
    }

    window.prInitNameMarquees();
})();

})();
</script>

<script src="nota_auto.js?v=<?php echo @filemtime(__DIR__ . '/nota_auto.js') ?: 1; ?>"></script>
<script src="piano.js?v=<?php echo @filemtime(__DIR__ . '/piano.js') ?: 1; ?>"></script>
<script nonce="<?php echo sec_nonce(); ?>">
/* Il piano: le viste Calendario e Piano di un progetto, e la vista
   «Calendario» di tutti i progetti. piano.js disegna, qui si sceglie quale. */
(function(){
    var P = window.StandoutPiano;
    if (!P) return;
    var master = document.getElementById('ppMaster');
    if (master) { P.master(master); return; }
    var host = document.getElementById('ppHost');
    var bar = document.getElementById('ppViews');
    if (!host || !bar) return;
    var started = false;
    function marca(v){
        document.body.setAttribute('data-pp', v);
        Array.prototype.forEach.call(bar.querySelectorAll('button'), function(b){ b.classList.toggle('on', b.getAttribute('data-pp') === v); });
    }
    window.PR_PIANO_SEG = marca;
    window.ppVai = function(v){
        if (v !== 'cal' && v !== 'plan') v = 'list';
        marca(v);
        try {
            var u = new URL(window.location.href);
            if (v === 'list') u.searchParams.delete('pp'); else u.searchParams.set('pp', v);
            window.history.replaceState(null, '', u.toString());
        } catch (e) {}
        if (v === 'list') return;
        if (!started) { started = true; P.project(host, parseInt(host.getAttribute('data-project'), 10) || 0, v); }
        else P.setTab(v);
    };
    bar.addEventListener('click', function(e){
        var b = e.target.closest('button[data-pp]');
        if (b) window.ppVai(b.getAttribute('data-pp'));
    });
    var iniziale = new URLSearchParams(window.location.search).get('pp');
    if (iniziale === 'cal' || iniziale === 'plan') window.ppVai(iniziale);
})();
</script>
<?php include __DIR__ . '/../planner/todo_widget.php'; ?>
<script nonce="<?php echo sec_nonce(); ?>">
/* La sezione attività del progetto: si accende dal tastino e mostra le
   attività collegate a questo progetto, le stesse della pagina to-do. */
(function prTodoSection(){
    var btn = document.getElementById('prTodoBtn');
    var box = document.getElementById('prHeroTodo');
    if (!btn || !box) return;
    var pid = parseInt(btn.dataset.project, 10) || 0;
    var mounted = false;

    function mount(){
        if (mounted || !window.standoutTodo) return;
        window.standoutTodo.mount(box, { scope:'project', projectId: pid });
        mounted = true;
    }
    if (btn.classList.contains('is-on')) mount();

    btn.addEventListener('click', function(){
        var next = !btn.classList.contains('is-on');
        btn.classList.toggle('is-on', next);
        box.classList.toggle('pr-hidden', !next);
        if (next) mount();
        var body = new URLSearchParams({ project_id: pid, value: next ? 1 : 0 });
        fetch('progetti.php?ajax=toggle_todo', {
            method:'POST', credentials:'same-origin',
            headers:{ 'Content-Type':'application/x-www-form-urlencoded' },
            body: body.toString()
        }).catch(function(){ toast(T('progetti.non_salvato')); });
    });
})();
</script>
<!-- Impostazioni della pagina. Cinque scelte che restano: quello che si vede
     e in che ordine. Sono modi di guardare, non modifiche ai progetti — per
     questo stanno qui e non nella scheda di un progetto. -->
<div class="pr-opz-ov" id="prOpzOv" data-on-click="if(event.target===this) prCloseOpzioni()">
    <div class="pr-opz">
        <div class="pr-opz-head">
            <span class="pr-opz-tit"><?php te('progetti.impostazioni'); ?></span>
            <button type="button" class="pr-opz-x" data-on-click="prCloseOpzioni()" aria-label="<?php te('comune.chiudi'); ?>">
                <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"><path d="M6 6l12 12M18 6 6 18"/></svg>
            </button>
        </div>

        <label class="pr-opz-riga">
            <input type="checkbox" id="prOpzCompletati">
            <span class="pr-opz-n"><?php te('progetti.opz_completati'); ?>
                <em><?php te('progetti.opz_completati_sub'); ?></em></span>
        </label>

        <label class="pr-opz-riga">
            <input type="checkbox" id="prOpzTag">
            <span class="pr-opz-n"><?php te('progetti.opz_tag'); ?>
                <em><?php te('progetti.opz_tag_sub'); ?></em></span>
        </label>

        <label class="pr-opz-riga">
            <input type="checkbox" id="prOpzRami">
            <span class="pr-opz-n"><?php te('progetti.opz_rami'); ?>
                <em><?php te('progetti.opz_rami_sub'); ?></em></span>
        </label>

        <label class="pr-opz-riga">
            <input type="checkbox" id="prOpzAttivi">
            <span class="pr-opz-n"><?php te('progetti.opz_attivi'); ?>
                <em><?php te('progetti.opz_attivi_sub'); ?></em></span>
        </label>

        <div class="pr-opz-sep"></div>
        <div class="pr-opz-lab"><?php te('progetti.opz_ordine'); ?></div>
        <div class="pr-opz-ordine" id="prOpzOrdine">
            <button type="button" data-ord="manuale"><?php te('progetti.opz_manuale'); ?></button>
            <button type="button" data-ord="alfabetico"><?php te('progetti.opz_alfabetico'); ?></button>
            <button type="button" data-ord="recenti"><?php te('progetti.opz_recenti'); ?></button>
            <button type="button" data-ord="scadenza"><?php te('progetti.opz_scadenza'); ?></button>
        </div>
    </div>
</div>

<script nonce="<?php echo sec_nonce(); ?>">
/* ============================================================
   IMPOSTAZIONI DELLA PAGINA
   Applicare le scelte richiede di ricostruire l'albero: ordinamenti e filtri
   li fa il server, che ha tutti i progetti e non solo quelli disegnati. Quindi
   si salva e si ricarica — una volta sola, alla chiusura del pannello, non a
   ogni spunta: quattro ricariche di fila per quattro spunte sarebbero quattro
   attese per una decisione sola.
   ============================================================ */
var PR_OPZ = <?php echo json_encode($prOpz, JSON_UNESCAPED_UNICODE); ?>;
var prOpzCambiate = false;

function prOpenOpzioni() {
    document.getElementById('prOpzCompletati').checked = !!PR_OPZ.completati;
    document.getElementById('prOpzTag').checked        = !!PR_OPZ.mostra_tag;
    document.getElementById('prOpzRami').checked       = !!PR_OPZ.ricorda_rami;
    document.getElementById('prOpzAttivi').checked     = !!PR_OPZ.solo_attivi;
    prOpzSegnaOrdine();
    prOpzCambiate = false;
    document.getElementById('prOpzOv').classList.add('is-open');
}

function prOpzSegnaOrdine() {
    document.querySelectorAll('#prOpzOrdine button').forEach(function (b) {
        b.classList.toggle('active', b.dataset.ord === PR_OPZ.ordine);
    });
}

function prCloseOpzioni() {
    document.getElementById('prOpzOv').classList.remove('is-open');
    if (!prOpzCambiate) return;   // niente da salvare, niente da ricaricare
    var body = new URLSearchParams();
    body.set('opzioni', JSON.stringify(PR_OPZ));
    fetch('progetti.php?ajax=salva_opzioni', {
        method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString()
    }).then(function () { location.reload(); })
      .catch(function () { location.reload(); });
}

document.addEventListener('DOMContentLoaded', function () {
    var mappa = {
        prOpzCompletati: 'completati', prOpzTag: 'mostra_tag',
        prOpzRami: 'ricorda_rami', prOpzAttivi: 'solo_attivi'
    };
    Object.keys(mappa).forEach(function (id) {
        var el = document.getElementById(id);
        if (!el) return;
        el.addEventListener('change', function () {
            PR_OPZ[mappa[id]] = el.checked ? 1 : 0;
            prOpzCambiate = true;
        });
    });
    var ord = document.getElementById('prOpzOrdine');
    if (ord) ord.addEventListener('click', function (e) {
        var b = e.target.closest('button[data-ord]');
        if (!b) return;
        PR_OPZ.ordine = b.dataset.ord;
        prOpzCambiate = true;
        prOpzSegnaOrdine();
    });

    /* «Mostra i tag» si applica subito, senza ricaricare: è l'unica delle
       cinque che non cambia quali progetti ci sono, solo quanto occupano. */
    document.body.classList.toggle('pr-senza-tag', !PR_OPZ.mostra_tag);
    var tagChk = document.getElementById('prOpzTag');
    if (tagChk) tagChk.addEventListener('change', function () {
        document.body.classList.toggle('pr-senza-tag', !tagChk.checked);
    });
});
</script>

<script nonce="<?php echo sec_nonce(); ?>">
/* ============================================================
   LA SCHEDA DEL TITOLO
   Tutto quello che prima si faceva dal popup di modifica si fa qui, dove la
   cosa si vede: il nome scrivendoci sopra, il colore dal tondo accanto, il
   deck e le date dai loro tag. Il popup restava il posto più lontano da dove
   il dato è mostrato, e ogni modifica costava aprire, cercare, salvare,
   chiudere.
   ============================================================ */

/* -------- il nome, scrivendoci sopra --------
   Si salva quando si esce dal campo, non a ogni lettera: salvare mentre si
   scrive vorrebbe dire una richiesta per battuta. Invio conferma, Escape
   rimette com'era — le due scorciatoie che chiunque prova per prime. */
(function () {
    var el = document.getElementById('prHeroName');
    if (!el) return;

    function salva() {
        var nuovo = (el.textContent || '').trim();
        var prima = el.dataset.prima || '';
        if (!nuovo) { el.textContent = prima; return; }   // un progetto senza nome non esiste
        if (nuovo === prima) return;
        el.dataset.prima = nuovo;
        var body = new URLSearchParams();
        body.set('project_id', el.dataset.id);
        body.set('name', nuovo);
        fetch('progetti.php?ajax=rinomina', {
            method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString()
        }).then(function (r) { return r.json(); }).then(function (d) {
            if (!d || !d.ok) { el.textContent = prima; el.dataset.prima = prima; return; }
            /* Il nome compare anche nelle pastiglie in alto e nelle briciole:
               si aggiornano qui invece di ricaricare la pagina, che a metà di
               una modifica è la cosa che fa perdere il filo. */
            document.querySelectorAll('[data-pill-id="' + el.dataset.id + '"] .pill-name').forEach(function (x) { x.textContent = nuovo; });
        }).catch(function () { el.textContent = prima; el.dataset.prima = prima; });
    }

    el.addEventListener('blur', salva);
    el.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); el.blur(); }
        if (e.key === 'Escape') { e.preventDefault(); el.textContent = el.dataset.prima || ''; el.blur(); }
    });
    /* Incollando si porta dietro il formato da dove si è copiato: qui è un
       nome, e un nome in grassetto rosso dentro un titolo è un incidente. */
    el.addEventListener('paste', function (e) {
        e.preventDefault();
        var testo = (e.clipboardData || window.clipboardData).getData('text');
        document.execCommand('insertText', false, (testo || '').replace(/\s+/g, ' ').trim());
    });
})();

/* -------- il colore -------- */
var prColoreId = 0;
window.prScegliColore = function (id) {
    prColoreId = id;
    document.getElementById('prColorOverlay').classList.add('show');
};
window.prColorePick = function (valore) {
    if (!prColoreId) return;
    document.getElementById('fBulkColorIds').value = String(prColoreId);
    document.getElementById('fBulkColorHex').value = valore;
    document.getElementById('fBulkColorRedirect').value = CURRENT_PROJECT_ID;
    /* Si riusa il modulo del colore in blocco con un id solo: è esattamente la
       stessa operazione, e un secondo endpoint per «lo stesso ma per uno» è un
       secondo posto in cui la regola dell'ereditarietà può divergere. */
    document.getElementById('formBulkColor').submit();
};

/* -------- il deck -------- */
window.prScegliDeck = function (id, attuale) {
    var modale = document.getElementById('cs-modal-category') || document.getElementById('cat-selector-modal');
    if (!modale || !window.csOpen) { toast(T('comune.errore_generico')); return; }
    var ascolta = function (e) {
        document.removeEventListener('categoryselected', ascolta);
        var cat = (e.detail && e.detail.id) ? e.detail.id : 0;
        var body = new URLSearchParams();
        body.set('project_id', id);
        body.set('fc_category_id', cat);
        fetch('progetti.php?ajax=collega_deck', {
            method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString()
        }).then(function () { location.reload(); });
    };
    document.addEventListener('categoryselected', ascolta);
    window.csOpen(modale);
};

/* Il «+N» dei figli con un deck: si apre e si richiude toccando fuori. Non è
   un comando, è un elenco che si legge — per questo non porta da nessuna
   parte e non cambia niente. */
window.prDeckFigliApri = function (e, btn) {
    e.stopPropagation();
    var tag = document.getElementById('prDeckFigli');
    if (!tag) return;
    var aperto = tag.classList.toggle('is-aperto');
    btn.textContent = aperto ? '−' : ('+' + btn.dataset.n);
};
document.addEventListener('click', function () {
    var tag = document.getElementById('prDeckFigli');
    if (!tag || !tag.classList.contains('is-aperto')) return;
    tag.classList.remove('is-aperto');
    var b = tag.querySelector('.pr-deck-piu');
    if (b) b.textContent = '+' + b.dataset.n;
});

/* -------- la nota, modificabile sul posto --------
   Un tocco apre l'editor con la sua barra; non ci sono Salva e Annulla: si
   salva da solo mentre si scrive (nota_auto.js), anche senza rete, e c'e' un
   «Annulla» per tornare indietro. Si chiude toccando fuori o con Esc.
   Il tocco e' sicuro anche leggendo una nota lunga: scorrendo il browser non
   genera nessun «click». */
(function () {
    var vista = document.getElementById('prNota');
    var box = document.getElementById('prNotaEdit');
    if (!vista || !box || !window.PrNota) return;

    function vuota(raw) { return PrNota.vuota(raw); }
    /* La nota disegnata, o l'invito «Aggiungi una nota» se non c'e' niente. */
    function mostra(raw) {
        vista.dataset.raw = raw || '';
        var etAltro = document.getElementById('prAltroNota');
        if (etAltro) etAltro.textContent = T(vuota(raw) ? 'progetti.aggiungi_valore' : 'comune.modifica');
        if (vuota(raw)) {
            vista.classList.add('pr-nota-vuota');
            vista.classList.remove('raw-text-content');
            vista.textContent = T('progetti.aggiungi_nota');
        } else {
            vista.classList.remove('pr-nota-vuota');
            vista.classList.add('raw-text-content');
            PrNota.disegna(vista, raw);
        }
    }
    function id() { return parseInt(vista.dataset.id, 10) || 0; }

    var ctl = PrNota.collega({
        box: box,
        id: id,
        iniziale: function () { return vista.dataset.raw || ''; },
        alSalvato: function (pid, nota) {
            if (window.PROJ_LOOKUP && PROJ_LOOKUP[pid]) PROJ_LOOKUP[pid].note = nota;
            if (box.hidden) mostra(nota);
        }
    });
    if (!ctl) return;

    /* Rete di sicurezza: una nota che, disegnata, non mostra niente (per
       esempio solo formattazione) diventa «Aggiungi una nota» invece di un
       riquadro vuoto. */
    function controllaVuota() {
        if (vista.classList.contains('pr-nota-vuota')) return;
        if ((vista.textContent || '').replace(/[\s\u00A0\u200B-\u200D\uFEFF]+/g, '') !== '') return;
        if (vista.querySelector('img, svg, mjx-container, .MathJax')) return;
        mostra('');
    }
    /* appena fc_parser ha disegnato le note (DOMContentLoaded), non dopo */
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', controllaVuota);
    else controllaVuota();
    window.addEventListener('load', function () { setTimeout(controllaVuota, 400); });

    /* Una modifica fatta offline e non ancora arrivata sul server vale piu'
       di quello che il server ha mandato: la pagina la mostra subito. */
    var q = PrNota.inCoda(id());
    if (q) mostra(q.note);

    window.prNotaApri = function () {
        if (!box.hidden) return;
        vista.hidden = true;
        box.hidden = false;
        ctl.apri();
    };
    window.prNotaChiudi = function () {
        if (box.hidden) return;
        var v = ctl.valore();
        ctl.chiudi();
        box.hidden = true;
        vista.hidden = false;
        mostra(v);
    };

    vista.addEventListener('click', function (e) {
        // un link dentro la nota si apre, non fa partire la modifica
        if (e.target.closest && e.target.closest('a[href]')) return;
        var sel = window.getSelection && window.getSelection();
        if (sel && !sel.isCollapsed && vista.contains(sel.anchorNode)) return;   // si stava selezionando del testo
        window.prNotaApri();
    });
    vista.setAttribute('role', 'button');
    vista.tabIndex = 0;
    vista.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); window.prNotaApri(); }
    });

    /* fuori dall'editor = ho finito. La barra puo' stare agganciata in cima
       e fuori dal riquadro, e le finestrelle (formule, colori) sono altrove:
       per questo si controlla un elenco di cose "dell'editor" e non solo il
       riquadro. */
    function dellEditor(el) {
        return el.closest && el.closest('#prNotaEdit, .fmt-toolbar, [id$="_bar"], .fmt-pop, .fmt-menu, .mq-editable-field, .mq-math-mode, .pr-toast, .pr-offline, dialog, [role="dialog"], [role="menu"]');
    }
    document.addEventListener('pointerdown', function (e) {
        if (box.hidden || dellEditor(e.target)) return;
        window.prNotaChiudi();
    }, true);
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !box.hidden) window.prNotaChiudi();
    });

    /* Quando una modifica rimasta in coda parte (rete tornata, pagina
       riaperta), la pagina si aggiorna con la versione che il server ha
       ripulito — se non ci si sta scrivendo sopra. */
    PrNota.alSincronizzare(function (pid, nota) {
        if (pid === id() && box.hidden && nota !== null) mostra(nota);
        if (window.PROJ_LOOKUP && PROJ_LOOKUP[pid] && nota !== null) PROJ_LOOKUP[pid].note = nota;
    });
    PrNota.svuota();
    PrNota.aggiornaAvviso();
})();

/* -------- le date --------
   Il campo data nativo: sul telefono apre la rotella del sistema, che è quella
   che l'utente sa già usare. Il tasto lo apre e resta invisibile sotto. */
window.prScegliData = function (id, campo, btn) {
    var inp = document.createElement('input');
    inp.type = 'date';
    inp.value = btn.dataset.valore || '';
    inp.style.cssText = 'position:fixed;opacity:0;pointer-events:none;left:-9999px';
    document.body.appendChild(inp);
    inp.addEventListener('change', function () {
        var body = new URLSearchParams();
        body.set('project_id', id);
        body.set('campo', campo);
        body.set('valore', inp.value);
        fetch('progetti.php?ajax=salva_data', {
            method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString()
        }).then(function () { location.reload(); });
    });
    inp.addEventListener('blur', function () { setTimeout(function () { inp.remove(); }, 300); });
    if (inp.showPicker) { try { inp.showPicker(); return; } catch (err) {} }
    inp.style.cssText = 'position:fixed;left:50%;top:50%;transform:translate(-50%,-50%);z-index:99999';
    inp.focus();
    inp.click();
};
</script>

</body>
</html>