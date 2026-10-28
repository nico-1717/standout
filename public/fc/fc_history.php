<?php
/* fc_history.php - storico delle partite di flashcard.
 *
 * COM'E' FATTA
 * La pagina arriva senza dati e si riempie con richieste separate: le partite
 * in sospeso (sempre, qualunque sia il periodo), l'elenco del periodo a
 * pagine di SFC_PAGINA madri, e la vista Materie solo quando la si apre.
 * Cosi' la prima schermata compare subito anche con anni di storico, invece
 * di aspettare che il server abbia contato tutto.
 *
 * COSA VUOL DIRE "IN SOSPESO"
 * Una partita con sequenza (fc_study_sequence) che ha ancora posizioni non
 * chiuse. "Concludi" non la chiude per sempre: toglie solo le posizioni
 * aperte e mai risposte, e la partita si riprende quando si vuole, anche
 * dopo mesi. Le partite nate prima della sequenza non si possono riprendere
 * (fc_game.php non saprebbe quali carte dare): figurano con il loro
 * avanzamento ma senza "Riprendi". fc_sequence_backfill.php le recupera.
 *
 * PARTITE SENZA RISPOSTE
 * Una partita aperta e lasciata senza segnare nemmeno una carta non si
 * mostra, e dopo un'ora di inattivita' si elimina. L'ora di margine serve a
 * non cancellare quella che si sta giocando in un'altra scheda.
 *
 * I ripassi (partite con parent_id) non sono righe a se': stanno dentro la
 * partita da cui discendono, anche quando sono ripassi di un ripasso.
 */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/auth_check.php';

$current_user_id = (int)$utente_corrente['id'];
/* sec_db() DOPO auth_check: e' quest'ultimo a caricare security_core.php. */
$conn = sec_db();

/* Studio o lingua (?course=N): lo storico mostra solo le partite di quel
   tipo e di quel corso (fc_sql_partite), e le statistiche per categoria
   solo i mazzi di quel corso. Vedi fc_type.php. */
require_once __DIR__ . '/../../lib/fc/fc_type.php';
require_once __DIR__ . '/../../lib/fc/fc_ripresa.php';
fc_avvia($current_user_id);

define('SFC_PAGINA', 25);

/* ======================================================================
   FUNZIONI
   ====================================================================== */

function sfc_json($dati) {
    header('Content-Type: application/json; charset=utf-8');
    /* Le risposte cambiano a ogni partita giocata ma l'indirizzo e' lo
       stesso: senza no-store il browser puo' servire la copia vecchia (e'
       gia' successo con todo_api.php). */
    header('Cache-Control: no-store');
    echo json_encode($dati, JSON_UNESCAPED_UNICODE);
    exit();
}

/* Data AAAA-MM-GG valida o stringa vuota: finisce in una query come
   parametro, ma una data inventata darebbe risultati senza senso. */
function sfc_data($v) {
    $v = (string)$v;
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m)) return '';
    return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? $v : '';
}

/* Il colore finisce in un attributo style lato client: si accetta solo un
   esadecimale, qualunque altra cosa diventa il blu di sistema. */
function sfc_colore($c) {
    $c = trim((string)$c);
    return preg_match('/^#[0-9a-fA-F]{3,8}$/', $c) ? $c : '#007AFF';
}

/* Tutte le partite di flashcard dell'utente, senza conteggi: e' una tabella
   leggera, e serve intera per ricostruire le catene madre -> ripassi. */
