<?php
/* =====================================================================
   STANDOUT — API DEL TRACKER (i blocchi di un progetto)

     tracker_api.php?ajax=list&project=ID   (GET)  i blocchi del progetto, nell'ordine
     tracker_api.php?ajax=add               (POST) un blocco nuovo in fondo
     tracker_api.php?ajax=save              (POST) titolo, config e/o data di un blocco
     tracker_api.php?ajax=remove            (POST) toglie un blocco
     tracker_api.php?ajax=move              (POST) sposta un blocco su o giu' di un posto
     tracker_api.php?ajax=ai_table          (POST) {text, request?}: l'IA ricava una tabella dal testo incollato (non salva)

   Il sito e il telefono scrivono la stessa tabella (tracker_blocks): qui si controllano solo forma e
   limiti, il significato di config e data lo conoscono i due client (vedi lib/sync_tracker.php).
   Ogni riga e' sempre ristretta all'utente collegato.
   ===================================================================== */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/security_core.php';
require_once __DIR__ . '/../../lib/auth_check_api.php';

$uid = (int)$utente_corrente['id'];

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    sec_guard_write($uid, 'tracker', 0, 600, 60);
}
$ajax = isset($_GET['ajax']) ? sec_str($_GET['ajax'], 20) : '';
sec_solo_post_se_scrive($ajax, ['list']);

require_once __DIR__ . '/../../lib/tracker_ai.php';

const TK_KINDS = ['table', 'counter', 'progress', 'chart', 'checklist', 'note', 'review', 'connection'];
const TK_MAX_BLOCCHI = 20;
const TK_MAX_BYTE = 900000;   // il telefono rifiuta oltre 1 MB di JSON per blocco
const TK_CIFRE = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

/** Il progetto e' dell'utente? Ritorna l'id o chiude con un errore. */
function tk_progetto(int $uid, $v): int {
    $id = sec_int($v, 1, PHP_INT_MAX, 0);
    if ($id <= 0 || !sec_one("SELECT id FROM projects WHERE id = ? AND user_id = ?", [$id, $uid], 'ii')) {
        sec_die_json(t('piano.api_azione_sconosciuta'), 404, 'not_found');
    }
    return $id;
}

/** Un blocco dell'utente (con il suo progetto) o errore. */
function tk_blocco(int $uid, $v): array {
    $id = sec_int($v, 1, PHP_INT_MAX, 0);
    $r = $id > 0 ? sec_one("SELECT id, uuid, project_id, kind, sort_key FROM tracker_blocks WHERE id = ? AND user_id = ?", [$id, $uid], 'ii') : null;
    if (!$r) sec_die_json(t('piano.api_azione_sconosciuta'), 404, 'not_found');
    return $r;
}

/** config / data: un oggetto JSON (non un elenco), come testo, entro il limite. Ritorna il testo o null se manca. */
function tk_json($v): ?string {
    if ($v === null) return null;
    $txt = is_string($v) ? $v : json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($txt) || strlen($txt) > TK_MAX_BYTE) sec_die_json(t('piano.api_azione_sconosciuta'), 400, 'bad_request');
    $d = json_decode($txt, true);
    if (!is_array($d) || json_last_error() !== JSON_ERROR_NONE || ($d !== [] && array_is_list($d))) {
        sec_die_json(t('piano.api_azione_sconosciuta'), 400, 'bad_request');
    }
    return $d === [] ? '{}' : $txt;
}

/** La chiave che viene subito dopo $ultima nell'ordine «frazionario» (la stessa famiglia di fractional-indexing che usa l'app). */
function tk_chiave_dopo(?string $ultima): string {
    if ($ultima === null || $ultima === '' || !preg_match('/^[a-z][0-9A-Za-z]*$/', $ultima)) return 'a0';
    $testa = $ultima[0];
    $len = ord($testa) - ord('a') + 2;                  // 'a' = 2 caratteri, 'b' = 3 ...
    $intera = substr($ultima, 0, $len);
    if (strlen($intera) < $len) return 'a0';
    $cifre = substr($intera, 1);
    $i = strlen($cifre) - 1; $riporto = true;
    while ($riporto && $i >= 0) {
        $d = strpos(TK_CIFRE, $cifre[$i]) + 1;
        if ($d === 62) { $cifre[$i] = '0'; $i--; } else { $cifre[$i] = TK_CIFRE[$d]; $riporto = false; }
    }
    if (!$riporto) return $testa . $cifre;
    if ($testa === 'z') return $intera . 'V';           // fuori scala: tra due chiavi lunghe basta una cifra in piu'
    $nuova = chr(ord($testa) + 1);
    return $nuova . $cifre . '0';
}

function tk_riga(array $r): array {
    return [
        'id' => (int)$r['id'], 'uuid' => $r['uuid'], 'kind' => $r['kind'], 'title' => $r['title'],
        'config' => json_decode((string)$r['config'], true) ?: new stdClass(),
        'data' => json_decode((string)$r['data'], true) ?: new stdClass(),
        'sort_key' => $r['sort_key'],
    ];
}

