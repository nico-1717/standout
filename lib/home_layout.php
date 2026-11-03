<?php
/* =====================================================================
   STANDOUT — il layout della Home: quali riquadri e in che ordine.
   Stava dentro public/home/home_api.php; sta qui perche' lo usano la pagina
   (home_api.php) e l'app (/api/v1/home/layout): la regola e' una sola.
   ===================================================================== */

/* I riquadri che esistono, nell'ordine in cui compaiono la prima volta.
   Un id sconosciuto arrivato dal client si scarta: il layout e' dell'utente
   ma l'elenco dei riquadri e' del codice. */
/* «tasks» e «habits» non sono piu' riquadri: attivita', acquisti, pastiglie e l'anello
   delle abitudini vivono dentro «agenda» (Oggi); le abitudini complete si aprono in un
   popup. I gruppi di dati con quei nomi esistono ancora (li chiede «agenda»), e un
   layout salvato che li nomina ancora li perde da solo (vedi ho_layout). */
/* L'ordine qui e' quello standard (lo stesso di ORDINE in home_core.js). */
const HO_RIQUADRI = ['shortcuts', 'agenda', 'note', 'cards', 'projects', 'grades', 'trivia', 'stats'];

function ho_tabelle(): void {
    static $fatto = false;
    if ($fatto) return;
    sec_q("CREATE TABLE IF NOT EXISTS home_layout (
             user_id INT NOT NULL,
             layout TEXT NOT NULL,
             updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
             PRIMARY KEY (user_id)
           ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    sec_q("CREATE TABLE IF NOT EXISTS fc_quick_drafts (
             id INT UNSIGNED NOT NULL AUTO_INCREMENT,
             user_id INT NOT NULL,
             front VARCHAR(500) NOT NULL,
             back VARCHAR(2000) NOT NULL DEFAULT '',
             deck_id INT UNSIGNED DEFAULT NULL,
             created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
             PRIMARY KEY (id),
             KEY idx_user (user_id, created_at)
           ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $fatto = true;
}

/* L'ordine dei tasti dell'accesso rapido (gli href delle pagine), salvato dentro la
   voce «shortcuts» del layout. Si tengono solo stringhe corte e sicure: l'elenco
   vero dei tasti e' del codice (dashboard_content.php), qui si salva solo l'ordine. */
function ho_ordine_scorciatoie($v): array {
    $o = [];
    if (is_array($v)) {
        foreach ($v as $h) {
            if (is_string($h) && preg_match('~^[A-Za-z0-9_/.\-]{1,60}$~', $h) && !in_array($h, $o, true)) $o[] = $h;
            if (count($o) >= 20) break;
        }
    }
    return $o;
}

/** Il layout salvato, ripulito e completato: ogni riquadro noto compare una volta sola. */
function ho_layout(int $uid): array {
    $r = sec_one("SELECT layout FROM home_layout WHERE user_id = ?", [$uid], 'i');
    $salvato = $r ? json_decode((string)$r['layout'], true) : null;
    $out = []; $visti = [];
    if (is_array($salvato)) {
        foreach ($salvato as $v) {
            $id = is_array($v) ? (string)($v['id'] ?? '') : '';
            if (!in_array($id, HO_RIQUADRI, true) || isset($visti[$id])) continue;
            $visti[$id] = true;
            $voce = ['id' => $id, 'on' => !empty($v['on'])];
            if ($id === 'shortcuts') { $ord = ho_ordine_scorciatoie($v['order'] ?? null); if ($ord) $voce['order'] = $ord; }
            $out[] = $voce;
        }
    }
    /* un riquadro aggiunto da un aggiornamento compare in fondo, acceso:
       chi ha gia' personalizzato la home non lo trova nascosto a sua insaputa */
    foreach (HO_RIQUADRI as $id) { if (!isset($visti[$id])) $out[] = ['id' => $id, 'on' => true]; }
    return $out;
}

/** Ripulisce un layout arrivato dal client (id sconosciuti e doppioni via, riquadri mancanti in fondo) e lo salva. */
function ho_layout_salva(int $uid, array $in): array {
    ho_tabelle();
    $pulito = []; $visti = [];
    foreach ($in as $v) {
        $id = is_array($v) ? (string)($v['id'] ?? '') : '';
        if (!in_array($id, HO_RIQUADRI, true) || isset($visti[$id])) continue;
        $visti[$id] = true;
        $voce = ['id' => $id, 'on' => !empty($v['on'])];
        if ($id === 'shortcuts') { $ord = ho_ordine_scorciatoie($v['order'] ?? null); if ($ord) $voce['order'] = $ord; }
        $pulito[] = $voce;
    }
    foreach (HO_RIQUADRI as $id) { if (!isset($visti[$id])) $pulito[] = ['id' => $id, 'on' => true]; }
    sec_q("INSERT INTO home_layout (user_id, layout) VALUES (?, ?) ON DUPLICATE KEY UPDATE layout = VALUES(layout)",
          [$uid, json_encode($pulito)], 'is');
    return $pulito;
}

/* ---- /api/v1/home/layout (l'app) ---- */

function api_h_home_layout(array $ctx): void {
    api_ok(['layout' => ho_layout((int)$ctx['utente']['id'])]);
}
function api_h_home_layout_salva(array $ctx): void {
    api_h_limite_scritture($ctx, 'api_home_write');
    $in = api_corpo()['layout'] ?? null;
    if (!is_array($in) || count($in) > 40) api_errore('validation', 'layout must be a list', 422, ['field' => 'layout']);
    api_ok(['layout' => ho_layout_salva((int)$ctx['utente']['id'], $in)]);
}
