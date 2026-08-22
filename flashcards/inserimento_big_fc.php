<?php
// connessione al database
require_once 'config.php';
require_once 'auth_check.php'; // 1. Protezione con login obbligatorio

$current_user_id = (int)$utente_corrente['id']; // 2. ID utente sicuro dal server

/* sec_db() DOPO auth_check: e' quest'ultimo a caricare security_core.php. */
$conn = sec_db();

/* I tipi di audio ammessi: non sono immagini, quindi hanno un elenco loro.
   sec_file() guarda il contenuto vero con finfo, mai l'estensione dichiarata. */
const FC_AUDIO_MIME = [
    'audio/mpeg' => 'mp3', 'audio/mp3' => 'mp3', 'audio/wav' => 'wav',
    'audio/x-wav' => 'wav', 'audio/webm' => 'webm', 'audio/ogg' => 'ogg',
    'audio/mp4' => 'm4a', 'audio/aac' => 'aac',
];

/* Salva un file preso da un campo multiplo ($_FILES[campo][...][indice]).
   Restituisce il nome scritto dal server, o '' se non c'era niente o il
   contenuto non e' del tipo giusto. Il nome del client non si usa mai. */
function ibf_salva_multi($campo, $i, $cartella, $audio) {
    if (!isset($_FILES[$campo]['name'][$i])) return '';
    if ((int)$_FILES[$campo]['error'][$i] !== UPLOAD_ERR_OK) return '';
    $file = [
        'tmp_name' => $_FILES[$campo]['tmp_name'][$i],
        'error'    => $_FILES[$campo]['error'][$i],
        'size'     => $_FILES[$campo]['size'][$i],
    ];
    $ris = $audio ? sec_file($file, FC_AUDIO_MIME, 10485760) : sec_file($file);
    if (empty($ris['ok'])) return '';
    if (!is_dir($cartella)) { @mkdir($cartella, 0755, true); }
    $nome = sec_safe_filename($ris['ext']);
    return move_uploaded_file($ris['tmp'], rtrim($cartella, '/') . '/' . $nome) ? $nome : '';
}

/* Cerca nel gruppo di immagini quella che ha il nome scritto nella riga e la
   salva. Il nome serve SOLO a trovare il file fra quelli caricati: quello
   scritto sul disco lo genera il server. Prima l'estensione veniva presa da
   quel nome, quindi scrivere "trucco.php" e caricare un file con quel nome
   bastava a piazzarlo eseguibile nella cartella delle immagini. */
function ibf_salva_dal_gruppo($campo, $nome_cercato, $cartella) {
    if ($nome_cercato === '' || !isset($_FILES[$campo]['name'])) return '';
    foreach ($_FILES[$campo]['name'] as $idx => $fname) {
        if ($fname !== $nome_cercato) continue;
        return ibf_salva_multi($campo, $idx, $cartella, false);
    }
    return '';
}

