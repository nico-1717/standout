<?php
/* =====================================================================
   STANDOUT — immagini e audio delle righe sincronizzate, dall'app

   POST   /api/v1/media/{tabella}/{uuid}/{campo}   carica o sostituisce (multipart, campo «file»)
   DELETE /api/v1/media/{tabella}/{uuid}/{campo}   toglie il file (va bene anche se non c'e')

   Perche' una rotta a parte: nel manifest i campi dei file sono «solo_lettura» (§3.7 di ARCHITETTURA_APP.md), cioe'
   il telefono non puo' scriverli con /sync/push: un nome di file scelto dal client potrebbe puntare a qualunque file.
   Qui il nome lo genera sempre il server, dal contenuto vero del file (finfo, non l'estensione), e la riga si
   aggiorna con un normale UPDATE: il registro della sincronizzazione lo annota da solo e gli altri dispositivi
   lo ricevono con la prossima /sync/pull.

   Le cartelle sono quelle di sempre del sito (media/flashcard_images, flashcard_audio, vocab_images, example_images):
   le carte caricate dal telefono si vedono sul sito e viceversa.
   ===================================================================== */

/** Per ogni tabella: campo => [tipo, cartella dentro media/]. Solo quello che c'e' qui si puo' toccare. */
const MEDIA_CAMPI = [
    'fc_study_cards' => [
        'question_image' => ['immagine', 'flashcard_images'], 'answer_image' => ['immagine', 'flashcard_images'],
        'question_audio' => ['audio', 'flashcard_audio'],     'answer_audio' => ['audio', 'flashcard_audio'],
    ],
    'fc_study_subcards' => [
        'question_image' => ['immagine', 'flashcard_images'], 'answer_image' => ['immagine', 'flashcard_images'],
        'question_audio' => ['audio', 'flashcard_audio'],     'answer_audio' => ['audio', 'flashcard_audio'],
    ],
    'fc_vocab_cards'    => ['image' => ['immagine', 'vocab_images']],
    'fc_vocab_subcards' => ['image' => ['immagine', 'vocab_images']],
    'fc_vocab_examples' => ['example_image' => ['immagine', 'example_images']],
];

/** L'immagine di un articolo del to buy ha la sua cartella e le sue regole (lib/todo_core.php): si riusa td_img_salva(). */
function media_e_articolo(string $tbl, string $campo): bool { return $tbl === 'todos' && $campo === 'buy_image'; }

/** L'id numerico di un articolo del to buy dell'utente, o null (le attivita' del to do non hanno immagine). */
function media_id_articolo(int $uid, string $uuid): ?int {
    $id = sync_id_da_uuid('todos', $uid, $uuid);
    if ($id === null) return null;
    $r = sec_one("SELECT 1 AS x FROM todos WHERE id = ? AND user_id = ? AND list_type = 'buy'", [$id, $uid], 'ii');
    return $r ? $id : null;
}

/** I tipi veri accettati per l'audio (e l'estensione che il server sceglie per ciascuno). */
const MEDIA_AUDIO_MIME = [
    'audio/mpeg' => 'mp3', 'audio/mp3' => 'mp3', 'audio/wav' => 'wav', 'audio/x-wav' => 'wav',
    'audio/webm' => 'webm', 'audio/ogg' => 'ogg', 'audio/mp4' => 'm4a', 'audio/x-m4a' => 'm4a', 'audio/aac' => 'aac',
    'video/mp4' => 'm4a',   // un audio AAC in contenitore mp4 (come lo registra Android) lo riconosce come video/mp4
];
const MEDIA_MAX_IMMAGINE = 5242880;    //  5 MB (l'app li manda gia' ridotti a 1600 px)
const MEDIA_MAX_AUDIO    = 10485760;   // 10 MB, come sul sito

/**
 * Lo spazio per immagini e audio di ogni utente. Un solo numero, che non dipende dal servizio che ospita il sito: se un
 * giorno il sito cambia casa, basta definire STANDOUT_MEDIA_QUOTA in config.php (in byte) senza toccare il codice.
 */
const MEDIA_QUOTA_PREDEFINITA = 524288000;   // 500 MB

function media_quota(): int {
    return defined('STANDOUT_MEDIA_QUOTA') ? max(0, (int)STANDOUT_MEDIA_QUOTA) : MEDIA_QUOTA_PREDEFINITA;
}

