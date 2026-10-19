<?php
/* Standout - motore delle lingue.
 *
 * Incluso da config.php, quindi disponibile ovunque senza che nessuna pagina
 * debba ricordarsi di richiamarlo: la stessa logica per cui le difese stanno
 * tutte in security_core.php.
 *
 * IL VINCOLO DA TENERE A MENTE, che ha gia' causato un errore:
 * config.php gira PRIMA di security_core.php, cioe' prima di session_start().
 * Tutto quello che si scrive in $_SESSION mentre questo file viene incluso
 * viene buttato via appena la sessione parte davvero. Percio' qui non si tocca
 * la sessione: si registra soltanto cosa e' stato chiesto, e la si scrive alla
 * prima chiamata di standout_lingua(), che avviene dentro la pagina, a sessione
 * gia' avviata.
 *
 * Da dove viene la lingua, in ordine:
 *   0. ?lang=xx           - richiesta esplicita nell'indirizzo
 *   1. la sessione        - gia' decisa in questa visita, nessuna query
 *   2. users.lang         - la scelta dell'utente, letta UNA volta e messa in
 *                           sessione: e' il motivo per cui non si interroga il
 *                           database a ogni pagina
 *   3. il cookie          - serve prima del login (landing, accesso, registrazione)
 *   4. Accept-Language    - primo accesso in assoluto: si indovina dal browser
 *   5. l'italiano         - se non si e' capito niente
 */

/* Le lingue che esistono davvero, cioe' quelle che hanno un file in lang/. */
const STANDOUT_LINGUE = ['it', 'en'];

/* La lingua in cui sono scritte le stringhe originali, quindi anche il ripiego
   quando una traduzione manca. */
const STANDOUT_LINGUA_BASE = 'it';

/* Il nome del cookie resta quello di prima del cambio di nome del sito: e'
   invisibile, e cambiarlo farebbe perdere la lingua scelta a chi non e'
   ancora entrato. */
const STANDOUT_LINGUA_COOKIE = 'nisama_lingua';

/* Come si presenta ogni lingua nel selettore: il nome scritto NELLA lingua
   stessa (chi non capisce l'italiano deve trovare "English", non "Inglese")
   e la bandiera in _assets/svg/flags/. Una lingua nuova: file in lang/,
   codice in STANDOUT_LINGUE e una riga qui. */
const STANDOUT_LINGUE_INFO = [
    'it' => ['nome' => 'Italiano', 'bandiera' => 'it'],
    'en' => ['nome' => 'English',  'bandiera' => 'gb'],
];


/* ============================================================
   1. QUALE LINGUA
   ============================================================ */

function standout_lingua_valida($codice): bool
{
    return is_string($codice) && in_array($codice, STANDOUT_LINGUE, true);
}

/* La lingua decisa per questa richiesta. Sta in una funzione a parte e torna
   per riferimento perche' anche standout_imposta_lingua() deve poterla cambiare:
   una static dentro standout_lingua() non sarebbe raggiungibile da fuori, e dopo
   un cambio a meta' pagina il resto continuerebbe nella lingua vecchia. */
function &standout_lingua_cache()
{
    static $scelta = null;
    return $scelta;
}

/* Memoria di cosa e' stato chiesto con ?lang=. Con un argomento scrive, senza
   legge. Serve a rimandare la scrittura in sessione al momento in cui la
   sessione esiste. */
function standout_lingua_richiesta(?string $imposta = null): ?string
{
    static $codice = null;
    if ($imposta !== null) $codice = $imposta;
    return $codice;
}

