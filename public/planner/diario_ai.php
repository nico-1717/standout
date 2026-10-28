<?php
/* =====================================================================
   STANDOUT — IL DIARIO E L'IA: RESOCONTO DEL TRIMESTRE E LIBRO
   Risponde sempre in JSON, solo POST.

     azione=stima          quanto costerebbe (resoconto o libro), senza IA
     azione=resoconto      scrive il resoconto di un trimestre
     azione=libro_piano    il piano del libro: titolo e capitoli (prima chiamata)
     azione=libro_avanti   scrive il pezzo successivo del libro (si chiama
                           finché non risponde fatto=true)

   IL RESOCONTO parla di come è stato il trimestre: come si è sentito chi
   scrive, i momenti alti e bassi, i temi, alcune righe che riassumono i suoi
   pensieri, e le abitudini e i pomodori confrontati con il trimestre prima e
   con gli obiettivi (le volte previste). I numeri li calcola il server
   (lib/planner/diario_numeri.php): all'IA si chiede di commentarli, non di
   contarli.

   IL LIBRO raccoglie solo i pensieri (sulla vita, le relazioni, sé…), non la
   cronaca dei giorni, e li riordina in capitoli che si leggono di fila.
   Niente di aggiunto o di cambiato: le stesse idee, scritte meglio. Si scrive
   a pezzi — un capitolo, o una parte di capitolo, per chiamata — perché un
   libro intero in una chiamata sola supererebbe il tempo massimo del server.
   Il lavoro è salvato a ogni pezzo: se la pagina si chiude, si riprende.
   Si può UNIRE al libro precedente (si sceglie prima): i suoi capitoli
   restano e i pensieri nuovi entrano dove c'entrano, o in capitoli nuovi.

   I crediti si addebitano a lavoro consegnato, come per le flashcard.
   ===================================================================== */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/security_core.php';
require_once __DIR__ . '/../../lib/auth_check_api.php';
require_once __DIR__ . '/../../lib/fc/fc_ai_lib.php';          // Gemini, budget, crediti, tempo
require_once __DIR__ . '/../../lib/planner/diario_numeri.php';

$uid = (int)($utente_corrente['id'] ?? 0);
if ($uid <= 0) sec_die_json(t('api.sessione_non_valida'), 401, 'auth');
date_default_timezone_set('Europe/Rome');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') sec_json(['ok' => false, 'error' => 'metodo'], 405);
sec_guard_write($uid, 'diary_ai', 0, 30, 60);
fc_ai_scadenza_imposta();
if (!dn_tabelle_ai()) sec_json(['ok' => false, 'error' => 'tabelle'], 500);

$in = sec_body() ?: [];
$azione = fc_ai_enum($in['azione'] ?? '', ['stima', 'resoconto', 'libro_piano', 'libro_avanti'], '');
if ($azione === '') sec_json(['ok' => false, 'error' => 'azione_sconosciuta'], 400);

/* la lingua in cui scrivere se il diario ne mescola più d'una */
$linguaUi = function_exists('standout_lingua') && standout_lingua() === 'en' ? 'English' : 'Italian';

