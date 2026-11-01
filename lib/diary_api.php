<?php
/* =====================================================================
   STANDOUT — IL DIARIO per l'API /api/v1/diary (PROGETTO §15, passo 9, fetta 7)

   Come lib/habits_api.php: qui c'e' solo il controllo dei tipi e le scritture
   «a campi» (PATCH). Le letture (giorno, anelli, progressi, mese, numeri) sono le
   funzioni della pagina in lib/diary_core.php, quindi dicono le stesse cose.

   Il diario del giorno sta in habit_daily_journal (umore, energia, testo) e nei
   due elenchi diary_list_items («cose belle» e «cose imparate»).

   Differenze dalla pagina, voluta: la pagina corregge in silenzio (un umore 9 diventa 5,
   un tipo di elenco sconosciuto diventa «good»); l'app riceve un errore col nome del
   campo (422). Un testo vuoto in un elenco e' un errore: toglierlo ha il suo DELETE.
   Errori: ['errore' => ['codice', 'campo', 'testo']].
   Caricato da public/api/v1/index.php.
   ===================================================================== */
require_once __DIR__ . '/diary_core.php';

const DG_FONT = ['caveat', 'kalam', 'patrick', 'cormorant', 'jost', 'sistema', 'std-caveat', 'std-kalam', 'std-patrick', 'std-cormorant', 'std-jost'];
const DG_CARTA = ['lined', 'dotted', 'plain'];
const DG_VOCI_MAX = 100;   // per giorno e per elenco: la pagina non ha un tetto, l'app non deve poter riempire la tabella

function dg_api_errore(string $codice, string $campo, string $testo): array {
    return ['errore' => ['codice' => $codice, 'campo' => $campo, 'testo' => $testo]];
}

/** La data nel corpo o nell'indirizzo: AAAA-MM-GG valida; assente = oggi. Ritorna [data, errore]. */
function dg_api_data($v, bool $presente): array {
    if (!$presente || $v === null) return [date('Y-m-d'), null];
    $d = is_string($v) ? sec_date($v, null) : null;
    return $d === null ? [null, dg_api_errore('validation', 'date', 'date must be YYYY-MM-DD')] : [$d, null];
}

/** Il giorno e le impostazioni (come la pagina). */
function dg_api_giorno(int $uid, ?string $data): array {
    [$d, $e] = dg_api_data($data, $data !== null);
    if ($e) return $e;
    return ['day' => dg_day($uid, $d), 'settings' => dg_settings($uid)];
}

/**
 * Cambia SOLO i campi nominati del giorno: note (testo, null = svuota), mood e energy (1-5, null = toglie).
 * Ritorna ['day' => ...] oppure un errore.
 */
function dg_api_giorno_salva(int $uid, array $c): array {
    [$data, $e] = dg_api_data($c['date'] ?? null, array_key_exists('date', $c));
    if ($e) return $e;
    $col = []; $val = [];
    if (array_key_exists('note', $c)) {
        $n = $c['note'];
        if ($n === null) $n = '';
        if (!is_string($n)) return dg_api_errore('validation', 'note', 'note must be text or null');
        if (mb_strlen($n) > 20000) return dg_api_errore('validation', 'note', 'note can be at most 20000 characters');
        $col['note'] = sec_text($n, 20000);
    }
    foreach (['mood', 'energy'] as $k) {
        if (!array_key_exists($k, $c)) continue;
        $v = $c[$k];
        if ($v !== null && (!is_int($v) || $v < 1 || $v > 5)) return dg_api_errore('validation', $k, "$k must be a number from 1 to 5, or null");
        $col[$k] = $v;
    }
    if (!$col) return dg_api_errore('validation', 'body', 'Send note, mood and/or energy');
    /* I nomi delle colonne vengono SOLO da questo elenco fisso (note, mood, energy), mai dal client. */
    $nomi = array_keys($col);
    sec_q("INSERT INTO habit_daily_journal (user_id, entry_date, " . implode(', ', array_map(fn($k) => "`$k`", $nomi)) . ")
           VALUES (?, ?, " . implode(', ', array_fill(0, count($nomi), '?')) . ")
           ON DUPLICATE KEY UPDATE " . implode(', ', array_map(fn($k) => "`$k` = VALUES(`$k`)", $nomi)),
          array_merge([$uid, $data], array_values($col)));
    return ['day' => dg_day($uid, $data)];
}

function dg_api_mese(int $uid, ?string $mese): array {
    if ($mese === null) $mese = date('Y-m');
    if (!preg_match('/^[0-9]{4}-(0[1-9]|1[0-2])$/', $mese)) return dg_api_errore('validation', 'month', 'month must be YYYY-MM');
    return dg_mese($uid, $mese);
}

function dg_api_numeri(int $uid, ?string $anno): array {
    if ($anno === null) $anno = date('Y');
    if (!preg_match('/^[0-9]{4}$/', $anno) || (int)$anno < 2000 || (int)$anno > 2100) return dg_api_errore('validation', 'year', 'year must be a year from 2000 to 2100');
    return dg_numeri($uid, (int)$anno);
}

