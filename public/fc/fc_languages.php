<?php
/* ============================================================================
   fc_languages.php — LE RICHIESTE DEI CORSI DI LINGUA (non e' piu' una pagina)

   Prima era la pagina "Lingue": un riquadro per corso, con le parole e la serie.
   Era un doppione di fc_home.php, che ora ha tutto (le righe dei corsi, il foglio
   per aggiungere una lingua, il menu del corso). Qui restano i POST che quei
   fogli mandano (fc_languages.js): aggiungi, cambia accento, collega progetto,
   elimina, riordina. Un GET rimanda a fc_home.php.

   Aggiungere una lingua deve costare un tocco: la lingua di partenza e' gia'
   la tua (quella dell'interfaccia), le piu' studiate sono in vista prima
   ancora di scrivere, e la ricerca trova "tedesco", "deutsch", "german" o
   "de" senza badare agli accenti. Il corso nuovo porta con se' il suo
   progetto in progetti.php, cosi' il tempo passato sulla lingua finisce nel
   pomodoro senza doverlo collegare a mano.
   ============================================================================ */

require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/auth_check.php';
$current_user_id = (int)$utente_corrente['id'];
$conn = sec_db();
require_once __DIR__ . '/../../lib/fc/fc_type.php';
fc_usa_tipo('vocab');


/* ----------------------------------------------------------------------------
   IL PROGETTO DEL CORSO
   Se esiste gia' un progetto con il nome della lingua (chi studia inglese ha
   spesso gia' "Inglese" fra i progetti) si collega quello invece di crearne
   un doppione. Solo se non e' gia' di un altro corso: due corsi sullo stesso
   progetto mescolerebbero i loro minuti.
   ---------------------------------------------------------------------------- */
