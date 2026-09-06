<?php
/**
 * fc_ai.php — il cervello lato server della generazione IA delle flashcard.
 *
 * Serve inserimento_big_fc.php e risponde SEMPRE in JSON. Quattro azioni:
 *
 *   stima     quanto costerebbe (token) una richiesta, senza chiamare l'IA
 *   concetti  primo giro: dal testo (o da un elenco/argomento) tira fuori
 *             l'elenco dei concetti chiave, con l'ancora nel documento
 *   densita   "piu' concetti" / "meno concetti" sull'elenco gia' ottenuto
 *   carte     genera un LOTTO di flashcard dai concetti scelti
 *
 * PERCHE' E' DIVISO COSI'
 *
 * Il costo di questa funzione non e' la lunghezza del documento: sono le
 * chiamate ripetute con il documento allegato. Qui il testo integrale viaggia
 * UNA volta sola, nell'azione "concetti". Da li' in poi il client manda solo i
 * concetti e, per la generazione, la finestra di testo attorno all'ancora di
 * ciascuno (campo "estratto"): su un documento da 30.000 caratteri e 60 carte
 * si passa da ~180.000 caratteri inviati a ~25.000.
 *
 * PERCHE' L'IA SCRIVE IL FORMATO VECCHIO A TAG
 *
 * L'editor usa il modello a run ({v:2, blocks:[...]}), ma fcdParse() legge
 * ancora il formato a tag /bold{...} e lo converte da solo. Far scrivere all'IA
 * i tag invece del JSON dei blocchi costa 3-4 volte meno token in uscita, e
 * soprattutto e' un formato che il modello sbaglia molto meno: la conversione
 * la fa codice nostro gia' collaudato, non il modello.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security_core.php';
require_once __DIR__ . '/auth_check_api.php'; // versione API: se manca il login risponde JSON, non reindirizza
require_once __DIR__ . '/piano_helpers.php';
require_once __DIR__ . '/fc_ai_formato.php'; // validazione E riparazione, condivise con ai_example.php

$fc_ai_user_id = (int)($utente_corrente['id'] ?? 0);
if ($fc_ai_user_id <= 0) sec_die_json('Sessione non valida.', 401, 'auth');

/* Una riga sola: CSRF, origine, livello e limite di frequenza. 30 chiamate al
   minuto sono gia' molte per un uso umano e fermano sul nascere uno script
   impazzito — che qui non farebbe danni ai dati, ma brucerebbe i token
   dell'utente, che e' il danno che conta. */
sec_guard_write($fc_ai_user_id, 'fc_ai', 0, 30, 60);

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

/* ============================================================
   INGRESSO: corpo JSON, CSRF, azione
   ============================================================ */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fc_ai_esci(['ok' => false, 'error' => 'metodo'], 405);
}

$fc_ai_in = sec_body();
if (!$fc_ai_in) {
    fc_ai_esci(['ok' => false, 'error' => 'corpo_non_valido'], 400);
}

$azione = fc_ai_enum($fc_ai_in['azione'] ?? '', ['stima', 'concetti', 'densita', 'carte', 'migliora'], '');
if ($azione === '') {
    fc_ai_esci(['ok' => false, 'error' => 'azione_sconosciuta'], 400);
}

/* ============================================================
   VALIDATORI D'INGRESSO
   ============================================================
   Involucri sottili sui sec_*: esistono solo per dare un nome corto a cio' che
   in questo file si ripete decine di volte, e per avere UN punto dove cambiare
   il trattamento del testo lungo (vedi fc_ai_documento, che di proposito non
   passa da sec_text). */

function fc_ai_testo($v, int $max): string {
    return sec_text($v, $max);
}

/**
 * Ripulitura del DOCUMENTO da mandare all'IA. Non usa sec_text apposta:
 * sec_text passa da strip_tags(), che mangia tutto quello che comincia per "<"
 * seguito da una lettera. In un testo di studio "P(X <b) " o "se x <n allora"
 * sono frasi normali, e sparirebbero pezzi di formula senza che nessuno se ne
 * accorga. Qui il testo non viene mai stampato come HTML — va a Gemini e torna
 * come carte, che escono comunque da fcdToHTML() con l'escaping — quindi la
 * difesa giusta e' togliere i tag veri, non ogni minore.
 */
