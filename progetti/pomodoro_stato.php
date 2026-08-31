<?php
/* =====================================================================
   NISAMA — LO STATO DEL POMODORO
   Un file a se' perche' la stessa lettura serve a tre posti: la pagina
   pomodoro quando si apre, la risposta a ?ajax=stato che il motore
   interroga, e la barra in cima a tutte le altre pagine. Tre copie
   della stessa query, prima o poi, non dicono piu' la stessa cosa.

   Si appoggia a security_core.php (sec_q, sec_one, sec_all): va incluso
   dopo, non prima.
   ===================================================================== */

/* Il tetto di un pomodoro: dieci ore. Oltre non e' piu' una sessione di
   studio, e' una pagina rimasta aperta. Sta qui in cima perche' serve sia al
   blocco ajax sia alla pulizia in fondo. */
const POMO_LIMITE_SECONDI = 36000;

/* ==========================================================================
   STATO ATTUALE
   ========================================================================== */
function pomo_color($hex) { 
    $hex = trim($hex ?? '');
    return (!empty($hex) && (preg_match('/^#([0-9a-fA-F]{3,8})$/', $hex) || strpos($hex, 'rgb') === 0)) ? $hex : ''; 
}

/* ==========================================================================
   LO STATO DEL POMODORO — UN POSTO SOLO
   Serve a tre padroni: la pagina quando si apre, la risposta a ?ajax=stato
   che il motore interroga ogni minuto, e la risposta a un'azione rifiutata.
   Scritta una volta sola perche' tre copie della stessa lettura, prima o
   poi, non dicono piu' la stessa cosa.

   POMODORO LASCIATI APERTI
   Un pomodoro dimenticato acceso (telefono chiuso, scheda persa) restava "in
   corso" per giorni: riaprendo la pagina lo si ritrovava vivo, con la
   possibilita' di aggiungere alla sessione tutto il tempo passato. Qui si
   taglia, PRIMA di leggere lo stato attuale, cosi' la pagina si apre pulita.

   La durata salvata e' la piu' piccola fra il tempo davvero trascorso e la
   durata prevista: un pomodoro da 50 minuti dimenticato per tre giorni vale
   50 minuti, e uno messo in pausa dopo 12 vale 12. Il cronometro non ha una
   durata prevista, quindi il suo tetto e' il limite stesso.

   Il calcolo si fa qui e non dentro l'UPDATE apposta: in SQL avrebbe dovuto
   rileggere la colonna appena scritta nella stessa istruzione, che funziona
   ma dipende dall'ordine di valutazione ed e' illeggibile fra sei mesi.
   ========================================================================== */
