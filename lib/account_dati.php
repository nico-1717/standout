<?php
/* =====================================================================
   STANDOUT — i dati di un utente: scaricarli ed eliminarli (GDPR).

   Due funzioni pubbliche, una per diritto:
     account_esporta($uid)  -> percorso di un file ZIP con tutti i dati
     account_elimina($uid)  -> cancella davvero: righe, file, tutto

   COME NON DIMENTICARE UNA TABELLA. Le tabelle con una colonna `user_id`
   (la grande maggioranza) si trovano da sole interrogando il database: una
   tabella nuova con user_id entra in esportazione e in cancellazione senza
   toccare niente. Restano le tabelle che NON hanno user_id e appartengono a un
   utente per un'altra strada (le carte stanno nel mazzo, il mazzo e' dell'utente;
   le risposte stanno nella partita...). Quelle sono elencate qui sotto, a
   mano, in ACCOUNT_CATENE, nell'ordine in cui vanno cancellate (prima i
   figli). Una tabella senza user_id che non e' ne' li' ne' fra i dati globali
   (ACCOUNT_TABELLE_GLOBALI) fa fallire tools/controlla_dati_utente.py: e' il
   segnale che il giorno in cui la si aggiunge va deciso di chi e'.
   ===================================================================== */

/* Le tabelle senza user_id che appartengono a un utente per via di un'altra
   tabella. [tabella => condizione SQL su un utente ?]; ogni `?` e' lo stesso id
   utente. Ordine = ordine di cancellazione (i figli prima dei genitori). */
const ACCOUNT_CATENE = [
    'ai_job_steps'        => 'job_id IN (SELECT id FROM ai_jobs WHERE user_id = ?)',
    'fc_study_ai_child'   => 'child_id IN (SELECT s.id_child FROM fc_study_subcards s JOIN fc_study_cards c ON c.id = s.parent_id JOIN fc_study_decks d ON d.id = c.category_id WHERE d.user_id = ?)',
    'fc_study_ai'         => 'card_id IN (SELECT c.id FROM fc_study_cards c JOIN fc_study_decks d ON d.id = c.category_id WHERE d.user_id = ?)',
    'fc_study_examples'   => '(parent_id IN (SELECT c.id FROM fc_study_cards c JOIN fc_study_decks d ON d.id = c.category_id WHERE d.user_id = ?) OR child_id IN (SELECT s.id_child FROM fc_study_subcards s JOIN fc_study_cards c ON c.id = s.parent_id JOIN fc_study_decks d ON d.id = c.category_id WHERE d.user_id = ?))',
    'fc_study_plays'      => 'game_id IN (SELECT id FROM fc_games WHERE user_id = ?)',
    'fc_study_sequence'   => 'game_id IN (SELECT id FROM fc_games WHERE user_id = ?)',
    'fc_study_game_decks' => 'game_id IN (SELECT id FROM fc_games WHERE user_id = ?)',
    'fc_vocab_plays'      => 'game_id IN (SELECT id FROM fc_games WHERE user_id = ?)',
    'fc_vocab_sequence'   => 'game_id IN (SELECT id FROM fc_games WHERE user_id = ?)',
    'fc_vocab_game_decks' => 'game_id IN (SELECT id FROM fc_games WHERE user_id = ?)',
    'fc_game_durations'   => 'game_id IN (SELECT id FROM fc_games WHERE user_id = ?)',
    'fc_study_deck_projects' => 'fc_category_id IN (SELECT id FROM fc_study_decks WHERE user_id = ?)',
    'fc_study_subcards'   => 'parent_id IN (SELECT c.id FROM fc_study_cards c JOIN fc_study_decks d ON d.id = c.category_id WHERE d.user_id = ?)',
    'fc_study_cards'      => 'category_id IN (SELECT id FROM fc_study_decks WHERE user_id = ?)',
    'fc_vocab_examples'   => '(parent_id IN (SELECT c.id FROM fc_vocab_cards c JOIN fc_vocab_decks d ON d.id = c.category_id WHERE d.user_id = ?) OR child_id IN (SELECT s.id_child FROM fc_vocab_subcards s JOIN fc_vocab_cards c ON c.id = s.parent_id JOIN fc_vocab_decks d ON d.id = c.category_id WHERE d.user_id = ?))',
    'fc_vocab_subcards'   => 'parent_id IN (SELECT c.id FROM fc_vocab_cards c JOIN fc_vocab_decks d ON d.id = c.category_id WHERE d.user_id = ?)',
    'fc_vocab_cards'      => 'category_id IN (SELECT id FROM fc_vocab_decks WHERE user_id = ?)',
    'friends'             => '(user_id_a = ? OR user_id_b = ?)',
    'friends_notifications' => '(from_user_id = ? OR to_user_id = ?)',
];

