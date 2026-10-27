<?php
/**
 * fc_ai_style.php — COME deve essere scritta una carta, e quanto deve
 * pensarci il modello.
 *
 * Due cose diverse che l'utente sceglie nello stesso posto:
 *
 *   LO STILE — registro, profondita', lunghezza, struttura, tipo di domanda,
 *   livello, ambito, e la "carta modello" da imitare. Sono scelte CHIUSE, e
 *   quasi tutte VERIFICABILI in PHP dopo la risposta: "risposta breve" non e'
 *   un auspicio ma un numero che si misura. Una richiesta che sappiamo
 *   controllare e' una richiesta che possiamo garantire, perche' la carta che
 *   non la rispetta torna al modello nel secondo giro con il motivo preciso
 *   ("troppo lunga: 1240 caratteri, massimo 900") — il meccanismo che
 *   fc_ai_valida_lotto() usa gia' per il formato.
 *
 *   L'IMPEGNO — quanti token il modello puo' spendere a ragionare prima di
 *   rispondere (thinkingBudget di Gemini). Si pagano come l'uscita: e' una
 *   leva di qualita' e di costo insieme, e il preventivo la conta.
 *
 * DI SERIE NON CAMBIA NIENTE
 *
 * Ogni scelta ha il valore "auto", che vuol dire "non specificato": nessuna
 * riga nel prompt, nessun controllo, nessun rifiuto. L'impegno di serie e'
 * "medio", cioe' il thinkingBudget di 512 che le carte usano da sempre. Chi
 * non apre le impostazioni riceve esattamente le carte di prima: un
 * controllo nuovo non deve poter rifiutare carte che oggi vanno bene.
 *
 * Le indicazioni libere NON passano da qui: hanno gia' il loro percorso in
 * fc_ai_azione_carte (recinto, precedenza dichiarata, secondo giro).
 */

/* ============================================================
   1. VALORI
   ============================================================ */

function fc_ai_stile_valori(): array {
    return [
        'registro'   => ['auto', 'tecnico', 'discorsivo', 'misto'],
        'profondita' => ['auto', 'punto', 'spiegato', 'esaustivo'],
        'lunghezza'  => ['auto', 'breve', 'media', 'lunga'],
        'struttura'  => ['auto', 'elenco', 'definizione', 'schema', 'codice'],
        'domanda'    => ['auto', 'diretta', 'applicativa', 'cloze'],
        'livello'    => ['auto', 'base', 'universita', 'esperto'],
        // cambia le regole di formato, non solo il tono: in informatica il LaTeX e' un danno
        'dominio'    => ['auto', 'matematica', 'informatica', 'lingua', 'giuridico', 'medico'],
        /* Che esempio scrivere: vale per gli esempi dentro le carte (quando
           l'opzione esempi e' accesa) e per quelli generati dal popup degli
           esempi. Un esercizio svolto e un'analogia non sono lo stesso
           strumento di studio, e chi studia sa quale gli serve. */
        'esempio'    => ['auto', 'concreto', 'esercizio', 'controesempio', 'analogia'],
    ];
}

function fc_ai_impegno_valori(): array {
    return ['basso', 'medio', 'alto', 'massimo'];
}

/**
 * Stampa il selettore dell'impegno come tendina (NS.tendina di standout_ui.js).
 *
 * Sta qui, e non nelle due pagine che lo usano, per la ragione di sempre: era
 * gia' scritto due volte — nel pannello delle carte e nel popup degli esempi —
 * e due copie della stessa cosa restano uguali solo finche' nessuno le tocca.
 * Le quattro voci, le loro spiegazioni e l'ordine arrivano da un punto solo.
 *
 * Perche' una tendina e non quattro pastiglie: le quattro parole ("Rapido",
 * "Equilibrato", "Accurato", "Massimo") non dicono da sole quanto costano ne'
 * quando servono, e questa e' la scelta che cambia il prezzo. Aperta, ogni
 * voce porta con se' la sua frase; chiusa, occupa una riga sola.
 *
 * Funziona anche senza JavaScript: le voci sono nel markup, e il valore scelto
 * lo legge chi interroga [data-valore].is-on, non una variabile in memoria.
 */
