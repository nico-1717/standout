<?php
    require_once __DIR__ . '/../../lib/config.php';
    require_once __DIR__ . '/../../lib/auth_check.php';
    require_once __DIR__ . '/fc_examples_box.php'; // render_examples_section(), condivisa con fc_view.php
    $current_user_id = (int)$utente_corrente['id'];

    /* sec_db() DOPO auth_check: e' quest'ultimo a caricare security_core.php. */
    $conn = sec_db();

    /* Studio o lingua: con ?course=N questo modulo e' quello di una parola,
       con campi semplici (parola, traduzione, significato, immagine) al posto
       dei due editor ricchi. Vedi fc_type.php. */
    require_once __DIR__ . '/../../lib/fc/fc_type.php';
    fc_avvia($current_user_id);

    /* PERCHE' QUESTA PAGINA SI PUO' TENERE IN MEMORIA PER CINQUE SECONDI
       fc_view.php comincia a scaricarla appena il dito tocca una
       carta, cosi' quando il popup si apre e' gia' arrivata. Ma session_start()
       manda per default "Cache-Control: no-store": il browser scarterebbe
       quello che ha appena preso e ricomincerebbe da capo all'apertura,
       rendendo il precaricamento una richiesta in piu' e nient'altro.

       Cinque secondi, e solo per l'apertura dentro un popup. Sono tanti per
       coprire il tocco (millisecondi) e il passaggio del mouse (un secondo
       scarso), e pochi perche' una versione vecchia possa arrivare a schermo:
       per vederla bisognerebbe salvare una carta e riaprirla entro cinque
       secondi, e in mezzo c'e' comunque il ricaricamento dell'elenco.
       private: e' roba di una persona sola, nessun intermediario la tenga.
       Le POST non passano di qui: piu' sotto la pagina esce prima. */
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && !empty($_GET['fc_popup'])) {
        header('Cache-Control: private, max-age=5');
        header_remove('Pragma');
        header_remove('Expires');
    }

    /* Rete di sicurezza, come in fc_ai.php/ai_example.php/fc_insert_card.php —
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
    $categorie_colore = array();   /* serve al pulsante dell'inserimento multiplo, che si intona alla materia */
    [$fcW, $fcP] = fc_filtro_corso();
    foreach (sec_all("SELECT id, name, color FROM " . fc_t('decks') . " WHERE user_id = ?" . $fcW . " ORDER BY name",
                     array_merge([$current_user_id], $fcP)) as $row) {
        $categories[$row['id']] = $row['name'];
        $categorie_colore[$row['id']] = trim((string)$row['color']) !== '' ? trim((string)$row['color']) : '#AF52DE';
    }

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
        $dest = rtrim($cartella, '/') . '/' . $nome;
        if (!move_uploaded_file($ris['tmp'], $dest)) return '';
        // vedi fc_image_sizes.php: si misura una volta, alla scrittura
        if (!$audio) fc_misura_registra($nome, $dest);
        return $nome;
    }

    /* Una voce del dizionario che potrebbe non esserci ancora: t() restituisce
       la chiave fra doppie parentesi angolari quando non la trova, e
       «<<fc.mfc_esempio_breve>>» stampato su un tasto e' peggio di una parola
       imprecisa. Appena la voce entra nel dizionario questo ripiego smette da
       solo di servire, senza che nessuno debba tornare qui. Stesso schema gia'
       usato da pomodoro_barra.php. */
    function mfc_t(string $chiave, string $ripiego): string {
        $v = function_exists('t') ? (string)t($chiave) : '';
        return ($v === '' || strpos($v, '<<') === 0) ? $ripiego : $v;
    }

    /* La parola "Esempio" da sola, nella lingua del sito. La voce dedicata
       adesso c'e' in it.json e in en.json (fc.mfc_esempio_breve), quindi qui
       non serve piu' nessuna astuzia: si chiede e si stampa.
       Il ripiego sulla dicitura lunga resta per l'unico caso che puo' ancora
       capitare — un dizionario di un'altra lingua, aggiunto domani, che quella
       voce non ce l'ha: meglio "Aggiungi esempio" che «<<fc.mfc_esempio_breve>>»
       stampato su un tasto. */
    /* Le prime parole di un campo, in chiaro. Il testo delle carte e' un documento
   JSON: stamparlo cosi' com'e' vorrebbe dire mostrare le parentesi graffe, e
   disegnarlo per bene richiederebbe di tirare dentro il parser e il suo giro
   di rendering per una riga di anteprima. Qui bastano le parole: si scendono i
   blocchi e si prendono i "run".
   Un formato che non si legge come JSON viene dal vecchio testo semplice, e li'
   basta togliere i tag. */
function mfc_testo_piano($raw, int $max = 90): string {
    $raw = trim((string)$raw);
    if ($raw === '') return '';

    $doc = json_decode($raw, true);
    if (!is_array($doc) || !isset($doc['blocks']) || !is_array($doc['blocks'])) {
        $piano = trim(preg_replace('/\s+/u', ' ', strip_tags($raw)));
    } else {
        $pezzi = [];
        foreach ($doc['blocks'] as $b) {
            if (!is_array($b)) continue;
            foreach (($b['runs'] ?? []) as $r) {
                if (is_array($r)) $pezzi[] = (string)($r['s'] ?? '');
                elseif (is_string($r)) $pezzi[] = $r;
            }
        }
        $piano = trim(preg_replace('/\s+/u', ' ', implode(' ', $pezzi)));
    }

    if ($piano === '') return '';
    if (function_exists('mb_strlen') && mb_strlen($piano) > $max) {
        return mb_substr($piano, 0, $max - 1) . '…';
    }
    return $piano;
}