/* Tabelle senza utente: dati comuni, cache o contatori. Niente da esportare
   ne' da cancellare per un singolo account. */
const ACCOUNT_TABELLE_GLOBALI = [
    'ai_perf_stats', 'fc_image_meta', 'habit_achievements', 'login_attempts',
    'place_cache', 'plans', 'rate_limits', 'roles', 'weather_cache', 'users',
];

/* Una colonna con uno di questi nomi non esce mai nell'esportazione: sono
   segreti o chiavi, non "dati dell'utente" (la password cifrata, i token del
   ricordami, i token di Google). */
const ACCOUNT_COLONNE_SEGRETE = '/(token|secret|password|hash|refresh|api_?key|_enc$|salt|verifier|selector)/i';

/** Le tabelle del database con una colonna user_id (tutte tranne `users`). */
function account_tabelle_con_utente(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    foreach (sec_all("SELECT c.TABLE_NAME AS t FROM information_schema.COLUMNS c
                        JOIN information_schema.TABLES t ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME AND t.TABLE_TYPE = 'BASE TABLE'
                       WHERE c.TABLE_SCHEMA = DATABASE() AND c.COLUMN_NAME = 'user_id' AND c.TABLE_NAME <> 'users'
                       ORDER BY c.TABLE_NAME") as $r) {
        $cache[] = (string)$r['t'];
    }
    return $cache;
}

/** Le colonne di una tabella che si possono esportare (non segrete, non generate). */
function account_colonne_esportabili(string $tabella): array {
    $out = [];
    foreach (sec_all("SELECT COLUMN_NAME AS c, EXTRA AS e FROM information_schema.COLUMNS
                       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION", [$tabella]) as $r) {
        if (stripos((string)$r['e'], 'GENERATED') !== false) continue;
        if (preg_match(ACCOUNT_COLONNE_SEGRETE, (string)$r['c'])) continue;
        $out[] = (string)$r['c'];
    }
    return $out;
}

/** Quanti segnaposto `?` ha una condizione: quante volte va passato l'id utente. */
function account_n_segnaposto(string $sql): int { return substr_count($sql, '?'); }

/**
 * I file caricati da un utente, come [cartella, nome]. Si leggono PRIMA di
 * cancellare le righe, che sono l'unico posto dove si sa quali file sono suoi.
 * Le cartelle sono quelle in cui il codice li scrive (fc_card_edit.php,
 * fc_examples_api.php, todo_api.php).
 */
function account_file_utente(int $uid): array {
    $img_studio = FC_IMAGE_PATH_INSERT;
    $img_vocab  = IMAGE_PATH_INSERT;
    $audio      = STANDOUT_DATA_DIR . 'flashcard_audio/';
    $img_esempi = STANDOUT_DATA_DIR . 'example_images/';
    $tobuy      = STANDOUT_DATA_DIR . 'uploads/tobuy/';

    $carte_studio  = "SELECT c.* FROM fc_study_cards c JOIN fc_study_decks d ON d.id = c.category_id WHERE d.user_id = ?";
    $figlie_studio = "SELECT s.* FROM fc_study_subcards s JOIN fc_study_cards c ON c.id = s.parent_id JOIN fc_study_decks d ON d.id = c.category_id WHERE d.user_id = ?";
    $carte_vocab   = "SELECT c.* FROM fc_vocab_cards c JOIN fc_vocab_decks d ON d.id = c.category_id WHERE d.user_id = ?";
    $figlie_vocab  = "SELECT s.* FROM fc_vocab_subcards s JOIN fc_vocab_cards c ON c.id = s.parent_id JOIN fc_vocab_decks d ON d.id = c.category_id WHERE d.user_id = ?";

    $trovati = [];
    $aggiungi = function (string $cartella, $nome) use (&$trovati) {
        $nome = basename((string)$nome);
        if ($nome === '' || $nome === '.' || $nome === '..') return;
        $trovati[$cartella . $nome] = [$cartella, $nome];
    };

    foreach ([$carte_studio, $figlie_studio] as $q) {
        foreach (sec_all($q, [$uid]) as $r) {
            $aggiungi($img_studio, $r['question_image'] ?? ''); $aggiungi($img_studio, $r['answer_image'] ?? '');
            $aggiungi($audio, $r['question_audio'] ?? '');      $aggiungi($audio, $r['answer_audio'] ?? '');
        }
    }
    foreach ([$carte_vocab, $figlie_vocab] as $q) {
        foreach (sec_all($q, [$uid]) as $r) $aggiungi($img_vocab, $r['image'] ?? '');
    }
    foreach (['fc_study_examples', 'fc_vocab_examples'] as $tab) {
        foreach (sec_all("SELECT example_image, example_audio FROM $tab WHERE " . ACCOUNT_CATENE[$tab], array_fill(0, account_n_segnaposto(ACCOUNT_CATENE[$tab]), $uid)) as $r) {
            $aggiungi($img_esempi, $r['example_image'] ?? ''); $aggiungi($audio, $r['example_audio'] ?? '');
        }
    }
    foreach (sec_all("SELECT buy_image FROM todos WHERE user_id = ? AND buy_image IS NOT NULL AND buy_image <> ''", [$uid]) as $r) {
        $aggiungi($tobuy, $r['buy_image']);
    }
    return array_values($trovati);
}

/* ---------------------------------------------------------------------
   ESPORTAZIONE
   --------------------------------------------------------------------- */

/**
 * Crea un file ZIP con i dati dell'utente e ritorna il suo percorso (da
 * cancellare dopo l'invio). Dentro: dati.json (una chiave per tabella),
 * LEGGIMI.txt e la cartella file/ con le immagini e gli audio dell'utente.
 * Il file zip sta nella cartella temporanea del sistema, con un nome casuale.
 */
function account_esporta(int $uid): string {
    if (!class_exists('ZipArchive')) throw new RuntimeException('ZipArchive mancante');
    @set_time_limit(120);
    @ini_set('memory_limit', '384M');

    $dati = ['esportato_il' => gmdate('c'), 'utente' => null, 'tabelle' => []];

    /* l'utente, senza password ne' token */
    $cols = account_colonne_esportabili('users');
    $u = sec_one("SELECT `" . implode('`, `', $cols) . "` FROM users WHERE id = ?", [$uid]);
    $dati['utente'] = $u ?: null;

    /* le tabelle con user_id */
    foreach (account_tabelle_con_utente() as $tab) {
        $cols = account_colonne_esportabili($tab);
        if (!$cols) continue;
        $righe = sec_all("SELECT `" . implode('`, `', $cols) . "` FROM `$tab` WHERE user_id = ?", [$uid]);
        if ($righe) $dati['tabelle'][$tab] = $righe;
    }
    /* le tabelle che appartengono all'utente per un'altra strada */
    foreach (ACCOUNT_CATENE as $tab => $cond) {
        $cols = account_colonne_esportabili($tab);
        if (!$cols) continue;
        $righe = sec_all("SELECT `" . implode('`, `', $cols) . "` FROM `$tab` WHERE $cond", array_fill(0, account_n_segnaposto($cond), $uid));
        if ($righe) $dati['tabelle'][$tab] = $righe;
    }

    $percorso = tempnam(sys_get_temp_dir(), 'standout_export_');
    if ($percorso === false) throw new RuntimeException('file temporaneo non creato');
    $zip = new ZipArchive();
    if ($zip->open($percorso, ZipArchive::OVERWRITE) !== true) throw new RuntimeException('zip non aperto');

    $zip->addFromString('dati.json', json_encode($dati, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE));
    $zip->addFromString('LEGGIMI.txt',
        "Standout - i tuoi dati / your data\n\n"
      . "dati.json: tutto quello che hai inserito, una voce per tabella. Le date sono in UTC dove non indicato.\n"
      . "           everything you entered, one entry per table.\n"
      . "file/:     le tue immagini e i tuoi audio, nelle cartelle in cui li tiene il sito.\n"
      . "           your images and audio.\n"
      . "La password e i codici di accesso non sono inclusi. / Your password and access codes are not included.\n");

    foreach (account_file_utente($uid) as [$cartella, $nome]) {
        $f = rtrim($cartella, '/') . '/' . $nome;
        if (is_file($f)) $zip->addFile($f, 'file/' . basename(rtrim($cartella, '/')) . '/' . $nome);
    }
    $zip->close();
    return $percorso;
}

/* ---------------------------------------------------------------------
   CANCELLAZIONE
   --------------------------------------------------------------------- */

/**
 * Cancella l'utente e tutto quello che e' suo. Righe prima, in una sola
 * transazione (o tutto o niente); file dopo, perche' un file non si puo' "annullare".
 * La password va verificata DA CHI CHIAMA: qui non si guarda.
 *
 * @return array{righe:int, file:int}
 */
function account_elimina(int $uid): array {
    if ($uid <= 0) throw new InvalidArgumentException('utente non valido');
    $conn = sec_db();

    /* da sapere prima che le righe spariscano */
    $file = account_file_utente($uid);
    $u = sec_one("SELECT username, email FROM users WHERE id = ?", [$uid]);
    $righe = 0;

    mysqli_begin_transaction($conn);
    try {
        foreach (ACCOUNT_CATENE as $tab => $cond) {
            sec_q("DELETE FROM `$tab` WHERE $cond", array_fill(0, account_n_segnaposto($cond), $uid));
            $righe += max(0, mysqli_affected_rows($conn));
        }
        foreach (account_tabelle_con_utente() as $tab) {
            sec_q("DELETE FROM `$tab` WHERE user_id = ?", [$uid]);
            $righe += max(0, mysqli_affected_rows($conn));
        }
        /* i tentativi di accesso non hanno user_id: portano nome utente o email */
        if ($u) {
            sec_q("DELETE FROM login_attempts WHERE identifier IN (?, ?)", [(string)$u['username'], (string)($u['email'] ?? '')]);
        }
        sec_q("DELETE FROM users WHERE id = ?", [$uid]);
        $righe += max(0, mysqli_affected_rows($conn));
        mysqli_commit($conn);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        throw $e;
    }

    /* i file, ora che le righe non li nominano piu': fc_file_togli controlla
       che nessun'altra carta (di un altro utente) usi lo stesso file */
    require_once __DIR__ . '/fc/fc_files.php';
    $tolti = 0;
    foreach ($file as [$cartella, $nome]) {
        $f = rtrim($cartella, '/') . '/' . $nome;
        if (!is_file($f)) continue;
        if (strpos($cartella, 'uploads/tobuy') !== false) { if (@unlink($f)) $tolti++; continue; }
        if (fc_file_togli($cartella, $nome)) $tolti++;
    }
    return ['righe' => $righe, 'file' => $tolti];
}