function fc_ai_documento($v, int $max): string {
    if (is_array($v) || is_object($v)) return '';
    $s = (string)$v;
    $s = str_replace(["\r\n", "\r"], "\n", $s);
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s);
    // solo i tag riconoscibili come HTML, con il loro contenuto se sono script o style
    $s = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $s);
    $s = preg_replace('#</?[a-z][a-z0-9]*\b[^>]*>#i', ' ', $s);
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

/* Involucro di sec_json: la forma della risposta la decide un punto solo,
   come per tutti gli altri endpoint del sito. */
function fc_ai_esci(array $payload, int $status = 200): void {
    sec_json($payload, $status);
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

    switch ($azione) {
        case 'concetti':
            $car = mb_strlen((string)($in['testo'] ?? '')) + mb_strlen((string)($in['indicazioni'] ?? ''));
            $car += mb_strlen(implode(' ', array_map('strval', (array)($in['elenco'] ?? []))));
            /* Un concetto in uscita e' titolo + ancora + due bandierine:
               ~45 token. Quanti ne uscira'? Sulla prosa didattica viene circa un
               concetto ogni 600 caratteri. */
            $attesi = max(8, (int)ceil($car / 600));
            return fc_ai_stima($car + $prompt_fisso * 3, $attesi * 45);

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
            return fc_ai_stima($car + $prompt_fisso, $uscita);

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
            return fc_ai_stima($car + $prompt_fisso * 2, max(1, count($concetti)) * $per_carta);
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
 */
function fc_ai_controlla_budget(int $user_id, int $stima): array {
    $piano = nisama_get_piano_utente($user_id);
    $limite = $piano ? $piano['text_tokens_limit'] : null;
    $usato  = $piano ? (int)$piano['monthly_text_tokens'] : 0;

    if ($limite === null) {
        return ['ok' => true, 'stima' => $stima, 'disponibili' => null];
    }
    $disponibili = max(0, (int)$limite - $usato);
    return ['ok' => ($stima <= $disponibili), 'stima' => $stima, 'disponibili' => $disponibili];
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
   CHIAMATA A GEMINI
   ============================================================ */

/**
 * Una chiamata sola. Restituisce ['ok'=>bool, 'dati'=>array|null,
 * 'token'=>int, 'errore'=>string].
 *
 * $sistema e' l'istruzione stabile (identica fra le chiamate della stessa
 * sessione di lavoro): tenerla immutata e in testa e' cio' che permette alla
 * cache di contesto di far pagare poco le ripetizioni.
 */
function fc_ai_gemini(string $sistema, string $utente, array $schema, int $pensiero = 0): array {
    $payload = [
        'systemInstruction' => ['parts' => [['text' => $sistema]]],
        'contents' => [['role' => 'user', 'parts' => [['text' => $utente]]]],
        'generationConfig' => [
            /* Bassa apposta: qui non serve fantasia, serve fedelta' al
               documento e al formato. */
            'temperature' => 0.25,
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
        CURLOPT_TIMEOUT => 120,
        CURLOPT_CONNECTTIMEOUT => 15,
    ]);
    $risposta = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errCurl = curl_error($ch);
    curl_close($ch);

    if ($risposta === false || $http !== 200) {
        sec_log_error('fc_ai', 'Gemini HTTP ' . $http . ' ' . $errCurl);
        return ['ok' => false, 'dati' => null, 'token' => 0, 'errore' => 'rete'];
    }

    $j = json_decode($risposta, true);
    $testo = $j['candidates'][0]['content']['parts'][0]['text'] ?? '';
    $token = (int)($j['usageMetadata']['totalTokenCount'] ?? 0);

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
 * Chiamata con un ritentativo. Il secondo giro parte solo se il primo e'
 * fallito per rete o per JSON illeggibile: sono i casi in cui riprovare
 * identici ha davvero senso.
 *
 * Il conteggio dei token e' cumulativo apposta: anche il tentativo fallito e'
 * stato pagato, e non addebitarlo vorrebbe dire che il limite del piano non
 * corrisponde alla spesa reale.
 */
function fc_ai_gemini_ritenta(string $sistema, string $utente, array $schema, int $pensiero, int &$token_totali): array {
    $t0 = microtime(true);
    $r = fc_ai_gemini($sistema, $utente, $schema, $pensiero);
    $token_totali += $r['token'];

    if (!$r['ok'] && in_array($r['errore'], ['rete', 'json', 'vuota'], true)) {
        usleep(700000); // mezzo secondo abbondante: quasi tutti i 503 passano da soli
        $r = fc_ai_gemini($sistema, $utente, $schema, $pensiero);
        $token_totali += $r['token'];
    }

    /* Il tempo medio per azione serve a mostrare un'attesa credibile prima di
       lanciare la chiamata (nisama_get_stima_tempo_ia lo rilegge da li'). */
    nisama_registra_tempo_ia('fc_' . ($GLOBALS['azione'] ?? 'ia'), (int)round((microtime(true) - $t0) * 1000));
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
function fc_ai_valida_lotto(array $carte, array $indici_attesi, bool $esempi_ammessi): array {
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

function fc_ai_sistema(string $lingua): string {
    return "Sei un docente che prepara flashcard di studio in lingua $lingua. "
         . "Scrivi domande brevi e non ambigue e risposte complete ma asciutte: "
         . "chi legge la risposta deve poter verificare da solo se sapeva rispondere. "
         . "Niente giri di parole, niente formule di cortesia, niente ripetizione della domanda "
         . "dentro la risposta. Rispondi sempre e solo con il JSON richiesto.";
}

/* ============================================================
   AZIONE: stima
   ============================================================ */

if ($azione === 'stima') {
    $stima = fc_ai_preventivo(fc_ai_enum($fc_ai_in['per'] ?? 'concetti', ['concetti', 'densita', 'carte'], 'concetti'), $fc_ai_in);
    $b = fc_ai_controlla_budget($fc_ai_user_id, $stima);
    fc_ai_esci([
        'ok' => true,
        'stima' => $b['stima'],
        'disponibili' => $b['disponibili'],
        'sufficiente' => $b['ok'],
    ]);
}

/* Da qui in poi si chiama davvero l'IA: prima si guarda il portafoglio.
   Se non bastano, la risposta dice "ripiego" e il client offre il prompt da
   copiare negli appunti — che e' la stessa strada di chi non ha abbonamento. */
$preventivo = fc_ai_preventivo($azione, $fc_ai_in);
$budget = fc_ai_controlla_budget($fc_ai_user_id, $preventivo);
if (!$budget['ok']) {
    fc_ai_esci([
        'ok' => false,
        'error' => 'limite_piano',
        'stima' => $budget['stima'],
        'disponibili' => $budget['disponibili'],
        'ripiego' => true,
    ]);
}

$lingua = fc_ai_enum($fc_ai_in['lingua'] ?? 'italiano', ['italiano', 'inglese'], 'italiano');
$token_spesi = 0;

/* ============================================================
   AZIONE: concetti
   ============================================================ */

if ($azione === 'concetti') {
    $fonte = fc_ai_enum($fc_ai_in['fonte'] ?? 'file', ['file', 'elenco', 'argomento'], 'file');
    $testo = fc_ai_documento($fc_ai_in['testo'] ?? '', FC_AI_MAX_TESTO);
    $titolo = fc_ai_riga($fc_ai_in['titolo'] ?? '', 200);
    $indicazioni = fc_ai_riga($fc_ai_in['indicazioni'] ?? '', 1000);
    $vuole_esempi = fc_ai_bool($fc_ai_in['opzioni']['esempi'] ?? false);
    $esempi_figli = fc_ai_bool($fc_ai_in['opzioni']['esempi_figli'] ?? false);
    $vuole_gerarchia = fc_ai_bool($fc_ai_in['opzioni']['gerarchia'] ?? false);
    $solo_elenco = fc_ai_bool($fc_ai_in['opzioni']['solo_elenco'] ?? false);

    $elenco = [];
    foreach ((array)($fc_ai_in['elenco'] ?? []) as $v) {
        $t = fc_ai_riga($v, 300);
        if ($t !== '') $elenco[] = $t;
        if (count($elenco) >= FC_AI_MAX_CONCETTI) break;
    }

    if ($fonte === 'file' && $testo === '') {
        fc_ai_esci(['ok' => false, 'error' => 'testo_mancante'], 400);
    }
    if ($fonte === 'elenco' && !$elenco) {
        fc_ai_esci(['ok' => false, 'error' => 'elenco_mancante'], 400);
    }
    if ($fonte === 'argomento' && $titolo === '') {
        fc_ai_esci(['ok' => false, 'error' => 'argomento_mancante'], 400);
    }

    /* Il documento va IN TESTA e non cambia mai: e' la parte che la cache di
       contesto puo' riusare nelle chiamate successive sullo stesso file. Le
       istruzioni, che invece cambiano, stanno in coda. */
    $u = '';
    if ($testo !== '') $u .= "DOCUMENTO:\n" . $testo . "\n\n";
    if ($titolo !== '') $u .= "ARGOMENTO: " . $titolo . "\n";
    if ($elenco) $u .= "ELENCO FORNITO DALLO STUDENTE:\n- " . implode("\n- ", $elenco) . "\n";
    if ($indicazioni !== '') $u .= "INDICAZIONI DELLO STUDENTE: " . $indicazioni . "\n";

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

    $r = fc_ai_gemini_ritenta(fc_ai_sistema($lingua), $u, fc_ai_schema_concetti(), 0, $token_spesi);
    nisama_incrementa_uso($fc_ai_user_id, 'token', $token_spesi);

    if (!$r['ok']) {
        fc_ai_esci(['ok' => false, 'error' => 'ia_' . $r['errore'], 'token' => $token_spesi], 502);
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
        fc_ai_esci(['ok' => false, 'error' => 'nessun_concetto', 'token' => $token_spesi], 502);
    }

    fc_ai_esci([
        'ok' => true,
        'concetti' => array_values(fc_ai_appiana_gerarchia($out)),
        'token' => $token_spesi,
    ]);
}

/* ============================================================
   AZIONE: densita  (piu' concetti / meno concetti)
   ============================================================
   Qui sta il risparmio piu' vistoso: "meno" non vede il documento (per capire
   che due titoli dicono la stessa cosa bastano i titoli) e restituisce solo le
   fusioni, non l'elenco riscritto. "piu'" il documento ce l'ha per forza, ma
   restituisce solo le aggiunte. */

if ($azione === 'densita') {
    $verso = fc_ai_enum($fc_ai_in['verso'] ?? '', ['piu', 'meno'], '');
    if ($verso === '') fc_ai_esci(['ok' => false, 'error' => 'verso_mancante'], 400);

    $titoli = [];
    foreach ((array)($fc_ai_in['titoli'] ?? []) as $v) {
        $t = fc_ai_riga($v, 300);
        if ($t !== '') $titoli[] = $t;
        if (count($titoli) >= FC_AI_MAX_CONCETTI) break;
    }
    if (count($titoli) < 2) fc_ai_esci(['ok' => false, 'error' => 'elenco_troppo_corto'], 400);

    $numerato = '';
    foreach ($titoli as $i => $t) $numerato .= $i . '. ' . $t . "\n";

    if ($verso === 'meno') {
        $u = "ELENCO ATTUALE:\n" . $numerato
           . "\nCOMPITO: raggruppa i concetti che conviene trattare in un'unica flashcard "
           . "piu' corposa, perche' dicono la stessa cosa o si studiano insieme.\n"
           . "In \"f\" metti i gruppi come liste di indici (almeno due indici per gruppo). "
           . "In \"t\", nello stesso ordine dei gruppi, il titolo unico del gruppo.\n"
           . "Non toccare i concetti che stanno bene da soli: non devono comparire in nessun gruppo.\n";

        $r = fc_ai_gemini_ritenta(fc_ai_sistema($lingua), $u, fc_ai_schema_fusioni(), 0, $token_spesi);
        nisama_incrementa_uso($fc_ai_user_id, 'token', $token_spesi);
        if (!$r['ok']) fc_ai_esci(['ok' => false, 'error' => 'ia_' . $r['errore'], 'token' => $token_spesi], 502);

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
        fc_ai_esci([
            'ok' => true, 'verso' => 'meno', 'fusioni' => $gruppi,
            'titoli' => $nuovi_titoli, 'modificato' => $modificato, 'token' => $token_spesi,
        ]);
    }

    // verso === 'piu'
    $testo = fc_ai_documento($fc_ai_in['testo'] ?? '', FC_AI_MAX_TESTO);
    $u = '';
    if ($testo !== '') $u .= "DOCUMENTO:\n" . $testo . "\n\n";
    $u .= "ELENCO ATTUALE:\n" . $numerato
        . "\nCOMPITO: aggiungi i concetti chiave che mancano, piu' fini e specifici di quelli gia' presenti.\n"
        . "Restituisci SOLO le aggiunte, mai i concetti gia' in elenco.\n"
        . "In \"d\" metti l'indice del concetto dopo il quale va inserito il nuovo.\n";
    if ($testo !== '') {
        $u .= "In \"a\" una citazione letterale di 4-8 parole copiata dal documento.\n";
    }

    $r = fc_ai_gemini_ritenta(fc_ai_sistema($lingua), $u, fc_ai_schema_aggiunte(), 0, $token_spesi);
    nisama_incrementa_uso($fc_ai_user_id, 'token', $token_spesi);
    if (!$r['ok']) fc_ai_esci(['ok' => false, 'error' => 'ia_' . $r['errore'], 'token' => $token_spesi], 502);

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

    fc_ai_esci([
        'ok' => true, 'verso' => 'piu', 'aggiunte' => $aggiunte,
        'modificato' => count($aggiunte) > 0, 'token' => $token_spesi,
    ]);
}

/** Chiave di confronto fra titoli: minuscole, senza accenti ne' punteggiatura. */
function fc_ai_chiave(string $s): string {
    $s = mb_strtolower($s, 'UTF-8');
    $s = strtr($s, ['à' => 'a', 'è' => 'e', 'é' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u']);
    $s = preg_replace('/[^a-z0-9 ]/u', '', $s);
    return trim(preg_replace('/\s+/', ' ', $s));
}

/* ============================================================
   AZIONE: carte  (un lotto, o una sola per la rigenerazione)
   ============================================================ */

if ($azione === 'carte') {
    $opz = (array)($fc_ai_in['opzioni'] ?? []);
    $esempi     = fc_ai_bool($opz['esempi'] ?? false);
    $integra    = fc_ai_bool($opz['integra'] ?? false);     // puo' usare conoscenze proprie
    $corregge   = fc_ai_bool($opz['corregge'] ?? false);    // puo' correggere errori del documento
    $testuale   = fc_ai_bool($opz['testuale'] ?? false);    // deve ricopiare le definizioni del documento
    $estratto   = fc_ai_documento($fc_ai_in['estratto'] ?? '', FC_AI_MAX_TESTO);
    $nota       = fc_ai_riga($fc_ai_in['nota'] ?? '', 500); // "rigenera, ma piu' corta" ecc.

    $concetti = [];
    foreach ((array)($fc_ai_in['concetti'] ?? []) as $c) {
        $t = fc_ai_riga($c['t'] ?? '', 300);
        if ($t === '') continue;
        $concetti[] = [
            'i' => isset($c['i']) ? (int)$c['i'] : count($concetti),
            't' => $t,
            'e' => !empty($c['e']) ? 1 : 0,
        ];
        if (count($concetti) >= FC_AI_LOTTO_MAX) break;
    }
    if (!$concetti) fc_ai_esci(['ok' => false, 'error' => 'concetti_mancanti'], 400);

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
        $u .= "Per i concetti segnati [con esempio] aggiungi in \"e\" un esempio concreto e breve. "
            . "Un solo esempio, e solo per quelli segnati.\n";
    }
    if ($nota !== '') $u .= "RICHIESTA AGGIUNTIVA DELLO STUDENTE: " . $nota . "\n";

    $u .= "\n" . fc_ai_spec_formato();

    /* Un budget di pensiero piccolo: sulla generazione si ripaga, perche' una
       carta rifatta costa molto piu' di qualche token di ragionamento. */
    $r = fc_ai_gemini_ritenta(fc_ai_sistema($lingua), $u, fc_ai_schema_carte($esempi), 512, $token_spesi);

    if (!$r['ok']) {
        nisama_incrementa_uso($fc_ai_user_id, 'token', $token_spesi);
        fc_ai_esci(['ok' => false, 'error' => 'ia_' . $r['errore'], 'token' => $token_spesi], 502);
    }

    $v = fc_ai_valida_lotto((array)($r['dati']['c'] ?? []), $indici, $esempi);

    /* Secondo giro MIRATO: si richiedono solo i concetti andati storti, con il
       motivo preciso. Rimandare il lotto intero significherebbe ripagare anche
       le carte che erano gia' buone — ed e' il tipo di spreco che qui si somma
       in fretta. */
    if ($v['da_rifare']) {
        $rifare = '';
        foreach ($v['da_rifare'] as $i => $motivo) {
            $t = '';
            foreach ($concetti as $c) if ($c['i'] === $i) $t = $c['t'];
            $rifare .= $i . '. ' . $t . '  (problema: ' . $motivo . ")\n";
        }
        $u2 = ($estratto !== '' ? "ESTRATTI DEL DOCUMENTO:\n" . $estratto . "\n\n" : '')
            . "Rifai SOLO queste flashcard, correggendo il problema indicato:\n" . $rifare . "\n"
            . fc_ai_spec_formato();

        $r2 = fc_ai_gemini_ritenta(fc_ai_sistema($lingua), $u2, fc_ai_schema_carte($esempi), 512, $token_spesi);
        if ($r2['ok']) {
            $v2 = fc_ai_valida_lotto((array)($r2['dati']['c'] ?? []), array_keys($v['da_rifare']), $esempi);
            foreach ($v2['ok'] as $i => $carta) $v['ok'][$i] = $carta;
        }
    }

    nisama_incrementa_uso($fc_ai_user_id, 'token', $token_spesi);

    if (!$v['ok']) {
        fc_ai_esci(['ok' => false, 'error' => 'nessuna_carta', 'token' => $token_spesi], 502);
    }

    /* Si torna anche l'elenco di chi e' rimasto scoperto: e' cio' che permette
       all'elenco in cima alla pagina di mostrare il concetto senza numero,
       invece di far credere allo studente che sia stato trattato. */
    $mancanti = [];
    foreach ($indici as $i) if (!isset($v['ok'][$i])) $mancanti[] = $i;

    ksort($v['ok']);
    fc_ai_esci([
        'ok' => true,
        'carte' => array_values($v['ok']),
        'mancanti' => $mancanti,
        'token' => $token_spesi,
    ]);
}

/* ============================================================
   AZIONE: migliora  (una carta sola, qualunque sia la sua origine)
   ============================================================
   A differenza di 'carte', qui non c'e' un elenco di concetti ne' un lotto:
   l'ingresso e' il contenuto GIA' SCRITTO sulla carta (o, se e' vuota, le
   indicazioni dello studente che ne fanno le veci). Serve al tasto presente
   su ogni riga della tabella, comprese quelle mai passate dall'IA. */

if ($azione === 'migliora') {
    $domanda  = fc_ai_documento($fc_ai_in['domanda'] ?? '', 6000);
    $risposta = fc_ai_documento($fc_ai_in['risposta'] ?? '', 8000);
    $nota     = fc_ai_riga($fc_ai_in['nota'] ?? '', 500);
    $estratto = fc_ai_documento($fc_ai_in['estratto'] ?? '', FC_AI_MAX_TESTO);
    $esempio  = fc_ai_bool($fc_ai_in['opzioni']['esempi'] ?? false);

    $vuota = (trim($domanda) === '' && trim($risposta) === '');
    if ($vuota && $nota === '') {
        // niente da migliorare e niente indicazioni: non c'e' materiale per scrivere nulla
        fc_ai_esci(['ok' => false, 'error' => 'serve_indicazione'], 400);
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
    if ($esempio) $u .= "Aggiungi anche un esempio concreto e breve in \"e\".\n";
    $u .= "Restituisci un solo elemento nell'elenco \"c\", con \"i\": 0.\n";
    $u .= "\n" . fc_ai_spec_formato();

    $r = fc_ai_gemini_ritenta(fc_ai_sistema($lingua), $u, fc_ai_schema_carte($esempio), 512, $token_spesi);
    nisama_incrementa_uso($fc_ai_user_id, 'token', $token_spesi);

    if (!$r['ok']) {
        fc_ai_esci(['ok' => false, 'error' => 'ia_' . $r['errore'], 'token' => $token_spesi], 502);
    }

    $v = fc_ai_valida_lotto((array)($r['dati']['c'] ?? []), [0], $esempio);
    if (!isset($v['ok'][0])) {
        fc_ai_esci(['ok' => false, 'error' => 'nessuna_carta', 'token' => $token_spesi], 502);
    }

    fc_ai_esci(['ok' => true, 'carta' => $v['ok'][0], 'token' => $token_spesi]);
}

fc_ai_esci(['ok' => false, 'error' => 'azione_sconosciuta'], 400);
