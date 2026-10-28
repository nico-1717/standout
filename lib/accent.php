<?php
/* =====================================================================
   STANDOUT — COLORE DELL'ACCOUNT (accento)
   Chi e' connesso sceglie dal pannello del profilo (avatar, riga "Stile")
   il colore che il sito usa al posto del blu: pulsanti, link, aloni.
   Il blu resta il colore di partenza: finche' non si sceglie altro, la
   pagina non riceve NIENTE in piu' (nemmeno un byte).

   DOVE VIVE LA SCELTA (cookie + copia nel database):
     cookie  nisama_accento = "<id utente>:<colore>", un anno. Si legge senza
             nessuna query: e' il motivo per cui le pagine non rallentano.
     users.ui_accent  la copia sul profilo: serve a un telefono nuovo, a un
             altro browser, a chi ha cancellato i cookie. Se nel cookie non c'e'
             niente di valido per QUESTO utente, la si legge una volta e si
             rifa' il cookie.
     sessione  stessa idea di lib/stile.php: letta una volta per sessione.
   Il cookie porta anche l'id utente: su un computer condiviso, chi entra dopo
   non eredita il colore di chi era entrato prima (e senza toccare il login).

   COME SI APPLICA: accento_css() produce un piccolo <style> che ridefinisce i
   token del blu (--blue, --blue-soft, ...). pwa_head_html() lo mette nel <head>
   di ogni pagina, con il nonce della CSP. Le pagine non vanno toccate: leggono
   gia' var(--blue). Il selettore html:root:root pesa piu' del :root di ogni
   foglio, quindi vince qualunque sia l'ordine di caricamento.

   Non vale per chi non e' connesso: la pagina d'ingresso (landing) e il login
   restano esattamente come sono.

   Sicurezza: il colore e' scelto da una lista chiusa (STANDOUT_ACCENTI). Il
   valore del cookie o dell'API che non e' in lista viene ignorato, quindi nel
   CSS non finisce mai testo scelto da un utente.
   ===================================================================== */
if (defined('STANDOUT_ACCENTO')) return;
define('STANDOUT_ACCENTO', true);

const STANDOUT_ACCENTO_BASE = 'blue';
const STANDOUT_ACCENTO_COOKIE = 'nisama_accento';

/* La tavolozza. 'l' = valore in tema chiaro, 'd' = in tema scuro.
   Scelti perche' il testo bianco sopra 'l' arrivi a 4.5:1 (il blu di partenza
   si ferma a 4.0) e perche' 'd' resti leggibile come testo sul fondo scuro.
   Cambiare un colore qui lo cambia in tutto il sito. */
const STANDOUT_ACCENTI = [
    'blue'   => ['l' => '#007AFF', 'd' => '#0A84FF'],
    'indigo' => ['l' => '#5856D6', 'd' => '#7D7AFF'],
    'violet' => ['l' => '#8E44C8', 'd' => '#BF5AF2'],
    'teal'   => ['l' => '#0B7A75', 'd' => '#25B3A7'],
    'coral'  => ['l' => '#C2410C', 'd' => '#FF7F45'],
    'rose'   => ['l' => '#C2255C', 'd' => '#FF5C8A'],
];

function standout_accento_valido($a): bool
{
    return is_string($a) && isset(STANDOUT_ACCENTI[$a]);
}

/* #RRGGBB -> "r,g,b" per costruire gli rgba() dei toni morbidi. */
function standout_accento_rgb(string $hex): string
{
    return hexdec(substr($hex, 1, 2)) . ',' . hexdec(substr($hex, 3, 2)) . ',' . hexdec(substr($hex, 5, 2));
}

/* Il colore dell'utente connesso (STANDOUT_ACCENTO_BASE se non ne ha scelto uno
   o se non e' connesso). Una volta per richiesta. */
function standout_accento(): string
{
    static $scelto = null;
    if ($scelto !== null) return $scelto;

    $uid = (int)($_SESSION['user_id'] ?? 0);
    if ($uid <= 0) return $scelto = STANDOUT_ACCENTO_BASE;

    /* 1. sessione */
    if (!empty($_SESSION['ui_accent']) && standout_accento_valido($_SESSION['ui_accent'])) {
        return $scelto = $_SESSION['ui_accent'];
    }

    /* 2. cookie, solo se e' di questo utente */
    $c = $_COOKIE[STANDOUT_ACCENTO_COOKIE] ?? '';
    if (is_string($c) && preg_match('/^(\d{1,10}):([a-z]{3,10})$/', $c, $m)
        && (int)$m[1] === $uid && standout_accento_valido($m[2])) {
        $scelto = $m[2];
    } else {
        /* 3. la copia sul profilo. try: finche' la colonna non esiste (migrazione
           non ancora eseguita) la pagina deve restare in piedi lo stesso. */
        $scelto = STANDOUT_ACCENTO_BASE;
        if (function_exists('standout_db')) {
            try {
                $conn = standout_db();
                $stmt = mysqli_prepare($conn, "SELECT ui_accent FROM users WHERE id = ?");
                if ($stmt) {
                    mysqli_stmt_bind_param($stmt, 'i', $uid);
                    mysqli_stmt_execute($stmt);
                    mysqli_stmt_bind_result($stmt, $valore);
                    if (mysqli_stmt_fetch($stmt) && standout_accento_valido($valore)) $scelto = $valore;
                    mysqli_stmt_close($stmt);
                }
            } catch (Throwable $e) {
                $scelto = STANDOUT_ACCENTO_BASE;
            }
        }
        standout_accento_scrivi_cookie($uid, $scelto);
    }

    if (session_status() === PHP_SESSION_ACTIVE) $_SESSION['ui_accent'] = $scelto;
    return $scelto;
}

