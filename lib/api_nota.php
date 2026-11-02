<?php
/* =====================================================================
   lib/api_nota.php — la nota veloce del To do per l'app (GET/PUT /api/v1/notes/quick)

   Cosa: il testo libero in cima al To do («appunti da smistare dopo»). Sul sito sta in todo_notes
   (una riga per utente) e si salva da solo, scrivendo sopra (todo_api.php?ajax=nota_salva).

   Perche' una rotta a parte e non il registro dei cambiamenti: e' UNA riga per utente, senza uuid, e
   cambiare la tabella vorrebbe dire una migrazione da fare a mano. Basta la sua colonna updated_at.

   Il rischio vero e' perdere testo: due telefoni (o il sito) che scrivono la nota. Per questo il PUT porta
   «base_updated_at», cioe' la versione su cui l'app ha scritto. Se sul server la nota e' cambiata da allora
   si risponde 409 con la versione del server, e l'app tiene TUTTE E DUE le versioni. Mai sovrascrivere a cieco.

   Sempre per l'utente del token (user_id nella WHERE, mai preso dal corpo).
   ===================================================================== */

const API_NOTA_MAX = 20000; // lo stesso limite del sito

function api_nota_tabella(): void {
    static $fatto = false;
    if ($fatto) return;
    $fatto = true;
    // come fa il sito: su un database vecchio la tabella potrebbe non esserci ancora
    sec_q("CREATE TABLE IF NOT EXISTS todo_notes (
             user_id INT NOT NULL,
             body MEDIUMTEXT NOT NULL,
             updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
             PRIMARY KEY (user_id)
           ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/** La nota di oggi: ['body' => testo, 'updated_at' => 'AAAA-MM-GG HH:MM:SS' | null se non e' mai stata scritta]. */
function api_nota_leggi(int $uid): array {
    api_nota_tabella();
    $r = sec_one("SELECT body, updated_at FROM todo_notes WHERE user_id = ?", [$uid], 'i');
    return ['body' => $r ? (string)$r['body'] : '', 'updated_at' => $r ? (string)$r['updated_at'] : null];
}

function api_h_nota_leggi(array $ctx): void {
    api_ok(['note' => api_nota_leggi((int)$ctx['utente']['id'])]);
}

function api_h_nota_scrivi(array $ctx): void {
    $uid = (int)$ctx['utente']['id'];
    api_h_limite_scritture($ctx, 'api_note_write', 120);
    $c = api_corpo();
    if (!array_key_exists('body', $c) || !is_string($c['body'])) {
        api_errore('validation', 'body must be a string', null, ['field' => 'body']);
    }
    if (mb_strlen($c['body'], 'UTF-8') > API_NOTA_MAX) {
        api_errore('validation', 'The note is too long (max ' . API_NOTA_MAX . ' characters)', null, ['field' => 'body']);
    }
    $base = $c['base_updated_at'] ?? null;
    if ($base !== null && !(is_string($base) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $base))) {
        api_errore('validation', 'base_updated_at must be null or "YYYY-MM-DD HH:MM:SS"', null, ['field' => 'base_updated_at']);
    }
    $body = sec_text($c['body'], API_NOTA_MAX);

    $ora = api_nota_leggi($uid);
    if ($ora['updated_at'] === null) {
        // ancora nessuna nota sul server: va bene solo se l'app non ne conosceva nessuna. INSERT IGNORE: se un altro
        // dispositivo l'ha creata un attimo fa, non si scrive sopra e si risponde 409.
        if ($base === null) {
            sec_q("INSERT IGNORE INTO todo_notes (user_id, body) VALUES (?, ?)", [$uid, $body], 'is');
            if (sec_affected() > 0) api_ok(['note' => api_nota_leggi($uid)]);
        }
    } elseif ($base !== null && $base === $ora['updated_at']) {
        // compare-and-swap: si scrive solo se la riga e' ancora quella che l'app aveva visto
        sec_q("UPDATE todo_notes SET body = ? WHERE user_id = ? AND updated_at = ?", [$body, $uid, $base], 'sis');
        $dopo = api_nota_leggi($uid);
        // stesso testo = MySQL non tocca updated_at e affected e' 0: va bene lo stesso
        if (sec_affected() > 0 || $dopo['body'] === $body) api_ok(['note' => $dopo]);
    }
    api_errore('conflict', 'The note changed on the server since you last saw it', null, ['note' => api_nota_leggi($uid)]);
}