function pomo_leggi_stato(int $uid): array
{
    foreach (sec_all("SELECT id, start_time, resume_time, status, duration_seconds, planned_duration_min
                      FROM pomodoro_sessions
                      WHERE user_id = ? AND end_time IS NULL
                        AND TIMESTAMPDIFF(SECOND, start_time, NOW()) > ?",
                     [$uid, POMO_LIMITE_SECONDI]) as $ab) {
        $accumulati = (int)($ab['duration_seconds'] ?? 0);
        // in pausa il segmento corrente non scorre: conta solo quello gia' accumulato
        $segmento = ($ab['status'] === 'pausa')
            ? 0
            : max(0, time() - strtotime($ab['resume_time'] ?: $ab['start_time']));
        $tetto  = $ab['planned_duration_min'] !== null ? ((int)$ab['planned_duration_min']) * 60 : POMO_LIMITE_SECONDI;
        $durata = min($accumulati + $segmento, $tetto);

        /* is_manual = 2, non 1. Sono tre cose diverse e meritano tre codici:
             0 = misurata dal timer, gli orari sono un fatto
             1 = scritta a mano dall'utente, gli orari li ha decisi lui
             2 = chiusa d'ufficio da qui, gli orari sono una nostra stima
           Con 1 non si sarebbe piu' potuto distinguere una sessione che l'utente
           ha scritto da una che abbiamo ricostruito noi — e serve distinguerle:
           la 2 va mostrata come "automatica" e ha diritto all'avviso, la 1 no.
           Correggendola a mano diventa 1: da quel momento gli orari sono suoi. */
        sec_q("UPDATE pomodoro_sessions
               SET duration_seconds = ?, end_time = ?, status = 'abbandonato', resume_time = NULL, is_manual = 2
               WHERE id = ? AND user_id = ?",
              [$durata, date('Y-m-d H:i:s', strtotime($ab['start_time']) + $durata),
               (int)$ab['id'], $uid]);
    }

    /* Anche una pausa puo' restare aperta: non c'e' niente da salvare, si butta. */
    sec_q("DELETE FROM pomodoro_current_breaks WHERE user_id = ? AND TIMESTAMPDIFF(SECOND, start_time, NOW()) > ?",
          [$uid, POMO_LIMITE_SECONDI]);

    /* La piu' recente chiusa d'ufficio e non ancora segnalata: e' quella di cui
       parla l'avviso in fondo alla pagina. */
    $avvisoAbbandono = sec_one("SELECT id, start_time, end_time, duration_seconds
                                FROM pomodoro_sessions
                                WHERE user_id = ? AND status = 'abbandonato'
                                ORDER BY id DESC LIMIT 1", [$uid]);

    $activeSession = sec_one("SELECT s.*, p.name AS pn, p.color AS pcolor FROM pomodoro_sessions s LEFT JOIN projects p ON p.id=s.project_id AND p.user_id=s.user_id WHERE s.user_id=? AND s.end_time IS NULL ORDER BY s.id DESC LIMIT 1",
                             [$uid]);

    /* La colonna e' project_id, non progetto_id: il resto del file (l'INSERT in
       avvia_pausa) scrive gia' project_id. Questo JOIN era rimasto con il nome
       vecchio, e da quando le query passano da sec_q l'errore non e' piu'
       zittito come faceva mysqli_query: la pagina moriva subito con
       "Errore nella richiesta al database". */
    $pausaAttiva = sec_one("SELECT pc.*, p.name AS pn, p.color AS pcolor FROM pomodoro_current_breaks pc LEFT JOIN projects p ON p.id=pc.project_id AND p.user_id=pc.user_id WHERE pc.user_id=?",
                           [$uid]);

    $lastSession = null;
    if (!$activeSession && !$pausaAttiva) {
        $lastSession = sec_one("SELECT s.*, p.name AS pn, p.color AS pcolor FROM pomodoro_sessions s LEFT JOIN projects p ON p.id=s.project_id AND p.user_id=s.user_id WHERE s.user_id=? ORDER BY s.id DESC LIMIT 1",
                               [$uid]);
        if ($lastSession && $lastSession['status'] !== 'completato') { $lastSession = null; }
    }

    /* Le durate scelte l'ultima volta (e lo stile del quadrante). Mancavano
       qui: le prendeva solo pomodoro.php per conto suo, in un pezzo di
       bootstrap che la barra non vede — cosi' un pomodoro o una pausa
       avviati dal popup delle altre pagine partivano sempre con 25 e 5
       minuti, i valori di fabbrica, invece di quelli salvati. Letta qui
       vale per chiunque chiami questa funzione, pagina pomodoro compresa. */
    $imp = sec_one("SELECT * FROM pomodoro_settings WHERE user_id=?", [$uid]);
    if (!$imp) {
        $imp = ['last_mode' => 0, 'last_pomodoro_duration_min' => 25, 'last_break_duration_min' => 5, 'last_display_style' => 'cerchio'];
    }

    $stato = [
        'active' => $activeSession ? [
            'id' => (int)$activeSession['id'], 'mode' => (int)$activeSession['mode'],
            'data_inizio' => $activeSession['start_time'], 'durata_prevista_minuti' => $activeSession['planned_duration_min'] !== null ? (int)$activeSession['planned_duration_min'] : null,
            'project_id' => $activeSession['project_id'] !== null ? (int)$activeSession['project_id'] : 0,
            'progetto_nome' => $activeSession['pn'], 'progetto_colore' => pomo_color($activeSession['pcolor']),
            'esito' => $activeSession['status'], 'durata_secondi' => (int)($activeSession['duration_seconds'] ?? 0),
            'data_ripresa' => $activeSession['resume_time'],
        ] : null,
        /* 'data_inizio' e non 'ora_inizio', ed e' la correzione di un guasto
           serio: il JavaScript ha sempre letto data_inizio, qui invece la
           chiave si chiamava ora_inizio. Con una pausa aperta l'idratazione
           moriva sulla prima riga (undefined.replace), quindi il quadrante
           restava sul 25:00 scritto a mano nell'HTML e la pagina sembrava
           semplicemente "a riposo" pur avendo una pausa in corso. */
        'pausa' => $pausaAttiva ? [
            'data_inizio' => $pausaAttiva['start_time'], 'durata_prevista_minuti' => (int)$pausaAttiva['planned_duration_min'],
            'project_id' => $pausaAttiva['project_id'] !== null ? (int)$pausaAttiva['project_id'] : 0,
            'progetto_nome' => $pausaAttiva['pn'], 'progetto_colore' => pomo_color($pausaAttiva['pcolor']),
        ] : null,
        'last' => $lastSession ? [
            'id' => (int)$lastSession['id'], 'mode' => (int)$lastSession['mode'], 'esito' => $lastSession['status'],
            'data_inizio' => $lastSession['start_time'], 'data_fine' => $lastSession['end_time'],
            'durata_prevista_minuti' => $lastSession['planned_duration_min'] !== null ? (int)$lastSession['planned_duration_min'] : null,
            'project_id' => $lastSession['project_id'] !== null ? (int)$lastSession['project_id'] : 0,
            'progetto_nome' => $lastSession['pn'], 'progetto_colore' => pomo_color($lastSession['pcolor']),
            'durata_secondi' => (int)($lastSession['duration_seconds'] ?? 0)
        ] : null,
        /* L'avviso del pomodoro chiuso d'ufficio. Le stringhe le compone il JS,
           che sa gia' in che lingua siamo: qui viaggiano solo i dati grezzi. */
        'avviso' => $avvisoAbbandono ? [
            'id' => (int)$avvisoAbbandono['id'],
            'data_inizio' => $avvisoAbbandono['start_time'],
            'durata_secondi' => (int)($avvisoAbbandono['duration_seconds'] ?? 0),
        ] : null,
        'settings' => [
            'ultima_modalita' => (int)$imp['last_mode'],
            'ultima_durata_pomodoro_min' => (int)$imp['last_pomodoro_duration_min'],
            'ultima_durata_pausa_min' => (int)$imp['last_break_duration_min'],
            'stile' => $imp['last_display_style'],
        ],
    ];

    /* L'impronta dello stato. Il controllo periodico confronta questa stringa
       con quella che aveva: se e' identica non c'e' niente da ridisegnare, e
       il giro al minuto costa una query e nient'altro. L'avviso resta fuori
       apposta: chiuderlo non e' un cambiamento del pomodoro. */
    $stato['revisione'] = md5(json_encode([$stato['active'], $stato['pausa'], $stato['last']]));

    return $stato;
}