// --- RICEVITORE AJAX PER L'INSERIMENTO MULTIPLO ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'insert_bulk') {
    header('Content-Type: application/json');

    /* Token di sessione, origine e limite di frequenza: prima non c'erano.
       60 al minuto: un inserimento multiplo e' una richiesta sola, per quante
       righe contenga. */
    sec_guard_write($current_user_id, 'fc_ins_big', 0, 60, 60);

    $categoryId = sec_int($_POST['category_id'] ?? 0, 1);
    if (!sec_owns('fc_categories', $categoryId, $current_user_id)) {
        echo json_encode(['success' => false, 'error' => t('fc.non_autorizzato_aggiungere')]);
        exit;
    }

    // Trova ordine
    $max_o = (int)(sec_one("SELECT MAX(custom_order) AS m FROM fc_images WHERE category_id = ?",
                           [$categoryId])['m'] ?? 0) + 1;

    $rows_count = isset($_POST['q_desc']) && is_array($_POST['q_desc']) ? count($_POST['q_desc']) : 0;
    $inserted_count = 0;
    $last_parent_id = 0;
    $child_order = 1;

    for ($i = 0; $i < $rows_count; $i++) {
        $qd = sec_text($_POST['q_desc'][$i] ?? '', 2000);
        $ad = sec_text($_POST['a_desc'][$i] ?? '', 2000);

        $q_img_name = sec_str($_POST['q_img_name'][$i] ?? '', 255);
        $a_img_name = sec_str($_POST['a_img_name'][$i] ?? '', 255);

        $is_child = sec_int($_POST['is_child'][$i] ?? 0, 0, 1);

        // Se la riga e' completamente vuota, la saltiamo
        if ($qd === '' && $ad === '' && $q_img_name === '' && $a_img_name === '') continue;

        /* Immagini: prima il file caricato su questa riga, poi (ripiego) quello
           del gruppo abbinato per nome. */
        $final_q_img = ibf_salva_multi('row_q_img', $i, FC_IMAGE_PATH_INSERT, false);
        if ($final_q_img === '') $final_q_img = ibf_salva_dal_gruppo('question_images', $q_img_name, FC_IMAGE_PATH_INSERT);

        $final_a_img = ibf_salva_multi('row_a_img', $i, FC_IMAGE_PATH_INSERT, false);
        if ($final_a_img === '') $final_a_img = ibf_salva_dal_gruppo('answer_images', $a_img_name, FC_IMAGE_PATH_INSERT);

        // Riga senza testo e senza immagini valide: non c'e' niente da salvare
        if ($qd === '' && $ad === '' && $final_q_img === '' && $final_a_img === '') continue;

        $final_q_aud = ibf_salva_multi('audio_q', $i, 'fc_audio', true);
        $final_a_aud = ibf_salva_multi('audio_a', $i, 'fc_audio', true);

        // --- INSERIMENTO NEL DB ---
        if ($is_child === 0) {
            sec_q("INSERT INTO fc_images (category_id, question_desc, question_image, question_audio, answer_desc, answer_image, answer_audio, custom_order)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                  [$categoryId, $qd, $final_q_img, $final_q_aud, $ad, $final_a_img, $final_a_aud, $max_o]);
            $last_parent_id = sec_insert_id();
            $max_o++;
            $child_order = 1;
            $inserted_count++;
        } elseif ($last_parent_id > 0) {
            sec_q("INSERT INTO fc_child_cards (parent_id, question_desc, question_image, question_audio, answer_desc, answer_image, answer_audio, custom_order)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                  [$last_parent_id, $qd, $final_q_img, $final_q_aud, $ad, $final_a_img, $final_a_aud, $child_order]);
            $child_order++;
            sec_q("UPDATE fc_images SET is_father = 1 WHERE id = ? AND category_id = ?", [$last_parent_id, $categoryId]);
            $inserted_count++;
        }
    }
    echo json_encode(['success' => true, 'count' => $inserted_count, 'category_id' => $categoryId]);
    exit;
}

/* $resultCategories non e' usata da nessuna parte in questo file: l'elenco lo
   costruisce category_selector.php, incluso piu' sotto. La query resta perche'
   togliere una variabile che qualcuno potrebbe leggere da un include e' un
   rischio inutile - ma se un giorno si conferma che non serve, va via. */
$resultCategories = sec_all("SELECT id, name FROM fc_categories WHERE user_id = ? ORDER BY name",
                            [$current_user_id]);

// Categoria pre-selezionata (es. arrivo da inserimento_fc.php con ?category_id=123)
$preselected_cat_id = 0;
$preselected_cat_name = '';
if (isset($_GET['category_id'])) {
    $preselected_cat_id = sec_int($_GET['category_id'] ?? 0, 0);
    if ($preselected_cat_id > 0) {
        // Pre-seleziona la categoria SOLO se appartiene all'utente
        $pcRow = sec_one("SELECT name FROM fc_categories WHERE id = ? AND user_id = ?",
                         [$preselected_cat_id, $current_user_id]);
        if ($pcRow) { $preselected_cat_name = $pcRow['name']; }
        else { $preselected_cat_id = 0; } // id non valido o di un altro utente: si ignora
    }
}
?>

<!DOCTYPE html>



<html lang="it">



<head>

    <meta name="csrf-token" content="<?php echo sec_csrf_token(); ?>">
    <script src="nisama_fetch.js"></script>

    <meta charset="UTF-8">



    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=0, viewport-fit=cover">



    <meta name="mobile-web-app-capable" content="yes">



    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">



    <meta name="format-detection" content="telephone=no">



    <meta name="theme-color" content="#F2F2F7" media="(prefers-color-scheme: light)">



    <meta name="theme-color" content="#000000" media="(prefers-color-scheme: dark)">



    <title><?php te('fc.ibfc_titolo'); ?></title>







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







<link rel="stylesheet" href="<?php echo PWA_BASE; ?>inserimento_big_fc.css?v=<?php echo @filemtime(dirname(__FILE__) . '/inserimento_big_fc.css') ?: 1; ?>">






    <script src="fc_parser.js"></script>



</head>



<body>



<?php include 'sidebar.html'; ?>







<div id="parent-selection-banner">



    <span><?php te('fc.ibfc_tocca_padre'); ?></span>



    <button type="button" onclick="cancelChildSelection()"><?php te('fc.ibfc_annulla_x'); ?></button>



</div>







<header class="app-header">



    <div class="hdr-text">



        <h1><?php te('fc.ibfc_big_insert'); ?></h1>



        <div class="hdr-sub"><?php te('fc.ibfc_flashcards_uni'); ?></div>



    </div>



    <div class="hdr-chip" id="row-count-chip"><?php te('fc.ibf_n_righe', ['n' => 0]); ?></div>



</header>

<div style="display: none;" id="editor-template-container">
    <?php 
        $ft_field = '%%FIELD_ID%%';
        $ft_raw = '%%RAW_TEXT%%';
        $ft_placeholder = '%%PLACEHOLDER%%';
        include 'format_toolbar.php'; 
    ?>
</div>





<div class="page-wrap">







    <div id="success-msg">



        <?php th('fc.ibf_completato', ['n' => '<strong id="success-count">0</strong>']); ?>



        <small><?php te('vocab.ib_ritorno'); ?></small>



    </div>







    <div class="format-help">



        <strong><?php te('vocab.ib_prompt_titolo'); ?></strong>



        <p><?php te('fc.ibf_prompt_sub'); ?></p>



        <div class="ai-prompt-box">
            <span class="ai-copy-hint"><?php te('vocab.ib_tocca_copiare'); ?></span>
            <textarea id="ai_prompt_textarea" readonly onclick="this.select(); navigator.clipboard.writeText(this.value).then(() => showToast('Copiato negli appunti! 📋')).catch(() => { document.execCommand('copy'); showToast('Copiato negli appunti! 📋'); });"></textarea>
        </div>



    </div>







    <form id="imageForm">



        <div class="setup-grid">



            <div class="card-surface setup-card full">



                <div class="sc-label"><span class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2Z"/></svg></span><?php te('vocab.ib_categoria_dest'); ?></div>



                <?php 



                    $cs_id = 'inserimento_big';



                    $cs_placeholder = T('vocab.ib_ph_seleziona');



                    $cs_selected_id = $preselected_cat_id;



                    $cs_selected_name = $preselected_cat_name;



                    include 'category_selector.php'; 



                ?>



            </div>







            <div class="card-surface setup-card">



                <div class="sc-label"><span class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3.2"/><path d="M4 7h2.2L8 4h8l1.8 3H20a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V8a1 1 0 0 1 1-1Z"/></svg></span><?php te('vocab.ib_immagini_opz'); ?></div>



                <label class="mini-label"><?php te('fc.ibfc_immagini_domanda'); ?></label>



                <input type="file" id="question_images" accept="image/*" multiple style="margin-bottom:12px;">



                <label class="mini-label"><?php te('fc.ibfc_immagini_risposta'); ?></label>



                <input type="file" id="answer_images" accept="image/*" multiple>



            </div>







            <div class="card-surface setup-card">
                <div class="sc-label">
                    <span class="ic">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 6h16M4 12h16M4 18h10"/></svg>
                    </span>
                    <?php te('vocab.ib_importa_testo'); ?>
                </div>
                
                <!-- Switch per il formato -->
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; background: var(--surface-2); padding: 10px 12px; border-radius: 12px; border: 1px solid var(--hairline-2);">
                    <label class="mini-label" style="margin:0; font-size: 13.5px;"><?php te('vocab.ib_separatori_pers'); ?></label>
                    <input type="checkbox" id="format_switch" style="transform: scale(1.2); accent-color: var(--blue); cursor: pointer;" onchange="toggleImportFormat()">
                </div>

                <textarea id="import_text" rows="3" placeholder='Incolla qui l&#39;array JSON. Esempio: [{"q": "Domanda", "a": "Risposta"}]' style="font-size:14.5px;"></textarea>
                
                <!-- Contenitore separatori, nascosto di default -->
                <div class="import-row" id="custom_separators_row" style="display: none;">
                    <div>
                        <label class="mini-label"><?php te('fc.ibfc_sep_flashcards'); ?></label>
                        <select id="import_row_separator" onchange="updatePlaceholder()">
                            <option value="@@@" selected><?php te('vocab.ib_sep_tre_chiocciole'); ?></option>
                            <option value="@"><?php te('vocab.ib_sep_chiocciola'); ?></option>
                            <option value="\n"><?php te('vocab.ib_sep_a_capo'); ?></option>
                        </select>
                    </div>
                    <div>
                        <label class="mini-label"><?php te('fc.ibfc_sep_domris'); ?></label>
                        <select id="import_separator" onchange="updatePlaceholder()">
                            <option value="###" selected><?php te('vocab.ib_sep_tre_hash'); ?></option>
                            <option value="|"><?php te('vocab.ib_sep_pipe'); ?></option>
                            <option value=";"><?php te('vocab.ib_sep_punto_virgola'); ?></option>
                        </select>
                    </div>
                </div>
            </div>



        </div>







        <button type="button" id="btn-generate-table" class="btn btn-warn" onclick="generateTable()">



            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12l7 7 7-7"/></svg>



            <?php te('vocab.ib_genera_tabella'); ?>



        </button>







        <div class="fc-rows" id="fcRows"></div>



        <div class="empty-hint" id="emptyHint"><?php te('fc.ibfc_nessuna_riga'); ?></div>







        <div class="action-stack">



            <button type="button" class="btn btn-primary" onclick="addSingleRow()">



                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>



                <?php te('fc.ibfc_aggiungi_riga'); ?>



            </button>



            <button type="submit" id="submitBtn" class="btn btn-success">



                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5 10 17l9-10"/></svg>



                <?php te('vocab.ib_salva_tutto'); ?>



            </button>



        </div>



    </form>



</div>







<div class="confirm-overlay" id="confirmOverlay">



    <div class="confirm-panel">



        <h3 id="confirmTitle"><?php te('vocab.ib_sei_sicuro'); ?></h3>



        <p id="confirmMessage"></p>



        <div class="confirm-actions">



            <button type="button" class="btn btn-ghost btn-sm" id="confirmCancelBtn"><?php te('comune.annulla'); ?></button>



            <button type="button" class="btn btn-warn btn-sm" id="confirmOkBtn"><?php te('vocab.conferma_b'); ?></button>



        </div>



    </div>



</div>



<script>



    /* ============================================================



       ICONE INLINE



       ============================================================ */



    const ICON_TRASH = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h16M9 7V5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2m2 0-1 13a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2L6 7"/></svg>';



    const ICON_LINK  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M9.5 14.5 14.5 9.5M8 17l-2 2a3.5 3.5 0 0 1-5-5l3-3a3.5 3.5 0 0 1 5 0M16 7l2-2a3.5 3.5 0 0 1 5 5l-3 3a3.5 3.5 0 0 1-5 0"/></svg>';



    const ICON_UNLINK= '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 17H8a4 4 0 0 1 0-8h1M11 7h5a4 4 0 0 1 0 8h-1M4 4l16 16"/></svg>';



    const ICON_GRIP  = '<svg viewBox="0 0 24 24" fill="currentColor"><circle cx="8" cy="6" r="1.6"/><circle cx="16" cy="6" r="1.6"/><circle cx="8" cy="12" r="1.6"/><circle cx="16" cy="12" r="1.6"/><circle cx="8" cy="18" r="1.6"/><circle cx="16" cy="18" r="1.6"/></svg>';



    const ICON_REORDER = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m8 9 4-4 4 4M8 15l4 4 4-4"/></svg>';



    const ICON_IMG   = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="10" r="1.6"/><path d="m21 16-5-5-4 4-3-3-6 6"/></svg>';



    const ICON_GEN   = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12l7 7 7-7"/></svg>';



    const ICON_CHECK = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5 10 17l9-10"/></svg>';







    function escAttr(s){



        return String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');



    }







    /* ============================================================



       STATO GLOBALE



       ============================================================ */



    let isSelectingParent = false;



    let childRowPending = null;



    let gestureBlock = false;



    let activeDrag = null;



    let autoScrollRAF = null;



    let lastPointerY = 0;



    let suppressNextRowClick = false;







    function buzz(ms){ try{ if (navigator.vibrate) navigator.vibrate(ms); }catch(e){} }



    function isChildRow(row){ return row.classList.contains('is-child'); }



    function getBlockStart(row){ let r = row; while (r && isChildRow(r)) r = r.previousElementSibling; return r; }



    function getBlockRows(parentRow){



        const rows = [parentRow];



        let cur = parentRow.nextElementSibling;



        while (cur && isChildRow(cur)) { rows.push(cur); cur = cur.nextElementSibling; }



        return rows;



    }







    /* ============================================================



       TOAST & MODALE CONFERMA



       ============================================================ */



    function showToast(msg, kind){



        const t = document.createElement('div');



        t.className = 'app-toast' + (kind === 'error' ? ' toast-error' : '');



        t.textContent = msg;



        document.body.appendChild(t);



        requestAnimationFrame(()=> t.classList.add('show'));



        setTimeout(()=>{ t.classList.remove('show'); setTimeout(()=> t.remove(), 320); }, 3200);



    }







    function showConfirm(title, message, okLabel){



        return new Promise(resolve=>{



            const overlay = document.getElementById('confirmOverlay');



            document.getElementById('confirmTitle').textContent = title;



            document.getElementById('confirmMessage').textContent = message;



            const okBtn = document.getElementById('confirmOkBtn');



            const cancelBtn = document.getElementById('confirmCancelBtn');



            okBtn.textContent = okLabel || T('vocab.conferma_b');



            overlay.classList.add('show');



            function cleanup(result){



                overlay.classList.remove('show');



                okBtn.removeEventListener('click', onOk);



                cancelBtn.removeEventListener('click', onCancel);



                resolve(result);



            }



            function onOk(){ cleanup(true); }



            function onCancel(){ cleanup(false); }



            okBtn.addEventListener('click', onOk);



            cancelBtn.addEventListener('click', onCancel);



        });



    }







    /* ============================================================



       FLIP — animazioni fluide su ogni riordino del DOM



       ============================================================ */



    function flip(container, mutate){



        const rows = Array.from(container.children);



        const first = new Map();



        rows.forEach(r=> first.set(r, r.getBoundingClientRect()));



        mutate();



        Array.from(container.children).forEach(r=>{



            const f = first.get(r);



            if (!f) return;



            const l = r.getBoundingClientRect();



            const dx = f.left - l.left, dy = f.top - l.top;



            if (Math.abs(dx) > 0.5 || Math.abs(dy) > 0.5){



                r.style.transition = 'none';



                r.style.transform = `translate(${dx}px, ${dy}px)`;



                requestAnimationFrame(()=>{



                    r.style.transition = 'transform .38s var(--spring)';



                    r.style.transform = '';



                });



            }



        });



    }







    /* ============================================================



       CONTATORI / STATO PULSANTI



       ============================================================ */



    function updateRowCountChip(){



        const n = document.querySelectorAll('#fcRows .fc-row').length;



        const chip = document.getElementById('row-count-chip');



        if (chip) chip.textContent = n + T(n === 1 ? 'fc.ibf_una_riga' : 'fc.ibf_n_righe_plur', {n: n});



    }



    function toggleEmptyHint(){



        const hint = document.getElementById('emptyHint');



        if (hint) hint.style.display = document.getElementById('fcRows').children.length ? 'none' : 'block';



    }



    function updateGenerateButtonState(){



        const list = document.getElementById('fcRows');



        const btn = document.getElementById('btn-generate-table');



        if (list.children.length > 0){



            btn.classList.remove('btn-warn'); btn.classList.add('btn-flat-warn');



            btn.innerHTML = T('vocab.ib_tabella_piena');



        } else {



            btn.classList.add('btn-warn'); btn.classList.remove('btn-flat-warn');



            btn.innerHTML = ICON_GEN + T('vocab.ib_genera_tabella');



        }



        updateRowCountChip();



        toggleEmptyHint();



    }







    /* ============================================================



       MOTORE PREVIEW 



       ============================================================ */



    let mathJaxTimeouts = {};







    /* ============================================================



       GENERAZIONE TABELLA & RIGHE



       ============================================================ */

    function updateAIPrompt() {
        const isCustom = document.getElementById('format_switch').checked;
        const rowSepVal = document.getElementById('import_row_separator').value;
        const colSepVal = document.getElementById('import_separator').value;
        
        let rule4 = "";
        let exampleOutput = "";
        
        // Regole di base sempre uguali
        const baseRules = T('fc.ibf_prompt_base');

        // Aggiunta dinamica della Regola 4 e dell'esempio
        // Aggiunta dinamica della Regola 4 e dell'esempio
    if (!isCustom) {
        rule4 = T('fc.ibf_regola_json');
                
                // Uso i doppi e quadrupli backslash per farli stampare correttamente nella textarea
                exampleOutput = `${T('vocab.ib_esempio_output')}
[
{"q": "Prima domanda /bold{importante}", "a": "Prima risposta"},
{"q": "Formula di $\\\\mu$?", "a": "La risposta:\\n$$\\\\frac{\\\\sigma}{\\\\sqrt{n}}$$\\n/red{Attenzione...}"}
]`;
            } else {
            const rowSepText = rowSepVal === '\\n' ? T('vocab.ib_un_invio') : `"${rowSepVal}"`;
            const colSepText = colSepVal === '\\t' ? T('vocab.ib_una_tab') : `"${colSepVal}"`;
            const actualRowSep = rowSepVal === '\\n' ? '\n' : rowSepVal;
            
            rule4 = T('fc.ibf_regola_separatori', {colSepText: colSepText, rowSepText: rowSepText});
            exampleOutput = `${T('vocab.ib_esempio_output')}
Prima domanda /bold{importante} ${colSepVal} Prima risposta${actualRowSep}Dimmi la formula di $domanda$ ${colSepVal} La risposta è $$formula$$ /red{attenzione...}`;
        }

        const finalPrompt = `${baseRules}\n${rule4}\n\n${exampleOutput}\n\n${T('fc.ibf_prompt_concetti')}`;
        
        document.getElementById('ai_prompt_textarea').value = finalPrompt;
    }

    function updatePlaceholder() {
        const isCustom = document.getElementById('format_switch').checked;
        const textArea = document.getElementById('import_text');

        if (isCustom) {
            let rowSep = document.getElementById('import_row_separator').value;
            let colSep = document.getElementById('import_separator').value;
            if (rowSep === '\\n') rowSep = T('fc.ibf_invio_a_capo');
            
            textArea.placeholder = `Incolla qui il testo generato dall'IA con ${rowSep} e ${colSep}…`;
        } else {
            textArea.placeholder = 'Incolla qui l\'array JSON. Esempio: [{"q": "Domanda", "a": "Risposta"}]';
        }
        
        // Aggiorniamo anche il prompt
        updateAIPrompt();
    }

    function toggleImportFormat() {
        const isCustom = document.getElementById('format_switch').checked;
        const customRow = document.getElementById('custom_separators_row');

        customRow.style.display = isCustom ? 'flex' : 'none';
        
        updatePlaceholder();
    }

    // Inizializza i testi corretti appena si carica la pagina
    window.addEventListener('DOMContentLoaded', () => {
        updatePlaceholder();
    });

    async function generateTable(){
        const list = document.getElementById('fcRows');
        if (list.children.length > 0){
            const ok = await showConfirm(
                T('vocab.ib_ricreare'),
                T('fc.ibf_ricreare_txt'),
                T('vocab.ib_si_ricrea')
            );
            if (!ok) return;
        }
        list.innerHTML = '';

        const filesQ = document.getElementById('question_images').files;
        const filesA = document.getElementById('answer_images').files;
        const textContent = document.getElementById('import_text').value.trim();
        const isCustom = document.getElementById('format_switch').checked;

        let parsedData = []; // Salveremo qui le domande e risposte strutturate

        if (textContent !== '') {
            if (!isCustom) {
                // LOGICA DI PARSING JSON
                try {
                    // 1. Ripara gli a capo "fisici" inseriti per errore (es. nelle formule isolate $$...$$)
                    // Trova gli invii che non fanno parte della struttura del JSON e li trasforma in \n
                    let safeText = textContent.replace(/\n(?!\s*[\}\]\s]*\s*(?:,|\]|"q"|"a"|\{))/g, '\\n');
                    
                    // 2. Raddoppia i backslash del LaTeX (\mu diventa \\mu, % diventa \\%), per evitare che
                    // il JSON li scambi per comandi di sistema non validi. Esclude \n, \t, " e / che sono già ok.
                    safeText = safeText.replace(/(?<!\\)\\(?!n|t|r|"|\\|\/)/g, '\\\\');
                    
                    const jsonArr = JSON.parse(safeText);
                    if (!Array.isArray(jsonArr)) throw new Error(T('vocab.ib_json_array'));
                    
                    parsedData = jsonArr.map(item => ({
                        // Estrae il testo e sostituisce tutti i "\%" con "%"
                        q: (item.q || item.domanda || item.question || '').replace(/\\%/g, '%'),
                        a: (item.a || item.risposta || item.answer || '').replace(/\\%/g, '%')
                    }));
                } catch (error) {
                    showToast('Errore: Il testo non è un JSON valido. Controlla la formattazione.', 'error');
                    console.error("Dettaglio errore JSON:", error); // Così puoi vederlo in console se serve
                    return; 
                }
            } else {
                // VECCHIA LOGICA DI PARSING CON SEPARATORI
                let colSeparator = document.getElementById('import_separator').value;
                if (colSeparator === '\\t') colSeparator = '\t';
                let rowSeparator = document.getElementById('import_row_separator').value;
                if (rowSeparator === '\\n') rowSeparator = '\n';

                const parsedLines = textContent.split(rowSeparator).map(l=>l.trim()).filter(l=> l !== '');
                parsedData = parsedLines.map(line => {
                    const parts = line.split(colSeparator);
                    return {
                        // Estrae il testo e sostituisce tutti i "\%" con "%"
                        q: (parts[0] ? parts[0].trim() : '').replace(/\\%/g, '%'),
                        a: parts.slice(1).join(colSeparator).trim().replace(/\\%/g, '%')
                    };
                });
            }
        }

        const maxRows = Math.max(filesQ.length, filesA.length, parsedData.length);

        for (let i=0; i<maxRows; i++){
            let qText = '', aText = '';
            if (i < parsedData.length){
                qText = parsedData[i].q;
                aText = parsedData[i].a;
            }
            
            const qUrl = i < filesQ.length ? URL.createObjectURL(filesQ[i]) : '';
            const qName = i < filesQ.length ? filesQ[i].name : '';
            const aUrl = i < filesA.length ? URL.createObjectURL(filesA[i]) : '';
            const aName = i < filesA.length ? filesA[i].name : '';
            
            appendRowHTML(i, qText, aText, qUrl, qName, aUrl, aName, false);
        }

        updateGenerateButtonState();
    }







    function addSingleRow(){

        const uniqueId = Date.now();

        appendRowHTML(uniqueId, '', '', '', '', '', '', false);

        updateGenerateButtonState();

        setTimeout(()=>{

            const row = document.querySelector(`.fc-row[data-rid="${uniqueId}"]`);

            if (row) row.scrollIntoView({behavior:'smooth', block:'center'});

            // Punta all'editor nuovo

            const editorDiv = document.getElementById('q-desc-'+uniqueId+'_editor');

            if (editorDiv) editorDiv.focus({preventScroll:true});

        }, 80);

    }







    





    function fieldTemplate(prefix, id, text, label, imgUrl, imgName, isChild){
        const taId = `${prefix}-desc-${id}`;
        const imgPrevId = `${prefix}-img-preview-${id}`;
        const placeholder = prefix === 'q'
            ? (isChild ? T('fc.ibf_ph_sottodomanda') : T('fc.ibf_ph_domanda'))
            : T('fc.ibf_ph_risposta');
        const thumb = imgUrl ? `<img src="${imgUrl}">` : ICON_IMG;

        // 1. Preleviamo l'HTML base generato dal template nascosto
        let editorHtml = document.getElementById('editor-template-container').innerHTML;
        
        // 2. Sostituiamo i segnaposto con i dati veri
        editorHtml = editorHtml
            .replace(/%%FIELD_ID%%/g, () => taId)
            .replace(/%%RAW_TEXT%%/g, () => escAttr(text))
            .replace(/%%PLACEHOLDER%%/g, () => placeholder);

        return `
        <div class="fc-field">
            <div class="field-label ${prefix === 'a' ? 'is-answer' : ''}">${label}</div>
            
            ${editorHtml}

            <div class="media-row">
                <label class="media-chip img-chip ${imgUrl ? 'has-img' : ''}" id="${prefix}-chip-${id}">
                    <input type="file" accept="image/*" onchange="previewRowImage(this, '${prefix}', '${id}')">
                    <span class="chip-thumb" id="${imgPrevId}">${thumb}</span>
                    <span class="img-name-tag">${imgName ? escAttr(imgName) : T('vocab.immagine')}</span>
                </label>
                <button type="button" class="media-chip btn-rec" onclick="toggleRowRecord(this, '${prefix}')">
                    <span class="chip-thumb"><span class="rec-dot"></span></span>
                    <span class="rec-label">${T('fc.ibf_registra')}</span>
                </button>
                <audio class="audio-player audio-player-${prefix}" controls></audio>
            </div>
            <input type="hidden" class="${prefix}-img-name" value="${imgName ? escAttr(imgName) : ''}">
        </div>`;
    }







    function appendRowHTML(id, qText, aText, qImgUrl, qImgName, aImgUrl, aImgName, isChild){



        const list = document.getElementById('fcRows');



        const wrap = document.createElement('div');



        wrap.className = 'fc-row data-row' + (isChild ? ' is-child' : '');



        wrap.dataset.rid = id;



        wrap.innerHTML = `



            <div class="swipe-actions">



                <button type="button" class="swipe-btn swipe-btn-delete" onclick="swipeDeleteRow(this)">${ICON_TRASH}<span>${T('comune.elimina')}</span></button>



                <button type="button" class="swipe-btn swipe-btn-join" onclick="swipeJoinRow(this)">${ICON_LINK}<span>${T('fc.ibf_figlio')}</span></button>



                <button type="button" class="swipe-btn swipe-btn-unjoin" onclick="swipeUnjoinRow(this)">${ICON_UNLINK}<span>${T('fc.ibf_sgancia')}</span></button>



            </div>



            <div class="fc-row-content">



                <div class="fc-row-strip">



                    <span class="grip">${ICON_GRIP}</span>



                    <span class="row-badge">${isChild ? T('vocab.figlio') : T('fc.ibf_concetto')}</span>



                    <span class="strip-spacer"></span>



                    <span class="strip-hint">${ICON_REORDER} Tieni premuto</span>



                </div>



                <div class="fc-row-grid">



                    ${fieldTemplate('q', id, qText, 'Domanda', qImgUrl, qImgName, isChild)}



                    ${fieldTemplate('a', id, aText, 'Risposta', aImgUrl, aImgName, isChild)}



                </div>



            </div>`;



        list.appendChild(wrap);



        // INIZIALIZZA I NUOVI EDITOR

        wrap.querySelectorAll('.fmt-editor-wrap').forEach(function(editorWrap) {

            if (typeof fcInitEditorWrap === 'function') fcInitEditorWrap(editorWrap);

        });



        updateGenerateButtonState();



    }







    







    function previewRowImage(input, type, id){



        const file = input.files[0];



        const thumb = document.getElementById(`${type}-img-preview-${id}`);



        const chip = document.getElementById(`${type}-chip-${id}`);



        const nameTag = chip.querySelector('.img-name-tag');



        const row = input.closest('.fc-row');







        if (file){



            const url = URL.createObjectURL(file);



            thumb.innerHTML = `<img src="${url}">`;



            chip.classList.add('has-img');



            if (nameTag) nameTag.textContent = file.name;



            if (type === 'q') row.qNewImgFile = file; else row.aNewImgFile = file;



        } else {



            thumb.innerHTML = ICON_IMG;



            chip.classList.remove('has-img');



            if (nameTag) nameTag.textContent = T('vocab.immagine');



            if (type === 'q') delete row.qNewImgFile; else delete row.aNewImgFile;



        }



    }



</script>



<script>



    /* ============================================================



       BADGE / STATO FIGLIO / SWIPE ACTIONS



       ============================================================ */



    function updateRowBadgeText(row){



        const badge = row.querySelector('.row-badge');



        if (badge) badge.textContent = isChildRow(row) ? T('vocab.figlio') : T('fc.ibf_concetto');



    }







    function closeSwipe(row){



        if (!row) return;



        const content = row.querySelector('.fc-row-content');



        content.style.transition = 'transform .32s var(--snap)';



        content.style.transform = 'translateX(0px)';



        row._swipeX = 0;



    }



    function closeAllSwipes(except){



        document.querySelectorAll('#fcRows .fc-row').forEach(r=>{



            if (r !== except && r._swipeX) closeSwipe(r);



        });



    }







    function swipeDeleteRow(btn){



        const row = btn.closest('.fc-row');



        const family = isChildRow(row) ? [row] : getBlockRows(row);



        const list = document.getElementById('fcRows');



        family.forEach(r=>{



            r.style.transition = 'transform .26s var(--spring), opacity .26s var(--spring)';



            r.style.transform = 'translateX(105%) scale(.95)';



            r.style.opacity = '0';



            r.style.pointerEvents = 'none';



        });



        setTimeout(()=>{



            flip(list, ()=> family.forEach(r=> r.remove()));



            updateGenerateButtonState();



        }, 230);



    }







    function swipeJoinRow(btn){



        const row = btn.closest('.fc-row');



        closeSwipe(row);



        startChildSelection(row);



    }







    function swipeUnjoinRow(btn){



        const row = btn.closest('.fc-row');



        unjoinRow(row);



    }







    function unjoinRow(row){



        row.classList.remove('is-child');



        updateRowBadgeText(row);



        const qArea = row.querySelector('.q-desc');



        if (qArea && qArea.placeholder.indexOf(T('fc.ibf_sottodomanda')) !== -1) qArea.placeholder = T('fc.ibf_ph_domanda');







        // Sposta la riga fisicamente alla fine del blocco dei figli attuali,



        // altrimenti "catturerebbe" come propri figli i suoi ex fratelli minori



        // (getBlockRows considera figlia ogni is-child consecutiva dopo una non-figlia).



        let lastChild = row;



        while (lastChild.nextElementSibling && lastChild.nextElementSibling.classList.contains('is-child')) {



            lastChild = lastChild.nextElementSibling;



        }



        if (lastChild !== row) {



            flip(document.getElementById('fcRows'), ()=> lastChild.after(row));



        }







        closeSwipe(row);



        updateGenerateButtonState();



        showToast(T('fc.ibf_sganciato'));



    }







    function markEligibleTargets(){



        document.querySelectorAll('#fcRows .fc-row').forEach(r=>{



            r.classList.remove('eligible-target');



            if (r !== childRowPending && !isChildRow(r)) r.classList.add('eligible-target');



        });



    }







    function startChildSelection(row){



        childRowPending = row;



        isSelectingParent = true;



        closeAllSwipes();



        row.classList.add('pending-child');



        document.getElementById('parent-selection-banner').classList.add('show');



        document.body.classList.add('selecting-mode');



        markEligibleTargets();



    }







    function cancelChildSelection(){



        isSelectingParent = false;



        if (childRowPending) childRowPending.classList.remove('pending-child');



        childRowPending = null;



        document.getElementById('parent-selection-banner').classList.remove('show');



        document.body.classList.remove('selecting-mode');



        document.querySelectorAll('.eligible-target').forEach(r=> r.classList.remove('eligible-target'));



    }







    document.getElementById('fcRows').addEventListener('click', function(e){



        if (!isSelectingParent) return;



        if (suppressNextRowClick) { suppressNextRowClick = false; return; }



        if (e.target.closest('button, input, textarea, a, select')) return;







        const clickedRow = e.target.closest('.fc-row');



        if (!clickedRow || clickedRow === childRowPending) return;







        if (isChildRow(clickedRow)) {



            showToast(T('fc.ibf_no_figlio_su_figlio'), 'error');



            return;



        }







        const list = document.getElementById('fcRows');



        const block = getBlockRows(clickedRow);



        const insertAfter = block[block.length - 1];



        const pending = childRowPending;







        flip(list, ()=>{



            insertAfter.after(pending);



            pending.classList.add('is-child');



        });







        updateRowBadgeText(pending);



        const qArea = pending.querySelector('.q-desc');



        if (qArea) qArea.placeholder = T('fc.ibf_ph_sottodomanda');



        closeSwipe(pending);



        buzz(14);



        cancelChildSelection();



        updateGenerateButtonState();



        showToast(T('fc.ibf_agganciato'));



    });







    /* ============================================================



       MOTORE GESTURE — swipe orizzontale + riordino a pressione prolungata



       ============================================================ */



    (function(){



        const TAP_SLOP = 9;



        const SWIPE_SLOP = 15;



        const STILL_SLOP = 6;



        const LONG_PRESS_MS = 420;



        let pend = null;







        function eligibleRow(e){



            if (!e.target || !e.target.closest) return null;



            if (e.target.closest('textarea, input, select, button, audio, a')) return null;



            const row = e.target.closest('.fc-row');



            if (!row || !row.closest('#fcRows')) return null;



            return row;



        }







        document.addEventListener('pointerdown', function(e){



            if (activeDrag || isSelectingParent) return;



            const insideRow = e.target.closest && e.target.closest('.fc-row');



            document.querySelectorAll('#fcRows .fc-row').forEach(r=>{



                if (r._swipeX && r !== insideRow) closeSwipe(r);



            });



        }, true);







        document.addEventListener('pointerdown', function(e){



            if (isSelectingParent) return;



            if (pend || activeDrag) return;



            if (e.pointerType === 'mouse' && e.button !== 0) return;



            const row = eligibleRow(e);



            if (!row) return;



            const content = row.querySelector('.fc-row-content');



            pend = {



                row, content, pointerId: e.pointerId,



                startX: e.clientX, startY: e.clientY,



                lastX: e.clientX, lastY: e.clientY,



                swiping: false, reordering: false,



                longPressTimer: setTimeout(()=>{



                    // Attiva il riordino SOLO se il dito è rimasto davvero fermo:



                    // se nel frattempo si è mosso (anche solo per iniziare uno scroll



                    // lento), non dobbiamo rubare il gesto alla pagina.



                    if (!pend || pend.swiping) return;



                    const stillDx = Math.abs(pend.lastX - pend.startX);



                    const stillDy = Math.abs(pend.lastY - pend.startY);



                    if (stillDx < STILL_SLOP && stillDy < STILL_SLOP) activateReorder(pend);



                }, LONG_PRESS_MS)



            };



        });







        document.addEventListener('pointermove', function(e){



            if (!pend || e.pointerId !== pend.pointerId) return;



            pend.lastX = e.clientX; pend.lastY = e.clientY;



            if (pend.reordering) { doReorderMove(e); return; }



            if (pend.swiping) { doSwipeMove(e); return; }







            const dx = e.clientX - pend.startX, dy = e.clientY - pend.startY;



            const adx = Math.abs(dx), ady = Math.abs(dy);







            // Priorità allo scroll nativo: appena il movimento non è NETTAMENTE



            // orizzontale, molliamo subito il gesto e lasciamo scorrere la pagina.



            // Un dito vero non è mai perfettamente dritto: meglio richiedere uno



            // swipe deciso piuttosto che rubare scroll verticali per errore.



            if (ady > TAP_SLOP && adx < ady * 1.8) {



                clearTimeout(pend.longPressTimer);



                pend = null; // lascia proseguire lo scroll nativo della pagina



                return;



            }



            if (adx > SWIPE_SLOP && adx > ady * 1.8) {



                clearTimeout(pend.longPressTimer);



                pend.swiping = true;



                activateSwipeGesture(pend);



                doSwipeMove(e);



            }



        }, {passive:true});







        document.addEventListener('pointerup', function(e){ finishGesture(e, true); });



        document.addEventListener('pointercancel', function(e){ finishGesture(e, false); });







        function finishGesture(e, committed){



            if (!pend || e.pointerId !== pend.pointerId) return;



            const p = pend; pend = null;



            clearTimeout(p.longPressTimer);



            if (p.reordering) finalizeReorder(p);



            else if (p.swiping) finalizeSwipe(p, committed);



        }







        /* ---- SWIPE ORIZZONTALE ---- */



        function activateSwipeGesture(ctx){



            gestureBlock = true;



            closeAllSwipes(ctx.row);



            ctx.content.style.transition = 'none';



            ctx.maxReveal = ctx.row.querySelector('.swipe-actions').offsetWidth || 172;



            ctx.startTranslate = ctx.row._swipeX || 0;



            document.body.classList.add('no-select');



        }



        function doSwipeMove(e){



            const ctx = pend;



            let x = ctx.startTranslate + (e.clientX - ctx.startX);



            if (x < 0) x = x * 0.15;



            if (x > ctx.maxReveal) x = ctx.maxReveal; // stop netto: mai oltre i pulsanti rivelati



            ctx.content.style.transform = `translateX(${x}px)`;



            ctx.currentX = x;



        }



        function finalizeSwipe(ctx, committed){



            document.body.classList.remove('no-select');



            gestureBlock = false;



            ctx.content.style.transition = 'transform .34s var(--snap)';



            let target = 0;



            if (committed && ctx.currentX !== undefined) target = ctx.currentX > ctx.maxReveal * 0.4 ? ctx.maxReveal : 0;



            ctx.content.style.transform = `translateX(${target}px)`;



            ctx.row._swipeX = target;



            if (target > 0) { suppressNextRowClick = true; setTimeout(()=> suppressNextRowClick = false, 260); buzz(8); }



        }







        /* ---- RIORDINO A PRESSIONE PROLUNGATA ---- */



        function activateReorder(ctx){



            ctx.reordering = true;



            gestureBlock = true;



            buzz(12);



            document.body.classList.add('no-select');







            const row = ctx.row;



            const child = isChildRow(row);



            ctx.blockRows = child ? [row] : getBlockRows(row);



            ctx.scopeParent = child ? getBlockStart(row) : null;







            const list = document.getElementById('fcRows');



            const firstRect = ctx.blockRows[0].getBoundingClientRect();



            const lastRect = ctx.blockRows[ctx.blockRows.length - 1].getBoundingClientRect();



            const totalHeight = lastRect.bottom - firstRect.top;



            const referenceNode = ctx.blockRows[ctx.blockRows.length - 1].nextElementSibling;







            const placeholder = document.createElement('div');



            placeholder.className = 'fc-row-placeholder';



            placeholder.style.height = totalHeight + 'px';



            list.insertBefore(placeholder, referenceNode);



            ctx.placeholder = placeholder;







            const ghost = document.createElement('div');



            ghost.className = 'fc-drag-ghost';



            ghost.style.position = 'fixed';



            ghost.style.left = firstRect.left + 'px';



            ghost.style.top = firstRect.top + 'px';



            ghost.style.width = firstRect.width + 'px';



            ghost.style.zIndex = 9999;



            ghost.style.display = 'flex';



            ghost.style.flexDirection = 'column';



            ghost.style.gap = '14px';



            ctx.blockRows.forEach(r=>{ r.classList.add('is-dragging-row'); ghost.appendChild(r); });



            document.body.appendChild(ghost);



            ctx.ghost = ghost;



            activeDrag = ctx;



        }







        function doReorderMove(e){



            const ctx = pend;



            const dy = e.clientY - ctx.startY;



            ctx.ghost.style.transform = `translateY(${dy}px) scale(1.015)`;



            lastPointerY = e.clientY;



            ensureAutoScroll();



            updateReorderPlacement(ctx, e.clientY);



        }







        function scopeListFor(ctx){



            const list = document.getElementById('fcRows');



            if (ctx.scopeParent){



                const out = [];



                let cur = ctx.scopeParent.nextElementSibling;



                while (cur && (cur.classList.contains('is-child') || cur === ctx.placeholder)) {



                    out.push(cur);



                    cur = cur.nextElementSibling;



                }



                return out;



            }



            return Array.from(list.children).filter(el=> el === ctx.placeholder || !el.classList.contains('is-child'));



        }







        function nodeRect(el, ctx){



            if (el === ctx.placeholder) return el.getBoundingClientRect();



            if (!ctx.scopeParent && !el.classList.contains('is-child')){



                const rows = getBlockRows(el);



                const a = rows[0].getBoundingClientRect();



                const b = rows[rows.length - 1].getBoundingClientRect();



                return { top: a.top, bottom: b.bottom };



            }



            return el.getBoundingClientRect();



        }







        function updateReorderPlacement(ctx, pointerY){



            const list = document.getElementById('fcRows');



            const scope = scopeListFor(ctx);



            const idx = scope.indexOf(ctx.placeholder);



            if (idx < 0) return;







            if (idx > 0){



                const prev = scope[idx - 1];



                const r = nodeRect(prev, ctx);



                const mid = r.top + (r.bottom - r.top) / 2;



                if (pointerY < mid){



                    flip(list, ()=> list.insertBefore(ctx.placeholder, prev));



                    buzz(4);



                    return;



                }



            }



            if (idx < scope.length - 1){



                const next = scope[idx + 1];



                const r = nodeRect(next, ctx);



                const mid = r.top + (r.bottom - r.top) / 2;



                if (pointerY > mid){



                    const afterEl = (!ctx.scopeParent && !next.classList.contains('is-child'))



                        ? getBlockRows(next)[getBlockRows(next).length - 1].nextElementSibling



                        : next.nextElementSibling;



                    flip(list, ()=> list.insertBefore(ctx.placeholder, afterEl));



                    buzz(4);



                    return;



                }



            }



        }







        function finalizeReorder(ctx){



            stopAutoScroll();



            document.body.classList.remove('no-select');



            gestureBlock = false;



            activeDrag = null;







            const list = document.getElementById('fcRows');



            const placeholder = ctx.placeholder;



            flip(list, ()=>{



                ctx.blockRows.forEach(r=>{ r.classList.remove('is-dragging-row'); list.insertBefore(r, placeholder); });



                placeholder.remove();



            });



            ctx.ghost.remove();



            buzz(10);



        }







        /* ---- AUTO-SCROLL AI BORDI ---- */



        function ensureAutoScroll(){ if (!autoScrollRAF) autoScrollLoop(); }



        function autoScrollLoop(){



            const margin = 110, maxSpeed = 16;



            const vh = window.innerHeight;



            let dy = 0;



            if (lastPointerY < margin) dy = -maxSpeed * (1 - lastPointerY / margin);



            else if (lastPointerY > vh - margin) dy = maxSpeed * (1 - (vh - lastPointerY) / margin);



            if (dy && activeDrag) window.scrollBy(0, dy);



            autoScrollRAF = activeDrag ? requestAnimationFrame(autoScrollLoop) : null;



        }



        function stopAutoScroll(){ if (autoScrollRAF) { cancelAnimationFrame(autoScrollRAF); autoScrollRAF = null; } }







        document.addEventListener('touchmove', function(e){ if (gestureBlock) e.preventDefault(); }, {passive:false});



    })();







    /* ============================================================



       REGISTRAZIONE AUDIO (con timer live, tinta elegante)



       ============================================================ */



    let currentRecorder = null;



    let currentBtn = null;



    let currentChunks = [];



    let recTimerInterval = null;



    let recStartTime = 0;







    function startRecTimer(btn){



        recStartTime = Date.now();



        clearInterval(recTimerInterval);



        recTimerInterval = setInterval(()=>{



            const s = Math.floor((Date.now() - recStartTime) / 1000);



            const mm = Math.floor(s / 60), ss = String(s % 60).padStart(2, '0');



            const label = btn.querySelector('.rec-label');



            if (label) label.textContent = mm + ':' + ss;



        }, 500);



    }



    function stopRecTimer(){ clearInterval(recTimerInterval); recTimerInterval = null; }







    async function toggleRowRecord(btn, type){



        if (btn.classList.contains('recording')) {



            currentRecorder.stop();



            stopRecTimer();



            btn.classList.remove('recording');



            const label = btn.querySelector('.rec-label');



            if (label) label.textContent = 'Registra';



            return;



        }







        if (currentRecorder) {



            showToast(T('fc.ibf_ferma_registrazione'), 'error');



            return;



        }







        try {



            const stream = await navigator.mediaDevices.getUserMedia({ audio: true });



            currentRecorder = new MediaRecorder(stream);



            currentChunks = [];



            currentBtn = btn;







            currentRecorder.ondataavailable = e=>{ if (e.data.size > 0) currentChunks.push(e.data); };







            currentRecorder.onstop = () => {



                const row = currentBtn.closest('.fc-row');



                const mimeType = currentRecorder.mimeType || 'audio/webm';



                const blob = new Blob(currentChunks, { type: mimeType });



                const ext = mimeType.includes('mp4') ? 'm4a' : 'webm';



                const player = row.querySelector('.audio-player-' + type);







                if (player) {



                    player.src = URL.createObjectURL(blob);



                    player.classList.add('has-audio');



                }



                if (type === 'q') { row.qAudioBlob = blob; row.qAudioExt = ext; }



                else { row.aAudioBlob = blob; row.aAudioExt = ext; }







                stream.getTracks().forEach(t=> t.stop());



                currentRecorder = null;



            };







            currentRecorder.start();



            btn.classList.add('recording');



            const label = btn.querySelector('.rec-label');



            if (label) label.textContent = '0:00';



            startRecTimer(btn);



            buzz(10);



        } catch (err) {



            showToast(T('fc.ifc_no_microfono'), 'error');



        }



    }







    /* ============================================================



       INVIO AJAX



       ============================================================ */



    function restoreSubmitLabel(){ return ICON_CHECK + ' Salva Tutto nel Database'; }







    document.getElementById('imageForm').addEventListener('submit', async function(e){



        e.preventDefault();

        

        // Riversa l'HTML dagli editor agli input nascosti prima di leggere i campi

        if (typeof fcSyncAllEditors === 'function') fcSyncAllEditors();







        const catIdInput = document.querySelector('input[name="category_id"]').value;



        if (catIdInput === '') { showToast(T('fc.ibf_serve_categoria'), 'error'); return; }







        const rows = document.querySelectorAll('#fcRows .fc-row.data-row');



        if (rows.length === 0) { showToast(T('fc.ibf_serve_riga'), 'error'); return; }







        const subBtn = document.getElementById('submitBtn');



        subBtn.disabled = true;



        subBtn.innerHTML = '<span class="spinner"></span> Salvataggio in corso…';







        const fd = new FormData();



        fd.append('action', 'insert_bulk');



        fd.append('category_id', catIdInput);







        const qFiles = document.getElementById('question_images').files;



        for (let i=0; i<qFiles.length; i++) fd.append('question_images[]', qFiles[i]);



        const aFiles = document.getElementById('answer_images').files;



        for (let i=0; i<aFiles.length; i++) fd.append('answer_images[]', aFiles[i]);







        rows.forEach((row, index)=>{



            const isChild = row.classList.contains('is-child') ? 1 : 0;



            fd.append('is_child[' + index + ']', isChild);



            // Recuperiamo l'ID univoco della riga (es. 123456789) impostato dal sistema
            const rid = row.dataset.rid; 
            
            // 1. Puntiamo all'elemento esatto usando l'ID, che sia un <input> o una <textarea>
            const qDescEl = document.getElementById('q-desc-' + rid);
            const aDescEl = document.getElementById('a-desc-' + rid);
            
            const qImgNameEl = row.querySelector('.q-img-name');
            const aImgNameEl = row.querySelector('.a-img-name');

            // 2. Estraiamo il valore. 
            // Aggiungiamo un fallback: se il campo nascosto è vuoto, proviamo a leggere 
            // il testo direttamente dal div dell'editor visibile (il cui ID termina con _editor)
            let qVal = qDescEl ? qDescEl.value : '';
            if (!qVal && document.getElementById('q-desc-' + rid + '_editor')) {
                const qEd = document.getElementById('q-desc-' + rid + '_editor');
                qVal = qEd.value || qEd.innerText || '';
            }
            
            let aVal = aDescEl ? aDescEl.value : '';
            if (!aVal && document.getElementById('a-desc-' + rid + '_editor')) {
                const aEd = document.getElementById('a-desc-' + rid + '_editor');
                aVal = aEd.value || aEd.innerText || '';
            }

            // Aggiungiamo i dati corretti al payload da inviare al PHP
            fd.append('q_desc[]', qVal);
            fd.append('a_desc[]', aVal);
            fd.append('q_img_name[]', qImgNameEl ? qImgNameEl.value : '');
            fd.append('a_img_name[]', aImgNameEl ? aImgNameEl.value : '');



        });







        try {



            const res = await fetch('inserimento_big_fc.php', { method: 'POST', body: fd });



            const data = await res.json();







            if (data.success) {



                document.getElementById('imageForm').style.display = 'none';



                document.getElementById('success-msg').style.display = 'block';



                document.getElementById('success-count').textContent = data.count;



                window.scrollTo({ top: 0, behavior: 'smooth' });



                setTimeout(()=>{ window.location.href = 'visualizzazione_fc.php?category_id=' + data.category_id; }, 2000);



            } else {



                showToast(T('fc.ibf_errore_salvataggio') + (data.error || ''), 'error');



                subBtn.disabled = false;



                subBtn.innerHTML = restoreSubmitLabel();



            }



        } catch (err) {



            showToast(T('fc.ibf_errore_rete'), 'error');



            subBtn.disabled = false;



            subBtn.innerHTML = restoreSubmitLabel();



        }



    });







    updateGenerateButtonState();



</script>




</body>



</html>