/** Quanti byte occupano sul disco i file dell'utente (immagini e audio delle carte, immagini del to buy). */
function media_usato(int $uid): int {
    $tot = 0;
    $visti = [];
    foreach (MEDIA_CAMPI as $tbl => $campi) {
        foreach ($campi as $col => [, $dir]) {
            $righe = sec_all(
                "SELECT DISTINCT t.`$col` AS f FROM `$tbl` t WHERE " . sync_prop($tbl, 't') . " = ? AND t.`$col` IS NOT NULL AND t.`$col` <> ''",
                [$uid], 'i'
            );
            foreach ($righe as $r) {
                $nome = (string)$r['f'];
                if (!media_nome_valido($nome)) continue;
                $chiave = $dir . '/' . $nome;
                if (isset($visti[$chiave])) continue;   // lo stesso file puo' servire a piu' carte: si conta una volta
                $visti[$chiave] = true;
                $path = media_percorso($dir, $nome);
                if (is_file($path)) $tot += (int)filesize($path);
            }
        }
    }
    $articoli = sec_all("SELECT buy_image AS f FROM todos WHERE user_id = ? AND list_type = 'buy' AND buy_image IS NOT NULL AND buy_image <> ''", [$uid], 'i');
    foreach ($articoli as $r) {
        $nome = (string)$r['f'];
        if (!media_nome_valido($nome)) continue;
        $path = td_img_dir() . '/' . $nome;
        if (is_file($path)) $tot += (int)filesize($path);
    }
    return $tot;
}

/** Il nome di un file di questa rotta, com'e' scritto dal server: niente altro e' un nome valido. */
function media_nome_valido(string $nome): bool {
    return (bool)preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]*\.[A-Za-z0-9]{2,5}$/', $nome);
}

/** Dove sta il file sul disco (la cartella e' sempre una di quelle dell'elenco, mai scelta dal client). */
function media_percorso(string $cartella, string $nome): string {
    return STANDOUT_DATA_DIR . $cartella . '/' . $nome;
}

/**
 * Quante righe usano ancora questo file (in qualunque campo della stessa cartella). Il sito puo' far puntare due
 * carte allo stesso file (duplicazioni, importazioni): si cancella dal disco solo quando non lo usa piu' nessuna.
 */
function media_riferimenti(string $cartella, string $nome): int {
    $n = 0;
    foreach (MEDIA_CAMPI as $tbl => $campi) {
        foreach ($campi as $col => [, $dir]) {
            if ($dir !== $cartella) continue;
            $r = sec_one("SELECT COUNT(*) AS n FROM `$tbl` WHERE `$col` = ?", [$nome], 's');
            $n += (int)($r['n'] ?? 0);
        }
    }
    return $n;
}

/** Toglie dal disco un file che non usa piu' nessuno. */
function media_scarta(string $cartella, ?string $nome): void {
    if ($nome === null || $nome === '' || !media_nome_valido($nome)) return;
    if (media_riferimenti($cartella, $nome) > 0) return;
    $path = media_percorso($cartella, $nome);
    if (is_file($path)) @unlink($path);
    if ($cartella !== 'flashcard_audio') {
        try { sec_q("DELETE FROM fc_image_meta WHERE filename = ?", [$nome], 's'); } catch (Throwable $e) { /* tabella non ancora migrata */ }
    }
}

/** La riga dell'utente a cui si riferisce la richiesta: ['id' => ..., 'valore' => nome attuale] oppure null. */
function media_riga(int $uid, string $tbl, string $uuid, string $col): ?array {
    $id = sync_id_da_uuid($tbl, $uid, $uuid);
    if ($id === null) return null;
    $r = sec_one("SELECT `$col` AS v FROM `$tbl` WHERE `" . sync_pk($tbl) . "` = ?", [$id], 'i');
    return $r ? ['id' => $id, 'valore' => $r['v'] !== null ? (string)$r['v'] : null] : null;
}

/** Controlla che tabella, uuid e campo siano di quelli ammessi; ritorna [tipo, cartella] oppure un errore. */
function media_destinazione(string $tbl, string $campo): ?array {
    return MEDIA_CAMPI[$tbl][$campo] ?? null;
}

/**
 * Carica o sostituisce un file. Ritorna ['name' => ...] oppure ['errore' => [codice, campo, testo]].
 * $file e' la voce di $_FILES (o null).
 */
