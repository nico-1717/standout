<?php
/* =========================================================================
   STANDOUT · SEZIONE AMICI — libreria condivisa
   Usata da: friends.php, friends_api.php, notifiche_widget.php
   ========================================================================= */

require_once __DIR__ . '/../../lib/config.php';
// FC_TABELLE: i nomi delle tabelle delle flashcard (studio e lingue), elenco chiuso
require_once __DIR__ . '/../../lib/fc/fc_type.php';

/* -------------------------------------------------------------------------
   CONFIGURAZIONE (le uniche 3 righe che potresti dover toccare)
   ------------------------------------------------------------------------- */

/* Quali sessioni di pomodoro contano come "focus": concluse e con durata reale.
   Se nel tuo DB l'esito delle sessioni annullate va escluso, aggiungi qui:
   ... AND ps.esito <> 'annullata'                                            */
const FR_POMO_OK = "ps.end_time IS NOT NULL AND ps.duration_seconds IS NOT NULL AND ps.duration_seconds > 0";

/* La tua pagina di logout. */
const FR_LOGOUT_URL = PWA_BASE . 'account/logout.php';

/* Se in index.php hai GIÀ un pallino/avatar con dentro il logout, scrivi qui il
   suo selettore CSS (es. '#userMenu' oppure '.avatar-btn'): il widget lo nasconde
   da solo, così non ti ritrovi due pallini in alto a destra. Lascia '' se non serve. */
const FR_LEGACY_AVATAR_SELECTOR = '';


/* -------------------------------------------------------------------------
   CONNESSIONE + UTENTE
   ------------------------------------------------------------------------- */
