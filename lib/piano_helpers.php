<?php
/**
 * Helper per il sistema piani/abbonamenti. Da includere (dopo auth_check
 * o auth_check_api) in qualsiasi endpoint che consuma risorse a
 * consumo mensile: caratteri audio (TTS), immagini generate, token
 * testuali IA.
 *
 * Uso tipico in un endpoint il cui costo si conosce SOLO a chiamata già
 * avvenuta (es. audio TTS, dove i caratteri da sintetizzare sono noti prima):
 *
 *   if (!standout_verifica_limite($utente_corrente['id'], 'audio', $caratteri_audio)) {
 *       die(json_encode(['success' => false, 'error' => 'limite_piano', ...]));
 *   }
 *   // ... chiamata reale all'IA ...
 *   standout_incrementa_uso($utente_corrente['id'], 'audio', $caratteri_audio);
 *
 * Uso tipico in un endpoint testuale IA (Gemini) il cui costo in token si
 * scopre solo DOPO la chiamata: usiamo una stima al posto di un numero fisso.
 *
 *   $stima = standout_verifica_limite_stimato($utente_corrente['id']);
 *   if (!$stima['ok']) {
 *       die(json_encode(['success' => false, 'error' => 'limite_piano',
 *           'message' => "Ti servirebbero circa {$stima['stima']} token, ma non li hai più a disposizione.",
 *           'stima' => $stima['stima'], 'disponibili' => $stima['disponibili']]));
 *   }
 *   // ... chiamata reale a Gemini ...
 *   standout_incrementa_uso($utente_corrente['id'], 'token', $token_usati);
 */

require_once __DIR__ . '/auth_core.php';

/**
 * Se siamo entrati in un nuovo mese rispetto a reference_month:
 *  1) fonde nella colonna storica "historical_avg_tokens_per_call" la media
 *     token/chiamata del mese appena concluso (media tra il valore già
 *     salvato e quello nuovo, così la stima si affina mese dopo mese);
 *  2) azzera i 6 contatori mensili (3 utilizzi + 3 chiamate).
 */