function standout_lingua(): string
{
    $scelta =& standout_lingua_cache();
    if ($scelta !== null) return $scelta;

    // 0. richiesta esplicita nell'indirizzo, registrata in fondo a questo file
    $daGet = standout_lingua_richiesta();
    if ($daGet !== null) {
        // ADESSO si puo': la sessione e' partita
        if (session_status() === PHP_SESSION_ACTIVE) $_SESSION['lingua'] = $daGet;
        return $scelta = $daGet;
    }

    // 1. gia' decisa in questa sessione
    if (!empty($_SESSION['lingua']) && standout_lingua_valida($_SESSION['lingua'])) {
        return $scelta = $_SESSION['lingua'];
    }

    // 2. la scelta salvata dall'utente: si legge una volta sola, poi finisce in
    //    sessione e per il resto della navigazione non si torna al database
    /* Il guardiano serve ai messaggi d'errore del database, che sono tradotti
       anche loro: se la connessione cade, il messaggio chiede la lingua, la
       lingua chiede il database, e senza questo si girerebbe in tondo. */
    static $staLeggendo = false;
    if ($staLeggendo) return STANDOUT_LINGUA_BASE;
    $staLeggendo = true;
    try {
        $dalDb = standout_lingua_utente();
    } catch (Throwable $e) {
        $dalDb = null;
    }
    $staLeggendo = false;
    if ($dalDb !== null) {
        if (session_status() === PHP_SESSION_ACTIVE) $_SESSION['lingua'] = $dalDb;
        return $scelta = $dalDb;
    }

    // 3. il cookie: copre le pagine dove non si e' ancora loggati
    if (!empty($_COOKIE[STANDOUT_LINGUA_COOKIE]) && standout_lingua_valida($_COOKIE[STANDOUT_LINGUA_COOKIE])) {
        return $scelta = $_COOKIE[STANDOUT_LINGUA_COOKIE];
    }

    // 4. quello che dichiara il browser
    $dalBrowser = standout_lingua_dal_browser();
    if ($dalBrowser !== null) return $scelta = $dalBrowser;

    return $scelta = STANDOUT_LINGUA_BASE;
}

/* Legge users.lang. Restituisce null anche quando la colonna e' vuota: vuol
   dire "non ho mai scelto", e allora si prosegue con cookie e browser.
   Non usa sec_* perche' questo file e' incluso prima di security_core.php:
   quelle funzioni qui non esistono ancora. */
function standout_lingua_utente(): ?string
{
    if (empty($_SESSION['user_id'])) return null;
    if (!function_exists('standout_db')) return null;   // auth_core non ancora incluso

    $conn = standout_db();
    if (!$conn) return null;

    $uid = (int)$_SESSION['user_id'];
    $stmt = mysqli_prepare($conn, "SELECT lang FROM users WHERE id = ?");
    if (!$stmt) return null;
    mysqli_stmt_bind_param($stmt, 'i', $uid);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $lang);
    $trovato = mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);

    if (!$trovato || !standout_lingua_valida($lang)) return null;
    return $lang;
}

/* Accept-Language arriva come "it-IT,it;q=0.9,en-US;q=0.8". Si tengono le prime
   due lettere di ogni voce, in ordine di preferenza, e si prende la prima che
   sappiamo parlare. */
function standout_lingua_dal_browser(): ?string
{
    $intestazione = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
    if ($intestazione === '') return null;

    foreach (explode(',', $intestazione) as $pezzo) {
        $codice = strtolower(substr(trim(explode(';', $pezzo)[0]), 0, 2));
        if (standout_lingua_valida($codice)) return $codice;
    }
    return null;
}


/* ============================================================
   2. CAMBIARE LINGUA
   ============================================================ */

/* Il cookie e basta. Separata perche' e' l'unica parte che si puo' fare mentre
   config.php viene incluso: richiede le intestazioni ancora aperte, e a quel
   punto lo sono. */
function standout_scrivi_cookie_lingua(string $codice): void
{
    /* setcookie() prepara l'intestazione per il BROWSER e non tocca $_COOKIE.
       Senza la riga qui sotto, la pagina in corso continuerebbe a leggere il
       valore precedente: e' il motivo per cui ?lang=it sembrava ignorato. */
    $_COOKIE[STANDOUT_LINGUA_COOKIE] = $codice;

    /* Un anno: e' una preferenza, non un dato di accesso. HttpOnly no, perche'
       un domani puo' servire al JavaScript sapere in che lingua siamo. */
    if (!headers_sent()) {
        setcookie(STANDOUT_LINGUA_COOKIE, $codice, [
            'expires'  => time() + 31536000,
            'path'     => '/',
            'secure'   => !empty($_SERVER['HTTPS']),
            'httponly' => false,
            'samesite' => 'Lax',
        ]);
    }
}