function fc_ai_tendina_impegno(string $id, string $scelto = 'medio'): void {
    $scelto = fc_ai_impegno_leggi($scelto);
    $spunta = '<svg class="ns-tendina-spunta" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
            . ' stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . '<path d="M20 6 9 17l-5-5"/></svg>';
    ?>
    <div class="ns-tendina fcai-impegno" id="<?php echo sec_h($id); ?>" data-valore="<?php echo sec_h($scelto); ?>">
        <button type="button" class="ns-tendina-btn ns-preme" aria-haspopup="listbox" aria-expanded="false"
                aria-label="<?php echo sec_h(t('fc.ibfai_impegno_sep')); ?>">
            <?php /* Il nome della voce scelta lo scrive il SERVER, non il
                     JavaScript. Se lo lascia scrivere al JavaScript, basta una
                     copia vecchia in cache del file di pagina — che non trova
                     piu' gli elementi che cercava — e la riga resta vuota: una
                     casella grigia senza testo e senza reazione al tocco, e
                     nemmeno un errore in console a dire perche'. */ ?>
            <span class="ns-tendina-valore"><?php te('fc.ibfai_impegno_' . $scelto); ?></span>
            <svg class="ns-tendina-frec" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="m6 9 6 6 6-6"/>
            </svg>
        </button>
        <div class="ns-tendina-menu" role="listbox" hidden
             aria-label="<?php echo sec_h(t('fc.ibfai_impegno_sep')); ?>">
            <?php foreach (fc_ai_impegno_valori() as $imp): ?>
                <button type="button" class="ns-tendina-voce<?php echo $imp === $scelto ? ' is-on' : ''; ?>"
                        role="option" aria-selected="<?php echo $imp === $scelto ? 'true' : 'false'; ?>"
                        data-valore="<?php echo $imp; ?>" data-impegno="<?php echo $imp; ?>">
                    <span class="ns-tendina-voce-t">
                        <b><?php te('fc.ibfai_impegno_' . $imp); ?></b>
                        <small><?php te('fc.ibfai_impegno_' . $imp . '_desc'); ?></small>
                    </span>
                    <?php echo $spunta; ?>
                </button>
            <?php endforeach; ?>
        </div>
    </div>
    <?php /* Rete di sicurezza. Se standout_ui.js non arriva — non caricato, in
             cache vecchia, bloccato da un filtro — NS.tendina non aggancia
             niente e questa resterebbe una riga che non si apre. In quel caso
             le quattro voci si mostrano in fila sotto, come un elenco normale:
             la scelta resta possibile, e chi la usa non si accorge di stare
             usando un ripiego. Il controllo e' su __nsTendina, cioe' sul
             risultato vero dell'aggancio, non sull'esistenza di NS. */ ?>
    <script nonce="<?php echo sec_nonce(); ?>">
    (function () {
        var id = <?php echo json_encode($id); ?>;
        function ripiego() {
            var el = document.getElementById(id);
            if (!el || el.__nsTendina) return;
            var menu = el.querySelector('.ns-tendina-menu');
            if (menu) { menu.hidden = false; menu.classList.add('is-ripiego'); }
            var btn = el.querySelector('.ns-tendina-btn');
            if (btn) btn.hidden = true;
        }
        /* setTimeout(0) e non il solo DOMContentLoaded: questo blocco e'
           scritto PRIMA degli script di pagina, quindi il suo ascoltatore
           scatterebbe prima che quelli abbiano avuto modo di agganciare la
           tendina, e il ripiego partirebbe sempre. Il rinvio lo mette in
           fondo alla fila. Il secondo controllo, al caricamento completo,
           copre chi aggancia piu' tardi. */
        function piuTardi() { setTimeout(ripiego, 0); }
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', piuTardi);
        else piuTardi();
        window.addEventListener('load', function () { setTimeout(ripiego, 0); });
    })();
    </script>
    <?php
}