if ($ajax === 'list') {
    $pid = tk_progetto($uid, $_GET['project'] ?? 0);
    $righe = sec_all("SELECT id, uuid, kind, title, config, data, sort_key FROM tracker_blocks
                      WHERE user_id = ? AND project_id = ? ORDER BY sort_key, created_at, id", [$uid, $pid], 'ii');
    sec_json(['ok' => true, 'blocks' => array_map('tk_riga', $righe), 'max' => TK_MAX_BLOCCHI]);
}

if ($ajax === 'add') {
    $pid = tk_progetto($uid, sec_in('project'));
    $kind = sec_enum(sec_in('kind'), TK_KINDS, '');
    if ($kind === '') sec_die_json(t('piano.api_azione_sconosciuta'), 400, 'bad_request');
    $titolo = mb_substr(trim(sec_str(sec_in('title', ''), 120)), 0, 120);
    $config = tk_json(sec_in('config')) ?? '{}';
    $data = tk_json(sec_in('data')) ?? '{}';
    $n = (int)(sec_one("SELECT COUNT(*) AS n FROM tracker_blocks WHERE user_id = ? AND project_id = ?", [$uid, $pid], 'ii')['n'] ?? 0);
    if ($n >= TK_MAX_BLOCCHI) sec_die_json(t('tracker.full', ['n' => TK_MAX_BLOCCHI]), 409, 'limit');
    if ($kind === 'connection' && sec_one("SELECT 1 AS x FROM tracker_blocks WHERE user_id = ? AND project_id = ? AND kind = 'connection'", [$uid, $pid], 'ii')) {
        sec_die_json(t('tracker.full', ['n' => TK_MAX_BLOCCHI]), 409, 'limit');
    }
    $ultima = sec_one("SELECT sort_key FROM tracker_blocks WHERE user_id = ? AND project_id = ? AND sort_key IS NOT NULL ORDER BY sort_key DESC LIMIT 1", [$uid, $pid], 'ii');
    $chiave = tk_chiave_dopo($ultima['sort_key'] ?? null);
    sec_q("INSERT INTO tracker_blocks (user_id, project_id, kind, title, config, data, sort_key) VALUES (?,?,?,?,?,?,?)",
          [$uid, $pid, $kind, $titolo, $config, $data, $chiave], 'iisssss');
    $nuovo = sec_one("SELECT id, uuid, kind, title, config, data, sort_key FROM tracker_blocks WHERE user_id = ? AND project_id = ? AND sort_key = ? ORDER BY id DESC LIMIT 1", [$uid, $pid, $chiave], 'iis');
    sec_json(['ok' => true, 'block' => $nuovo ? tk_riga($nuovo) : null]);
}

if ($ajax === 'ai_table') {
    sec_require_rate('tracker_ai', $uid, 20, 3600);
    $r = tkai_tabella($uid, (string)sec_in('text', ''), (string)sec_in('request', ''));
    if (!$r['ok']) sec_die_json($r['message'], (int)$r['http'], $r['code']);
    sec_json($r);
}

if ($ajax === 'save') {
    $b = tk_blocco($uid, sec_in('id'));
    $id = (int)$b['id'];
    $titolo = sec_in('title');
    $config = tk_json(sec_in('config'));
    $data = tk_json(sec_in('data'));
    if ($titolo !== null) sec_q("UPDATE tracker_blocks SET title = ? WHERE id = ? AND user_id = ?", [mb_substr(trim(sec_str($titolo, 120)), 0, 120), $id, $uid], 'sii');
    if ($config !== null) sec_q("UPDATE tracker_blocks SET config = ? WHERE id = ? AND user_id = ?", [$config, $id, $uid], 'sii');
    if ($data !== null)   sec_q("UPDATE tracker_blocks SET data = ? WHERE id = ? AND user_id = ?", [$data, $id, $uid], 'sii');
    sec_json(['ok' => true]);
}

if ($ajax === 'remove') {
    $b = tk_blocco($uid, sec_in('id'));
    sec_q("DELETE FROM tracker_blocks WHERE id = ? AND user_id = ?", [(int)$b['id'], $uid], 'ii');
    sec_json(['ok' => true]);
}

if ($ajax === 'move') {
    $b = tk_blocco($uid, sec_in('id'));
    $su = sec_int(sec_in('dir'), -1, 1, 1) < 0;
    $lista = sec_all("SELECT id, sort_key FROM tracker_blocks WHERE user_id = ? AND project_id = ? ORDER BY sort_key, created_at, id", [$uid, (int)$b['project_id']], 'ii');
    $i = -1;
    foreach ($lista as $k => $r) if ((int)$r['id'] === (int)$b['id']) { $i = $k; break; }
    $j = $su ? $i - 1 : $i + 1;
    if ($i < 0 || $j < 0 || $j >= count($lista)) sec_json(['ok' => true]);
    $a = $lista[$i]; $c = $lista[$j];
    if ($a['sort_key'] === null || $c['sort_key'] === null || $a['sort_key'] === $c['sort_key']) {
        // chiavi mancanti o uguali: si rinumera tutta la fila (a0, a1, ...)
        $ordine = array_column($lista, 'id');
        [$ordine[$i], $ordine[$j]] = [$ordine[$j], $ordine[$i]];
        $k = null;
        foreach ($ordine as $x) { $k = tk_chiave_dopo($k); sec_q("UPDATE tracker_blocks SET sort_key = ? WHERE id = ? AND user_id = ?", [$k, (int)$x, $uid], 'sii'); }
    } else {
        sec_q("UPDATE tracker_blocks SET sort_key = ? WHERE id = ? AND user_id = ?", [$c['sort_key'], (int)$a['id'], $uid], 'sii');
        sec_q("UPDATE tracker_blocks SET sort_key = ? WHERE id = ? AND user_id = ?", [$a['sort_key'], (int)$c['id'], $uid], 'sii');
    }
    sec_json(['ok' => true]);
}

sec_die_json(t('piano.api_azione_sconosciuta'), 400, 'bad_request');
