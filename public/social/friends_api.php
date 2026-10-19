<?php
/* =========================================================================
   STANDOUT · SEZIONE AMICI — endpoint AJAX
   Risponde sempre in JSON. Ogni azione è verificata lato server:
   il client non può mai imporre chi è, né leggere i dati di un non-amico.
   ========================================================================= */

require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/auth_check.php';      // stessa protezione di stats_fc.php
require_once __DIR__ . '/friends_core.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function fr_out($dati) { echo json_encode($dati, JSON_UNESCAPED_UNICODE); exit; }
function fr_ko(string $msg, int $code = 400) { http_response_code($code); fr_out(['ok' => false, 'errore' => $msg]); }

/* Le chiamate arrivano solo dal nostro JS (che manda questo header):
   un form ostile su un altro sito non può aggiungerlo senza preflight. */
if (empty($_SERVER['HTTP_X_STANDOUT'])) { fr_ko(t('amici.e_richiesta'), 403); }

$me = fr_utente_corrente();
if (!$me) { fr_ko(t('amici.e_sessione'), 401); }
$me = (int)$me['id'];

$c      = fr_conn();
$azione = isset($_POST['azione']) ? (string)$_POST['azione'] : '';
$uid    = isset($_POST['uid'])    ? (int)$_POST['uid']       : 0;

