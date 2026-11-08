<?php
/* =====================================================================
   STANDOUT — sync: lingue (corsi, mazzi, parole, parole figlie, esempi, preferiti, imparate)

   Le regole sono quelle del sito (public/fc/fc_languages.php, fc_insert_save.php, fc_examples_api.php):
     - un corso e' una coppia (lingua che si conosce, lingua che si impara) per utente: la lingua da imparare puo' avere una
       variante (en-GB, pt-BR), quella che si conosce no; le due non possono avere la stessa base; si puo' cambiare solo
       la variante, mai la lingua; nasce insieme al suo progetto (si collega uno gia' chiamato come la lingua, o se ne fa uno)
     - un mazzo di lingua sta dentro un corso (course_id), come quelli di studio: nome, padre (dello stesso corso), colore,
       icona, note, data d'esame
     - una parola ha parola (255), traduzione (500; 255 per le figlie), significato (400) e note (3000); l'immagine non passa di qui
     - un esempio e' di una parola o di una figlia: testo (1000) e, se serve, le parti da nascondere nel gioco (gap_text)
   Cancellare un corso toglie tutto quello che e' suo, riga per riga (il registro le annota): il sito lascia in giro le righe
   che non hanno una chiave esterna.
   ===================================================================== */

require_once __DIR__ . '/fc/fc_type.php';
require_once __DIR__ . '/fc/fc_vocab_gap.php';

const SYNC_LING_NOTE_MAX = 3000;

/* ---------------- corsi ---------------- */

