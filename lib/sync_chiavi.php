<?php
/* =====================================================================
   STANDOUT — chiavi di ordinamento per l'app (fractional-indexing)

   PERCHE': l'app ordina le liste con una stringa («sort_key») invece di un
   numero: spostare una voce cambia UNA riga, non si rinumera tutta la lista, e
   due telefoni offline possono spostare voci diverse senza pestarsi i piedi.
   Le chiavi sono quelle della libreria JavaScript `fractional-indexing`, che
   l'app usa per crearne di nuove tra due vicine. Il server qui ne deve fare
   solo due cose: la chiave dopo l'ultima (voce nuova in coda, come fa il sito
   con sort_order = MAX+1) e la chiave per la posizione N (riallineare una lista
   dopo un riordino fatto dal sito). Stessa regola delle migrazioni SQL
   (tools/genera_migrazioni_sync.py, espr_chiave): a0..az, b00..bzz, c000...

   FORMATO: una testa (a=2 caratteri totali, b=3, c=4...) seguita da cifre in
   base 62 (0-9 A-Z a-z). La parte dopo la «parte intera» e' la frazione, creata
   dall'app quando infila una voce fra due.
   ===================================================================== */

const SYNC_CIFRE = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

/** Quante cifre ha la parte intera, dalla testa ('a' = 1 cifra, 'b' = 2...). null se la testa non e' valida. */
function sync_chiave_cifre(string $chiave): ?int {
    $t = $chiave[0] ?? '';
    if ($t >= 'a' && $t <= 'z') return ord($t) - ord('a') + 1;
    return null;   // le teste maiuscole (numeri negativi) non le creiamo mai lato server
}

/** La chiave subito dopo $ultima (null = lista vuota). Ignora l'eventuale frazione di $ultima. */
function sync_chiave_dopo(?string $ultima): string {
    if ($ultima === null || $ultima === '') return 'a0';
    $n = sync_chiave_cifre($ultima);
    if ($n === null || strlen($ultima) < $n + 1) return 'a0';
    $testa  = $ultima[0];
    $intera = substr($ultima, 1, $n);
    // si incrementa come un numero in base 62, da destra
    $i = $n - 1;
    while ($i >= 0) {
        $p = strpos(SYNC_CIFRE, $intera[$i]);
        if ($p === false) return 'a0';
        if ($p < 61) { $intera[$i] = SYNC_CIFRE[$p + 1]; return $testa . $intera; }
        $intera[$i] = '0';
        $i--;
    }
    // tutte le cifre erano 'z': serve una cifra in piu' e la testa avanza
    if ($testa >= 'z') return $ultima;   // oltre 62^25 voci: non succede
    return chr(ord($testa) + 1) . str_repeat('0', $n + 1);
}

/** La chiave della posizione $n (0 = prima): a0, a1... az, b00... */
function sync_chiave_posizione(int $n): string {
    $k = 'a0';
    for ($i = 0; $i < $n; $i++) $k = sync_chiave_dopo($k);
    return $k;
}

/** Chiavi valide per il server: testa minuscola + cifre in base 62, al massimo 40 caratteri. */
function sync_chiave_valida($k): bool {
    if (!is_string($k) || strlen($k) < 2 || strlen($k) > 40) return false;
    if (!preg_match('/^[a-z][0-9A-Za-z]+$/', $k)) return false;
    $n = sync_chiave_cifre($k);
    return $n !== null && strlen($k) >= $n + 1;
}