function sfc_partite_utente(int $uid): array {
    $mappa = [];
    foreach (sec_all("SELECT id, parent_id, game_date, card_status, score, game_order, total_father_words
                        FROM fc_games
                       WHERE user_id = ? AND " . fc_sql_partite() . "
                       ORDER BY game_date DESC, id DESC", [$uid]) as $g) {
        $mappa[(int)$g['id']] = $g;
    }
    return $mappa;
}

/* Risale ai genitori finche' ce ne sono. Un ripasso la cui madre e' stata
   eliminata diventa radice di se stesso, invece di sparire. */
function sfc_radice(int $id, array $mappa): int {
    $visti = [$id => true];
    while (true) {
        $p = (int)($mappa[$id]['parent_id'] ?? 0);
        if ($p <= 0 || !isset($mappa[$p]) || isset($visti[$p])) return $id;
        $visti[$p] = true;
        $id = $p;
    }
}

/* I conteggi di un gruppo di partite, a blocchi da 500 per non costruire
   IN (...) enormi. Le quattro tabelle si leggono separate e non con una
   JOIN: hanno granularita' diverse (una riga per carta, per posizione, per
   sessione) e unite moltiplicherebbero le somme. */
function sfc_aggregati(array $ids): array {
    $agg = [];
    foreach ($ids as $id) {
        $agg[$id] = ['ok' => 0, 'ko' => 0, 'aperte' => 0, 'pend' => 0, 'prima_vuota' => 0, 'max_pos' => 0,
                     'ultima_giocata' => '', 'seq' => 0, 'secondi' => 0, 'sessioni' => 0, 'ultima_sessione' => '',
                     'cats' => []];
    }
    foreach (array_chunk($ids, 500) as $blocco) {
        $ph = implode(',', array_fill(0, count($blocco), '?'));

        /* "Titolo" chiude la posizione ma non e' una risposta: non conta ne'
           fra le giuste ne' fra le sbagliate. NULL e vuoto sono entrambi
           "ancora da rispondere". */
        foreach (sec_all("SELECT game_id,
                                 SUM(user_answer = 'Correct') AS ok,
                                 SUM(user_answer = 'Wrong') AS ko,
                                 COUNT(DISTINCT position) AS aperte,
                                 COUNT(DISTINCT CASE WHEN COALESCE(user_answer, '') = '' THEN position END) AS pend,
                                 MIN(CASE WHEN COALESCE(user_answer, '') = '' THEN position END) AS prima_vuota,
                                 MAX(position) AS max_pos,
                                 MAX(play_date) AS ultima
                            FROM " . fc_t('plays') . " WHERE game_id IN ($ph) GROUP BY game_id", $blocco) as $r) {
            $g = (int)$r['game_id'];
            $agg[$g]['ok'] = (int)$r['ok'];
            $agg[$g]['ko'] = (int)$r['ko'];
            $agg[$g]['aperte'] = (int)$r['aperte'];
            $agg[$g]['pend'] = (int)$r['pend'];
            $agg[$g]['prima_vuota'] = (int)$r['prima_vuota'];
            $agg[$g]['max_pos'] = (int)$r['max_pos'];
            $agg[$g]['ultima_giocata'] = (string)$r['ultima'];
        }

        foreach (sec_all("SELECT game_id, COUNT(*) AS n FROM " . fc_t('sequence') . "
                           WHERE game_id IN ($ph) GROUP BY game_id", $blocco) as $r) {
            $agg[(int)$r['game_id']]['seq'] = (int)$r['n'];
        }

        foreach (sec_all("SELECT game_id, SUM(duration_seconds) AS s, COUNT(*) AS n, MAX(updated_at) AS u
                            FROM fc_game_durations WHERE game_id IN ($ph) GROUP BY game_id", $blocco) as $r) {
            $g = (int)$r['game_id'];
            $agg[$g]['secondi'] = (int)$r['s'];
            $agg[$g]['sessioni'] = (int)$r['n'];
            $agg[$g]['ultima_sessione'] = (string)$r['u'];
        }

        foreach (sec_all("SELECT gfc.game_id, c.id, c.name, c.color
                            FROM " . fc_t('game_decks') . " gfc
                            JOIN " . fc_t('decks') . " c ON c.id = gfc.fc_category_id
                           WHERE gfc.game_id IN ($ph)
                           ORDER BY gfc.game_id, gfc.category_order, c.name", $blocco) as $r) {
            $agg[(int)$r['game_id']]['cats'][] = ['id' => (int)$r['id'], 'nome' => (string)$r['name'],
                                                  'colore' => sfc_colore($r['color'])];
        }
    }
    return $agg;
}

/* Una partita come la legge la pagina. */
function sfc_riga(array $g, array $a): array {
    $seq = $a['seq'];
    $chiuse = max(0, $a['aperte'] - $a['pend']);

    if ($seq > 0) {
        $totale = $seq;
        $sospesa = $chiuse < $seq;
    } else {
        /* Senza sequenza il totale vero non si puo' sapere: resta l'ultimo
           salvato da fc_game.php, e la partita non si puo' riprendere. */
        $totale = max((int)$g['total_father_words'], $a['aperte'], 1);
        $sospesa = false;
    }

    /* Dove riprendere: la prima posizione con una carta ancora vuota, oppure
       quella dopo l'ultima aperta. fc_game.php rimanda comunque alla prima
       vuota, ma senza questo "Riprendi" riaprirebbe la posizione 1. */
    $pos = $a['prima_vuota'] > 0 ? $a['prima_vuota'] : $a['max_pos'] + 1;
    if ($seq > 0 && ($pos > $seq || ($sospesa && $a['prima_vuota'] === 0))) {
        /* «ultima + 1» puo' cadere oltre la sequenza, o su una posizione gia'
           chiusa lasciandosene dietro di aperte: si chiede alla regola comune */
        $r = fc_ripresa_posizione((int)$g['id'], fc_is_vocab() ? 'vocab' : 'study');
        $pos = $r ?? min($pos, $seq);
    }

    $ultima = max((string)$g['game_date'], $a['ultima_giocata'], $a['ultima_sessione']);

    return [
        'id'        => (int)$g['id'],
        'data'      => (string)$g['game_date'],
        'ultima'    => $ultima,
        'cats'      => $a['cats'],
        'stato'     => in_array($g['card_status'], ['all', '0', '1'], true) ? $g['card_status'] : null,
        'ordine'    => $g['game_order'] === 'in_order' ? 'in_order' : 'random',
        'punteggio' => $g['score'] !== null ? round((float)$g['score'], 2) : null,
        'ok'        => $a['ok'],
        'ko'        => $a['ko'],
        'chiuse'    => min($chiuse, $totale),
        'totale'    => $totale,
        'sospesa'   => $sospesa,
        'pos'       => max(1, $pos),
        'secondi'   => $a['secondi'],
        'sessioni'  => $a['sessioni'],
        'ripasso'   => (int)($g['parent_id'] ?? 0) > 0,
    ];
}

/* LA STRADA FINO AL 100%
   Una partita con errori si ripassa: ogni ripasso ha solo le carte sbagliate
   in quello prima. Quando un ripasso finisce senza errori la partita e'
   "conquistata", e lo Storico la mostra in oro: e' il traguardo che si vuole
   far inseguire. Una partita finita senza errori al primo colpo e' "perfetta".
     catena        'perfetta' | 'conquistata' | 'aperta' | null (niente di finito)
     salita        la precisione di ogni passo, partita e ripassi in ordine
     da_sistemare  gli errori rimasti nell'ultimo passo finito
     ultimo_id     la partita da cui partire per il prossimo ripasso */
function sfc_catena(array $madre, array $ripassi): array {
    $membri = array_merge([$madre], $ripassi);
    $salita = [];
    foreach ($membri as $m) {
        $n = $m['ok'] + $m['ko'];
        $salita[] = $n ? (int)round($m['ok'] / $n * 100) : null;
    }
    $ultimo = null;
    foreach ($membri as $m) if (!$m['sospesa'] && $m['ok'] + $m['ko'] > 0) $ultimo = $m;
    if (!$ultimo) $stato = null;
    elseif (!$madre['sospesa'] && $madre['ko'] === 0 && $madre['ok'] > 0) $stato = 'perfetta';
    elseif ($ultimo['ko'] === 0 && $ultimo['id'] !== $madre['id']) $stato = 'conquistata';
    else $stato = 'aperta';
    return [
        'catena' => $stato,
        'salita' => $salita,
        'da_sistemare' => $ultimo ? $ultimo['ko'] : 0,
        'ultimo_id' => $ultimo ? $ultimo['id'] : $madre['id'],
    ];
}

/* Le righe finite di un elenco di madri: la madre con i suoi ripassi dentro.
   Tenuta fuori dall'endpoint perche' serve due volte, sulla prima pagina
   (tutte le madri del periodo) e sulle successive (solo quelle della
   pagina). */
function sfc_costruisci(array $madri, array $gruppi, array $mappa): array {
    $ids = [];
    foreach ($madri as $r) {
        $ids[] = $r;
        foreach ($gruppi[$r] ?? [] as $m) $ids[] = $m;
    }
    $agg = sfc_aggregati($ids);

    $out = [];
    foreach ($madri as $r) {
        $madre = sfc_riga($mappa[$r], $agg[$r]);

        $membri = $gruppi[$r] ?? [];
        usort($membri, function ($x, $y) use ($mappa) {
            return strcmp((string)$mappa[$x]['game_date'], (string)$mappa[$y]['game_date']) ?: ($x <=> $y);
        });
        $ripassi = [];
        foreach ($membri as $m) {
            $rip = sfc_riga($mappa[$m], $agg[$m]);
            if ($rip['ok'] + $rip['ko'] > 0) $ripassi[] = $rip;
        }

        $madre['ripassi'] = $ripassi;
        $madre['secondi_tot'] = $madre['secondi'] + array_sum(array_column($ripassi, 'secondi'));
        $out[] = $madre + sfc_catena($madre, $ripassi);
    }
    return $out;
}

/* Una partita e tutti i suoi discendenti. Si eliminano solo le righe di
   games: sequenza, giocate, sessioni e categorie se ne vanno da sole
   (ON DELETE CASCADE nel database). Prima si cancellavano solo i ripassi
   diretti, e un ripasso di un ripasso restava orfano. */
function fc_cancella_partita(int $id, int $uid): bool {
    if ($id <= 0 || !sec_one("SELECT id FROM fc_games WHERE id = ? AND user_id = ?", [$id, $uid])) return false;

    $tutti = [$id => true];
    $coda = [$id];
    while (!empty($coda)) {
        $ph = implode(',', array_fill(0, count($coda), '?'));
        $coda_nuova = [];
        foreach (sec_all("SELECT id FROM fc_games WHERE user_id = ? AND parent_id IN ($ph)",
                         array_merge([$uid], $coda)) as $r) {
            $f = (int)$r['id'];
            if (!isset($tutti[$f])) { $tutti[$f] = true; $coda_nuova[] = $f; }
        }
        $coda = $coda_nuova;
    }

    foreach (array_chunk(array_keys($tutti), 500) as $blocco) {
        $ph = implode(',', array_fill(0, count($blocco), '?'));
        sec_q("DELETE FROM fc_games WHERE user_id = ? AND id IN ($ph)", array_merge([$uid], $blocco));
    }
    return true;
}

/* Elimina le partite senza nessuna risposta, ferme da piu' di un'ora.
   Gira al massimo ogni mezz'ora per sessione: e' una lettura su tutto lo
   storico, non serve rifarla a ogni apertura. Una partita vuota con un
   ripasso che invece ha risposte si tiene, per non lasciarlo senza origine. */
function sfc_pulisci_vuote(int $uid): void {
    if (isset($_SESSION) && (int)($_SESSION['sfc_pulizia'] ?? 0) > time() - 1800) return;
    if (isset($_SESSION)) $_SESSION['sfc_pulizia'] = time();

    $vuote = [];
    foreach (sec_all("SELECT g.id
                        FROM fc_games g
                        LEFT JOIN (SELECT DISTINCT gp.game_id
                                     FROM " . fc_t('plays') . " gp JOIN fc_games x ON x.id = gp.game_id
                                    WHERE x.user_id = ? AND gp.user_answer IN ('Correct', 'Wrong')) r ON r.game_id = g.id
                        LEFT JOIN (SELECT sd.game_id, MAX(sd.updated_at) AS u
                                     FROM fc_game_durations sd JOIN fc_games y ON y.id = sd.game_id
                                    WHERE y.user_id = ? GROUP BY sd.game_id) s ON s.game_id = g.id
                       WHERE g.user_id = ? AND " . fc_sql_partite('g') . " AND r.game_id IS NULL
                         AND GREATEST(COALESCE(g.game_date, '1970-01-01'), COALESCE(s.u, '1970-01-01')) < NOW() - INTERVAL 1 HOUR",
                     [$uid, $uid, $uid]) as $r) {
        $vuote[(int)$r['id']] = true;
    }
    if (empty($vuote)) return;

    $figli = [];
    foreach (sec_all("SELECT id, parent_id FROM fc_games WHERE user_id = ? AND parent_id > 0", [$uid]) as $r) {
        $figli[(int)$r['parent_id']][] = (int)$r['id'];
    }

    $da_eliminare = [];
    foreach (array_keys($vuote) as $id) {
        $coda = $figli[$id] ?? [];
        $tutti_vuoti = true;
        $visti = [];
        while (!empty($coda)) {
            $f = array_pop($coda);
            if (isset($visti[$f])) continue;
            $visti[$f] = true;
            if (!isset($vuote[$f])) { $tutti_vuoti = false; break; }
            foreach ($figli[$f] ?? [] as $n) $coda[] = $n;
        }
        if ($tutti_vuoti) $da_eliminare[] = $id;
    }

    foreach (array_chunk($da_eliminare, 500) as $blocco) {
        $ph = implode(',', array_fill(0, count($blocco), '?'));
        sec_q("DELETE FROM fc_games WHERE user_id = ? AND id IN ($ph)", array_merge([$uid], $blocco));
    }
}

/* Carte risposte per giorno nel periodo, raggruppate in al massimo una
   sessantina di barre: per giorno, per settimana o per mese a seconda di
   quanto e' lungo il periodo, altrimenti su "Tutto" le barre sarebbero
   migliaia e illeggibili. */
function sfc_attivita(int $uid, string $da, string $a): array {
    $sql = "SELECT DATE(gp.play_date) AS d, COUNT(*) AS n
              FROM " . fc_t('plays') . " gp JOIN fc_games g ON g.id = gp.game_id
             WHERE g.user_id = ? AND " . fc_sql_partite('g') . "
               AND gp.user_answer IN ('Correct', 'Wrong') AND gp.play_date IS NOT NULL";
    $par = [$uid];
    if ($da !== '') { $sql .= " AND gp.play_date >= ?"; $par[] = $da . ' 00:00:00'; }
    if ($a !== '')  { $sql .= " AND gp.play_date < DATE_ADD(?, INTERVAL 1 DAY)"; $par[] = $a; }
    $sql .= " GROUP BY d ORDER BY d";

    $per_giorno = [];
    foreach (sec_all($sql, $par) as $r) $per_giorno[(string)$r['d']] = (int)$r['n'];

    $oggi = date('Y-m-d');
    $inizio = $da !== '' ? $da : (array_key_first($per_giorno) ?? $oggi);
    $fine = $a !== '' ? $a : $oggi;
    if ($inizio > $fine) $inizio = $fine;

    $t0 = new DateTime($inizio);
    $t1 = new DateTime($fine);
    $giorni = (int)$t0->diff($t1)->days + 1;
    $passo = $giorni <= 62 ? 'giorno' : ($giorni <= 434 ? 'settimana' : 'mese');

    $barre = [];
    $cur = clone $t0;
    while ($cur <= $t1) {
        if ($passo === 'giorno') {
            $chiave = $cur->format('Y-m-d');
        } elseif ($passo === 'settimana') {
            $chiave = (clone $t0)->modify('+' . (intdiv((int)$t0->diff($cur)->days, 7) * 7) . ' days')->format('Y-m-d');
        } else {
            $chiave = $cur->format('Y-m-01');
        }
        if (!isset($barre[$chiave])) $barre[$chiave] = 0;
        $barre[$chiave] += $per_giorno[$cur->format('Y-m-d')] ?? 0;
        $cur->modify('+1 day');
    }

    $out = [];
    foreach ($barre as $d => $n) $out[] = ['d' => $d, 'n' => $n];
    return ['passo' => $passo, 'barre' => $out];
}

/* ======================================================================
   ENDPOINT
   ====================================================================== */

/* Eliminazione: in POST con token, perche' cancella. Prima era un GET, e
   bastava un tag immagine su un altro sito per far sparire una partita. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['azione'] ?? '') === 'elimina') {
    sec_guard_write($current_user_id, 'fc_history', 0, 60, 60);
    sfc_json(['success' => fc_cancella_partita(sec_int($_POST['id'] ?? 0, 1), $current_user_id)]);
}

/* ELIMINARE UN RIPASSO (non la partita intera). Solo un ripasso: una
   partita madre si elimina con "elimina" qui sopra, che si porta via tutto.
   Risponde con la riga della madre ricalcolata (catena, barre, ripassi), cosi'
   la pagina ridisegna il dettaglio aperto senza ricaricare l'elenco. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['azione'] ?? '') === 'elimina_ripasso') {
    sec_guard_write($current_user_id, 'fc_history', 0, 60, 60);
    $id = sec_int($_POST['id'] ?? 0, 1);
    $mappa = sfc_partite_utente($current_user_id);
    if (!isset($mappa[$id]) || (int)($mappa[$id]['parent_id'] ?? 0) <= 0) sfc_json(['success' => false]);
    $radice = sfc_radice($id, $mappa);
    if (!fc_cancella_partita($id, $current_user_id)) sfc_json(['success' => false]);

    $mappa = sfc_partite_utente($current_user_id);
    $membri = [];
    foreach ($mappa as $gid => $g) {
        if ($gid !== $radice && sfc_radice($gid, $mappa) === $radice) $membri[] = $gid;
    }
    $righe = isset($mappa[$radice]) ? sfc_costruisci([$radice], [$radice => $membri], $mappa) : [];
    sfc_json(['success' => true, 'madre' => $radice, 'riga' => $righe[0] ?? null]);
}

$ajax = sec_enum($_GET['ajax'] ?? null, ['lista', 'sospese', 'materie'], '');

if ($ajax === 'lista') {
    $da      = sfc_data($_GET['da'] ?? '');
    $a       = sfc_data($_GET['a'] ?? '');
    $stato   = sec_enum($_GET['stato'] ?? null, ['tutte', 'sospese', 'concluse'], 'tutte');
    $tipo    = sec_enum($_GET['tipo'] ?? null, ['tutti', 'all', '0', '1'], 'tutti');
    $ordina  = sec_enum($_GET['ordina'] ?? null, ['recenti', 'lunghe', 'errori', 'precisione'], 'recenti');
    $materia = max(0, sec_int($_GET['materia'] ?? 0, 0));
    $offset  = max(0, sec_int($_GET['offset'] ?? 0, 0));

    $mappa = sfc_partite_utente($current_user_id);

    /* Madri del periodo (per data della madre) e i loro ripassi, qualunque
       sia la data di questi: un ripasso fatto il giorno dopo appartiene
       comunque a quella partita. */
    $gruppi = [];
    foreach ($mappa as $id => $g) {
        $r = sfc_radice($id, $mappa);
        if (!isset($gruppi[$r])) $gruppi[$r] = [];
        if ($r !== $id) $gruppi[$r][] = $id;
    }

    /* Filtri e ordinamenti si applicano a tutte le madri del periodo, quindi
       la prima pagina deve comunque leggere i conteggi di tutte. L'elenco
       ordinato che ne esce si tiene da parte: le pagine successive leggono
       i conteggi delle sole 25 partite che mostrano, invece di rifare il
       lavoro a ogni scorrimento. Se l'elenco non c'e' piu' - sessione
       scaduta, filtri cambiati a meta' - si ricalcola tutto: e' piu' lento,
       non sbagliato. */
    $chiave = md5(implode('|', [$da, $a, $stato, $tipo, $ordina, $materia]));
    $salvato = $_SESSION['sfc_pag'] ?? null;

    if ($offset > 0 && is_array($salvato) && ($salvato['k'] ?? '') === $chiave
        && (int)($salvato['t'] ?? 0) > time() - 900
        && $offset + SFC_PAGINA <= count($salvato['ids'])) {

        /* Una partita eliminata da un'altra scheda non c'e' piu' in $mappa:
           si salta, invece di far fallire tutta la pagina. */
        $pagina = [];
        foreach (array_slice($salvato['ids'], $offset, SFC_PAGINA) as $id) {
            if (isset($mappa[(int)$id])) $pagina[] = (int)$id;
        }
        sfc_json([
            'righe' => sfc_costruisci($pagina, $gruppi, $mappa),
            'altre' => count($salvato['ids']) > $offset + SFC_PAGINA,
        ]);
    }

    $madri = [];
    foreach ($gruppi as $r => $membri) {
        $giorno = substr((string)$mappa[$r]['game_date'], 0, 10);
        if ($da !== '' && $giorno < $da) continue;
        if ($a !== '' && $giorno > $a) continue;
        $madri[] = $r;
    }

    /* Il filtro per materia vale per la categoria scelta e tutte le sue
       sottocartelle: scegliendo "Analisi" si vogliono anche le partite
       fatte solo su "Integrali". */
    $ramo = [];
    if ($materia > 0) {
        $figli_cat = [];
        [$fcW, $fcP] = fc_filtro_corso();
        foreach (sec_all("SELECT id, parent_id FROM " . fc_t('decks') . " WHERE user_id = ?" . $fcW, array_merge([$current_user_id], $fcP)) as $c) {
            $figli_cat[(int)$c['parent_id']][] = (int)$c['id'];
        }
        $coda = [$materia];
        while (!empty($coda)) {
            $c = array_pop($coda);
            if (isset($ramo[$c])) continue;
            $ramo[$c] = true;
            foreach ($figli_cat[$c] ?? [] as $f) $coda[] = $f;
        }
        /* ...e le cartelle che la contengono: una partita su "Analisi" ha
           giocato anche le carte di "Integrali". */
        $padre_di = [];
        foreach ($figli_cat as $pid => $ff) foreach ($ff as $f) $padre_di[$f] = (int)$pid;
        $sfc_su = $padre_di[$materia] ?? 0; $sfc_g = 0;
        while ($sfc_su > 0 && $sfc_g++ < 40) { $ramo[$sfc_su] = true; $sfc_su = $padre_di[$sfc_su] ?? 0; }
    }

    /* Le madri si ordinano per la LORO data: $gruppi segue l'ordine in cui
       si incontra il primo membro, e un ripasso recente di una partita
       vecchia la porterebbe in cima. */
    usort($madri, function ($x, $y) use ($mappa) {
        return strcmp((string)$mappa[$y]['game_date'], (string)$mappa[$x]['game_date']) ?: ($y <=> $x);
    });

    $righe = [];
    foreach (sfc_costruisci($madri, $gruppi, $mappa) as $madre) {
        /* Una madre senza risposte si mostra solo se ha ripassi con risposte
           (caso raro, ma altrimenti quei ripassi sparirebbero). */
        if ($madre['ok'] + $madre['ko'] === 0 && empty($madre['ripassi'])) continue;

        if ($tipo !== 'tutti' && $madre['stato'] !== $tipo) continue;

        if ($materia > 0) {
            $dentro = false;
            foreach ($madre['cats'] as $c) { if (isset($ramo[$c['id']])) { $dentro = true; break; } }
            if (!$dentro) continue;
        }

        $qualcuna_sospesa = $madre['sospesa'];
        foreach ($madre['ripassi'] as $rip) { if ($rip['sospesa']) $qualcuna_sospesa = true; }
        if ($stato === 'sospese' && !$qualcuna_sospesa) continue;
        if ($stato === 'concluse' && $qualcuna_sospesa) continue;

        $righe[] = $madre;
    }

    if ($ordina === 'lunghe') {
        usort($righe, function ($x, $y) { return $y['secondi_tot'] <=> $x['secondi_tot'] ?: strcmp($y['data'], $x['data']); });
    } elseif ($ordina === 'errori') {
        usort($righe, function ($x, $y) { return $y['ko'] <=> $x['ko'] ?: strcmp($y['data'], $x['data']); });
    } elseif ($ordina === 'precisione') {
        usort($righe, function ($x, $y) {
            $px = ($x['ok'] + $x['ko']) > 0 ? $x['ok'] / ($x['ok'] + $x['ko']) : 1;
            $py = ($y['ok'] + $y['ko']) > 0 ? $y['ok'] / ($y['ok'] + $y['ko']) : 1;
            return $px <=> $py ?: strcmp($y['data'], $x['data']);
        });
    }
    /* "recenti" e' gia' l'ordine di $madri. */

    /* L'elenco si tiene fino a 1500 partite: oltre, il peso in sessione non
       vale il risparmio, e chi scorre cosi' in fondo pagherebbe comunque il
       calcolo completo. */
    if (isset($_SESSION)) {
        $_SESSION['sfc_pag'] = ['k' => $chiave, 't' => time(),
                                'ids' => array_slice(array_column($righe, 'id'), 0, 1500)];
    }

    $risposta = [
        'righe' => array_slice($righe, $offset, SFC_PAGINA),
        'altre' => count($righe) > $offset + SFC_PAGINA,
    ];

    /* Riepilogo e barre solo con la prima pagina: per le successive non
       cambiano, e ricalcolarle rallenterebbe proprio lo scorrimento. */
    if ($offset === 0) {
        $ok = 0; $ko = 0; $sec = 0;
        foreach ($righe as $m) {
            $ok += $m['ok']; $ko += $m['ko']; $sec += $m['secondi_tot'];
            foreach ($m['ripassi'] as $rip) { $ok += $rip['ok']; $ko += $rip['ko']; }
        }
        $risposta['riepilogo'] = ['partite' => count($righe), 'secondi' => $sec, 'ok' => $ok, 'ko' => $ko];
        $risposta['attivita'] = sfc_attivita($current_user_id, $da, $a);
        $risposta['mai'] = empty($mappa);
    }

    sfc_json($risposta);
}

if ($ajax === 'sospese') {
    $mappa = sfc_partite_utente($current_user_id);

    /* Solo una partita con sequenza puo' essere in sospeso: i conteggi si
       fanno solo su quelle, non su tutto lo storico. */
    $ids = [];
    foreach (sec_all("SELECT DISTINCT s.game_id
                        FROM " . fc_t('sequence') . " s JOIN fc_games g ON g.id = s.game_id
                       WHERE g.user_id = ?", [$current_user_id]) as $r) {
        if (isset($mappa[(int)$r['game_id']])) $ids[] = (int)$r['game_id'];
    }

    $agg = sfc_aggregati($ids);
    $righe = [];
    foreach ($ids as $id) {
        $riga = sfc_riga($mappa[$id], $agg[$id]);
        if ($riga['sospesa'] && $riga['ok'] + $riga['ko'] > 0) $righe[] = $riga;
    }
    usort($righe, function ($x, $y) { return strcmp($y['ultima'], $x['ultima']); });

    sfc_json(['righe' => array_slice($righe, 0, 20), 'totale' => count($righe)]);
}

if ($ajax === 'materie') {
    $da = sfc_data($_GET['da'] ?? '');
    $a  = sfc_data($_GET['a'] ?? '');
    $da_ts = $da !== '' ? $da . ' 00:00:00' : '1970-01-01 00:00:00';
    $a_ts  = $a !== '' ? $a : date('Y-m-d');

    /* La colonna archived arriva con migrazione_categorie_archivio.sql: se
       non c'e' ancora la pagina funziona lo stesso, senza nascondere niente. */
    $con_archivio = (bool)sec_one("SHOW COLUMNS FROM " . fc_t('decks') . " LIKE 'archived'");
    $cats = [];
    foreach (sec_all("SELECT id, name, parent_id, color" . ($con_archivio ? ", archived" : "") . "
                        FROM " . fc_t('decks') . " WHERE user_id = ?" . fc_filtro_corso()[0] . " ORDER BY name",
                     array_merge([$current_user_id], fc_filtro_corso()[1])) as $c) {
        $cats[(int)$c['id']] = [
            'id' => (int)$c['id'], 'nome' => (string)$c['name'], 'padre' => (int)$c['parent_id'],
            'colore' => sfc_colore($c['color']), 'archiviata' => $con_archivio && (int)$c['archived'] === 1,
            'carte' => 0, 'imparate' => 0, 'ok' => 0, 'ko' => 0, 'secondi' => 0, 'ultima' => '',
        ];
    }

    foreach (sec_all("SELECT i.category_id AS cat, COUNT(*) AS carte, SUM(u.fc_image_id IS NOT NULL) AS imparate
                        FROM " . fc_t('cards') . " i
                        JOIN " . fc_t('decks') . " c ON c.id = i.category_id AND c.user_id = ?
                        LEFT JOIN " . fc_t('learnt') . " u ON u.fc_image_id = i.id AND u.is_child = 0 AND u.user_id = ?
                       GROUP BY i.category_id", [$current_user_id, $current_user_id]) as $r) {
        if (!isset($cats[(int)$r['cat']])) continue;
        $cats[(int)$r['cat']]['carte'] = (int)$r['carte'];
        $cats[(int)$r['cat']]['imparate'] = (int)$r['imparate'];
    }

    /* Ogni risposta va alla categoria della sua carta, non a quelle della
       partita: una partita su tre materie non deve regalare le stesse
       risposte a tutte e tre. Il figlio prende la categoria del padre. */
    $join_cat = "FROM " . fc_t('plays') . " gp
                 JOIN fc_games g ON g.id = gp.game_id
                 LEFT JOIN " . fc_t('cards') . " i ON i.id = gp.fc_image_id
                 LEFT JOIN " . fc_t('subcards') . " ch ON ch.id_child = gp.fc_child_id
                 LEFT JOIN " . fc_t('cards') . " ip ON ip.id = ch.parent_id
                WHERE g.user_id = ? AND " . fc_sql_partite('g') . " AND gp.user_answer IN ('Correct', 'Wrong')";

    $risposte_partita = [];
    foreach (sec_all("SELECT gp.game_id, COALESCE(i.category_id, ip.category_id) AS cat,
                             SUM(gp.user_answer = 'Correct') AS ok, SUM(gp.user_answer = 'Wrong') AS ko,
                             COUNT(DISTINCT gp.position) AS pos_risp
                      $join_cat
                        AND gp.play_date >= ? AND gp.play_date < DATE_ADD(?, INTERVAL 1 DAY)
                      GROUP BY gp.game_id, cat", [$current_user_id, $da_ts, $a_ts]) as $r) {
        $cat = (int)$r['cat'];
        if (!isset($cats[$cat])) continue;
        $cats[$cat]['ok'] += (int)$r['ok'];
        $cats[$cat]['ko'] += (int)$r['ko'];
        $risposte_partita[(int)$r['game_id']][$cat] = (int)$r['ok'] + (int)$r['ko'];
        /* Il completamento per partita e' posizioni distinte risposte / carte totali della categoria.
           Si tiene solo il massimo del periodo: basta un 100% per tenerla al 100%. */
        if ($cats[$cat]['carte'] > 0) {
            $comp = min(1.0, (int)$r['pos_risp'] / $cats[$cat]['carte']);
            if (!isset($cats[$cat]['completion']) || $comp > $cats[$cat]['completion']) {
                $cats[$cat]['completion'] = $comp;
            }
        }
    }

    /* Il tempo e' registrato per partita, non per carta: si divide fra le
       categorie in proporzione alle risposte date in quella partita. */
    /* Ogni categoria parte con completion=0; viene alzata sopra solo se giocata nel periodo. */
    foreach ($cats as $cid => &$_cc) { if (!isset($_cc['completion'])) $_cc['completion'] = 0.0; }
    unset($_cc);

    foreach (sec_all("SELECT sd.game_id, SUM(sd.duration_seconds) AS s
                        FROM fc_game_durations sd JOIN fc_games g ON g.id = sd.game_id
                       WHERE g.user_id = ? AND sd.start_time >= ? AND sd.start_time < DATE_ADD(?, INTERVAL 1 DAY)
                       GROUP BY sd.game_id", [$current_user_id, $da_ts, $a_ts]) as $r) {
        $per_cat = $risposte_partita[(int)$r['game_id']] ?? [];
        $tot = array_sum($per_cat);
        if ($tot <= 0) continue;
        foreach ($per_cat as $cat => $n) $cats[$cat]['secondi'] += (int)round((int)$r['s'] * $n / $tot);
    }

    foreach (sec_all("SELECT COALESCE(i.category_id, ip.category_id) AS cat, MAX(gp.play_date) AS ultima
                      $join_cat
                      GROUP BY cat", [$current_user_id]) as $r) {
        if (isset($cats[(int)$r['cat']])) $cats[(int)$r['cat']]['ultima'] = (string)$r['ultima'];
    }

    sfc_json(['categorie' => array_values($cats)]);
}

/* ======================================================================
   PAGINA
   ====================================================================== */

sfc_pulisci_vuote($current_user_id);

$lingua_html = substr((string)T('fc.sfc_locale'), 0, 2) ?: 'it';
?>
<!DOCTYPE html>
<html lang="<?php echo sec_h($lingua_html); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=0, viewport-fit=cover">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="format-detection" content="telephone=no">
<meta name="theme-color" content="#F2F2F7" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#000000" media="(prefers-color-scheme: dark)">
<meta name="csrf-token" content="<?php echo sec_h(sec_csrf_token()); ?>">
<title><?php te('fc.sfc_titolo_pagina'); ?></title>
<link rel="stylesheet" href="<?php echo PWA_BASE; ?>_assets/ui/base.css?v=<?php echo @filemtime(__DIR__ . '/../_assets/ui/base.css') ?: 1; ?>">
<link rel="stylesheet" href="<?php echo PWA_BASE; ?>fc/fc_history.css?v=<?php echo @filemtime(__DIR__ . '/fc_history.css') ?: 1; ?>">
</head>
<body>
<?php include __DIR__ . '/../partials/sidebar.php'; ?>

<div class="sfc">

    <header class="sfc-hdr">
        <div class="sfc-hdr-top">
            <div class="sfc-hdr-titoli">
                <h1 class="sfc-titolo"><?php te('fc.sfc_storico'); ?></h1>
                <div class="sfc-sub" id="sfcSub">&nbsp;</div>
            </div>
            <div class="sfc-hdr-azioni">
                <button type="button" class="sfc-icon-btn" id="sfcFiltriBtn" aria-label="<?php te('fc.sfc_filtri'); ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 6h16"/><path d="M7 12h10"/><path d="M10 18h4"/></svg>
                    <span class="sfc-badge" id="sfcBadge" hidden>0</span>
                </button>
                <?php /* Lo stesso tasto play di categories_fc, con l'anello che
                         pulsa: e' l'invito a giocare in tutta la sezione, e deve
                         essere riconoscibile allo stesso modo ovunque. */ ?>
                <a href="<?php echo sec_h(fc_url('fc_game_setup.php')); ?>" class="hdr-play-btn" aria-label="<?php te('fc.cg_gioca'); ?>" title="<?php te('fc.cg_gioca'); ?>">
                    <span class="hdr-play-ring" aria-hidden="true"></span>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 4v16l13-8L7 4Z"/></svg>
                </a>
            </div>
        </div>
        <div class="sfc-chips" id="sfcChips"></div>
    </header>

    <section class="sfc-riprendi" id="sfcRiprendi" hidden>
        <div class="sfc-sezione-tit"><span><?php te('fc.sfc_da_riprendere'); ?></span><span class="sfc-sezione-n" id="sfcRiprendiN"></span></div>
        <div class="sfc-carosello" id="sfcCarosello"></div>
    </section>

    <section class="sfc-periodo">
        <div class="sfc-seg-riga">
            <div class="sfc-seg" id="sfcPeriodo">
                <button type="button" data-v="7"><?php te('fc.sfc_per_7'); ?></button>
                <button type="button" data-v="30"><?php te('fc.sfc_per_30'); ?></button>
                <button type="button" data-v="90"><?php te('fc.sfc_per_90'); ?></button>
                <button type="button" data-v="0"><?php te('fc.sfc_per_tutto'); ?></button>
            </div>
            <button type="button" class="sfc-icon-btn piccolo" id="sfcDateBtn" aria-label="<?php te('fc.sfc_date'); ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="17" rx="3"/><path d="M3 9h18M8 2v4M16 2v4"/></svg>
            </button>
        </div>
        <div class="sfc-date" id="sfcDate" hidden>
            <label><span><?php te('fc.sfc_dal'); ?></span><input type="date" id="sfcDa"></label>
            <label><span><?php te('fc.sfc_al'); ?></span><input type="date" id="sfcA"></label>
        </div>

        <div class="sfc-riepilogo">
            <div class="sfc-metrica"><div class="sfc-metrica-lbl"><?php te('fc.sfc_m_partite'); ?></div><div class="sfc-metrica-val" id="sfcMPartite">–</div></div>
            <div class="sfc-metrica"><div class="sfc-metrica-lbl"><?php te('fc.sfc_m_tempo'); ?></div><div class="sfc-metrica-val" id="sfcMTempo">–</div></div>
            <div class="sfc-metrica"><div class="sfc-metrica-lbl"><?php te('fc.sfc_m_precisione'); ?></div><div class="sfc-metrica-val" id="sfcMPrecisione">–</div></div>
        </div>
        <div class="sfc-attivita" id="sfcAttivita" aria-hidden="true"></div>
    </section>

    <div class="sfc-vista-riga">
        <div class="sfc-seg" id="sfcVista">
            <button type="button" data-v="partite"><?php te('fc.sfc_vista_partite'); ?></button>
            <button type="button" data-v="materie"><?php te('fc.sfc_vista_materie'); ?></button>
        </div>
        <button type="button" class="sfc-icon-btn piccolo" id="sfcDensita" aria-label="<?php te('fc.sfc_densita'); ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 5h16M4 10h16M4 15h10M4 20h7"/></svg>
        </button>
    </div>

    <section id="sfcPanePartite">
        <div class="sfc-lista" id="sfcLista"></div>
        <div class="sfc-sentinella" id="sfcSentinella"></div>
        <div class="sfc-vuoto" id="sfcVuoto" hidden></div>
    </section>

    <section id="sfcPaneMaterie" hidden>
        <div id="sfcMaterie"></div>
    </section>
</div>

<!-- FOGLIO: filtri -->
<div class="sfc-sheet-overlay" id="sfcFiltriOverlay">
    <div class="sfc-sheet" role="dialog" aria-modal="true">
        <div class="sfc-sheet-maniglia"></div>
        <div class="sfc-sheet-testa"><h3><?php te('fc.sfc_filtri'); ?></h3><button type="button" class="sfc-sheet-fine" id="sfcFiltriFine"><?php te('fc.sfc_fine'); ?></button></div>

        <div class="sfc-campo">
            <label><?php te('fc.sfc_f_stato'); ?></label>
            <div class="sfc-seg" data-filtro="stato">
                <button type="button" data-v="tutte"><?php te('fc.sfc_f_stato_tutte'); ?></button>
                <button type="button" data-v="sospese"><?php te('fc.sfc_in_sospeso'); ?></button>
                <button type="button" data-v="concluse"><?php te('fc.sfc_f_stato_concluse'); ?></button>
            </div>
        </div>

        <div class="sfc-campo">
            <label><?php te('fc.sfc_f_tipo'); ?></label>
            <div class="sfc-seg" data-filtro="tipo">
                <button type="button" data-v="tutti"><?php te('fc.sfc_f_tipo_tutti'); ?></button>
                <button type="button" data-v="0"><?php te('fc.sfc_st_0'); ?></button>
                <button type="button" data-v="1"><?php te('fc.sfc_st_1'); ?></button>
                <button type="button" data-v="all"><?php te('fc.sfc_st_all'); ?></button>
            </div>
        </div>

        <div class="sfc-campo">
            <label><?php te('fc.sfc_f_ordina'); ?></label>
            <div class="sfc-seg a-capo" data-filtro="ordina">
                <button type="button" data-v="recenti"><?php te('fc.sfc_o_recenti'); ?></button>
                <button type="button" data-v="lunghe"><?php te('fc.sfc_o_lunghe'); ?></button>
                <button type="button" data-v="errori"><?php te('fc.sfc_o_errori'); ?></button>
                <button type="button" data-v="precisione"><?php te('fc.sfc_o_precisione'); ?></button>
            </div>
        </div>

        <div class="sfc-campo">
            <label><?php te('fc.sfc_f_materia'); ?></label>
            <?php
                /* Stesse variabili con cui lo includono categories_fc e
                   visualizzazione_fc; il valore scelto arriva con l'evento
                   'categoryselected', filtrato per uid. */
                $cs_id = 'historyFilter';
                $cs_title = T('fc.sfc_f_materia');
                $cs_show_all_option = true;
                $cs_all_option_label = T('fc.cs_tutte_materie');
                $cs_allow_create = false;
                include __DIR__ . '/fc_deck_selector.php';
            ?>
        </div>

        <button type="button" class="sfc-sheet-reset" id="sfcReset"><?php te('fc.sfc_reimposta'); ?></button>
    </div>
</div>

<!-- AVVISO: conferma eliminazione -->
<div class="sfc-alert-overlay" id="sfcAlert">
    <div class="sfc-alert" role="alertdialog" aria-modal="true">
        <div class="sfc-alert-icona"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg></div>
        <h4><?php te('fc.sfc_elimina_tit'); ?></h4>
        <p><?php te('fc.sfc_elimina_txt'); ?></p>
        <div class="sfc-alert-azioni">
            <button type="button" class="sfc-alert-btn" id="sfcAlertNo"><?php te('comune.annulla'); ?></button>
            <button type="button" class="sfc-alert-btn distruttivo" id="sfcAlertSi"><?php te('comune.elimina'); ?></button>
        </div>
    </div>
</div>

<div class="sfc-toast" id="sfcToast" role="status"></div>

<?php echo standout_i18n_json(['comune', 'fc', 'vocab', 'fcv']); ?>
<?php echo fc_boot_json(); ?>
<script nonce="<?php echo sec_nonce(); ?>">
(function () {
    'use strict';

    var SPRING = 'cubic-bezier(.32,.72,0,1)';
    /* Velocita' in pixel al secondo, non in secondi fissi: tutti i titoli
       scorrono alla stessa velocita', quindi uno lungo il doppio ci mette il
       doppio ad arrivare in fondo. Arrivato alla fine si ferma qualche
       secondo (si legge l'ultima parola), poi riparte ISTANTANEAMENTE
       dall'inizio, si ferma un attimo e ricomincia.
       Prima il ritorno partiva 450 ms dopo l'INIZIO dello scorrimento invece
       che dopo la sua fine: i titoli ripartivano senza mai arrivare in fondo. */
    var TICKER_PX_S = 30;            /* velocita' di scorrimento, uguale per tutti */
    var TICKER_PAUSA_INIZIO = 1200;  /* ms fermo all'inizio prima di scorrere */
    var TICKER_PAUSA_FINE = 2500;    /* ms fermo in fondo prima di ripartire */

    function avviaTickerEl(inner, over) {
        /* Un numero di generazione invece di clearTimeout su piu' id: ogni
           passo del ciclo controlla di essere ancora quello giusto, cosi'
           fermare il ciclo (o farne ripartire un altro) e' un solo
           incremento, senza dover tracciare quale timeout sia in corso. */
        var gen = inner.__tickerGen = (inner.__tickerGen || 0) + 1;   /* ++undefined darebbe NaN, e NaN !== NaN fermerebbe tutto */
        function passo(fn, ms) { return setTimeout(function () { if (inner.__tickerGen === gen) fn(); }, ms); }
        function ciclo() {
            /* ritorno all'inizio: istantaneo, senza transizione */
            inner.style.transition = 'none';
            inner.style.transform = 'translateX(0)';
            void inner.offsetWidth; /* forza il ridisegno prima della prossima transition */
            passo(function () {
                var msAndata = Math.round(over / TICKER_PX_S * 1000);
                inner.style.transition = 'transform ' + msAndata + 'ms linear';
                inner.style.transform = 'translateX(-' + over + 'px)';
                /* la pausa in fondo parte quando lo scorrimento e' FINITO */
                passo(ciclo, msAndata + TICKER_PAUSA_FINE);
            }, TICKER_PAUSA_INIZIO);
        }
        ciclo();
    }
    function fermaTickerEl(inner) {
        inner.__tickerGen = (inner.__tickerGen || 0) + 1;   /* invalida il ciclo in corso */
        inner.style.transition = 'none';
        inner.style.transform = 'translateX(0)';
    }

    var tickerObs = null;
    function initTicker() {
        if (!('IntersectionObserver' in window)) return;
        if (tickerObs) tickerObs.disconnect();
        tickerObs = new IntersectionObserver(function (voci) {
            voci.forEach(function (v) {
                var wrap = v.target, inner = wrap.querySelector('.sfc-nome-inner');
                if (!inner) return;
                var over = inner.scrollWidth - wrap.clientWidth;
                /* se sta gia' scorrendo non lo si fa ripartire da capo: un
                   secondo avviso dell'osservatore lo interromperebbe a meta' */
                if (over > 6 && v.isIntersecting) {
                    if (!inner.__tickerOn) { inner.__tickerOn = true; avviaTickerEl(inner, over); }
                } else {
                    inner.__tickerOn = false; fermaTickerEl(inner);
                }
            });
        }, { threshold: 0.5 });
    }
    function osservaTicker(root) {
        if (!tickerObs) return;
        (root || document).querySelectorAll('.sfc-nome-wrap:not([data-obs])').forEach(function (w) {
            w.setAttribute('data-obs', '1'); tickerObs.observe(w);
        });
    }
    var SNAP = 'cubic-bezier(.24,1.3,.35,1)';
    var APERTO_X = -88;              /* quanto scorre la riga per scoprire "Elimina" */
    var PREF = 'sfc_pref';
    var LOCALE = T('fc.sfc_locale') || 'it-IT';
    var CSRF = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

    var ICONE = {
        trofeo: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8 21h8M12 17v4M7 4h10v5a5 5 0 0 1-10 0Z"/><path d="M7 6H4a3 3 0 0 0 3 4M17 6h3a3 3 0 0 1-3 4"/></svg>',
        stella: '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="m12 2.5 2.9 6.2 6.8.8-5 4.7 1.3 6.7L12 17.6l-6 3.3 1.3-6.7-5-4.7 6.8-.8Z"/></svg>',
        bersaglio: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1.5" fill="currentColor"/></svg>',
        play: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 4v16l13-8L7 4Z"/></svg>',
        cestino: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>',
        ripasso: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17 2l4 4-4 4"/><path d="M3 11v-1a4 4 0 0 1 4-4h14"/><path d="M7 22l-4-4 4-4"/><path d="M21 13v1a4 4 0 0 1-4 4H3"/></svg>',
        chev: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg>',
        x: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>',
        spunta: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>',
        risultati: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/></svg>',
        vuoto: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="3"/><path d="M3 9h18M8 4v4M16 4v4"/></svg>',
        cerca: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>'
    };

    var el = {};
    ['sfcSub', 'sfcFiltriBtn', 'sfcBadge', 'sfcChips', 'sfcRiprendi', 'sfcRiprendiN', 'sfcCarosello',
     'sfcPeriodo', 'sfcDateBtn', 'sfcDate', 'sfcDa', 'sfcA', 'sfcMPartite', 'sfcMTempo', 'sfcMPrecisione',
     'sfcAttivita', 'sfcVista', 'sfcDensita', 'sfcPanePartite', 'sfcLista', 'sfcSentinella', 'sfcVuoto',
     'sfcPaneMaterie', 'sfcMaterie', 'sfcFiltriOverlay', 'sfcFiltriFine', 'sfcReset',
     'sfcAlert', 'sfcAlertNo', 'sfcAlertSi', 'sfcToast'
    ].forEach(function (id) { el[id] = document.getElementById(id); });

    /* ============================== STATO ============================== */

    function leggiPref() {
        try { return JSON.parse(localStorage.getItem(PREF)) || {}; } catch (e) { return {}; }
    }
    /* Periodo, vista e densita' si ricordano: sono il modo in cui si guarda
       lo storico, non un filtro da rifare ogni volta. I filtri invece
       ripartono puliti, altrimenti si dimentica di averli e l'elenco sembra
       incompleto. */
    function salvaPref() {
        try {
            localStorage.setItem(PREF, JSON.stringify({ periodo: state.periodo, vista: state.vista, densita: state.densita }));
        } catch (e) {}
    }

    var pref = leggiPref();
    var state = {
        periodo: ['7', '30', '90', '0'].indexOf(String(pref.periodo)) > -1 ? String(pref.periodo) : '30',
        vista: pref.vista === 'materie' ? 'materie' : 'partite',
        densita: pref.densita === 'dettagliata' ? 'dettagliata' : 'compatta',
        filtri: { stato: 'tutte', tipo: 'tutti', ordina: 'recenti', materia: 0, materiaNome: '' },
        offset: 0, finite: false, caricando: false, richiesta: 0,
        ultimoGiorno: null, righe: {},
        riepilogo: null,
        materie: null, materieRichiesta: 0, materieAperte: {},
        ordNonToccate: 'tempo_fa', ordGiocate: 'tempo'
    };

    /* ============================== UTILITA' ============================== */

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function buzz(ms) { try { if (navigator.vibrate) navigator.vibrate(ms); } catch (e) {} }

    function isoData(d) {
        return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    }
    function oggiISO() { return isoData(new Date()); }
    function isoMenoGiorni(n) { var d = new Date(); d.setDate(d.getDate() - n); return isoData(d); }

    /* Le date arrivano come "AAAA-MM-GG HH:MM:SS" nell'ora del server: si
       leggono come ora locale, che per un utente solo e' la stessa. */
    function leggiData(s) {
        if (!s) return null;
        var d = new Date(String(s).replace(' ', 'T'));
        return isNaN(d.getTime()) ? null : d;
    }
    function giornoDi(d) { return d ? isoData(d) : ''; }
    function giorniFa(d) {
        if (!d) return null;
        var a = new Date(); a.setHours(0, 0, 0, 0);
        var b = new Date(d.getTime()); b.setHours(0, 0, 0, 0);
        return Math.round((a - b) / 86400000);
    }
    function relativo(d) {
        var n = giorniFa(d);
        if (n === null) return '';
        if (n <= 0) return T('fc.sfc_rel_oggi');
        if (n === 1) return T('fc.sfc_rel_ieri');
        return T('fc.sfc_rel_giorni', { n: n });
    }
    function ora(d) { return d ? d.toLocaleTimeString(LOCALE, { hour: '2-digit', minute: '2-digit' }) : ''; }

    function etichettaGiorno(d) {
        var n = giorniFa(d);
        if (n === 0) return T('comune.oggi');
        if (n === 1) return T('comune.ieri');
        var opz = { weekday: 'long', day: 'numeric', month: 'long' };
        if (d.getFullYear() !== new Date().getFullYear()) opz.year = 'numeric';
        var s = d.toLocaleDateString(LOCALE, opz);
        return s.charAt(0).toUpperCase() + s.slice(1);
    }
    function dataBreve(d) {
        var opz = { day: 'numeric', month: 'short' };
        if (d.getFullYear() !== new Date().getFullYear()) opz.year = 'numeric';
        return d.toLocaleDateString(LOCALE, opz);
    }

    function durata(sec) {
        sec = Math.max(0, Math.round(sec || 0));
        /* Zero secondi vuol dire "non c'e' stato nessun gioco", non "meno di
           un minuto di gioco": sono due informazioni diverse, e la seconda
           lascia intendere che si sia comunque giocato qualcosa. */
        if (sec === 0) return '0';
        if (sec < 60) return T('fc.sfc_dur_s');
        var m = Math.round(sec / 60);
        if (m < 60) return T('fc.sfc_dur_m', { m: m });
        return T('fc.sfc_dur_h', { h: Math.floor(m / 60), m: String(m % 60).padStart(2, '0') });
    }

    function precisione(ok, ko) { return ok + ko > 0 ? Math.round(ok / (ok + ko) * 100) : null; }
    function classePrecisione(p) { return p === null ? 'nd' : (p >= 80 ? 'buona' : (p >= 50 ? 'media' : 'bassa')); }

    function nomePartita(r) {
        if (!r.cats || !r.cats.length) return T('fc.cs_tutte_materie');
        return r.cats.map(function (c) { return c.nome; }).join(', ');
    }
    function colorePartita(r) { return (r.cats && r.cats.length) ? r.cats[0].colore : '#8E8E93'; }
    function etichettaStato(s) { return s === null || s === undefined ? '' : T('fc.sfc_st_' + s); }

    function anello(r, colore) {
        var pct = r.totale > 0 ? Math.min(1, r.chiuse / r.totale) : 0;
        var c = 75.4;
        return '<svg class="sfc-anello" viewBox="0 0 30 30" aria-hidden="true">' +
            '<circle cx="15" cy="15" r="12" class="traccia"/>' +
            '<circle cx="15" cy="15" r="12" class="pieno" style="stroke:' + esc(colore) + '" stroke-dasharray="' + (pct * c).toFixed(1) + ' ' + c + '"/>' +
            '</svg>';
    }

    var toastTimer = null;
    function toast(msg, ok) {
        el.sfcToast.innerHTML = (ok !== false ? ICONE.spunta : '') + '<span>' + msg + '</span>';
        el.sfcToast.classList.add('show');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { el.sfcToast.classList.remove('show'); }, 2600);
    }

    function segmenta(contenitore, valore) {
        Array.prototype.forEach.call(contenitore.querySelectorAll('button'), function (b) {
            b.classList.toggle('attivo', b.getAttribute('data-v') === String(valore));
        });
    }

    /* ============================== PERIODO ============================== */

    function intervallo() {
        if (state.periodo === 'custom') {
            var da = el.sfcDa.value, a = el.sfcA.value;
            if (da && a && da > a) { var t = da; da = a; a = t; }
            return { da: da, a: a };
        }
        if (state.periodo === '0') return { da: '', a: '' };
        return { da: isoMenoGiorni(parseInt(state.periodo, 10) - 1), a: oggiISO() };
    }

    function etichettaPeriodo() {
        if (state.periodo === 'custom') {
            var iv = intervallo();
            var f = function (s) { return s ? dataBreve(leggiData(s + ' 00:00:00')) : '…'; };
            return T('fc.sfc_per_custom', { da: f(iv.da), a: f(iv.a) });
        }
        return T('fc.sfc_per_' + (state.periodo === '0' ? 'tutto' : state.periodo) + '_lungo');
    }

    el.sfcPeriodo.addEventListener('click', function (e) {
        var b = e.target.closest('button'); if (!b) return;
        state.periodo = b.getAttribute('data-v');
        segmenta(el.sfcPeriodo, state.periodo);
        el.sfcDate.hidden = true;
        el.sfcDateBtn.classList.remove('attivo');
        salvaPref();
        periodoCambiato();
    });

    el.sfcDateBtn.addEventListener('click', function () {
        var apri = el.sfcDate.hidden;
        el.sfcDate.hidden = !apri;
        if (apri) {
            if (!el.sfcDa.value) el.sfcDa.value = intervallo().da || isoMenoGiorni(29);
            if (!el.sfcA.value) el.sfcA.value = intervallo().a || oggiISO();
        }
    });

    [el.sfcDa, el.sfcA].forEach(function (inp) {
        inp.addEventListener('change', function () {
            if (!el.sfcDa.value || !el.sfcA.value) return;
            /* Un intervallo scelto a mano non si salva fra le preferenze:
               alla prossima apertura si torna all'ultimo periodo fisso. */
            state.periodo = 'custom';
            segmenta(el.sfcPeriodo, '');
            el.sfcDateBtn.classList.add('attivo');
            periodoCambiato();
        });
    });

    function periodoCambiato() {
        caricaLista(true);
        state.materie = null;
        if (state.vista === 'materie') caricaMaterie();
    }

    /* ============================== RIEPILOGO ============================== */

    function aggiornaSottotitolo() {
        if (!state.riepilogo) { el.sfcSub.innerHTML = '&nbsp;'; return; }
        var n = state.riepilogo.partite;
        el.sfcSub.innerHTML = T(n === 1 ? 'fc.sfc_sub_1' : 'fc.sfc_sub', { n: n, periodo: etichettaPeriodo() });
    }

    function disegnaRiepilogo(rie, att) {
        state.riepilogo = rie;
        el.sfcMPartite.textContent = rie.partite;
        el.sfcMTempo.innerHTML = durata(rie.secondi);
        var p = precisione(rie.ok, rie.ko);
        el.sfcMPrecisione.textContent = p === null ? '–' : p + '%';
        aggiornaSottotitolo();

        var barre = (att && att.barre) || [];
        var max = 0;
        barre.forEach(function (b) { if (b.n > max) max = b.n; });
        el.sfcAttivita.innerHTML = barre.map(function (b) {
            var h = b.n > 0 ? Math.max(4, Math.round(b.n / max * 30)) : 2;
            return '<span class="' + (b.n > 0 ? 'si' : 'no') + '" style="height:' + h + 'px"></span>';
        }).join('');
    }

    /* ============================== DA RIPRENDERE ============================== */

    function caricaSospese() {
        fetch(fcUrl('fc_history.php?ajax=sospese'), { cache: 'no-store', credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) { disegnaSospese(d.righe || [], d.totale || 0); })
            .catch(function () { el.sfcRiprendi.hidden = true; });
    }

    function disegnaSospese(righe, totale) {
        if (!righe.length) { el.sfcRiprendi.hidden = true; el.sfcCarosello.innerHTML = ''; return; }
        el.sfcRiprendi.hidden = false;
        el.sfcRiprendiN.textContent = totale;
        el.sfcCarosello.innerHTML = righe.map(function (r) {
            var col = colorePartita(r);
            var pct = r.totale > 0 ? Math.round(r.chiuse / r.totale * 100) : 0;
            var sotto = r.ripasso ? T('fc.sfc_ripasso_errori') : etichettaStato(r.stato);
            return '<a class="sfc-rip-card" href="fc_game.php?game_id=' + r.id + '&amp;position=' + r.pos + '">' +
                '<div class="sfc-rip-testa"><span class="sfc-punto" style="background:' + esc(col) + '"></span>' +
                    '<div class="sfc-nome-wrap sfc-rip-nome"><span class="sfc-nome-inner">' + esc(nomePartita(r)) + '</span></div></div>' +
                '<div class="sfc-rip-sotto">' + (sotto || '&nbsp;') + '</div>' +
                '<div class="sfc-rip-prog"><div class="sfc-traccia"><div style="width:' + pct + '%;background:' + esc(col) + '"></div></div>' +
                    '<span>' + r.chiuse + '/' + r.totale + '</span></div>' +
                '<div class="sfc-rip-piede"><span>' + relativo(leggiData(r.ultima)) + (r.secondi >= 60 ? ' · ' + durata(r.secondi) : '') + '</span>' +
                    '<span class="sfc-rip-btn">' + ICONE.play + T('fc.sfc_riprendi') + '</span></div>' +
            '</a>';
        }).join('');
        osservaTicker(el.sfcCarosello);
    }

    /* ============================== ELENCO PARTITE ============================== */

    function scheletro() {
        var h = '';
        for (var i = 0; i < 6; i++) h += '<div class="sfc-skel" style="animation-delay:' + (i * 70) + 'ms"></div>';
        return h;
    }

    function caricaLista(azzera) {
        if (azzera) {
            state.offset = 0; state.finite = false; state.caricando = false;
            state.ultimoGiorno = null; state.righe = {};
            el.sfcLista.innerHTML = scheletro();
            el.sfcVuoto.hidden = true;
            el.sfcMPartite.textContent = '–';
            el.sfcMTempo.textContent = '–';
            el.sfcMPrecisione.textContent = '–';
            el.sfcAttivita.innerHTML = '';
            state.riepilogo = null;
            initTicker();
        }
        if (state.finite || state.caricando) return;

        /* Ogni richiesta ha un numero: se nel frattempo e' cambiato un filtro,
           la risposta vecchia arriva tardi e va ignorata, altrimenti mischia
           partite di due filtri diversi. */
        var numero = ++state.richiesta;
        state.caricando = true;
        var iv = intervallo();
        var f = state.filtri;
        var q = new URLSearchParams({
            ajax: 'lista', da: iv.da, a: iv.a, stato: f.stato, tipo: f.tipo, ordina: f.ordina,
            materia: f.materia, offset: state.offset
        });

        fetch(fcUrl('fc_history.php?' + q.toString()), { cache: 'no-store', credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (numero !== state.richiesta) return;
                state.caricando = false;
                if (state.offset === 0) {
                    el.sfcLista.innerHTML = '';
                    if (d.riepilogo) disegnaRiepilogo(d.riepilogo, d.attivita);
                }
                var righe = d.righe || [];
                disegnaRighe(righe);
                state.offset += righe.length;
                state.finite = !d.altre;
                if (state.offset === 0) disegnaVuoto(!!d.mai);
                controllaSentinella();
            })
            .catch(function () {
                if (numero !== state.richiesta) return;
                state.caricando = false;
                if (state.offset === 0) el.sfcLista.innerHTML = '';
                toast(T('fc.sfc_errore_caricamento'), false);
            });
    }

    function disegnaVuoto(mai) {
        var conFiltri = contaFiltri() > 0;
        var tit, txt, icona;
        if (mai) { tit = 'fc.sfc_mai_tit'; txt = 'fc.sfc_mai_txt'; icona = ICONE.vuoto; }
        else if (conFiltri) { tit = 'fc.sfc_vuoto_filtri_tit'; txt = 'fc.sfc_vuoto_filtri_txt'; icona = ICONE.cerca; }
        else { tit = 'fc.sfc_vuoto_tit'; txt = 'fc.sfc_vuoto_txt'; icona = ICONE.vuoto; }
        el.sfcVuoto.innerHTML = icona + '<h3>' + T(tit) + '</h3><p>' + T(txt) + '</p>' +
            (conFiltri ? '<button type="button" id="sfcVuotoReset">' + T('fc.sfc_reimposta') + '</button>' : '');
        el.sfcVuoto.hidden = false;
        var b = document.getElementById('sfcVuotoReset');
        if (b) b.addEventListener('click', azzeraFiltri);
    }

    function disegnaRighe(righe) {
        var html = '';
        var perGiorno = state.filtri.ordina === 'recenti';
        righe.forEach(function (r, i) {
            state.righe[r.id] = r;
            var d = leggiData(r.data);
            if (perGiorno) {
                var g = giornoDi(d);
                if (g !== state.ultimoGiorno) {
                    state.ultimoGiorno = g;
                    html += '<div class="sfc-giorno">' + esc(d ? etichettaGiorno(d) : '') + '</div>';
                }
            }
            html += rigaHTML(r, perGiorno, i);
        });
        el.sfcLista.insertAdjacentHTML('beforeend', html);
        osservaTicker(el.sfcLista);
    }

    function rigaHTML(r, perGiorno, i) {
        var d = leggiData(r.data);
        var col = colorePartita(r);
        var qualcunaSospesa = r.sospesa || (r.ripassi || []).some(function (x) { return x.sospesa; });
        var p = precisione(r.ok, r.ko);

        var meta = (perGiorno ? ora(d) : (d ? dataBreve(d) + ' · ' + ora(d) : ''));
        if (r.secondi_tot >= 60) meta += ' · ' + durata(r.secondi_tot);

        /* LA STRADA FINO AL 100% (sfc_catena, lato server): una partita
           conquistata a forza di ripassi, o perfetta al primo colpo, e' oro;
           una con errori ancora da sistemare lo dice, e mostra la salita fatta. */
        var oro = !qualcunaSospesa && (r.catena === 'conquistata' || r.catena === 'perfetta');
        var nRipassi = (r.ripassi || []).length;
        var pill = qualcunaSospesa
            ? '<span class="sfc-pill sospesa">' + T('fc.sfc_in_sospeso') + '</span>'
            : (oro ? '<span class="sfc-pill oro">' + ICONE.stella + '100%</span>'
                   : '<span class="sfc-pill ' + classePrecisione(p) + '">' + (p === null ? '–' : p + '%') + '</span>');
        var targa = '';
        if (oro) {
            targa = '<div class="sfc-targa">' + ICONE.trofeo + '<span>' + (r.catena === 'perfetta'
                ? T('fc.sfc_perfetta')
                : T(nRipassi === 1 ? 'fc.sfc_conquistata_1' : 'fc.sfc_conquistata_n', { n: nRipassi })) + '</span></div>';
        } else if (!qualcunaSospesa && r.catena === 'aperta' && r.da_sistemare > 0) {
            targa = '<div class="sfc-targa da-fare">' + ICONE.bersaglio + '<span>' + T(r.da_sistemare === 1 ? 'fc.sfc_da_sistemare_1' : 'fc.sfc_da_sistemare_n', { n: r.da_sistemare }) + '</span>' + salitaMini(r) + '</div>';
        }

        /* La riga "dettagliata" c'e' sempre nel markup e la mostra il CSS:
           cambiare densita' non ridisegna l'elenco ne' perde le righe aperte. */
        var nRip = (r.ripassi || []).length;
        var extra = '<div class="sfc-riga-extra">' +
            '<span>' + T('fc.sfc_carte_n', { c: r.chiuse, t: r.totale }) + '</span>' +
            '<span>' + T(r.ko === 1 ? 'fc.sfc_errori_1' : 'fc.sfc_errori_n', { n: r.ko }) + '</span>' +
            (nRip ? '<span>' + T(nRip === 1 ? 'fc.sfc_ripassi_1' : 'fc.sfc_ripassi_n', { n: nRip }) + '</span>' : '') +
            '<span class="sfc-mini-barra"><i class="ok" style="width:' + (p === null ? 0 : p) + '%"></i><i class="ko" style="width:' + (p === null ? 0 : 100 - p) + '%"></i></span>' +
            '</div>';

        return '<div class="sfc-item' + (oro ? ' is-oro' : '') + '" data-id="' + r.id + '" style="animation-delay:' + (Math.min(i, 10) * 25) + 'ms">' +
            '<div class="sfc-swipe">' +
                '<button type="button" class="sfc-swipe-del" data-elimina="' + r.id + '">' + ICONE.cestino + '<span>' + T('comune.elimina') + '</span></button>' +
                '<div class="sfc-riga" data-id="' + r.id + '">' +
                    (oro ? '<span class="sfc-medaglia">' + ICONE.trofeo + '</span>' : anello(r, col)) +
                    '<div class="sfc-riga-main">' +
                        '<div class="sfc-nome-wrap"><span class="sfc-nome-inner">' + esc(nomePartita(r)) + '</span></div>' +
                        '<div class="sfc-riga-meta">' + meta + '</div>' +
                        targa +
                        extra +
                    '</div>' +
                    pill +
                '</div>' +
            '</div>' +
            '<div class="sfc-det" id="sfcDet' + r.id + '" hidden></div>' +
        '</div>';
    }

    /* Accanto a "Sistema N errori" il vecchio "Ripassa errori" (che apre
       solo l'elenco degli errori nei risultati) sembrava un doppione: li'
       diventa "Vedi gli errori", con l'occhio. */
    var ICONE_OCCHIO = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>';
    function dettaglioHTML(r) {
        var p = precisione(r.ok, r.ko);
        var meta = [];
        if (r.stato !== null && r.stato !== undefined) meta.push(etichettaStato(r.stato));
        meta.push(T('fc.sfc_ord_' + r.ordine));
        if (r.punteggio !== null && r.punteggio !== undefined) meta.push(T('fc.sfc_punteggio', { p: Math.round(r.punteggio) }));

        var html = '<div class="sfc-det-griglia">' +
            cella(T('fc.sfc_d_carte'), r.chiuse + '/' + r.totale) +
            cella(T('fc.sfc_d_errori'), r.ko) +
            cella(T('fc.sfc_d_tempo'), durata(r.secondi)) +
            cella(T('fc.sfc_d_sessioni'), r.sessioni) +
            '</div>' +
            '<div class="sfc-det-barra"><i class="ok" style="width:' + (p === null ? 0 : p) + '%"></i><i class="ko" style="width:' + (p === null ? 0 : 100 - p) + '%"></i></div>' +
            '<div class="sfc-det-meta">' + meta.join(' · ') + '</div>';

        html += stradaHTML(r);

        if (r.ripassi && r.ripassi.length) {
            html += '<div class="sfc-ripassi">' + r.ripassi.map(function (x, i) {
                var px = precisione(x.ok, x.ko);
                var dx = leggiData(x.data);
                return '<div class="sfc-ripasso">' +
                    '<a class="sfc-ripasso-link" href="' + (x.sospesa ? ('fc_game.php?game_id=' + x.id + '&amp;position=' + x.pos) : ('fc_result.php?game_id=' + x.id)) + '">' +
                        '<span class="sfc-ripasso-icona">' + (x.sospesa ? ICONE.play : ICONE.ripasso) + '</span>' +
                        '<span class="sfc-ripasso-nome">' + T('fc.sfc_ripasso_n', { n: i + 1 }) + '</span>' +
                        '<span class="sfc-ripasso-data">' + esc(dx ? dataBreve(dx) : '') + '</span>' +
                        '<span class="sfc-ripasso-prog">' + x.chiuse + '/' + x.totale + '</span>' +
                        '<span class="sfc-pill ' + classePrecisione(px) + '">' + (px === null ? '–' : px + '%') + '</span>' +
                    '</a>' +
                    /* un ripasso lasciato a meta' si puo' buttare via da solo,
                       senza toccare la partita e gli altri ripassi */
                    (x.sospesa ? '<button type="button" class="sfc-ripasso-del" data-elimina-rip="' + x.id + '" data-madre="' + r.id + '" aria-label="' + esc(T('fc.sfc_elimina_ripasso')) + '">' + ICONE.cestino + '</button>' : '') +
                '</div>';
            }).join('') + '</div>';
        }

        /* il prossimo ripasso parte dagli errori dell'ULTIMO passo, non della
           partita: e' cosi' che la catena arriva al 100% */
        var sistema = !r.sospesa && r.catena === 'aperta' && r.da_sistemare > 0 && !(r.ripassi || []).some(function (x) { return x.sospesa; });
        html += '<div class="sfc-det-azioni">' +
            (r.sospesa ? '<a class="sfc-btn primario" href="fc_game.php?game_id=' + r.id + '&amp;position=' + r.pos + '">' + ICONE.play + T('fc.sfc_riprendi') + '</a>' : '') +
            (sistema ? '<a class="sfc-btn primario sistema" href="fc_game_retry.php?parent_id=' + r.ultimo_id + '">' + ICONE.ripasso + T(r.da_sistemare === 1 ? 'fc.sfc_sistema_1' : 'fc.sfc_sistema_n', { n: r.da_sistemare }) + '</a>' : '') +
            '<a class="sfc-btn" href="fc_result.php?game_id=' + r.id + '">' + ICONE.risultati + T('fc.sfc_risultati') + '</a>' +
            (r.ko > 0 ? '<a class="sfc-btn" href="fc_result.php?game_id=' + r.id + '&amp;solo_errori=1">' + (sistema ? ICONE_OCCHIO + T('fc.sfc_vedi_errori') : ICONE.ripasso + T('fc.sfc_ripassa')) + '</a>' : '') +
            '<button type="button" class="sfc-btn icona pericolo" data-elimina="' + r.id + '" aria-label="' + esc(T('comune.elimina')) + '">' + ICONE.cestino + '</button>' +
            '</div>';
        return html;
    }

    /* la salita in piccolo: una barretta per passo, alta quanto la precisione */
    function salitaMini(r) {
        var s = (r.salita || []).filter(function (x) { return x !== null; });
        if (s.length < 2) return '';
        return '<span class="sfc-salita-mini" aria-hidden="true">' + s.map(function (v) {
            return '<i class="' + classePrecisione(v) + '" style="height:' + Math.max(12, v) + '%"></i>';
        }).join('') + '<i class="meta"></i></span>';
    }

    /* LA STRADA FINO AL 100% nel dettaglio: un gradino per la partita e uno per
       ogni ripasso, con la percentuale sopra, e in fondo il traguardo. */
    function stradaHTML(r) {
        var s = r.salita || [];
        if (r.catena === 'perfetta' || s.length === 0 || (s.length === 1 && r.catena !== 'aperta')) return '';
        var preso = r.catena === 'conquistata';
        var passi = s.map(function (v, i) {
            var h = v === null ? 8 : Math.max(10, v);
            return '<div class="sfc-gradino" style="--i:' + i + '">' +
                '<span class="sfc-gradino-pct">' + (v === null ? '–' : v + '%') + '</span>' +
                '<span class="sfc-gradino-barra ' + (v === 100 ? 'oro' : classePrecisione(v)) + '" style="height:' + h + '%"></span>' +
                '<span class="sfc-gradino-lbl">' + (i === 0 ? T('fc.sfc_strada_partita') : T('fc.sfc_strada_rip', { n: i })) + '</span>' +
            '</div>';
        }).join('');
        if (!preso) {
            passi += '<div class="sfc-gradino meta" style="--i:' + s.length + '">' +
                '<span class="sfc-gradino-pct">100%</span>' +
                '<span class="sfc-gradino-barra" style="height:100%">' + ICONE.trofeo + '</span>' +
                '<span class="sfc-gradino-lbl">' + T('fc.sfc_strada_meta') + '</span></div>';
        }
        return '<div class="sfc-strada' + (preso ? ' presa' : '') + '">' +
            '<div class="sfc-strada-tit">' + (preso ? ICONE.trofeo : ICONE.bersaglio) + '<span>' + T(preso ? 'fc.sfc_strada_presa' : 'fc.sfc_strada') + '</span></div>' +
            '<div class="sfc-strada-grafico">' + passi + '</div></div>';
    }

    function cella(lbl, val) {
        return '<div class="sfc-det-cella"><div class="lbl">' + lbl + '</div><div class="val">' + val + '</div></div>';
    }

    function apriChiudiDettaglio(id) {
        var det = document.getElementById('sfcDet' + id);
        var item = det && det.closest('.sfc-item');
        if (!det || !state.righe[id]) return;
        if (det.hidden) {
            det.innerHTML = dettaglioHTML(state.righe[id]);
            det.hidden = false;
            item.classList.add('aperto');
        } else {
            det.hidden = true;
            item.classList.remove('aperto');
        }
    }

    /* Carica la pagina successiva quando il fondo dell'elenco si avvicina.
       Il controllo dopo ogni disegno serve perche' l'osservatore avvisa solo
       quando la sentinella ENTRA nello schermo: se ci resta dentro anche dopo
       le nuove righe (schermo alto, pagina corta) non richiamerebbe piu'. */
    var osservatore = ('IntersectionObserver' in window)
        ? new IntersectionObserver(function (voci) {
            if (voci[0].isIntersecting && state.vista === 'partite') caricaLista(false);
        }, { rootMargin: '600px 0px' })
        : null;
    if (osservatore) osservatore.observe(el.sfcSentinella);

    function controllaSentinella() {
        if (state.finite || state.vista !== 'partite') return;
        requestAnimationFrame(function () {
            if (el.sfcSentinella.getBoundingClientRect().top < window.innerHeight + 600) caricaLista(false);
        });
    }
    if (!osservatore) {
        window.addEventListener('scroll', function () { controllaSentinella(); }, { passive: true });
    }

    /* ============================== DENSITA' E VISTA ============================== */

    function applicaDensita() {
        el.sfcLista.classList.toggle('dettagliata', state.densita === 'dettagliata');
        el.sfcDensita.classList.toggle('attivo', state.densita === 'dettagliata');
    }
    el.sfcDensita.addEventListener('click', function () {
        state.densita = state.densita === 'dettagliata' ? 'compatta' : 'dettagliata';
        applicaDensita();
        salvaPref();
    });

    function applicaVista() {
        segmenta(el.sfcVista, state.vista);
        el.sfcPanePartite.hidden = state.vista !== 'partite';
        el.sfcPaneMaterie.hidden = state.vista !== 'materie';
        el.sfcDensita.hidden = state.vista !== 'partite';
        if (state.vista === 'materie' && !state.materie) caricaMaterie();
        if (state.vista === 'partite') controllaSentinella();
    }
    el.sfcVista.addEventListener('click', function (e) {
        var b = e.target.closest('button'); if (!b) return;
        state.vista = b.getAttribute('data-v');
        applicaVista();
        salvaPref();
    });

    /* ============================== FILTRI ============================== */

    function apriFoglio() { el.sfcFiltriOverlay.classList.add('open'); }
    function chiudiFoglio() { el.sfcFiltriOverlay.classList.remove('open'); }
    el.sfcFiltriBtn.addEventListener('click', apriFoglio);
    el.sfcFiltriFine.addEventListener('click', chiudiFoglio);
    el.sfcFiltriOverlay.addEventListener('click', function (e) { if (e.target === el.sfcFiltriOverlay) chiudiFoglio(); });

    Array.prototype.forEach.call(el.sfcFiltriOverlay.querySelectorAll('[data-filtro]'), function (seg) {
        seg.addEventListener('click', function (e) {
            var b = e.target.closest('button'); if (!b) return;
            state.filtri[seg.getAttribute('data-filtro')] = b.getAttribute('data-v');
            filtriCambiati();
        });
    });

    document.addEventListener('categoryselected', function (e) {
        if (!e.detail || e.detail.uid !== 'historyFilter') return;
        var id = parseInt(e.detail.id, 10) || 0;
        state.filtri.materia = id;
        state.filtri.materiaNome = id ? (e.detail.name || '') : '';
        filtriCambiati();
    });

    function impostaMateria(id, nome) {
        state.filtri.materia = id;
        state.filtri.materiaNome = nome;
        if (window.csSetValue) window.csSetValue('cs-modal-historyFilter', id, id ? nome : T('fc.cs_tutte_materie'));
    }

    function azzeraFiltri() {
        state.filtri.stato = 'tutte'; state.filtri.tipo = 'tutti'; state.filtri.ordina = 'recenti';
        impostaMateria(0, '');
        filtriCambiati();
    }
    el.sfcReset.addEventListener('click', azzeraFiltri);

    function contaFiltri() {
        var f = state.filtri, n = 0;
        if (f.stato !== 'tutte') n++;
        if (f.tipo !== 'tutti') n++;
        if (f.ordina !== 'recenti') n++;
        if (f.materia) n++;
        return n;
    }

    function disegnaFiltri() {
        Array.prototype.forEach.call(el.sfcFiltriOverlay.querySelectorAll('[data-filtro]'), function (seg) {
            segmenta(seg, state.filtri[seg.getAttribute('data-filtro')]);
        });
        var n = contaFiltri();
        el.sfcBadge.hidden = n === 0;
        el.sfcBadge.textContent = n;
        el.sfcFiltriBtn.classList.toggle('attivo', n > 0);

        var f = state.filtri, chips = [];
        if (f.materia) chips.push({ k: 'materia', t: esc(f.materiaNome) });
        if (f.stato !== 'tutte') chips.push({ k: 'stato', t: T(f.stato === 'sospese' ? 'fc.sfc_in_sospeso' : 'fc.sfc_f_stato_concluse') });
        if (f.tipo !== 'tutti') chips.push({ k: 'tipo', t: T('fc.sfc_st_' + f.tipo) });
        if (f.ordina !== 'recenti') chips.push({ k: 'ordina', t: T('fc.sfc_o_' + f.ordina) });
        el.sfcChips.innerHTML = chips.map(function (c) {
            return '<span class="sfc-chip">' + c.t + '<button type="button" data-chip="' + c.k + '" aria-label="' + esc(T('fc.sfc_rimuovi_filtro')) + '">' + ICONE.x + '</button></span>';
        }).join('');
    }

    el.sfcChips.addEventListener('click', function (e) {
        var b = e.target.closest('[data-chip]'); if (!b) return;
        var k = b.getAttribute('data-chip');
        if (k === 'materia') impostaMateria(0, '');
        if (k === 'stato') state.filtri.stato = 'tutte';
        if (k === 'tipo') state.filtri.tipo = 'tutti';
        if (k === 'ordina') state.filtri.ordina = 'recenti';
        filtriCambiati();
    });

    function filtriCambiati() {
        disegnaFiltri();
        caricaLista(true);
    }

    /* ============================== SWIPE PER ELIMINARE ============================== */

    var sw = null;
    var itemAperto = null;

    function chiudiSwipe(item, animato) {
        var riga = item.querySelector('.sfc-riga');
        riga.style.transition = animato ? ('transform .4s ' + SNAP) : 'none';
        riga.style.transform = '';
        item.classList.remove('swipe-aperto');
        if (itemAperto === item) itemAperto = null;
    }
    function apriSwipe(item) {
        if (itemAperto && itemAperto !== item) chiudiSwipe(itemAperto, true);
        var riga = item.querySelector('.sfc-riga');
        riga.style.transition = 'transform .4s ' + SNAP;
        riga.style.transform = 'translateX(' + APERTO_X + 'px)';
        item.classList.add('swipe-aperto');
        itemAperto = item;
    }

    el.sfcLista.addEventListener('pointerdown', function (e) {
        if (e.pointerType === 'mouse' && e.button !== 0) return;
        var riga = e.target.closest('.sfc-riga');
        if (!riga) return;
        var item = riga.closest('.sfc-item');
        var aperto = item.classList.contains('swipe-aperto');
        sw = { item: item, riga: riga, sx: e.clientX, sy: e.clientY, base: aperto ? APERTO_X : 0,
               modo: null, pos: aperto ? APERTO_X : 0, pid: e.pointerId };
        riga.style.transition = 'none';
    });

    el.sfcLista.addEventListener('pointermove', function (e) {
        if (!sw || e.pointerId !== sw.pid) return;
        var dx = e.clientX - sw.sx, dy = e.clientY - sw.sy;
        if (sw.modo === null) {
            if (Math.abs(dx) < 7 && Math.abs(dy) < 7) return;
            /* Si decide una volta sola: se il primo movimento e' verticale
               e' uno scorrimento della pagina e la riga non si muove piu'. */
            sw.modo = Math.abs(dx) > Math.abs(dy) * 1.2 ? 'h' : 'v';
            if (sw.modo === 'h') {
                try { sw.riga.setPointerCapture(sw.pid); } catch (err) {}
                if (itemAperto && itemAperto !== sw.item) chiudiSwipe(itemAperto, true);
            }
        }
        if (sw.modo !== 'h') return;
        e.preventDefault();
        var pos = sw.base + dx;
        if (pos > 0) pos = pos * 0.15;
        else if (pos < APERTO_X) pos = APERTO_X + (pos - APERTO_X) * 0.22;
        var armato = pos <= APERTO_X * 0.55;
        if (armato !== (sw.pos <= APERTO_X * 0.55)) buzz(armato ? 10 : 6);
        sw.pos = pos;
        sw.riga.style.transform = 'translateX(' + pos + 'px)';
    }, { passive: false });

    function fineSwipe(e) {
        if (!sw || (e.pointerId !== undefined && e.pointerId !== sw.pid)) return;
        var s = sw; sw = null;
        try { s.riga.releasePointerCapture(s.pid); } catch (err) {}
        if (s.modo === 'h') {
            if (s.pos <= APERTO_X * 0.5) apriSwipe(s.item); else chiudiSwipe(s.item, true);
        } else if (s.modo === null && e.type === 'pointerup') {
            if (s.item.classList.contains('swipe-aperto')) chiudiSwipe(s.item, true);
            else apriChiudiDettaglio(s.item.getAttribute('data-id'));
        }
    }
    el.sfcLista.addEventListener('pointerup', fineSwipe);
    el.sfcLista.addEventListener('pointercancel', fineSwipe);

    document.addEventListener('pointerdown', function (e) {
        if (itemAperto && !itemAperto.contains(e.target)) chiudiSwipe(itemAperto, true);
    }, true);

    /* ============================== ELIMINAZIONE ============================== */

    var daEliminare = null;

    var ripDaEliminare = null;   // { id, madre } quando si elimina un ripasso
    var avvisoTit = el.sfcAlert.querySelector('h4'), avvisoTxt = el.sfcAlert.querySelector('p');
    var avvisoTitOrig = avvisoTit ? avvisoTit.textContent : '', avvisoTxtOrig = avvisoTxt ? avvisoTxt.textContent : '';

    el.sfcLista.addEventListener('click', function (e) {
        var rb = e.target.closest('[data-elimina-rip]');
        if (rb) {
            e.preventDefault();
            ripDaEliminare = { id: rb.getAttribute('data-elimina-rip'), madre: rb.getAttribute('data-madre') };
            daEliminare = null;
            if (avvisoTit) avvisoTit.textContent = T('fc.sfc_elimina_rip_tit');
            if (avvisoTxt) avvisoTxt.textContent = T('fc.sfc_elimina_rip_txt');
            el.sfcAlert.classList.add('open');
            return;
        }
        var b = e.target.closest('[data-elimina]');
        if (!b) return;
        daEliminare = b.getAttribute('data-elimina');
        ripDaEliminare = null;
        if (avvisoTit) avvisoTit.textContent = avvisoTitOrig;
        if (avvisoTxt) avvisoTxt.textContent = avvisoTxtOrig;
        el.sfcAlert.classList.add('open');
    });

    function eliminaRipasso(rip) {
        var dati = new FormData();
        dati.append('azione', 'elimina_ripasso');
        dati.append('id', rip.id);
        dati.append('csrf_token', CSRF);
        fetch(fcUrl('fc_history.php'), { method: 'POST', body: dati, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || !d.success) { toast(T('fc.sfc_no_elimina'), false); return; }
                /* la riga della madre torna ricalcolata: si ridisegna il
                   dettaglio aperto, con un ripasso in meno */
                var mid = String(d.madre || rip.madre);
                if (d.riga) state.righe[mid] = d.riga;
                var det = document.getElementById('sfcDet' + mid);
                if (det && !det.hidden && state.righe[mid]) det.innerHTML = dettaglioHTML(state.righe[mid]);
                toast(T('fc.sfc_ripasso_eliminato'));
                caricaSospese();
            })
            .catch(function () { toast(T('comune.errore_rete'), false); });
    }

    function chiudiAvviso() {
        el.sfcAlert.classList.remove('open');
        if (itemAperto) chiudiSwipe(itemAperto, true);
        daEliminare = null;
    }
    el.sfcAlertNo.addEventListener('click', chiudiAvviso);
    el.sfcAlert.addEventListener('click', function (e) { if (e.target === el.sfcAlert) chiudiAvviso(); });

    el.sfcAlertSi.addEventListener('click', function () {
        var id = daEliminare, rip = ripDaEliminare;
        chiudiAvviso();
        ripDaEliminare = null;
        if (rip) { eliminaRipasso(rip); return; }
        if (!id) return;
        var dati = new FormData();
        dati.append('azione', 'elimina');
        dati.append('id', id);
        dati.append('csrf_token', CSRF);
        fetch(fcUrl('fc_history.php'), { method: 'POST', body: dati, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d && d.success) { togliRiga(id); toast(T('fc.sfc_eliminata')); caricaSospese(); }
                else toast(T('fc.sfc_no_elimina'), false);
            })
            .catch(function () { toast(T('comune.errore_rete'), false); });
    });

    function togliRiga(id) {
        var item = el.sfcLista.querySelector('.sfc-item[data-id="' + id + '"]');
        var riga = state.righe[id];
        delete state.righe[id];
        if (state.riepilogo && riga) {
            state.riepilogo.partite = Math.max(0, state.riepilogo.partite - 1);
            el.sfcMPartite.textContent = state.riepilogo.partite;
            aggiornaSottotitolo();
        }
        if (!item) return;
        var h = item.offsetHeight;
        item.style.height = h + 'px';
        item.style.overflow = 'hidden';
        requestAnimationFrame(function () {
            item.style.transition = 'height .34s ' + SPRING + ', opacity .3s';
            item.style.height = '0px';
            item.style.opacity = '0';
        });
        setTimeout(function () {
            /* L'intestazione del giorno resta orfana se era l'ultima partita
               di quel giorno: si toglie anche lei. */
            var prima = item.previousElementSibling;
            var dopo = item.nextElementSibling;
            item.remove();
            if (prima && prima.classList.contains('sfc-giorno') && (!dopo || dopo.classList.contains('sfc-giorno'))) prima.remove();
            if (!el.sfcLista.querySelector('.sfc-item')) disegnaVuoto(false);
        }, 360);
    }

    /* ============================== MATERIE ============================== */

    function caricaMaterie() {
        var numero = ++state.materieRichiesta;
        el.sfcMaterie.innerHTML = scheletro();
        var iv = intervallo();
        var q = new URLSearchParams({ ajax: 'materie', da: iv.da, a: iv.a });
        fetch(fcUrl('fc_history.php?' + q.toString()), { cache: 'no-store', credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (numero !== state.materieRichiesta) return;
                state.materie = preparaMaterie(d.categorie || []);
                disegnaMaterie();
            })
            .catch(function () {
                if (numero !== state.materieRichiesta) return;
                el.sfcMaterie.innerHTML = '';
                toast(T('fc.sfc_errore_caricamento'), false);
            });
    }

    /* Ogni cartella porta i numeri di tutto il suo ramo: "Analisi" e'
       toccata se si e' giocato anche solo su una sua sottocartella. Le
       archiviate e i loro rami restano fuori, come in categories_fc. */
    function preparaMaterie(lista) {
        var perId = {}, figli = {};
        lista.forEach(function (c) { perId[c.id] = c; });
        lista.forEach(function (c) {
            var p = perId[c.padre] ? c.padre : 0;
            (figli[p] = figli[p] || []).push(c.id);
        });

        function ramo(id, archiviataSopra) {
            var c = perId[id];
            c.archiviataEff = archiviataSopra || c.archiviata;
            /* comp_num / comp_den = media pesata della completion:
               peso = carte della categoria, valore = completion del server.
               Per le foglie il server dà il max nel periodo; qui si risale. */
            var r = { carte: c.carte, imparate: c.imparate, ok: c.ok, ko: c.ko, secondi: c.secondi,
                      ultima: c.ultima || '', ids: [id], nonToccate: 0,
                      comp_num: (c.completion || 0) * c.carte, comp_den: c.carte };
            (figli[id] || []).forEach(function (f) {
                var s = ramo(f, c.archiviataEff);
                if (perId[f].archiviataEff) return;
                r.carte += s.carte; r.imparate += s.imparate; r.ok += s.ok; r.ko += s.ko; r.secondi += s.secondi;
                if (s.ultima > r.ultima) r.ultima = s.ultima;
                r.ids = r.ids.concat(s.ids);
                r.comp_num += s.comp_num; r.comp_den += s.comp_den;
                if (s.carte > 0 && s.ok + s.ko === 0) r.nonToccate += 1 + s.nonToccate;
                else r.nonToccate += s.nonToccate;
            });
            r.completion = r.comp_den > 0 ? r.comp_num / r.comp_den : 0;
            c.r = r;
            return r;
        }
        (figli[0] || []).forEach(function (id) { ramo(id, false); });

        return { perId: perId, figli: figli };
    }

    function visibili(ids) {
        var m = state.materie;
        return (ids || []).filter(function (id) { var c = m.perId[id]; return !c.archiviataEff && c.r.carte > 0; });
    }
    function toccata(c) { return c.r.ok + c.r.ko > 0; }

    function ordinaNonToccate(ids) {
        var m = state.materie;
        return ids.slice().sort(function (a, b) {
            var ca = m.perId[a].r, cb = m.perId[b].r;
            if (state.ordNonToccate === 'da_imparare') return (cb.carte - cb.imparate) - (ca.carte - ca.imparate);
            if (state.ordNonToccate === 'alfa') return m.perId[a].nome.localeCompare(m.perId[b].nome, undefined, {sensitivity:'base'});
            return (ca.ultima || '').localeCompare(cb.ultima || '');
        });
    }
    function ordinaGiocate(ids) {
        var m = state.materie;
        return ids.slice().sort(function (a, b) {
            var ca = m.perId[a].r, cb = m.perId[b].r;
            if (state.ordGiocate === 'precisione') return (ca.ok / (ca.ok + ca.ko)) - (cb.ok / (cb.ok + cb.ko));
            if (state.ordGiocate === 'carte') return (cb.ok + cb.ko) - (ca.ok + ca.ko);
            if (state.ordGiocate === 'alfa') return m.perId[a].nome.localeCompare(m.perId[b].nome, undefined, {sensitivity:'base'});
            return cb.secondi - ca.secondi;
        });
    }

    var ORD_NON = ['tempo_fa', 'da_imparare', 'alfa'];
    var ORD_GIOC = ['tempo', 'precisione', 'carte', 'alfa'];

    function disegnaMaterie() {
        var m = state.materie;
        var radici = visibili(m.figli[0]);
        if (!radici.length) {
            el.sfcMaterie.innerHTML = '<div class="sfc-vuoto">' + ICONE.vuoto + '<p>' + T('fc.sfc_mat_vuoto') + '</p></div>';
            return;
        }
        var non = radici.filter(function (id) { return !toccata(m.perId[id]); });
        var gioc = radici.filter(function (id) { return toccata(m.perId[id]); });

        var html = '<div class="sfc-mat-riepilogo">' + T(gioc.length === 1 ? 'fc.sfc_mat_riepilogo_1' : 'fc.sfc_mat_riepilogo', { n: gioc.length, tot: radici.length }) + '</div>';

        /* Prima quelle giocate nel periodo, poi quelle non toccate: si guarda
           prima cosa si e' fatto, e sotto cosa resta da riprendere. */
        if (gioc.length) {
            html += '<div class="sfc-sezione-tit"><span>' + T('fc.sfc_mat_giocate') + ' · ' + gioc.length + '</span>' +
                (gioc.length > 1 ? '<button type="button" class="sfc-ordina" data-ordina="gioc">' + T('fc.sfc_ms_' + state.ordGiocate) + ICONE.chev + '</button>' : '') + '</div>';
            html += '<div class="sfc-mat-gruppo">' + ordinaGiocate(gioc).map(function (id) { return materiaHTML(id, 0, 0); }).join('') + '</div>';
        }

        html += '<div class="sfc-sezione-tit"><span>' + T('fc.sfc_mat_non_toccate') + ' · ' + non.length + '</span>' +
            (non.length > 1 ? '<button type="button" class="sfc-ordina" data-ordina="non">' + T('fc.sfc_ms_' + state.ordNonToccate) + ICONE.chev + '</button>' : '') + '</div>';
        html += non.length
            ? '<div class="sfc-mat-gruppo">' + ordinaNonToccate(non).map(function (id) { return materiaHTML(id, 0); }).join('') + '</div>'
            : '<div class="sfc-mat-nota">' + T('fc.sfc_tutte_toccate') + '</div>';
        el.sfcMaterie.innerHTML = html;
    }

    function linkGioca(c) {
        /* Tutto il ramo, perche' start_game_fc prende le carte solo delle
           categorie elencate; lo stato "da imparare" solo se ne resta
           qualcuna, altrimenti la partita nascerebbe vuota. */
        var stato = c.r.carte - c.r.imparate > 0 ? '0' : 'all';
        return fcUrl('fc_game_start.php?category_id=' + encodeURIComponent(c.r.ids.join(',')) + '&status=' + stato + '&order=random').replace(/&/g, '&amp;');
    }

    function materiaHTML(id, livello, maxSecondi) {
        var m = state.materie;
        var c = m.perId[id];
        var r = c.r;
        var figli = visibili(m.figli[id]);
        var rientro = livello > 0 ? ' style="padding-left:' + (8 + livello * 18) + 'px"' : '';

        if (!toccata(c)) {
            var dUltima = leggiData(r.ultima);
            var sotto = dUltima
                ? T('fc.sfc_mat_ultima', { rel: relativo(dUltima) }) + ' · ' + T(r.carte - r.imparate === 1 ? 'fc.sfc_mat_da_imparare_1' : 'fc.sfc_mat_da_imparare', { n: r.carte - r.imparate })
                : T('fc.sfc_mai_giocata') + ' · ' + T(r.carte === 1 ? 'fc.sfc_mat_carte_1' : 'fc.sfc_mat_carte', { n: r.carte });
            return '<div class="sfc-mat-riga"' + rientro + '>' +
                '<span class="sfc-mat-chev vuoto"></span>' +
                '<div class="sfc-mat-main">' +
                    '<div class="sfc-mat-nome"><span class="sfc-punto" style="background:' + esc(c.colore) + '"></span>' + esc(c.nome) + '</div>' +
                    '<div class="sfc-mat-sotto">' + sotto + '</div>' +
                '</div>' +
                '<a class="sfc-mini-btn" href="' + linkGioca(c) + '">' + ICONE.play + T('fc.sfc_gioca') + '</a>' +
            '</div>';
        }

        var p = precisione(r.ok, r.ko);
        /* La barra usa completion%, non il tempo relativo. maxSecondi rimane nella firma per retrocompatibilità. */
        var larghezza = Math.round((r.completion || 0) * 100);
        var aperta = !!state.materieAperte[id];
        var sottoNon = r.nonToccate > 0
            ? '<div class="sfc-mat-sotto">' + T(r.nonToccate === 1 ? 'fc.sfc_mat_sub_non_1' : 'fc.sfc_mat_sub_non', { n: r.nonToccate }) + '</div>'
            : '';

        var html = '<div class="sfc-mat-item' + (aperta ? ' aperta' : '') + '">' +
            '<div class="sfc-mat-riga giocata" data-filtra="' + id + '"' + rientro + '>' +
                (figli.length
                    ? '<button type="button" class="sfc-mat-chev" data-apri="' + id + '" aria-expanded="' + aperta + '">' + ICONE.chev + '</button>'
                    : '<span class="sfc-mat-chev vuoto"></span>') +
                '<div class="sfc-mat-main">' +
                    '<div class="sfc-mat-nome"><span class="sfc-punto" style="background:' + esc(c.colore) + '"></span>' + esc(c.nome) + '</div>' +
                    '<div class="sfc-mat-tempo"><div class="sfc-traccia"><div style="width:' + larghezza + '%;background:' + esc(c.colore) + '"></div></div><span>' + durata(r.secondi) + '</span></div>' +
                    sottoNon +
                '</div>' +
                '<span class="sfc-pill ' + classePrecisione(p) + '">' + (p === null ? '–' : p + '%') + '</span>' +
            '</div>';

        if (figli.length) {
            var giocF = ordinaGiocate(figli.filter(function (f) { return toccata(m.perId[f]); }));
            var nonF = ordinaNonToccate(figli.filter(function (f) { return !toccata(m.perId[f]); }));
            html += '<div class="sfc-mat-figli"' + (aperta ? '' : ' hidden') + '>' +
                giocF.concat(nonF).map(function (f) { return materiaHTML(f, livello + 1, 0); }).join('') +
                '</div>';
        }
        return html + '</div>';
    }

    /* ── MENU DI ORDINAMENTO ───────────────────────────────────────────────
       Un popover piccolo ancorato al bottone, con tutte le opzioni visibili
       invece di doverle scoprire una alla volta toccando ripetutamente. */
    var menuAperto = null;

    function chiudiMenu() {
        if (!menuAperto) return;
        var m = menuAperto; menuAperto = null;
        m.classList.remove('open');
        setTimeout(function () { m.remove(); }, 160);
        document.removeEventListener('pointerdown', chiudiMenuSeFuori, true);
        window.removeEventListener('scroll', chiudiMenu, true);
    }
    function chiudiMenuSeFuori(e) {
        if (menuAperto && !menuAperto.contains(e.target)) chiudiMenu();
    }
    function apriMenu(btn, opzioni, attivo, onScegli) {
        chiudiMenu();
        var pop = document.createElement('div');
        pop.className = 'sfc-menu-pop';
        pop.innerHTML = opzioni.map(function (o) {
            return '<button type="button" class="sfc-menu-item' + (o === attivo ? ' attivo' : '') + '" data-mv="' + o + '">' +
                '<span>' + esc(T('fc.sfc_ms_' + o)) + '</span>' + (o === attivo ? ICONE.spunta : '') + '</button>';
        }).join('');
        document.body.appendChild(pop);

        var r = btn.getBoundingClientRect();
        var pr = pop.getBoundingClientRect();
        var left = Math.max(8, Math.min(r.right - pr.width, window.innerWidth - pr.width - 8));
        var top = r.bottom + 6;
        if (top + pr.height > window.innerHeight - 8) top = r.top - pr.height - 6;
        pop.style.left = left + 'px';
        pop.style.top = top + 'px';
        requestAnimationFrame(function () { pop.classList.add('open'); });

        pop.addEventListener('click', function (e) {
            var it = e.target.closest('[data-mv]');
            if (!it) return;
            onScegli(it.getAttribute('data-mv'));
            chiudiMenu();
        });
        menuAperto = pop;
        /* setTimeout: altrimenti il click che ha appena aperto il menu
           arriverebbe anche a questo listener e lo richiuderebbe subito. */
        setTimeout(function () {
            document.addEventListener('pointerdown', chiudiMenuSeFuori, true);
            window.addEventListener('scroll', chiudiMenu, true);
        }, 0);
    }

    el.sfcMaterie.addEventListener('click', function (e) {
        var ord = e.target.closest('[data-ordina]');
        if (ord) {
            var tipo = ord.getAttribute('data-ordina');
            var opzioni = tipo === 'non' ? ORD_NON : ORD_GIOC;
            var attivo = tipo === 'non' ? state.ordNonToccate : state.ordGiocate;
            apriMenu(ord, opzioni, attivo, function (v) {
                if (tipo === 'non') state.ordNonToccate = v; else state.ordGiocate = v;
                disegnaMaterie();
            });
            return;
        }
        var chev = e.target.closest('[data-apri]');
        if (chev) {
            var id = chev.getAttribute('data-apri');
            var item = chev.closest('.sfc-mat-item');
            var box = item.querySelector(':scope > .sfc-mat-figli');
            var apri = !state.materieAperte[id];
            state.materieAperte[id] = apri;
            item.classList.toggle('aperta', apri);
            chev.setAttribute('aria-expanded', apri);
            if (box) box.hidden = !apri;
            return;
        }
        if (e.target.closest('a')) return;
        /* Toccare una materia giocata porta alle sue partite: e' la domanda
           naturale dopo "qui ho giocato poco" o "qui sbaglio molto". */
        var riga = e.target.closest('[data-filtra]');
        if (riga) {
            var cid = parseInt(riga.getAttribute('data-filtra'), 10);
            impostaMateria(cid, state.materie.perId[cid].nome);
            state.vista = 'partite';
            applicaVista();
            salvaPref();
            filtriCambiati();
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
    });

    /* ============================== AVVIO ============================== */

    function leggiURLParams() {
        try {
            var p = new URLSearchParams(window.location.search);
            var ordina = p.get('ordina'), stato = p.get('stato');
            if (['recenti','lunghe','errori','precisione'].indexOf(ordina) > -1) state.filtri.ordina = ordina;
            if (['tutte','sospese','concluse'].indexOf(stato) > -1) state.filtri.stato = stato;
            /* dal mazzo (fc_view, "Partite con questo mazzo"): solo quel mazzo, da sempre */
            var m = parseInt(p.get('materia') || '0', 10);
            if (m > 0) {
                state.filtri.materia = m;
                state.filtri.materiaNome = p.get('materia_nome') || '';
                state.periodo = '0';
            }
        } catch(e) {}
    }

    function avvia() {
        leggiURLParams();
        initTicker();
        segmenta(el.sfcPeriodo, state.periodo);
        applicaDensita();
        disegnaFiltri();
        applicaVista();
        caricaSospese();
        caricaLista(true);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', avvia);
    else avvia();
})();
</script>
</body>
</html>
