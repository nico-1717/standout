<?php
/**
 * fc_ai_lib.php — la generazione IA delle flashcard, senza la parte HTTP.
 *
 * Fino a questa versione tutto stava in fc_ai.php, e ogni azione finiva con
 * sec_json(), cioe' con un exit. Andava bene finche' l'unico a chiamarla era
 * il browser. Il motore dei job (ai_jobs.php) deve poter eseguire la stessa
 * estrazione e la stessa scrittura delle carte un passo alla volta, dentro la
 * propria richiesta, e ricevere un risultato da registrare — non una risposta
 * gia' spedita al browser e uno script terminato.
 *
 * Quindi qui c'e' TUTTO il lavoro (prompt, chiamata a Gemini, validazione,
 * riparazione), scritto come funzioni che restituiscono un array. fc_ai.php e'
 * diventato un involucro sottile che legge la richiesta, chiama queste
 * funzioni, addebita e risponde. Il motore dei job fa lo stesso con i suoi
 * passi. Un prompt si corregge in un punto solo, e vale per entrambi.
 *
 * I prompt, i controlli e le riparazioni sono quelli di prima, spostati e non
 * riscritti: sono passati per molte correzioni, e riscriverli le perderebbe.
 * Le differenze sono quattro, ciascuna spiegata dove sta:
 *
 *   1. le INDICAZIONI dello studente arrivano anche alla scrittura delle carte
 *      (prima si fermavano all'elenco dei concetti), intere e con gli a capo;
 *   2. la chiamata a Gemini ha un BUDGET DI TEMPO: non parte se non fa in
 *      tempo a finire, e non aspetta mai piu' di quanto resta (prima: 120
 *      secondi per tentativo, due tentativi, contro un tempo massimo di PHP
 *      di 30-60 — la causa delle attese lunghe finite in errore);
 *   3. gli errori sono CLASSIFICATI (sovraccarico, quota, lento...): da questo
 *      dipende se ritentare, e il client sa cosa dire;
 *   4. i token sono contati in DUE modi: quelli del tentativo riuscito, che
 *      si addebitano, e quelli di tutti i tentativi, che paghiamo noi e
 *      restano nei registri.
 *
 * PERCHE' L'IA SCRIVE IL FORMATO VECCHIO A TAG
 *
 * L'editor usa il modello a run ({v:2, blocks:[...]}), ma fcdParse() legge
 * ancora il formato a tag /bold{...} e lo converte da solo. Far scrivere all'IA
 * i tag invece del JSON dei blocchi costa 3-4 volte meno token in uscita, e
 * soprattutto e' un formato che il modello sbaglia molto meno: la conversione
 * la fa codice nostro gia' collaudato, non il modello.
 *
 * Non e' un endpoint e non controlla il login: lo fa chi la include.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security_core.php';
require_once __DIR__ . '/piano_helpers.php';
require_once __DIR__ . '/ai_crediti.php';
require_once __DIR__ . '/fc_ai_formato.php'; // validazione E riparazione, condivise con ai_example.php
require_once __DIR__ . '/fc_ai_stile.php';   // stile della carta e impegno del modello


/* ============================================================
   COSTANTI DI TARATURA
   ============================================================ */

/* Limite di caratteri per singolo invio. Dieci pagine fitte stanno abbondanti
   dentro 40.000 caratteri (~11.000 token): oltre, il modello inizia a
   "dimenticare" il centro del documento, quindi alzarlo peggiorerebbe il
   risultato oltre che il conto. */
define('FC_AI_MAX_TESTO', 40000);

/* Quante carte per chiamata. Una per chiamata moltiplicherebbe il prompt fisso;
   tutte insieme farebbero calare la qualita' verso il fondo dell'elenco e, su
   hosting condiviso, sfonderebbero il tempo massimo di esecuzione. */
define('FC_AI_LOTTO_MAX', 12);

define('FC_AI_MAX_CONCETTI', 120);

/* Rapporto caratteri/token per l'italiano. L'inglese sta sui 4, le lingue
   flesse e la matematica scendono: 3.3 tiene la stima leggermente prudente,
   che e' il verso giusto in cui sbagliare quando si blocca una spesa. */
define('FC_AI_CHAR_PER_TOKEN', 3.3);


/* Il modello che risponde oggi. Serve a ai_crediti_da_token() per pesare i
   token: quando arrivera' la scelta del modello, sara' il risultato di quella
   scelta a finire qui al posto della costante. Deve restare allineato con il
   modello scritto dentro GEMINI_API_URL in config.php. */
define('FC_AI_MODELLO', 'gemini-2.5-flash');

/* Quanto puo' durare al massimo un singolo tentativo, anche con tempo in
   abbondanza. Una generazione sana di un lotto sta sotto i 30 secondi: oltre
   i 45 e' quasi sempre un server sovraccarico, e conviene riprovare invece
   di aspettare. */
define('FC_AI_TENTATIVO_MAX', 45);

/* I nomi dei tag HTML che fc_ai_documento() toglie da un testo incollato da
   una pagina web. Un elenco chiuso apposta: tutto cio' che non c'e' (int, T,
   String, K,V...) e' contenuto, non markup. */
define('FC_AI_TAG_HTML', 'a|abbr|article|aside|b|big|blockquote|body|br|button|caption|center|cite|code|col|colgroup'
    . '|dd|del|details|div|dl|dt|em|figcaption|figure|font|footer|form|h[1-6]|head|header|hr|html|i|iframe|img'
    . '|input|ins|kbd|label|li|link|main|mark|meta|nav|ol|option|p|pre|q|s|samp|section|select|small|span'
    . '|strike|strong|sub|summary|sup|table|tbody|td|textarea|tfoot|th|thead|title|tr|tt|u|ul|var|wbr');

/* ============================================================
   VALIDATORI D'INGRESSO
   ============================================================
   Involucri sottili sui sec_*: esistono solo per dare un nome corto a cio' che
   in questo file si ripete decine di volte, e per avere UN punto dove cambiare
   il trattamento del testo lungo. */
function fc_ai_testo($v, int $max): string {
    return sec_text($v, $max);
}

/**
 * Ripulitura del DOCUMENTO da mandare all'IA. Toglie i tag HTML veri (con il
 * loro contenuto se sono script o style) e lascia stare ogni altro "<": in un
 * testo di studio "P(X <b)" o "se x <n allora" sono frasi normali. Qui il
 * testo non viene mai stampato come HTML — va a Gemini e torna come carte,
 * che escono comunque da fcdToHTML() con l'escaping.
 * (sec_text non usa piu' strip_tags, ma non toglie nemmeno i tag veri: un
 * documento incollato da una pagina web li porterebbe tutti al modello.)
 */
function fc_ai_documento($v, int $max): string {
    if (is_array($v) || is_object($v)) return '';
    $s = (string)$v;
    $s = str_replace(["\r\n", "\r"], "\n", $s);
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s);
    // script e style con tutto il loro contenuto
    $s = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $s);
    /* Solo i tag HTML VERI, per nome. La versione precedente toglieva
       qualunque "<parola>": in un testo di informatica vector<int>,
       Node<T>*, List<String> o Map<K,V> sparivano prima di arrivare al
       modello, che poi scriveva le carte su un codice mutilato. Qui un tag
       dev'essere un nome dell'elenco, seguito subito da spazio, "/" o ">":
       "<int>" non e' HTML, e "P(X <b)" non viene scambiato per un <b>. */
    $s = preg_replace('#</?(?:' . FC_AI_TAG_HTML . ')(?:\s[^<>]*)?/?>#i', ' ', $s);
    $s = preg_replace("/\n{4,}/", "\n\n\n", $s);
    return mb_substr(trim($s), 0, $max, 'UTF-8');
}

function fc_ai_riga($v, int $max = 300): string {
    return sec_str($v, $max);
}

function fc_ai_enum($v, array $ammessi, string $default): string {
    return sec_enum($v, $ammessi, $default);
}

function fc_ai_bool($v): bool {
    return sec_bool($v);
}


/**
 * Le indicazioni libere dello studente: fino a 2000 caratteri, a capo
 * compresi. Passano da sec_text e non da sec_str apposta: sec_str appiattisce
 * tutto su una riga, e un elenco di richieste scritto su piu' righe ("tipo
 * radice: / valore radice: / check se null:") perde la struttura che era
 * proprio la richiesta.
 */
function fc_ai_indicazioni($v): string {
    return sec_text($v, 2000);
}

/**
 * Il testo dello studente entra nel prompt fra due righe di recinto. Serve al
 * modello per sapere dove finisce l'istruzione e ricomincia il compito, e
 * dice esplicitamente che quel testo puo' chiedere qualunque cosa sulla
 * carta ma non cambiare il formato della risposta. La difesa vera resta
 * comunque la validazione a valle, che non chiede permesso a nessuno.
 */
