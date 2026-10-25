<?php
/* =====================================================================
   STANDOUT — LA NOTA VELOCE: SMISTAMENTO CON L'IA
   Solo POST, risponde sempre in JSON.

     {body}  ->  { ok, items: [...], crediti }

   Legge la nota (una riga per appunto) e propone dove va ogni voce: to do,
   to buy, evento del planner, nuovo progetto. NON salva niente:
   restituisce una proposta che l'utente controlla, corregge e conferma nella
   schermata di conferma. Il salvataggio vero e' «nota_applica» in
   todo_api.php.

   Due regole che tengono la proposta onesta:
   - ogni riga della nota finisce in almeno una voce. Quelle che l'IA salta
     tornano come «to do» da controllare: meglio una voce da correggere che
     un appunto sparito;
   - progetti e categorie si riconoscono SOLO fra quelli che l'utente ha gia'.
     L'IA non inventa id: ne' ne riceve, ne' ne restituisce. Riceve i nomi, e
     qui si riconoscono i nomi.

   I crediti si addebitano a lavoro consegnato, come per il diario.
   ===================================================================== */
require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/security_core.php';
require_once __DIR__ . '/../../lib/auth_check_api.php';
require_once __DIR__ . '/../../lib/fc/fc_ai_lib.php';   // Gemini, budget, crediti, tempo

$uid = (int)($utente_corrente['id'] ?? 0);
if ($uid <= 0) sec_die_json(t('api.sessione_non_valida'), 401, 'auth');
date_default_timezone_set('Europe/Rome');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') sec_json(['ok' => false, 'error' => 'metodo'], 405);
sec_guard_write($uid, 'todo_nota_ai', 0, 20, 60);
fc_ai_scadenza_imposta();

const TNA_RIGHE_MAX = 80;
const TNA_DEST = ['todo', 'buy', 'event', 'project'];

/* le righe della nota: niente vuote, puntini e numeri davanti tolti, corte */
$grezzo = sec_text(sec_in('body'), 20000);
$righe = [];
foreach (preg_split('/\n/u', $grezzo) as $r) {
    $r = trim(preg_replace('/^[\s\-•*·]+|^\d{1,2}[.)]\s+/u', '', $r));
    if ($r === '') continue;
    $righe[] = mb_substr($r, 0, 300);
    if (count($righe) >= TNA_RIGHE_MAX) break;
}
if (!$righe) sec_json(['ok' => false, 'error' => 'nota_vuota'], 400);

/* quello che l'utente ha gia': i nomi con cui l'IA puo' agganciare le voci */
$progetti = array_map(function ($r) { return (string)$r['name']; },
    sec_all("SELECT id, name FROM projects WHERE user_id = ? AND study_event_id IS NULL ORDER BY is_favorite DESC, updated_at DESC, id DESC LIMIT 60", [$uid], 'i'));
$categorie = array_map(function ($r) { return (string)$r['name']; },
    sec_all("SELECT name FROM todo_buy_categories WHERE user_id = ? ORDER BY sort_order, id", [$uid], 'i'));

$lingua = function_exists('standout_lingua') && standout_lingua() === 'en' ? 'English' : 'Italian';
$oggi = date('Y-m-d');
$giorno = date('l');

$sistema = "You sort a person's quick note into the right lists of their planner app. The note has one jotting per line (numbered). "
    . "Today is $giorno $oggi (Europe/Rome). The note may be in Italian, English or a mix: understand both. "
    . "For EACH jotting decide where it goes (field dest): "
    . "todo = something to do (an errand, a task, a call, a reminder); "
    . "buy = something to purchase; "
    . "event = something happening at a time with other people or a place (appointment, meeting, dinner, exam, trip) that belongs in the calendar; "
    . "project = a bigger goal that needs several steps and a name, NOT a single task. "
    . "Rules: keep the person's own words in title (fix typos, drop the date/time/price/priority words you moved into other fields, capitalise the first letter), never invent tasks or details. "
    . "If one line holds several separate items (a list of things to buy, say), split it into several items, all with the same src. "
    . "date = YYYY-MM-DD resolved from words like tomorrow, Friday, 3 November, next week (empty if none; for event with no date use empty); time = HH:MM 24h (empty if none or all-day); minutes = expected length if stated (0 if not). "
    . "project = the name of ONE of the existing projects below if the jotting clearly belongs to it (empty otherwise). Existing projects: "
    . ($progetti ? json_encode($progetti, JSON_UNESCAPED_UNICODE) : '[]') . ". "
    . "category (buy only) = the best fitting existing category: " . ($categorie ? json_encode($categorie, JSON_UNESCAPED_UNICODE) : '[]')
    . "; if none fits, a short new category name in $lingua; empty if unsure. "
    . "price = number in euros if stated (0 if not). priority (buy only) = 1 urgent, 2 normal, 3 low, default 3. "
    . "src = the line numbers the item comes from. Titles in the language of the jotting. Output only the JSON.";

$utente = '';
foreach ($righe as $n => $r) $utente .= ($n + 1) . ': ' . $r . "\n";

