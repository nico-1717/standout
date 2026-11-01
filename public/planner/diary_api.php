<?php
/* =====================================================================
   STANDOUT — API DEL DIARIO
   Un solo punto di scrittura per: popup "diario del giorno" (habits e
   planner) e pagina diario.php. Dove si compila non importa: umore,
   energia e testo stanno in habit_daily_journal, gli elenchi in
   diary_list_items. Tutto passa da security_core.php.

   Chiamate:  diary_api.php?ajax=day&date=YYYY-MM-DD          (GET)
              diary_api.php?ajax=month&month=YYYY-MM          (GET)
              diary_api.php?ajax=insights&year=YYYY           (GET)  il diario in numeri
              diary_api.php?ajax=save_text  ...               (POST)
              diary_api.php?ajax=save_mood  ...               (POST)
              diary_api.php?ajax=list_save  ...               (POST)
              diary_api.php?ajax=list_delete ...              (POST)
              diary_api.php?ajax=settings_save ...            (POST)
   ===================================================================== */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/security_core.php';
require_once __DIR__ . '/../../lib/auth_check_api.php';

$uid = (int)$utente_corrente['id'];
date_default_timezone_set('Europe/Rome');

$ajax = isset($_GET['ajax']) ? sec_str($_GET['ajax'], 30) : '';
if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    sec_guard_write($uid, 'diary', 0, 400, 60);
}
/* Solo le letture possono arrivare con GET: il resto è POST, dove il token CSRF
   viene davvero controllato (vedi sec_solo_post_se_scrive in security_core.php). */
sec_solo_post_se_scrive($ajax, ['day', 'insights', 'month']);

/* Impostazioni, giorno, anelli e progressi (dg_*) e le letture «mese» e «numeri» stanno in
   lib/diary_core.php: le usa anche l'app (/api/v1/diary). */
require_once __DIR__ . '/../../lib/diary_core.php';

/* ===================== ENDPOINT ===================== */

if ($ajax === 'day') {
    $date = sec_date(sec_in('date'), date('Y-m-d'));
    sec_json(['ok' => true, 'day' => dg_day($uid, $date), 'settings' => dg_settings($uid)]);
}

if ($ajax === 'save_text') {
    $date = sec_date(sec_in('date'), date('Y-m-d'));
    $note = sec_text(sec_in('note'), 20000);
    sec_q("INSERT INTO habit_daily_journal (user_id, entry_date, note) VALUES (?,?,?)
           ON DUPLICATE KEY UPDATE note = VALUES(note)", [$uid, $date, $note], 'iss');
    sec_json(['ok' => true]);
}

if ($ajax === 'save_mood') {
    $date   = sec_date(sec_in('date'), date('Y-m-d'));
    $which  = sec_enum(sec_in('which'), ['mood', 'energy'], 'mood');
    $raw    = sec_in('value');
    $value  = ($raw === null || $raw === '' || $raw === 0) ? null : sec_int($raw, 1, 5, 3);
    $column = $which === 'mood' ? 'mood' : 'energy';
    sec_q("INSERT INTO habit_daily_journal (user_id, entry_date, `$column`) VALUES (?,?,?)
           ON DUPLICATE KEY UPDATE `$column` = VALUES(`$column`)", [$uid, $date, $value]);
    sec_json(['ok' => true, 'which' => $which, 'value' => $value]);
}

if ($ajax === 'list_save') {
    $date   = sec_date(sec_in('date'), date('Y-m-d'));
    $type   = sec_enum(sec_in('list_type'), ['good', 'learned'], 'good');
    $itemId = sec_int(sec_in('item_id'), 0, PHP_INT_MAX, 0);
    $text   = sec_str(sec_in('content'), 300);

    if ($itemId > 0) {
        if (!sec_owns('diary_list_items', $itemId, $uid)) sec_die_json(t('api.voce_non_trovata'), 404, 'not_found');
        if ($text === '') {
            sec_q("DELETE FROM diary_list_items WHERE id = ? AND user_id = ?", [$itemId, $uid], 'ii');
            sec_json(['ok' => true, 'deleted' => true, 'id' => $itemId]);
        }
        sec_q("UPDATE diary_list_items SET content = ? WHERE id = ? AND user_id = ?", [$text, $itemId, $uid], 'sii');
        sec_json(['ok' => true, 'id' => $itemId]);
    }
    if ($text === '') sec_json(['ok' => true, 'id' => 0]);

    $next = sec_one("SELECT COALESCE(MAX(sort_order), 0) + 1 AS n FROM diary_list_items
                     WHERE user_id = ? AND entry_date = ? AND list_type = ?", [$uid, $date, $type], 'iss');
    sec_q("INSERT INTO diary_list_items (user_id, entry_date, list_type, content, sort_order) VALUES (?,?,?,?,?)",
          [$uid, $date, $type, $text, (int)($next['n'] ?? 1)], 'isssi');
    sec_json(['ok' => true, 'id' => sec_insert_id()]);
}

if ($ajax === 'list_delete') {
    $itemId = sec_int(sec_in('item_id'), 1, PHP_INT_MAX, 0);
    if (!sec_owns('diary_list_items', $itemId, $uid)) sec_die_json(t('api.voce_non_trovata'), 404, 'not_found');
    sec_q("DELETE FROM diary_list_items WHERE id = ? AND user_id = ?", [$itemId, $uid], 'ii');
    sec_json(['ok' => true]);
}

if ($ajax === 'settings_save') {
    $fields = []; $params = [];
    $font = sec_in('font');
    if ($font !== null) {
        $fields[] = 'font_key = ?';
        $params[] = sec_enum($font, ['caveat', 'kalam', 'patrick', 'cormorant', 'jost',
                                       /* scelte dallo stile standard (diario.js, fontEff) */
                                       'sistema', 'std-caveat', 'std-kalam', 'std-patrick', 'std-cormorant', 'std-jost'], 'caveat');
    }
    $size = sec_in('size');
    if ($size !== null) { $fields[] = 'font_size = ?'; $params[] = sec_int($size, 14, 26, 18); }
    $paper = sec_in('paper');
    if ($paper !== null) { $fields[] = 'paper_key = ?'; $params[] = sec_enum($paper, ['lined', 'dotted', 'plain'], 'lined'); }
    if (!empty($fields)) {
        sec_q("INSERT IGNORE INTO diary_settings (user_id) VALUES (?)", [$uid], 'i');
        $params[] = $uid;
        sec_q("UPDATE diary_settings SET " . implode(', ', $fields) . " WHERE user_id = ?", $params);
    }
    sec_json(['ok' => true, 'settings' => dg_settings($uid)]);
}

/* --------------------------------------------------------------------- */
/* Il diario in numeri: un anno giorno per giorno, gli anni a confronto, */
/* i trimestri con il loro resoconto e il libro (diario_anno.php)        */
/* --------------------------------------------------------------------- */
if ($ajax === 'insights') {
    $anno = (int)sec_in('year');
    if ($anno < 2000 || $anno > 2100) $anno = (int)date('Y');
    sec_json(['ok' => true] + dg_numeri($uid, $anno));
}

/* --------------------------------------------------------------------- */
/* Un mese: i giorni scritti, le medie, tutto quello che ho imparato     */
/* --------------------------------------------------------------------- */
if ($ajax === 'month') {
    $month = sec_str(sec_in('month'), 7);
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) $month = date('Y-m');
    sec_json(['ok' => true] + dg_mese($uid, $month));
}

sec_die_json(t('api.richiesta_non_riconosciuta'), 404, 'unknown_action');