/* Il cookie e basta. Un anno: e' una preferenza, non un dato di accesso.
   HttpOnly no: non contiene niente di segreto, e (come la lingua) puo' servire
   al JavaScript. SameSite=Lax come gli altri. */
function standout_accento_scrivi_cookie(int $uid, string $accento): void
{
    $_COOKIE[STANDOUT_ACCENTO_COOKIE] = $uid . ':' . $accento;
    if (headers_sent()) return;
    setcookie(STANDOUT_ACCENTO_COOKIE, $uid . ':' . $accento, [
        'expires'  => time() + 31536000,
        'path'     => '/',
        'secure'   => function_exists('sec_is_https') ? sec_is_https() : !empty($_SERVER['HTTPS']),
        'httponly' => false,
        'samesite' => 'Lax',
    ]);
}

/* Salva la scelta: sessione e cookie sempre, copia sul profilo se la colonna
   c'e'. Ritorna [ok, copia_db]: ok = la scelta vale da subito su questo
   browser; copia_db = e' stata salvata anche per gli altri dispositivi. */
function standout_imposta_accento(string $accento): array
{
    $uid = (int)($_SESSION['user_id'] ?? 0);
    if (!standout_accento_valido($accento) || $uid <= 0) return [false, false];

    $copia = false;
    if (function_exists('standout_db')) {
        try {
            $conn = standout_db();
            /* il blu e' "mai scelto": si salva NULL, come ui_style */
            $valore = $accento === STANDOUT_ACCENTO_BASE ? null : $accento;
            $stmt = mysqli_prepare($conn, "UPDATE users SET ui_accent = ? WHERE id = ?");
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'si', $valore, $uid);
                $copia = (bool)mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }
        } catch (Throwable $e) {
            $copia = false;
        }
    }

    if (session_status() === PHP_SESSION_ACTIVE) $_SESSION['ui_accent'] = $accento;
    standout_accento_scrivi_cookie($uid, $accento);
    return [true, $copia];
}

/* Il CSS che ridefinisce il blu. Stringa vuota per il blu di partenza.
   I toni morbidi ("soft", "wash") e gli "inchiostri" (la variante piu' scura/
   chiara per il testo piccolo) si ricavano dal colore con le stesse proporzioni
   che il sito usa per il blu: .09/.16, .14/.24, .07/.13.
   --sb-accent e' il colore della barra laterale, che e' scura in entrambi i
   temi: usa sempre il valore 'd'. */
function standout_accento_css(?string $accento = null): string
{
    $accento = $accento ?? standout_accento();
    if (!standout_accento_valido($accento) || $accento === STANDOUT_ACCENTO_BASE) return '';
    $l = STANDOUT_ACCENTI[$accento]['l'];
    $d = STANDOUT_ACCENTI[$accento]['d'];
    $lr = standout_accento_rgb($l);
    $dr = standout_accento_rgb($d);

    return 'html:root:root{'
        . "--blue:$l;--blue-soft:rgba($lr,.09);--blue-ink:color-mix(in srgb,$l 78%,#000);"
        . "--so-blue:$l;--so-blue-soft:rgba($lr,.14);--so-blue-wash:rgba($lr,.07);--so-blue-ink:color-mix(in srgb,$l 78%,#000);"
        . "--sb-accent:$d}"
        . '@media (prefers-color-scheme:dark){html:root:root{'
        . "--blue:$d;--blue-soft:rgba($dr,.16);--blue-ink:color-mix(in srgb,$d 72%,#fff);"
        . "--so-blue:$d;--so-blue-soft:rgba($dr,.24);--so-blue-wash:rgba($dr,.13);--so-blue-ink:color-mix(in srgb,$d 72%,#fff)}}";
}

/* Il <style> da mettere nel <head> (vuoto per il blu). Senza nonce la CSP lo
   rifiuterebbe: meglio niente colore che un errore in console. id="so-accento":
   il selettore del profilo lo trova e lo sostituisce quando si cambia colore. */
function standout_accento_head(): string
{
    if (!function_exists('sec_nonce')) return '';
    $css = standout_accento_css();
    if ($css === '') return '';
    return '<style id="so-accento" nonce="' . sec_nonce() . '">' . $css . "</style>\n";
}
