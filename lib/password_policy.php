<?php
/* =========================================================================
   STANDOUT · REGOLA DELLE PASSWORD (STANDOUT_PROGETTO.md §7.4)

   - almeno 10 caratteri (e un tetto, per non far lavorare il server su
     megabyte di testo: l'hash e' lento apposta)
   - nessuna regola su maiuscole e simboli: allungare vale piu' che complicare
   - non fra le password piu' diffuse
   - non uguale al nome utente o alla parte dell'email prima della chiocciola
   - non un carattere solo ripetuto, ne' una sequenza ovvia (1234567890)

   Si applica a chi si REGISTRA da ora in poi. Chi ha gia' una password piu'
   corta continua a entrare: la regola non tocca gli hash esistenti.

   Ritorna null se la password va bene, altrimenti il codice dell'errore:
     'corta' | 'lunga' | 'comune' | 'utente'
   ========================================================================= */

const STANDOUT_PASSWORD_MIN = 10;
const STANDOUT_PASSWORD_MAX = 128;

/** Le password piu' usate (in minuscolo) fra quelle abbastanza lunghe da passare il minimo. */
function standout_password_comuni(): array {
    return [
        '1234567890','0123456789','12345678910','123456789012','1234567891','1234567890a','1234567890q',
        'qwertyuiop','qwertyuiop1','qwerty1234','qwerty12345','qwerty123456','qwertyuiopasdfghjkl','asdfghjkl1','asdfghjklq',
        'azertyuiop','1q2w3e4r5t','1q2w3e4r5t6y','q1w2e3r4t5','zxcvbnm123','zxcvbnmasdfghjkl','1qaz2wsx3edc',
        'password1','password12','password123','password1234','password12345','password123456','password1!','password01',
        'passw0rd123','passw0rd12','p@ssw0rd123','p@ssword123','pass123456','pass1234567','mypassword','mypassword1','mypassword123',
        'iloveyou12','iloveyou123','iloveyou1234','iloveyou123456','ilovemyself','letmein123','letmein1234','welcome123','welcome1234','welcome12345',
        'administrator','admin12345','admin123456','admin1234567','abcdefghij','abcd123456','abc1234567','abc123456789','abcdefg123',
        'trustno1234','trustno1111','sunshine123','princess123','football123','baseball123','superman123','dragon12345','monkey12345','master12345',
        'shadow12345','michael1234','charlie1234','jordan23456','harley12345','ranger12345','buster12345','soccer12345','hockey12345','killer12345',
        'changeme123','changeme12','default1234','test123456','test1234567','testtest12','testing1234','guest123456','user123456','login12345',
        'aaaaaaaaaa','aaaaaaaaaaaa','1111111111','0000000000','1212121212','1231231234','9876543210','0987654321','1029384756','5555555555',
        'password!!','passwordpassword','qazwsxedcrfv','qazwsx123456','zaq12wsxcde3','1qazxsw23edc','0okmnji9uhb',
        /* le piu' comuni in italiano */
        'ciaociao12','ciaociaociao','ciao123456','ciao1234567','ciaobello1','ciaociao123','password01!','passwordital','parolachiave',
        'italia1234','italia12345','italia123456','roma123456','milano12345','napoli12345','juventus12','juventus123','juventus1234','milan123456',
        'inter123456','amoreamore','tivoglio','tiamo12345','tiamo123456','tiamoamore','mamma12345','mamma123456','papa123456','forzaroma1',
        'forzajuve1','forzanapoli','forzamilan1','fortunato1','principessa','bellissima1','sempreinter','salvatore1','francesco1','alessandro1',
        'nikita2024','nikita2025','nikita2026','standout123','standout1234','nisama1234','nisama12345','standout2026','standout2025',
        'polimi1234','polimi12345','polimi123456','politecnico','ingegneria1','studente123','studente1234','universita1',
    ];
}

/** Toglie ogni differenza che non cambia davvero la password: maiuscole, spazi, leet semplice. */
function standout_password_normalizza(string $p): string {
    $p = mb_strtolower(trim($p));
    return strtr($p, ['@' => 'a', '0' => 'o', '1' => 'i', '3' => 'e', '$' => 's', '!' => 'i', '5' => 's', '7' => 't']);
}

function standout_password_errore(string $password, string $username = '', string $email = ''): ?string {
    $len = mb_strlen($password);
    if ($len < STANDOUT_PASSWORD_MIN) return 'corta';
    if ($len > STANDOUT_PASSWORD_MAX) return 'lunga';

    $minuscola = mb_strtolower($password);

    /* un carattere solo, o due che si alternano */
    if (count(array_unique(preg_split('//u', $minuscola, -1, PREG_SPLIT_NO_EMPTY))) <= 2) return 'comune';

    /* una sequenza ovvia: tutta crescente o tutta decrescente (1234567890, abcdefghij) */
    $o = array_map('mb_ord', preg_split('//u', $minuscola, -1, PREG_SPLIT_NO_EMPTY));
    $su = true; $giu = true;
    for ($i = 1; $i < count($o); $i++) {
        if ($o[$i] !== $o[$i - 1] + 1) $su = false;
        if ($o[$i] !== $o[$i - 1] - 1) $giu = false;
    }
    if ($su || $giu) return 'comune';

    $comuni = array_flip(standout_password_comuni());
    $norm = standout_password_normalizza($password);
    foreach ([$minuscola, $norm] as $v) {
        if (isset($comuni[$v])) return 'comune';
    }
    foreach (array_keys($comuni) as $c) {
        /* "password123" e' comune anche come "password123456": basta che la
           password cominci con una voce lunga dell'elenco e aggiunga solo cifre */
        if (mb_strlen($c) >= 8 && strpos($minuscola, $c) === 0 && ctype_digit(substr($minuscola, strlen($c)))) return 'comune';
    }

    /* parola banale + numeri e segni in coda ("Password123!", "Qwerty2026#"): allungare
       con cifre non rende sicura una parola che e' nell'elenco di tutti gli attaccanti */
    $banali = ['password','passw0rd','p@ssword','qwerty','qwertyuiop','iloveyou','welcome','letmein','administrator','admin','changeme',
               'ciaociao','ciao','juventus','milan','inter','napoli','italia','mamma','tiamo','forzaroma','standout','nisama','polimi'];
    foreach ([$minuscola, $norm] as $v) {
        $base = rtrim($v, '0123456789!@#$%^&*()_+-=.,;:?');
        if ($base !== $v && in_array($base, $banali, true)) return 'comune';
    }

    /* il proprio nome utente o la propria email */
    $parti = [];
    if ($username !== '') $parti[] = mb_strtolower($username);
    if ($email !== '') {
        $loc = mb_strtolower(explode('@', $email)[0]);
        if (mb_strlen($loc) >= 4) $parti[] = $loc;
    }
    foreach ($parti as $x) {
        if ($x !== '' && mb_strlen($x) >= 4 && (strpos($minuscola, $x) !== false || strpos($norm, standout_password_normalizza($x)) !== false)) return 'utente';
    }
    return null;
}