switch ($azione) {

    /* ---------------------------------------------------- CERCA PERSONE */
    case 'cerca': {
        $q = isset($_POST['q']) ? trim((string)$_POST['q']) : '';
        if ($q === '') { fr_out(['ok' => true, 'risultati' => []]); }
        $qs = mysqli_real_escape_string($c, $q);

        $amici = fr_amici_ids($c, $me);
        $inv = []; $ric = [];
        $r = mysqli_query($c, "SELECT from_user_id, to_user_id FROM friends_notifications
                               WHERE is_accepted =0 AND (from_user_id = $me OR to_user_id = $me)");
        while ($r && ($row = mysqli_fetch_assoc($r))) {
            if ((int)$row['from_user_id'] === $me) { $inv[] = (int)$row['to_user_id']; }
            else { $ric[] = (int)$row['from_user_id']; }
        }

        $out = [];
        $r = mysqli_query($c, "SELECT id, username FROM users
                               WHERE is_active = 1 AND id <> $me AND username LIKE '%$qs%'
                               ORDER BY username ASC LIMIT 12");
        while ($r && ($row = mysqli_fetch_assoc($r))) {
            $id = (int)$row['id'];
            if (in_array($id, $amici, true))    { $stato = 'amico'; }
            elseif (in_array($id, $inv, true))  { $stato = 'inviata'; }
            elseif (in_array($id, $ric, true))  { $stato = 'ricevuta'; }
            else                                { $stato = 'nessuno'; }
            $out[] = [
                'uid'       => $id,
                'username'  => $row['username'],
                'iniziale'  => fr_iniziale($row['username']),
                'gradiente' => fr_gradiente($id),
                'stato'     => $stato,
            ];
        }
        fr_out(['ok' => true, 'risultati' => $out]);
    }

    /* ------------------------------------------------- INVIA RICHIESTA */
    case 'richiedi': {
        if ($uid <= 0 || $uid === $me)      { fr_ko(t('amici.e_utente_non_valido')); }
        $r = mysqli_query($c, "SELECT username FROM users WHERE id = $uid AND is_active = 1");
        if (!$r || !mysqli_num_rows($r))    { fr_ko(t('amici.e_utente_non_trovato')); }
        if (fr_sono_amici($c, $me, $uid))   { fr_ko(t('amici.e_gia_amici')); }

        // Se lui aveva già chiesto a me, la richiesta si chiude subito: amici.
        $r = mysqli_query($c, "SELECT 1 FROM friends_notifications
                               WHERE from_user_id = $uid AND to_user_id = $me AND is_accepted =0 LIMIT 1");
        if ($r && mysqli_num_rows($r)) {
            [$a, $b] = fr_coppia($me, $uid);
            mysqli_query($c, "INSERT IGNORE INTO friends (user_id_a, user_id_b) VALUES ($a, $b)");
            mysqli_query($c, "DELETE FROM friends_notifications
                              WHERE from_user_id = $uid AND to_user_id = $me AND is_accepted =0");
            mysqli_query($c, "INSERT INTO friends_notifications (from_user_id, to_user_id, is_accepted, is_read)
                              VALUES ($me, $uid, 1, 0)
                              ON DUPLICATE KEY UPDATE is_read = 0, created_at = NOW()");
            fr_out(['ok' => true, 'stato' => 'amico', 'messaggio' => t('amici.m_a_vicenda')]);
        }

        mysqli_query($c, "INSERT IGNORE INTO friends_notifications (from_user_id, to_user_id, is_accepted)
                          VALUES ($me, $uid, 0)");
        fr_out(['ok' => true, 'stato' => 'inviata', 'messaggio' => t('amici.m_inviata')]);
    }

    /* -------------------------------------------------------- ACCETTA */
    case 'accetta': {
        if ($uid <= 0) { fr_ko(t('amici.e_utente_non_valido')); }
        $r = mysqli_query($c, "SELECT 1 FROM friends_notifications
                               WHERE from_user_id = $uid AND to_user_id = $me AND is_accepted =0 LIMIT 1");
        if (!$r || !mysqli_num_rows($r)) { fr_ko(t('amici.e_non_esiste')); }

        [$a, $b] = fr_coppia($me, $uid);
        mysqli_query($c, "INSERT IGNORE INTO friends (user_id_a, user_id_b) VALUES ($a, $b)");
        mysqli_query($c, "DELETE FROM friends_notifications
                          WHERE from_user_id = $uid AND to_user_id = $me AND is_accepted =0");
        mysqli_query($c, "INSERT INTO friends_notifications (from_user_id, to_user_id, is_accepted, is_read)
                          VALUES ($me, $uid, 1, 0)
                          ON DUPLICATE KEY UPDATE is_read = 0, created_at = NOW()");

        $u = fr_utenti($c, [$uid]);
        fr_out(['ok' => true, 'messaggio' => t('amici.m_ora_amico', ['nome' => $u[$uid]['username'] ?? '']),
                'n' => fr_conta_notifiche($c, $me)]);
    }

    /* --------------------------------------------------------- RIFIUTA */
    case 'rifiuta': {
        if ($uid <= 0) { fr_ko(t('amici.e_utente_non_valido')); }
        // Nessuna notifica a chi ha chiesto: qui non si umilia nessuno.
        mysqli_query($c, "DELETE FROM friends_notifications
                          WHERE from_user_id = $uid AND to_user_id = $me AND is_accepted =0");
        fr_out(['ok' => true, 'messaggio' => t('amici.m_rifiutata'), 'n' => fr_conta_notifiche($c, $me)]);
    }

    /* --------------------------------------- ANNULLA RICHIESTA INVIATA */
    case 'annulla': {
        if ($uid <= 0) { fr_ko(t('amici.e_utente_non_valido')); }
        mysqli_query($c, "DELETE FROM friends_notifications
                          WHERE from_user_id = $me AND to_user_id = $uid AND is_accepted =0");
        fr_out(['ok' => true, 'messaggio' => t('amici.m_annullata'), 'n' => fr_conta_notifiche($c, $me)]);
    }

    /* ---------------------------------------------------- TOGLI AMICO */
    case 'rimuovi': {
        if ($uid <= 0) { fr_ko(t('amici.e_utente_non_valido')); }
        [$a, $b] = fr_coppia($me, $uid);
        mysqli_query($c, "DELETE FROM friends WHERE user_id_a = $a AND user_id_b = $b");
        mysqli_query($c, "DELETE FROM friends_notifications
                          WHERE (from_user_id = $me AND to_user_id = $uid)
                             OR (from_user_id = $uid AND to_user_id = $me)");
        fr_out(['ok' => true, 'messaggio' => t('amici.m_rimossa'), 'n' => fr_conta_notifiche($c, $me)]);
    }

    /* ------------------------------------- SEGNA LE NOTIFICHE COME LETTE */
    case 'leggi': {
        mysqli_query($c, "UPDATE friends_notifications SET is_read = 1
                          WHERE to_user_id = $me AND is_accepted =1 AND is_read = 0");
        fr_out(['ok' => true, 'n' => fr_conta_notifiche($c, $me)]);
    }

    /* ------------------------------- ELENCO NOTIFICHE (usato dal widget) */
    case 'notifiche': {
        $items = fr_notifiche($c, $me);
        foreach ($items as &$i) {
            $i['iniziale']  = fr_iniziale($i['username']);
            $i['gradiente'] = fr_gradiente((int)$i['uid']);
            $i['quando']    = fr_quando((string)$i['quando']);
        }
        unset($i);
        fr_out(['ok' => true, 'n' => fr_conta_notifiche($c, $me), 'items' => $items]);
    }

    /* --------------------- STATISTICHE DI UN AMICO (solo se è davvero amico) */
    case 'stats': {
        if ($uid <= 0) { fr_ko(t('amici.e_utente_non_valido')); }
        if ($uid !== $me && !fr_sono_amici($c, $me, $uid)) { fr_ko(t('amici.e_non_amici'), 403); }
        fr_out(['ok' => true, 'stats' => fr_stats($c, $uid)]);
    }

    /* ------------------------------------------------ CLASSIFICA GLOBALE */
    case 'classifica': {
        $p = isset($_POST['periodo']) ? (string)$_POST['periodo'] : 'oggi';
        if (!in_array($p, ['oggi', 'settimana', 'mese'], true)) { $p = 'oggi'; }
        fr_out([
            'ok'        => true,
            'periodo'   => $p,
            'righe'     => fr_classifica($c, $p),
            'in_focus'  => fr_quanti_in_focus($c),
        ]);
    }
}

fr_ko(t('amici.e_azione'));