function media_carica(int $uid, string $tbl, string $uuid, string $campo, $file): array {
    if (media_e_articolo($tbl, $campo)) {
        $id = media_id_articolo($uid, $uuid);
        if ($id === null) return ['errore' => ['not_found', 'uuid', 'Row not found']];
        if (media_usato($uid) + (int)(is_array($file) ? ($file['size'] ?? 0) : 0) > media_quota()) {
            return ['errore' => ['quota_exceeded', 'file', 'Your storage space is full: remove some pictures or recordings']];
        }
        $r = td_img_salva($uid, $id, is_array($file) ? $file : null);
        if (isset($r['errore'])) {
            return ['errore' => [$r['errore'][1] >= 500 ? 'server_error' : 'validation', 'file', 'The image is not valid (jpg, png, webp or gif, 4 MB at most)']];
        }
        return ['name' => $r['image']];
    }
    $dest = media_destinazione($tbl, $campo);
    if ($dest === null) return ['errore' => ['validation', 'field', 'Unknown table or field']];
    [$tipo, $cartella] = $dest;
    $riga = media_riga($uid, $tbl, $uuid, $campo);
    if ($riga === null) return ['errore' => ['not_found', 'uuid', 'Row not found']];

    if (!is_array($file)) return ['errore' => ['validation', 'file', 'No file received: send a multipart form field named file']];
    $ris = $tipo === 'audio'
        ? sec_file($file, MEDIA_AUDIO_MIME, MEDIA_MAX_AUDIO)
        : sec_file($file, null, MEDIA_MAX_IMMAGINE);
    if (empty($ris['ok'])) return ['errore' => ['validation', 'file', 'The file is not valid (type, size or content)']];
    /* L'audio non puo' sembrare un'immagine e viceversa: sec_file guarda solo l'elenco ammesso, qui si controlla il genere. */
    $genere = $tipo === 'audio' ? 'audio/' : 'image/';
    $mime = (string)$ris['mime'];
    $audioMp4 = $tipo === 'audio' && $mime === 'video/mp4';
    if (strpos($mime, $genere) !== 0 && !$audioMp4) return ['errore' => ['validation', 'file', 'The file is not valid (type, size or content)']];

    /* Lo spazio dell'utente: il file che si sostituisce libera il suo posto. */
    $vecchio = $riga['valore'] !== null && media_nome_valido($riga['valore']) && is_file(media_percorso($cartella, $riga['valore']))
        ? (int)filesize(media_percorso($cartella, $riga['valore'])) : 0;
    $nuovo = (int)($file['size'] ?? 0);
    if (media_usato($uid) - $vecchio + $nuovo > media_quota()) {
        return ['errore' => ['quota_exceeded', 'file', 'Your storage space is full: remove some pictures or recordings']];
    }

    $dir = STANDOUT_DATA_DIR . $cartella;
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return ['errore' => ['server_error', 'file', 'The file could not be saved']];
    if (!is_writable($dir)) return ['errore' => ['server_error', 'file', 'The file could not be saved']];

    $nome = sec_safe_filename($ris['ext']);
    if (!move_uploaded_file($ris['tmp'], $dir . '/' . $nome)) return ['errore' => ['server_error', 'file', 'The file could not be saved']];
    @chmod($dir . '/' . $nome, 0644);
    if ($tipo === 'immagine' && function_exists('fc_misura_registra')) fc_misura_registra($nome, $dir . '/' . $nome);

    sec_q("UPDATE `$tbl` SET `$campo` = ? WHERE `" . sync_pk($tbl) . "` = ?", [$nome, $riga['id']], 'si');
    media_scarta($cartella, $riga['valore']);
    return ['name' => $nome];
}

/** Toglie il file di un campo. Ritorna true; false se la riga non c'e' (o non e' dell'utente). */
function media_togli(int $uid, string $tbl, string $uuid, string $campo): ?bool {
    if (media_e_articolo($tbl, $campo)) {
        $id = media_id_articolo($uid, $uuid);
        if ($id === null) return false;
        td_img_togli($uid, $id);
        return true;
    }
    $dest = media_destinazione($tbl, $campo);
    if ($dest === null) return null;
    $riga = media_riga($uid, $tbl, $uuid, $campo);
    if ($riga === null) return false;
    if ($riga['valore'] !== null) {
        sec_q("UPDATE `$tbl` SET `$campo` = NULL WHERE `" . sync_pk($tbl) . "` = ?", [$riga['id']], 'i');
        media_scarta($dest[1], $riga['valore']);
    }
    return true;
}
