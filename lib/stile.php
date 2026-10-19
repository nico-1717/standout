<?php
/* =====================================================================
   STANDOUT — STILE DELLE PAGINE "DI CARTA"
   Diario, Planner, To do e To study hanno due vesti:
     standard  lo stile del resto del sito (sfondo grigio chiaro, carte
               bianche, carattere di sistema, blu iOS, modalita' scura)
     paper     la carta: toni caldi, serif, grafia a mano
   La scelta sta su users.ui_style (NULL = mai scelto = standard) e si
   cambia dal pannello del profilo (notifiche_widget.php).

   Come si applica: la pagina scrive standout_stile_attr() dentro <html>
   e, se lo stile e' standard, carica _assets/ui/stile_standard.css DOPO il
   suo foglio. Quel file non tocca le regole della pagina: ridefinisce i
   token (colori, caratteri) sotto html[data-stile="standard"]. La carta
   resta quindi identica a com'era, riga per riga.
   ===================================================================== */
if (defined('STANDOUT_STILE')) return;
define('STANDOUT_STILE', true);

const STANDOUT_STILI = ['standard', 'paper'];
const STANDOUT_STILE_BASE = 'standard';

function standout_stile_valido($s): bool
{
    return is_string($s) && in_array($s, STANDOUT_STILI, true);
}

/* Lo stile dell'utente connesso. Letto una volta e tenuto in sessione,
   come la lingua: non serve una query a ogni pagina. */
function standout_stile(): string
{
    static $scelto = null;
    if ($scelto !== null) return $scelto;

    if (!empty($_SESSION['ui_style']) && standout_stile_valido($_SESSION['ui_style'])) {
        return $scelto = $_SESSION['ui_style'];
    }

    $dalDb = null;
    $uid = (int)($_SESSION['user_id'] ?? 0);
    if ($uid > 0 && function_exists('standout_db')) {
        /* try: finche' la migrazione del 19/10/2026 non e' stata eseguita la
           colonna non esiste, e la pagina deve restare in piedi lo stesso. */
        try {
            $conn = standout_db();
            $stmt = mysqli_prepare($conn, "SELECT ui_style FROM users WHERE id = ?");
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'i', $uid);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_bind_result($stmt, $valore);
                if (mysqli_stmt_fetch($stmt) && standout_stile_valido($valore)) $dalDb = $valore;
                mysqli_stmt_close($stmt);
            }
        } catch (Throwable $e) {
            $dalDb = null;
        }
    }

    $scelto = $dalDb ?? STANDOUT_STILE_BASE;
    if (session_status() === PHP_SESSION_ACTIVE) $_SESSION['ui_style'] = $scelto;
    return $scelto;
}

/* Salva la scelta sul profilo. false se lo stile non esiste o se la
   colonna manca (migrazione non eseguita). */
function standout_imposta_stile(string $stile): bool
{
    if (!standout_stile_valido($stile)) return false;
    $uid = (int)($_SESSION['user_id'] ?? 0);
    if ($uid <= 0 || !function_exists('standout_db')) return false;
    try {
        $conn = standout_db();
        $stmt = mysqli_prepare($conn, "UPDATE users SET ui_style = ? WHERE id = ?");
        if (!$stmt) return false;
        mysqli_stmt_bind_param($stmt, 'si', $stile, $uid);
        $ok = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    } catch (Throwable $e) {
        return false;
    }
    if ($ok && session_status() === PHP_SESSION_ACTIVE) $_SESSION['ui_style'] = $stile;
    return (bool)$ok;
}

/* Da mettere dentro il tag <html> della pagina. */
function standout_stile_attr(): string
{
    return 'data-stile="' . htmlspecialchars(standout_stile(), ENT_QUOTES, 'UTF-8') . '"';
}

/* Il foglio dello stile standard, solo quando serve. Va stampato DOPO il
   foglio della pagina, perche' ne ridefinisce i token. */
function standout_stile_css(): string
{
    if (standout_stile() !== 'standard') return '';
    $file = __DIR__ . '/../public/_assets/ui/stile_standard.css';
    $v = @filemtime($file) ?: 1;
    return '<link rel="stylesheet" href="' . PWA_BASE . '_assets/ui/stile_standard.css?v=' . $v . '">';
}