function fc_ai_stile_default(): array {
    $s = [];
    foreach (fc_ai_stile_valori() as $k => $v) $s[$k] = 'auto';
    $s['etichette'] = [];   // solo con struttura = schema
    $s['modello_q'] = '';   // la carta modello: domanda...
    $s['modello_a'] = '';   // ...e risposta
    return $s;
}

/** Normalizza cio' che arriva dal client. Quello che non riconosce torna "auto". */
function fc_ai_stile_leggi($in): array {
    $s = fc_ai_stile_default();
    if (!is_array($in)) return $s;

    foreach (fc_ai_stile_valori() as $campo => $ammessi) {
        if (isset($in[$campo]) && in_array((string)$in[$campo], $ammessi, true)) $s[$campo] = (string)$in[$campo];
    }

    /* Le etichette dello schema: "tipo radice / valore radice / check nulla".
       Una per voce, su una riga sola ciascuna. Oltre otto non e' piu' una
       flashcard, e' una scheda. */
    foreach ((array)($in['etichette'] ?? []) as $e) {
        if (!is_string($e)) continue;
        $e = trim(preg_replace('/\s+/u', ' ', sec_text($e, 80)));
        $e = rtrim($e, ':');
        if ($e !== '' && !in_array($e, $s['etichette'], true)) $s['etichette'][] = $e;
        if (count($s['etichette']) >= 8) break;
    }
    // uno schema senza voci non e' uno schema: torna auto invece di generare a caso
    if ($s['struttura'] === 'schema' && !$s['etichette']) $s['struttura'] = 'auto';

    // sec_text e non sec_str: la carta modello ha a capo, e "<int>" e' contenuto
    $s['modello_q'] = sec_text($in['modello_q'] ?? '', 1500);
    $s['modello_a'] = sec_text($in['modello_a'] ?? '', 3000);
    return $s;
}

function fc_ai_impegno_leggi($v): string {
    return in_array((string)$v, fc_ai_impegno_valori(), true) ? (string)$v : 'medio';
}

/** true se l'utente non ha scelto niente: il caso in cui nulla deve cambiare. */
function fc_ai_stile_vuoto(array $s): bool {
    foreach (fc_ai_stile_valori() as $k => $v) if ($s[$k] !== 'auto') return false;
    return $s['modello_a'] === '';
}

/* ============================================================
   2. IMPEGNO -> PARAMETRI DEL MODELLO
   ============================================================
   Il budget di ragionamento per azione. "medio" sulle carte e' il 512 di
   sempre; sull'estrazione dei concetti di serie non si pensa (e' un lavoro
   di lettura, non di scrittura), e si comincia a farlo solo da "alto".

   Sono token pagati come l'uscita: "massimo" su un lotto di otto carte
   aggiunge fino a ~6000 token per chiamata. Il preventivo li conta tutti
   (fc_ai_pensiero_atteso), cosi' l'utente vede il prezzo della scelta prima
   di farla, non dopo. */

function fc_ai_pensiero(string $impegno, string $azione): int {
    $tabella = [
        //            basso  medio  alto   massimo
        'carte'    => [0,    512,   2048,  6144],
        'migliora' => [0,    512,   2048,  6144],
        'concetti' => [0,    0,     1024,  2048],
        /* Un esempio solo: meno da tenere a mente di un lotto, quindi un tetto
           piu' basso in cima. "medio" resta 512 come le carte: il vecchio
           ai_example.php non fissava nessun budget, e gemini-2.5-flash
           decideva da se' quanto pensare — la causa dei timeout a 25 secondi
           che poi, riprovando, non si ripresentavano. */
        'esempio'  => [0,    512,   2048,  4096],
    ];
    $i = array_search($impegno, fc_ai_impegno_valori(), true);
    return $tabella[$azione][$i === false ? 1 : $i] ?? 0;
}