/* $salvaSulProfilo = false quando il cambio arriva da un indirizzo (?lang=en):
   una GET non deve mai modificare il profilo, altrimenti basta un collegamento
   preparato da altri per cambiare le impostazioni a chi ci clicca. Dal modulo
   POST delle impostazioni, invece, si salva. */
function standout_imposta_lingua(string $codice, bool $salvaSulProfilo = false): bool
{
    if (!standout_lingua_valida($codice)) return false;

    /* Solo a sessione attiva: scriverci prima di session_start() non da' errore,
       riempie un array che verra' semplicemente sostituito. */
    if (session_status() === PHP_SESSION_ACTIVE) $_SESSION['lingua'] = $codice;

    standout_scrivi_cookie_lingua($codice);

    $cache =& standout_lingua_cache();
    $cache = $codice;

    if ($salvaSulProfilo && !empty($_SESSION['user_id']) && function_exists('standout_db')) {
        $conn = standout_db();
        if ($conn) {
            $uid = (int)$_SESSION['user_id'];
            $stmt = mysqli_prepare($conn, "UPDATE users SET lang = ? WHERE id = ?");
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'si', $codice, $uid);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }
        }
    }
    return true;
}


/* Subito dopo il login. La lingua salvata sul profilo vale su ogni
   dispositivo: se c'e', prende il posto di quella scelta da non connessi.
   Se il profilo non ne ha ancora una (utente nuovo o di prima che esistesse
   la scelta), ci si salva quella in uso adesso, cosi' chi ha scelto
   "English" nella pagina d'ingresso se la ritrova ovunque. */
function standout_lingua_dopo_login(int $uid): void
{
    if ($uid <= 0 || !function_exists('standout_db')) return;
    $conn = standout_db();
    if (!$conn) return;

    $stmt = mysqli_prepare($conn, "SELECT lang FROM users WHERE id = ?");
    if (!$stmt) return;
    mysqli_stmt_bind_param($stmt, 'i', $uid);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $lang);
    $trovato = mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);
    if (!$trovato) return;

    if (standout_lingua_valida($lang)) {
        standout_imposta_lingua($lang, false);
        return;
    }
    $attuale = standout_lingua();
    $stmt = mysqli_prepare($conn, "UPDATE users SET lang = ? WHERE id = ?");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'si', $attuale, $uid);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
}


/* ============================================================
   3. IL DIZIONARIO
   ============================================================ */

/* I file stanno in lang/, uno per lingua, e sono JSON e non PHP apposta: quando
   l'app in React Native leggera' le stesse traduzioni dovra' poterli aprire
   senza passare da un interprete PHP. */
function standout_dizionario(string $lingua): array
{
    static $caricati = [];
    if (isset($caricati[$lingua])) return $caricati[$lingua];

    $percorso = __DIR__ . '/../lang/' . $lingua . '.json';
    if (!is_readable($percorso)) return $caricati[$lingua] = [];

    $dati = json_decode(file_get_contents($percorso), true);
    return $caricati[$lingua] = is_array($dati) ? $dati : [];
}

/* Cerca una chiave a punti ("pomodoro.avvia") dentro l'array annidato.
   null se non c'e': serve a distinguere "manca" da "c'e' ma e' vuota". */
function standout_cerca_chiave(array $dizionario, string $chiave): ?string
{
    $nodo = $dizionario;
    foreach (explode('.', $chiave) as $pezzo) {
        if (!is_array($nodo) || !array_key_exists($pezzo, $nodo)) return null;
        $nodo = $nodo[$pezzo];
    }
    return is_string($nodo) ? $nodo : null;
}


