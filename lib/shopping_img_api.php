<?php
/* =====================================================================
   STANDOUT — L'IMMAGINE DI UN ARTICOLO per l'API /api/v1/shopping/{id}/image
   (PROGETTO §15, passo 9, fetta 8)

   Il lavoro vero (tipo letto dal contenuto, massimo 4 MB, nome scelto dal server,
   cartella che non esegue niente) e' lo stesso della pagina: td_img_salva() in
   lib/todo_core.php. Qui c'e' solo:
     - il controllo che l'articolo sia del to buy dell'utente (un id di un to do o di
       un altro utente = 404; la pagina invece accetta anche un to do);
     - gli errori col nome del campo (422 su «file»);
     - la lettura: l'app non conosce la cartella, quindi il file lo riceve da qui,
       con la sessione, e non da un indirizzo pubblico.
   Caricato da public/api/v1/index.php.
   ===================================================================== */
require_once __DIR__ . '/shopping_api.php';

const SP_IMG_TIPI = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'];

/** L'articolo del to buy dell'utente, o null. */
function sp_img_articolo(int $uid, int $id): ?array {
    return sec_one("SELECT id, buy_image FROM todos WHERE id = ? AND user_id = ? AND list_type = 'buy'", [$id, $uid], 'ii');
}

/** Carica (o sostituisce) l'immagine. Ritorna ['has_image' => true] oppure un errore. */
function sp_img_carica(int $uid, int $id): array {
    if (!sp_img_articolo($uid, $id)) return td_api_errore('not_found', 'id', 'Item not found');
    $r = td_img_salva($uid, $id, $_FILES['file'] ?? null);
    if (isset($r['errore'])) {
        [, $http, $codice] = $r['errore'];
        if ($http >= 500) return td_api_errore('server_error', 'file', 'The image could not be saved');
        // frasi fisse in inglese (la pagina usa la lingua dell'utente, l'app no)
        $frasi = [
            'too_big'  => 'Image too large: 4 MB at most',
            'bad_type' => 'The file must be a jpg, png, webp or gif image',
        ];
        return td_api_errore('validation', 'file', $frasi[$codice] ?? 'No image received: send a multipart form field named file');
    }
    return ['has_image' => true];
}

/** Toglie l'immagine; vale anche se non ce n'e'. false = articolo non trovato. */
function sp_img_togli(int $uid, int $id): bool {
    if (!sp_img_articolo($uid, $id)) return false;
    td_img_togli($uid, $id);
    return true;
}

/**
 * Il file da mandare: ['path' => ..., 'mime' => ...] oppure null.
 * Il nome viene dal database ma si controlla lo stesso: solo la forma che crea td_img_salva().
 */
function sp_img_file(int $uid, int $id): ?array {
    $a = sp_img_articolo($uid, $id);
    $nome = (string)($a['buy_image'] ?? '');
    if (!preg_match('/^b[0-9]+_[0-9a-f]{20}\.(jpg|png|webp|gif)$/', $nome, $m)) return null;
    $path = td_img_dir() . '/' . $nome;
    return is_file($path) ? ['path' => $path, 'mime' => SP_IMG_TIPI[$m[1]]] : null;
}