/**
 * Quanti token di pensiero aspettarsi davvero, per il preventivo. Il budget
 * e' un tetto, non un consumo: sulle carte il modello ne usa in media poco
 * piu' della meta'. Si conta il 70%, un margine sopra la media osservata —
 * sottostimare vorrebbe dire far partire un job che si ferma a meta' per
 * crediti finiti.
 */
function fc_ai_pensiero_atteso(string $impegno, string $azione): int {
    return (int)ceil(fc_ai_pensiero($impegno, $azione) * 0.7);
}

/**
 * Temperatura dallo stile. "auto" resta al 0,25 di sempre; il registro
 * discorsivo ne ha bisogno di piu', perche' a 0,25 una spiegazione a voce
 * esce legnosa.
 */
function fc_ai_temperatura(array $s, float $auto = 0.25): float {
    /* $auto: la temperatura quando il registro e' "auto". Per le carte 0,25;
       per gli esempi 0,4, come ha sempre fatto ai_example.php — un esempio
       deve essere un po' inventato, una definizione no. */
    return ['auto' => $auto, 'tecnico' => 0.15, 'misto' => 0.3, 'discorsivo' => 0.5][$s['registro']] ?? $auto;
}

/* ============================================================
   3. LIMITI MISURABILI
   ============================================================
   In caratteri del testo VISIBILE (senza i tag del sito): e' cio' che si
   conta senza ambiguita' su un testo pieno di formule, dove "parola" non
   vuol dire niente. Il minimo conta quanto il massimo: "risposta inutile"
   e' quasi sempre una risposta troppo corta. */

function fc_ai_stile_limiti(array $s): ?array {
    if ($s['lunghezza'] === 'auto') return null;
    $l = ['breve' => [60, 420], 'media' => [180, 900], 'lunga' => [350, 1800]][$s['lunghezza']];
    // uno schema a N voci non ci sta nei limiti di una risposta discorsiva
    if ($s['struttura'] === 'schema') {
        $n = count($s['etichette']);
        $l[0] = max($l[0], $n * 20);
        $l[1] = max($l[1], $n * 160);
    }
    if ($s['profondita'] === 'esaustivo') $l[1] = (int)round($l[1] * 1.4);
    return $l;
}

/** Limiti per il testo di un ESEMPIO: piu' larghi della risposta, un esercizio svolto e' lungo per natura. */
function fc_ai_stile_limiti_esempio(array $s): ?array {
    if ($s['lunghezza'] === 'auto') return null;
    $l = ['breve' => [60, 500], 'media' => [200, 1100], 'lunga' => [400, 2200]][$s['lunghezza']];
    if ($s['esempio'] === 'esercizio') $l[1] = (int)round($l[1] * 1.5);
    return $l;
}

/* ============================================================
   4. IL PROMPT
   ============================================================ */

/**
 * Che esempio chiedere. Con "auto" torna esattamente la frase che il prompt
 * delle carte usa da sempre ("un esempio concreto e breve"): cambiare anche
 * solo quella, a scelte non fatte, vorrebbe dire cambiare le carte di tutti.
 */
function fc_ai_stile_istruzione_esempio(array $s): string {
    $tipo = [
        'auto'          => "un esempio concreto e breve",
        'concreto'      => "un esempio concreto, preso da una situazione reale o da un caso numerico",
        'esercizio'     => "un esercizio svolto: prima la consegna, poi lo svolgimento passo per passo, poi il risultato",
        'controesempio' => "un controesempio: un caso che SEMBRA rientrare nel concetto ma non ci rientra, e perche'",
        'analogia'      => "un'analogia con qualcosa di quotidiano, e subito dopo il punto preciso in cui l'analogia smette di valere",
    ][$s['esempio']];
    $lim = fc_ai_stile_limiti_esempio($s);
    if ($lim) $tipo .= " (fra {$lim[0]} e {$lim[1]} caratteri di testo, vincolo verificato)";
    return $tipo;
}