/* ------------------------------------------------------------------ */
/* I testi del diario di un periodo                                    */
/* ------------------------------------------------------------------ */
/** Il diario giorno per giorno, per il resoconto: testo, umore, energia, elenchi. */
function da_diario(int $uid, string $da, string $a, int $max = 120000): string {
    $giorni = [];
    foreach (sec_all("SELECT entry_date, mood, energy, note FROM habit_daily_journal
                      WHERE user_id = ? AND entry_date BETWEEN ? AND ? ORDER BY entry_date", [$uid, $da, $a], 'iss') as $r) {
        $giorni[$r['entry_date']] = ['m' => $r['mood'], 'e' => $r['energy'], 't' => trim((string)$r['note']), 'good' => [], 'learned' => []];
    }
    foreach (sec_all("SELECT entry_date, list_type, content FROM diary_list_items
                      WHERE user_id = ? AND entry_date BETWEEN ? AND ? AND content <> '' ORDER BY entry_date, sort_order, id",
                     [$uid, $da, $a], 'iss') as $r) {
        if (!isset($giorni[$r['entry_date']])) $giorni[$r['entry_date']] = ['m' => null, 'e' => null, 't' => '', 'good' => [], 'learned' => []];
        $giorni[$r['entry_date']][$r['list_type'] === 'good' ? 'good' : 'learned'][] = trim($r['content']);
    }
    /* se è troppo lungo, ogni giorno si accorcia nella stessa proporzione:
       meglio un trimestre intero un po' sfoltito che solo i primi due mesi */
    $tot = 0;
    foreach ($giorni as $g) $tot += mb_strlen($g['t']) + 120;
    $quota = $tot > $max ? $max / $tot : 1;
    $out = '';
    foreach ($giorni as $d => $g) {
        $testa = '[' . $d . ($g['m'] !== null ? ' · mood ' . $g['m'] . '/5' : '') . ($g['e'] !== null ? ' · energy ' . $g['e'] . '/5' : '') . ']';
        $t = $g['t'];
        if ($quota < 1 && mb_strlen($t) > 200) $t = mb_substr($t, 0, max(200, (int)(mb_strlen($t) * $quota))) . '…';
        $out .= $testa . "\n" . ($t !== '' ? $t . "\n" : '');
        if ($g['good']) $out .= 'Good things: ' . implode('; ', $g['good']) . "\n";
        if ($g['learned']) $out .= 'Learned: ' . implode('; ', $g['learned']) . "\n";
        $out .= "\n";
    }
    return $out;
}

/** I frammenti per il libro: i paragrafi dei testi e le cose imparate, con un id. */
function da_frammenti(int $uid, string $da, string $a): array {
    $out = []; $n = 0;
    foreach (sec_all("SELECT entry_date, note FROM habit_daily_journal
                      WHERE user_id = ? AND entry_date BETWEEN ? AND ? AND note IS NOT NULL AND note <> '' ORDER BY entry_date",
                     [$uid, $da, $a], 'iss') as $r) {
        $testo = trim((string)$r['note']);
        /* i testi lunghi si dividono nei loro paragrafi: un giorno può
           contenere pensieri che vanno in capitoli diversi */
        $pezzi = mb_strlen($testo) > 700 ? preg_split('/\n\s*\n|\n(?=\S)/u', $testo) : [$testo];
        foreach ($pezzi as $p) {
            $p = trim($p);
            if (mb_strlen($p) < 25) continue;
            $out['f' . (++$n)] = ['d' => $r['entry_date'], 't' => $p];
        }
    }
    foreach (sec_all("SELECT entry_date, content FROM diary_list_items
                      WHERE user_id = ? AND entry_date BETWEEN ? AND ? AND list_type = 'learned' AND CHAR_LENGTH(content) >= 25
                      ORDER BY entry_date, sort_order", [$uid, $da, $a], 'iss') as $r) {
        $out['f' . (++$n)] = ['d' => $r['entry_date'], 't' => trim($r['content'])];
    }
    return $out;
}

/* ------------------------------------------------------------------ */
/* I numeri del trimestre, con il confronto                            */
/* ------------------------------------------------------------------ */
function da_numeri(int $uid, int $anno, int $q): array {
    [$da, $a] = dn_trimestre($anno, $q);
    $oggi = date('Y-m-d');
    if ($a > $oggi) $a = $oggi;
    $pq = $q === 1 ? [$anno - 1, 4] : [$anno, $q - 1];
    [$pda, $pa] = dn_trimestre($pq[0], $pq[1]);
    $ora = dn_riassunto(dn_giorni($uid, $da, $a));
    $prima = dn_riassunto(dn_giorni($uid, $pda, $pa));

    /* abitudine per abitudine: fatte e previste (l'obiettivo), ora e prima */
    $abitudini = [];
    $hs = sec_all("SELECT id, name, frequency_type, weekdays, interval_days, times_per_week, times_per_month,
                          start_date, end_date, is_archived, archived_at
                   FROM habits WHERE user_id = ? AND habit_kind = 'build' AND start_date <= ?", [$uid, $a], 'is');
    $fatte = function ($da, $a) use ($uid) {
        $m = [];
        foreach (sec_all("SELECT habit_id, COUNT(*) AS n FROM habit_logs WHERE user_id = ? AND status = 'done'
                          AND log_date BETWEEN ? AND ? GROUP BY habit_id", [$uid, $da, $a], 'iss') as $r) $m[(int)$r['habit_id']] = (int)$r['n'];
        return $m;
    };
    $fOra = $fatte($da, $a); $fPrima = $fatte($pda, $pa);
    foreach ($hs as $h) {
        $prev = 0.0; $prevPrima = 0.0;
        for ($t = strtotime($da); $t <= strtotime($a); $t += 86400) $prev += dn_prevista($h, date('Y-m-d', $t));
        for ($t = strtotime($pda); $t <= strtotime($pa); $t += 86400) $prevPrima += dn_prevista($h, date('Y-m-d', $t));
        if ($prev < 1 && $prevPrima < 1) continue;
        $abitudini[] = ['nome' => $h['name'],
                        'fatte' => $fOra[(int)$h['id']] ?? 0, 'obiettivo' => (int)round($prev),
                        'fatte_prima' => $fPrima[(int)$h['id']] ?? 0, 'obiettivo_prima' => (int)round($prevPrima)];
    }
    return ['periodo' => [$da, $a], 'periodo_prima' => [$pda, $pa], 'ora' => $ora, 'prima' => $prima, 'abitudini' => $abitudini];
}

/* ------------------------------------------------------------------ */
/* Schemi delle risposte                                               */
/* ------------------------------------------------------------------ */
function da_schema_resoconto(): array {
    $s = ['type' => 'STRING'];
    $a = ['type' => 'ARRAY', 'items' => $s];
    return ['type' => 'OBJECT', 'properties' => [
        'titolo' => $s, 'in_breve' => $s, 'sentimenti' => $a, 'alti' => $a, 'bassi' => $a,
        'temi' => ['type' => 'ARRAY', 'items' => ['type' => 'OBJECT', 'properties' => ['tema' => $s, 'testo' => $s], 'required' => ['tema', 'testo']]],
        'pensieri' => $a, 'abitudini' => $s, 'concentrazione' => $s, 'spunti' => $a,
        'libro_possibile' => ['type' => 'BOOLEAN'],
    ], 'required' => ['titolo', 'in_breve', 'sentimenti', 'alti', 'bassi', 'temi', 'pensieri', 'abitudini', 'concentrazione', 'spunti', 'libro_possibile']];
}
function da_schema_piano(): array {
    return ['type' => 'OBJECT', 'properties' => [
        'possibile' => ['type' => 'BOOLEAN'],
        'titolo' => ['type' => 'STRING'],
        'capitoli' => ['type' => 'ARRAY', 'items' => ['type' => 'OBJECT', 'properties' => [
            'titolo' => ['type' => 'STRING'],
            'dal_libro' => ['type' => 'INTEGER'],
            'frammenti' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
        ], 'required' => ['titolo', 'dal_libro', 'frammenti']]],
    ], 'required' => ['possibile', 'titolo', 'capitoli']];
}
function da_schema_testo(): array {
    return ['type' => 'OBJECT', 'properties' => ['testo' => ['type' => 'STRING']], 'required' => ['testo']];
}

/** Addebita a lavoro consegnato. */
function da_addebita(int $uid, array $token, string $nota): int {
    $c = ai_crediti_da_token($token['utili'], FC_AI_MODELLO);
    ai_crediti_addebita($uid, $c, ['model' => FC_AI_MODELLO, 'provider_tokens' => $token['totali'], 'note' => $nota]);
    return $c;
}

function da_trimestre_in(array $in): array {
    $anno = sec_int($in['anno'] ?? 0, 2000, 2100, (int)date('Y'));
    $q = sec_int($in['q'] ?? 0, 1, 4, 1);
    [$da, $a] = dn_trimestre($anno, $q);
    if ($da > date('Y-m-d')) sec_json(['ok' => false, 'error' => 'futuro'], 400);
    return [$anno, $q, $da, min($a, date('Y-m-d'))];
}

/** Un trimestre ha abbastanza da raccontare? */
function da_idoneo(int $uid, string $da, string $a): bool {
    $scritti = 0; $parole = 0;
    foreach (dn_giorni($uid, $da, $a) as $g) { if ($g['w'] >= 10) $scritti++; $parole += $g['w']; }
    return $scritti >= DN_MIN_GIORNI && $parole >= DN_MIN_PAROLE;
}

/* ================================================================== */
/* stima                                                               */
/* ================================================================== */
if ($azione === 'stima') {
    [$anno, $q, $da, $a] = da_trimestre_in($in);
    $per = fc_ai_enum($in['per'] ?? 'resoconto', ['resoconto', 'libro'], 'resoconto');
    if ($per === 'resoconto') {
        $car = mb_strlen(da_diario($uid, $da, $a));
        $stima = fc_ai_stima($car + 3000, 2200);
    } else {
        $car = 0;
        foreach (da_frammenti($uid, $da, $a) as $f) $car += mb_strlen($f['t']) + 20;
        $unisci = sec_int($in['unisci'] ?? 0, 0, PHP_INT_MAX, 0);
        $prec = 0;
        if ($unisci) {
            $b = sec_one("SELECT capitoli FROM diary_ai_books WHERE id = ? AND user_id = ?", [$unisci, $uid], 'ii');
            foreach ((array)json_decode($b['capitoli'] ?? '[]', true) as $c) $prec += mb_strlen((string)($c['testo'] ?? ''));
        }
        /* piano (legge tutto, scrive poco) + capitoli (leggono e riscrivono) */
        $stima = fc_ai_stima($car + 2000, 1200) + fc_ai_stima($car + (int)($prec * 0.6), 0) + fc_ai_token_da_caratteri($car + (int)($prec * 0.6));
    }
    $b = fc_ai_controlla_budget($uid, $stima);
    sec_json(['ok' => true, 'stima' => $b['stima'], 'disponibili' => $b['disponibili'], 'sufficiente' => $b['ok']]);
}

/* ================================================================== */
/* resoconto                                                           */
/* ================================================================== */
if ($azione === 'resoconto') {
    [$anno, $q, $da, $a] = da_trimestre_in($in);
    if (!da_idoneo($uid, $da, $a)) sec_json(['ok' => false, 'error' => 'troppo_poco'], 400);
    $diario = da_diario($uid, $da, $a);
    $numeri = da_numeri($uid, $anno, $q);
    $budget = fc_ai_controlla_budget($uid, fc_ai_stima(mb_strlen($diario) + 3000, 2200));
    if (!$budget['ok']) sec_json(['ok' => false, 'error' => 'limite_piano', 'stima' => $budget['stima'], 'disponibili' => $budget['disponibili']]);

    $sistema = "You write a quarterly personal report from someone's private journal (period $da to $a). "
        . "Write in the same language the journal is written in; if it is mixed, use $linguaUi. "
        . "Talk to the person directly (\"you\"), warmly, honestly, concretely, without judging and without clichés. "
        . "Ground everything in the journal: refer to their own words and to dates; NEVER invent events, people, feelings or facts. No diagnoses. "
        . "If the journal shows serious distress or thoughts of self-harm, gently encourage talking to someone they trust or to a professional, without dramatising. "
        . "Fields: titolo = a short evocative title for this quarter; in_breve = 2-3 sentences on how the quarter went; "
        . "sentimenti = 2-4 paragraphs on how they felt, how mood and energy moved and what seemed to lift or lower them; "
        . "alti / bassi = up to 4 short items each, with the date; temi = 2-5 recurring themes with a short paragraph each; "
        . "pensieri = 3-6 short lines, in first person as if the person wrote them, that sum up what they thought about in this period (their views and reflections, not the events); "
        . "abitudini = one paragraph on habits using the numbers given: done vs goal (the expected count) and vs the previous quarter, naming the habits that went best and worst; "
        . "concentrazione = one paragraph on focus time and pomodoros vs the previous quarter; "
        . "spunti = 2-3 gentle suggestions for the next quarter, each grounded in something they wrote or in the numbers; "
        . "libro_possibile = true only if the journal contains enough personal reflections (on life, relationships, themselves, values, the future) to make a small book of their thoughts, not just a log of what happened.";
    $utente = "NUMBERS (computed, use them as they are):\n" . json_encode($numeri, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            . "\n\nJOURNAL:\n" . $diario;
    $token = fc_ai_token_nuovo();
    $r = fc_ai_gemini_ritenta($sistema, $utente, da_schema_resoconto(), 0, $token, 'diario_resoconto', 0.6);
    if (!$r['ok']) sec_json(['ok' => false, 'error' => 'ia_' . $r['errore'], 'riprovabile' => fc_ai_riprovabile('ia_' . $r['errore'])], 502);

    $crediti = da_addebita($uid, $token, 'diario:resoconto ' . $anno . 'Q' . $q);
    sec_q("REPLACE INTO diary_ai_reports (user_id, anno, trimestre, periodo_da, periodo_a, contenuto, numeri, crediti)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
          [$uid, $anno, $q, $da, $a, json_encode($r['dati'], JSON_UNESCAPED_UNICODE), json_encode($numeri, JSON_UNESCAPED_UNICODE), $crediti]);
    $id = sec_insert_id();
    sec_json(['ok' => true, 'id' => $id, 'crediti' => $crediti, 'libro_possibile' => !empty($r['dati']['libro_possibile'])]);
}

/* ================================================================== */
/* libro_piano                                                         */
/* ================================================================== */
const DA_PARTE_MAX = 7000;   // caratteri di frammenti per chiamata

if ($azione === 'libro_piano') {
    [$anno, $q, $da, $a] = da_trimestre_in($in);
    if (!da_idoneo($uid, $da, $a)) sec_json(['ok' => false, 'error' => 'troppo_poco'], 400);
    $frammenti = da_frammenti($uid, $da, $a);
    if (count($frammenti) < 5) sec_json(['ok' => false, 'error' => 'niente_pensieri'], 400);

    $unisci = sec_int($in['unisci'] ?? 0, 0, PHP_INT_MAX, 0);
    $prec = null; $capPrec = [];
    if ($unisci) {
        $prec = sec_one("SELECT id, titolo, periodo_da, capitoli FROM diary_ai_books WHERE id = ? AND user_id = ? AND stato = 'fatto'", [$unisci, $uid], 'ii');
        if (!$prec) sec_json(['ok' => false, 'error' => 'libro_non_trovato'], 404);
        $capPrec = (array)json_decode($prec['capitoli'], true);
    }

    $elenco = '';
    foreach ($frammenti as $id => $f) $elenco .= $id . ' [' . $f['d'] . ']: ' . $f['t'] . "\n";
    $sistema = "You organise a person's own written thoughts into a short book. Use ONLY reflections: thoughts about life, relationships, themselves, values, fears, hopes, the future, the past. "
        . "Leave out fragments that only report events, plans or to-dos, unless they carry a reflection. "
        . "Group the chosen fragments by theme into 3 to 10 chapters, ordered so the book reads linearly, each chapter leading to the next. "
        . "For each chapter give a short title (in the language of the fragments; if mixed, $linguaUi), dal_libro (see below) and the ids of its fragments in the order they should be read. A fragment goes in one chapter at most. "
        . "titolo = a title for the whole book. possibile = false if there are not enough reflections to make a book (then capitoli can be empty).";
    if ($capPrec) {
        $sistema .= " This book CONTINUES a previous book whose chapters are listed as B0, B1, ...: every previous chapter must appear exactly once, with dal_libro = its number, "
                  . "possibly in a new order and with new fragments that belong to it; new chapters use dal_libro = -1.";
    } else {
        $sistema .= " Always set dal_libro = -1.";
    }
    $utente = '';
    if ($capPrec) {
        $utente .= "PREVIOUS BOOK CHAPTERS:\n";
        foreach ($capPrec as $i => $c) $utente .= 'B' . $i . ': ' . ($c['titolo'] ?? '') . ' — ' . mb_substr((string)($c['testo'] ?? ''), 0, 400) . "…\n";
        $utente .= "\n";
    }
    $utente .= "FRAGMENTS:\n" . $elenco;

    $budget = fc_ai_controlla_budget($uid, fc_ai_stima(mb_strlen($utente) + 2000, 1200));
    if (!$budget['ok']) sec_json(['ok' => false, 'error' => 'limite_piano', 'stima' => $budget['stima'], 'disponibili' => $budget['disponibili']]);
    $token = fc_ai_token_nuovo();
    $r = fc_ai_gemini_ritenta($sistema, $utente, da_schema_piano(), 0, $token, 'diario_libro', 0.3);
    if (!$r['ok']) sec_json(['ok' => false, 'error' => 'ia_' . $r['errore'], 'riprovabile' => fc_ai_riprovabile('ia_' . $r['errore'])], 502);
    $p = $r['dati'];
    if (empty($p['possibile']) && !$capPrec) {
        da_addebita($uid, $token, 'diario:libro_piano (niente pensieri)');
        sec_json(['ok' => false, 'error' => 'niente_pensieri'], 400);
    }

    /* Il piano si controlla qui, non ci si fida: frammenti inventati o usati
       due volte si tolgono, i capitoli del libro precedente dimenticati si
       rimettono in fondo, e i capitoli lunghi si dividono in parti. */
    $usati = []; $presi = []; $capitoli = [];
    foreach ((array)($p['capitoli'] ?? []) as $c) {
        $ids = [];
        foreach ((array)($c['frammenti'] ?? []) as $fid) {
            $fid = (string)$fid;
            if (!isset($frammenti[$fid]) || isset($usati[$fid])) continue;
            $usati[$fid] = true; $ids[] = $fid;
        }
        $dal = (int)($c['dal_libro'] ?? -1);
        if ($dal >= 0 && (!isset($capPrec[$dal]) || isset($presi[$dal]))) $dal = -1;
        if ($dal >= 0) $presi[$dal] = true;
        if ($dal < 0 && !$ids) continue;
        $parti = []; $parte = []; $lung = 0;
        foreach ($ids as $fid) {
            $l = mb_strlen($frammenti[$fid]['t']);
            if ($parte && $lung + $l > DA_PARTE_MAX) { $parti[] = $parte; $parte = []; $lung = 0; }
            $parte[] = $fid; $lung += $l;
        }
        if ($parte) $parti[] = $parte;
        $capitoli[] = ['titolo' => fc_ai_riga($c['titolo'] ?? '', 160), 'dal_libro' => $dal, 'parti' => $parti];
    }
    foreach ($capPrec as $i => $c) {
        if (!isset($presi[$i])) $capitoli[] = ['titolo' => (string)($c['titolo'] ?? ''), 'dal_libro' => $i, 'parti' => []];
    }
    if (!$capitoli) { da_addebita($uid, $token, 'diario:libro_piano (vuoto)'); sec_json(['ok' => false, 'error' => 'niente_pensieri'], 400); }

    $piano = ['titolo' => fc_ai_riga($p['titolo'] ?? '', 200), 'capitoli' => $capitoli, 'frammenti' => $frammenti];
    $scritti = array_map(function ($c) { return ['titolo' => $c['titolo'], 'testo' => '', 'parti_fatte' => 0]; }, $capitoli);
    $crediti = da_addebita($uid, $token, 'diario:libro_piano ' . $anno . 'Q' . $q);
    sec_q("INSERT INTO diary_ai_books (user_id, titolo, periodo_da, periodo_a, unito_a, stato, piano, capitoli, crediti)
           VALUES (?, ?, ?, ?, ?, 'scrittura', ?, ?, ?)",
          [$uid, $piano['titolo'], $prec ? $prec['periodo_da'] : $da, $a, $prec ? (int)$prec['id'] : null,
           json_encode($piano, JSON_UNESCAPED_UNICODE), json_encode($scritti, JSON_UNESCAPED_UNICODE), $crediti]);
    $id = sec_insert_id();
    $pezzi = 0;
    foreach ($capitoli as $c) $pezzi += max(1, count($c['parti']));
    sec_json(['ok' => true, 'id' => $id, 'titolo' => $piano['titolo'],
              'capitoli' => array_map(function ($c) { return $c['titolo']; }, $capitoli), 'pezzi' => $pezzi, 'crediti' => $crediti]);
}

/* ================================================================== */
/* libro_avanti — il pezzo successivo                                  */
/* ================================================================== */
if ($azione === 'libro_avanti') {
    $id = sec_int($in['id'] ?? 0, 1, PHP_INT_MAX, 0);
    $b = sec_one("SELECT * FROM diary_ai_books WHERE id = ? AND user_id = ?", [$id, $uid], 'ii');
    if (!$b) sec_json(['ok' => false, 'error' => 'libro_non_trovato'], 404);
    $piano = json_decode($b['piano'], true);
    $scritti = json_decode($b['capitoli'], true);
    $capPrec = [];
    if (!empty($b['unito_a'])) {
        $pr = sec_one("SELECT capitoli FROM diary_ai_books WHERE id = ? AND user_id = ?", [(int)$b['unito_a'], $uid], 'ii');
        $capPrec = $pr ? (array)json_decode($pr['capitoli'], true) : [];
    }
    $conta = function () use ($piano, $scritti) {
        $tot = 0; $fatti = 0;
        foreach ($piano['capitoli'] as $i => $c) { $n = max(1, count($c['parti'])); $tot += $n; $fatti += min($n, (int)$scritti[$i]['parti_fatte']); }
        return [$fatti, $tot];
    };

    /* il primo pezzo che manca */
    $ci = null; $pi = null;
    foreach ($piano['capitoli'] as $i => $c) {
        $n = max(1, count($c['parti']));
        if ((int)$scritti[$i]['parti_fatte'] < $n) { $ci = $i; $pi = (int)$scritti[$i]['parti_fatte']; break; }
    }
    if ($ci === null) {
        sec_q("UPDATE diary_ai_books SET stato = 'fatto' WHERE id = ? AND user_id = ?", [$id, $uid], 'ii');
        [$f, $t] = $conta();
        sec_json(['ok' => true, 'fatto' => true, 'fatti' => $f, 'totale' => $t]);
    }
    $cap = $piano['capitoli'][$ci];
    $testoPrec = $cap['dal_libro'] >= 0 && isset($capPrec[$cap['dal_libro']]) ? (string)($capPrec[$cap['dal_libro']]['testo'] ?? '') : '';
    $crediti = 0;

    if (!$cap['parti']) {
        /* un capitolo del libro precedente senza niente di nuovo: resta com'era */
        $scritti[$ci]['testo'] = $testoPrec;
        $scritti[$ci]['parti_fatte'] = 1;
    } else {
        $fr = '';
        foreach ($cap['parti'][$pi] as $fid) if (isset($piano['frammenti'][$fid])) $fr .= '- ' . $piano['frammenti'][$fid]['t'] . "\n";
        $sistema = "You rewrite a person's own thoughts as one chapter of a book written by them: first person, in their language and with their voice. "
            . "Keep every idea and every nuance. Do NOT add ideas, facts, examples, opinions, advice or conclusions that are not in the text, and do not leave any out. "
            . "You may fix grammar and style, join sentences, remove repetitions and reorder sentences so the chapter reads linearly from beginning to end. "
            . "No headings, no title, no lists, no commentary about the author. Paragraphs separated by one blank line.";
        $utente = 'Chapter title: ' . $cap['titolo'] . "\n\n";
        if ($pi === 0 && $testoPrec !== '') {
            $sistema .= " The chapter already exists: keep its text (you may lightly re-edit it so the new thoughts fit in) and weave the new thoughts in where they belong, so the result is one coherent chapter.";
            $utente .= "EXISTING CHAPTER:\n" . $testoPrec . "\n\nNEW THOUGHTS TO WEAVE IN:\n" . $fr;
        } elseif ($pi > 0) {
            $sistema .= " This is a continuation: the chapter already started; continue it naturally from where it stops, without repeating it.";
            $fine = mb_substr((string)$scritti[$ci]['testo'], -1200);
            $utente .= "THE CHAPTER SO FAR ENDS WITH:\n…" . $fine . "\n\nTHOUGHTS TO CONTINUE WITH:\n" . $fr;
        } else {
            $utente .= "THOUGHTS:\n" . $fr;
        }
        $budget = fc_ai_controlla_budget($uid, fc_ai_stima(mb_strlen($utente) + 1500, fc_ai_token_da_caratteri(mb_strlen($utente))));
        if (!$budget['ok']) sec_json(['ok' => false, 'error' => 'limite_piano', 'stima' => $budget['stima'], 'disponibili' => $budget['disponibili']]);
        $token = fc_ai_token_nuovo();
        $r = fc_ai_gemini_ritenta($sistema, $utente, da_schema_testo(), 0, $token, 'diario_libro', 0.4);
        if (!$r['ok']) sec_json(['ok' => false, 'error' => 'ia_' . $r['errore'], 'riprovabile' => fc_ai_riprovabile('ia_' . $r['errore'])], 502);
        $testo = trim((string)($r['dati']['testo'] ?? ''));
        if ($testo === '') sec_json(['ok' => false, 'error' => 'ia_vuota', 'riprovabile' => true], 502);
        $scritti[$ci]['testo'] = $pi > 0 ? rtrim($scritti[$ci]['testo']) . "\n\n" . $testo : $testo;
        $scritti[$ci]['parti_fatte'] = $pi + 1;
        $crediti = da_addebita($uid, $token, 'diario:libro_capitolo ' . $id);
    }
    sec_q("UPDATE diary_ai_books SET capitoli = ?, crediti = crediti + ? WHERE id = ? AND user_id = ?",
          [json_encode($scritti, JSON_UNESCAPED_UNICODE), $crediti, $id, $uid], 'siii');
    [$f, $t] = $conta();
    if ($f >= $t) sec_q("UPDATE diary_ai_books SET stato = 'fatto' WHERE id = ? AND user_id = ?", [$id, $uid], 'ii');
    sec_json(['ok' => true, 'fatto' => $f >= $t, 'fatti' => $f, 'totale' => $t, 'capitolo' => $scritti[$ci]['titolo'], 'crediti' => $crediti]);
}