/** Il progetto di un corso: uno gia' chiamato come la lingua (non di un altro corso) o uno nuovo. Ritorna l'id o null. */
function sync_lingue_progetto(int $uid, int $corso, string $learn): ?int {
    $nome = fc_lingua_nome($learn, false);
    $es = sec_one("SELECT p.id FROM projects p
                    WHERE p.user_id = ? AND LOWER(p.name) = LOWER(?)
                      AND NOT EXISTS (SELECT 1 FROM fc_vocab_courses k WHERE k.project_id = p.id AND k.id <> ?)
                    ORDER BY p.parent_id IS NOT NULL, p.id LIMIT 1", [$uid, $nome, $corso]);
    if ($es) $pid = (int)$es['id'];
    else {
        $ord = (int)(sec_one("SELECT COALESCE(MAX(custom_order), -1) + 1 AS n FROM projects WHERE user_id = ? AND parent_id IS NULL", [$uid])['n'] ?? 0);
        sec_q("INSERT INTO projects (user_id, parent_id, name, color, color_edited, status, custom_order, created_at)
               VALUES (?, NULL, ?, '#5856D6', 1, 'in_progress', ?, NOW())", [$uid, $nome, $ord]);
        $pid = (int)sec_insert_id();
    }
    if ($pid <= 0) return null;
    sec_q("UPDATE fc_vocab_courses SET project_id = ? WHERE id = ? AND user_id = ?", [$pid, $corso, $uid]);
    return $pid;
}

/** Gli id delle partite di lingua di un corso. */
function sync_lingue_partite(int $uid, int $corso): array {
    return array_map(fn($r) => (int)$r['id'], sec_all("SELECT id FROM fc_games WHERE user_id = ? AND kind = 'vocab' AND course_id = ?", [$uid, $corso]));
}

/** Toglie un corso con tutto quello che e' suo, dal basso. */
function sync_lingue_elimina_corso(int $uid, array $corso): void {
    $c = (int)$corso['id'];
    foreach (sync_lingue_partite($uid, $c) as $g) {
        foreach (['fc_vocab_plays', 'fc_game_durations', 'fc_vocab_game_decks', 'fc_vocab_sequence'] as $t) sec_q("DELETE FROM `$t` WHERE game_id = ?", [$g]);
        sec_q("DELETE FROM fc_games WHERE id = ? AND user_id = ?", [$g, $uid]);
    }
    sec_q("DELETE FROM fc_vocab_records WHERE user_id = ? AND course_id = ?", [$uid, $c]);
    $mazzi = array_map(fn($r) => (int)$r['id'], sec_all("SELECT id FROM fc_vocab_decks WHERE user_id = ? AND course_id = ?", [$uid, $c]));
    foreach (array_reverse($mazzi) as $m) {
        sync_lingue_svuota_mazzo($uid, $m);
        sec_q("DELETE FROM fc_vocab_decks WHERE id = ? AND user_id = ?", [$m, $uid]);
    }
    sec_q("DELETE FROM fc_vocab_courses WHERE id = ? AND user_id = ?", [$c, $uid]);
}

function sync_corsi_applica(int $uid, string $uuid, string $op, array $patch): array {
    $riga = sec_one("SELECT * FROM fc_vocab_courses WHERE user_id = ? AND uuid = ?", [$uid, $uuid]);
    if ($op === 'delete') {
        if ($riga) sync_lingue_elimina_corso($uid, $riga);
        return ['status' => 'applied'];
    }
    if ($er = sync_fc_controlla('fc_vocab_courses', $patch, $riga === null)) return $er;
    if (isset($patch['sort_key']) && !sync_chiave_valida($patch['sort_key'])) return sync_rifiuto('validation', 'sort_key', 'Invalid sort_key');

    $progetto = null; $vuoleProgetto = array_key_exists('project_uuid', $patch);
    if ($vuoleProgetto && $patch['project_uuid'] !== null) {
        [$progetto, $er] = sync_fc_risolvi('projects', $uid, $patch['project_uuid'], 'project_uuid', 'Project');
        if ($er) return $er;
        if (sec_one("SELECT 1 AS x FROM fc_vocab_courses WHERE project_id = ? AND uuid <> ?", [$progetto, $uuid]))
            return sync_rifiuto('forbidden', 'project_uuid', 'That project already belongs to another course');
    }

    if ($riga === null) {
        $conosce = $patch['known_lang'] ?? null; $impara = $patch['learn_lang'] ?? null;
        if (!is_string($conosce) || !is_string($impara) || !fc_lingua_valida($impara) || !fc_lingua_nota_valida($conosce))
            return sync_rifiuto('validation', 'learn_lang', 'known_lang and learn_lang must be languages we offer');
        if (fc_lingua_base($impara) === $conosce) return sync_rifiuto('validation', 'learn_lang', 'You cannot learn the language you already know');
        if (sec_one("SELECT 1 AS x FROM fc_vocab_courses WHERE user_id = ? AND known_lang = ? AND learn_lang = ?", [$uid, $conosce, $impara]))
            return sync_rifiuto('forbidden', 'learn_lang', 'You already have this course');
        $ord = (int)(sec_one("SELECT COALESCE(MAX(custom_order), -1) + 1 AS n FROM fc_vocab_courses WHERE user_id = ?", [$uid])['n'] ?? 0);
        if ($er = sync_fc2_inserisci('fc_vocab_courses', $uuid,
            "INSERT INTO fc_vocab_courses (user_id, known_lang, learn_lang, custom_order) VALUES (?,?,?,?)", [$uid, $conosce, $impara, $ord])) return $er;
        $id = sync_id_da_uuid('fc_vocab_courses', $uid, $uuid);
        if ($id === null) return sync_rifiuto('validation', 'uuid', 'Could not create the course');
        if ($vuoleProgetto && $progetto !== null) sec_q("UPDATE fc_vocab_courses SET project_id = ? WHERE id = ? AND user_id = ?", [$progetto, $id, $uid]);
        elseif (!$vuoleProgetto) {
            try { sync_lingue_progetto($uid, $id, $impara); } catch (Throwable $e) { sec_log_error('sync_lingue', 'progetto: ' . $e->getMessage()); }
        }
    } else {
        $id = (int)$riga['id'];
        if (array_key_exists('known_lang', $patch) && $patch['known_lang'] !== $riga['known_lang']) return sync_rifiuto('validation', 'known_lang', 'known_lang cannot change');
        if (array_key_exists('learn_lang', $patch) && $patch['learn_lang'] !== $riga['learn_lang']) {
            $nuova = $patch['learn_lang'];
            // solo fra varianti della stessa lingua: le parole gia' scritte restano giuste
            if (!is_string($nuova) || !fc_lingua_valida($nuova) || fc_lingua_base($nuova) !== fc_lingua_base($riga['learn_lang']))
                return sync_rifiuto('validation', 'learn_lang', 'Only a variant of the same language is allowed');
            if (sec_one("SELECT 1 AS x FROM fc_vocab_courses WHERE user_id = ? AND known_lang = ? AND learn_lang = ? AND id <> ?", [$uid, $riga['known_lang'], $nuova, $id]))
                return sync_rifiuto('forbidden', 'learn_lang', 'You already have this course');
            sec_q("UPDATE fc_vocab_courses SET learn_lang = ? WHERE id = ? AND user_id = ?", [$nuova, $id, $uid]);
        }
        if ($vuoleProgetto) sec_q("UPDATE fc_vocab_courses SET project_id = ? WHERE id = ? AND user_id = ?", [$progetto, $id, $uid]);
    }
    if (isset($patch['sort_key'])) sync_applica_sort_key('fc_vocab_courses', $uid, $id, $patch['sort_key']);
    return ['status' => 'applied'];
}

/* ---------------- mazzi ---------------- */

function sync_lingue_ramo(int $uid, int $radice): array {
    $ids = [$radice]; $da = [$radice]; $giri = 0;
    while ($da && $giri++ < 60) {
        $segn = implode(',', array_fill(0, count($da), '?'));
        $figli = sec_all("SELECT id FROM fc_vocab_decks WHERE user_id = ? AND parent_id IN ($segn)", array_merge([$uid], $da));
        $da = [];
        foreach ($figli as $f) if (!in_array((int)$f['id'], $ids, true)) { $ids[] = (int)$f['id']; $da[] = (int)$f['id']; }
    }
    return $ids;
}

/** Toglie le parole di un mazzo con tutto quello che portano (figlie, esempi, preferiti, imparate, risposte), riga per riga. */
function sync_lingue_svuota_mazzo(int $uid, int $mazzo): void {
    $carte = array_map(fn($r) => (int)$r['id'], sec_all("SELECT id FROM fc_vocab_cards WHERE category_id = ?", [$mazzo]));
    foreach ($carte as $c) sync_lingue_togli_parola($uid, $c);
}

/** Toglie una parola e le sue figlie, con esempi, preferiti, imparate e risposte date. */
function sync_lingue_togli_parola(int $uid, int $carta): void {
    $figlie = array_map(fn($r) => (int)$r['id_child'], sec_all("SELECT id_child FROM fc_vocab_subcards WHERE parent_id = ?", [$carta]));
    foreach ($figlie as $f) {
        sec_q("DELETE FROM fc_vocab_examples WHERE child_id = ?", [$f]);
        sec_q("DELETE FROM fc_vocab_favorites WHERE user_id = ? AND fc_child_id = ?", [$uid, $f]);
        sec_q("DELETE FROM fc_vocab_learnt WHERE user_id = ? AND is_child = 1 AND fc_image_id = ?", [$uid, $f]);
        sec_q("DELETE FROM fc_vocab_subcards WHERE id_child = ?", [$f]);
    }
    sec_q("DELETE FROM fc_vocab_examples WHERE parent_id = ?", [$carta]);
    sec_q("DELETE FROM fc_vocab_favorites WHERE user_id = ? AND fc_image_id = ?", [$uid, $carta]);
    sec_q("DELETE FROM fc_vocab_learnt WHERE user_id = ? AND is_child = 0 AND fc_image_id = ?", [$uid, $carta]);
    sec_q("DELETE FROM fc_vocab_cards WHERE id = ?", [$carta]);
}

function sync_lingue_mazzi_applica(int $uid, string $uuid, string $op, array $patch): array {
    $riga = sec_one("SELECT * FROM fc_vocab_decks WHERE user_id = ? AND uuid = ?", [$uid, $uuid]);
    if ($op === 'delete') {
        if ($riga) {
            foreach (array_reverse(sync_lingue_ramo($uid, (int)$riga['id'])) as $id) {
                sync_lingue_svuota_mazzo($uid, $id);
                sec_q("DELETE FROM fc_vocab_decks WHERE id = ? AND user_id = ?", [$id, $uid]);
            }
        }
        return ['status' => 'applied'];
    }
    if ($er = sync_fc_controlla('fc_vocab_decks', $patch, $riga === null)) return $er;

    $r = $riga ?: ['name' => '', 'parent_id' => 0, 'color' => '#A0A0A0', 'color_edited' => 0, 'archived' => 0, 'icon' => null,
                   'notes' => null, 'pinned' => 0, 'exam_date' => null, 'course_id' => 0];
    if ($riga === null) {
        if (!array_key_exists('course_uuid', $patch)) return sync_rifiuto('validation', 'course_uuid', 'course_uuid is required');
        [$cid, $er] = sync_fc_risolvi('fc_vocab_courses', $uid, $patch['course_uuid'], 'course_uuid', 'Course');
        if ($er) return $er;
        $r['course_id'] = $cid;
    }
    if (array_key_exists('name', $patch)) {
        $n = sync_fc_testo($patch['name'], 120, false);
        if ($n === false || $n === '') return sync_rifiuto('validation', 'name', 'name cannot be empty');
        $r['name'] = $n;
    } elseif ($riga === null) {
        return sync_rifiuto('validation', 'name', 'name is required');
    }
    if (array_key_exists('parent_uuid', $patch)) {
        if ($patch['parent_uuid'] === null) $r['parent_id'] = 0;
        else {
            [$pid, $er] = sync_fc_risolvi('fc_vocab_decks', $uid, $patch['parent_uuid'], 'parent_uuid', 'Parent deck');
            if ($er) return $er;
            if ($riga && in_array($pid, sync_lingue_ramo($uid, (int)$riga['id']), true)) return sync_rifiuto('validation', 'parent_uuid', 'A deck cannot go inside itself');
            $pm = sec_one("SELECT course_id FROM fc_vocab_decks WHERE id = ?", [$pid]);
            if ((int)($pm['course_id'] ?? 0) !== (int)$r['course_id']) return sync_rifiuto('validation', 'parent_uuid', 'The parent deck belongs to another course');
            $r['parent_id'] = $pid;
        }
    }
    if (array_key_exists('color', $patch)) {
        if (!is_string($patch['color']) || !preg_match('/^#[0-9A-Fa-f]{6}$/', $patch['color'])) return sync_rifiuto('validation', 'color', 'color must be #RRGGBB');
        $r['color'] = strtoupper($patch['color']);
    }
    foreach (['color_edited', 'archived', 'pinned'] as $b) {
        if (!array_key_exists($b, $patch)) continue;
        if (!is_bool($patch[$b])) return sync_rifiuto('validation', $b, "$b must be true or false");
        $r[$b] = $patch[$b] ? 1 : 0;
    }
    if (array_key_exists('icon', $patch)) {
        if ($patch['icon'] === null || $patch['icon'] === '') $r['icon'] = null;
        elseif (!is_string($patch['icon']) || !fc_icona_valida($patch['icon'])) return sync_rifiuto('validation', 'icon', 'Unknown icon');
        else $r['icon'] = $patch['icon'];
    }
    if (array_key_exists('notes', $patch)) {
        $v = sync_fc_testo($patch['notes'], 20000, true);
        if ($v === false) return sync_rifiuto('validation', 'notes', 'notes must be text');
        $r['notes'] = $v;
    }
    if (array_key_exists('exam_date', $patch)) {
        if ($patch['exam_date'] === null || $patch['exam_date'] === '') $r['exam_date'] = null;
        else {
            $v = is_string($patch['exam_date']) ? sec_date($patch['exam_date'], null) : null;
            if ($v === null) return sync_rifiuto('validation', 'exam_date', 'exam_date must be YYYY-MM-DD');
            $r['exam_date'] = $v;
        }
    }

    if ($riga === null) {
        if ($er = sync_fc2_inserisci('fc_vocab_decks', $uuid,
            "INSERT INTO fc_vocab_decks (name, parent_id, color, color_edited, archived, icon, notes, pinned, exam_date, course_id, user_id) VALUES (?,?,?,?,?,?,?,?,?,?,?)",
            [$r['name'], $r['parent_id'], $r['color'], $r['color_edited'], $r['archived'], $r['icon'], $r['notes'], $r['pinned'], $r['exam_date'], $r['course_id'], $uid])) return $er;
    } else {
        sec_q("UPDATE fc_vocab_decks SET name = ?, parent_id = ?, color = ?, color_edited = ?, archived = ?, icon = ?, notes = ?, pinned = ?, exam_date = ? WHERE id = ? AND user_id = ?",
              [$r['name'], $r['parent_id'], $r['color'], $r['color_edited'], $r['archived'], $r['icon'], $r['notes'], $r['pinned'], $r['exam_date'], (int)$riga['id'], $uid]);
    }
    return ['status' => 'applied'];
}

/* ---------------- parole e parole figlie ---------------- */

/** I testi di una parola dal patch (parola, traduzione, significato, note), controllati e tagliati come fa il sito. Ritorna [campi, errore]. */
function sync_lingue_testi(array $patch, bool $figlia, array $r): array {
    $max = ['word' => 255, 'translation' => $figlia ? 255 : 500, 'meaning' => 400, 'notes' => SYNC_LING_NOTE_MAX];
    foreach ($max as $c => $m) {
        if (!array_key_exists($c, $patch)) continue;
        $nullo = ($c === 'meaning' || $c === 'notes' || (!$figlia && $c === 'translation'));
        $v = sync_fc_testo($patch[$c], $m, $nullo);
        if ($v === false) return [$r, sync_rifiuto('validation', $c, "$c must be text")];
        if ($c === 'word' && $v === '') return [$r, sync_rifiuto('validation', 'word', 'word cannot be empty')];
        if ($c === 'translation' && $figlia && $v === null) $v = '';
        $r[$c] = $v;
    }
    return [$r, null];
}

function sync_lingue_carte_applica(int $uid, string $uuid, string $op, array $patch): array {
    $riga = sec_one("SELECT * FROM fc_vocab_cards WHERE uuid = ? AND category_id IN (SELECT id FROM fc_vocab_decks WHERE user_id = ?)", [$uuid, $uid]);
    if ($op === 'delete') {
        if ($riga) sync_lingue_togli_parola($uid, (int)$riga['id']);
        return ['status' => 'applied'];
    }
    if ($er = sync_fc_controlla('fc_vocab_cards', $patch, $riga === null)) return $er;

    $r = $riga ?: ['category_id' => 0, 'word' => '', 'translation' => null, 'meaning' => null, 'notes' => null, 'custom_order' => 0];
    $spostata = false;
    if (array_key_exists('category_uuid', $patch)) {
        [$cid, $er] = sync_fc_risolvi('fc_vocab_decks', $uid, $patch['category_uuid'], 'category_uuid', 'Deck');
        if ($er) return $er;
        if ($riga && (int)$riga['category_id'] !== $cid) $spostata = true;
        $r['category_id'] = $cid;
    } elseif ($riga === null) {
        return sync_rifiuto('validation', 'category_uuid', 'category_uuid is required');
    }
    [$r, $er] = sync_lingue_testi($patch, false, $r);
    if ($er) return $er;
    if ($riga === null && trim((string)$r['word']) === '') return sync_rifiuto('validation', 'word', 'word is required');
    if ($riga === null || $spostata) {
        $m = sec_one("SELECT COALESCE(MAX(custom_order), 0) + 1 AS n FROM fc_vocab_cards WHERE category_id = ?", [$r['category_id']]);
        $r['custom_order'] = (int)($m['n'] ?? 1);
    }
    if ($riga === null) {
        if ($er = sync_fc2_inserisci('fc_vocab_cards', $uuid,
            "INSERT INTO fc_vocab_cards (category_id, word, translation, meaning, notes, is_father, custom_order) VALUES (?,?,?,?,?,0,?)",
            [$r['category_id'], $r['word'], $r['translation'], $r['meaning'], $r['notes'], $r['custom_order']])) return $er;
    } else {
        sec_q("UPDATE fc_vocab_cards SET category_id = ?, word = ?, translation = ?, meaning = ?, notes = ?, custom_order = ? WHERE id = ?",
              [$r['category_id'], $r['word'], $r['translation'], $r['meaning'], $r['notes'], $r['custom_order'], (int)$riga['id']]);
    }
    return ['status' => 'applied'];
}

function sync_lingue_ricalcola_padre(int $idPadre): void {
    $n = (int)(sec_one("SELECT COUNT(*) AS n FROM fc_vocab_subcards WHERE parent_id = ?", [$idPadre])['n'] ?? 0);
    $cur = sec_one("SELECT is_father FROM fc_vocab_cards WHERE id = ?", [$idPadre]);
    if ($cur && (int)$cur['is_father'] !== ($n > 0 ? 1 : 0)) sec_q("UPDATE fc_vocab_cards SET is_father = ? WHERE id = ?", [$n > 0 ? 1 : 0, $idPadre]);
}

function sync_lingue_figlie_applica(int $uid, string $uuid, string $op, array $patch): array {
    $riga = sec_one("SELECT s.* FROM fc_vocab_subcards s JOIN fc_vocab_cards c ON c.id = s.parent_id
                     JOIN fc_vocab_decks d ON d.id = c.category_id WHERE s.uuid = ? AND d.user_id = ?", [$uuid, $uid]);
    if ($op === 'delete') {
        if ($riga) {
            $f = (int)$riga['id_child'];
            sec_q("DELETE FROM fc_vocab_examples WHERE child_id = ?", [$f]);
            sec_q("DELETE FROM fc_vocab_favorites WHERE user_id = ? AND fc_child_id = ?", [$uid, $f]);
            sec_q("DELETE FROM fc_vocab_learnt WHERE user_id = ? AND is_child = 1 AND fc_image_id = ?", [$uid, $f]);
            sec_q("DELETE FROM fc_vocab_subcards WHERE id_child = ?", [$f]);
            sync_lingue_ricalcola_padre((int)$riga['parent_id']);
        }
        return ['status' => 'applied'];
    }
    if ($er = sync_fc_controlla('fc_vocab_subcards', $patch, $riga === null)) return $er;

    $r = $riga ?: ['parent_id' => 0, 'word' => '', 'translation' => '', 'meaning' => null, 'notes' => null];
    if ($riga === null) {
        if (!array_key_exists('parent_uuid', $patch)) return sync_rifiuto('validation', 'parent_uuid', 'parent_uuid is required');
        [$pid, $er] = sync_fc_risolvi('fc_vocab_cards', $uid, $patch['parent_uuid'], 'parent_uuid', 'Parent word');
        if ($er) return $er;
        $r['parent_id'] = $pid;
    }
    [$r, $er] = sync_lingue_testi($patch, true, $r);
    if ($er) return $er;
    if ($riga === null && trim((string)$r['word']) === '') return sync_rifiuto('validation', 'word', 'word is required');
    if ($riga === null) {
        $m = sec_one("SELECT COALESCE(MAX(custom_order), 0) + 1 AS n FROM fc_vocab_subcards WHERE parent_id = ?", [$r['parent_id']]);
        if ($er = sync_fc2_inserisci('fc_vocab_subcards', $uuid,
            "INSERT INTO fc_vocab_subcards (parent_id, word, translation, meaning, notes, custom_order) VALUES (?,?,?,?,?,?)",
            [$r['parent_id'], $r['word'], $r['translation'], $r['meaning'], $r['notes'], (int)($m['n'] ?? 1)])) return $er;
        sync_lingue_ricalcola_padre((int)$r['parent_id']);
    } else {
        sec_q("UPDATE fc_vocab_subcards SET word = ?, translation = ?, meaning = ?, notes = ? WHERE id_child = ?",
              [$r['word'], $r['translation'], $r['meaning'], $r['notes'], (int)$riga['id_child']]);
    }
    return ['status' => 'applied'];
}

/* ---------------- esempi, preferiti, imparate ---------------- */

/** Una parola o una parola figlia, indicate da due uuid (esattamente uno dei due). Ritorna [id parola|null, id figlia|null, errore|null]. */
function sync_lingue_bersaglio(int $uid, array $patch, string $campoCarta, string $campoFiglia): array {
    $c = $patch[$campoCarta] ?? null; $f = $patch[$campoFiglia] ?? null;
    if (($c === null) === ($f === null)) return [null, null, sync_rifiuto('validation', $campoCarta, "Exactly one of $campoCarta and $campoFiglia is required")];
    if ($c !== null) {
        [$id, $er] = sync_fc_risolvi('fc_vocab_cards', $uid, $c, $campoCarta, 'Word');
        return [$id, null, $er];
    }
    [$id, $er] = sync_fc_risolvi('fc_vocab_subcards', $uid, $f, $campoFiglia, 'Child word');
    return [null, $id, $er];
}

function sync_lingue_esempi_applica(int $uid, string $uuid, string $op, array $patch): array {
    $riga = sec_one("SELECT e.* FROM fc_vocab_examples e WHERE e.uuid = ? AND " . sync_prop('fc_vocab_examples', 'e') . " = ?", [$uuid, $uid]);
    if ($op === 'delete') {
        if ($riga) sec_q("DELETE FROM fc_vocab_examples WHERE id = ?", [(int)$riga['id']]);
        return ['status' => 'applied'];
    }
    if ($er = sync_fc_controlla('fc_vocab_examples', $patch, $riga === null)) return $er;
    $testo = $riga ? $riga['example_text'] : null; $gap = $riga ? $riga['gap_text'] : null;
    if (array_key_exists('example_text', $patch)) {
        $testo = sync_fc_testo($patch['example_text'], 1000, true);
        if ($testo === false) return sync_rifiuto('validation', 'example_text', 'example_text must be text');
    }
    if (array_key_exists('gap_text', $patch)) {
        if ($patch['gap_text'] !== null && !is_string($patch['gap_text'])) return sync_rifiuto('validation', 'gap_text', 'gap_text must be text');
        $g = fcv_gap_pulisci((string)$patch['gap_text']);
        $gap = $g !== '' ? $g : null;
    }
    if ($riga) {
        sec_q("UPDATE fc_vocab_examples SET example_text = ?, gap_text = ? WHERE id = ?", [$testo, $gap, (int)$riga['id']]);
        return ['status' => 'applied'];
    }
    [$cid, $fid, $er] = sync_lingue_bersaglio($uid, $patch, 'card_uuid', 'child_uuid');
    if ($er) return $er;
    if ($er = sync_fc2_inserisci('fc_vocab_examples', $uuid,
        "INSERT INTO fc_vocab_examples (parent_id, child_id, example_text, gap_text) VALUES (?,?,?,?)", [$cid, $fid, $testo, $gap])) return $er;
    return ['status' => 'applied'];
}

function sync_lingue_preferiti_applica(int $uid, string $uuid, string $op, array $patch): array {
    $riga = sec_one("SELECT * FROM fc_vocab_favorites WHERE user_id = ? AND uuid = ?", [$uid, $uuid]);
    if ($op === 'delete') {
        if ($riga) sec_q("DELETE FROM fc_vocab_favorites WHERE id = ? AND user_id = ?", [(int)$riga['id'], $uid]);
        return ['status' => 'applied'];
    }
    if ($er = sync_fc_controlla('fc_vocab_favorites', $patch, $riga === null)) return $er;
    if ($riga) return ['status' => 'applied'];

    [$cid, $fid, $er] = sync_lingue_bersaglio($uid, $patch, 'card_uuid', 'child_uuid');
    if ($er) return $er;
    $data = date('Y-m-d');
    if (array_key_exists('favorited_date', $patch)) {
        $data = sync_fc2_data($patch['favorited_date']);
        if ($data === null) return sync_rifiuto('validation', 'favorited_date', 'favorited_date must be YYYY-MM-DD');
    }
    $vecchia = $cid !== null
        ? sec_one("SELECT id FROM fc_vocab_favorites WHERE user_id = ? AND fc_image_id = ?", [$uid, $cid])
        : sec_one("SELECT id FROM fc_vocab_favorites WHERE user_id = ? AND fc_child_id = ?", [$uid, $fid]);
    if ($vecchia) sec_q("DELETE FROM fc_vocab_favorites WHERE id = ? AND user_id = ?", [(int)$vecchia['id'], $uid]);
    if ($er = sync_fc2_inserisci('fc_vocab_favorites', $uuid,
        "INSERT INTO fc_vocab_favorites (user_id, fc_image_id, fc_child_id, favorited_date) VALUES (?,?,?,?)", [$uid, $cid, $fid, $data])) return $er;
    return ['status' => 'applied'];
}

function sync_lingue_imparate_applica(int $uid, string $uuid, string $op, array $patch): array {
    if ($op === 'delete') {
        sec_q("DELETE FROM fc_vocab_learnt WHERE user_id = ? AND uuid = ?", [$uid, $uuid]);
        return ['status' => 'applied'];
    }
    $riga = sec_one("SELECT * FROM fc_vocab_learnt WHERE user_id = ? AND uuid = ?", [$uid, $uuid]);
    if ($er = sync_fc_controlla('fc_vocab_learnt', $patch, $riga === null)) return $er;
    $data = null;
    if (array_key_exists('learnt_date', $patch)) {
        $data = sync_fc2_data($patch['learnt_date']);
        if ($data === null) return sync_rifiuto('validation', 'learnt_date', 'learnt_date must be YYYY-MM-DD');
    }
    if ($riga) {
        if ($data !== null) sec_q("UPDATE fc_vocab_learnt SET learnt_date = ? WHERE id = ? AND user_id = ?", [$data, (int)$riga['id'], $uid]);
        return ['status' => 'applied'];
    }
    if (!array_key_exists('target_uuid', $patch) || !array_key_exists('is_child', $patch)) return sync_rifiuto('validation', 'target_uuid', 'target_uuid and is_child are required');
    if (!is_bool($patch['is_child'])) return sync_rifiuto('validation', 'is_child', 'is_child must be true or false');
    $figlia = $patch['is_child'] ? 1 : 0;
    [$id, $er] = $figlia
        ? sync_fc_risolvi('fc_vocab_subcards', $uid, $patch['target_uuid'], 'target_uuid', 'Child word')
        : sync_fc_risolvi('fc_vocab_cards', $uid, $patch['target_uuid'], 'target_uuid', 'Word');
    if ($er) return $er;
    if (sync_uuid_imparata($uid, $patch['target_uuid'], $figlia) !== $uuid) return sync_rifiuto('validation', 'uuid', 'uuid does not match the word');
    sec_q("INSERT INTO fc_vocab_learnt (user_id, fc_image_id, is_child, learnt_date) VALUES (?, ?, ?, ?)
           ON DUPLICATE KEY UPDATE learnt_date = LEAST(learnt_date, VALUES(learnt_date))", [$uid, $id, $figlia, $data ?? date('Y-m-d')]);
    return ['status' => 'applied'];
}
