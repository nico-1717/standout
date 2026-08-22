<?php
    require_once 'config.php';
    require_once 'auth_check.php';
    $current_user_id = (int)$utente_corrente['id'];

    /* sec_db() DOPO auth_check: e' quest'ultimo a caricare security_core.php. */
    $conn = sec_db();

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

    // --- SALVATAGGIO / ELIMINAZIONE ---
    if ($_SERVER["REQUEST_METHOD"] == "POST" && (isset($_POST['fc_id']) || isset($_POST['child_id']))) {

        /* Token di sessione, origine e limite di frequenza: prima non c'erano,
           su una pagina che modifica ed elimina. */
        sec_guard_write($current_user_id, 'fc_modifica', 0, 120, 60);

        $is_child = isset($_POST['child_id']);
        $id = $is_child ? sec_int($_POST['child_id'] ?? 0, 1) : sec_int($_POST['fc_id'] ?? 0, 1);
        $catID = sec_int($_POST['category_id'] ?? $_POST['category'] ?? $_POST['previous_catid'] ?? 0, 0);

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
            ? sec_one("SELECT * FROM fc_child_cards WHERE id = ?", [$id])
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

            if ($is_child) sec_q("DELETE FROM fc_child_cards WHERE id = ?", [$id]);
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
        $quest = sec_text($_POST['quest'] ?? '', 2000);
        $answ  = sec_text($_POST['answ'] ?? '', 2000);

        /* Per ogni allegato: null = non toccare, '' = togliere, altrimenti il
           nome nuovo. Prima erano pezzi di SQL costruiti a mano
           ("question_image = '...'") incollati nella UPDATE: con i segnaposto
           quel modo non regge. */
        $campi = [];
        foreach ([
            ['question_image', 'remove_q_img', 'qNew_image', FC_IMAGE_PATH_INSERT, false, 'replace_q_img'],
            ['answer_image',   'remove_a_img', 'aNew_image', FC_IMAGE_PATH_INSERT, false, 'replace_a_img'],
            ['question_audio', 'remove_q_aud', 'audio_q',    'fc_audio',            true,  null],
            ['answer_audio',   'remove_a_aud', 'audio_a',    'fc_audio',            true,  null],
        ] as [$colonna, $rimuovi, $campoFile, $cartella, $audio, $sostituisci]) {
            if (sec_bool($_POST[$rimuovi] ?? 0)) {
                mfc_togli($cartella, $old[$colonna] ?? '');
                $campi[$colonna] = '';
                continue;
            }
            /* per le immagini serve anche la spunta "sostituisci"; per l'audio
               basta che sia arrivato un file, come nell'originale */
            if ($sostituisci !== null && !sec_bool($_POST[$sostituisci] ?? 0)) continue;
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
            sec_q("UPDATE fc_child_cards SET " . implode(', ', $sets) . " WHERE id = ?", array_merge($vals, [$id]));
        } else {
            sec_q("UPDATE fc_images SET " . implode(', ', $sets) . " WHERE id = ?", array_merge($vals, [$id]));
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
<script>
        window.MathJax = {
            tex: {
                inlineMath: [['$', '$'], ['\\(', '\\)']],
                displayMath: [['$$', '$$'], ['\\[', '\\]']]
            }
        };
    </script>
    <script id="MathJax-script" async src="https://cdn.jsdelivr.net/npm/mathjax@3/es5/tex-mml-chtml.js"></script>

<link rel="stylesheet" href="<?php echo PWA_BASE; ?>modifica_immagine_fc.css?v=<?php echo @filemtime(dirname(__FILE__) . '/modifica_immagine_fc.css') ?: 1; ?>">
</head>
<body>

<?php
    include 'sidebar.html';

    // ============================================================
    // Funzioni di supporto per generare i blocchi HTML ripetuti
    // (domanda / risposta) senza duplicare markup — nessuna logica
    // di business qui dentro, solo presentazione.
    // ============================================================

    function fc_image_block($p, $img) {
        ?>
        <div class="img-compare">
            <div class="img-box">
                <span class="img-tag"><?php te('fc.attuale'); ?></span>
                <?php if (!empty($img)): ?>
                    <img src="fc_Images/<?php echo sec_h($img); ?>">
                <?php else: ?>
                    <div class="img-empty"><?php te('vocab.mi_nessuna_immagine'); ?></div>
                <?php endif; ?>
            </div>
            <div class="img-box img-box-new">
                <span class="img-tag img-tag-new"><?php te('vocab.mi_nuova'); ?></span>
                <img id="<?php echo sec_h($p); ?>_preview" style="display:none;">
                <div id="<?php echo sec_h($p); ?>_no_img" class="img-empty img-empty-warn" style="display:none;"><?php te('vocab.mi_senza_immagine'); ?></div>
            </div>
        </div>
        <div class="file-row">
            <input type="file" name="<?php echo sec_h($p); ?>New_image" id="<?php echo sec_h($p); ?>New_image" accept="image/*" style="display:none;">
            <label for="<?php echo sec_h($p); ?>New_image" class="file-btn">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 8h3l1.6-2.2h6.8L17 8h3a1 1 0 0 1 1 1v9a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9a1 1 0 0 1 1-1z"/><circle cx="12" cy="13.2" r="3.3"/></svg>
                <?php te('vocab.ins_scegli_foto'); ?>
            </label>
        </div>
        <div class="control-panel">
            <label class="chk-row chk-blue"><input type="checkbox" name="replace_<?php echo sec_h($p); ?>_img" id="replace_<?php echo sec_h($p); ?>_img" value="1"> <?php te('vocab.mi_sostituisci'); ?></label>
            <label class="chk-row chk-red"><input type="checkbox" name="remove_<?php echo sec_h($p); ?>_img" id="remove_<?php echo sec_h($p); ?>_img" value="1" onchange="toggleImgRemove('<?php echo sec_h($p); ?>')"> <?php te('vocab.mi_rimuovi_immagine'); ?></label>
        </div>
        <?php
    }

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
    if (isset($_GET['fc_id']) || isset($_GET['child_id'])) {

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
        // ---------------------------------------------------------------------------

        if ($row) {
            $category_id = $is_child ? $prev_cat : $row['category_id'];
            $ex_count = (int)(sec_one("SELECT COUNT(*) AS c FROM fc_examples WHERE fc_image_id = ?", [$id])['c'] ?? 0);

            $cancel_url = isset($_GET['return_to_game'])
                ? "game_fc.php?game_id=" . $_GET['game_id'] . "&position=" . $_GET['position'] . "&status=" . $_GET['status'] . "&order=" . $_GET['order']
                : "visualizzazione_fc.php?category_id=" . $category_id;
            ?>

            <div class="app-header">
                <div class="hd-eyebrow"><?php te('fc.mfc_modifica_scheda'); ?></div>
                <h1><?php te('fc.ifc_domanda_risposta'); ?><?php if ($is_child) echo ' <span class="child-badge">' . sec_h(t('vocab.figlio')) . '</span>'; ?></h1>
            </div>

            <div class="page">
            <form id="editFcForm" method="POST" enctype="multipart/form-data">

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

                <!-- ============== CARD DOMANDA ============== -->
                <section class="fc-card">
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

                    <?php fc_image_block('q', $row['question_image']); ?>
                    <?php fc_audio_block('q', t('fc.ifc_voc_domanda'), $row['question_audio']); ?>
                </section>

                <!-- ============== CARD RISPOSTA ============== -->
                <section class="fc-card">
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

                    <?php fc_image_block('a', $row['answer_image']); ?>
                    <?php fc_audio_block('a', t('fc.ifc_voc_risposta'), $row['answer_audio']); ?>
                </section>

                <!-- ============== ESEMPI ============== -->
                <section class="fc-card fc-card-tight">
                    <?php if ($ex_count > 0): ?>
                        <button type="button" class="ex-btn ex-btn-filled" onclick="openExamplesModal(<?php echo (int)$id; ?>, document.getElementById('quest').value, document.getElementById('answ').value)">
                            <?php te('fc.mfc_gestisci_esempi'); ?> <span class="ex-badge"><?php echo (int)$ex_count; ?></span>
                        </button>
                    <?php else: ?>
                        <button type="button" class="ex-btn ex-btn-empty" onclick="openExamplesModal(<?php echo (int)$id; ?>, document.getElementById('quest').value, document.getElementById('answ').value)">
                            <?php te('fc.mfc_aggiungi_esempio'); ?>
                        </button>
                    <?php endif; ?>
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

                <!-- ============== BARRA AZIONI ============== -->
                <div class="bottom-bar">
                    <div class="bottom-bar-row">
                        <button type="button" class="btn-cancel" onclick="window.location.href='<?php echo sec_h($cancel_url); ?>'"><?php te('comune.annulla'); ?></button>
                        <button type="submit" name="save" value="1" class="btn-save"><?php te('vocab.mi_salva'); ?></button>
                    </div>
                    <button type="submit" name="delete" value="1" class="btn-delete-link" onclick="return confirm('Eliminare definitivamente questa scheda?');"><?php te('vocab.mi_elimina_def'); ?></button>
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

    var mathJaxTimeouts = {};
    function updatePreview(textarea, previewId) {
        var previewEl = document.getElementById(previewId);
        if (previewEl && typeof parseTextForPreview === 'function') {
            previewEl.innerHTML = parseTextForPreview(textarea.value);
            clearTimeout(mathJaxTimeouts[previewId]);
            mathJaxTimeouts[previewId] = setTimeout(function () { fcTypesetMath([previewEl]); }, 400);
        }
    }

    function setupPreview(inputId, imgId, checkId) {
        var inputEl = document.getElementById(inputId);
        if (!inputEl) return;
        inputEl.addEventListener('change', function (e) {
            if (this.files && this.files[0]) {
                var r = new FileReader();
                r.onload = function (ex) {
                    var img = document.getElementById(imgId);
                    img.src = ex.target.result;
                    img.style.display = 'inline-block';
                    document.getElementById(inputId.startsWith('q') ? 'q_no_img' : 'a_no_img').style.display = 'none';
                    document.getElementById(checkId).checked = true;
                };
                r.readAsDataURL(this.files[0]);
            }
        });
    }
    setupPreview('qNew_image', 'q_preview', 'replace_q_img');
    setupPreview('aNew_image', 'a_preview', 'replace_a_img');

    function toggleImgRemove(type) {
        var removed = document.getElementById('remove_' + type + '_img').checked;
        if (removed) {
            document.getElementById(type + '_preview').style.display = 'none';
            document.getElementById(type + '_no_img').style.display = 'block';
            document.getElementById(type + 'New_image').value = '';
            document.getElementById('replace_' + type + '_img').checked = false;
        } else {
            document.getElementById(type + '_no_img').style.display = 'none';
        }
    }

    // ============================================================
    // REGISTRAZIONE AUDIO
    // ============================================================
    var recQ, recA;
    var chunksQ = [], chunksA = [];
    var blobQ = null, blobA = null;
    var extQ = 'webm', extA = 'webm';
    var timerQ = null, timerA = null, secQ = 0, secA = 0;

    function fcFmtTime(s) {
        var m = Math.floor(s / 60), ss = s % 60;
        return m + ':' + String(ss).padStart(2, '0');
    }

    async function toggleRecord(type) {
        var btn = document.getElementById('btn_rec_' + type);
        var statusEl = document.getElementById('rec_status_' + type);
        var isRecording = btn.classList.contains('recording');

        if (!isRecording) {
            var stream;
            try { stream = await navigator.mediaDevices.getUserMedia({ audio: true }); }
            catch (err) { alert(T('fc.ifc_no_microfono')); return; }
            var rec = new MediaRecorder(stream);
            if (type === 'q') { recQ = rec; chunksQ = []; } else { recA = rec; chunksA = []; }
            rec.ondataavailable = function (e) { (type === 'q' ? chunksQ : chunksA).push(e.data); };
            rec.onstop = function () {
                var chunks = type === 'q' ? chunksQ : chunksA;
                var ext = rec.mimeType.indexOf('mp4') !== -1 ? 'm4a' : 'webm';
                var blob = new Blob(chunks, { type: rec.mimeType });
                if (type === 'q') { blobQ = blob; extQ = ext; } else { blobA = blob; extA = ext; }
                var player = document.getElementById('audio_preview_' + type);
                player.src = URL.createObjectURL(blob);
                player.style.display = 'block';
            };
            rec.start();
            btn.classList.add('recording');
            btn.querySelector('.ic-mic').style.display = 'none';
            btn.querySelector('.ic-stop').style.display = '';
            if (type === 'q') { secQ = 0; statusEl.textContent = '0:00'; timerQ = setInterval(function () { secQ++; statusEl.textContent = fcFmtTime(secQ); }, 1000); }
            else { secA = 0; statusEl.textContent = '0:00'; timerA = setInterval(function () { secA++; statusEl.textContent = fcFmtTime(secA); }, 1000); }
        } else {
            if (type === 'q') { recQ.stop(); recQ.stream.getTracks().forEach(function (t) { t.stop(); }); clearInterval(timerQ); }
            else { recA.stop(); recA.stream.getTracks().forEach(function (t) { t.stop(); }); clearInterval(timerA); }
            btn.classList.remove('recording');
            btn.querySelector('.ic-mic').style.display = '';
            btn.querySelector('.ic-stop').style.display = 'none';
            statusEl.textContent = 'Audio pronto';
        }
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
    if (document.getElementById('editFcForm')) {
        document.getElementById('editFcForm').addEventListener('submit', async function (e) {
            var activeSubmit = e.submitter ? e.submitter.name : '';
            if (activeSubmit === 'delete') return true;

            e.preventDefault();

            fcSyncAllEditors();

            var subBtn = this.querySelector('button[name="save"]');
            var originalText = subBtn.innerHTML;
            subBtn.disabled = true;
            subBtn.innerHTML = 'Salvataggio in corso…';

            var fd = new FormData(this);
            if (blobQ) fd.append('audio_q', blobQ, 'q.' + extQ);
            if (blobA) fd.append('audio_a', blobA, 'a.' + extA);

            try {
                var res = await fetch('modifica_immagine_fc.php', { method: 'POST', body: fd });
                var text = await res.text();
                document.open();
                document.write(text);
                document.close();
            } catch (err) {
                alert('Errore durante il salvataggio.');
                subBtn.disabled = false;
                subBtn.innerHTML = originalText;
            }
        });
    }
    </script>

    <?php include 'examples_modal.php'; ?>

</body>
</html>