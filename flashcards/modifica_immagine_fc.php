<?php
    require_once 'config.php';
    require_once 'auth_check.php';
    require_once 'examples_box.php'; // render_examples_section(), condivisa con visualizzazione_fc.php
    $current_user_id = (int)$utente_corrente['id'];

    /* sec_db() DOPO auth_check: e' quest'ultimo a caricare security_core.php. */
    $conn = sec_db();

    /* Rete di sicurezza, come in fc_ai.php/ai_example.php/inserimento_fc.php —
       qui con una ragione in piu': senza buffering, un avviso PHP stampato
       prima di un header('Location:...') non lo sporca soltanto, lo fa
       FALLIRE del tutto ("headers already sent"), in silenzio: il salvataggio
       va a buon fine nel database ma il redirect non parte, e il client
       finisce nel ramo document.write() con qualunque output parziale sia
       rimasto. Con ob_start() attivo header() funziona comunque, qualunque
       cosa sia stata scritta prima. Lo shutdown handler copre il solo caso
       che resta: un errore davvero fatale, prima che qualunque header parta. */
    ob_start();
    register_shutdown_function(function () {
        $err = error_get_last();
        if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
            if (ob_get_level() > 0) { ob_clean(); }
            if (!headers_sent()) http_response_code(500);
            echo '<!DOCTYPE html><html lang="it"><body><div class="page"><div class="empty-state">'
               . sec_h('Errore interno del server: ' . $err['message'] . ' (riga ' . $err['line'] . ')')
               . '</div></div></body></html>';
        }
    });

    $categories = array();
    foreach (sec_all("SELECT id, name FROM fc_categories WHERE user_id = ? ORDER BY name",
                     [$current_user_id]) as $row) { $categories[$row['id']] = $row['name']; }

    /* I tipi di audio ammessi: sec_file() guarda il contenuto vero con finfo,
       non l'estensione dichiarata dal client. */
    const FC_AUDIO_MIME = [
        'audio/mpeg' => 'mp3', 'audio/mp3' => 'mp3', 'audio/wav' => 'wav',
        'audio/x-wav' => 'wav', 'audio/webm' => 'webm', 'audio/ogg' => 'ogg',
        'audio/mp4' => 'm4a', 'audio/aac' => 'aac',
    ];

    /* Salva un allegato: restituisce il nome scritto dal server, o '' se non
       c'era niente o il contenuto non e' del tipo giusto. */
    function mfc_salva($campo, $cartella, $audio) {
        if (!isset($_FILES[$campo]) || ($_FILES[$campo]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return '';
        $ris = $audio ? sec_file($_FILES[$campo], FC_AUDIO_MIME, 10485760) : sec_file($_FILES[$campo]);
        if (empty($ris['ok'])) return '';
        if (!is_dir($cartella)) { @mkdir($cartella, 0755, true); }
        $nome = sec_safe_filename($ris['ext']);
        return move_uploaded_file($ris['tmp'], rtrim($cartella, '/') . '/' . $nome) ? $nome : '';
    }

    /* Cancella un file dal disco. basename(): il nome viene dal database, ma se
       ci finisse un percorso non deve poter uscire dalla cartella. */
    function mfc_togli($cartella, $nome) {
        if (empty($nome)) return;
        $f = rtrim($cartella, '/') . '/' . basename($nome);
        if (is_file($f)) @unlink($f);
    }

    /* --- CREAZIONE (modo=insert) ---
       Questa pagina fa ora sia inserimento sia modifica: sono lo stesso
       modulo — stessi campi, stessa toolbar, stesso audio, stessa immagine,
       stessi esempi, stessa IA — e tenerli in due file voleva dire
       correggere ogni cosa due volte. inserimento_fc.php resta come
       indirizzo, ma rimanda qui.

       Il ramo e' separato da quello di modifica perche' fa una INSERT invece
       di una UPDATE e non ha niente da eliminare; da li' in poi la risposta
       e' la stessa: l'id della carta nuova, cosi' il modulo puo' restare
       aperto e agganciarle subito gli esempi. */
    if ($_SERVER["REQUEST_METHOD"] == "POST" && ($_POST['modo'] ?? '') === 'insert') {
        sec_guard_write($current_user_id, 'fc_modifica', 0, 120, 60);
        ob_clean();
        header('Content-Type: application/json');

        $catID = sec_int($_POST['category_id'] ?? 0, 0);
        if ($catID <= 0) $catID = sec_int($_POST['previous_catid'] ?? 0, 0);
        if ($catID <= 0 || !sec_owns('fc_categories', $catID, $current_user_id)) {
            echo json_encode(['success' => false, 'error' => t('fc.categoria_non_valida')]);
            exit;
        }

        $quest = sec_text($_POST['quest'] ?? '', 20000);
        $answ  = sec_text($_POST['answ'] ?? '', 20000);

        $q_img = mfc_salva('qNew_image', FC_IMAGE_PATH_INSERT, false);
        $a_img = mfc_salva('aNew_image', FC_IMAGE_PATH_INSERT, false);
        $q_aud = mfc_salva('audio_q', 'fc_audio', true);
        $a_aud = mfc_salva('audio_a', 'fc_audio', true);

        if ($quest === '' && $answ === '' && $q_img === '' && $a_img === '' && $q_aud === '' && $a_aud === '') {
            echo json_encode(['success' => false, 'empty' => true, 'error' => t('fc.serve_qualcosa')]);
            exit;
        }

        $max_o = (int)(sec_one("SELECT MAX(custom_order) AS m FROM fc_images WHERE category_id = ?",
                               [$catID])['m'] ?? 0) + 1;

        sec_q("INSERT INTO fc_images (question_image, answer_image, question_desc, answer_desc, category_id, question_audio, answer_audio, custom_order)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
              [$q_img, $a_img, $quest, $answ, $catID, $q_aud, $a_aud, $max_o]);

        echo json_encode(['success' => true, 'fc_id' => sec_insert_id(), 'category_id' => $catID]);
        exit;
    }

    // --- SALVATAGGIO / ELIMINAZIONE ---
    if ($_SERVER["REQUEST_METHOD"] == "POST" && (isset($_POST['fc_id']) || isset($_POST['child_id']))) {

        /* Token di sessione, origine e limite di frequenza: prima non c'erano,
           su una pagina che modifica ed elimina. */
        sec_guard_write($current_user_id, 'fc_modifica', 0, 120, 60);

        $is_child = isset($_POST['child_id']);
        $id = $is_child ? sec_int($_POST['child_id'] ?? 0, 1) : sec_int($_POST['fc_id'] ?? 0, 1);

        /* category_id arriva dal selettore "Plug & Play" (category_selector.php),
           che scrive il proprio campo nascosto: se quel campo esiste ma e' vuoto
           o "0" (selettore non toccato), il ?? di PHP non lo considera assente -
           lo considera fornito - e non scende a controllare 'category' o
           'previous_catid'. Il risultato era category_id=0 ad ogni salvataggio
           in cui non si tocca la categoria: la carta restava al suo posto (qui
           sotto non si scrive nulla se $catID resta com'era), ma il redirect
           portava a "Tutte le categorie" invece che alla pagina di partenza, e
           la carta - in fondo a un elenco lungo - sembrava sparita: si tornava
           sempre in cima. Un valore non numerico o <= 0 vale quindi come "non
           fornito", e si scende alle alternative. */
        $catIDgrezzo = $_POST['category_id'] ?? $_POST['category'] ?? null;
        $catID = ($catIDgrezzo !== null && (int)$catIDgrezzo > 0)
            ? sec_int($catIDgrezzo, 1)
            : sec_int($_POST['previous_catid'] ?? 0, 0);

        /* La scheda dev'essere sua: per un figlio la proprieta' passa dal padre.
           Le due query sono scritte per esteso invece di comporre il nome della
           tabella in una variabile: i nomi di tabella non si legano a un
           segnaposto, quindi meno se ne scrivono a runtime meglio e'. */
        if ($is_child) {
            $check_row = sec_one("SELECT c.user_id FROM fc_child_cards ch
                        JOIN fc_images i ON ch.parent_id = i.id
                        JOIN fc_categories c ON i.category_id = c.id
                        WHERE ch.id_child = ?", [$id]);
        } else {
            $check_row = sec_one("SELECT c.user_id FROM fc_images i
                        JOIN fc_categories c ON i.category_id = c.id
                        WHERE i.id = ?", [$id]);
        }
        if (!$check_row || (int)$check_row['user_id'] !== $current_user_id) {
            die(sec_h(t('fc.non_proprietario')));
        }

        // Se cambia categoria, anche la nuova dev'essere sua
        if (!$is_child && $catID > 0 && !sec_owns('fc_categories', $catID, $current_user_id)) {
            die(sec_h(t('fc.non_proprietario_categoria')));
        }

        // Stato attuale dei file allegati
        $old = $is_child
            ? sec_one("SELECT * FROM fc_child_cards WHERE id_child = ?", [$id])
            : sec_one("SELECT * FROM fc_images WHERE id = ?", [$id]);
        if (!$old) { die(sec_h(t('fc.scheda_non_trovata'))); }

        /* Dove tornare dopo. I valori arrivano dal modulo, quindi si validano:
           prima finivano dentro una stringa JavaScript, e un apice in game_id
           bastava a chiuderla e a scrivere codice nella pagina. */
        $return_to_game = sec_bool($_POST['return_to_game'] ?? 0);
        if ($return_to_game) {
            $redirect_url = 'game_fc.php?game_id=' . sec_int($_POST['game_id'] ?? 0, 1)
                . '&position=' . sec_int($_POST['position'] ?? 1, 1)
                . '&status=' . urlencode(sec_enum($_POST['status'] ?? null, ['all', '1', '0'], '0'))
                . '&order=' . urlencode(sec_enum($_POST['order'] ?? null, ['in_order', 'random'], 'random'));
        } else {
            $redirect_url = 'visualizzazione_fc.php?category_id=' . (int)$catID;
        }

        // AZIONE ELIMINA
        if (isset($_POST['delete'])) {
            mfc_togli(FC_IMAGE_PATH_INSERT, $old['question_image'] ?? '');
            mfc_togli(FC_IMAGE_PATH_INSERT, $old['answer_image'] ?? '');
            mfc_togli('fc_audio', $old['question_audio'] ?? '');
            mfc_togli('fc_audio', $old['answer_audio'] ?? '');

            if ($is_child) sec_q("DELETE FROM fc_child_cards WHERE id_child = ?", [$id]);
            else           sec_q("DELETE FROM fc_images WHERE id = ?", [$id]);

            /* header('Location:') e non un blocco di codice stampato in pagina: il
               salto lo fa il server, e nessun valore finisce dentro del codice.
               (La sequenza di apertura di uno script non si puo' scrivere
               nemmeno in un commento: il browser la vede lo stesso, ed e'
               esattamente quello che e' successo qui.) */
            header('Location: ' . $redirect_url);
            exit;
        }

        // AZIONE SALVA
        $quest = sec_text($_POST['quest'] ?? '', 20000);
        $answ  = sec_text($_POST['answ'] ?? '', 20000);

        /* Per ogni allegato: null = non toccare, '' = togliere, altrimenti il
           nome nuovo. Prima erano pezzi di SQL costruiti a mano
           ("question_image = '...'") incollati nella UPDATE: con i segnaposto
           quel modo non regge.

           CORREZIONE: le immagini avevano bisogno anche di una casella
           "sostituisci" spuntata a parte dal file scelto — un secondo stato
           da tenere sincronizzato col primo, e fonte di bug quando i due
           finivano fuori fase. L'interfaccia non ha piu' quella casella (si
           preme direttamente il riquadro per scegliere un file nuovo): ora
           basta che un file sia arrivato, esattamente come gia' funzionava
           per l'audio. */
        $campi = [];
        foreach ([
            ['question_image', 'remove_q_img', 'qNew_image', FC_IMAGE_PATH_INSERT, false],
            ['answer_image',   'remove_a_img', 'aNew_image', FC_IMAGE_PATH_INSERT, false],
            ['question_audio', 'remove_q_aud', 'audio_q',    'fc_audio',            true],
            ['answer_audio',   'remove_a_aud', 'audio_a',    'fc_audio',            true],
        ] as [$colonna, $rimuovi, $campoFile, $cartella, $audio]) {
            if (sec_bool($_POST[$rimuovi] ?? 0)) {
                mfc_togli($cartella, $old[$colonna] ?? '');
                $campi[$colonna] = '';
                continue;
            }
            $nuovo = mfc_salva($campoFile, $cartella, $audio);
            if ($nuovo !== '') {
                mfc_togli($cartella, $old[$colonna] ?? '');
                $campi[$colonna] = $nuovo;
            }
        }

        $sets = ['question_desc = ?', 'answer_desc = ?'];
        $vals = [$quest, $answ];
        if (!$is_child) { $sets[] = 'category_id = ?'; $vals[] = $catID; }
        foreach ($campi as $colonna => $valore) { $sets[] = "$colonna = ?"; $vals[] = $valore; }

        if ($is_child) {
            sec_q("UPDATE fc_child_cards SET " . implode(', ', $sets) . " WHERE id_child = ?", array_merge($vals, [$id]));
        } else {
            sec_q("UPDATE fc_images SET " . implode(', ', $sets) . " WHERE id = ?", array_merge($vals, [$id]));
        }

        /* Si torna sulla carta appena salvata, non in cima all'elenco:
           visualizzazione_fc.php legge fc_focus / fc_focus_child e ci scorre
           sopra. Non lo si fa per l'eliminazione (la carta non c'e' piu') ne'
           quando si torna alla partita, che ha gia' un suo punto d'arrivo. */
        if (!$return_to_game) {
            $redirect_url .= ($is_child ? '&fc_focus_child=' : '&fc_focus=') . (int)$id;
        }

        header('Location: ' . $redirect_url);
        exit;
    }
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title><?php te('fc.mfc_titolo'); ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="theme-color" content="#F2F2F7" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#000000" media="(prefers-color-scheme: dark)">

    <?php echo nisama_i18n_json(['comune', 'fc', 'vocab']); ?>

<meta name="csrf-token" content="<?php echo sec_h(sec_csrf_token()); ?>">
<script src="<?php echo PWA_BASE; ?>nisama_fetch.js"></script>
<script src="<?php echo PWA_BASE; ?>fc_parser.js"></script>

<script>
        window.MathJax = {
            tex: {
                inlineMath: [['$', '$'], ['\\(', '\\)']],
                displayMath: [['$$', '$$'], ['\\[', '\\]']]
            }
        };
    </script>
    <script id="MathJax-script" async src="https://cdn.jsdelivr.net/npm/mathjax@3/es5/tex-mml-chtml.js"></script>
    <script src="<?php echo PWA_BASE; ?>fc_audio_recorder.js"></script>
    <script src="<?php echo PWA_BASE; ?>fc_editor_immagine.js"></script>
    <script src="<?php echo PWA_BASE; ?>examples_modal.js"></script>
    <script src="<?php echo PWA_BASE; ?>fc_migliora_carta.js"></script>

<link rel="stylesheet" href="<?php echo PWA_BASE; ?>autosave_bar.css">
<link rel="stylesheet" href="<?php echo PWA_BASE; ?>examples_box.css">
<link rel="stylesheet" href="<?php echo PWA_BASE; ?>fc_migliora_carta.css">
<link rel="stylesheet" href="<?php echo PWA_BASE; ?>modifica_immagine_fc.css?v=<?php echo @filemtime(dirname(__FILE__) . '/modifica_immagine_fc.css') ?: 1; ?>">
</head>
<body>

<?php
    /* Niente sidebar qui: questa pagina si apre come popup sopra un'altra,
       e il pulsante dell'hamburger finiva a coprire i pulsanti del modulo
       (si vede negli screenshot, sopra "Continua a modificare"). La
       navigazione ce l'ha gia' la pagina sotto. */

    // ============================================================
    // Funzioni di supporto per generare i blocchi HTML ripetuti
    // (domanda / risposta) senza duplicare markup — nessuna logica
    // di business qui dentro, solo presentazione.
    // ============================================================
    // Il blocco immagine (fc_img_box_modifica) vive in
    // fc_editor_immagine.php, condiviso con inserimento_fc.php.
    require_once 'fc_editor_immagine.php';
    require_once 'fc_migliora_carta.php';

    function fc_audio_block($p, $label, $audioFile) {
        ?>
        <div class="audio-card">
            <div class="audio-row">
                <button type="button" class="rec-btn" id="btn_rec_<?php echo sec_h($p); ?>" onclick="toggleRecord('<?php echo sec_h($p); ?>')" aria-label="<?php te('fc.ifc_registra'); ?>">
                    <svg class="ic-mic" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="2" width="6" height="12" rx="3"/><path d="M5 10a7 7 0 0 0 14 0"/><line x1="12" y1="19" x2="12" y2="22"/></svg>
                    <svg class="ic-stop" viewBox="0 0 24 24" width="18" height="18" style="display:none;"><rect x="6" y="6" width="12" height="12" rx="3" fill="currentColor"/></svg>
                </button>
                <div class="audio-info">
                    <div class="audio-title"><?php echo sec_h($label); ?></div>
                    <div class="audio-sub" id="rec_status_<?php echo sec_h($p); ?>"><?php echo !empty($audioFile) ? T('fc.mfc_audio_presente') : T('fc.ifc_tocca_registrare'); ?></div>
                </div>
            </div>
            <?php if (!empty($audioFile)): ?>
                <div class="audio-player-row">
                    <audio src="fc_audio/<?php echo sec_h($audioFile); ?>" controls></audio>
                    <label class="mini-remove"><input type="checkbox" name="remove_<?php echo sec_h($p); ?>_aud" value="1"> Elimina</label>
                </div>
            <?php endif; ?>
            <audio id="audio_preview_<?php echo sec_h($p); ?>" controls style="display:none;" class="audio-player-row-new"></audio>
        </div>
        <?php
    }

    // CARICAMENTO RECORD IN EDIT MODE (GET)
    /* In inserimento non c'e' nessun record da caricare: si costruisce una
       riga finta vuota, cosi' TUTTO il markup piu' sotto (che legge $row)
       funziona identico nelle due modalita' senza doppioni di if. */
    $fc_modo = (($_GET['modo'] ?? '') === 'insert') ? 'insert' : 'edit';

    if ($fc_modo === 'insert') {
        $is_child = false;
        $id = 0;
        $prev_cat = sec_int($_GET['category_id'] ?? $_GET['previous_catid'] ?? 0, 0);
        $category_id = $prev_cat;
        $ex_count = 0;
        $row = [
            'question_desc' => '', 'answer_desc' => '',
            'question_image' => '', 'answer_image' => '',
            'question_audio' => '', 'answer_audio' => '',
            'category_id' => $prev_cat,
        ];
        $cancel_url = "visualizzazione_fc.php" . ($prev_cat > 0 ? "?category_id=" . $prev_cat : "");
    }

    if ($fc_modo === 'insert' || isset($_GET['fc_id']) || isset($_GET['child_id'])) {

        if ($fc_modo === 'edit') {
            $is_child = isset($_GET['child_id']);
            $id = $is_child ? sec_int($_GET['child_id'] ?? 0, 1) : sec_int($_GET['fc_id'] ?? 0, 1);
            $prev_cat = sec_int($_GET['previous_catid'] ?? 0, 0);

            // --- Il record si carica SOLO se appartiene all'utente ---
            if ($is_child) {
                $row = sec_one("SELECT t.* FROM fc_child_cards t
                              JOIN fc_images i ON t.parent_id = i.id
                              JOIN fc_categories c ON i.category_id = c.id
                              WHERE t.id_child = ? AND c.user_id = ?", [$id, $current_user_id]);
            } else {
                $row = sec_one("SELECT t.* FROM fc_images t
                              JOIN fc_categories c ON t.category_id = c.id
                              WHERE t.id = ? AND c.user_id = ?", [$id, $current_user_id]);
            }
            // -----------------------------------------------------------------------
        }

        if ($row) {
            if ($fc_modo === 'edit') {
                $category_id = $is_child ? $prev_cat : $row['category_id'];
                $ex_col = $is_child ? 'child_id' : 'parent_id';
                $ex_count = (int)(sec_one("SELECT COUNT(*) AS c FROM fc_examples WHERE $ex_col = ?", [$id])['c'] ?? 0);
            }

            /* Anche l'annullamento torna sulla carta da cui si e' partiti, non
               in cima all'elenco: chi annulla e' comunque nel bel mezzo di un
               giro fra le carte, ed e' li' che vuole ritrovarsi. Stessi
               parametri usati dal salvataggio in fondo alla POST.

               CORREZIONE: qui i valori arrivavano da $_GET senza nessuna
               validazione (game_id, position, status, order incollati cosi'
               come sono nell'indirizzo). Il ramo POST qui sotto, che fa
               esattamente la stessa cosa dopo un salvataggio, gia' passa da
               sec_int/sec_enum/urlencode — e lo spiega nel suo commento — ma
               questo ramo GET, eseguito quando la pagina si CARICA, non
               aveva mai ricevuto la stessa cura: un game_id con dentro un
               apice o un tag sarebbe finito diretto nell'href stampato in
               pagina. */
            $cancel_url = isset($_GET['return_to_game'])
                ? "game_fc.php?game_id=" . sec_int($_GET['game_id'] ?? 0, 1)
                    . "&position=" . sec_int($_GET['position'] ?? 1, 1)
                    . "&status=" . urlencode(sec_enum($_GET['status'] ?? null, ['all', '1', '0'], '0'))
                    . "&order=" . urlencode(sec_enum($_GET['order'] ?? null, ['in_order', 'random'], 'random'))
                : "visualizzazione_fc.php?category_id=" . $category_id
                  . ($is_child ? "&fc_focus_child=" : "&fc_focus=") . (int)$id;
            ?>

            <?php /* Niente app-header: diceva le stesse cose della barra qui
                     sotto, e in un popup significava due fasce sovrapposte
                     (si vedeva nello screenshot: "EDIT CARD / Question &
                     Answer" e subito sotto "Cancel / NEW CARD / Create").
                     Ne resta una sola, quella condivisa col pannello esempi. */ ?>

            <div class="page">
            <form id="editFcForm" method="POST" enctype="multipart/form-data">
                <?php /* Stessa identica barra del pannello esempi: stesse classi
                         (.exm-form-topbar), stessa disposizione, stessi SVG e
                         stessa logica di salvataggio — il pulsante verde con
                         l'interruttore "salvataggio automatico" e' autosave_bar.php,
                         lo stesso componente, qui con un'istanza propria ('scheda')
                         perche' il suo sporco e' indipendente da quello del
                         pannello esempi che puo' aprirsi sopra. */ ?>
                <div class="exm-form-topbar">
                    <span class="exm-form-title" id="fcTopbarTitle"><?php echo $fc_modo === 'insert' ? sec_h(t('fc.ifc_nuova_scheda')) : sec_h(t('fc.mfc_titolo_breve')); ?></span>
                    <div class="exm-form-actions">
                        <?php if ($fc_modo === 'edit'): ?>
                        <button type="submit" name="delete" value="1" class="exm-icon-btn exm-icon-btn-danger" onclick="return confirm('<?php echo sec_h(t('vocab.mi_elimina_def')); ?>?');" aria-label="<?php te('vocab.mi_elimina_def'); ?>">
                            <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h16M9 7V5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2m2 0-1 13a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2L6 7"/></svg>
                        </button>
                        <?php endif; ?>
                        <?php $ns_instance = 'scheda'; include 'autosave_bar.php'; unset($ns_instance); ?>
                    </div>
                    <button type="button" class="exm-icon-btn exm-close-inline" onclick="fcAnnullaModifica('<?php echo sec_h($cancel_url); ?>')" aria-label="<?php te('comune.chiudi'); ?>">
                        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M6 6l12 12M18 6 6 18"/></svg>
                    </button>
                </div>


                <input type="hidden" name="csrf_token" value="<?php echo sec_h(sec_csrf_token()); ?>">
                <input type="hidden" name="modo" value="<?php echo sec_h($fc_modo); ?>">

                <input type="hidden" name="return_to_game" value="<?php echo isset($_GET['return_to_game']) ? 1 : 0; ?>">
                <input type="hidden" name="game_id" value="<?php echo isset($_GET['game_id']) ? (int)$_GET['game_id'] : (isset($_POST['game_id']) ? (int)$_POST['game_id'] : 0); ?>">
                <input type="hidden" name="position" value="<?php echo isset($_GET['position']) ? (int)$_GET['position'] : (isset($_POST['position']) ? (int)$_POST['position'] : 0); ?>">
                <input type="hidden" name="status" value="<?php echo isset($_GET['status']) ? sec_h($_GET['status']) : (isset($_POST['status']) ? sec_h($_POST['status']) : '0'); ?>">
                <input type="hidden" name="order" value="<?php echo isset($_GET['order']) ? sec_h($_GET['order']) : (isset($_POST['order']) ? sec_h($_POST['order']) : 'random'); ?>">

                <?php if ($is_child): ?>
                    <input type="hidden" name="child_id" value="<?php echo (int)$id; ?>">
                <?php else: ?>
                    <input type="hidden" name="fc_id" value="<?php echo (int)$id; ?>">
                <?php endif; ?>
                <input type="hidden" name="previous_catid" value="<?php echo (int)$prev_cat; ?>">

                <!-- ============== MIGLIORA CON L'IA ============== -->
                <section class="fc-card fc-card-tight fc-card-shortcuts">
                    <?php if ($fc_modo === 'insert'): ?>
                    <?php /* L'inserimento multiplo era il primo pulsante della vecchia
                             pagina di inserimento, ed e' sparito nell'unione: chi deve
                             creare venti carte non vuole aprire venti volte questo
                             modulo. Sta qui in cima, dove stava prima, e porta con se'
                             la categoria gia' scelta. */ ?>
                    <a class="cta-insert" href="inserimento_big_fc.php<?php echo $category_id > 0 ? ('?category_id=' . (int)$category_id) : ''; ?>">
                        <span class="cta-insert-icon">
                            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="14" height="4" rx="1"/><rect x="7" y="10" width="14" height="4" rx="1"/><rect x="3" y="16" width="14" height="4" rx="1"/></svg>
                        </span>
                        <span class="cta-insert-text">
                            <span class="cta-insert-title"><?php te('vocab.ins_multiplo'); ?></span>
                            <span class="cta-insert-sub"><?php te('fc.ifc_multiplo_sub'); ?></span>
                        </span>
                        <svg class="cta-insert-chev" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m9 6 6 6-6 6"/></svg>
                    </a>
                    <div class="fc-shortcut-sep"></div>
                    <?php endif; ?>
                    <?php fc_migliora_carta_blocco(); ?>
                </section>

                <!-- ============== CATEGORIA ============== -->
                <section class="fc-card">
                    <div class="fc-card-head"><span class="fc-card-title"><?php te('vocab.cat_categoria'); ?></span></div>
                    <?php if (!$is_child): 
                        // Configuriamo le variabili per il nuovo componente "Plug & Play"
                        $cs_id = 'modifica_fc'; // ID univoco per sbloccare la modalità "moderna"
                        $cs_selected_id = $category_id;
                        $cs_selected_name = (!empty($category_id) && isset($categories[$category_id])) ? $categories[$category_id] : '';
                        $cs_placeholder = t('fc.ifc_scegli_cat');
                        
                        // Il file genererà automaticamente il bottone, l'input nascosto e il popup!
                        include 'category_selector.php'; 
                    ?>
                    <?php else: ?>
                        <div class="inherited-note"><?php te('fc.mfc_categoria_ereditata'); ?></div>
                    <?php endif; ?>
                </section>

                <!-- ============== CARD DOMANDA ============== -->
                <section class="fc-card fc-card-q">
                    <div class="fc-card-head">
                        <span class="fc-card-title"><?php te('fc.ifc_domanda'); ?></span>
                        <button type="button" class="pill-help" onclick="openAgenda()">
                            <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><line x1="12" y1="11" x2="12" y2="16"/><circle cx="12" cy="7.6" r="1" fill="currentColor" stroke="none"/></svg>
                            <?php te('fc.ifc_guida'); ?>
                        </button>
                    </div>

                    <?php
                    $ft_field = 'quest';
                    $ft_raw = $row['question_desc'] ?? '';
                    $ft_placeholder = t('fc.ifc_ph_domanda');
                    include 'format_toolbar.php';
                    ?>

                    <?php fc_img_box_modifica('q', t('fc.ifc_img_domanda'), $row['question_image']); ?>
                    <?php fc_audio_block('q', t('fc.ifc_voc_domanda'), $row['question_audio']); ?>
                </section>

                <!-- ============== CARD RISPOSTA ============== -->
                <section class="fc-card fc-card-a">
                    <div class="fc-card-head">
                        <span class="fc-card-title"><?php te('fc.ifc_risposta'); ?></span>
                        <button type="button" class="pill-help" onclick="openAgenda()">
                            <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><line x1="12" y1="11" x2="12" y2="16"/><circle cx="12" cy="7.6" r="1" fill="currentColor" stroke="none"/></svg>
                            <?php te('fc.ifc_guida'); ?>
                        </button>
                    </div>

                    <?php
                    $ft_field = 'answ';
                    $ft_raw = $row['answer_desc'] ?? '';
                    $ft_placeholder = t('fc.ifc_ph_risposta');
                    include 'format_toolbar.php';
                    ?>

                    <?php fc_img_box_modifica('a', t('fc.ifc_img_risposta'), $row['answer_image']); ?>
                    <?php fc_audio_block('a', t('fc.ifc_voc_risposta'), $row['answer_audio']); ?>
                </section>

                <!-- ============== ESEMPI ============== -->
                <?php /* In inserimento la sezione parte nascosta: finche' la carta
                         non esiste non ha un id a cui agganciare un esempio. La
                         mostra il JS subito dopo la creazione. */ ?>
                <section class="fc-card fc-card-tight qa-examples-wrap"<?php echo $fc_modo === 'insert' ? ' hidden' : ''; ?>>
                    <?php $ex_tipo = $is_child ? 'child' : 'parent'; ?>
                    <?php if ($ex_count > 0): ?>
                        <?php /* Il riquadro col carosello, come in visualizzazione_fc.php: si
                                 riempie da solo via examples_modal.js/examples_api.php, e un
                                 tocco sopra apre lo stesso popup di modifica che il pulsante
                                 apriva prima — qui sotto, nello script della pagina, non il
                                 visore a schermo intero che usa visualizzazione (quella pagina
                                 e' di sola lettura, questa e' gia' un modulo di modifica: ha
                                 piu' senso finire dritti nel popup che permette di cambiarlo). */ ?>
                        <?php render_examples_section($id, $ex_tipo, $id, [$id => $ex_count]); ?>
                    <?php else: ?>
                        <button id="btn-ex-<?php echo $ex_tipo[0]; ?><?php echo (int)$id; ?>" type="button" class="ex-btn ex-btn-empty" onclick="openExamplesModal(<?php echo (int)$id; ?>, '<?php echo $ex_tipo; ?>', document.getElementById('quest').value, document.getElementById('answ').value)">
                            <?php te('fc.mfc_aggiungi_esempio'); ?>
                        </button>
                    <?php endif; ?>
                </section>



                <div class="fc-form-foot">
                    <?php /* L'eliminazione sta nella barra in alto, col cestino:
                             ripeterla qui sotto era lo stesso comando due volte,
                             e quello in fondo si trovava solo scorrendo. */ ?>
                    <?php /* Compare solo DOPO che una carta e' stata creata: prima
                             non avrebbe niente da ripulire. Tiene la categoria e
                             azzera tutto il resto. */ ?>
                    <button type="button" class="btn-altra" id="fcAltraBtn" hidden onclick="fcNuovaScheda()"><?php te('fc.ifc_inserisci_altra'); ?></button>
                </div>

            </form>
            </div>

            <?php
        } else {
            echo "<div class='page'><div class='empty-state'>" . sec_h(t('fc.scheda_non_trovata')) . "</div></div>";
        }
    } else {
        echo "<div class='page'><div class='empty-state'>" . sec_h(t('vocab.mi_id_mancante')) . "</div></div>";
    }
    ?>

    <div id="agendaModal" class="modal-overlay">
        <div class="modal-content">
            <button class="close-btn" onclick="closeAgenda()">&times;</button>
            <h3 class="modal-title"><?php te('fc.ifc_guida_rapida'); ?></h3>
            <p class="modal-sub"><?php te('fc.ifc_guida_intro'); ?></p>
            <ul class="guide-list">
                <li><span class="g-ic" style="color:var(--red)">A</span> <?php te('fc.ifc_guida_colore'); ?></li>
                <li><span class="g-ic"><b>B</b></span> <?php te('fc.ifc_guida_stili'); ?></li>
                <li><span class="g-ic">🖊</span> <?php te('fc.ifc_guida_evidenzia'); ?></li>
                <li><span class="g-ic">≡</span><?php te('fc.ifc_guida_elenco'); ?></li>
                <li><span class="g-ic">🎨</span> <?php te('fc.ifc_guida_barra'); ?></li>
                <li><span class="g-ic">$x$</span><?php te('fc.ifc_guida_formula_inline'); ?></li>
                <li><span class="g-ic">$$x$$</span> <?php te('fc.ifc_guida_formula_blocco'); ?></li>
                <li><span class="g-ic">&lt;/&gt;</span><?php te('fc.ifc_guida_grezzo'); ?></li>
            </ul>
            <p class="modal-note"><?php te('fc.ifc_guida_formula_modifica'); ?></p>
        </div>
    </div>

    <script>
    // ============================================================
    // MOTORE TESTO-TAG <-> AST, TOOLBAR ED EDITOR: ora vivono in
    // fc_parser.js e format_toolbar.php (riutilizzabili altrove).
    // Qui restano solo le parti specifiche di questa pagina.
    // ============================================================

    // ============================================================
    // COMPATIBILITÀ: funzioni storiche usate anche altrove
    // (agenda, esempi, anteprima immagini)
    // ============================================================
    function openAgenda() { document.getElementById('agendaModal').style.display = 'flex'; }
    function closeAgenda() { document.getElementById('agendaModal').style.display = 'none'; }

    /* Richiamata dal pulsante Annulla. Fuori da un popup si comporta come
       sempre: naviga davvero. Dentro un popup (fc_edit_popup.js) non c'e'
       niente da navigare — si avvisa solo chi l'ha aperto, che lo chiude. */
    /* Stato "ci sono modifiche non salvate": lo alza qualunque input dentro
       il modulo, lo abbassa un salvataggio riuscito. Serve solo a decidere se
       chiedere conferma uscendo — se non si e' toccato niente, chiedere
       sarebbe solo un ostacolo in piu'. */
    var fcSporco = false;
    function fcSegnaSporco() { fcSporco = true; }
    document.addEventListener('input', fcSegnaSporco);
    document.addEventListener('change', fcSegnaSporco);

    function fcEsci(cancelUrl) {
        if (window.parent && window.parent !== window) {
            window.parent.postMessage({ source: 'fc-modifica-popup', type: 'annulla' }, window.location.origin);
            return;
        }
        window.location.href = cancelUrl;
    }

    /* Modalita' e id: l'id cambia durante la sessione (in inserimento nasce a
       0 e diventa reale appena si crea la carta), quindi e' una variabile, non
       un valore stampato una volta sola nel markup. */
    /* Avvisa la pagina che ha aperto il popup che il database e' cambiato.
       NON chiude niente: con il salvataggio automatico si salva di continuo, e
       chiudere a ogni salvataggio renderebbe il modulo inutilizzabile. Chi
       sta sotto si limita a prenderne nota e si aggiorna alla chiusura. */
    var FC_CANCEL_URL = '<?php echo sec_h($cancel_url); ?>';

    /* La cornice del popup non ha piu' una sua "x": quando l'utente tocca
       fuori dal pannello, il popup CHIEDE a questa pagina di chiudersi invece
       di farlo da se'. Cosi' la conferma sulle modifiche non salvate vale
       anche da li', che e' proprio il caso in cui serve di piu'. */
    window.addEventListener('message', function (ev) {
        if (!ev.data || ev.data.source !== 'fc-popup-host') return;
        if (ev.data.type === 'chiedi-chiusura') fcAnnullaModifica(FC_CANCEL_URL);
    });

    function fcSegnalaCambiato() {
        if (window.parent && window.parent !== window) {
            window.parent.postMessage({
                source: 'fc-modifica-popup',
                type: 'cambiato',
                fc_id: FC_ID_CORRENTE,
                fc_tipo: FC_TIPO
            }, window.location.origin);
        }
    }

    /* Gli esempi aggiunti o eliminati dal loro pannello passano da
       examples_api.php per conto loro, senza toccare il codice di questa
       pagina: senza questo aggancio, chiudendo il popup dopo aver aggiunto un
       esempio l'elenco sotto resterebbe con il vecchio conteggio. */
    (function () {
        var fetchOriginale = window.fetch;
        window.fetch = function (input, init) {
            var url = (typeof input === 'string') ? input : (input && input.url) || '';
            var metodo = String((init && init.method) || 'GET').toUpperCase();
            return fetchOriginale.apply(this, arguments).then(function (res) {
                if (metodo === 'POST' && url.indexOf('examples_api.php') !== -1 && res.ok) {
                    fcSegnalaCambiato();
                }
                return res;
            });
        };
    })();

    var FC_MODO = '<?php echo sec_h($fc_modo); ?>';
    var FC_ID_CORRENTE = <?php echo (int)$id; ?>;
    var FC_TIPO = '<?php echo $is_child ? 'child' : 'parent'; ?>';

    function fcAnnullaModifica(cancelUrl) {
        if (!fcSporco) { fcEsci(cancelUrl); return; }
        var ov = document.getElementById('fcConfermaUscita');
        ov.classList.add('on');
        ov.querySelector('.fc-conf-esci').onclick = function () { ov.classList.remove('on'); fcEsci(cancelUrl); };
        ov.querySelector('.fc-conf-resta').onclick = function () { ov.classList.remove('on'); };
    }

    /* Pulisce il modulo per una carta nuova tenendo SOLO la categoria: e' il
       campo che quasi sempre resta lo stesso fra una carta e la successiva,
       ed e' anche il piu' noioso da riscegliere ogni volta. */
    function fcNuovaScheda() {
        document.querySelectorAll('.fmt-editor-wrap[data-field]').forEach(function (wrap) {
            if (typeof ftRender === 'function' && typeof fcdParse === 'function') ftRender(wrap, fcdParse(''));
            var hidden = wrap.querySelector('input[type="hidden"]');
            if (hidden) hidden.value = '';
        });
        ['q', 'a'].forEach(function (t) {
            fcAudioResetta(t);
            var pl = document.getElementById('audio_preview_' + t);
            if (pl) { pl.src = ''; pl.style.display = 'none'; }
            var st = document.getElementById('rec_status_' + t);
            if (st) st.textContent = T('fc.ifc_tocca_registrare');
            var inp = document.getElementById(t + 'New_image');
            if (inp) inp.value = '';
            var pv = document.getElementById(t + '_preview');
            if (pv) { pv.src = ''; pv.style.display = 'none'; }
            var wr = document.getElementById(t + '_img_wrap');
            if (wr) wr.classList.remove('has-img');
        });
        /* La carta nuova non esiste ancora: si torna a "crea", si nasconde il
           riquadro esempi (non c'e' nulla a cui agganciarli) e si riparte. */
        FC_ID_CORRENTE = 0;
        document.getElementById('fcAltraBtn').hidden = true;
        var box = document.querySelector('.qa-examples');
        if (box) box.hidden = true;
        NisamaSaveBar.reset('scheda');
        fcSporco = false;
        if (typeof fcMigliora !== 'undefined') fcMigliora.scartaEsempioInSospeso();
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }


    var mathJaxTimeouts = {};
    function updatePreview(textarea, previewId) {
        var previewEl = document.getElementById(previewId);
        if (previewEl && typeof parseTextForPreview === 'function') {
            previewEl.innerHTML = parseTextForPreview(textarea.value);
            clearTimeout(mathJaxTimeouts[previewId]);
            mathJaxTimeouts[previewId] = setTimeout(function () {
                if (typeof fcdDisegnaFormule === 'function') fcdDisegnaFormule(previewEl);
            }, 400);
        }
    }

    // ============================================================
    // ANTEPRIME IMMAGINI — motore in fc_editor_immagine.js, condiviso
    // con inserimento_fc.php. onScelta annulla una rimozione gia' chiesta:
    // scegliere un file nuovo e rimuovere insieme non avrebbe senso, e vince
    // il tocco piu' recente.
    // ============================================================
    fcImgSetupPreview('qNew_image', 'q_preview', 'q_img_wrap', function () { fcImgAnnullaRimozione('q'); });
    fcImgSetupPreview('aNew_image', 'a_preview', 'a_img_wrap', function () { fcImgAnnullaRimozione('a'); });

    // ============================================================
    // GENERA/MIGLIORA CON L'IA
    // ============================================================
    fcMigliora.inizializza({ campoQ: 'quest', campoA: 'answ' });

    /* A differenza di inserimento_fc.php, qui la carta esiste gia' e ha gia'
       un id: un esempio generato dall'IA si puo' salvare subito, senza
       aspettare il salvataggio della carta. fc_migliora_carta.js chiama
       questa funzione (se definita) appena ne produce uno. */
    async function fcMiglioraEsempioPronto(testo) {
        var fcId = FC_ID_CORRENTE;
        var fcType = FC_TIPO;
        if (!fcId) return;   // carta non ancora creata: l'esempio resta in sospeso
        try {
            var fd = new FormData();
            fd.append('action', 'save');
            fd.append('fc_id', fcId);
            fd.append('fc_type', fcType);
            fd.append('ex_id', 0);
            fd.append('text', testo);
            var meta = document.querySelector('meta[name="csrf-token"]');
            fd.append('csrf_token', meta ? meta.content : '');
            var r = await fetch('examples_api.php', { method: 'POST', body: fd });
            var j = await r.json();
            if (j && j.success) {
                fcMigliora.scartaEsempioInSospeso();
                fcSegnalaCambiato();
                /* Il riquadro esempi va ridisegnato: ora ce n'e' uno in piu' di
                   quelli che aveva letto al caricamento della pagina. */
                document.querySelectorAll('.qa-examples-box').forEach(function (b) {
                    b.classList.remove('qa-examples-box-filled');
                    b.innerHTML = '';
                    b._fcExamples = null;
                });
                if (typeof fcLoadExampleBoxes === 'function') fcLoadExampleBoxes();
            }
        } catch (e) { /* la carta e' comunque aggiornata: l'esempio si puo' riaggiungere a mano */ }
    }

    // ============================================================
    // REGISTRAZIONE AUDIO — motore in fc_audio_recorder.js, condiviso
    // con inserimento_fc.php ed examples_modal.php. Qui resta solo
    // l'involucro che sa dove mettere l'anteprima di QUESTA pagina.
    // ============================================================
    function toggleRecord(type) {
        toggleFcAudioRecord(type, 'btn_rec_' + type, 'rec_status_' + type, function (blob) {
            var player = document.getElementById('audio_preview_' + type);
            player.src = URL.createObjectURL(blob);
            player.style.display = 'block';
        });
    }

    // ============================================================
    // WIRING GLOBALE + INVIO FORM
    // ============================================================
    // ============================================================
    // NOTA: il wiring della toolbar/editor (fcSetupToolbar, fcSetupEditor,
    // idratazione iniziale, tocco su una formula per rieditarla, ecc.)
    // avviene già da solo dentro format_toolbar.php per ogni editor
    // presente in pagina — qui basta gestire l'invio del form.
    // ============================================================
    // ============================================================
    // RIQUADRO ESEMPI — si riempie da solo, un tocco sopra apre il
    // popup di modifica che esisteva gia' (openExamplesModal). Non il
    // visore a schermo intero: quello lo usa solo visualizzazione_fc.php,
    // che e' di sola lettura — qui si e' gia' dentro un modulo di
    // modifica, ha piu' senso finire dritti dove si puo' cambiare.
    // ============================================================
    if (typeof fcLoadExampleBoxes === 'function') fcLoadExampleBoxes();

    document.addEventListener('click', function (e) {
        var box = e.target.closest('.qa-examples-box-filled');
        if (!box) return;
        /* Le frecce/pallini del carosello, e i controlli audio dentro
           l'esempio, hanno gia' un loro significato: il tocco non deve
           aprire il popup anche in quei casi. */
        if (e.target.closest('.qa-ex-nav, a, audio, .audio-player')) return;
        /* Fine di una sfogliata: il browser manda comunque un click, che
           altrimenti aprirebbe il popup ogni volta che si cambia esempio
           (fcExUltimoSwipe vive in examples_modal.js). */
        if (Date.now() - fcExUltimoSwipe < 400) return;

        var realId = parseInt(box.getAttribute('data-fc-id'), 10) || 0;
        var type = box.getAttribute('data-fc-type') || 'parent';
        var qEl = document.getElementById('quest'), aEl = document.getElementById('answ');
        openExamplesModal(realId, type, qEl ? qEl.value : '', aEl ? aEl.value : '');
    });

    if (document.getElementById('editFcForm')) {
        /* Dentro un popup (fc_edit_popup.js) questa pagina vive in un iframe:
           window.parent e' la finestra vera, quella che ha aperto il popup.
           Fuori da un popup — aperta come pagina a se', con un indirizzo
           diretto — window.parent === window, e ci si comporta come sempre:
           un vero redirect del browser. */
        var dentroPopup = (window.parent && window.parent !== window);

        /* Il salvataggio ora e' una funzione a se': la chiama il pulsante verde
           della barra (autosave_bar.js) e la chiama il salvataggio automatico
           quando l'interruttore e' acceso. Restituisce true/false, che e' il
           contratto che NisamaSaveBar si aspetta. */
        async function salvaScheda() {
            fcSyncAllEditors();

            var fd = new FormData(document.getElementById('editFcForm'));
            if (fcAudioBlobDi('q')) fd.append('audio_q', fcAudioBlobDi('q'), 'q.' + fcAudioExtDi('q'));
            if (fcAudioBlobDi('a')) fd.append('audio_a', fcAudioBlobDi('a'), 'a.' + fcAudioExtDi('a'));

            try {
                var res = await fetch('modifica_immagine_fc.php', { method: 'POST', body: fd });

                /* CREAZIONE: il server risponde in JSON con l'id della carta
                   nuova, e il modulo RESTA APERTO — e' quello che permette di
                   agganciarle subito un esempio, impossibile prima che
                   esistesse (fc_examples vuole l'id di una carta vera). */
                if (FC_MODO === 'insert') {
                    var dati = await res.json();
                    if (!dati.success) { alert(dati.error || T('fc.ibfai_errore_ia')); return false; }

                    FC_ID_CORRENTE = dati.fc_id;
                    fcSporco = false;
                    document.getElementById('fcAltraBtn').hidden = false;

                    var sezEx = document.querySelector('.qa-examples-wrap');
                    if (sezEx) {
                        sezEx.hidden = false;
                        sezEx.querySelectorAll('.qa-examples-box').forEach(function (b) {
                            b.setAttribute('data-fc-id', dati.fc_id);
                        });
                    }
                    var inSospeso = (typeof fcMigliora !== 'undefined') ? fcMigliora.esempioInSospeso() : null;
                    if (inSospeso) fcMiglioraEsempioPronto(inSospeso.testo);

                    /* Da qui in avanti non si crea piu': si modifica la carta
                       appena creata. Senza questo, premere di nuovo salva
                       creerebbe un doppione a ogni giro. */
                    FC_MODO = 'edit';
                    var campoModo = document.querySelector('input[name="modo"]');
                    if (campoModo) campoModo.value = 'edit';
                    var campoId = document.querySelector('input[name="fc_id"]');
                    if (!campoId) {
                        campoId = document.createElement('input');
                        campoId.type = 'hidden';
                        campoId.name = 'fc_id';
                        document.getElementById('editFcForm').appendChild(campoId);
                    }
                    campoId.value = dati.fc_id;

                    if (window.parent && window.parent !== window) {
                        window.parent.postMessage({ source: 'fc-modifica-popup', type: 'creato', fc_id: dati.fc_id }, window.location.origin);
                    }
                    return true;
                }

                /* MODIFICA: il server chiude con un header('Location:'), che
                   fetch() segue da solo — res.redirected dice che e' andata. */
                if (res.redirected && res.url) {
                    fcSporco = false;
                    /* Mancava: con il salvataggio automatico la modifica finiva
                       nel database senza che nessuno lo dicesse alla pagina
                       sotto, che alla chiusura restava com'era. */
                    fcSegnalaCambiato();
                    return true;
                }
                return false;
            } catch (err) {
                return false;
            }
        }

        /* L'invio nativo del modulo resta solo per l'eliminazione, che e' una
           navigazione vera (la carta sparisce e si torna all'elenco). */
        document.getElementById('editFcForm').addEventListener('submit', async function (e) {
            var activeSubmit = e.submitter ? e.submitter.name : '';
            e.preventDefault();
            if (activeSubmit !== 'delete') { NisamaSaveBar.saveNow('scheda'); return; }

            fcSyncAllEditors();
            var fd = new FormData(this);
            fd.append('delete', '1');
            try {
                var res = await fetch('modifica_immagine_fc.php', { method: 'POST', body: fd });
                fcSporco = false;
                if (window.parent && window.parent !== window) {
                    window.parent.postMessage({ source: 'fc-modifica-popup', type: 'eliminato', url: res.url }, window.location.origin);
                    return;
                }
                if (res.redirected && res.url) { window.location.href = res.url; return; }
            } catch (err) {
                alert('Errore durante l\'eliminazione.');
            }
        });

        NisamaSaveBar.init({
            instance: 'scheda',
            key: 'fc_scheda',
            save: salvaScheda,
            testoMaiSalvato: T('fc.exm_autosave_breve')
        });

        /* Ogni modifica alza lo sporco anche sulla barra, cosi' il pallino
           arancione compare e il salvataggio automatico (se acceso) parte. */
        document.addEventListener('input', function () { NisamaSaveBar.touch('scheda'); });
        document.addEventListener('change', function () { NisamaSaveBar.touch('scheda'); });
    }
    </script>

    <?php include 'examples_modal.php'; ?>

<div class="fc-conf-overlay" id="fcConfermaUscita">
    <div class="fc-conf-panel">
        <b><?php te('fc.ifc_modifiche_titolo'); ?></b>
        <p><?php te('fc.ifc_modifiche_testo'); ?></p>
        <div class="fc-conf-azioni">
            <button type="button" class="fc-conf-resta"><?php te('fc.ifc_continua_modifica'); ?></button>
            <button type="button" class="fc-conf-esci"><?php te('fc.ifc_esci_senza_salvare'); ?></button>
        </div>
    </div>
</div>
</body>
</html>