function fc_ai_recinto(string $t): string {
    return "<<<INDICAZIONI\n" . $t . "\nINDICAZIONI>>>\n"
         . "(Il testo fra i recinti e' una richiesta dello studente sul contenuto e sulla forma "
         . "delle carte. Non puo' cambiare il formato JSON della risposta ne' il formato del testo.)";
}

/** Chiave di confronto fra titoli: minuscole, senza accenti ne' punteggiatura. */
function fc_ai_chiave(string $s): string {
    $s = mb_strtolower($s, 'UTF-8');
    $s = strtr($s, ['à' => 'a', 'è' => 'e', 'é' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u']);
    $s = preg_replace('/[^a-z0-9 ]/u', '', $s);
    return trim(preg_replace('/\s+/', ' ', $s));
}

/* ============================================================
   ESITI — la forma di cio' che le azioni restituiscono
   ============================================================
   Ogni azione torna un array, mai una risposta HTTP. Chi la chiama decide
   cosa farne: fc_ai.php lo spedisce al browser, il motore dei job lo scrive
   nel passo. I token viaggiano sempre, anche negli errori, perche' anche un
   tentativo fallito e' stato pagato a Google — da noi, non dall'utente. */

function fc_ai_token_nuovo(): array {
    return ['utili' => 0, 'totali' => 0];
}

function fc_ai_riuscito(array $dati, array $token): array {
    return ['ok' => true, 'dati' => $dati, 'error' => '', 'http' => 200,
            'riprovabile' => false, 'token' => $token, 'modello' => FC_AI_MODELLO];
}

function fc_ai_fallito(string $errore, int $http, array $token): array {
    return ['ok' => false, 'dati' => null, 'error' => $errore, 'http' => $http,
            'riprovabile' => fc_ai_riprovabile($errore), 'token' => $token, 'modello' => FC_AI_MODELLO];
}

/**
 * Se ha senso riprovare la stessa richiesta. Serve al client per scegliere
 * fra il tasto "Riprova" e un messaggio diverso, e al motore dei job per
 * decidere se rimettere il passo in coda o segnarlo fallito.
 *
 * Non sono riprovabili gli errori che si ripeterebbero identici: la chiave
 * sbagliata, una richiesta malformata, una risposta troncata perche' troppo
 * lunga (va accorciato il lotto, non ripetuto), un contenuto rifiutato.
 */
function fc_ai_riprovabile(string $errore): bool {
    $e = preg_replace('/^ia_/', '', $errore);
    return in_array($e, ['sovraccarico', 'rete', 'lento', 'tempo', 'quota', 'json', 'vuota', 'nessuna_carta', 'nessun_concetto', 'nessun_esempio'], true);
}

/* ============================================================
   STIMA DEI TOKEN
   ============================================================
   piano_helpers ha gia' nisama_verifica_limite_stimato(), che usa la media
   storica delle chiamate. Qui NON va bene: quella media mescola le chiamate
   piccole (una spiegazione) con queste, che sono di un altro ordine di
   grandezza, e la dimensione di QUESTA richiesta la conosciamo esattamente
   prima di partire. Meglio una stima vera e poi il conteggio reale. */

function fc_ai_token_da_caratteri(int $caratteri): int {
    return (int)ceil($caratteri / FC_AI_CHAR_PER_TOKEN);
}

/**
 * Stima (ingresso + uscita) di una chiamata.
 * $uscita_attesa e' in token: la parte piu' cara, perche' quasi ovunque
 * l'uscita costa 3-4 volte l'ingresso.
 */
function fc_ai_stima(int $caratteri_ingresso, int $uscita_attesa): int {
    return fc_ai_token_da_caratteri($caratteri_ingresso) + $uscita_attesa;
}

/** Preventivo per ciascuna azione, in token. */
function fc_ai_preventivo(string $azione, array $in): int {
    $prompt_fisso = 900; // istruzioni + specifica di formato, sempre presenti

    /* L'impegno scelto aggiunge i token di ragionamento, pagati come l'uscita;
       la carta modello si rilegge a ogni chiamata. Entrambi vanno nel
       preventivo, o l'utente scoprirebbe il prezzo di "massimo" solo dopo. */
    $opz = (array)($in['opzioni'] ?? []);
    $impegno = fc_ai_impegno_leggi($opz['impegno'] ?? 'medio');
    $stile = fc_ai_stile_leggi($opz['stile'] ?? []);
    $car_stile = mb_strlen($stile['modello_q'] . $stile['modello_a']) + (fc_ai_stile_vuoto($stile) ? 0 : 600);

    switch ($azione) {
        case 'concetti':
            $car = mb_strlen((string)($in['testo'] ?? '')) + mb_strlen((string)($in['indicazioni'] ?? ''));
            $car += mb_strlen(implode(' ', array_map('strval', (array)($in['elenco'] ?? []))));
            /* Un concetto in uscita e' titolo + ancora + due bandierine:
               ~45 token. Quanti ne uscira'? Sulla prosa didattica viene circa un
               concetto ogni 600 caratteri. */
            $attesi = max(8, (int)ceil($car / 600));
            return fc_ai_stima($car + $prompt_fisso * 3, $attesi * 45) + fc_ai_pensiero_atteso($impegno, 'concetti');

        case 'densita':
            $car = mb_strlen(implode(' ', array_map('strval', (array)($in['titoli'] ?? []))));
            $car += mb_strlen((string)($in['testo'] ?? ''));
            $n = max(1, count((array)($in['titoli'] ?? [])));
            /* "meno" restituisce solo le fusioni (pochissimo), "piu'" le
               aggiunte: mezzo elenco nel caso peggiore. */
            $uscita = ($in['verso'] ?? '') === 'meno' ? max(60, $n * 4) : max(200, (int)($n / 2) * 45);
            return fc_ai_stima($car + $prompt_fisso, $uscita);

        case 'migliora':
            /* Una carta sola: ne' concetti ne' lotto. L'ingresso e' quello che
               gia' c'e' scritto (o le indicazioni, su una carta vuota), non un
               documento da leggere da capo. */
            $car = mb_strlen((string)($in['domanda'] ?? '')) + mb_strlen((string)($in['risposta'] ?? ''))
                 + mb_strlen((string)($in['nota'] ?? '')) + mb_strlen((string)($in['estratto'] ?? ''));
            $uscita = fc_ai_bool($in['opzioni']['esempi'] ?? false) ? 190 : 130;
            return fc_ai_stima($car + $car_stile + $prompt_fisso, $uscita) + fc_ai_pensiero_atteso($impegno, 'migliora');

        case 'esempio':
            /* La carta di partenza, le indicazioni e l'esempio da scrivere: un
               esempio sta sui 200 token, un esercizio svolto anche il doppio. */
            $car = mb_strlen((string)($in['q'] ?? '')) + mb_strlen((string)($in['a'] ?? ''))
                 + mb_strlen((string)($in['extra'] ?? ''));
            $uscita = in_array($stile['esempio'], ['esercizio'], true) || $stile['lunghezza'] === 'lunga' ? 450 : 220;
            return fc_ai_stima($car + $car_stile + $prompt_fisso, $uscita) + fc_ai_pensiero_atteso($impegno, 'esempio');

        case 'carte':
            $concetti = (array)($in['concetti'] ?? []);
            /* Per il solo preventivo il client puo' mandare la LUNGHEZZA degli
               estratti invece degli estratti: caricare 25.000 caratteri per
               sapere quanto costerebbe caricarli sarebbe un giro a vuoto. */
            $car = isset($in['caratteri']) ? max(0, (int)$in['caratteri']) : mb_strlen((string)($in['estratto'] ?? ''));
            foreach ($concetti as $c) $car += mb_strlen((string)($c['t'] ?? ''));
            /* Una carta domanda+risposta formattata sta sui 130 token; con
               l'esempio acceso si arriva a ~190. */
            $per_carta = fc_ai_bool($in['opzioni']['esempi'] ?? false) ? 190 : 130;
            // una risposta lunga o esaustiva scrive di piu': il preventivo lo sa
            if ($stile['lunghezza'] === 'lunga' || $stile['profondita'] === 'esaustivo') $per_carta *= 2;
            return fc_ai_stima($car + $car_stile + $prompt_fisso * 2, max(1, count($concetti)) * $per_carta)
                 + fc_ai_pensiero_atteso($impegno, 'carte');
    }
    return 0;
}

/**
 * Controlla che l'utente possa permettersi la chiamata. Restituisce
 * ['ok'=>bool, 'stima'=>int, 'disponibili'=>?int].
 *
 * Il consumo vero viene addebitato DOPO, con i numeri che torna Gemini: qui si
 * fa solo il preventivo. Non e' una prenotazione: due chiamate lanciate insieme
 * possono ancora sforare di poco, ed e' un compromesso accettato — l'alternativa
 * (una riga di impegno da scrivere e ripulire) costa piu' di quanto valga.
 *
 * Legge il saldo da ai_crediti_stato(), la stessa funzione che usa il motore
 * dei job: un solo modo di dire "quanti crediti restano".
 */
function fc_ai_controlla_budget(int $user_id, int $stima): array {
    $s = ai_crediti_stato($user_id);
    if ($s['disponibili'] === null) {
        return ['ok' => true, 'stima' => $stima, 'disponibili' => null];
    }
    return ['ok' => ($stima <= $s['disponibili']), 'stima' => $stima, 'disponibili' => $s['disponibili']];
}

/* ============================================================
   SPECIFICA DEL FORMATO — la parte che l'IA deve rispettare
   ============================================================
   Nowdoc apposta: qui dentro ci sono $ e \ che devono arrivare al modello
   esattamente come sono scritti.

   E' volutamente corta. Una specifica lunga non fa scrivere meglio: fa solo
   pagare piu' token a ogni singola chiamata, ed e' il controllo automatico a
   valle — non la lunghezza delle istruzioni — a garantire il formato. */

function fc_ai_spec_formato(): string {
    return <<<'SPEC'
FORMATO DEL TESTO (obbligatorio, e' il formato del sito):
- grassetto /bold{...}, corsivo /italic{...}, sottolineato /underline{...}, evidenziato /yellow{...}
- colori /red{...} /blue{...} /green{...} /orange{...} /purple{...} /gray{...}
- formula in linea $...$ ; formula centrata su riga propria $$...$$ ; sintassi LaTeX
- elenco puntato: /list{ una riga per punto, ogni riga inizia con "-" ; "--" per il secondo livello }
- a capo semplice: \n
Non usare Markdown (niente **, ##, backtick), niente HTML, niente emoji.
Le graffe dei tag devono essere sempre chiuse e i delimitatori $ sempre in coppia: prima di
proseguire a scrivere dopo un $, controlla di aver aperto e chiuso quella stessa formula — un $
dimenticato trasforma tutto il testo scritto dopo in una formula, fino al prossimo $ che trova.
Dentro una formula ($...$ o $$...$$) non va MAI nessun tag di formattazione: niente /bold{},
niente /red{} ne' gli altri colori. La formattazione dentro le formule non e' supportata, e la
riga intera smette di funzionare. Se un pezzo di formula deve risaltare, lascialo scritto in
LaTeX puro, senza colore.
Per le formule usa SOLO $ e $$. Mai \( \) ne' \[ \]: quel sito non li riconosce e restano scritti
cosi' come sono, in chiaro, invece di diventare una formula.
SPEC;
}

/* ============================================================
   IL TEMPO — un budget, come i token
   ============================================================
   Su un hosting condiviso max_execution_time sta fra 30 e 60 secondi. Quando
   scade PHP muore a meta': non esce nessun JSON, esce una pagina d'errore, e
   il client puo' solo dire "errore di rete". Riprovando va, perche' la
   seconda volta Gemini risponde in dieci secondi invece che in quaranta.

   La regola qui e': si sa quanto tempo resta, e non si comincia mai qualcosa
   che non si fa in tempo a finire. Un errore pulito ("lento", riprovabile) il
   client e il motore dei job lo sanno gestire; una morte improvvisa no. */

/**
 * Fissa il tempo a disposizione di questa richiesta. Senza argomento lo
 * ricava da max_execution_time, meno un margine per scrivere la risposta e
 * aggiornare i contatori. Il motore dei job lo chiama con un valore suo.
 */
function fc_ai_scadenza_imposta(?float $secondi = null): void {
    if ($secondi === null) {
        $limite = (int)ini_get('max_execution_time');
        /* 0 vuol dire "nessun limite" (riga di comando, o server configurato
           cosi'). Non e' un invito ad aspettare all'infinito: davanti c'e'
           comunque qualcuno che guarda una rotellina, e un proxy con un suo
           tempo massimo di cui nessuno ci avvisa. */
        if ($limite <= 0) $limite = 90;
        $secondi = $limite - 4;
    }
    $GLOBALS['fc_ai_scadenza'] = microtime(true) + max(5.0, $secondi);
}

/** Secondi ancora spendibili in questa richiesta. */
function fc_ai_tempo_rimasto(): float {
    if (!isset($GLOBALS['fc_ai_scadenza'])) fc_ai_scadenza_imposta();
    return $GLOBALS['fc_ai_scadenza'] - microtime(true);
}

/* ============================================================
   CHIAMATA A GEMINI
   ============================================================ */

/**
 * Una chiamata sola. Restituisce ['ok'=>bool, 'dati'=>array|null,
 * 'token'=>int, 'errore'=>string].
 *
 * $sistema e' l'istruzione stabile (identica fra le chiamate della stessa
 * sessione di lavoro): tenerla immutata e in testa e' cio' che permette alla
 * cache di contesto di far pagare poco le ripetizioni.
 *
 * L'errore torna classificato, perche' da quella parola dipendono tre
 * decisioni diverse: se ritentare, quanto aspettare, cosa dire all'utente.
 * Prima finiva tutto sotto "rete", e cosi' un limite di quota (ritentare non
 * serve mai) veniva ritentato come un 503 passeggero (ritentare risolve).
 */
function fc_ai_gemini(string $sistema, string $utente, array $schema, int $pensiero = 0, float $temperatura = 0.25): array {
    /* Sotto i otto secondi non vale la pena partire: nessuna risposta utile
       arriva cosi' in fretta, e partire vorrebbe dire farsi tagliare a meta'
       invece di restituire un errore che si sa gestire. */
    $resta = fc_ai_tempo_rimasto();
    if ($resta < 8.0) {
        return ['ok' => false, 'dati' => null, 'token' => 0, 'errore' => 'tempo'];
    }

    $payload = [
        'systemInstruction' => ['parts' => [['text' => $sistema]]],
        'contents' => [['role' => 'user', 'parts' => [['text' => $utente]]]],
        'generationConfig' => [
            /* Bassa di serie: qui non serve fantasia, serve fedelta' al
               documento e al formato. La alza solo il registro discorsivo
               (fc_ai_temperatura), dove a 0,25 una spiegazione esce legnosa. */
            'temperature' => $temperatura,
            'topP' => 0.9,
            'responseMimeType' => 'application/json',
            'responseSchema' => $schema,
            /* gemini-2.5-flash "pensa" di default, e i token di pensiero si
               pagano come quelli in uscita. Sull'estrazione e sulle fusioni non
               serve (0); sulla generazione delle carte un budget piccolo si
               ripaga, perche' evita rigenerazioni. */
            'thinkingConfig' => ['thinkingBudget' => $pensiero],
        ],
    ];

    $ch = curl_init(GEMINI_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        // mai piu' di quanto resta, e mai piu' di un tentativo ragionevole
        CURLOPT_TIMEOUT => (int)floor(min($resta - 2.0, FC_AI_TENTATIVO_MAX)),
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $risposta = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errno = curl_errno($ch);
    $errCurl = curl_error($ch);
    curl_close($ch);

    $j = ($risposta === false) ? null : json_decode($risposta, true);
    $token = (int)($j['usageMetadata']['totalTokenCount'] ?? 0);

    $errore = '';
    if ($errno === CURLE_OPERATION_TIMEOUTED)      $errore = 'lento';
    elseif ($errno !== 0 || $risposta === false)    $errore = 'rete';
    elseif ($http === 429)                          $errore = 'quota';      // troppe richieste al minuto
    elseif ($http === 401 || $http === 403)         $errore = 'chiave';
    elseif ($http === 400)                          $errore = 'richiesta';  // il nostro payload e' sbagliato
    elseif ($http >= 500)                           $errore = 'sovraccarico';
    elseif ($http !== 200)                          $errore = 'rete';

    if ($errore !== '') {
        sec_log_error('fc_ai', "Gemini $errore http=$http curl=$errno " . mb_substr($errCurl, 0, 200));
        return ['ok' => false, 'dati' => null, 'token' => $token, 'errore' => $errore];
    }

    /* HTTP 200 con un motivo di interruzione. MAX_TOKENS vuol dire risposta
       tagliata a meta': il JSON non chiude, e ripeterla uguale la taglierebbe
       di nuovo. Va accorciato il lotto, non ripetuta la chiamata. */
    $motivo = $j['candidates'][0]['finishReason'] ?? '';
    if ($motivo === 'MAX_TOKENS') {
        return ['ok' => false, 'dati' => null, 'token' => $token, 'errore' => 'troncata'];
    }
    if (in_array($motivo, ['SAFETY', 'PROHIBITED_CONTENT', 'RECITATION', 'BLOCKLIST'], true)) {
        return ['ok' => false, 'dati' => null, 'token' => $token, 'errore' => 'filtro'];
    }

    $testo = $j['candidates'][0]['content']['parts'][0]['text'] ?? '';
    if ($testo === '') {
        return ['ok' => false, 'dati' => null, 'token' => $token, 'errore' => 'vuota'];
    }

    /* Con responseMimeType=application/json le recinzioni ``` non dovrebbero
       arrivare mai, ma toglierle costa una riga e salva la richiesta invece di
       buttarla. */
    $testo = trim(preg_replace('/^```[a-z]*|```$/mi', '', trim($testo)));
    $dati = json_decode($testo, true);
    if (!is_array($dati)) {
        return ['ok' => false, 'dati' => null, 'token' => $token, 'errore' => 'json'];
    }
    return ['ok' => true, 'dati' => $dati, 'token' => $token, 'errore' => ''];
}

/**
 * Chiamata con un ritentativo, dentro il budget di tempo.
 *
 * Il secondo giro parte solo per gli errori che riprovare puo' risolvere
 * (sovraccarico, rete, lentezza, JSON illeggibile, risposta vuota), e solo se
 * resta il tempo di completarlo: altrimenti si restituisce subito l'errore,
 * che il chiamante sa gestire.
 *
 * $token accumula in due voci. 'totali': tutto cio' che Google ci ha fatto
 * pagare, tentativi falliti compresi — resta nei registri, e' il costo nostro.
 * 'utili': solo il tentativo andato a buon fine — e' cio' che si addebita
 * all'utente. Prima si sommava tutto e si addebitava tutto: tecnicamente
 * giusto, ma su una funzione a pagamento vuol dire far pagare due volte
 * all'utente un 503 del nostro fornitore.
 */
function fc_ai_gemini_ritenta(string $sistema, string $utente, array $schema, int $pensiero,
                              array &$token, string $etichetta = 'ia', float $temperatura = 0.25): array {
    $t0 = microtime(true);
    $r = fc_ai_gemini($sistema, $utente, $schema, $pensiero, $temperatura);
    $token['totali'] += $r['token'];

    if (!$r['ok'] && in_array($r['errore'], ['sovraccarico', 'rete', 'lento', 'json', 'vuota'], true)) {
        // mezzo secondo abbondante: quasi tutti i 503 passano da soli
        if (fc_ai_tempo_rimasto() > 12.0) {
            usleep(700000 + random_int(0, 300000));
            $r = fc_ai_gemini($sistema, $utente, $schema, $pensiero, $temperatura);
            $token['totali'] += $r['token'];
        }
    }
    if ($r['ok']) $token['utili'] += $r['token'];

    /* Il tempo medio per azione serve a mostrare un'attesa credibile prima di
       lanciare la chiamata (nisama_get_stima_tempo_ia lo rilegge da li'). */
    nisama_registra_tempo_ia('fc_' . $etichetta, (int)round((microtime(true) - $t0) * 1000));
    return $r;
}

/* ============================================================
   VALIDAZIONE DELL'USCITA — deterministica, in PHP
   ============================================================
   fc_ai_tag_ammessi(), fc_ai_ripara_formato() e fc_ai_valida_formato() sono
   in fc_ai_formato.php, incluso in cima a questo file: le usa anche
   ai_example.php, un endpoint piu' vecchio e piu' piccolo che genera solo
   esempi e prima non aveva alcun controllo di formato.

   Il controllo "l'IA ha fatto quello che le ho chiesto?" non si chiede
   all'IA: si calcola. Costa zero token, ed e' per lo stesso motivo che la
   riparazione (anche quella e' gratis, e' testo che rigiriamo noi) va
   SEMPRE tentata prima del rifiuto: rifiutare vuol dire un'altra chiamata,
   cioe' altri token spesi per un problema che spesso si sistema da solo. */

/**
 * Controlla un lotto di carte contro i concetti richiesti.
 * Restituisce ['ok'=>[carte buone], 'da_rifare'=>[indice => motivo]].
 */
function fc_ai_valida_lotto(array $carte, array $indici_attesi, bool $esempi_ammessi, ?array $stile = null): array {
    $buone = [];
    $problemi = [];
    $visti = [];

    foreach ($carte as $c) {
        if (!is_array($c) || !isset($c['i'])) continue;
        $i = (int)$c['i'];
        if (!in_array($i, $indici_attesi, true)) continue; // indice inventato: si scarta
        if (isset($visti[$i])) {                            // doppione: vale il primo
            continue;
        }
        $visti[$i] = true;

        $q = fc_ai_testo($c['q'] ?? '', 4000);
        $a = fc_ai_testo($c['a'] ?? '', 8000);

        /* Prima si ripara (gratis, locale), poi si valida quello che resta —
           mai il contrario: valutare il testo originale e scartarlo a quel
           punto vorrebbe dire buttare via anche i casi che la riparazione
           avrebbe sistemato da sola. */
        $q = fc_ai_ripara_formato($q);
        $a = fc_ai_ripara_formato($a);

        $err = fc_ai_valida_formato($q);
        if ($err === '') $err = fc_ai_valida_formato($a);
        /* Poi lo stile scelto dall'utente, con lo stesso meccanismo: il motivo
           torna al modello nel secondo giro. Con tutto su "auto" non rifiuta
           niente. */
        if ($err === '' && $stile) $err = fc_ai_stile_verifica($stile, $q, $a);
        if ($err !== '') { $problemi[$i] = $err; continue; }

        $carta = ['i' => $i, 'q' => $q, 'a' => $a];

        /* Un solo esempio per carta: e' quello che regge fc_examples, dove la
           carta e' la chiave. Se ne arrivano di piu' si tiene il primo invece
           di rifare la chiamata: il di piu' e' un di piu', non un errore.
           Anche qui si ripara prima di giudicare: solo se resta rotto dopo il
           tentativo si fa rifare l'intera carta, con lo stesso motivo che
           gia' usa il giro di correzione per domanda e risposta — non esiste
           un modo per chiedere all'IA "solo l'esempio di nuovo" senza
           riscrivere anche il resto nello stesso turno di conversazione. */
        if ($esempi_ammessi && !empty($c['e'])) {
            $e = is_array($c['e']) ? (string)reset($c['e']) : (string)$c['e'];
            $e = fc_ai_testo($e, 4000);
            $e = fc_ai_ripara_formato($e);
            $err_e = $e !== '' ? fc_ai_valida_formato($e) : '';
            // e lo stile scelto per gli esempi (tipo, lunghezza, ambito)
            if ($err_e === '' && $e !== '' && $stile) $err_e = fc_ai_stile_verifica_esempio($stile, $e);
            if ($err_e !== '') { $problemi[$i] = 'esempio: ' . $err_e; continue; }
            if ($e !== '') $carta['e'] = $e;
        }

        /* Provenienza e correzioni: servono al popup che avvisa "qui ho
           corretto". Sono dati, non interpretazioni nostre a posteriori. */
        $carta['src'] = fc_ai_enum($c['src'] ?? 'file', ['file', 'ia'], 'file');
        if (!empty($c['corr'])) $carta['corr'] = fc_ai_riga($c['corr'], 400);

        $buone[$i] = $carta;
    }

    // Concetti rimasti senza carta: sono la vera dimenticanza da far rifare
    foreach ($indici_attesi as $i) {
        if (!isset($buone[$i]) && !isset($problemi[$i])) $problemi[$i] = 'nessuna carta generata';
    }

    return ['ok' => $buone, 'da_rifare' => $problemi];
}

/**
 * Normalizza il legame padre-figlio: nel sito un figlio non puo' avere figli.
 * Se il modello incatena tre livelli, la correzione la facciamo qui invece di
 * spendere una chiamata per spiegarglielo di nuovo.
 */
function fc_ai_appiana_gerarchia(array $concetti): array {
    $n = count($concetti);
    foreach ($concetti as $i => $c) {
        $p = $c['p'];
        if ($p === null) continue;
        if ($p < 0 || $p >= $n || $p === $i) { $concetti[$i]['p'] = null; continue; }
        // il padre e' a sua volta figlio: si promuove questo a padre
        if ($concetti[$p]['p'] !== null) $concetti[$i]['p'] = null;
    }

    /* Qui NON si tocca il numero di figli. Un padre con un figlio solo e' un
       raggruppamento legittimo -- un teorema con il suo corollario, una
       definizione con il suo caso particolare -- e scioglierlo sarebbe imporre
       un gusto nostro sopra una scelta che il modello ha fatto leggendo il
       testo. Si appiattisce solo cio' che il database non regge: i nipoti. */
    return $concetti;
}

/* ============================================================
   SCHEMI DI RISPOSTA
   ============================================================
   Con responseSchema il modello non puo' proprio emettere una forma diversa:
   e' quello che fa sparire quasi tutti i ritentativi "formato sbagliato", che
   sono token pagati due volte per avere la stessa cosa. */

function fc_ai_schema_concetti(): array {
    return [
        'type' => 'OBJECT',
        'properties' => [
            'c' => [
                'type' => 'ARRAY',
                'items' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        't' => ['type' => 'STRING'],   // titolo del concetto
                        'a' => ['type' => 'STRING'],   // ancora: citazione letterale breve
                        'p' => ['type' => 'INTEGER'],  // indice del padre, -1 se nessuno
                        'e' => ['type' => 'INTEGER'],  // 1 se merita un esempio
                    ],
                    'required' => ['t'],
                ],
            ],
        ],
        'required' => ['c'],
    ];
}

function fc_ai_schema_fusioni(): array {
    return [
        'type' => 'OBJECT',
        'properties' => [
            'f' => ['type' => 'ARRAY', 'items' => ['type' => 'ARRAY', 'items' => ['type' => 'INTEGER']]],
            't' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
        ],
        'required' => ['f', 't'],
    ];
}

function fc_ai_schema_aggiunte(): array {
    return [
        'type' => 'OBJECT',
        'properties' => [
            'a' => [
                'type' => 'ARRAY',
                'items' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        't' => ['type' => 'STRING'],
                        'a' => ['type' => 'STRING'],
                        'd' => ['type' => 'INTEGER'], // va inserito DOPO questo indice
                    ],
                    'required' => ['t'],
                ],
            ],
        ],
        'required' => ['a'],
    ];
}

function fc_ai_schema_carte(bool $esempi): array {
    $props = [
        'i' => ['type' => 'INTEGER'],
        'q' => ['type' => 'STRING'],
        'a' => ['type' => 'STRING'],
        'src' => ['type' => 'STRING'],
        'corr' => ['type' => 'STRING'],
    ];
    if ($esempi) $props['e'] = ['type' => 'STRING'];

    return [
        'type' => 'OBJECT',
        'properties' => [
            'c' => [
                'type' => 'ARRAY',
                'items' => ['type' => 'OBJECT', 'properties' => $props, 'required' => ['i', 'q', 'a']],
            ],
        ],
        'required' => ['c'],
    ];
}

/* ============================================================
   ISTRUZIONE DI SISTEMA
   ============================================================ */

function fc_ai_sistema(string $lingua, ?array $stile = null): string {
    /* Con il registro discorsivo "asciutte, niente giri di parole" direbbe il
       contrario di cio' che l'utente ha chiesto: due istruzioni opposte nella
       stessa chiamata, e vincerebbe quella scritta per prima, cioe' questa. */
    $tono = ($stile && $stile['registro'] === 'discorsivo')
        ? "risposte spiegate con calma, come a voce, ma senza divagare: "
        : "risposte complete ma asciutte: ";
    return "Sei un docente che prepara flashcard di studio in lingua $lingua. "
         . "Scrivi domande brevi e non ambigue e " . $tono
         . "chi legge la risposta deve poter verificare da solo se sapeva rispondere. "
         . "Niente formule di cortesia, niente ripetizione della domanda "
         . "dentro la risposta. Rispondi sempre e solo con il JSON richiesto.";
}

/* ============================================================
   LE AZIONI
   ============================================================
   Ognuna riceve l'ingresso gia' decodificato ($in, la stessa forma che il
   browser manda a fc_ai.php) e restituisce un esito di fc_ai_riuscito() o
   fc_ai_fallito(). Nessuna addebita: lo fa chi chiama, che sa se il lavoro e'
   stato consegnato. Nessun controllo del budget: anche quello e' di chi
   chiama, che conosce il contesto (una chiamata sola, o un passo di un job). */

/**
 * Dal testo (o da un elenco/argomento) l'elenco dei concetti chiave, con
 * l'ancora nel documento.
 */
function fc_ai_azione_concetti(array $in, string $lingua): array {
    $token = fc_ai_token_nuovo();
    $fonte = fc_ai_enum($in['fonte'] ?? 'file', ['file', 'elenco', 'argomento'], 'file');
    $testo = fc_ai_documento($in['testo'] ?? '', FC_AI_MAX_TESTO);
    $titolo = fc_ai_riga($in['titolo'] ?? '', 200);
    $indicazioni = fc_ai_indicazioni($in['indicazioni'] ?? '');
    $vuole_esempi = fc_ai_bool($in['opzioni']['esempi'] ?? false);
    $esempi_figli = fc_ai_bool($in['opzioni']['esempi_figli'] ?? false);
    $vuole_gerarchia = fc_ai_bool($in['opzioni']['gerarchia'] ?? false);
    $solo_elenco = fc_ai_bool($in['opzioni']['solo_elenco'] ?? false);

    $elenco = [];
    foreach ((array)($in['elenco'] ?? []) as $v) {
        $t = fc_ai_riga($v, 300);
        if ($t !== '') $elenco[] = $t;
        if (count($elenco) >= FC_AI_MAX_CONCETTI) break;
    }

    if ($fonte === 'file' && $testo === '') {
        return fc_ai_fallito('testo_mancante', 400, $token);
    }
    if ($fonte === 'elenco' && !$elenco) {
        return fc_ai_fallito('elenco_mancante', 400, $token);
    }
    if ($fonte === 'argomento' && $titolo === '') {
        return fc_ai_fallito('argomento_mancante', 400, $token);
    }

    /* Il documento va IN TESTA e non cambia mai: e' la parte che la cache di
       contesto puo' riusare nelle chiamate successive sullo stesso file. Le
       istruzioni, che invece cambiano, stanno in coda. */
    $u = '';
    if ($testo !== '') $u .= "DOCUMENTO:\n" . $testo . "\n\n";
    if ($titolo !== '') $u .= "ARGOMENTO: " . $titolo . "\n";
    if ($elenco) $u .= "ELENCO FORNITO DALLO STUDENTE:\n- " . implode("\n- ", $elenco) . "\n";
    if ($indicazioni !== '') {
        /* Le stesse indicazioni arrivano anche alla scrittura delle carte (vedi
           fc_ai_azione_carte). Qui valgono solo per la SCELTA dei concetti: se
           parlano di come scrivere le risposte, il modello deve ignorarle ora,
           invece di provare a rispettarle sui titoli. */
        $u .= "INDICAZIONI DELLO STUDENTE (qui contano solo per scegliere i concetti; "
            . "quelle sulla forma delle carte verranno applicate dopo):\n"
            . fc_ai_recinto($indicazioni) . "\n";
    }

    $u .= "\nCOMPITO: elenca i concetti chiave che meritano una flashcard.\n"
        . "Ogni voce e' un TITOLO, non una spiegazione: \"Teorema delle probabilita' totali\", "
        . "\"Indipendenza di variabili aleatorie\". Mai una frase, mai una definizione.\n"
        . "Un concetto per flashcard: se due cose si studiano separatamente, sono due voci.\n";

    if ($fonte === 'file') {
        /* L'ancora e' il perno di tutto l'impianto: e' cio' che permette alle
           chiamate successive di ricevere solo il pezzo di documento che serve
           invece del documento intero, ed e' anche cio' che rende visibile allo
           studente da dove viene ogni carta. Deve essere COPIATA, non riscritta,
           o non la ritroviamo nel testo. */
        $u .= "Per ogni concetto copia in \"a\" una citazione LETTERALE di 4-8 parole "
            . "presa dal documento, dal punto in cui il concetto e' trattato. "
            . "Copiala carattere per carattere, senza riscriverla.\n";
    }
    if ($elenco && !$solo_elenco) {
        $u .= "L'elenco dello studente e' una traccia, non un vangelo: correggi cio' che e' "
            . "impreciso, unisci i doppioni e aggiungi i concetti importanti che mancano.\n";
    } elseif ($elenco && $solo_elenco) {
        $u .= "Attieniti ai concetti dell'elenco: puoi correggerne il titolo, non aggiungerne di nuovi.\n";
    }
    if ($vuole_gerarchia) {
        /* La regola strutturale del sito va detta esplicitamente, ma non ci si
           fida: fc_ai_appiana_gerarchia() la fa comunque rispettare. */
        $u .= "In \"p\" metti l'indice (0-based, riferito a questo stesso elenco) del concetto "
            . "padre, oppure -1. Un solo livello: un concetto che ha un padre non puo' averne figli.\n"
            . "Usa il legame padre-figlio quando un concetto e' un aspetto, un caso particolare, "
            . "un corollario o un passaggio di un altro concetto dell'elenco. Anche un figlio solo "
            . "va bene: un teorema con il suo corollario e' un legame giusto.\n"
            . "Il padre dev'essere a sua volta un concetto che merita la sua flashcard, non "
            . "un'etichetta di raccolta: \"Integrali\", \"Introduzione\", \"Definizioni\" non sono padri.\n"
            . "Nel dubbio metti -1: un elenco piatto e' meglio di raggruppamenti forzati.\n";

        /* Da un ELENCO scritto a mano la prudenza si ritorce contro. Chi scrive
           "permutazioni, disposizioni, combinazioni" si aspetta di ritrovare
           sotto "disposizioni" i due casi con e senza ripetizione: sono le
           voci che studia davvero, e se non escono il raggruppamento sembra
           non funzionare. Da un documento no: li' i concetti ci sono gia'
           scritti e inventarne di nuovi vuol dire allontanarsi dal testo. */
        if ($elenco && !$solo_elenco) {
            $u .= "Le voci dell'elenco sono titoli larghi scritti a mano. Quando una si divide in "
                . "casi standard della materia (con/senza ripetizione, finito/infinito, discreto/continuo, "
                . "il teorema e il suo corollario), aggiungi quei casi come figli di quella voce invece "
                . "di lasciarla da sola: sono loro le flashcard che servono per studiare.\n";
        }
    } else {
        $u .= "Metti sempre \"p\": -1.\n";
    }
    if (!$vuole_esempi) {
        $u .= "Metti sempre \"e\": 0.\n";
    } else {
        $u .= "In \"e\" metti 1 per i concetti che si capiscono davvero solo con un esempio concreto, 0 per gli altri.\n";
        /* Se gli esempi sui figli non li vuole, e' inutile farli scegliere al
           modello per poi scartarli: sono token spesi per niente. Il filtro
           vero lo fa comunque il client, che la gerarchia ce l'ha in mano. */
        if ($vuole_gerarchia && !$esempi_figli) {
            $u .= "Metti \"e\": 1 solo su concetti senza padre (p uguale a -1).\n";
        }
    }

    /* Livello e ambito tarano quali concetti meritano una carta (per uno
       studente delle superiori "definizione di limite" si', per un esperto
       no). La forma delle risposte qui non conta: si scelgono i titoli. */
    $opz_c = (array)($in['opzioni'] ?? []);
    $taratura = fc_ai_stile_prompt(fc_ai_stile_leggi($opz_c['stile'] ?? []), 'concetti');
    if ($taratura !== '') $u .= $taratura;

    /* L'estrazione di serie non ragiona (e' lettura, non scrittura): lo fa solo
       da impegno "alto", dove su un documento difficile evita di scambiare un
       esempio per un concetto. */
    $pensiero_c = fc_ai_pensiero(fc_ai_impegno_leggi($opz_c['impegno'] ?? 'medio'), 'concetti');
    $r = fc_ai_gemini_ritenta(fc_ai_sistema($lingua), $u, fc_ai_schema_concetti(), $pensiero_c, $token, 'concetti');

    if (!$r['ok']) {
        return fc_ai_fallito('ia_' . $r['errore'], 502, $token);
    }

    $out = [];
    foreach ((array)($r['dati']['c'] ?? []) as $c) {
        $t = fc_ai_riga($c['t'] ?? '', 300);
        if ($t === '') continue;
        $p = isset($c['p']) ? (int)$c['p'] : -1;
        $out[] = [
            't' => $t,
            'a' => fc_ai_riga($c['a'] ?? '', 300),
            'p' => ($vuole_gerarchia && $p >= 0) ? $p : null,
            'e' => ($vuole_esempi && !empty($c['e'])) ? 1 : 0,
        ];
        if (count($out) >= FC_AI_MAX_CONCETTI) break;
    }
    if (!$out) {
        return fc_ai_fallito('nessun_concetto', 502, $token);
    }

    return fc_ai_riuscito(['concetti' => array_values(fc_ai_appiana_gerarchia($out))], $token);
}

/* ============================================================
   AZIONE: densita  (piu' concetti / meno concetti)
   ============================================================
   Qui sta il risparmio piu' vistoso: "meno" non vede il documento (per capire
   che due titoli dicono la stessa cosa bastano i titoli) e restituisce solo le
   fusioni, non l'elenco riscritto. "piu'" il documento ce l'ha per forza, ma
   restituisce solo le aggiunte. */

/**
 * "piu' concetti" / "meno concetti" sull'elenco gia' ottenuto.
 */
function fc_ai_azione_densita(array $in, string $lingua): array {
    $token = fc_ai_token_nuovo();
    $verso = fc_ai_enum($in['verso'] ?? '', ['piu', 'meno'], '');
    if ($verso === '') return fc_ai_fallito('verso_mancante', 400, $token);

    $titoli = [];
    foreach ((array)($in['titoli'] ?? []) as $v) {
        $t = fc_ai_riga($v, 300);
        if ($t !== '') $titoli[] = $t;
        if (count($titoli) >= FC_AI_MAX_CONCETTI) break;
    }
    if (count($titoli) < 2) return fc_ai_fallito('elenco_troppo_corto', 400, $token);

    $numerato = '';
    foreach ($titoli as $i => $t) $numerato .= $i . '. ' . $t . "\n";

    if ($verso === 'meno') {
        $u = "ELENCO ATTUALE:\n" . $numerato
           . "\nCOMPITO: raggruppa i concetti che conviene trattare in un'unica flashcard "
           . "piu' corposa, perche' dicono la stessa cosa o si studiano insieme.\n"
           . "In \"f\" metti i gruppi come liste di indici (almeno due indici per gruppo). "
           . "In \"t\", nello stesso ordine dei gruppi, il titolo unico del gruppo.\n"
           . "Non toccare i concetti che stanno bene da soli: non devono comparire in nessun gruppo.\n";

        $r = fc_ai_gemini_ritenta(fc_ai_sistema($lingua), $u, fc_ai_schema_fusioni(), 0, $token, 'densita');
        if (!$r['ok']) return fc_ai_fallito('ia_' . $r['errore'], 502, $token);

        /* Un indice puo' stare in un gruppo solo, altrimenti la fusione lato
           client diventa ambigua e due gruppi si mangerebbero lo stesso
           concetto. Il controllo e' nostro: al modello non lo si richiede
           nemmeno, tanto va verificato comunque. */
        $usati = [];
        $gruppi = [];
        $nuovi_titoli = [];
        $tt = (array)($r['dati']['t'] ?? []);
        foreach ((array)($r['dati']['f'] ?? []) as $k => $g) {
            $g = array_values(array_unique(array_map('intval', (array)$g)));
            $g = array_values(array_filter($g, function ($i) use ($titoli, $usati) {
                return $i >= 0 && $i < count($titoli) && !isset($usati[$i]);
            }));
            if (count($g) < 2) continue;
            foreach ($g as $i) $usati[$i] = true;
            $gruppi[] = $g;
            $nuovi_titoli[] = fc_ai_riga($tt[$k] ?? $titoli[$g[0]], 300);
        }

        $modificato = count($gruppi) > 0; // serve al client per dire "non c'era piu' niente da unire"
        return fc_ai_riuscito([
            'verso' => 'meno', 'fusioni' => $gruppi,
            'titoli' => $nuovi_titoli, 'modificato' => $modificato,
        ], $token);
    }

    // verso === 'piu'
    $testo = fc_ai_documento($in['testo'] ?? '', FC_AI_MAX_TESTO);
    $u = '';
    if ($testo !== '') $u .= "DOCUMENTO:\n" . $testo . "\n\n";
    $u .= "ELENCO ATTUALE:\n" . $numerato
        . "\nCOMPITO: aggiungi i concetti chiave che mancano, piu' fini e specifici di quelli gia' presenti.\n"
        . "Restituisci SOLO le aggiunte, mai i concetti gia' in elenco.\n"
        . "In \"d\" metti l'indice del concetto dopo il quale va inserito il nuovo.\n";
    if ($testo !== '') {
        $u .= "In \"a\" una citazione letterale di 4-8 parole copiata dal documento.\n";
    }

    $r = fc_ai_gemini_ritenta(fc_ai_sistema($lingua), $u, fc_ai_schema_aggiunte(), 0, $token, 'densita');
    if (!$r['ok']) return fc_ai_fallito('ia_' . $r['errore'], 502, $token);

    /* Il modello a volte "aggiunge" un concetto che c'e' gia' con altre parole:
       il confronto sui titoli normalizzati lo intercetta senza spendere
       un'altra chiamata per chiederglielo. */
    $gia = [];
    foreach ($titoli as $t) $gia[fc_ai_chiave($t)] = true;

    $aggiunte = [];
    foreach ((array)($r['dati']['a'] ?? []) as $a) {
        $t = fc_ai_riga($a['t'] ?? '', 300);
        if ($t === '' || isset($gia[fc_ai_chiave($t)])) continue;
        $gia[fc_ai_chiave($t)] = true;
        $d = isset($a['d']) ? (int)$a['d'] : count($titoli) - 1;
        $aggiunte[] = [
            't' => $t,
            'a' => fc_ai_riga($a['a'] ?? '', 300),
            'd' => max(-1, min($d, count($titoli) - 1)),
        ];
    }

    return fc_ai_riuscito([
        'verso' => 'piu', 'aggiunte' => $aggiunte,
        'modificato' => count($aggiunte) > 0,
    ], $token);
}

/**
 * Un LOTTO di flashcard dai concetti scelti (o una sola, per la
 * rigenerazione).
 */
function fc_ai_azione_carte(array $in, string $lingua): array {
    $token = fc_ai_token_nuovo();
    $opz = (array)($in['opzioni'] ?? []);
    $esempi     = fc_ai_bool($opz['esempi'] ?? false);
    $integra    = fc_ai_bool($opz['integra'] ?? false);     // puo' usare conoscenze proprie
    $corregge   = fc_ai_bool($opz['corregge'] ?? false);    // puo' correggere errori del documento
    $testuale   = fc_ai_bool($opz['testuale'] ?? false);    // deve ricopiare le definizioni del documento
    $estratto   = fc_ai_documento($in['estratto'] ?? '', FC_AI_MAX_TESTO);
    $nota       = fc_ai_riga($in['nota'] ?? '', 500); // "rigenera, ma piu' corta" ecc.
    $indicazioni = fc_ai_indicazioni($in['indicazioni'] ?? '');
    $stile      = fc_ai_stile_leggi($opz['stile'] ?? []);
    $impegno    = fc_ai_impegno_leggi($opz['impegno'] ?? 'medio');
    $righe_stile = fc_ai_stile_prompt($stile, 'carte');
    $sistema    = fc_ai_sistema($lingua, $stile);

    $concetti = [];
    foreach ((array)($in['concetti'] ?? []) as $c) {
        $t = fc_ai_riga($c['t'] ?? '', 300);
        if ($t === '') continue;
        $concetti[] = [
            'i' => isset($c['i']) ? (int)$c['i'] : count($concetti),
            't' => $t,
            'e' => !empty($c['e']) ? 1 : 0,
        ];
        if (count($concetti) >= FC_AI_LOTTO_MAX) break;
    }
    if (!$concetti) return fc_ai_fallito('concetti_mancanti', 400, $token);

    $indici = array_column($concetti, 'i');
    $lista = '';
    foreach ($concetti as $c) {
        $lista .= $c['i'] . '. ' . $c['t'] . ($esempi && $c['e'] ? '  [con esempio]' : '') . "\n";
    }

    $u = '';
    if ($estratto !== '') $u .= "ESTRATTI DEL DOCUMENTO:\n" . $estratto . "\n\n";
    $u .= "CONCETTI DA TRASFORMARE IN FLASHCARD (il numero e' il campo \"i\" della risposta):\n" . $lista . "\n";

    $u .= "COMPITO: una flashcard per ogni concetto elencato, nessuno escluso e nessuno in piu'.\n"
        . "\"q\" e' la domanda, \"a\" la risposta.\n";

    $u .= $integra
        ? "Puoi integrare con le tue conoscenze cio' che manca negli estratti; quando lo fai metti \"src\": \"ia\".\n"
        : "Usa esclusivamente le informazioni degli estratti. Se un concetto non e' trattato, "
          . "scrivi la carta con cio' che c'e' e metti \"src\": \"ia\".\n";
    if ($corregge) {
        $u .= $integra
            ? "Se il documento contiene un errore, correggilo e spiega la correzione in \"corr\" (una riga).\n"
            : "Non aggiungere contenuti che il documento non tratta, ma verifica che i dati, i calcoli e le "
              . "affermazioni del documento siano corretti: se trovi un errore vero, correggilo e spiega la "
              . "correzione in \"corr\" (una riga). Non e' integrare — e' controllare cio' che gia' c'e'.\n";
    }
    $u .= $testuale
        ? "Ricopia le definizioni del documento parola per parola, senza riformularle.\n"
        : "Puoi riformulare con parole tue, purche' il contenuto resti esattamente quello.\n";
    if ($esempi) {
        // con "tipo di esempio" su auto la frase e' quella di sempre
        $u .= "Per i concetti segnati [con esempio] aggiungi in \"e\" " . fc_ai_stile_istruzione_esempio($stile) . ". "
            . "Un solo esempio, e solo per quelli segnati.\n";
    }
    if ($nota !== '') $u .= "RICHIESTA AGGIUNTIVA DELLO STUDENTE: " . $nota . "\n";

    /* Lo stile scelto nelle impostazioni: vuoto se e' tutto su "auto". Viene
       prima delle indicazioni libere, che hanno la precedenza su di lui. */
    if ($righe_stile !== '') $u .= "\n" . $righe_stile;

    /* LE INDICAZIONI DELLO STUDENTE — fino a questa versione non arrivavano
       mai qui: il pannello le mandava solo all'estrazione dei concetti, e solo
       con la fonte "argomento". Chi scriveva "voglio la risposta come elenco
       descrizione - codice" le vedeva applicate alla scelta dei titoli, cioe'
       a niente. Ora arrivano a chi scrive le carte, intere (a capo compresi),
       con una precedenza dichiarata: vincono su tutte le indicazioni qui
       sopra, ma non sul formato del sito, che resta l'ultima cosa letta. */
    if ($indicazioni !== '') {
        $u .= "\nISTRUZIONI DELLO STUDENTE. Hanno la precedenza su tutte le indicazioni qui sopra, "
            . "tranne che sul formato del testo descritto sotto. Se chiedono una struttura precisa "
            . "per la risposta (un elenco di voci, un ordine, delle etichette), quella struttura va "
            . "rispettata voce per voce in ogni carta.\n"
            . fc_ai_recinto($indicazioni) . "\n";
    }

    $u .= "\n" . fc_ai_spec_formato();

    /* Il budget di ragionamento lo decide l'impegno scelto ("medio" = il 512
       di sempre); la temperatura il registro. */
    $pensiero = fc_ai_pensiero($impegno, 'carte');
    $temp = fc_ai_temperatura($stile);
    $r = fc_ai_gemini_ritenta($sistema, $u, fc_ai_schema_carte($esempi), $pensiero, $token, 'carte', $temp);

    if (!$r['ok']) {
        return fc_ai_fallito('ia_' . $r['errore'], 502, $token);
    }

    $v = fc_ai_valida_lotto((array)($r['dati']['c'] ?? []), $indici, $esempi, $stile);

    /* Secondo giro MIRATO: si richiedono solo i concetti andati storti, con il
       motivo preciso. Rimandare il lotto intero significherebbe ripagare anche
       le carte che erano gia' buone — ed e' il tipo di spreco che qui si somma
       in fretta. */
    /* Il secondo giro parte solo se c'e' il tempo di finirlo: dentro il motore
       dei job ogni passo vive in una richiesta con un tempo massimo, e un giro
       interrotto a meta' costerebbe token senza consegnare niente. Le carte
       non rifatte tornano come "mancanti", e il job le puo' riprendere. */
    if ($v['da_rifare'] && fc_ai_tempo_rimasto() >= 20.0) {
        $rifare = '';
        foreach ($v['da_rifare'] as $i => $motivo) {
            $t = '';
            foreach ($concetti as $c) if ($c['i'] === $i) $t = $c['t'];
            $rifare .= $i . '. ' . $t . '  (problema: ' . $motivo . ")\n";
        }
        $u2 = ($estratto !== '' ? "ESTRATTI DEL DOCUMENTO:\n" . $estratto . "\n\n" : '')
            . "Rifai SOLO queste flashcard, correggendo il problema indicato:\n" . $rifare . "\n"
            // senza lo stile, le carte rifatte perderebbero la forma che le altre rispettano
            . ($righe_stile !== '' ? $righe_stile . "\n" : '')
            . ($indicazioni !== '' ? "Rispetta sempre queste istruzioni dello studente:\n" . fc_ai_recinto($indicazioni) . "\n\n" : '')
            . fc_ai_spec_formato();

        $r2 = fc_ai_gemini_ritenta($sistema, $u2, fc_ai_schema_carte($esempi), $pensiero, $token, 'carte', $temp);
        if ($r2['ok']) {
            $v2 = fc_ai_valida_lotto((array)($r2['dati']['c'] ?? []), array_keys($v['da_rifare']), $esempi, $stile);
            foreach ($v2['ok'] as $i => $carta) $v['ok'][$i] = $carta;
        }
    }

    if (!$v['ok']) {
        return fc_ai_fallito('nessuna_carta', 502, $token);
    }

    /* Si torna anche l'elenco di chi e' rimasto scoperto: e' cio' che permette
       all'elenco in cima alla pagina di mostrare il concetto senza numero,
       invece di far credere allo studente che sia stato trattato. */
    $mancanti = [];
    foreach ($indici as $i) if (!isset($v['ok'][$i])) $mancanti[] = $i;

    ksort($v['ok']);
    return fc_ai_riuscito(['carte' => array_values($v['ok']), 'mancanti' => $mancanti], $token);
}

/* ============================================================
   AZIONE: migliora  (una carta sola, qualunque sia la sua origine)
   ============================================================
   A differenza di 'carte', qui non c'e' un elenco di concetti ne' un lotto:
   l'ingresso e' il contenuto GIA' SCRITTO sulla carta (o, se e' vuota, le
   indicazioni dello studente che ne fanno le veci). Serve al tasto presente
   su ogni riga della tabella, comprese quelle mai passate dall'IA. */

/**
 * Una carta sola, qualunque sia la sua origine.
 */
function fc_ai_azione_migliora(array $in, string $lingua): array {
    $token = fc_ai_token_nuovo();
    $domanda  = fc_ai_documento($in['domanda'] ?? '', 6000);
    $risposta = fc_ai_documento($in['risposta'] ?? '', 8000);
    $nota     = fc_ai_riga($in['nota'] ?? '', 500);
    $estratto = fc_ai_documento($in['estratto'] ?? '', FC_AI_MAX_TESTO);
    $esempio  = fc_ai_bool($in['opzioni']['esempi'] ?? false);
    $stile    = fc_ai_stile_leggi($in['opzioni']['stile'] ?? []);
    $impegno  = fc_ai_impegno_leggi($in['opzioni']['impegno'] ?? 'medio');
    $righe_stile = fc_ai_stile_prompt($stile, 'carte');

    $vuota = (trim($domanda) === '' && trim($risposta) === '');
    if ($vuota && $nota === '') {
        // niente da migliorare e niente indicazioni: non c'e' materiale per scrivere nulla
        return fc_ai_fallito('serve_indicazione', 400, $token);
    }

    $u = '';
    if ($estratto !== '') $u .= "ESTRATTO DEL DOCUMENTO ORIGINALE:\n" . $estratto . "\n\n";

    if ($vuota) {
        // carta vuota: le indicazioni sono l'unica cosa da cui partire
        $u .= "COMPITO: scrivi una flashcard sul seguente argomento/richiesta:\n" . $nota . "\n";
    } else {
        $u .= "CARTA ATTUALE:\nDomanda: " . $domanda . "\nRisposta: " . $risposta . "\n\n"
            . "COMPITO: riscrivi questa carta spiegando lo STESSO concetto in un modo DIVERSO da "
            . "come e' scritto ora — cambia l'angolazione, l'ordine delle idee, l'esempio usato — "
            . "cosi' chi la rilegge non si ritrova le stesse frasi con qualche sinonimo cambiato. "
            . "Il contenuto deve restare corretto ed equivalente a quello di partenza.\n";
        if ($nota !== '') $u .= "RICHIESTA AGGIUNTIVA DELLO STUDENTE: " . $nota . "\n";
    }
    if ($esempio) $u .= "Aggiungi anche in \"e\" " . fc_ai_stile_istruzione_esempio($stile) . ".\n";
    if ($righe_stile !== '') $u .= "\n" . $righe_stile;
    $u .= "Restituisci un solo elemento nell'elenco \"c\", con \"i\": 0.\n";
    $u .= "\n" . fc_ai_spec_formato();

    $sistema = fc_ai_sistema($lingua, $stile);
    $pensiero = fc_ai_pensiero($impegno, 'migliora');
    $temp = fc_ai_temperatura($stile);
    $r = fc_ai_gemini_ritenta($sistema, $u, fc_ai_schema_carte($esempio), $pensiero, $token, 'migliora', $temp);

    if (!$r['ok']) {
        return fc_ai_fallito('ia_' . $r['errore'], 502, $token);
    }

    $v = fc_ai_valida_lotto((array)($r['dati']['c'] ?? []), [0], $esempio, $stile);

    /* Una carta sola che non rispetta lo stile (o il formato): un secondo
       tentativo con il motivo, come per i lotti, se c'e' il tempo. */
    if (!isset($v['ok'][0]) && isset($v['da_rifare'][0]) && fc_ai_tempo_rimasto() >= 20.0) {
        $u2 = $u . "\nIL TENTATIVO PRECEDENTE E' STATO RIFIUTATO: " . $v['da_rifare'][0] . ". Correggi questo punto.\n";
        $r2 = fc_ai_gemini_ritenta($sistema, $u2, fc_ai_schema_carte($esempio), $pensiero, $token, 'migliora', $temp);
        if ($r2['ok']) $v = fc_ai_valida_lotto((array)($r2['dati']['c'] ?? []), [0], $esempio, $stile);
    }
    if (!isset($v['ok'][0])) {
        return fc_ai_fallito('nessuna_carta', 502, $token);
    }

    return fc_ai_riuscito(['carta' => $v['ok'][0]], $token);
}


/* ============================================================
   AZIONE: esempio  (il popup degli esempi, su una carta)
   ============================================================
   Prima viveva tutta in ai_example.php, con un prompt suo, senza schema,
   senza budget di ragionamento e senza budget di tempo: gemini-2.5-flash
   decideva da se' quanto pensare, e quando pensava molto superava i 25
   secondi di timeout. Ora e' una sorella di fc_ai_azione_migliora: stesso
   formato, stesso stile, stesso impegno, stessa verifica, stesso secondo
   giro mirato. Le regole di formato sono quelle delle carte
   (fc_ai_spec_formato), non piu' una copia che rischiava di divergere. */

function fc_ai_schema_esempio(): array {
    return [
        'type' => 'OBJECT',
        'properties' => ['e' => ['type' => 'STRING']],
        'required' => ['e'],
    ];
}

/**
 * $in: q, a (il testo della carta), extra (indicazioni libere dello
 * studente), opzioni{stile, impegno}. Torna ['esempio' => testo].
 */
function fc_ai_azione_esempio(array $in, string $lingua): array {
    $token = fc_ai_token_nuovo();
    $q = fc_ai_documento($in['q'] ?? '', 6000);
    $a = fc_ai_documento($in['a'] ?? '', 8000);
    if (trim($q) === '' && trim($a) === '') return fc_ai_fallito('carta_vuota', 400, $token);

    $extra = fc_ai_indicazioni($in['extra'] ?? '');
    $opz = (array)($in['opzioni'] ?? []);
    $stile = fc_ai_stile_leggi($opz['stile'] ?? []);
    $impegno = fc_ai_impegno_leggi($opz['impegno'] ?? 'medio');

    $u = "FLASHCARD:\nDomanda: " . $q . "\nRisposta: " . $a . "\n\n"
       . "COMPITO: scrivi in \"e\" " . fc_ai_stile_istruzione_esempio($stile) . " sul concetto di questa flashcard. "
       . "Entra subito nell'esempio: niente introduzioni, niente \"ecco un esempio\". "
       . "Se e' un esercizio, la consegna e subito dopo la soluzione.\n";
    $righe = fc_ai_stile_prompt($stile, 'esempio');
    if ($righe !== '') $u .= "\n" . $righe;
    if ($extra !== '') {
        $u .= "\nISTRUZIONI DELLO STUDENTE. Hanno la precedenza su tutto il resto, tranne che sul "
            . "formato del testo descritto sotto.\n" . fc_ai_recinto($extra) . "\n";
    }
    $u .= "\n" . fc_ai_spec_formato();

    $sistema = fc_ai_sistema($lingua, $stile);
    $pensiero = fc_ai_pensiero($impegno, 'esempio');
    $temp = fc_ai_temperatura($stile, 0.4);

    $r = fc_ai_gemini_ritenta($sistema, $u, fc_ai_schema_esempio(), $pensiero, $token, 'esempio', $temp);
    if (!$r['ok']) return fc_ai_fallito('ia_' . $r['errore'], 502, $token);

    $e = fc_ai_ripara_formato(fc_ai_testo($r['dati']['e'] ?? '', 8000));
    $err = trim($e) === '' ? 'esempio vuoto' : fc_ai_valida_formato($e);
    if ($err === '') $err = fc_ai_stile_verifica_esempio($stile, $e);

    // un secondo tentativo con il motivo, come per le carte, se c'e' il tempo
    if ($err !== '' && fc_ai_tempo_rimasto() >= 20.0) {
        $u2 = $u . "\nIL TENTATIVO PRECEDENTE E' STATO RIFIUTATO: " . $err . ". Correggi questo punto.\n";
        $r2 = fc_ai_gemini_ritenta($sistema, $u2, fc_ai_schema_esempio(), $pensiero, $token, 'esempio', $temp);
        if ($r2['ok']) {
            $e = fc_ai_ripara_formato(fc_ai_testo($r2['dati']['e'] ?? '', 8000));
            $err = trim($e) === '' ? 'esempio vuoto' : fc_ai_valida_formato($e);
            if ($err === '') $err = fc_ai_stile_verifica_esempio($stile, $e);
        }
    }
    /* Un esempio che non rispetta la LUNGHEZZA chiesta ma e' ben formato si
       consegna lo stesso: e' comunque un buon esempio, e rifiutarlo dopo due
       tentativi lascerebbe l'utente a mani vuote. Uno con il formato rotto
       no: in pagina romperebbe la formula. */
    if ($err !== '' && (trim($e) === '' || fc_ai_valida_formato($e) !== '')) {
        return fc_ai_fallito('nessun_esempio', 502, $token);
    }
    return fc_ai_riuscito(['esempio' => $e], $token);
}