/**
 * Le righe di stile per la scrittura delle carte. Vuoto se non c'e' niente
 * da dire. $contesto: 'carte' o 'concetti' (sull'estrazione contano solo
 * livello e ambito: li' si scelgono i titoli, non la forma delle risposte).
 */
function fc_ai_stile_prompt(array $s, string $contesto = 'carte'): string {
    $p = '';
    $livello = ['base' => 'scuola superiore', 'universita' => 'universitario', 'esperto' => 'avanzato, per chi conosce gia\' la materia'];
    if ($s['livello'] !== 'auto') $p .= "Livello del destinatario: " . $livello[$s['livello']] . ".\n";

    if ($contesto === 'concetti') {
        if ($s['dominio'] !== 'auto') $p .= "Ambito: {$s['dominio']}.\n";
        return $p === '' ? '' : "TARATURA:\n" . $p;
    }

    /* Per un esempio contano tono, livello e ambito; la forma della risposta
       e il tipo di domanda sono cose della carta, e il tipo di esempio lo
       dice gia' fc_ai_stile_istruzione_esempio. */
    if ($contesto === 'esempio') {
        if ($s['registro'] !== 'auto') $p .= "Tono: " . [
            'tecnico' => "tecnico e asciutto.", 'discorsivo' => "discorsivo, come a voce.",
            'misto' => "una riga in parole semplici, poi il dettaglio tecnico.",
        ][$s['registro']] . "\n";
        $p .= fc_ai_stile_dominio($s);
        return $p === '' ? '' : "COME DEVE ESSERE SCRITTO L'ESEMPIO:\n" . $p;
    }

    if ($s['registro'] !== 'auto') $p .= "Registro: " . [
        'tecnico'    => "tecnico e asciutto. Terminologia precisa, nessuna frase di raccordo, nessuna introduzione.",
        'discorsivo' => "discorsivo. Spiega come lo faresti a voce a chi non ha capito: prima l'idea in una frase, poi il dettaglio.",
        'misto'      => "una riga di spiegazione in parole semplici, poi il contenuto tecnico preciso.",
    ][$s['registro']] . "\n";

    if ($s['profondita'] !== 'auto') $p .= "Profondita': " . [
        'punto'     => "solo il punto. Il fatto da sapere e nient'altro: niente contesto, niente perche', niente premesse.",
        'spiegato'  => "il punto piu' il minimo che serve per capirlo davvero (il perche', o la condizione in cui vale).",
        'esaustivo' => "trattazione completa: enunciato, condizioni di validita', casi limite o errori tipici.",
    ][$s['profondita']] . "\n";

    $lim = fc_ai_stile_limiti($s);
    if ($lim) {
        $p .= "Lunghezza della risposta: fra {$lim[0]} e {$lim[1]} caratteri di testo (i tag di formato non contano). "
            . "E' un vincolo verificato automaticamente: una risposta fuori da questo intervallo viene rifiutata.\n";
    }

    if ($s['domanda'] !== 'auto') $p .= "Domanda: " . [
        'diretta'     => "chiede direttamente il concetto (\"Che cos'e'...\", \"Quando vale...\").",
        'applicativa' => "mette davanti a un caso concreto da risolvere con il concetto, invece di chiederne la definizione.",
        'cloze'       => "e' un'affermazione con il pezzo chiave sostituito da [...]; la risposta comincia con cio' che va al posto dei puntini.",
    ][$s['domanda']] . "\n";

    $p .= fc_ai_stile_struttura($s);
    $p .= fc_ai_stile_dominio($s);

    /* LA CARTA MODELLO. Un modello linguistico imita un esempio molto meglio di
       quanto esegua una descrizione: chi incolla una carta come la vuole sta
       dando l'istruzione piu' precisa possibile. Va in fondo alle scelte
       chiuse perche' vince su di esse. */
    if ($s['modello_a'] !== '') {
        $p .= "CARTA MODELLO fornita dallo studente. Non e' un esempio di argomento: e' il CALCO da seguire. "
            . "Copiane l'impostazione — quante parti ha la risposta, in che ordine, quanto e' lunga ciascuna, "
            . "che tono ha — e cambia solo il contenuto. Dove il modello e' in conflitto con le indicazioni "
            . "qui sopra, vince il modello.\n";
        if ($s['modello_q'] !== '') $p .= "--- domanda modello ---\n" . $s['modello_q'] . "\n";
        $p .= "--- risposta modello ---\n" . $s['modello_a'] . "\n--- fine modello ---\n";
    }

    return $p === '' ? '' : "COME DEVE ESSERE SCRITTA OGNI CARTA:\n" . $p;
}