function fcl_progetto_per_corso(int $uid, array $corso): ?int {
    $nome = fc_lingua_nome($corso['learn_lang'], false);
    $esistente = sec_one("SELECT p.id FROM projects p
                           WHERE p.user_id = ? AND LOWER(p.name) = LOWER(?)
                             AND NOT EXISTS (SELECT 1 FROM fc_vocab_courses k WHERE k.project_id = p.id AND k.id <> ?)
                           ORDER BY p.parent_id IS NOT NULL, p.id LIMIT 1",
                         [$uid, $nome, (int)$corso['id']], 'isi');
    if ($esistente) {
        $pid = (int)$esistente['id'];
    } else {
        $ordine = (int)(sec_one("SELECT COALESCE(MAX(custom_order), -1) + 1 AS n FROM projects
                                  WHERE user_id = ? AND parent_id IS NULL", [$uid], 'i')['n'] ?? 0);
        sec_q("INSERT INTO projects (user_id, parent_id, name, color, color_edited, status, custom_order, created_at)
               VALUES (?, NULL, ?, '#5856D6', 1, 'in_progress', ?, NOW())", [$uid, $nome, $ordine], 'isi');
        $pid = sec_insert_id();
    }
    if ($pid <= 0) return null;
    sec_q("UPDATE fc_vocab_courses SET project_id = ? WHERE id = ? AND user_id = ?", [$pid, (int)$corso['id'], $uid], 'iii');
    return $pid;
}


/* ----------------------------------------------------------------------------
   AZIONI (chiamate dalla pagina con fetch: rispondono JSON)
   ---------------------------------------------------------------------------- */
$azione = sec_enum($_POST['action'] ?? null, ['add', 'project', 'variant', 'delete', 'order'], '');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $azione !== '') {
    sec_guard_write($current_user_id, 'fc_courses', 0, 60, 60);

    if ($azione === 'add') {
        $impara  = sec_str($_POST['learn'] ?? '', 10);
        $conosce = sec_str($_POST['known'] ?? '', 10);
        if (!fc_lingua_valida($impara) || !fc_lingua_nota_valida($conosce)) {
            sec_die_json(t('fchub.err_lingua'), 422, 'lang');
        }
        if (fc_lingua_base($impara) === $conosce) {
            sec_die_json(t('fchub.err_stessa'), 422, 'same');
        }
        /* Gia' presente: non e' un errore, si entra nel corso che c'e'. Un
           doppio tocco o un "aggiungi" su una lingua dimenticata devono
           portare allo stesso posto. */
        $c = sec_one("SELECT id FROM fc_vocab_courses WHERE user_id = ? AND known_lang = ? AND learn_lang = ?",
                     [$current_user_id, $conosce, $impara], 'iss');
        if (!$c) {
            $ordine = (int)(sec_one("SELECT COALESCE(MAX(custom_order), -1) + 1 AS n FROM fc_vocab_courses WHERE user_id = ?",
                                    [$current_user_id], 'i')['n'] ?? 0);
            sec_q("INSERT INTO fc_vocab_courses (user_id, known_lang, learn_lang, custom_order) VALUES (?, ?, ?, ?)",
                  [$current_user_id, $conosce, $impara, $ordine], 'issi');
            $id = sec_insert_id();
            $corso = fc_leggi_corso($id, $current_user_id);
            if ($corso) {
                try { fcl_progetto_per_corso($current_user_id, $corso); }
                catch (Throwable $e) { sec_log_error('fc_languages', 'progetto: ' . $e->getMessage()); }
            }
        } else {
            $id = (int)$c['id'];
        }
        sec_json(['ok' => true, 'id' => $id, 'url' => 'fc_decks.php?course=' . $id]);
    }

    $corso = fc_leggi_corso(sec_int($_POST['course'] ?? 0, 0), $current_user_id);
    if (!$corso && $azione !== 'order') sec_die_json(t('fchub.err_non_trovato'), 404, 'not_found');

    if ($azione === 'project') {
        $pid = fcl_progetto_per_corso($current_user_id, $corso);
        sec_json(['ok' => $pid !== null, 'project_id' => $pid]);
    }

    if ($azione === 'variant') {
        /* Solo fra varianti della stessa lingua: cambiano voce e bandiera, le
           parole restano giuste. Passare da inglese a tedesco invece
           renderebbe sbagliate tutte le parole gia' scritte. */
        $nuova = sec_str($_POST['learn'] ?? '', 10);
        if (!fc_lingua_valida($nuova) || fc_lingua_base($nuova) !== fc_lingua_base($corso['learn_lang'])) {
            sec_die_json(t('fchub.err_lingua'), 422, 'lang');
        }
        $doppio = sec_one("SELECT id FROM fc_vocab_courses WHERE user_id = ? AND known_lang = ? AND learn_lang = ? AND id <> ?",
                          [$current_user_id, $corso['known_lang'], $nuova, (int)$corso['id']], 'issi');
        if ($doppio) sec_die_json(t('fchub.err_gia_corso'), 409, 'exists');
        sec_q("UPDATE fc_vocab_courses SET learn_lang = ? WHERE id = ? AND user_id = ?",
              [$nuova, (int)$corso['id'], $current_user_id], 'sii');
        sec_json(['ok' => true]);
    }

    if ($azione === 'delete') {
        /* Le carte non hanno una chiave esterna verso il mazzo (come quelle di
           studio): cancellare solo i mazzi le lascerebbe orfane. Si tolgono
           prima le carte — figlie, esempi, giocate e preferiti seguono da soli
           per cascata — poi le parole imparate, poi il corso, che si porta via
           mazzi e partite. */
        $ids = array_map(fn($r) => (int)$r['id'],
                         sec_all("SELECT id FROM fc_vocab_cards WHERE category_id IN
                                    (SELECT id FROM fc_vocab_decks WHERE course_id = ? AND user_id = ?)",
                                 [(int)$corso['id'], $current_user_id], 'ii'));
        foreach (array_chunk($ids, 500) as $blocco) {
            $ph = implode(',', array_fill(0, count($blocco), '?'));
            /* le sottocarte spariscono per cascata con il padre, ma il loro "imparata"
               (is_child = 1) sta in una riga a parte: va tolto prima, finche' si sanno gli id */
            sec_q("DELETE FROM fc_vocab_learnt WHERE user_id = ? AND is_child = 1
                      AND fc_image_id IN (SELECT id_child FROM fc_vocab_subcards WHERE parent_id IN ($ph))",
                  array_merge([$current_user_id], $blocco));
            sec_q("DELETE FROM fc_vocab_learnt WHERE user_id = ? AND is_child = 0 AND fc_image_id IN ($ph)", array_merge([$current_user_id], $blocco));
            sec_q("DELETE FROM fc_vocab_cards WHERE id IN ($ph)", $blocco);
        }
        sec_q("DELETE FROM fc_vocab_courses WHERE id = ? AND user_id = ?", [(int)$corso['id'], $current_user_id], 'ii');
        sec_audit('fc_course_delete', 'ok', 'corso ' . (int)$corso['id'] . ', parole ' . count($ids));
        sec_json(['ok' => true]);
    }

    if ($azione === 'order') {
        $n = 0;
        foreach (explode(',', (string)($_POST['ids'] ?? '')) as $grezzo) {
            $id = sec_int($grezzo, 0);
            if ($id <= 0) continue;
            sec_q("UPDATE fc_vocab_courses SET custom_order = ? WHERE id = ? AND user_id = ?", [$n++, $id, $current_user_id], 'iii');
        }
        sec_json(['ok' => true]);
    }
}


/* ----------------------------------------------------------------------------
   NIENTE PAGINA
   Chi arriva qui con un GET (un vecchio segnalibro, "fc_languages.php#add",
   un redirect del .htaccess) finisce sulla pagina principale: le lingue e il
   foglio per aggiungerne una stanno li'. Il frammento "#add" lo conserva il
   browser da solo, perche' il Location non ne ha uno.
   ---------------------------------------------------------------------------- */
header('Location: ' . PWA_BASE . 'fc/fc_home.php');
exit;