function mfc_parola_esempio(): string {
        return mfc_t('fc.mfc_esempio_breve', (string)t('fc.mfc_aggiungi_esempio'));
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
       correggere ogni cosa due volte. fc_insert_card.php resta come
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
        if ($catID <= 0 || !fc_mazzo_mio($catID, $current_user_id)) {
            echo json_encode(['success' => false, 'error' => t('fc.categoria_non_valida')]);
            exit;
        }

        /* La parola: testo semplice, un'immagine sola, niente audio (si
           ascolta con la sintesi vocale). */
        if (fc_is_vocab()) {
            $parola = sec_str($_POST['word'] ?? '', 255);
            $trad   = sec_str($_POST['translation'] ?? '', 500);
            $sign   = sec_str($_POST['meaning'] ?? '', 400);
            $img    = mfc_salva('qNew_image', IMAGE_PATH_INSERT, false);
            if ($parola === '' && $trad === '' && $img === '') {
                echo json_encode(['success' => false, 'empty' => true, 'error' => t('fc.serve_qualcosa')]);
                exit;
            }
            $max_o = (int)(sec_one("SELECT MAX(custom_order) AS m FROM " . fc_t('cards') . " WHERE category_id = ?",
                                   [$catID])['m'] ?? 0) + 1;
            sec_q("INSERT INTO " . fc_t('cards') . " (image, word, translation, meaning, category_id, is_father, custom_order)
                   VALUES (?, ?, ?, ?, ?, 0, ?)",
                  [$img !== '' ? $img : null, $parola, $trad, $sign !== '' ? $sign : null, $catID, $max_o]);
            echo json_encode(['success' => true, 'fc_id' => sec_insert_id(), 'category_id' => $catID]);
            exit;
        }

        $quest = sec_text($_POST['quest'] ?? '', 20000);
        $answ  = sec_text($_POST['answ'] ?? '', 20000);

        $q_img = mfc_salva('qNew_image', FC_IMAGE_PATH_INSERT, false);
        $a_img = mfc_salva('aNew_image', FC_IMAGE_PATH_INSERT, false);
        $q_aud = mfc_salva('audio_q', STANDOUT_DATA_DIR . 'flashcard_audio', true);
        $a_aud = mfc_salva('audio_a', STANDOUT_DATA_DIR . 'flashcard_audio', true);

        if ($quest === '' && $answ === '' && $q_img === '' && $a_img === '' && $q_aud === '' && $a_aud === '') {
            echo json_encode(['success' => false, 'empty' => true, 'error' => t('fc.serve_qualcosa')]);
            exit;
        }

        $max_o = (int)(sec_one("SELECT MAX(custom_order) AS m FROM " . fc_t('cards') . " WHERE category_id = ?",
                               [$catID])['m'] ?? 0) + 1;

        sec_q("INSERT INTO " . fc_t('cards') . " (question_image, answer_image, question_desc, answer_desc, category_id, question_audio, answer_audio, custom_order)
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

        /* category_id arriva dal selettore "Plug & Play" (fc_deck_selector.php),
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
            $check_row = sec_one("SELECT c.user_id FROM " . fc_t('subcards') . " ch
                        JOIN " . fc_t('cards') . " i ON ch.parent_id = i.id
                        JOIN " . fc_t('decks') . " c ON i.category_id = c.id
                        WHERE ch.id_child = ?", [$id]);
        } else {
            $check_row = sec_one("SELECT c.user_id FROM " . fc_t('cards') . " i
                        JOIN " . fc_t('decks') . " c ON i.category_id = c.id
                        WHERE i.id = ?", [$id]);
        }
        if (!$check_row || (int)$check_row['user_id'] !== $current_user_id) {
            die(sec_h(t('fc.non_proprietario')));
        }

        // Se cambia categoria, anche la nuova dev'essere sua
        if (!$is_child && $catID > 0 && !fc_mazzo_mio($catID, $current_user_id)) {
            die(sec_h(t('fc.non_proprietario_categoria')));
        }

        // Stato attuale dei file allegati
        $old = $is_child
            ? sec_one("SELECT * FROM " . fc_t('subcards') . " WHERE id_child = ?", [$id])
            : sec_one("SELECT * FROM " . fc_t('cards') . " WHERE id = ?", [$id]);
        if (!$old) { die(sec_h(t('fc.scheda_non_trovata'))); }

        /* Dove tornare dopo. I valori arrivano dal modulo, quindi si validano:
           prima finivano dentro una stringa JavaScript, e un apice in game_id
           bastava a chiuderla e a scrivere codice nella pagina. */
        $return_to_game = sec_bool($_POST['return_to_game'] ?? 0);
        if ($return_to_game) {
            $redirect_url = 'fc_game.php?game_id=' . sec_int($_POST['game_id'] ?? 0, 1)
                . '&position=' . sec_int($_POST['position'] ?? 1, 1)
                . '&status=' . urlencode(sec_enum($_POST['status'] ?? null, ['all', '1', '0'], '0'))
                . '&order=' . urlencode(sec_enum($_POST['order'] ?? null, ['in_order', 'random'], 'random'));
        } else {
            $redirect_url = fc_url('fc_view.php', ['category_id' => (int)$catID]);
        }

        /* LA PAROLA: stessi passi (elimina oppure salva), con le sue colonne. */
        if (fc_is_vocab()) {
            if (isset($_POST['delete'])) {
                mfc_togli(IMAGE_PATH_INSERT, $old['image'] ?? '');
                if ($is_child) sec_q("DELETE FROM " . fc_t('subcards') . " WHERE id_child = ?", [$id]);
                else           sec_q("DELETE FROM " . fc_t('cards') . " WHERE id = ?", [$id]);
                header('Location: ' . $redirect_url);
                exit;
            }
            $sets = ['word = ?', 'translation = ?', 'meaning = ?'];
            $vals = [sec_str($_POST['word'] ?? '', 255), sec_str($_POST['translation'] ?? '', 500)];
            $sign = sec_str($_POST['meaning'] ?? '', 400);
            $vals[] = $sign !== '' ? $sign : null;
            if (sec_bool($_POST['remove_q_img'] ?? 0)) {
                mfc_togli(IMAGE_PATH_INSERT, $old['image'] ?? '');
                $sets[] = 'image = ?'; $vals[] = null;
            } else {
                $nuova = mfc_salva('qNew_image', IMAGE_PATH_INSERT, false);
                if ($nuova !== '') {
                    mfc_togli(IMAGE_PATH_INSERT, $old['image'] ?? '');
                    $sets[] = 'image = ?'; $vals[] = $nuova;
                }
            }
            if (!$is_child) { $sets[] = 'category_id = ?'; $vals[] = $catID; }
            if ($is_child) sec_q("UPDATE " . fc_t('subcards') . " SET " . implode(', ', $sets) . " WHERE id_child = ?", array_merge($vals, [$id]));
            else           sec_q("UPDATE " . fc_t('cards') . " SET " . implode(', ', $sets) . " WHERE id = ?", array_merge($vals, [$id]));
            if (!$return_to_game) $redirect_url .= ($is_child ? '&fc_focus_child=' : '&fc_focus=') . (int)$id;
            header('Location: ' . $redirect_url);
            exit;
        }

        // AZIONE ELIMINA
        if (isset($_POST['delete'])) {
            mfc_togli(FC_IMAGE_PATH_INSERT, $old['question_image'] ?? '');
            mfc_togli(FC_IMAGE_PATH_INSERT, $old['answer_image'] ?? '');
            mfc_togli(STANDOUT_DATA_DIR . 'flashcard_audio', $old['question_audio'] ?? '');
            mfc_togli(STANDOUT_DATA_DIR . 'flashcard_audio', $old['answer_audio'] ?? '');

            if ($is_child) sec_q("DELETE FROM " . fc_t('subcards') . " WHERE id_child = ?", [$id]);
            else           sec_q("DELETE FROM " . fc_t('cards') . " WHERE id = ?", [$id]);

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
            ['question_audio', 'remove_q_aud', 'audio_q',    STANDOUT_DATA_DIR . 'flashcard_audio', true],
            ['answer_audio',   'remove_a_aud', 'audio_a',    STANDOUT_DATA_DIR . 'flashcard_audio', true],
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
            sec_q("UPDATE " . fc_t('subcards') . " SET " . implode(', ', $sets) . " WHERE id_child = ?", array_merge($vals, [$id]));
        } else {
            sec_q("UPDATE " . fc_t('cards') . " SET " . implode(', ', $sets) . " WHERE id = ?", array_merge($vals, [$id]));
        }

        /* Si torna sulla carta appena salvata, non in cima all'elenco:
           fc_view.php legge fc_focus / fc_focus_child e ci scorre
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
<?php /* Senza questa riga, il primo disegno di una pagina dentro un iframe e'
         BIANCO, comunque sia il tema: il browser non sa ancora che sa stare
         anche in scuro, e il bianco e' il suo colore di partenza. Su tema scuro
         diventa un rettangolo bianco a tutto schermo che lampeggia all'apertura
         del popup — la cosa che dava piu' fastidio nella registrazione.
         color-scheme glielo dice prima ancora di leggere il foglio di stile. */ ?>
    <meta name="color-scheme" content="light dark">
<script>
/* 'unload' NON SI USA PIU', E IL BROWSER LO DICE
   ------------------------------------------------------------------
   E' l'errore rosso nello screenshot: "Permissions policy violation:
   unload is not allowed in this document", con jquery.min.js come
   colpevole apparente. jQuery e' solo il messaggero: la riga indicata e'
   il suo addEventListener interno, chi ha chiesto 'unload' e' il codice
   che ha scritto $(window).on('unload', ...) o .unload(...).

   Chrome ed Edge stanno ritirando 'unload' (rompe la cache avanti/indietro)
   e lo governano con una Permissions-Policy: dove non e' concesso — e in un
   documento dentro un iframe di norma non lo e' — registrarlo non fa niente
   e lascia in console quella violazione. Quindi non e' che l'evento arriva
   tardi o male: non arriva proprio, e chiunque contasse su di lui per
   salvare qualcosa in uscita sta gia' perdendo quel giro, in silenzio.

   Il sostituto ufficiale e' 'pagehide': scatta negli stessi momenti di
   'unload' e in piu' quando la pagina finisce nella cache avanti/indietro,
   dove 'unload' non scattava. Qui si sostituisce alla fonte, su window: chi
   chiede 'unload' riceve 'pagehide' senza accorgersene, la violazione non
   ha piu' modo di prodursi e il gestore, per la prima volta, viene davvero
   chiamato.

   Sta come prima cosa nel <head> apposta: piu' avanti nella pagina
   arrivano gli script che config.php innesta da se' (fra cui jQuery), e un
   rattoppo che arriva dopo chi deve rattoppare non serve a niente.

   Questo copre il documento del popup. Se la violazione compare anche
   caricando una pagina normale, il legame vero e' altrove: si trova con
   grep -rn "unload" sui file del sito e si sistema li', che e' meglio. */
(function () {
    if (!window.addEventListener) return;
    var nativo = window.addEventListener;
    window.addEventListener = function (tipo, fn, opz) {
        return nativo.call(this, tipo === 'unload' ? 'pagehide' : tipo, fn, opz);
    };
    var toglieva = window.removeEventListener;
    window.removeEventListener = function (tipo, fn, opz) {
        return toglieva.call(this, tipo === 'unload' ? 'pagehide' : tipo, fn, opz);
    };
})();
</script>
    <title><?php te('fc.mfc_titolo'); ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="theme-color" content="#F2F2F7" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#000000" media="(prefers-color-scheme: dark)">

    <?php echo standout_i18n_json(['comune', 'fc', 'vocab', 'fcv']); ?>

<meta name="csrf-token" content="<?php echo sec_h(sec_csrf_token()); ?>">
<?php /* IL ?v= NON E' UN VEZZO — E' LA CAUSA DEL PULSANTE IA MORTO.
         Questi file venivano serviti con un indirizzo sempre uguale, quindi
         il browser continuava a usare la copia che aveva in cache. Sul disco
         c'era la versione nuova di fc_improve_card.js, quella che apre il
         popup; in memoria ne girava una vecchia, di quando il riquadro era
         una sezione in linea e non un popup — e quella faceva due cose:
         nascondeva il pulsante (per questo spariva al tocco) e mostrava
         #fcMiglioraBox, che pero' sta dentro #fcMiglioraOverlay, rimasto
         hidden. Risultato: il pulsante sparisce, non compare niente, nessun
         errore. Nessuna delle mie ipotesi poteva essere giusta, perche'
         leggevo un file che il browser non stava eseguendo.
         Con la data del file nell'indirizzo, una modifica al file e' un
         indirizzo nuovo, e la cache non ha piu' niente da riusare. */ ?>
<script src="<?php echo PWA_BASE; ?>_assets/js/standout_fetch.js?v=<?php echo @filemtime(__DIR__ . '/../_assets/js/standout_fetch.js') ?: 1; ?>"></script>
<?php echo fc_boot_json(); ?>
<?php if (fc_is_vocab()): /* le parole sono testo semplice: niente fc_parser, niente formule */ ?>
<script src="<?php echo PWA_BASE; ?>fc/fc_speak.js?v=<?php echo @filemtime(__DIR__ . '/fc_speak.js') ?: 1; ?>"></script>
<?php else: ?>
<script src="<?php echo PWA_BASE; ?>fc/fc_parser.js?v=<?php echo @filemtime(__DIR__ . '/fc_parser.js') ?: 1; ?>"></script>
<?php endif; ?>

<?php /* La configurazione di MathJax non e' piu' qui: la scrive fc_parser.js,
         caricato apposta la riga sopra questa. E' lui a produrre le formule,
         quindi e' lui a sapere cosa MathJax deve saper leggere — e una copia
         sola non puo' allontanarsi da un'altra, come era gia' successo con
         \cancel, che qui c'era e in fc_view.php no.

         ATTENZIONE A UNA DIFFERENZA CHE HO DOVUTO SCIOGLIERE: qui i dollari
         erano delimitatori di formula ($...$), in fc_view.php no.
         Ho tenuto la versione senza, che e' quella per cui il formato nuovo e'
         stato fatto: la formattazione non sta piu' dentro il testo, il LaTeX
         arriva gia' racchiuso in \( \), e col dollaro attivo una frase come
         "costa 5$ e 3$" diventa una formula in corsivo matematico. Se pero'
         qui dentro qualcuno scrive formule col dollaro a mano, quelle
         smetteranno di essere riconosciute: e' l'unico cambiamento di
         comportamento di questo accorpamento. */ ?>
    <?php if (!fc_is_vocab()): ?>
    <script id="MathJax-script" async src="https://cdn.jsdelivr.net/npm/mathjax@3/es5/tex-mml-chtml.js"></script>
    <?php endif; ?>
    <script src="<?php echo PWA_BASE; ?>fc/fc_audio_recorder.js?v=<?php echo @filemtime(__DIR__ . '/fc_audio_recorder.js') ?: 1; ?>"></script>
    <script src="<?php echo PWA_BASE; ?>fc/fc_image_editor.js?v=<?php echo @filemtime(__DIR__ . '/fc_image_editor.js') ?: 1; ?>"></script>
    <script src="<?php echo PWA_BASE; ?>fc/fc_examples_modal.js?v=<?php echo @filemtime(__DIR__ . '/fc_examples_modal.js') ?: 1; ?>"></script>
    <?php if (!fc_is_vocab()): ?><script src="<?php echo PWA_BASE; ?>fc/fc_improve_card.js?v=<?php echo @filemtime(__DIR__ . '/fc_improve_card.js') ?: 1; ?>"></script><?php endif; ?>

<?php /* Il ?v= non e' un vezzo: questo foglio decide DOVE sta il cerchio
         dell'IA, e finche' l'indirizzo restava identico il browser continuava
         a servire la copia che aveva in cache — si correggeva la posizione e
         non cambiava niente, il che rende impossibile capire se la correzione
         era sbagliata o solo non arrivata. Con la data del file nell'indirizzo,
         una modifica al foglio e' un indirizzo nuovo. Stesso schema gia' usato
         qui sotto per fc_card_edit.css. */ ?>
<link rel="stylesheet" href="<?php echo PWA_BASE; ?>fc/fc_ai_fab.css?v=<?php echo @filemtime(__DIR__ . '/fc_ai_fab.css') ?: 1; ?>">
<link rel="stylesheet" href="<?php echo PWA_BASE; ?>fc/fc_autosave_bar.css?v=<?php echo @filemtime(__DIR__ . '/fc_autosave_bar.css') ?: 1; ?>">
<link rel="stylesheet" href="<?php echo PWA_BASE; ?>fc/fc_examples_box.css?v=<?php echo @filemtime(__DIR__ . '/fc_examples_box.css') ?: 1; ?>">
<link rel="stylesheet" href="<?php echo PWA_BASE; ?>fc/fc_improve_card.css?v=<?php echo @filemtime(__DIR__ . '/fc_improve_card.css') ?: 1; ?>">
<?php /* I riquadri immagine/audio non stanno piu' dentro il foglio di questa
         pagina: sono gli stessi che usa il modulo dell'esempio, e vivono in
         fc_media_box.css. Va caricato PRIMA di fc_card_edit.css, cosi'
         se un giorno questa pagina volesse ritoccarne uno puo' farlo da li'
         senza combattere con l'ordine. */ ?>
<link rel="stylesheet" href="<?php echo PWA_BASE; ?>fc/fc_media_box.css?v=<?php echo @filemtime(__DIR__ . '/fc_media_box.css') ?: 1; ?>">
<link rel="stylesheet" href="<?php echo PWA_BASE; ?>fc/fc_card_edit.css?v=<?php echo @filemtime(__DIR__ . '/fc_card_edit.css') ?: 1; ?>">
</head>
<body class="<?php echo fc_is_vocab() ? 'fc-vocab' : 'fc-study'; ?>">

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
    // fc_image_editor.php, condiviso con fc_insert_card.php.
    require_once __DIR__ . '/fc_image_editor.php';
    require_once __DIR__ . '/fc_improve_card.php';

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
                    <audio src="../media/flashcard_audio/<?php echo sec_h($audioFile); ?>" controls></audio>
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
            'word' => '', 'translation' => '', 'meaning' => '', 'image' => '',
            'question_desc' => '', 'answer_desc' => '',
            'question_image' => '', 'answer_image' => '',
            'question_audio' => '', 'answer_audio' => '',
            'category_id' => $prev_cat,
        ];
        $cancel_url = fc_url('fc_view.php', $prev_cat > 0 ? ['category_id' => $prev_cat] : []);
    }

    if ($fc_modo === 'insert' || isset($_GET['fc_id']) || isset($_GET['child_id'])) {

        if ($fc_modo === 'edit') {
            $is_child = isset($_GET['child_id']);
            $id = $is_child ? sec_int($_GET['child_id'] ?? 0, 1) : sec_int($_GET['fc_id'] ?? 0, 1);
            $prev_cat = sec_int($_GET['previous_catid'] ?? 0, 0);

            // --- Il record si carica SOLO se appartiene all'utente ---
            if ($is_child) {
                $row = sec_one("SELECT t.* FROM " . fc_t('subcards') . " t
                              JOIN " . fc_t('cards') . " i ON t.parent_id = i.id
                              JOIN " . fc_t('decks') . " c ON i.category_id = c.id
                              WHERE t.id_child = ? AND c.user_id = ?", [$id, $current_user_id]);
            } else {
                $row = sec_one("SELECT t.* FROM " . fc_t('cards') . " t
                              JOIN " . fc_t('decks') . " c ON t.category_id = c.id
                              WHERE t.id = ? AND c.user_id = ?", [$id, $current_user_id]);
            }
            // -----------------------------------------------------------------------
        }

        if ($row) {
            if ($fc_modo === 'edit') {
                $category_id = $is_child ? $prev_cat : $row['category_id'];
                /* Le sottocarte di questa carta, per la sezione in fondo. Solo
                   quello che serve a una riga di anteprima: chiedere anche
                   audio e immagini vorrebbe dire portarsi dietro dei dati che
                   nessuno guarda. */
                $figli = $is_child ? [] : sec_all(
                    "SELECT id_child, " . (fc_is_vocab() ? 'word AS question_desc, translation AS answer_desc' : 'question_desc, answer_desc') . "
                       FROM " . fc_t('subcards') . " WHERE parent_id = ?
                      ORDER BY custom_order ASC, id_child ASC", [$id]);

                $ex_col = $is_child ? 'child_id' : 'parent_id';
                $ex_count = (int)(sec_one("SELECT COUNT(*) AS c FROM " . fc_t('examples') . " WHERE $ex_col = ?", [$id])['c'] ?? 0);
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
                ? "fc_game.php?game_id=" . sec_int($_GET['game_id'] ?? 0, 1)
                    . "&position=" . sec_int($_GET['position'] ?? 1, 1)
                    . "&status=" . urlencode(sec_enum($_GET['status'] ?? null, ['all', '1', '0'], '0'))
                    . "&order=" . urlencode(sec_enum($_GET['order'] ?? null, ['in_order', 'random'], 'random'))
                : fc_url('fc_view.php', ['category_id' => $category_id])
                  . ($is_child ? "&fc_focus_child=" : "&fc_focus=") . (int)$id;
            ?>

            <?php /* Niente app-header: diceva le stesse cose della barra qui
                     sotto, e in un popup significava due fasce sovrapposte
                     (si vedeva nello screenshot: "EDIT CARD / Question &
                     Answer" e subito sotto "Cancel / NEW CARD / Create").
                     Ne resta una sola, quella condivisa col pannello esempi. */ ?>

            <div class="page fc-page-rel">
            <?php /* Fuori dalle sezioni: il pulsante e' in sovraimpressione sulla
                     pagina, non un elemento del modulo. */ if (!fc_is_vocab()) fc_migliora_carta_blocco(); ?>
            <form id="editFcForm" method="POST" enctype="multipart/form-data">
                <?php /* Stessa identica barra del pannello esempi: stesse classi
                         (.exm-form-topbar), stessa disposizione, stessi SVG e
                         stessa logica di salvataggio — il pulsante verde con
                         l'interruttore "salvataggio automatico" e' fc_autosave_bar.php,
                         lo stesso componente, qui con un'istanza propria ('scheda')
                         perche' il suo sporco e' indipendente da quello del
                         pannello esempi che puo' aprirsi sopra. */ ?>
                <div class="exm-form-topbar">
                    <?php /* Non piu' una scritta sola su una fascia bianca: un segno
                             grafico, un occhiello piccolo e il nome sotto — la
                             stessa gerarchia che l'intestazione della pagina aveva
                             prima di essere tolta, ma dentro la barra. */ ?>
                    <span class="fc-tb-mark" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3v4a1 1 0 0 0 1 1h4"/><path d="M17 21H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h7l5 5v11a2 2 0 0 1-2 2Z"/></svg>
                    </span>
                    <span class="exm-form-title fc-tb-testo">
                        <span class="fc-tb-occhiello"><?php echo fc_is_vocab() ? sec_h(fc_lingua_nome(fc_corso()['learn_lang'], false)) : sec_h(t('fc.ifc_domanda_risposta')); ?></span>
                        <span class="fc-tb-nome" id="fcTopbarTitle"><?php echo $fc_modo === 'insert' ? sec_h(t('fc.ifc_nuova_scheda')) : sec_h(t('fc.mfc_titolo_breve')); ?></span>
                    </span>
                    <div class="exm-form-actions">
                        <?php if ($fc_modo === 'edit'): ?>
                        <button type="submit" name="delete" value="1" class="exm-icon-btn exm-icon-btn-danger" onclick="return confirm('<?php echo sec_h(t('vocab.mi_elimina_def')); ?>?');" aria-label="<?php te('vocab.mi_elimina_def'); ?>">
                            <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h16M9 7V5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2m2 0-1 13a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2L6 7"/></svg>
                        </button>
                        <?php endif; ?>
                        <?php /* Aggiungere un esempio e' un'azione della carta, come
                                 salvarla o eliminarla: sta qui, non sepolta in fondo
                                 al modulo. In inserimento parte disabilitato — un
                                 esempio ha bisogno di una carta che esista gia'. */ ?>
                        <?php /* Non piu' un'icona sola: una pastiglia col testo, dello
                                 stesso viola della famiglia IA/esempi — un "+" senza
                                 scritta non diceva cosa avrebbe aggiunto. */ ?>
                        <?php /* Il numero fra parentesi non e' una decorazione: senza,
                                 il tasto dice sempre la stessa cosa a una carta nuda e
                                 a una che ha gia' quattro esempi, e per sapere quale
                                 delle due si ha davanti bisogna aprirlo. Parte dal
                                 conteggio letto dal database qui sopra ($ex_count) e
                                 poi lo tiene aggiornato il JS, che e' l'unico a sapere
                                 cosa succede nel pannello degli esempi. */ ?>
                        <?php /* "Esempio" e basta, non piu' "Aggiungi esempio": il verbo
                                 diceva anche una cosa non piu' vera, perche' il tasto
                                 ora apre l'elenco. In una barra dove convivono cestino,
                                 salvataggio, interruttore e chiusura, due parole in meno
                                 sono due parole di spazio guadagnate.
                                 mfc_t() prende la voce breve dal dizionario se c'e', e
                                 finche' non c'e' ripiega su quella lunga: meglio una
                                 parola in piu' che «<<fc.mfc_esempio_breve>>» stampato
                                 su un tasto. L'aria-label resta quello disteso — a chi
                                 ascolta la pagina lo spazio non manca. */ ?>
                        <button type="button" class="fc-btn-esempio" id="fcAddEsempio" onclick="fcApriEsempi()"
                                <?php echo $fc_modo === 'insert' ? 'disabled' : ''; ?>
                                aria-label="<?php te('fc.mfc_aggiungi_esempio'); ?>" title="<?php te('fc.mfc_aggiungi_esempio'); ?>">
                            <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M14 3v4a1 1 0 0 0 1 1h4"/>
                                <path d="M13.5 21H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h7l5 5v5"/>
                                <path d="M18.5 15.5v6M15.5 18.5h6"/>
                            </svg>
                            <span><?php echo sec_h(mfc_parola_esempio()); ?></span>
                            <span class="fc-btn-esempio-conta" id="fcEsempiConta"><?php echo (!empty($ex_count) && (int)$ex_count > 0) ? '(' . (int)$ex_count . ')' : ''; ?></span>
                        </button>
                        <?php $ns_instance = 'scheda'; include __DIR__ . '/fc_autosave_bar.php'; unset($ns_instance); ?>
                    </div>
                    <button type="button" class="exm-icon-btn exm-close-inline" onclick="fcAnnullaModifica('<?php echo sec_h($cancel_url); ?>')" aria-label="<?php te('comune.chiudi'); ?>">
                        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M6 6l12 12M18 6 6 18"/></svg>
                    </button>
                </div>


                <input type="hidden" name="csrf_token" value="<?php echo sec_h(sec_csrf_token()); ?>"><?php echo fc_campo_tipo(); ?>
                <input type="hidden" name="modo" value="<?php echo sec_h($fc_modo); ?>">

                <input type="hidden" name="return_to_game" value="<?php echo isset($_GET['return_to_game']) ? 1 : 0; ?>">
                <input type="hidden" name="game_id" value="<?php echo isset($_GET['game_id']) ? (int)$_GET['game_id'] : (isset($_POST['game_id']) ? (int)$_POST['game_id'] : 0); ?>">
                <input type="hidden" name="position" value="<?php echo isset($_GET['position']) ? (int)$_GET['position'] : (isset($_POST['position']) ? (int)$_POST['position'] : 0); ?>">
                <input type="hidden" name="status" value="<?php echo isset($_GET['status']) ? sec_h($_GET['status']) : (isset($_POST['status']) ? sec_h($_POST['status']) : '0'); ?>">
                <input type="hidden" name="order" value="<?php echo isset($_GET['order']) ? sec_h($_GET['order']) : (isset($_POST['order']) ? sec_h($_POST['order']) : 'random'); ?>">

                <?php if ($is_child && !empty($_GET['torna_a'])): ?>
                <?php /* Si e' arrivati qui dalla sezione "sottocarte" del padre:
                         il ritorno deve esserci, altrimenti l'unica uscita e' la
                         X, che chiude tutto e riporta all'elenco — cioe' fa
                         perdere il punto in cui si stava lavorando. */ ?>
                <a class="fc-torna-padre" href="<?php echo sec_h(fc_url('fc_card_edit.php', ['fc_id' => (int)$_GET['torna_a'], 'previous_catid' => (int)$category_id])); ?><?php echo !empty($_GET['fc_popup']) ? '&fc_popup=1' : ''; ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
                    <?php te('fc.vis_fcv_carta_padre'); ?>
                </a>
                <?php endif; ?>

                <?php if ($is_child): ?>
                    <input type="hidden" name="child_id" value="<?php echo (int)$id; ?>">
                <?php else: ?>
                    <input type="hidden" name="fc_id" value="<?php echo (int)$id; ?>">
                <?php endif; ?>
                <input type="hidden" name="previous_catid" value="<?php echo (int)$prev_cat; ?>">

                <!-- ============== MIGLIORA CON L'IA ============== -->
                <?php /* La card intera, non solo il pulsante dentro: in modifica non c'e'
             niente da metterci (l'inserimento multiplo ha senso solo quando
             si sta ancora scegliendo se creare una carta sola o tante), e una
             card bianca vuota — si vede nello screenshot, un ovale senza
             contenuto sotto la barra — restava comunque a occupare spazio e
             a chiedersi cosa dovesse esserci. */ ?>
                <?php if ($fc_modo === 'insert'): ?>
                <section class="fc-card fc-card-tight fc-card-shortcuts">
                    <?php /* L'inserimento multiplo era il primo pulsante della vecchia
                             pagina di inserimento, ed e' sparito nell'unione: chi deve
                             creare venti carte non vuole aprire venti volte questo
                             modulo. Sta qui in cima, dove stava prima, e porta con se'
                             la categoria gia' scelta. */ ?>
                    <?php /* target="_top": questo modulo vive dentro un iframe, e senza
                             indicare la finestra vera l'inserimento multiplo si
                             caricava DENTRO il popup — stretto, con la sua barra e
                             senza via d'uscita. Cosi' invece prende tutta la
                             pagina, come deve.
                             Il colore e' quello della materia: il pulsante porta
                             dentro quella categoria, e vederlo del suo colore lo
                             lega a cio' su cui si sta lavorando. */
                           $cat_col = $categorie_colore[$category_id] ?? '#AF52DE'; ?>
                    <a class="cta-insert" target="_top" style="--cta-col:<?php echo sec_h($cat_col); ?>"
                       href="<?php echo sec_h(fc_url('fc_insert.php', $category_id > 0 ? ['category_id' => (int)$category_id] : [])); ?>">
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
                </section>
                <?php endif; ?>

                <!-- ============== CATEGORIA ============== -->
                <section class="fc-card fc-card-categoria">
                    <div class="fc-card-head"><span class="fc-card-title"><?php te('vocab.cat_categoria'); ?></span></div>
                    <?php if (!$is_child): 
                        // Configuriamo le variabili per il nuovo componente "Plug & Play"
                        $cs_id = 'modifica_fc'; // ID univoco per sbloccare la modalità "moderna"
                        $cs_selected_id = $category_id;
                        $cs_selected_name = (!empty($category_id) && isset($categories[$category_id])) ? $categories[$category_id] : '';
                        $cs_placeholder = t('fc.ifc_scegli_cat');
                        
                        // Il file genererà automaticamente il bottone, l'input nascosto e il popup!
                        include __DIR__ . '/fc_deck_selector.php'; 
                    ?>
                    <?php else: ?>
                        <div class="inherited-note"><?php te('fc.mfc_categoria_ereditata'); ?></div>
                    <?php endif; ?>
                </section>

                <?php if (fc_is_vocab()): $fcC = fc_corso(); ?>
                <!-- ============== LA PAROLA ==============
                     Campi semplici: la parola nella lingua che si impara, la
                     traduzione nella propria, il significato (facoltativo,
                     anche lui nella lingua che si impara) e un'immagine. Il
                     tasto accanto alla parola la fa sentire subito, per
                     controllare che sia scritta come si pronuncia. -->
                <section class="fc-card fc-card-q fc-vocab-word">
                    <div class="fc-card-head">
                        <span class="fc-card-title fc-lang-title"><img class="fc-lang-flag" src="<?php echo sec_h(fc_lingua_bandiera($fcC['learn_lang'])); ?>" alt=""> <?php echo sec_h(fc_lingua_nome($fcC['learn_lang'], false)); ?></span>
                        <button type="button" class="fc-vocab-speak" id="fcSpeakWord" aria-label="<?php te('fcv.ascolta'); ?>">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 5 6 9H2v6h4l5 4z"/><path d="M15.5 8.5a5 5 0 0 1 0 7"/><path d="M18.8 5.2a9.5 9.5 0 0 1 0 13.6"/></svg>
                        </button>
                    </div>
                    <input type="text" name="word" id="fcWord" class="fc-vocab-input fc-vocab-input-big" data-fc-vocab-field
                           value="<?php echo sec_h($row['word'] ?? ''); ?>" placeholder="<?php te('fcv.ph_parola'); ?>"
                           lang="<?php echo sec_h($fcC['learn_lang']); ?>" autocomplete="off" autocapitalize="off" spellcheck="true" maxlength="255">
                </section>

                <section class="fc-card fc-card-a">
                    <div class="fc-card-head">
                        <span class="fc-card-title fc-lang-title"><img class="fc-lang-flag" src="<?php echo sec_h(fc_lingua_bandiera($fcC['known_lang'])); ?>" alt=""> <?php te('fcv.traduzione'); ?></span>
                    </div>
                    <input type="text" name="translation" id="fcTranslation" class="fc-vocab-input" data-fc-vocab-field
                           value="<?php echo sec_h($row['translation'] ?? ''); ?>" placeholder="<?php te('fcv.ph_traduzione'); ?>"
                           lang="<?php echo sec_h($fcC['known_lang']); ?>" autocomplete="off" maxlength="500">
                    <label class="fc-vocab-label" for="fcMeaning"><?php te('fcv.significato'); ?> <span class="fc-vocab-opt"><?php te('fcv.facoltativo'); ?></span></label>
                    <textarea name="meaning" id="fcMeaning" class="fc-vocab-input fc-vocab-area" rows="2" data-fc-vocab-field
                              placeholder="<?php te('fcv.ph_significato'); ?>" lang="<?php echo sec_h($fcC['learn_lang']); ?>" maxlength="400"><?php echo sec_h($row['meaning'] ?? ''); ?></textarea>
                </section>

                <section class="fc-card">
                    <div class="fc-card-head"><span class="fc-card-title"><?php te('fcv.immagine'); ?></span></div>
                    <?php fc_img_box_modifica('q', t('fcv.immagine'), $row['image'] ?? ''); ?>
                </section>
                <?php else: ?>
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
                    include __DIR__ . '/../partials/format_toolbar.php';
                    ?>

                    <?php /* Se non c'e' ne' immagine ne' audio i due riquadri stanno su una
                             riga sola: vuoti e impilati occupavano mezza schermata
                             per non dire niente. */
                       $affianca_q = empty($row['question_image']) && empty($row['question_audio']); ?>
                    <div class="<?php echo $affianca_q ? 'fc-media-affiancati' : ''; ?>">
                    <?php fc_img_box_modifica('q', t('fc.ifc_img_domanda'), $row['question_image']); ?>
                    <?php fc_audio_block('q', t('fc.ifc_voc_domanda'), $row['question_audio']); ?>
                    </div>
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
                    include __DIR__ . '/../partials/format_toolbar.php';
                    ?>

                    <?php /* Se non c'e' ne' immagine ne' audio i due riquadri stanno su una
                             riga sola: vuoti e impilati occupavano mezza schermata
                             per non dire niente. */
                       $affianca_a = empty($row['answer_image']) && empty($row['answer_audio']); ?>
                    <div class="<?php echo $affianca_a ? 'fc-media-affiancati' : ''; ?>">
                    <?php fc_img_box_modifica('a', t('fc.ifc_img_risposta'), $row['answer_image']); ?>
                    <?php fc_audio_block('a', t('fc.ifc_voc_risposta'), $row['answer_audio']); ?>
                    </div>
                </section>
                <?php endif; ?>

                <!-- ============== ESEMPI ============== -->
                <?php /* In inserimento la sezione parte nascosta: finche' la carta
                         non esiste non ha un id a cui agganciare un esempio. La
                         mostra il JS subito dopo la creazione. */ ?>
                <section class="fc-card fc-card-tight qa-examples-wrap"<?php echo $fc_modo === 'insert' ? ' hidden' : ''; ?>>
                    <?php $ex_tipo = $is_child ? 'child' : 'parent'; ?>
                    <?php /* Il riquadro col carosello, identico a quello di
                             fc_view.php: si riempie da solo via
                             fc_examples_modal.js. Il pulsante "aggiungi" non e' piu'
                             qui — sta nella barra in alto, fra le azioni della
                             carta. Se non ci sono esempi la sezione resta vuota e
                             il JS la nasconde. */ ?>
                    <?php render_examples_section($id, $ex_tipo, $id, [$id => max(1, (int)$ex_count)]); ?>
</section>



                <?php if (!empty($figli)): ?>
                <?php /* LE SOTTOCARTE, IN FONDO E CHIUSE
                         Aperto il modulo di un padre non c'era nessun segno che
                         avesse delle sottocarte: per correggerne una si tornava
                         all'elenco, la si cercava, la si apriva. Qui sono dove
                         devono stare — sotto la carta a cui appartengono — e
                         chiuse, perche' nove volte su dieci si e' venuti per il
                         padre e non per loro.
                         Ognuna porta al proprio modulo, quello vero, con tutto
                         quello che ha (immagini, audio, esempi): una modifica a
                         una sottocarta non e' una modifica di serie B e non
                         merita una versione ridotta dell'editor. Il parametro
                         torna_a fa comparire li' il ritorno a questa carta,
                         cosi' non si resta in fondo a un percorso senza
                         via d'uscita. */ ?>
                <section class="fc-card fc-figli" id="fcFigli">
                    <button type="button" class="fc-figli-testa" onclick="fcApriChiudiFigli(this)" aria-expanded="false">
                        <svg class="fc-figli-freccia" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>
                        <span class="fc-figli-tit"><?php echo sec_h(T('fc.vis_n_sottocarte', ['n' => count($figli)])); ?></span>
                    </button>

                    <div class="fc-figli-corpo" hidden>
                        <?php foreach ($figli as $i => $f):
                            $fq = mfc_testo_piano($f['question_desc']);
                            $fa = mfc_testo_piano($f['answer_desc'], 70);
                            $url = fc_url('fc_card_edit.php', ['child_id' => (int)$f['id_child'], 'previous_catid' => (int)$category_id, 'torna_a' => (int)$id])
                                 . (!empty($_GET['fc_popup']) ? '&fc_popup=1' : '');
                        ?>
                        <a class="fc-figlio" href="<?php echo sec_h($url); ?>">
                            <span class="fc-figlio-n"><?php echo (int)$i + 1; ?></span>
                            <span class="fc-figlio-testi">
                                <span class="fc-figlio-q"><?php echo sec_h($fq !== '' ? $fq : '—'); ?></span>
                                <span class="fc-figlio-a"><?php echo sec_h($fa !== '' ? $fa : '—'); ?></span>
                            </span>
                            <svg class="fc-figlio-matita" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </section>
                <?php endif; ?>

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

            <?php /* La guida parlava solo della barra di formattazione, che e'
                     rimasta com'era: nel frattempo pero' questo modulo ha
                     preso l'IA, gli esempi, il salvataggio automatico e il
                     tocco sul riquadro per scegliere un'immagine, e di quelli
                     non diceva niente. Ora e' divisa per argomento, cosi' si
                     trova quello che si cerca invece di leggere un elenco
                     unico. */ ?>

            <?php /* Le icone sono le STESSE di format_toolbar.php, copiate da li':
                     una guida che disegna icone diverse da quelle sui pulsanti
                     costringe a indovinare la corrispondenza. */ ?>
            <div class="guide-sez"><?php te('fc.ifc_guida_sez_testo'); ?></div>
            <ul class="guide-list">
                <li><span class="g-ic g-ic-svg"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M4.8 16.6L10.4 4.6h1.5l5.6 12"/><path d="M7.1 12.6h8.1"/><path d="M4.4 20.2h15.2" opacity=".45"/></svg></span> <?php te('fc.ifc_guida_tab_testo'); ?></li>
                <li><span class="g-ic"><b>B</b> <i>I</i> <u>U</u></span> <?php te('fc.ifc_guida_stili'); ?></li>
                <li><span class="g-ic" style="color:var(--red)">A</span> <?php te('fc.ifc_guida_colore'); ?></li>
                <li><span class="g-ic g-ic-svg"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M14.6 3.9l5.5 5.5-7.7 7.7-5.5-5.5z"/><path d="M6.9 11.6l-1.6 3.1 3.9 3.9 3.2-1.5"/><path d="M5.3 14.7l-1.4 3.6 3.6-1.4"/><path d="M4 21.4h16" stroke="var(--yellow,#FFCC00)" stroke-width="2.6"/></svg></span> <?php te('fc.ifc_guida_evidenzia'); ?></li>
                <li><span class="g-ic g-ic-svg"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="3" width="6" height="11" rx="3"/><path d="M5 11a7 7 0 0 0 14 0"/><path d="M12 18v3"/></svg></span> <?php te('fc.ifc_guida_detta'); ?></li>
            </ul>

            <div class="guide-sez"><?php te('fc.ifc_guida_sez_elenco'); ?></div>
            <ul class="guide-list">
                <li><span class="g-ic g-ic-svg"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="4.6" cy="6.5" r="1.35" fill="currentColor" stroke="none"/><path d="M9.5 6.5h10.4"/><circle cx="4.6" cy="12" r="1.35" fill="currentColor" stroke="none"/><path d="M9.5 12h10.4"/><circle cx="4.6" cy="17.5" r="1.35" fill="currentColor" stroke="none"/><path d="M9.5 17.5h10.4"/></svg></span> <?php te('fc.ifc_guida_tab_elenco'); ?></li>
                <li><span class="g-ic g-ic-svg"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M3.8 5.2h16.4"/><path d="M10.6 9.7h9.6"/><path d="M10.6 14.3h9.6"/><path d="M3.8 18.8h16.4"/><path d="M3.8 9.4l3.6 2.6-3.6 2.6z" fill="currentColor" stroke="none"/></svg></span> <?php te('fc.ifc_guida_rientri'); ?></li>
                <li><span class="g-ic g-ic-svg"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="4.6" cy="6.8" r="1.35" fill="currentColor" stroke="none"/><path d="M9.5 6.8h10.4"/><path d="M20 12.4v2.2a2 2 0 01-2 2H10"/><path d="M12.6 14.1L9.9 16.6l2.7 2.5"/></svg></span> <?php te('fc.ifc_guida_acapo'); ?></li>
                <li><span class="g-ic">🎨</span> <?php te('fc.ifc_guida_barra'); ?></li>
            </ul>

            <div class="guide-sez"><?php te('fc.ifc_guida_sez_formule'); ?></div>
            <ul class="guide-list">
                <li><span class="g-ic g-ic-svg"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M2.8 12.9l2.3 1.2 2.4-8.4h13.7"/><path d="M11.8 11.2l5.4 6.6"/><path d="M17.2 11.2l-5.4 6.6"/></svg></span> <?php te('fc.ifc_guida_tab_formula'); ?></li>
                <li><span class="g-ic">$x$</span> <?php te('fc.ifc_guida_formula_inline'); ?></li>
                <li><span class="g-ic">$$x$$</span> <?php te('fc.ifc_guida_formula_blocco'); ?></li>
                <li><span class="g-ic">&lt;/&gt;</span> <?php te('fc.ifc_guida_grezzo'); ?></li>
            </ul>
            <p class="modal-note"><?php te('fc.ifc_guida_formula_modifica'); ?></p>

            <div class="guide-sez"><?php te('fc.ifc_guida_sez_media'); ?></div>
            <ul class="guide-list">
                <li><span class="g-ic">🖼</span> <?php te('fc.ifc_guida_img'); ?></li>
                <li><span class="g-ic">🎙</span> <?php te('fc.ifc_guida_audio'); ?></li>
            </ul>

            <div class="guide-sez"><?php te('fc.ifc_guida_sez_ia'); ?></div>
            <ul class="guide-list">
                <li><span class="g-ic" style="color:var(--purple)">✦</span> <?php te('fc.ifc_guida_ia'); ?></li>
                <li><span class="g-ic">＋</span> <?php te('fc.ifc_guida_ia_esempio'); ?></li>
            </ul>

            <div class="guide-sez"><?php te('fc.ifc_guida_sez_esempi'); ?></div>
            <ul class="guide-list">
                <li><span class="g-ic">＋</span> <?php te('fc.ifc_guida_esempi_add'); ?></li>
                <li><span class="g-ic">‹ ›</span> <?php te('fc.ifc_guida_esempi_box'); ?></li>
            </ul>

            <div class="guide-sez"><?php te('fc.ifc_guida_sez_salvataggio'); ?></div>
            <ul class="guide-list">
                <li><span class="g-ic" style="color:var(--green)">💾</span> <?php te('fc.ifc_guida_salva'); ?></li>
                <li><span class="g-ic">↻</span> <?php te('fc.ifc_guida_altra'); ?></li>
                <li><span class="g-ic">☰</span> <?php te('fc.ifc_guida_multiplo'); ?></li>
            </ul>
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

    /* QUALE INPUT SPORCA LA CARTA
       ---------------------------------------------------------------
       Nel documento non c'e' solo la carta. Ci sono anche il pannello degli
       esempi, il riquadro dell'IA e la guida rapida: tutti fuori da
       #editFcForm, tutti con campi di testo dentro. Ascoltando 'input' su
       tutto il documento, scrivere un esempio sporcava la CARTA — ed e'
       esattamente il caso segnalato: si aggiunge un esempio (che si salva da
       solo, con la sua barra e la sua istanza di autosave, e finisce nel
       database subito), non si tocca niente della carta, si chiude e la
       pagina chiede "uscire senza salvare?". Una domanda senza risposta
       giusta: non c'era niente da salvare, e rispondere "esci" lasciava il
       dubbio di aver buttato via l'esempio appena scritto.

       La regola ora e' letterale: sporca la carta solo cio' che succede
       dentro il modulo della carta. */
    function fcEventoDellaCarta(ev) {
        var form = document.getElementById('editFcForm');
        var t = ev && ev.target;
        if (!form || !t || t.nodeType !== 1) return false;
        /* t.form copre il campo che sta fuori dal modulo ma gli e' legato con
           l'attributo form= — oggi non ce ne sono, domani non si sa. */
        return form.contains(t) || t.form === form;
    }

    function fcSegnaSporco(ev) { if (fcEventoDellaCarta(ev)) fcSporco = true; }
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

    /* Il tasto porta all'ELENCO degli esempi, non dritto al modulo di uno
       nuovo. Prima ci saltava sopra: chi voleva rileggere o correggere un
       esempio gia' scritto si ritrovava un foglio bianco, e l'unica via per
       l'elenco era il riquadro in fondo alla carta — che pero' esiste solo se
       almeno un esempio c'e' gia'.

       Non serve nessun caso particolare per la carta senza esempi:
       openExamplesModal, se non ne trova nemmeno uno, apre da se' il modulo
       del nuovo (fc_examples_modal.php). Quindi il tasto continua a fare la cosa
       giusta in tutt'e due le situazioni, e "aggiungi" resta a un tocco di
       distanza — il "+" in basso a destra del pannello. */
    function fcApriEsempi() {
        if (!FC_ID_CORRENTE) return;
        var q = document.getElementById('quest') || document.getElementById('fcWord'), a = document.getElementById('answ') || document.getElementById('fcTranslation');
        openExamplesModal(FC_ID_CORRENTE, FC_TIPO, q ? q.value : '', a ? a.value : '');
    }
    /* Il vecchio nome resta valido: e' citato in qualche onclick e non vale la
       pena rompere una pagina per un nome. */
    function fcApriNuovoEsempio() { fcApriEsempi(); }

    /* ---- Quanti esempi ha la carta ----
       Il numero stampato dal server e' una fotografia del momento in cui la
       pagina e' partita: da li' in poi si aggiungono e si eliminano esempi
       senza mai ricaricare, e l'unico modo di restare onesti e' richiederlo
       all'API dopo ogni scrittura. */
    function fcMostraContaEsempi(n) {
        var el = document.getElementById('fcEsempiConta');
        if (!el) return;
        n = parseInt(n, 10) || 0;
        el.textContent = n > 0 ? '(' + n + ')' : '';
    }

    function fcRicontaEsempi() {
        if (!FC_ID_CORRENTE) { fcMostraContaEsempi(0); return; }
        fetch(fcExApi('action=get&fc_id=') + encodeURIComponent(FC_ID_CORRENTE)
              + '&fc_type=' + encodeURIComponent(FC_TIPO))
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || !d.success) return;
                fcMostraContaEsempi((d.examples || []).length);
            })
            .catch(function () { /* il numero resta quello di prima: meglio vecchio che sparito */ });
    }

    /* Un passo di avvio che si rompe non deve portarsi dietro quelli dopo.
       Questo blocco e' una fila di inizializzazioni indipendenti — il
       guardiano dei pannelli, le anteprime delle immagini, l'IA — scritte una
       sotto l'altra al primo livello dello script. Scritte cosi', un errore
       nella prima ferma il file a meta' e le altre non partono mai: si va a
       cercare il guasto nell'IA, e l'IA non c'entra niente, semplicemente non
       e' mai stata avviata. Con questo involucro ogni passo cade da solo, e
       la console dice quale.
       Non e' un modo per nascondere gli errori: e' un modo per non
       moltiplicarli. L'errore vero resta scritto in console, col nome del
       passo davanti. */
    function fcAvvia(nome, fn) {
        try { fn(); }
        catch (err) { console.error('[modifica_immagine] avvio "' + nome + '" non riuscito:', err); }
    }

    /* ================================================================
       IL PULSANTE DELL'IA — PRIMA DI TUTTO IL RESTO
       ================================================================
       Questo aggancio stava in fondo al blocco, dopo una decina di altre
       inizializzazioni. Ogni volta che ho provato a spiegarmi il pulsante
       morto sono ripartito dal pulsante, e il pulsante non c'entrava: se una
       qualunque riga PRECEDENTE si rompe, lo script si ferma li' e l'aggancio
       non viene mai eseguito. Il cerchio resta a schermo, disegnato dal PHP,
       con addosso un onclick che chiama una funzione che nessuno ha
       preparato — e premerlo non fa niente, in silenzio, senza un errore che
       colleghi le due cose.
       Spostarlo in cima non e' un rimedio elegante a un guasto ignoto: e'
       togliere di mezzo l'unica dipendenza che aveva. Qui davanti non c'e'
       niente che possa cadere prima.

       Due reti, non una:
       - l'ascoltatore sul pulsante, con l'onclick tolto (quello fallisce in
         silenzio: se fcMigliora non c'e', il browser lo scrive in console e
         basta);
       - un secondo ascoltatore in FASE DI CATTURA sul documento, che prende
         il click prima di chiunque altro. Se qualcosa lungo la strada lo sta
         fermando — un gestore che chiama stopPropagation, un elemento
         trasparente sopra — questo lo intercetta lo stesso.
       E se il click non arriva proprio, la controprova qui sotto dice chi c'e'
       davvero in quel punto dello schermo. */
    var fcUltimoApriIA = 0;

    function fcApriIA(ev) {
        if (ev) ev.preventDefault();
        /* Due strade portano qui (click e pointerup): la seconda serve solo
           quando la prima non arriva, e senza questa guardia, quando arrivano
           tutt'e due, il riquadro si aprirebbe e richiuderebbe. */
        if (Date.now() - fcUltimoApriIA < 400) return;
        fcUltimoApriIA = Date.now();

        try {
            if (typeof fcMigliora === 'undefined' || !fcMigliora || typeof fcMigliora.apri !== 'function') {
                throw new Error('fc_improve_card.js non e\' stato caricato');
            }
            fcMigliora.apri();
        } catch (err) {
            console.error('[modifica_immagine] apertura IA:', err);
            alert('IA: ' + (err && err.message ? err.message : err));
            return;
        }

        /* apri() puo' andare a buon fine e il riquadro non vedersi lo stesso —
           e da fuori i due casi si assomigliano: in tutt'e due "premo e non
           succede niente". Qui si guarda, e si dice quale dei due e'. */
        setTimeout(function () {
            var ov = document.getElementById('fcMiglioraOverlay');
            if (!ov) { console.warn('[modifica_immagine] #fcMiglioraOverlay non esiste'); return; }
            var st = getComputedStyle(ov);
            if (ov.getBoundingClientRect().width > 0 && st.display !== 'none') return;
            console.warn('[modifica_immagine] apri() e\' riuscito ma il riquadro non si vede |',
                         'display:', st.display, '| visibility:', st.visibility,
                         '| opacity:', st.opacity, '| hidden:', ov.hasAttribute('hidden'), ov);
        }, 60);
    }

    function fcTocchiIA(ev) {
        if (!ev.target || !ev.target.closest) return;
        if (!ev.target.closest('#fcMiglioraFab')) return;
        ev.stopPropagation();
        fcApriIA(ev);
    }

    /* DUE EVENTI, NON UNO, TUTT'E DUE IN CATTURA SUL DOCUMENTO.
       La cattura sul documento arriva prima di qualunque altro gestore, quindi
       nessuno puo' fermare il click strada facendo. Ma resta un caso che la
       cattura non copre: se qualcosa chiama preventDefault() sul pointerdown,
       il click NON VIENE MAI GENERATO — non c'e' niente da intercettare, e
       tutto quello che si e' verificato finora resta vero (il pulsante c'e',
       non e' coperto, la funzione esiste) mentre premerlo non fa niente.
       'pointerup' scatta comunque, ed e' l'unica strada che resta se e' quello
       il caso. Se arrivano entrambe, la guardia sui 400ms lascia passare la
       prima. */
    document.addEventListener('click', fcTocchiIA, true);
    document.addEventListener('pointerup', fcTocchiIA, true);

    /* Aprendo dal pointerup, il click arriva DOPO — e a quel punto sotto il
       dito non c'e' piu' il pulsante ma il velo del riquadro appena aperto,
       che al tocco si chiude. Si aprirebbe e richiuderebbe nello stesso gesto,
       troppo in fretta per vederlo: da fuori sembrerebbe di nuovo un pulsante
       che non fa niente. Il primo click subito dopo l'apertura non conta. */
    document.addEventListener('click', function (ev) {
        if (Date.now() - fcUltimoApriIA > 500) return;
        if (!ev.target || !ev.target.closest) return;
        if (!ev.target.closest('#fcMiglioraOverlay')) return;
        ev.stopPropagation();
    }, true);

    /* Le parole non hanno (per ora) l'IA del modulo: niente pulsante da agganciare. */
    if (!(window.FC && FC.tipo === 'vocab')) fcAvvia('pulsante IA', function () {
        var fab = document.getElementById('fcMiglioraFab');
        if (!fab) throw new Error('#fcMiglioraFab non esiste nella pagina');

        /* Appeso al <body>: nato dentro .fcm-wrap, dentro .page, dentro il
           modulo, gli basta un antenato con un z-index piu' basso di un altro
           elemento qualsiasi per finire SOTTO — e un pulsante sotto qualcosa
           non e' invisibile, e' peggio: si vede, si preme, e il tocco lo prende
           quello sopra. */
        if (fab.parentElement !== document.body) document.body.appendChild(fab);
        fab.removeAttribute('onclick');

        /* E IL RIQUADRO CON LUI.
           Il pulsante l'avevo gia' portato sul <body>; il riquadro che deve
           aprire e' rimasto dentro .fcm-wrap, dentro .page, dentro il modulo.
           E' quello a non vedersi: il pulsante infatti sparisce al tocco,
           cioe' qualcosa gli passa sopra — apri() sta girando, il riquadro si
           apre, e non arriva a schermo.
           Un velo a tutto schermo non ha nessun motivo di stare in mezzo al
           contenuto: gli basta un antenato con transform, filter,
           backdrop-filter, contain o will-change per smettere di essere
           ancorato alla finestra e diventare prigioniero di quell'antenato —
           un rettangolo grande come un pezzo di modulo, sotto tutto il resto.
           Attaccato al <body> quella catena non c'e' piu'.
           Perche' nel pannello degli esempi lo stesso riquadro funziona: li'
           l'antenato piu' vicino di quel tipo e' .exm-overlay, che a sua volta
           e' fisso e grande quanto la finestra — il riquadro ci finisce dentro
           e per pura combinazione si trova al posto giusto. Qui sopra di lui
           c'e' il modulo, e la combinazione non c'e'. */
        var ovIA = document.getElementById('fcMiglioraOverlay');
        if (ovIA && ovIA.parentElement !== document.body) document.body.appendChild(ovIA);

        setTimeout(function () {
            var r = fab.getBoundingClientRect();
            if (!r.width) { console.warn('[modifica_immagine] il pulsante IA non ha dimensioni: lo nasconde una regola CSS'); return; }
            var sopra = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2);
            if (sopra === fab || fab.contains(sopra)) return;
            console.warn('[modifica_immagine] il pulsante IA e\' coperto da:', sopra,
                         '| classi:', sopra && sopra.className,
                         '| z-index:', sopra && getComputedStyle(sopra).zIndex);
        }, 600);
    });

    /* ================================================================
       CHI STA SOPRA COMANDA
       ================================================================
       In questo documento i pannelli si impilano: il modulo della carta,
       sopra il pannello degli esempi, sopra il riquadro dell'IA degli esempi.
       Due cose andavano storte, ed e' la stessa dimenticanza vista da due
       lati: quello che sta sotto continuava a comportarsi come se fosse
       ancora in primo piano.

       1. Il cerchio dell'IA della carta e' fisso in basso a destra, quindi
          restava li' anche con il pannello degli esempi aperto — accanto al
          cerchio dell'IA DEGLI ESEMPI, che sta nello stesso angolo. Due
          pulsanti identici a un centimetro uno dall'altro che fanno cose
          diverse: si sbaglia, e sbagliando si chiede all'IA di riscrivere la
          carta mentre si stava scrivendo un esempio.

       2. Con un pannello aperto, la rotellina e il dito continuavano a
          scorrere il modulo sottostante: si tornava indietro e la carta era
          in un punto diverso da dove la si era lasciata.

       Un guardiano solo per tutt'e due, perche' la domanda e' una sola: c'e'
       qualcosa aperto sopra? I pannelli non avvisano quando si aprono (chi li
       apre cambia style.display o l'attributo hidden e basta), quindi lo si
       guarda: un MutationObserver su style e hidden, che sono esattamente i
       due attributi con cui si aprono e si chiudono. */
    fcAvvia('guardiano dei pannelli', function () {
        /* Chi copre il modulo. Il riquadro IA della carta e' nell'elenco solo
           per lo scorrimento: quando e' aperto il suo pulsante puo' restare
           visibile, e' il suo. */
        var COPRONO   = '#ex-list-modal, #ex-form-modal, #agendaModal, .cs-modal-overlay, #fcMiglioraOverlay';
        var RUBANO_IA = '#ex-list-modal, #ex-form-modal, #agendaModal, .cs-modal-overlay';

        /* "Ha un rettangolo sullo schermo?" invece di "che display ha?".
           getComputedStyle diceva la verita' solo se il foglio di stile del
           pannello era gia' arrivato: se per un istante non lo era, un <div>
           nascosto risultava display:block, cioe' aperto, e il guardiano si
           comportava come se ci fosse un pannello sopra quando non c'era
           niente. getClientRects() non ha questo problema: un elemento
           nascosto, in qualunque modo lo sia, non occupa nessun rettangolo. */
        function visibile(el) {
            if (!el || el.hasAttribute('hidden')) return false;
            return el.getClientRects().length > 0;
        }
        function qualcosaAperto(selettori) {
            var lista = document.querySelectorAll(selettori);
            for (var i = 0; i < lista.length; i++) if (visibile(lista[i])) return true;
            return false;
        }

        var eraCoperto = null, scrollSalvato = 0;

        function aggiorna() {
            var coperto = qualcosaAperto(COPRONO);

            var fab = document.getElementById('fcMiglioraFab');
            /* Una CLASSE, non l'attributo hidden. Due motivi, e il secondo e'
               quello che conta: l'attributo hidden su quel pulsante appartiene
               gia' a fc_improve_card.js, e due padroni sullo stesso
               attributo vuol dire che ogni tanto vince quello sbagliato e il
               pulsante resta spento anche a pannelli chiusi. Il primo motivo
               e' che l'osservatore qui sotto guarda proprio 'hidden': scriverlo
               da dentro il gestore vuol dire farsi richiamare da se stessi,
               ogni 30ms, finche' il pannello resta aperto. La classe non e'
               osservata, quindi il giro si chiude. */
            if (fab) fab.classList.toggle('is-coperto', qualcosaAperto(RUBANO_IA));

            /* Primo giro: si prende nota e basta. Prima qui eraCoperto valeva
               null, quindi il confronto falliva sempre e si finiva nel ramo
               che "ripristina" lo scorrimento — a pagina appena aperta, con
               scrollSalvato ancora a zero: uno scatto in cima senza motivo. */
            if (eraCoperto === null) { eraCoperto = coperto; return; }
            if (coperto === eraCoperto) return;
            /* La posizione di scorrimento si mette da parte e si rimette a
               mano: overflow:hidden di norma la conserva, ma "di norma" su
               una cosa che si nota subito non basta — e ripristinarla costa
               una riga. */
            if (coperto) scrollSalvato = window.scrollY || document.documentElement.scrollTop || 0;
            eraCoperto = coperto;
            document.documentElement.classList.toggle('fc-popup-sopra', coperto);
            if (!coperto) window.scrollTo(0, scrollSalvato);
        }

        var attesa = null;
        new MutationObserver(function () {
            /* Un filo di attesa: aprire un pannello tocca piu' attributi di
               fila, e senza questo il controllo girerebbe una volta per
               ognuno per arrivare sempre alla stessa conclusione. */
            clearTimeout(attesa);
            attesa = setTimeout(aggiorna, 30);
        }).observe(document.documentElement, {
            attributes: true, subtree: true, attributeFilter: ['style', 'hidden']
        });

        aggiorna();
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
       fc_examples_api.php per conto loro, senza toccare il codice di questa
       pagina: senza questo aggancio, chiudendo il popup dopo aver aggiunto un
       esempio l'elenco sotto resterebbe con il vecchio conteggio. */
    (function () {
        var fetchOriginale = window.fetch;
        window.fetch = function (input, init) {
            var url = (typeof input === 'string') ? input : (input && input.url) || '';
            var metodo = String((init && init.method) || 'GET').toUpperCase();
            return fetchOriginale.apply(this, arguments).then(function (res) {
                if (metodo === 'POST' && url.indexOf('fc_examples_api.php') !== -1 && res.ok) {
                    fcSegnalaCambiato();
                    /* Aggiunto o eliminato un esempio, il numero sul tasto in
                       barra e' vecchio di un esempio: si richiede. */
                    fcRicontaEsempi();
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
        /* i campi semplici della parola (flashcard di lingua) */
        document.querySelectorAll('[data-fc-vocab-field]').forEach(function (el) { el.value = ''; });
        var fw = document.getElementById('fcWord'); if (fw) setTimeout(function () { fw.focus(); }, 300);
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
        /* Anche il numero sul tasto: e' quello della carta precedente, e su una
           carta che non esiste ancora vorrebbe dire il falso. */
        fcMostraContaEsempi(0);
        document.getElementById('fcAltraBtn').hidden = true;
        var box = document.querySelector('.qa-examples');
        if (box) box.hidden = true;
        StandoutSaveBar.reset('scheda');
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
    // ANTEPRIME IMMAGINI — motore in fc_image_editor.js, condiviso
    // con fc_insert_card.php. onScelta annulla una rimozione gia' chiesta:
    // scegliere un file nuovo e rimuovere insieme non avrebbe senso, e vince
    // il tocco piu' recente.
    // ============================================================
    fcAvvia('anteprime immagini', function () {
        fcImgSetupPreview('qNew_image', 'q_preview', 'q_img_wrap', function () { fcImgAnnullaRimozione('q'); });
        fcImgSetupPreview('aNew_image', 'a_preview', 'a_img_wrap', function () { fcImgAnnullaRimozione('a'); });
    });

    // ============================================================
    // GENERA/MIGLIORA CON L'IA
    // ============================================================
    /* Senza questa chiamata il pulsante dell'IA c'e', si preme, e non succede
       niente: apri() va a leggere due campi di cui non conosce ancora il nome
       e si ferma sulla prima riga. E' il guasto piu' facile da diagnosticare
       male, perche' il sintomo (un pulsante morto) non assomiglia per niente
       alla causa (una riga piu' su che ha interrotto lo script). */
    if (!(window.FC && FC.tipo === 'vocab')) fcAvvia('IA', function () {
        fcMigliora.inizializza({ campoQ: 'quest', campoA: 'answ' });
    });

    /* LA PAROLA: il tasto accanto la fa sentire nella lingua del corso. */
    fcAvvia('pronuncia', function () {
        var b = document.getElementById('fcSpeakWord');
        if (!b) return;
        b.addEventListener('click', function () {
            var w = document.getElementById('fcWord');
            if (w && w.value.trim() && typeof fcSpeakLearn === 'function') fcSpeakLearn(b, w.value);
        });
    });

    /* IL PULSANTE DELL'IA, RIAGGANCIATO A MANO
       Il cerchio nasce con onclick="fcMigliora.apri()" scritto nell'attributo
       (fc_ai_fab.php). Un attributo del genere fallisce in silenzio: se
       fcMigliora non c'e' — il file non caricato, un errore prima — il browser
       scrive una riga in console e il pulsante sembra semplicemente morto. Ed
       e' impossibile distinguere "non ha caricato" da "ha caricato e non fa
       niente" senza aprire gli strumenti da sviluppatore.
       Qui l'attributo viene tolto e sostituito da un ascoltatore vero, che
       prova ad aprire e, se non ci riesce, LO DICE: un avviso a schermo col
       motivo, invece di un pulsante che non risponde. */
    /* L'aggancio del pulsante IA sta ora in cima a questo blocco: vedi li'
       il perche'. */

    /* A differenza di fc_insert_card.php, qui la carta esiste gia' e ha gia'
       un id: un esempio generato dall'IA si puo' salvare subito, senza
       aspettare il salvataggio della carta. fc_improve_card.js chiama
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
            var r = await fetch(fcExApi(''), { method: 'POST', body: fd });
            var j = await r.json();
            if (j && j.success) {
                fcMigliora.scartaEsempioInSospeso();
                fcSegnalaCambiato();
                /* Il riquadro esempi va ridisegnato: ora ce n'e' uno in piu' di
                   quelli che aveva letto al caricamento della pagina. */
                fcRinfrescaSezioneEsempi();
            }
        } catch (e) { /* la carta e' comunque aggiornata: l'esempio si puo' riaggiungere a mano */ }
    }

    // ============================================================
    // REGISTRAZIONE AUDIO — motore in fc_audio_recorder.js, condiviso
    // con fc_insert_card.php ed fc_examples_modal.php. Qui resta solo
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
    // visore a schermo intero: quello lo usa solo fc_view.php,
    // che e' di sola lettura — qui si e' gia' dentro un modulo di
    // modifica, ha piu' senso finire dritti dove si puo' cambiare.
    // ============================================================
    /* ---- LA SEZIONE ESEMPI DELLA CARTA, RIDISEGNATA ----
       Il riquadro col carosello si riempie una volta sola, all'apertura della
       pagina, leggendo dall'API. Da li' in poi il pannello degli esempi vive
       di vita propria: si aggiunge, si corregge, si elimina, e la carta sotto
       non ne sa niente — si chiudeva il pannello e la sezione mostrava ancora
       gli esempi di prima, col vecchio conteggio. Bastava che questo pezzo di
       codice, che gia' esisteva, non venisse mai richiamato al momento giusto.
       Qui e' una funzione con un nome, cosi' si puo' richiamare da tutti i
       momenti giusti invece di essere copiata in alcuni di loro. */
    /* Apre e chiude l'elenco delle sottocarte. hidden e non una classe: e' un
       contenuto che c'e' o non c'e', non un contenuto trasparente — e cosi' non
       lo leggono nemmeno i lettori di schermo mentre e' chiuso. */
    function fcApriChiudiFigli(btn) {
        var corpo = document.querySelector('.fc-figli-corpo');
        if (!corpo) return;
        var apro = corpo.hidden;
        corpo.hidden = !apro;
        btn.setAttribute('aria-expanded', apro ? 'true' : 'false');
        btn.closest('.fc-figli').classList.toggle('is-aperta', apro);
    }

    function fcRinfrescaSezioneEsempi() {
        var sez = document.querySelector('.qa-examples-wrap');
        if (!sez) return;

        sez.querySelectorAll('.qa-examples-box').forEach(function (b) {
            b.classList.remove('qa-examples-box-filled');
            b.innerHTML = '';
            /* L'elenco e l'indice del carosello vivono come proprieta' JS
               sull'elemento (fc_examples_modal.js): svuotare l'HTML e lasciarli
               li' vorrebbe dire riempire un carosello nuovo con le frecce di
               quello vecchio. */
            b._fcExamples = null;
            b._fcIndex = 0;
        });

        if (typeof fcLoadExampleBoxes === 'function') fcLoadExampleBoxes(sez);
        fcRicontaEsempi();

        /* La sezione si nasconde se il riquadro resta vuoto: il conteggio
           lato server e' solo un'ipotesi (puo' essere cambiato da un'altra
           scheda), quello che conta e' cosa ha davvero trovato l'API. Il
           ritardo aspetta che la richiesta di fcLoadExampleBoxes torni. */
        setTimeout(function () {
            if (FC_MODO === 'insert' && !FC_ID_CORRENTE) return;
            sez.hidden = !sez.querySelector('.qa-examples-box-filled');
        }, 900);
    }

    fcRinfrescaSezioneEsempi();

    /* Si arriva qui dal visore degli esempi di fc_view.php, che
       passa apri_esempio=<id>: la carta si apre e sopra ci si trova subito il
       modulo di QUELL'esempio. Senza, l'unico modo di correggere un esempio
       visto altrove era rifare a mano tutta la strada.
       Il ritardo aspetta che fc_examples_modal.php abbia definito le sue
       funzioni: sta in fondo alla pagina, dopo questo script. */
    <?php if (!empty($_GET['apri_esempio'])): ?>
    setTimeout(function () {
        if (typeof exApriEsempioSingolo !== 'function' || !FC_ID_CORRENTE) return;
        var q = document.getElementById('quest') || document.getElementById('fcWord'), a = document.getElementById('answ') || document.getElementById('fcTranslation');
        /* exApriEsempioSingolo e non openExampleForm: quest'ultima legge i campi
           dalla memoria del pannello, che arrivando da un indirizzo e' ancora
           vuota — il modulo si aprirebbe bianco. */
        exApriEsempioSingolo(FC_ID_CORRENTE, FC_TIPO, <?php echo (int)$_GET['apri_esempio']; ?>,
                             q ? q.value : '', a ? a.value : '');
    }, 120);
    <?php endif; ?>

    /* Il pannello degli esempi avvisa quando si chiude del tutto
       (chiudiElencoEsempi in fc_examples_modal.php). E' il momento in cui si
       torna a guardare la carta, quindi e' il momento in cui la sua sezione
       esempi deve gia' essere quella nuova — non dopo un ricaricamento. */
    document.addEventListener('fc-esempi-chiusi', function () {
        fcRinfrescaSezioneEsempi();
    });

    document.addEventListener('click', function (e) {
        var box = e.target.closest('.qa-examples-box-filled');
        if (!box) return;
        /* Le frecce/pallini del carosello, e i controlli audio dentro
           l'esempio, hanno gia' un loro significato: il tocco non deve
           aprire il popup anche in quei casi. */
        if (e.target.closest('.qa-ex-nav, a, audio, .audio-player')) return;
        /* Fine di una sfogliata: il browser manda comunque un click, che
           altrimenti aprirebbe il popup ogni volta che si cambia esempio
           (fcExUltimoSwipe vive in fc_examples_modal.js). */
        if (Date.now() - fcExUltimoSwipe < 400) return;

        var realId = parseInt(box.getAttribute('data-fc-id'), 10) || 0;
        var type = box.getAttribute('data-fc-type') || 'parent';
        var qEl = document.getElementById('quest') || document.getElementById('fcWord'), aEl = document.getElementById('answ') || document.getElementById('fcTranslation');
        openExamplesModal(realId, type, qEl ? qEl.value : '', aEl ? aEl.value : '');

        /* Qui si e' gia' dentro un modulo di modifica: toccare un esempio deve
           portare a modificare QUELLO, non a sfogliare l'elenco. Il carosello
           sa quale sta mostrando (_fcIndex), quindi si apre direttamente il suo
           modulo. Il ritardo aspetta l'animazione del pannello sotto. */
        var esempi = box._fcExamples || [];
        var corrente = esempi[box._fcIndex || 0];
        if (corrente && corrente.id && typeof openExampleForm === 'function') {
            setTimeout(function () { openExampleForm(realId, type, corrente.id); }, 260);
        }
    });

    if (document.getElementById('editFcForm')) {
        /* Dentro un popup (fc_edit_popup.js) questa pagina vive in un iframe:
           window.parent e' la finestra vera, quella che ha aperto il popup.
           Fuori da un popup — aperta come pagina a se', con un indirizzo
           diretto — window.parent === window, e ci si comporta come sempre:
           un vero redirect del browser. */
        var dentroPopup = (window.parent && window.parent !== window);

        /* Il salvataggio ora e' una funzione a se': la chiama il pulsante verde
           della barra (fc_autosave_bar.js) e la chiama il salvataggio automatico
           quando l'interruttore e' acceso. Restituisce true/false, che e' il
           contratto che StandoutSaveBar si aspetta. */
        async function salvaScheda() {
            if (typeof fcSyncAllEditors === 'function') fcSyncAllEditors();

            var fd = new FormData(document.getElementById('editFcForm'));
            if (fcAudioBlobDi('q')) fd.append('audio_q', fcAudioBlobDi('q'), 'q.' + fcAudioExtDi('q'));
            if (fcAudioBlobDi('a')) fd.append('audio_a', fcAudioBlobDi('a'), 'a.' + fcAudioExtDi('a'));

            try {
                var res = await fetch(fcUrl('fc_card_edit.php'), { method: 'POST', body: fd });

                /* CREAZIONE: il server risponde in JSON con l'id della carta
                   nuova, e il modulo RESTA APERTO — e' quello che permette di
                   agganciarle subito un esempio, impossibile prima che
                   esistesse (fc_study_examples vuole l'id di una carta vera). */
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
                    /* Da qui in poi questo popup E' quello di modifica: cambia
                       la modalita', il titolo, e si accendono le azioni che
                       hanno bisogno di una carta esistente (esempi, elimina).
                       Senza questo, premere di nuovo salva creerebbe un
                       doppione a ogni giro. */
                    FC_MODO = 'edit';
                    var campoModo = document.querySelector('input[name="modo"]');
                    if (campoModo) campoModo.value = 'edit';
                    var titolo = document.getElementById('fcTopbarTitle');
                    if (titolo) titolo.textContent = T('fc.mfc_titolo_breve');
                    var addEx = document.getElementById('fcAddEsempio');
                    if (addEx) addEx.disabled = false;
                    var altra = document.getElementById('fcAltraBtn');
                    if (altra) altra.hidden = false;
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
            if (activeSubmit !== 'delete') { StandoutSaveBar.saveNow('scheda'); return; }

            if (typeof fcSyncAllEditors === 'function') fcSyncAllEditors();
            var fd = new FormData(this);
            fd.append('delete', '1');
            try {
                var res = await fetch(fcUrl('fc_card_edit.php'), { method: 'POST', body: fd });
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

        StandoutSaveBar.init({
            instance: 'scheda',
            key: 'fc_scheda',
            save: salvaScheda,
            testoMaiSalvato: T('fc.exm_autosave_breve')
        });

        /* Ogni modifica alza lo sporco anche sulla barra, cosi' il pallino
           arancione compare e il salvataggio automatico (se acceso) parte.
           Stesso filtro di fcSegnaSporco, e per lo stesso motivo: senza, ogni
           lettera scritta in un esempio accendeva il pallino della CARTA e —
           con l'interruttore acceso — le faceva partire un salvataggio per
           qualcosa che non era cambiato. La barra degli esempi ce l'ha gia',
           la sua, sull'istanza 'esempi'. */
        document.addEventListener('input', function (ev) { if (fcEventoDellaCarta(ev)) StandoutSaveBar.touch('scheda'); });
        document.addEventListener('change', function (ev) { if (fcEventoDellaCarta(ev)) StandoutSaveBar.touch('scheda'); });
    }
    </script>

    <?php include __DIR__ . '/fc_examples_modal.php'; ?>

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