function fr_conn(): mysqli {
    static $c = null;
    if ($c instanceof mysqli) { return $c; }
    $c = @mysqli_connect(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
    if (!$c) { die(t('amici.e_connessione')); }
    @mysqli_set_charset($c, 'utf8mb4');
    return $c;
}

/* Recupera l'utente loggato qualunque sia il sistema di auth della pagina
   ospite (auth_check.php -> $utente_corrente, auth_core.php -> funzione). */
function fr_utente_corrente(): ?array {
    if (!empty($GLOBALS['utente_corrente']['id'])) { return $GLOBALS['utente_corrente']; }
    if (!empty($GLOBALS['standout_utente']['id']))   { return $GLOBALS['standout_utente']; }
    if (function_exists('standout_utente_corrente')) {
        $u = standout_utente_corrente();
        if (!empty($u['id'])) { return $u; }
    }
    return null;
}


/* -------------------------------------------------------------------------
   FORMATTAZIONE / IDENTITÀ VISIVA
   ------------------------------------------------------------------------- */

/* 3720s -> "1h 02m" · 900s -> "15m" · 0 -> "0m" */
function fr_durata(int $sec): string {
    $sec = max(0, $sec);
    $h = intdiv($sec, 3600);
    $m = intdiv($sec % 3600, 60);
    if ($h > 0) { return $h . 'h ' . str_pad((string)$m, 2, '0', STR_PAD_LEFT) . 'm'; }
    if ($m > 0) { return $m . 'm'; }
    return $sec > 0 ? '<1m' : '0m';
}

function fr_minuti(int $sec): int { return (int)round($sec / 60); }

function fr_iniziale(string $username): string {
    $u = trim($username);
    if ($u === '') { return '?'; }
    if (function_exists('mb_substr')) { return mb_strtoupper(mb_substr($u, 0, 1, 'UTF-8'), 'UTF-8'); }
    return strtoupper(substr($u, 0, 1));
}

/* Gradiente stabile e unico per ogni utente: l'avatar di Marco sarà sempre
   quello stesso viola/rosa, ovunque nel sito. Riconoscibilità istantanea. */
function fr_gradiente(int $uid): string {
    $h1 = ($uid * 67 + 24) % 360;
    $h2 = ($h1 + 46) % 360;
    return "linear-gradient(135deg, hsl({$h1} 85% 63%), hsl({$h2} 80% 48%))";
}

/* Livello: 1 punto per carta giocata, 2 per minuto di focus, 5 per carta imparata.
   Soglia livello n = 100*(n-1)^2  ->  i primi livelli arrivano subito,
   i successivi costano sempre di più. */
function fr_livello(int $xp): array {
    $liv  = 1 + (int)floor(sqrt(max(0, $xp) / 100));
    $base = 100 * ($liv - 1) ** 2;
    $next = 100 * $liv ** 2;
    $pct  = $next > $base ? (int)round((($xp - $base) / ($next - $base)) * 100) : 0;
    return ['livello' => $liv, 'xp' => $xp, 'xp_base' => $base, 'xp_next' => $next, 'pct' => max(0, min(100, $pct))];
}


/* -------------------------------------------------------------------------
   AMICIZIE
   ------------------------------------------------------------------------- */
function fr_coppia(int $a, int $b): array { return [min($a, $b), max($a, $b)]; }

function fr_sono_amici(mysqli $c, int $a, int $b): bool {
    if ($a === $b) { return false; }
    [$x, $y] = fr_coppia($a, $b);
    $r = mysqli_query($c, "SELECT 1 FROM friends WHERE user_id_a = $x AND user_id_b = $y LIMIT 1");
    return (bool)($r && mysqli_num_rows($r) > 0);
}

/** @return int[] id degli amici */
function fr_amici_ids(mysqli $c, int $uid): array {
    $ids = [];
    $r = mysqli_query($c, "SELECT IF(user_id_a = $uid, user_id_b, user_id_a) AS amico
                           FROM friends WHERE user_id_a = $uid OR user_id_b = $uid");
    while ($r && ($row = mysqli_fetch_assoc($r))) { $ids[] = (int)$row['amico']; }
    return $ids;
}

/** @return array<int,array{id:int,username:string}> */
function fr_utenti(mysqli $c, array $ids): array {
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if (!$ids) { return []; }
    $in  = implode(',', $ids);
    $out = [];
    $r = mysqli_query($c, "SELECT id, username FROM users WHERE id IN ($in)");
    while ($r && ($row = mysqli_fetch_assoc($r))) {
        $out[(int)$row['id']] = ['id' => (int)$row['id'], 'username' => $row['username']];
    }
    return $out;
}

/* Chi sta facendo focus PROPRIO ADESSO (sessione aperta da meno di 12h).
   @return array<int,int> uid => minuti trascorsi */
function fr_in_focus_ora(mysqli $c, array $ids): array {
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if (!$ids) { return []; }
    $in  = implode(',', $ids);
    $out = [];
    $r = mysqli_query($c, "SELECT user_id, TIMESTAMPDIFF(MINUTE, MAX(start_time), NOW()) AS min_fa
                           FROM pomodoro_sessions
                           WHERE user_id IN ($in) AND end_time IS NULL
                             AND start_time >= (NOW() - INTERVAL 12 HOUR)
                           GROUP BY user_id");
    while ($r && ($row = mysqli_fetch_assoc($r))) { $out[(int)$row['user_id']] = max(0, (int)$row['min_fa']); }
    return $out;
}

/* Chi ha fatto QUALCOSA oggi (focus o flashcard): serve per l'anello colorato
   attorno all'avatar, come le storie di Instagram. @return int[] */
function fr_attivi_oggi(mysqli $c, array $ids): array {
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if (!$ids) { return []; }
    $in  = implode(',', $ids);
    $out = [];
    $r = mysqli_query($c, "SELECT DISTINCT u FROM (
            SELECT ps.user_id AS u FROM pomodoro_sessions ps
             WHERE ps.user_id IN ($in) AND DATE(ps.start_time) = CURDATE()
            UNION
            SELECT gm.user_id AS u FROM fc_games gm JOIN " . FC_TABELLE['study']['plays'] . " gp ON gp.game_id = gm.id
                WHERE gm.user_id IN ($in) AND DATE(gp.play_date) = CURDATE()
            UNION
            SELECT gm.user_id AS u FROM fc_games gm JOIN " . FC_TABELLE['vocab']['plays'] . " gp ON gp.game_id = gm.id
                WHERE gm.user_id IN ($in) AND DATE(gp.play_date) = CURDATE()
        ) t");
    while ($r && ($row = mysqli_fetch_assoc($r))) { $out[] = (int)$row['u']; }
    return $out;
}

/* Focus di oggi (secondi) per un gruppo di utenti. @return array<int,int> */
function fr_focus_oggi_multi(mysqli $c, array $ids): array {
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if (!$ids) { return []; }
    $in  = implode(',', $ids);
    $out = [];
    $r = mysqli_query($c, "SELECT ps.user_id, SUM(ps.duration_seconds) AS s
                           FROM pomodoro_sessions ps
                           WHERE ps.user_id IN ($in) AND DATE(ps.start_time) = CURDATE() AND " . FR_POMO_OK . "
                           GROUP BY ps.user_id");
    while ($r && ($row = mysqli_fetch_assoc($r))) { $out[(int)$row['user_id']] = (int)$row['s']; }
    return $out;
}


/* -------------------------------------------------------------------------
   NOTIFICHE
   ------------------------------------------------------------------------- */

/* Il numero rosso sul pallino:
   richieste ricevute ancora in sospeso (vanno gestite, quindi insistono)
   + notifiche di accettazione non ancora lette (spariscono appena le leggi). */
function fr_conta_notifiche(mysqli $c, int $uid): int {
    $n = 0;
    $r = mysqli_query($c, "SELECT COUNT(*) AS n FROM friends_notifications
                           WHERE to_user_id = $uid AND is_accepted = 0");
    if ($r) { $n += (int)mysqli_fetch_assoc($r)['n']; }
    $r = mysqli_query($c, "SELECT COUNT(*) AS n FROM friends_notifications
                           WHERE to_user_id = $uid AND is_accepted = 1 AND is_read = 0");
    if ($r) { $n += (int)mysqli_fetch_assoc($r)['n']; }
    return $n;
}

/* Elenco completo per il pannello: ricevute, inviate, accettate. */
function fr_notifiche(mysqli $c, int $uid): array {
    $out = [];

    // 1. richieste RICEVUTE (da accettare o rifiutare)
    $r = mysqli_query($c, "SELECT n.from_user_id AS uid, u.username, n.created_at
                           FROM friends_notifications n JOIN users u ON u.id = n.from_user_id
                           WHERE n.to_user_id = $uid AND n.is_accepted = 0
                           ORDER BY n.created_at DESC");
    while ($r && ($row = mysqli_fetch_assoc($r))) {
        $out[] = ['tipo' => 'ricevuta', 'uid' => (int)$row['uid'], 'username' => $row['username'],
                  'quando' => $row['created_at'], 'nuova' => true];
    }

    // 2. accettazioni: "X ha accettato la tua richiesta"
    $r = mysqli_query($c, "SELECT n.from_user_id AS uid, u.username, n.created_at, n.is_read
                           FROM friends_notifications n JOIN users u ON u.id = n.from_user_id
                           WHERE n.to_user_id = $uid AND n.is_accepted = 1
                           ORDER BY n.created_at DESC LIMIT 20");
    while ($r && ($row = mysqli_fetch_assoc($r))) {
        $out[] = ['tipo' => 'accettata', 'uid' => (int)$row['uid'], 'username' => $row['username'],
                  'quando' => $row['created_at'], 'nuova' => !(int)$row['is_read']];
    }

    // 3. richieste INVIATE ancora in attesa
    $r = mysqli_query($c, "SELECT n.to_user_id AS uid, u.username, n.created_at
                           FROM friends_notifications n JOIN users u ON u.id = n.to_user_id
                           WHERE n.from_user_id = $uid AND n.is_accepted = 0
                           ORDER BY n.created_at DESC");
    while ($r && ($row = mysqli_fetch_assoc($r))) {
        $out[] = ['tipo' => 'inviata', 'uid' => (int)$row['uid'], 'username' => $row['username'],
                  'quando' => $row['created_at'], 'nuova' => false];
    }

    // le cose da fare stanno sempre in cima
    usort($out, function ($a, $b) {
        $peso = ['ricevuta' => 0, 'accettata' => 1, 'inviata' => 2];
        if ($peso[$a['tipo']] !== $peso[$b['tipo']]) { return $peso[$a['tipo']] <=> $peso[$b['tipo']]; }
        return strcmp((string)$b['quando'], (string)$a['quando']);
    });
    return $out;
}

/* "3 min fa", "2 h fa", "ieri", "12/07" */
function fr_quando(string $datetime): string {
    $t = strtotime($datetime);
    if (!$t) { return ''; }
    $d = time() - $t;
    if ($d < 60)     { return t('notifiche.adesso'); }
    if ($d < 3600)   { return t('notifiche.min_fa', ['n' => intdiv($d, 60)]); }
    if ($d < 86400)  { return t('notifiche.ore_fa', ['n' => intdiv($d, 3600)]); }
    if ($d < 172800) { return t('notifiche.ieri'); }
    if ($d < 604800) { return t('notifiche.giorni_fa', ['n' => intdiv($d, 86400)]); }
    return date(standout_lingua() === 'en' ? 'm/d' : 'd/m', $t);
}


/* -------------------------------------------------------------------------
   ICONE BADGE (stessa famiglia di stats_fc.php)
   ------------------------------------------------------------------------- */
function fr_icona(string $t): string {
    $o = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">';
    switch ($t) {
        case 'flame':  return $o . '<path d="M8.5 14.5A2.5 2.5 0 0 0 11 17a2.5 2.5 0 0 0 2.5-2.5c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7.5 7.5 0 1 1-15 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z"/></svg>';
        case 'book':   return $o . '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>';
        case 'trophy': return $o . '<path d="M8 21h8M12 17v4M7 4h10v5a5 5 0 0 1-10 0V4z"/><path d="M17 5h3a2 2 0 0 1-2 4M7 5H4a2 2 0 0 0 2 4"/></svg>';
        case 'star':   return $o . '<path d="m12 2 3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>';
        case 'clock':  return $o . '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>';
        case 'timer':  return $o . '<path d="M10 2h4"/><path d="M12 14v-4"/><circle cx="12" cy="14" r="8"/></svg>';
        case 'bolt':   return $o . '<path d="M13 2 4 14h6l-1 8 9-12h-6l1-8z"/></svg>';
        case 'gem':    return $o . '<path d="M6 3h12l4 6-10 12L2 9z"/><path d="M2 9h20"/><path d="m10 3-2 6 4 12 4-12-2-6"/></svg>';
        case 'users':  return $o . '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>';
        default:       return $o . '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1.2" fill="currentColor"/></svg>';
    }
}


/* -------------------------------------------------------------------------
   STREAK — identica logica a stats_fc.php (giorni consecutivi, viva se
   l'ultimo giorno attivo è oggi o ieri)
   ------------------------------------------------------------------------- */
function fr_streak(array $giorni): array {
    $giorni = array_values(array_unique($giorni));
    sort($giorni);
    $record = 0; $run = 0; $prev = null;
    foreach ($giorni as $g) {
        $o = new DateTime($g);
        if ($prev !== null && (int)$prev->diff($o)->format('%a') === 1) { $run++; } else { $run = 1; }
        $record = max($record, $run);
        $prev = $o;
    }
    $corrente = 0;
    if ($giorni) {
        $diff = (int)(new DateTime('today'))->diff(new DateTime(end($giorni)))->format('%r%a');
        if ($diff === 0 || $diff === -1) { $corrente = $run; }
    }
    return ['corrente' => $corrente, 'record' => $record];
}


/* =========================================================================
   IL MOTORE: tutte le statistiche di UN utente.
   Stesse tabelle e stessa logica di stats_fc.php, ma parametrizzate
   sull'utente -> gli stessi numeri valgono per me e per i miei amici.
   ========================================================================= */
function fr_stats(mysqli $c, int $uid, string $username = ''): array {
    $uid = (int)$uid;
    if ($username === '') {
        $r = mysqli_query($c, "SELECT username FROM users WHERE id = $uid");
        $username = ($r && ($row = mysqli_fetch_assoc($r))) ? $row['username'] : 'utente';
    }

    /* ---------- FLASHCARD: giorni attivi, carte giocate, precisione ---------- */
    $fcGiorni = []; $carteGiocate = 0; $carteCorrette = 0;
    // Le giocate stanno in due tabelle (studio e lingue): si sommano con una UNION.
    // I nomi di tabella vengono da FC_TABELLE (elenco chiuso), mai dal browser.
    $pS = FC_TABELLE['study']['plays']; $pV = FC_TABELLE['vocab']['plays'];
    $r = mysqli_query($c, "SELECT d, COUNT(*) AS c, SUM(ans = 'Correct') AS ok FROM (
            SELECT DATE(gp.play_date) AS d, gp.user_answer AS ans
                FROM $pS gp JOIN fc_games gm ON gp.game_id = gm.id
                WHERE gm.user_id = $uid AND gp.play_date IS NOT NULL AND gp.user_answer IN ('Correct','Wrong')
            UNION ALL
            SELECT DATE(gp.play_date), gp.user_answer
                FROM $pV gp JOIN fc_games gm ON gp.game_id = gm.id
                WHERE gm.user_id = $uid AND gp.play_date IS NOT NULL AND gp.user_answer IN ('Correct','Wrong')
        ) x GROUP BY d ORDER BY d ASC");
    while ($r && ($row = mysqli_fetch_assoc($r))) {
        $fcGiorni[]     = $row['d'];
        $carteGiocate  += (int)$row['c'];
        $carteCorrette += (int)$row['ok'];
    }
    $precisione = $carteGiocate > 0 ? (int)round($carteCorrette / $carteGiocate * 100) : 0;

    /* ---------- POMODORO: una query, tutti i numeri ---------- */
    $pomoGiorni = []; $perGiorno = [];
    $focusTotale = 0; $sessioni = 0; $record = 0; $recordData = null;
    $r = mysqli_query($c, "SELECT DATE(ps.start_time) AS d, SUM(ps.duration_seconds) AS s, COUNT(*) AS n
        FROM pomodoro_sessions ps
        WHERE ps.user_id = $uid AND " . FR_POMO_OK . "
        GROUP BY d ORDER BY d ASC");
    while ($r && ($row = mysqli_fetch_assoc($r))) {
        $g = $row['d']; $s = (int)$row['s'];
        $pomoGiorni[]   = $g;
        $perGiorno[$g]  = $s;
        $focusTotale   += $s;
        $sessioni      += (int)$row['n'];
        if ($s > $record) { $record = $s; $recordData = $g; }
    }
    $oggi     = date('Y-m-d');
    $focusOggi = $perGiorno[$oggi] ?? 0;

    $tot7 = 0; $tot30 = 0; $serie7 = [];
    for ($i = 6; $i >= 0; $i--) { $serie7[] = $perGiorno[date('Y-m-d', strtotime("-$i day"))] ?? 0; }
    foreach ($serie7 as $v) { $tot7 += $v; }
    for ($i = 29; $i >= 0; $i--) { $tot30 += $perGiorno[date('Y-m-d', strtotime("-$i day"))] ?? 0; }

    /* ---------- STREAK: flashcard, pomodoro e "perfetta" (entrambe) ---------- */
    $sFc    = fr_streak($fcGiorni);
    $sPomo  = fr_streak($pomoGiorni);
    // Streak perfetta = giorni in cui hai fatto SIA flashcard SIA focus.
    // Se preferisci "almeno una delle due", sostituisci array_intersect con array_unique(array_merge(...)).
    $sCombo = fr_streak(array_values(array_intersect($fcGiorni, $pomoGiorni)));

    /* ---------- CARTE TOTALI / IMPARATE (per mastery e badge) ----------
       Vale per studio e lingue insieme. In "learnt" c'e' sempre l'id della carta
       padre (anche quando si impara una figlia: fc_game.php marca il padre). */
    $carteTotali = 0; $carteImparate = 0;
    $catTot = []; $catImp = [];
    foreach (FC_TABELLE as $tipo => $T) {
        // nello studio i padri sono contenitori e non contano; nelle lingue si'
        $soloFoglie = $tipo === 'study' ? 'AND i.is_father = 0' : '';
        $r = mysqli_query($c, "SELECT COUNT(*) AS c FROM {$T['cards']} i
                JOIN {$T['decks']} d ON i.category_id = d.id WHERE d.user_id = $uid $soloFoglie");
        $carteTotali += $r ? (int)mysqli_fetch_assoc($r)['c'] : 0;
        $r = mysqli_query($c, "SELECT COUNT(*) AS c FROM {$T['subcards']} s
                JOIN {$T['cards']} i ON s.parent_id = i.id
                JOIN {$T['decks']} d ON i.category_id = d.id WHERE d.user_id = $uid");
        $carteTotali += $r ? (int)mysqli_fetch_assoc($r)['c'] : 0;
        $r = mysqli_query($c, "SELECT COUNT(DISTINCT fc_image_id) AS c FROM {$T['learnt']} WHERE user_id = $uid");
        $carteImparate += $r ? (int)mysqli_fetch_assoc($r)['c'] : 0;

        // mazzo completato (serve per il badge trofeo): tutte le sue carte sono imparate
        $r = mysqli_query($c, "SELECT i.category_id AS cid, COUNT(*) AS t, SUM(l.fc_image_id IS NOT NULL) AS l
                FROM {$T['cards']} i JOIN {$T['decks']} d ON i.category_id = d.id
                LEFT JOIN {$T['learnt']} l ON l.fc_image_id = i.id AND l.user_id = $uid
                WHERE d.user_id = $uid GROUP BY i.category_id");
        while ($r && ($row = mysqli_fetch_assoc($r))) {
            $catTot[$tipo . $row['cid']] = (int)$row['t'];
            $catImp[$tipo . $row['cid']] = (int)$row['l'];
        }
    }
    $mastery = $carteTotali > 0 ? (int)round($carteImparate / $carteTotali * 100) : 0;
    $catCompleta = false;
    foreach ($catTot as $k => $t) {
        if ($t > 0 && ($catImp[$k] ?? 0) >= $t) { $catCompleta = true; break; }
    }

    /* ---------- CATEGORIE GIOCATE NELLE ULTIME 24 ORE ----------
       Categoria risolta a cascata come in fc_stats.php:
       1) mazzo vero della carta  2) partita con un solo mazzo  3) primo mazzo della partita.
       La durata della partita (fc_game_durations) viene ripartita fra i suoi
       mazzi in proporzione alle carte giocate. Studio e lingue hanno mazzi con id
       separati, quindi la chiave e' "tipo + id" (es. study12, vocab12). */
    $perPartita = [];
    foreach (FC_TABELLE as $tipo => $T) {
        $r = mysqli_query($c, "SELECT gp.game_id AS gid, gp.user_answer AS ans,
                COALESCE(
                    ci.category_id,
                    gc1.category_id,
                    (SELECT gd.fc_category_id FROM {$T['game_decks']} gd
                        JOIN {$T['decks']} de ON de.id = gd.fc_category_id
                        WHERE gd.game_id = gm.id AND de.user_id = $uid
                        ORDER BY gd.category_order ASC LIMIT 1)
                ) AS cat
            FROM {$T['plays']} gp
            JOIN fc_games gm ON gp.game_id = gm.id
            LEFT JOIN {$T['cards']} ci ON gp.fc_image_id = ci.id
            LEFT JOIN (SELECT game_id, MIN(fc_category_id) AS category_id FROM {$T['game_decks']}
                        GROUP BY game_id HAVING COUNT(*) = 1) gc1 ON gc1.game_id = gp.game_id
            WHERE gm.user_id = $uid
            AND gp.play_date >= (NOW() - INTERVAL 24 HOUR)
            AND gp.user_answer IN ('Correct','Wrong')");
        while ($r && ($row = mysqli_fetch_assoc($r))) {
            $gid = (int)$row['gid'];
            $cid = $row['cat'] !== null ? $tipo . (int)$row['cat'] : '';
            if (!isset($perPartita[$gid])) { $perPartita[$gid] = ['tot' => 0, 'cat' => []]; }
            if (!isset($perPartita[$gid]['cat'][$cid])) { $perPartita[$gid]['cat'][$cid] = ['n' => 0, 'ok' => 0]; }
            $perPartita[$gid]['tot']++;
            $perPartita[$gid]['cat'][$cid]['n']++;
            if ($row['ans'] === 'Correct') { $perPartita[$gid]['cat'][$cid]['ok']++; }
        }
    }

    $durPartita = [];
    if ($perPartita) {
        $in = implode(',', array_map('intval', array_keys($perPartita)));
        $r = mysqli_query($c, "SELECT game_id, SUM(duration_seconds) AS s FROM fc_game_durations
                               WHERE game_id IN ($in) GROUP BY game_id");
        while ($r && ($row = mysqli_fetch_assoc($r))) { $durPartita[(int)$row['game_id']] = (int)$row['s']; }
    }

    $catAgg = [];
    foreach ($perPartita as $gid => $p) {
        $dur = $durPartita[$gid] ?? 0;
        foreach ($p['cat'] as $cid => $v) {
            if (!isset($catAgg[$cid])) { $catAgg[$cid] = ['n' => 0, 'ok' => 0, 'sec' => 0]; }
            $catAgg[$cid]['n']   += $v['n'];
            $catAgg[$cid]['ok']  += $v['ok'];
            $catAgg[$cid]['sec'] += $p['tot'] > 0 ? $dur * ($v['n'] / $p['tot']) : 0;
        }
    }

    // i nomi si leggono dalla tabella dei mazzi del loro tipo
    $nomi = [];
    foreach (FC_TABELLE as $tipo => $T) {
        $ids = [];
        foreach (array_keys($catAgg) as $k) {
            if ($k !== '' && strpos((string)$k, $tipo) === 0) { $ids[] = (int)substr((string)$k, strlen($tipo)); }
        }
        if (!$ids) { continue; }
        $in = implode(',', $ids);
        $r = mysqli_query($c, "SELECT id, name, color FROM {$T['decks']} WHERE id IN ($in) AND user_id = $uid");
        while ($r && ($row = mysqli_fetch_assoc($r))) {
            $nomi[$tipo . (int)$row['id']] = ['nome' => $row['name'], 'colore' => $row['color'] ?: '#007AFF'];
        }
    }

    $categorie = []; $carte24 = 0; $ok24 = 0;
    foreach ($catAgg as $cid => $v) {
        $carte24 += $v['n']; $ok24 += $v['ok'];
        $categorie[] = [
            'id'     => $cid,
            'nome'   => $nomi[$cid]['nome']   ?? t('amici.senza_categoria'),
            'colore' => $nomi[$cid]['colore'] ?? '#8A8A8E',
            'sec'    => (int)round($v['sec']),
            'carte'  => $v['n'],
            'pct'    => $v['n'] > 0 ? (int)round($v['ok'] / $v['n'] * 100) : 0,
        ];
    }
    usort($categorie, function ($a, $b) {
        $d = $b['sec'] <=> $a['sec'];
        return $d !== 0 ? $d : ($b['carte'] <=> $a['carte']);
    });
    $precisione24 = $carte24 > 0 ? (int)round($ok24 / $carte24 * 100) : 0;

    /* ---------- PROGETTO PIÙ SEGUITO: oggi e questa settimana ---------- */
    $topProgetto = function (string $filtroData) use ($c, $uid) {
        $r = mysqli_query($c, "SELECT p.name, p.color, SUM(ps.duration_seconds) AS s
            FROM pomodoro_sessions ps JOIN projects p ON p.id = ps.project_id
            WHERE ps.user_id = $uid AND ps.project_id IS NOT NULL
              AND $filtroData AND " . FR_POMO_OK . "
            GROUP BY p.id, p.name, p.color ORDER BY s DESC LIMIT 1");
        if ($r && ($row = mysqli_fetch_assoc($r))) {
            return ['nome' => $row['name'], 'colore' => $row['color'] ?: '#007AFF', 'sec' => (int)$row['s']];
        }
        return null;
    };
    $progOggi = $topProgetto("DATE(ps.start_time) = CURDATE()");
    $progSett = $topProgetto("ps.start_time >= (CURDATE() - INTERVAL 6 DAY)");

    /* ---------- LIVELLO ---------- */
    $xp  = $carteGiocate + fr_minuti($focusTotale) * 2 + $carteImparate * 5;
    $liv = fr_livello($xp);

    /* ---------- BADGE ---------- */
    $badge = [
        ['t' => 'target', 'l' => t('amici.b_10_carte'),       'v' => $carteGiocate    >= 10],
        ['t' => 'flame',  'l' => t('amici.b_streak_3'),     'v' => $sFc['record']   >= 3],
        ['t' => 'flame',  'l' => t('amici.b_streak_7'),     'v' => $sFc['record']   >= 7],
        ['t' => 'flame',  'l' => t('amici.b_streak_30'),    'v' => $sFc['record']   >= 30],
        ['t' => 'book',   'l' => t('amici.b_50_imparate'),      'v' => $carteImparate   >= 50],
        ['t' => 'book',   'l' => t('amici.b_100_imparate'),     'v' => $carteImparate   >= 100],
        ['t' => 'trophy', 'l' => t('amici.b_cat_completa'),   'v' => $catCompleta],
        ['t' => 'target', 'l' => t('amici.b_500_carte'),      'v' => $carteGiocate    >= 500],
        ['t' => 'star',   'l' => t('amici.b_1000_carte'),     'v' => $carteGiocate    >= 1000],
        ['t' => 'timer',  'l' => t('amici.b_primo_pomo'),         'v' => $sessioni        >= 1],
        ['t' => 'clock',  'l' => t('amici.b_10_ore'),        'v' => $focusTotale     >= 36000],
        ['t' => 'clock',  'l' => t('amici.b_50_ore'),        'v' => $focusTotale     >= 180000],
        ['t' => 'bolt',   'l' => t('amici.b_4_ore'),     'v' => $record          >= 14400],
        ['t' => 'flame',  'l' => t('amici.b_focus_7'), 'v' => $sPomo['record'] >= 7],
        ['t' => 'gem',    'l' => t('amici.b_giornata_perfetta'),      'v' => $sCombo['record'] >= 1],
        ['t' => 'gem',    'l' => t('amici.b_7_perfette'),    'v' => $sCombo['record'] >= 7],
    ];
    $badgeFatti = 0;
    foreach ($badge as $b) { if ($b['v']) { $badgeFatti++; } }

    /* ---------- USCITA ---------- */
    return [
        'uid'        => $uid,
        'username'   => $username,
        'iniziale'   => fr_iniziale($username),
        'gradiente'  => fr_gradiente($uid),

        'livello'    => $liv['livello'],
        'xp'         => $liv['xp'],
        'xp_next'    => $liv['xp_next'],
        'xp_pct'     => $liv['pct'],

        'streak_fc'          => $sFc['corrente'],
        'streak_fc_rec'      => $sFc['record'],
        'streak_pomo'        => $sPomo['corrente'],
        'streak_pomo_rec'    => $sPomo['record'],
        'streak_combo'       => $sCombo['corrente'],
        'streak_combo_rec'   => $sCombo['record'],

        'focus_oggi'      => $focusOggi,
        'focus_record'    => $record,
        'focus_record_d'  => $recordData,
        'focus_media_7'   => (int)round($tot7 / 7),
        'focus_media_30'  => (int)round($tot30 / 30),
        'focus_tot_7'     => $tot7,
        'focus_totale'    => $focusTotale,
        'focus_sessioni'  => $sessioni,
        'serie_7'         => $serie7,

        'carte_giocate'   => $carteGiocate,
        'carte_imparate'  => $carteImparate,
        'carte_totali'    => $carteTotali,
        'mastery'         => $mastery,
        'precisione'      => $precisione,

        'carte_24h'       => $carte24,
        'precisione_24h'  => $precisione24,
        'categorie_24h'   => count($categorie),
        'categorie'       => $categorie,

        'progetto_oggi'   => $progOggi,
        'progetto_sett'   => $progSett,

        'badge'           => $badge,
        'badge_fatti'     => $badgeFatti,
        'badge_totali'    => count($badge),
    ];
}


/* =========================================================================
   LE METRICHE DEL CONFRONTO — definite una volta sola, usate sia nel duello
   1 contro 1 sia nel confronto con tutti. 'su' = più alto è meglio.
   ========================================================================= */
function fr_metriche(): array {
    return [
        ['k' => 'livello',         'l' => t('amici.livello'),             'f' => 'num',    'i' => 'star'],
        ['k' => 'focus_oggi',      'l' => t('amici.focus_oggi'),       'f' => 'durata', 'i' => 'timer'],
        ['k' => 'streak_combo',    'l' => t('amici.streak_combo'),     'f' => 'giorni', 'i' => 'gem'],
        ['k' => 'streak_pomo',     'l' => t('amici.streak_pomo'),     'f' => 'giorni', 'i' => 'flame'],
        ['k' => 'streak_fc',       'l' => t('amici.streak_fc'),    'f' => 'giorni', 'i' => 'flame'],
        ['k' => 'focus_record',    'l' => t('amici.record_giorno'), 'f' => 'durata', 'i' => 'trophy'],
        ['k' => 'focus_media_7',   'l' => t('amici.media_sett'),     'f' => 'durata', 'i' => 'clock'],
        ['k' => 'focus_media_30',  'l' => t('amici.media_mese'),          'f' => 'durata', 'i' => 'clock'],
        ['k' => 'focus_totale',    'l' => t('amici.focus_totale'),        'f' => 'durata', 'i' => 'bolt'],
        ['k' => 'carte_24h',       'l' => t('amici.m_carte_24h'),     'f' => 'num',    'i' => 'book'],
        ['k' => 'precisione_24h',  'l' => t('amici.m_precisione_24h'),      'f' => 'pct',    'i' => 'target'],
        ['k' => 'categorie_24h',   'l' => t('amici.m_categorie_24h'), 'f' => 'num',    'i' => 'book'],
        ['k' => 'badge_fatti',     'l' => t('amici.m_badge'),     'f' => 'num',    'i' => 'trophy'],
    ];
}


/* =========================================================================
   CLASSIFICA GLOBALE — tutti gli utenti del database, per tempo di focus.
   $periodo: 'oggi' | 'settimana' | 'mese'
   ========================================================================= */
function fr_classifica(mysqli $c, string $periodo = 'oggi'): array {
    if ($periodo === 'settimana')  { $filtro = "ps.start_time >= (CURDATE() - INTERVAL 6 DAY)"; }
    elseif ($periodo === 'mese')  { $filtro = "ps.start_time >= (CURDATE() - INTERVAL 29 DAY)"; }
    else                          { $filtro = "DATE(ps.start_time) = CURDATE()"; }
    $out = [];
    $r = mysqli_query($c, "SELECT u.id, u.username, SUM(ps.duration_seconds) AS s, COUNT(*) AS n
        FROM pomodoro_sessions ps JOIN users u ON u.id = ps.user_id
        WHERE $filtro AND u.is_active = 1 AND " . FR_POMO_OK . "
        GROUP BY u.id, u.username
        HAVING s > 0
        ORDER BY s DESC, u.username ASC");
    $pos = 0;
    while ($r && ($row = mysqli_fetch_assoc($r))) {
        $out[] = [
            'pos'       => ++$pos,
            'uid'       => (int)$row['id'],
            'username'  => $row['username'],
            'iniziale'  => fr_iniziale($row['username']),
            'gradiente' => fr_gradiente((int)$row['id']),
            'sec'       => (int)$row['s'],
            'sessioni'  => (int)$row['n'],
        ];
    }
    return $out;
}

/* Quante persone stanno facendo focus in questo momento, su tutto il sito. */
function fr_quanti_in_focus(mysqli $c): int {
    $r = mysqli_query($c, "SELECT COUNT(DISTINCT user_id) AS n FROM pomodoro_sessions
                           WHERE end_time IS NULL AND start_time >= (NOW() - INTERVAL 12 HOUR)");
    return $r ? (int)mysqli_fetch_assoc($r)['n'] : 0;
}