/** Una voce degli elenchi come la vede l'app, o null. */
function dg_api_voce(int $uid, int $id): ?array {
    $r = sec_one("SELECT id, entry_date, list_type, content, sort_order FROM diary_list_items WHERE id = ? AND user_id = ?", [$id, $uid], 'ii');
    return $r ? ['id' => (int)$r['id'], 'date' => $r['entry_date'], 'list_type' => $r['list_type'], 'content' => $r['content'], 'order' => (int)$r['sort_order']] : null;
}

function dg_api_voce_testo($v): array {
    if (!is_string($v)) return [null, dg_api_errore('validation', 'content', 'content must be text')];
    $t = trim($v);
    if ($t === '') return [null, dg_api_errore('validation', 'content', 'content cannot be empty')];
    if (mb_strlen($t) > 300) return [null, dg_api_errore('validation', 'content', 'content can be at most 300 characters')];
    return [sec_str($t, 300), null];
}

/** Aggiunge una voce a «good» o «learned» di un giorno. Ritorna ['item' => ...] oppure un errore. */
function dg_api_voce_crea(int $uid, array $c): array {
    [$data, $e] = dg_api_data($c['date'] ?? null, array_key_exists('date', $c));
    if ($e) return $e;
    $tipo = $c['list_type'] ?? null;
    if (!is_string($tipo) || !in_array($tipo, ['good', 'learned'], true)) return dg_api_errore('validation', 'list_type', 'list_type must be good or learned');
    [$testo, $e] = dg_api_voce_testo($c['content'] ?? null);
    if ($e) return $e;
    $n = sec_one("SELECT COUNT(*) AS n, COALESCE(MAX(sort_order), 0) + 1 AS prossimo FROM diary_list_items
                  WHERE user_id = ? AND entry_date = ? AND list_type = ?", [$uid, $data, $tipo], 'iss');
    if ((int)($n['n'] ?? 0) >= DG_VOCI_MAX) return dg_api_errore('validation', 'content', 'Too many items for this day and list (at most ' . DG_VOCI_MAX . ')');
    sec_q("INSERT INTO diary_list_items (user_id, entry_date, list_type, content, sort_order) VALUES (?,?,?,?,?)",
          [$uid, $data, $tipo, $testo, (int)($n['prossimo'] ?? 1)], 'isssi');
    return ['item' => dg_api_voce($uid, sec_insert_id())];
}

function dg_api_voce_modifica(int $uid, int $id, array $c): array {
    if (!dg_api_voce($uid, $id)) return dg_api_errore('not_found', 'id', 'Item not found');
    if (!array_key_exists('content', $c)) return dg_api_errore('validation', 'body', 'Send content');
    [$testo, $e] = dg_api_voce_testo($c['content']);
    if ($e) return $e;
    sec_q("UPDATE diary_list_items SET content = ? WHERE id = ? AND user_id = ?", [$testo, $id, $uid], 'sii');
    return ['item' => dg_api_voce($uid, $id)];
}

function dg_api_voce_elimina(int $uid, int $id): bool {
    if (!dg_api_voce($uid, $id)) return false;
    sec_q("DELETE FROM diary_list_items WHERE id = ? AND user_id = ?", [$id, $uid], 'ii');
    return true;
}

/** Carattere, grandezza e carta della pagina diario. Cambia SOLO le chiavi nominate. */
function dg_api_impostazioni_salva(int $uid, array $c): array {
    $campi = [];
    if (array_key_exists('font', $c)) {
        if (!is_string($c['font']) || !in_array($c['font'], DG_FONT, true)) return dg_api_errore('validation', 'font', 'font must be one of: ' . implode(', ', DG_FONT));
        $campi['font_key'] = $c['font'];
    }
    if (array_key_exists('size', $c)) {
        if (!is_int($c['size']) || $c['size'] < 14 || $c['size'] > 26) return dg_api_errore('validation', 'size', 'size must be a number from 14 to 26');
        $campi['font_size'] = $c['size'];
    }
    if (array_key_exists('paper', $c)) {
        if (!is_string($c['paper']) || !in_array($c['paper'], DG_CARTA, true)) return dg_api_errore('validation', 'paper', 'paper must be one of: ' . implode(', ', DG_CARTA));
        $campi['paper_key'] = $c['paper'];
    }
    if (!$campi) return dg_api_errore('validation', 'body', 'Send font, size and/or paper');
    sec_q("INSERT IGNORE INTO diary_settings (user_id) VALUES (?)", [$uid], 'i');
    /* I nomi delle colonne vengono SOLO da questo elenco fisso, mai dal client. */
    $set = []; $par = [];
    foreach ($campi as $col => $val) { $set[] = "`$col` = ?"; $par[] = $val; }
    $par[] = $uid;
    sec_q("UPDATE diary_settings SET " . implode(', ', $set) . " WHERE user_id = ?", $par);
    return ['settings' => dg_settings($uid)];
}