function standout_reset_contatori_se_serve(int $user_id): void {
    $conn = standout_db();
    $meseCorrente = date('Y-m-01');

    $stmt = mysqli_prepare($conn, "SELECT reference_month, monthly_text_tokens, monthly_text_tokens_calls, historical_avg_tokens_per_call
        FROM user_plans WHERE user_id = ?");
    mysqli_stmt_bind_param($stmt, 'i', $user_id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);

    if (!$row || $row['reference_month'] === $meseCorrente) return; // già in regola, niente da fare

    // Il mese è cambiato: prima di azzerare, fondiamo la media del mese appena
    // concluso in quella storica (solo se l'utente ha fatto almeno una chiamata,
    // altrimenti non c'è nulla di nuovo da fondere).
    $nuova_storica = $row['historical_avg_tokens_per_call'] !== null ? (float)$row['historical_avg_tokens_per_call'] : null;
    $chiamate_mese_scorso = (int)$row['monthly_text_tokens_calls'];
    if ($chiamate_mese_scorso > 0) {
        $media_mese_scorso = ((int)$row['monthly_text_tokens']) / $chiamate_mese_scorso;
        $nuova_storica = ($nuova_storica === null) ? $media_mese_scorso : (($nuova_storica + $media_mese_scorso) / 2);
    }

    $stmt2 = mysqli_prepare($conn, "UPDATE user_plans
        SET monthly_audio_chars = 0, monthly_generated_images = 0, monthly_text_tokens = 0,
            monthly_audio_calls = 0, monthly_generated_images_calls = 0, monthly_text_tokens_calls = 0,
            historical_avg_tokens_per_call = ?, reference_month = ?
        WHERE user_id = ? AND reference_month <> ?");
    mysqli_stmt_bind_param($stmt2, 'dsis', $nuova_storica, $meseCorrente, $user_id, $meseCorrente);
    mysqli_stmt_execute($stmt2);
    mysqli_stmt_close($stmt2);
}

/** Piano + utilizzo corrente dell'utente (con reset automatico di mese già applicato). */
function standout_get_piano_utente(int $user_id): ?array {
    standout_reset_contatori_se_serve($user_id);
    $conn = standout_db();
    $stmt = mysqli_prepare($conn, "SELECT p.id AS piano_id, p.name, p.monthly_price,
               p.audio_chars_limit, p.images_limit, p.text_tokens_limit,
               up.monthly_audio_chars, up.monthly_generated_images, up.monthly_text_tokens,
               up.monthly_audio_calls, up.monthly_generated_images_calls, up.monthly_text_tokens_calls,
               up.historical_avg_tokens_per_call,
               up.free_trial_used, up.subscribed_at
        FROM user_plans up JOIN plans p ON p.id = up.piano_id
        WHERE up.user_id = ?");
    mysqli_stmt_bind_param($stmt, 'i', $user_id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
    return $row ?: null;
}

/**
 * true se l'utente può ancora consumare $quantita unità di $tipo questo mese.
 * $tipo: 'audio' | 'immagini' | 'token'
 */
function standout_verifica_limite(int $user_id, string $tipo, int $quantita = 1): bool {
    $piano = standout_get_piano_utente($user_id);
    if (!$piano) return true; // nessuna riga piano trovata: non blocchiamo, ma andrebbe controllata la configurazione

    switch ($tipo) {
        case 'audio':     $limite = $piano['audio_chars_limit'];  $usato = (int)$piano['monthly_audio_chars'];   break;
        case 'immagini':  $limite = $piano['images_limit'];       $usato = (int)$piano['monthly_generated_images']; break;
        case 'token':     $limite = $piano['text_tokens_limit'];  $usato = (int)$piano['monthly_text_tokens'];    break;
        default: return true;
    }

    if ($limite === null) return true; // NULL nel piano = illimitato
    return ($usato + $quantita) <= (int)$limite;
}

/** Aggiunge $quantita al contatore mensile di $tipo per l'utente, e incrementa di 1 il contatore di chiamate corrispondente. */
function standout_incrementa_uso(int $user_id, string $tipo, int $quantita): void {
    if ($quantita <= 0) return;
    standout_reset_contatori_se_serve($user_id);

    $colonne = [
        'audio'    => ['monthly_audio_chars', 'monthly_audio_calls'],
        'immagini' => ['monthly_generated_images', 'monthly_generated_images_calls'],
        'token'    => ['monthly_text_tokens', 'monthly_text_tokens_calls'],
    ];
    if (!isset($colonne[$tipo])) return;
    [$colonna, $colonna_chiamate] = $colonne[$tipo]; // whitelist fissa: mai da input utente, sicuro da interpolare nel nome colonna

    $conn = standout_db();
    $stmt = mysqli_prepare($conn, "UPDATE user_plans SET {$colonna} = {$colonna} + ?, {$colonna_chiamate} = {$colonna_chiamate} + 1 WHERE user_id = ?");
    mysqli_stmt_bind_param($stmt, 'ii', $quantita, $user_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

/**
 * Stima quanti token costerà mediamente una chiamata testuale IA dell'utente,
 * da usare PRIMA di interrogare Gemini (il costo reale si scopre solo a
 * chiamata avvenuta). Cascata dalla stima più precisa alla più generica:
 *   1) media personale del mese in corso, se l'utente ha già fatto >5 chiamate;
 *   2) media di tutti gli utenti con >5 chiamate questo mese;
 *   3) media storica personale dell'utente (colonna historical_avg_tokens_per_call,
 *      utile a inizio mese quando nessuno ha ancora accumulato abbastanza chiamate);
 *   4) valore fisso di riserva (default 300, quello usato finora "alla cieca").
 *
 * $piano, se passato, evita di rileggere il piano dal DB (lo fa già il chiamante).
 */
function standout_stima_token_per_chiamata(int $user_id, int $fallback = 300, ?array $piano = null): float {
    if ($piano === null) $piano = standout_get_piano_utente($user_id);

    // 1) Media personale di questo mese
    if ($piano && (int)$piano['monthly_text_tokens_calls'] > 5) {
        return ((int)$piano['monthly_text_tokens']) / ((int)$piano['monthly_text_tokens_calls']);
    }

    // 2) Media di tutti gli utenti con >5 chiamate questo mese
    $row = sec_one("SELECT AVG(monthly_text_tokens / monthly_text_tokens_calls) AS media
        FROM user_plans WHERE monthly_text_tokens_calls > 5");
    if ($row && $row['media'] !== null) {
        return (float)$row['media'];
    }

    // 3) Media storica personale (salvata l'ultima volta che i contatori si sono azzerati)
    if ($piano && $piano['historical_avg_tokens_per_call'] !== null) {
        return (float)$piano['historical_avg_tokens_per_call'];
    }

    // 4) Nessun dato disponibile da nessuna parte: fallback fisso
    return (float)$fallback;
}

/**
 * Come standout_verifica_limite() ma per le chiamate testuali IA: usa la stima
 * di standout_stima_token_per_chiamata() al posto di un numero fisso, e
 * restituisce anche i dettagli utili per costruire un messaggio d'errore
 * chiaro (quanti token servirebbero, quanti ne restano). Legge il piano UNA
 * sola volta (prima la funzione lo rileggeva dal DB fino a 3 volte per ogni
 * controllo, inutilmente).
 */
function standout_verifica_limite_stimato(int $user_id, int $fallback = 300): array {
    $piano = standout_get_piano_utente($user_id);
    $stima = (int)ceil(standout_stima_token_per_chiamata($user_id, $fallback, $piano));
    $limite = $piano ? $piano['text_tokens_limit'] : null;
    $usato = $piano ? (int)$piano['monthly_text_tokens'] : 0;
    $disponibili = ($limite === null) ? null : max(0, (int)$limite - $usato);
    $ok = ($limite === null) ? true : (($usato + $stima) <= (int)$limite);

    return [
        'ok' => $ok,
        'stima' => $stima,
        'disponibili' => $disponibili, // null = piano illimitato
        'limite' => $limite,
    ];
}

/**
 * Tempo medio storico (in secondi) e se l'ultima chiamata registrata è stata
 * molto più lenta del solito, per una data modalità (es. 'explain', 'metaphor',
 * 'example', 'shatter', 'trivia'). Utile per mostrare un timer stimato prima
 * di lanciare la chiamata a Gemini e per avvisare se le API sembrano intasate.
 */
function standout_get_stima_tempo_ia(string $mode): array {
    $row = sec_one("SELECT avg_duration_ms, samples, last_duration_ms FROM ai_perf_stats WHERE mode = ?", [$mode], 's');

    if (!$row || (int)$row['samples'] === 0) {
        return ['avg_seconds' => null, 'slower_than_usual' => false];
    }

    $avg = (int)$row['avg_duration_ms'];
    $last = $row['last_duration_ms'] !== null ? (int)$row['last_duration_ms'] : null;
    // Se l'ultima chiamata misurata è stata almeno il 60% più lenta della media storica, avvisiamo l'utente
    $slower = ($last !== null && $avg > 0 && $last > $avg * 1.6);

    return ['avg_seconds' => round($avg / 1000, 1), 'slower_than_usual' => $slower];
}

/** Registra la durata (ms) di una chiamata IA appena completata, aggiornando la media storica di quella modalità. */
function standout_registra_tempo_ia(string $mode, int $duration_ms): void {
    $conn = standout_db();
    $row = sec_one("SELECT avg_duration_ms, samples FROM ai_perf_stats WHERE mode = ?", [$mode], 's');

    if ($row) {
        $samples = (int)$row['samples'];
        $nuova_media = (int)round((((int)$row['avg_duration_ms']) * $samples + $duration_ms) / ($samples + 1));
        $stmt = mysqli_prepare($conn, "UPDATE ai_perf_stats SET avg_duration_ms = ?, samples = samples + 1, last_duration_ms = ?, last_measured_at = NOW() WHERE mode = ?");
        mysqli_stmt_bind_param($stmt, 'iis', $nuova_media, $duration_ms, $mode);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    } else {
        $stmt = mysqli_prepare($conn, "INSERT INTO ai_perf_stats (mode, avg_duration_ms, samples, last_duration_ms, last_measured_at) VALUES (?, ?, 1, ?, NOW())");
        mysqli_stmt_bind_param($stmt, 'sii', $mode, $duration_ms, $duration_ms);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
}