/* ============================================================
   4. LE FUNZIONI CHE SI USANO NELLE PAGINE
   ============================================================ */

/* t('pomodoro.avvia')                    -> "Avvia"
   t('pomodoro.pausa_di', ['minuti' => 5]) -> sostituisce {minuti}
 *
 * Se la traduzione manca si ripiega sull'italiano; se manca anche quella
 * restituisce la chiave fra parentesi uncinate. Volutamente brutto: una scritta
 * come <<pomodoro.avvia>> si nota subito, mentre una stringa vuota passerebbe
 * inosservata per mesi. */
function t(string $chiave, array $sostituzioni = []): string
{
    $testo = standout_cerca_chiave(standout_dizionario(standout_lingua()), $chiave);

    if ($testo === null && standout_lingua() !== STANDOUT_LINGUA_BASE) {
        $testo = standout_cerca_chiave(standout_dizionario(STANDOUT_LINGUA_BASE), $chiave);
    }
    if ($testo === null) return '<<' . $chiave . '>>';

    foreach ($sostituzioni as $nome => $valore) {
        $testo = str_replace('{' . $nome . '}', (string)$valore, $testo);
    }
    return $testo;
}

/* Da usare dentro l'HTML: traduce E mette in sicurezza in un colpo solo.
   htmlspecialchars diretto e non sec_h() perche' questo file e' incluso prima
   di security_core.php. */
function te(string $chiave, array $sostituzioni = []): void
{
    echo htmlspecialchars(t($chiave, $sostituzioni), ENT_QUOTES, 'UTF-8');
}

/* Come te(), ma per le frasi che contengono un pezzo di HTML in mezzo - di
 * solito un contatore dentro uno <span>.
 *
 * Perche' serve: te() mette in sicurezza tutto il risultato, quindi uno <span>
 * passato come sostituzione verrebbe stampato come testo invece che disegnato.
 * Qui si mette in sicurezza PRIMA la frase tradotta, e solo dopo si inseriscono
 * i frammenti - che devono quindi essere HTML scritto da noi, mai roba
 * arrivata dall'utente.
 *
 * Serve a non spezzare le frasi in due chiavi: "Esami nel gruppo (" + "selezionati)"
 * sarebbe intraducibile, perche' in altre lingue il numero non sta li' in mezzo. */
function th(string $chiave, array $frammenti = []): void
{
    $testo = htmlspecialchars(t($chiave), ENT_QUOTES, 'UTF-8');
    foreach ($frammenti as $nome => $html) {
        $testo = str_replace('{' . $nome . '}', $html, $testo);
    }
    echo $testo;
}

/* Le stringhe che servono al JavaScript della pagina.
 *
 * Escono come <script type="application/json">: e' un contenitore di DATI, non
 * codice, quindi la CSP non lo blocca nemmeno il giorno che toglieremo
 * 'unsafe-inline'. Un <script> normale con dentro un oggetto invece lo sarebbe.
 *
 * Si passano solo i gruppi che servono - standout_i18n_json(['comune','pomodoro'])
 * - e non tutto il dizionario: le stringhe saranno migliaia, spedirle tutte a
 * ogni pagina sarebbe peso inutile su ogni caricamento. */