$stima = fc_ai_stima(mb_strlen($sistema . $utente) + 600, 350 + (int)(mb_strlen($utente) / 2));
$budget = fc_ai_controlla_budget($uid, $stima);
if (!$budget['ok']) sec_json(['ok' => false, 'error' => 'limite_piano', 'stima' => $budget['stima'], 'disponibili' => $budget['disponibili']]);

$s = ['type' => 'STRING'];
$schema = ['type' => 'OBJECT', 'properties' => [
    'items' => ['type' => 'ARRAY', 'items' => ['type' => 'OBJECT', 'properties' => [
        'src' => ['type' => 'ARRAY', 'items' => ['type' => 'INTEGER']],
        'dest' => $s, 'title' => $s, 'date' => $s, 'time' => $s,
        'minutes' => ['type' => 'INTEGER'], 'project' => $s, 'category' => $s,
        'price' => ['type' => 'NUMBER'], 'priority' => ['type' => 'INTEGER'],
    ], 'required' => ['src', 'dest', 'title', 'date', 'time', 'minutes', 'project', 'category', 'price', 'priority']]],
], 'required' => ['items']];

$token = fc_ai_token_nuovo();
$r = fc_ai_gemini_ritenta($sistema, $utente, $schema, 0, $token, 'todo_nota', 0.2);
if (!$r['ok']) sec_json(['ok' => false, 'error' => 'ia_' . $r['errore'], 'riprovabile' => fc_ai_riprovabile('ia_' . $r['errore'])], 502);

/* ---- controllo della proposta: niente di quello che arriva si da' per buono ---- */
$idProgetti = [];
foreach (sec_all("SELECT id, name FROM projects WHERE user_id = ? AND study_event_id IS NULL", [$uid], 'i') as $p) {
    $idProgetti[mb_strtolower(trim((string)$p['name']))] = (int)$p['id'];
}
$idCategorie = [];
foreach (sec_all("SELECT id, name FROM todo_buy_categories WHERE user_id = ?", [$uid], 'i') as $c) {
    $idCategorie[mb_strtolower(trim((string)$c['name']))] = (int)$c['id'];
}

$items = [];
$coperte = [];
foreach ((array)($r['dati']['items'] ?? []) as $it) {
    if (!is_array($it) || count($items) >= 120) continue;
    $dest = fc_ai_enum($it['dest'] ?? '', TNA_DEST, 'todo');
    $titolo = fc_ai_riga($it['title'] ?? '', $dest === 'event' ? 180 : 300);
    if ($titolo === '') continue;

    $src = [];
    foreach ((array)($it['src'] ?? []) as $n) {
        $n = (int)$n;
        if ($n >= 1 && $n <= count($righe)) { $src[] = $n; $coperte[$n] = true; }
    }
    $src = array_values(array_unique($src));

    $data = sec_date($it['date'] ?? '', null);
    $ora  = !empty($it['time']) ? sec_time($it['time'], null) : null;
    $nomeProg = mb_strtolower(trim((string)($it['project'] ?? '')));
    $nomeCat  = fc_ai_riga($it['category'] ?? '', 60);
    $catId = $nomeCat !== '' ? ($idCategorie[mb_strtolower($nomeCat)] ?? 0) : 0;
    $prezzo = (float)($it['price'] ?? 0);

    $items[] = [
        'src' => $src,
        'dest' => $dest,
        'title' => $titolo,
        'date' => $data,
        'time' => $ora !== null ? substr($ora, 0, 5) : null,
        'minutes' => sec_int($it['minutes'] ?? 0, 0, 1440, 0),
        'project_id' => $dest === 'project' ? 0 : ($idProgetti[$nomeProg] ?? 0),
        'cat_id' => $dest === 'buy' ? $catId : 0,
        /* una categoria che non esiste ancora si propone col suo nome: la
           crea il salvataggio, e solo se l'utente conferma */
        'cat_name' => ($dest === 'buy' && !$catId) ? $nomeCat : '',
        'price' => ($dest === 'buy' && $prezzo > 0 && $prezzo < 1000000) ? number_format($prezzo, 2, '.', '') : '',
        'priority' => sec_int($it['priority'] ?? 3, 1, 3, 3),
    ];
}

/* le righe che l'IA ha saltato tornano come attivita' da controllare */
foreach ($righe as $n => $testo) {
    if (!empty($coperte[$n + 1])) continue;
    $items[] = ['src' => [$n + 1], 'dest' => 'todo', 'title' => fc_ai_riga($testo, 300), 'date' => null, 'time' => null,
                'minutes' => 0, 'project_id' => 0, 'cat_id' => 0, 'cat_name' => '', 'price' => '', 'priority' => 3,
                'da_controllare' => true];
}

$crediti = ai_crediti_da_token($token['utili'], FC_AI_MODELLO);
ai_crediti_addebita($uid, $crediti, ['model' => FC_AI_MODELLO, 'provider_tokens' => $token['totali'], 'note' => 'todo:nota']);

sec_json(['ok' => true, 'items' => $items, 'righe' => $righe, 'crediti' => $crediti]);