function fc_ai_stile_struttura(array $s): string {
    switch ($s['struttura']) {
        case 'elenco':
            return "Struttura: la risposta e' un elenco puntato dentro /list{...}, un punto per informazione. "
                 . "Niente paragrafo introduttivo prima dell'elenco.\n";
        case 'definizione':
            return "Struttura: prima riga la definizione in una frase sola; sotto, se serve, la formula o la condizione. Nient'altro.\n";
        case 'codice':
            return "Struttura: al centro della risposta c'e' il codice, non la prosa. Ogni istruzione su una riga sua. "
                 . "Le spiegazioni, se servono, sono etichette corte prima del codice, mai commenti in mezzo.\n";
        case 'schema':
            $righe = '';
            foreach ($s['etichette'] as $e) $righe .= "  " . $e . ":\n  <contenuto>\n";
            return "Struttura: la risposta e' uno SCHEMA a voci fisse. Queste voci, tutte, in questo ordine, ciascuna "
                 . "con la sua etichetta seguita da due punti su una riga e il contenuto sulla riga successiva:\n"
                 . $righe
                 . "Niente introduzione, niente conclusione, nessuna voce in piu' e nessuna in meno. Se per un concetto "
                 . "una voce non ha senso, scrivi comunque l'etichetta e sotto una riga che dice perche' non si applica. "
                 . "La presenza di ogni etichetta e' verificata automaticamente.\n";
    }
    return '';
}

/**
 * L'ambito non e' un tono: cambia quali strumenti di formato sono giusti. E'
 * la differenza fra una carta di algoritmi leggibile e una in cui il modello
 * ha messo "Node<int>*" fra dollari perche' il prompt generale gli chiede di
 * usare le formule.
 */
function fc_ai_stile_dominio(array $s): string {
    switch ($s['dominio']) {
        case 'matematica':
            return "Ambito matematico: ogni simbolo, variabile o espressione va dentro \$...\$, anche quando e' una lettera "
                 . "sola in mezzo alla frase. Le formule importanti su riga propria con \$\$...\$\$.\n";
        case 'informatica':
            return "Ambito informatico: il codice va scritto in chiaro, NON dentro \$...\$ — i delimitatori di formula lo "
                 . "renderebbero come matematica. Tipi e identificatori si scrivono come sono (Node<int>*, root->val()). "
                 . "Usa \$...\$ solo per la complessita' asintotica e per la matematica vera.\n";
        case 'lingua':
            return "Ambito linguistico: il termine straniero in /italic{...}, la traduzione in chiaro. Se la pronuncia e' "
                 . "irregolare, indicala fra parentesi.\n";
        case 'giuridico':
            return "Ambito giuridico: cita l'articolo o la fonte quando c'e' negli estratti, mai a memoria. Distingui la regola dall'eccezione.\n";
        case 'medico':
            return "Ambito medico: nome scientifico e nome comune insieme la prima volta. Dosaggi e valori solo se stanno negli estratti.\n";
    }
    return '';
}

/* ============================================================
   5. VERIFICA — cio' che trasforma una preferenza in una garanzia
   ============================================================
   Restituisce '' se la carta rispetta lo stile, altrimenti il motivo, scritto
   in modo da poter essere rimandato tale e quale al modello nel secondo giro.
   Si controlla SOLO cio' che l'utente ha scelto: con tutto su "auto" questa
   funzione non rifiuta niente. */