function standout_i18n_json(array $spazi): string
{
    $dizionario = standout_dizionario(standout_lingua());
    $base = standout_dizionario(STANDOUT_LINGUA_BASE);

    /* Una pagina puo' chiamarla piu' volte: la sua e quella di ogni
       componente che include (la lista delle attivita' dentro il planner,
       il diario del giorno, il selettore dei progetti...). Ogni componente
       chiede i SUOI gruppi, cosi' funziona in qualunque pagina finisca.
       La prima chiamata stampa anche T(); le successive solo i gruppi che
       mancano, in un altro contenitore che T() legge quando non trova una
       chiave. */
    static $stampati = [];
    $prima = !$stampati;
    $spazi = array_values(array_diff(array_unique($spazi), $stampati));
    if (!$prima && !$spazi) return '';
    $stampati = array_merge($stampati, $spazi);

    $fuori = [];
    foreach ($spazi as $spazio) {
        $tradotto  = isset($dizionario[$spazio]) && is_array($dizionario[$spazio]) ? $dizionario[$spazio] : [];
        $originale = isset($base[$spazio]) && is_array($base[$spazio]) ? $base[$spazio] : [];
        // + conserva le chiavi di sinistra: le traduzioni vincono, l'italiano
        // tappa i buchi. Stesso ripiego che fa t().
        $fuori[$spazio] = $tradotto + $originale;
    }

    /* RETE DI SICUREZZA, e non e' teorica: se i18n.js non si carica (non e'
       stato caricato sul server, un 404, la cache di un proxy) la funzione T()
       non esiste. Le pagine la chiamano al primo rigo del loro script - per
       esempio per i nomi dei mesi - quindi il blocco muore con un
       ReferenceError e la pagina resta vuota: grafici bianchi e numeri a zero,
       senza nessun errore visibile a parte la console.
       E' successo davvero su stats_fc.php.

       Il dizionario pero' e' GIA' dentro la pagina, qui sopra: basta una T()
       minima definita prima, e la pagina funziona comunque. i18n.js, quando
       arriva, la sostituisce con quella completa. */
    $json = json_encode($fuori, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
    if (!$prima) {
        return '<script type="application/json" class="i18n-dati">' . $json . '</script>' . "\n";
    }

    $minima = <<<'JS'
<script>
if (typeof window.T !== 'function') {
    window.T = function (chiave, sostituzioni) {
        var dati = {};
        var cc = document.querySelectorAll('#i18n-dati, script.i18n-dati');
        for (var k = 0; k < cc.length; k++) {
            try {
                var d = JSON.parse(cc[k].textContent) || {};
                for (var g in d) if (Object.prototype.hasOwnProperty.call(d, g)) dati[g] = d[g];
            } catch (e) {}
        }
        var nodo = dati, pezzi = String(chiave).split('.');
        for (var i = 0; i < pezzi.length; i++) {
            if (nodo === null || typeof nodo !== 'object' || !(pezzi[i] in nodo)) return '<<' + chiave + '>>';
            nodo = nodo[pezzi[i]];
        }
        if (typeof nodo !== 'string') return '<<' + chiave + '>>';
        if (sostituzioni) {
            for (var nome in sostituzioni) {
                if (Object.prototype.hasOwnProperty.call(sostituzioni, nome)) {
                    nodo = nodo.split('{' + nome + '}').join(sostituzioni[nome]);
                }
            }
        }
        return nodo;
    };
}
</script>
JS;

    return '<script type="application/json" id="i18n-dati">'
        . $json
        . '</script>' . "\n"
        . $minima . "\n"
        /* Senza defer, e va messo nel <head>: i blocchi <script> delle pagine
           non sono differiti e girano mentre la pagina si costruisce, quindi
           T() deve gia' esistere quando li incontra. */
        . '<script src="' . (defined('PWA_BASE') ? PWA_BASE : '/') . '_assets/js/i18n.js"></script>' . "\n";
}


/* ============================================================
   5. ?lang= NELL'INDIRIZZO
   ============================================================
   Qui si fa solo la meta' che si PUO' fare adesso: il cookie, che ha bisogno
   delle intestazioni ancora aperte. La sessione la scrive standout_lingua() alla
   prima chiamata, quando session_start() e' gia' avvenuto - vedi la nota in
   cima al file. */
if (isset($_GET['lang']) && standout_lingua_valida($_GET['lang'])) {
    standout_lingua_richiesta($_GET['lang']);
    standout_scrivi_cookie_lingua($_GET['lang']);
}


/* ============================================================
   6. NOMI DI PRIMA
   ============================================================
   Un config.php scritto prima del cambio di nome chiama ancora
   nisama_lingua(): senza questo alias ogni pagina si fermerebbe con un
   errore fatale. */
if (!function_exists('nisama_lingua')) {
    function nisama_lingua(): string { return standout_lingua(); }
}