function fc_ai_stile_verifica(array $s, string $q, string $a): string {
    $nudo = fc_ai_stile_testo_nudo($a);
    $len = mb_strlen($nudo, 'UTF-8');

    $lim = fc_ai_stile_limiti($s);
    if ($lim && $len < $lim[0]) {
        return "risposta troppo corta ($len caratteri, minimo {$lim[0]}): aggiungi sostanza — il perche', la condizione, "
             . "un dato concreto — senza allungare con giri di parole";
    }
    if ($lim && $len > $lim[1]) {
        return "risposta troppo lunga ($len caratteri, massimo {$lim[1]}): togli cio' che non serve a rispondere alla domanda";
    }

    if ($s['struttura'] === 'elenco' && strpos($a, '/list{') === false) {
        return "manca l'elenco: la risposta doveva essere un elenco puntato dentro /list{...}";
    }
    if ($s['struttura'] === 'schema') {
        $mancanti = [];
        foreach ($s['etichette'] as $e) {
            if (mb_stripos($nudo, $e, 0, 'UTF-8') === false) $mancanti[] = $e;
        }
        if ($mancanti) return "mancano queste voci obbligatorie dello schema: " . implode('; ', $mancanti);
    }

    /* L'errore piu' frequente in informatica: il modello prende l'abitudine
       LaTeX del prompt generale e ci mette dentro il codice. */
    if ($s['dominio'] === 'informatica' && preg_match('/\$[^$]*(->|::|\(\)|<[A-Za-z_]+>|\+\+)[^$]*\$/u', $a)) {
        return "c'e' del codice dentro i delimitatori di formula \$...\$: il codice va scritto in chiaro";
    }

    if ($s['domanda'] === 'cloze' && strpos($q, '[...]') === false) {
        return "la domanda doveva essere un'affermazione con il pezzo chiave sostituito da [...]";
    }
    return '';
}

/** La stessa verifica, per il testo di un esempio. '' se va bene. */
function fc_ai_stile_verifica_esempio(array $s, string $e): string {
    $len = mb_strlen(fc_ai_stile_testo_nudo($e), 'UTF-8');
    $lim = fc_ai_stile_limiti_esempio($s);
    if ($lim && $len < $lim[0]) return "esempio troppo corto ($len caratteri, minimo {$lim[0]})";
    if ($lim && $len > $lim[1]) return "esempio troppo lungo ($len caratteri, massimo {$lim[1]}): tieni solo cio' che fa capire il concetto";
    if ($s['dominio'] === 'informatica' && preg_match('/\$[^$]*(->|::|\(\)|<[A-Za-z_]+>|\+\+)[^$]*\$/u', $e)) {
        return "c'e' del codice dentro i delimitatori di formula \$...\$: il codice va scritto in chiaro";
    }
    return '';
}

/** Il testo come lo legge l'utente: senza i tag del sito. */
function fc_ai_stile_testo_nudo(string $t): string {
    $t = preg_replace('#/(?:' . implode('|', array_map('preg_quote', fc_ai_tag_ammessi())) . ')\{#u', '', $t);
    $t = str_replace(['{', '}'], '', $t);
    return trim(preg_replace('/[ \t]+/u', ' ', $t));
}

/**
 * Una riga per i registri: quale combinazione di scelte e' stata usata.
 * Serve a capire, dopo, quali stili producono piu' secondi giri — cioe' dove
 * il prompt e' scritto male e va corretto.
 */
function fc_ai_stile_firma(array $s, string $impegno): string {
    $parti = [];
    foreach (fc_ai_stile_valori() as $k => $v) if ($s[$k] !== 'auto') $parti[] = "$k=" . $s[$k];
    if ($s['etichette']) $parti[] = 'voci=' . count($s['etichette']);
    if ($s['modello_a'] !== '') $parti[] = 'modello';
    return 'impegno=' . $impegno . ($parti ? ' ' . implode(' ', $parti) : '');
}
