<?php
/* ============================================================================
   fc_type.php — DI CHE TIPO SONO LE FLASHCARD DI QUESTA RICHIESTA

   Le flashcard sono di due tipi, con le stesse pagine:
     - studio  (fc_study_*)  domanda e risposta, testo ricco, immagini, audio
     - lingua  (fc_vocab_*)  parola, traduzione, significato, esempi, immagine

   Le pagine sono UNA per entrambi: fc_decks.php, fc_view.php, fc_game.php...
   Una correzione al gioco vale cosi' per le due cose insieme, invece di
   doverla ricordare in due copie che col tempo divergono.

   COME UNA PAGINA SA IL TIPO
   Dall'indirizzo: ?course=N vuol dire "lingua, corso N". Senza, e' studio.
   Un solo parametro e non due (tipo + corso) apposta: un mazzo di lingua
   senza il suo corso non ha senso, e due parametri possono contraddirsi.
   Le pagine di una partita invece il tipo lo prendono dalla PARTITA
   (fc_games.kind), non dall'indirizzo: e' un dato del server, non del client.

   PERCHE' I NOMI DI TABELLA SI COMPONGONO
   Una query non puo' avere il nome della tabella come segnaposto. Qui i nomi
   vengono da un elenco chiuso scritto nel codice (FC_TABELLE): nessun valore
   arrivato dal browser entra mai in una query come nome — e' la regola di
   SICUREZZA.md, punto 3.

   DA INCLUDERE dopo auth_check.php (servono le sec_*):
       require_once __DIR__ . '/fc_type.php';
       fc_avvia($current_user_id);          // pagine normali
       fc_avvia_da_gioco($riga_di_fc_games) // pagine di una partita
   ============================================================================ */

if (defined('FC_TYPE_CARICATO')) return;
define('FC_TYPE_CARICATO', true);

const FC_TABELLE = [
    'study' => [
        'cards'      => 'fc_study_cards',
        'subcards'   => 'fc_study_subcards',
        'decks'      => 'fc_study_decks',
        'examples'   => 'fc_study_examples',
        'learnt'     => 'fc_study_learnt',
        'favorites'  => 'fc_study_favorites',
        'plays'      => 'fc_study_plays',
        'sequence'   => 'fc_study_sequence',
        'game_decks' => 'fc_study_game_decks',
    ],
    'vocab' => [
        'cards'      => 'fc_vocab_cards',
        'subcards'   => 'fc_vocab_subcards',
        'decks'      => 'fc_vocab_decks',
        'examples'   => 'fc_vocab_examples',
        'learnt'     => 'fc_vocab_learnt',
        'favorites'  => 'fc_vocab_favorites',
        'plays'      => 'fc_vocab_plays',
        'sequence'   => 'fc_vocab_sequence',
        'game_decks' => 'fc_vocab_game_decks',
    ],
];

/* Lo stato di questa richiesta. Una funzione con una static e non tre
   variabili globali: le funzioni delle pagine (cs_resolve_color e simili) lo
   leggono senza dover dichiarare "global" ovunque, che e' la dimenticanza
   tipica che fa girare una query sulla tabella sbagliata. */
function &fc_stato(): array {
    static $s = ['tipo' => 'study', 'corso' => null];
    return $s;
}

function fc_tipo(): string     { return fc_stato()['tipo']; }
function fc_is_vocab(): bool   { return fc_stato()['tipo'] === 'vocab'; }
function fc_corso(): ?array    { return fc_stato()['corso']; }
function fc_corso_id(): int    { $c = fc_stato()['corso']; return $c ? (int)$c['id'] : 0; }

/** Nome della tabella del tipo corrente: fc_t('decks') -> fc_study_decks o fc_vocab_decks. */
function fc_t(string $nome): string {
    $tipo = fc_stato()['tipo'];
    if (!isset(FC_TABELLE[$tipo][$nome])) {
        // un nome sbagliato e' un errore di programmazione: meglio fermarsi
        // subito che lanciare una query su una tabella inventata
        throw new LogicException('fc_t: tabella sconosciuta ' . $nome);
    }
    return FC_TABELLE[$tipo][$nome];
}

/** Il corso dell'utente, oppure null se non e' suo o non esiste. */
function fc_leggi_corso(int $corsoId, int $userId): ?array {
    if ($corsoId <= 0 || $userId <= 0) return null;
    return sec_one("SELECT id, user_id, known_lang, learn_lang, project_id, custom_order, created_at
                      FROM fc_vocab_courses WHERE id = ? AND user_id = ?", [$corsoId, $userId], 'ii');
}

/**
 * Da chiamare in cima a ogni pagina e a ogni endpoint delle flashcard.
 * Con ?course=N (in GET o nel corpo) il tipo diventa "lingua"; il corso deve
 * essere dell'utente, altrimenti si torna alla pagina principale delle
 * flashcard (dove stanno le lingue) — o, per un endpoint, si risponde 404
 * senza dire se il corso esiste.
 */
function fc_avvia(int $userId, bool $isApi = false): void {
    $s =& fc_stato();
    $grezzo = $_GET['course'] ?? ($_POST['course'] ?? (sec_body()['course'] ?? null));
    $corsoId = sec_int($grezzo, 0, PHP_INT_MAX, 0);
    if ($corsoId <= 0) { $s = ['tipo' => 'study', 'corso' => null]; return; }

    $corso = fc_leggi_corso($corsoId, $userId);
    if (!$corso) {
        if ($isApi) sec_die_json(t('api.non_trovato'), 404, 'not_found');
        header('Location: ' . PWA_BASE . 'fc/fc_home.php');
        exit;
    }
    $s = ['tipo' => 'vocab', 'corso' => $corso];
}

/**
 * Per le pagine di una partita: il tipo lo dice la riga di fc_games, non
 * l'indirizzo. Cosi' cambiare a mano ?course= non trasforma una partita di
 * studio in una di lingua.
 */
function fc_avvia_da_gioco(array $partita): void {
    $s =& fc_stato();
    if (($partita['kind'] ?? 'study') === 'vocab') {
        $corso = fc_leggi_corso((int)($partita['course_id'] ?? 0), (int)($partita['user_id'] ?? 0));
        $s = ['tipo' => 'vocab', 'corso' => $corso];
    } else {
        $s = ['tipo' => 'study', 'corso' => null];
    }
}

/** Forza un tipo: serve agli strumenti che lavorano su entrambi (statistiche riassuntive). */
function fc_usa_tipo(string $tipo, ?array $corso = null): void {
    $s =& fc_stato();
    $s = ['tipo' => $tipo === 'vocab' ? 'vocab' : 'study', 'corso' => $tipo === 'vocab' ? $corso : null];
}

/* ----------------------------------------------------------------------------
   INDIRIZZI E MODULI: il tipo deve viaggiare con ogni link
   ---------------------------------------------------------------------------- */

/** 'course=N' oppure '' per lo studio: si accoda a un indirizzo esistente. */
function fc_qs(): string {
    return fc_is_vocab() ? 'course=' . fc_corso_id() : '';
}

/** Indirizzo di una pagina delle flashcard con il tipo gia' dentro. */
function fc_url(string $pagina, array $parametri = []): string {
    if (fc_is_vocab()) $parametri = ['course' => fc_corso_id()] + $parametri;
    $q = http_build_query($parametri);
    return $pagina . ($q !== '' ? (strpos($pagina, '?') === false ? '?' : '&') . $q : '');
}

/** Il campo nascosto da mettere in ogni <form> che scrive. */
function fc_campo_tipo(): string {
    return fc_is_vocab() ? '<input type="hidden" name="course" value="' . fc_corso_id() . '">' : '';
}

/* ----------------------------------------------------------------------------
   PROPRIETA' DEI MAZZI
   ---------------------------------------------------------------------------- */

/**
 * Pezzo di WHERE che tiene i mazzi di lingua dentro il corso corrente.
 * Ritorna [sql, parametri]: per lo studio e' vuoto, cosi' la stessa query
 * gira su entrambi i tipi.
 *     [$w, $p] = fc_filtro_corso('c');
 *     sec_all("SELECT ... FROM " . fc_t('decks') . " c WHERE c.user_id = ?" . $w, array_merge([$uid], $p));
 */
function fc_filtro_corso(string $alias = ''): array {
    if (!fc_is_vocab()) return ['', []];
    $col = ($alias !== '' ? $alias . '.' : '') . 'course_id';
    return [" AND $col = ?", [fc_corso_id()]];
}

/**
 * Condizione sulle partite (fc_games) del tipo corrente: per lo studio
 * kind='study', per le lingue kind='vocab' del corso. Il numero del corso
 * entra come intero, non come testo: viene dal server (fc_leggi_corso, gia'
 * verificato dell'utente) ed e' convertito con (int), quindi non puo' portare
 * dentro niente altro. Una stringa e non [sql, parametri] perche' le query
 * dello storico la usano anche dentro sottoquery, dove tenere in ordine i
 * segnaposto sarebbe la fonte d'errore piu' probabile.
 */
function fc_sql_partite(string $alias = ''): string {
    $a = $alias !== '' ? $alias . '.' : '';
    return fc_is_vocab()
        ? "{$a}kind = 'vocab' AND {$a}course_id = " . (int)fc_corso_id()
        : "{$a}kind = 'study'";
}

/** Il mazzo e' dell'utente E (per le lingue) del corso corrente. */
function fc_mazzo_mio(int $deckId, int $userId): bool {
    if ($deckId <= 0 || $userId <= 0) return false;
    [$w, $p] = fc_filtro_corso();
    return sec_one("SELECT 1 AS ok FROM " . fc_t('decks') . " WHERE id = ? AND user_id = ?" . $w . " LIMIT 1",
                   array_merge([$deckId, $userId], $p)) !== null;
}

/* ----------------------------------------------------------------------------
   CONTENUTO DELLE CARTE
   ---------------------------------------------------------------------------- */

/**
 * Le colonne di una carta con i nomi di quelle di studio. Le pagine che
 * MOSTRANO una carta (elenco, gioco, risultato) lavorano tutte con
 * question_* / answer_*: per le lingue la parola fa da domanda, la traduzione
 * da risposta, l'immagine sta col lato della parola, e il significato arriva
 * come colonna in piu'. Chi SCRIVE invece usa i nomi veri (word,
 * translation...): un alias in scrittura non esiste.
 */
function fc_colonne_carta(string $alias = ''): string {
    $a = $alias !== '' ? $alias . '.' : '';
    if (!fc_is_vocab()) return "{$a}*, NULL AS meaning";
    return "{$a}*, {$a}word AS question_desc, {$a}image AS question_image, NULL AS question_audio,"
         . " {$a}translation AS answer_desc, NULL AS answer_image, NULL AS answer_audio";
}

/** Come sopra, per le carte figlie (stessi nomi). */
function fc_colonne_figlia(string $alias = ''): string {
    return fc_colonne_carta($alias);
}

/** Colonna del testo "davanti" (domanda / parola) e "dietro" (risposta / traduzione). */
function fc_col_fronte(): string { return fc_is_vocab() ? 'word' : 'question_desc'; }
function fc_col_retro(): string  { return fc_is_vocab() ? 'translation' : 'answer_desc'; }

/**
 * Le colonne in piu' di un mazzo nuovo: per le lingue il mazzo nasce dentro il
 * corso. Ritorna [colonne, segnaposto, valori] da accodare a una INSERT.
 */
function fc_mazzo_nuovo_extra(): array {
    return fc_is_vocab() ? [', course_id', ', ?', [fc_corso_id()]] : ['', '', []];
}

/**
 * Le colonne di contenuto di una carta, uguali fra carta padre e carta figlia.
 * Servono a chi SPOSTA una carta fra le due tabelle (unisci, separa): la
 * riga si ricopia colonna per colonna, e l'elenco viene da qui, non dal
 * browser.
 */
function fc_colonne_contenuto(): array {
    return fc_is_vocab()
        ? ['word', 'translation', 'meaning', 'notes', 'image']
        : ['question_desc', 'question_image', 'question_audio', 'answer_desc', 'answer_image', 'answer_audio'];
}

/* ----------------------------------------------------------------------------
   VERSO DI UNA PARTITA DI LINGUA
   ----------------------------------------------------------------------------
   Lo sceglie l'utente all'avvio e sta in fc_games.type, come gia' faceva il
   vecchio vocabolario ("en_it"):
     w2t  si vede la parola, si risponde con la traduzione
     t2w  si vede la traduzione, si risponde con la parola
     mix  meta' e meta', alternati per posizione
   Il suffisso "_L" (carte gia' imparate) e' lo stesso dello studio ("fc_L").
   Le partite del vecchio vocabolario hanno altri nomi: "en_it" mostrava la
   parola inglese, quindi e' w2t; le altre chiedevano la parola. */
const FC_VERSI = ['w2t', 't2w', 'mix'];

function fc_verso_da_tipo(string $type): string {
    foreach (FC_VERSI as $v) if (strpos($type, $v) === 0) return $v;
    return strpos($type, 'en_it') !== false ? 'w2t' : 't2w';
}

/** Il verso di UNA posizione: per "mix" si alterna, sempre uguale per quella posizione. */
function fc_verso_posizione(string $verso, int $gameId, int $posizione): string {
    if ($verso !== 'mix') return $verso;
    return (($gameId + $posizione) % 2) === 0 ? 'w2t' : 't2w';
}

/**
 * Gira una carta di lingua (gia' con i nomi di studio, vedi fc_colonne_carta)
 * secondo il verso: in t2w la traduzione fa da domanda e la parola da
 * risposta. L'immagine resta davanti in tutti e due i versi: e' un aiuto a
 * ricordare, non la risposta. "fc_lato_parola" dice dove sta la parola, per
 * la pronuncia.
 */
function fc_gira_carta(array $card, string $verso): array {
    $card['fc_lato_parola'] = 'q';
    if (!fc_is_vocab() || $verso !== 't2w') return $card;
    [$card['question_desc'], $card['answer_desc']] = [$card['answer_desc'] ?? '', $card['question_desc'] ?? ''];
    $card['fc_lato_parola'] = 'a';
    return $card;
}

/** Cartella delle immagini, come la vede il browser (relativa alla pagina). */
function fc_cartella_immagini(): string {
    return PWA_BASE . 'media/' . (fc_is_vocab() ? 'vocab_images/' : 'flashcard_images/');
}

/** Cartella delle immagini sul disco, dove si salvano. */
function fc_cartella_immagini_disco(): string {
    return fc_is_vocab() ? IMAGE_PATH_INSERT : FC_IMAGE_PATH_INSERT;
}

/* ----------------------------------------------------------------------------
   LE LINGUE
   ---------------------------------------------------------------------------- */

/* Codice => [bandiera, voce, nome it, variante it, nome en, variante en,
               nome nella lingua stessa, variante nella lingua stessa]

   - La bandiera e' il file in svg/flags/ (codice del paese, quadrato).
   - La voce e' quella che si chiede al sintetizzatore del browser e al
     riconoscimento vocale: e' il motivo per cui la variante regionale esiste
     solo dove cambia davvero (inglese britannico e americano si pronunciano
     diversi, il tedesco no).
   - L'elenco contiene solo lingue che i sintetizzatori dei telefoni e dei
     computer sanno leggere ad alta voce: una lingua che non parla, in una
     sezione dove si ascoltano le parole, sarebbe una promessa mancata.
   - I nomi sono scritti qui e non chiesti a PHP (Locale::getDisplayLanguage)
     perche' l'estensione intl sull'hosting non e' garantita, e la stessa
     tabella servira' all'app. */
const FC_LINGUE = [
    'af'     => ['za',     'af-ZA',  'Afrikaans', '', 'Afrikaans', '', 'Afrikaans', ''],
    'sq'     => ['al',     'sq-AL',  'Albanese', '', 'Albanian', '', 'Shqip', ''],
    'ar'     => ['sa',     'ar-SA',  'Arabo', '', 'Arabic', '', 'العربية', ''],
    'bn'     => ['bd',     'bn-BD',  'Bengalese', '', 'Bengali', '', 'বাংলা', ''],
    'bg'     => ['bg',     'bg-BG',  'Bulgaro', '', 'Bulgarian', '', 'Български', ''],
    'ca'     => ['es-ct',  'ca-ES',  'Catalano', '', 'Catalan', '', 'Català', ''],
    'zh-CN'  => ['cn',     'zh-CN',  'Cinese', 'Semplificato', 'Chinese', 'Simplified', '中文', '简体'],
    'zh-TW'  => ['tw',     'zh-TW',  'Cinese', 'Tradizionale', 'Chinese', 'Traditional', '中文', '繁體'],
    'zh-HK'  => ['hk',     'zh-HK',  'Cantonese', '', 'Cantonese', '', '粵語', ''],
    'hr'     => ['hr',     'hr-HR',  'Croato', '', 'Croatian', '', 'Hrvatski', ''],
    'cs'     => ['cz',     'cs-CZ',  'Ceco', '', 'Czech', '', 'Čeština', ''],
    'da'     => ['dk',     'da-DK',  'Danese', '', 'Danish', '', 'Dansk', ''],
    'nl'     => ['nl',     'nl-NL',  'Olandese', '', 'Dutch', '', 'Nederlands', ''],
    'en-GB'  => ['gb',     'en-GB',  'Inglese', 'Regno Unito', 'English', 'United Kingdom', 'English', 'United Kingdom'],
    'en-US'  => ['us',     'en-US',  'Inglese', 'Stati Uniti', 'English', 'United States', 'English', 'United States'],
    'en-AU'  => ['au',     'en-AU',  'Inglese', 'Australia', 'English', 'Australia', 'English', 'Australia'],
    'et'     => ['ee',     'et-EE',  'Estone', '', 'Estonian', '', 'Eesti', ''],
    'fil'    => ['ph',     'fil-PH', 'Filippino', '', 'Filipino', '', 'Filipino', ''],
    'fi'     => ['fi',     'fi-FI',  'Finlandese', '', 'Finnish', '', 'Suomi', ''],
    'fr-FR'  => ['fr',     'fr-FR',  'Francese', 'Francia', 'French', 'France', 'Français', 'France'],
    'fr-CA'  => ['ca',     'fr-CA',  'Francese', 'Canada', 'French', 'Canada', 'Français', 'Canada'],
    'de'     => ['de',     'de-DE',  'Tedesco', '', 'German', '', 'Deutsch', ''],
    'el'     => ['gr',     'el-GR',  'Greco', '', 'Greek', '', 'Ελληνικά', ''],
    'he'     => ['il',     'he-IL',  'Ebraico', '', 'Hebrew', '', 'עברית', ''],
    'hi'     => ['in',     'hi-IN',  'Hindi', '', 'Hindi', '', 'हिन्दी', ''],
    'hu'     => ['hu',     'hu-HU',  'Ungherese', '', 'Hungarian', '', 'Magyar', ''],
    'is'     => ['is',     'is-IS',  'Islandese', '', 'Icelandic', '', 'Íslenska', ''],
    'id'     => ['id',     'id-ID',  'Indonesiano', '', 'Indonesian', '', 'Indonesia', ''],
    'ga'     => ['ie',     'ga-IE',  'Irlandese', '', 'Irish', '', 'Gaeilge', ''],
    'it'     => ['it',     'it-IT',  'Italiano', '', 'Italian', '', 'Italiano', ''],
    'ja'     => ['jp',     'ja-JP',  'Giapponese', '', 'Japanese', '', '日本語', ''],
    'ko'     => ['kr',     'ko-KR',  'Coreano', '', 'Korean', '', '한국어', ''],
    'lv'     => ['lv',     'lv-LV',  'Lettone', '', 'Latvian', '', 'Latviešu', ''],
    'lt'     => ['lt',     'lt-LT',  'Lituano', '', 'Lithuanian', '', 'Lietuvių', ''],
    'ms'     => ['my',     'ms-MY',  'Malese', '', 'Malay', '', 'Melayu', ''],
    'nb'     => ['no',     'nb-NO',  'Norvegese', '', 'Norwegian', '', 'Norsk', ''],
    'fa'     => ['ir',     'fa-IR',  'Persiano', '', 'Persian', '', 'فارسی', ''],
    'pl'     => ['pl',     'pl-PL',  'Polacco', '', 'Polish', '', 'Polski', ''],
    'pt-PT'  => ['pt',     'pt-PT',  'Portoghese', 'Portogallo', 'Portuguese', 'Portugal', 'Português', 'Portugal'],
    'pt-BR'  => ['br',     'pt-BR',  'Portoghese', 'Brasile', 'Portuguese', 'Brazil', 'Português', 'Brasil'],
    'ro'     => ['ro',     'ro-RO',  'Rumeno', '', 'Romanian', '', 'Română', ''],
    'ru'     => ['ru',     'ru-RU',  'Russo', '', 'Russian', '', 'Русский', ''],
    'sr'     => ['rs',     'sr-RS',  'Serbo', '', 'Serbian', '', 'Српски', ''],
    'sk'     => ['sk',     'sk-SK',  'Slovacco', '', 'Slovak', '', 'Slovenčina', ''],
    'sl'     => ['si',     'sl-SI',  'Sloveno', '', 'Slovenian', '', 'Slovenščina', ''],
    'es-ES'  => ['es',     'es-ES',  'Spagnolo', 'Spagna', 'Spanish', 'Spain', 'Español', 'España'],
    'es-MX'  => ['mx',     'es-MX',  'Spagnolo', 'Messico', 'Spanish', 'Mexico', 'Español', 'México'],
    'sw'     => ['ke',     'sw-KE',  'Swahili', '', 'Swahili', '', 'Kiswahili', ''],
    'sv'     => ['se',     'sv-SE',  'Svedese', '', 'Swedish', '', 'Svenska', ''],
    'ta'     => ['in',     'ta-IN',  'Tamil', '', 'Tamil', '', 'தமிழ்', ''],
    'th'     => ['th',     'th-TH',  'Thailandese', '', 'Thai', '', 'ไทย', ''],
    'tr'     => ['tr',     'tr-TR',  'Turco', '', 'Turkish', '', 'Türkçe', ''],
    'uk'     => ['ua',     'uk-UA',  'Ucraino', '', 'Ukrainian', '', 'Українська', ''],
    'ur'     => ['pk',     'ur-PK',  'Urdu', '', 'Urdu', '', 'اردو', ''],
    'vi'     => ['vn',     'vi-VN',  'Vietnamita', '', 'Vietnamese', '', 'Tiếng Việt', ''],
    'cy'     => ['gb-wls', 'cy-GB',  'Gallese', '', 'Welsh', '', 'Cymraeg', ''],
    'gu'     => ['in',     'gu-IN',  'Gujarati', '', 'Gujarati', '', 'ગુજરાતી', ''],
    'mr'     => ['in',     'mr-IN',  'Marathi', '', 'Marathi', '', 'मराठी', ''],
    'te'     => ['in',     'te-IN',  'Telugu', '', 'Telugu', '', 'తెలుగు', ''],
    'kn'     => ['in',     'kn-IN',  'Kannada', '', 'Kannada', '', 'ಕನ್ನಡ', ''],
    'ml'     => ['in',     'ml-IN',  'Malayalam', '', 'Malayalam', '', 'മലയാളം', ''],
    'eu'     => ['es-pv',  'eu-ES',  'Basco', '', 'Basque', '', 'Euskara', ''],
    'gl'     => ['es-ga',  'gl-ES',  'Galiziano', '', 'Galician', '', 'Galego', ''],
];

/* Le lingue proposte per prime nella ricerca, prima ancora di scrivere. */
const FC_LINGUE_POPOLARI = ['en-GB', 'es-ES', 'fr-FR', 'de', 'en-US', 'pt-BR', 'ja', 'zh-CN', 'ko', 'ru', 'ar', 'it'];

/** La lingua "di base" di un codice: en-GB -> en. */
function fc_lingua_base(string $codice): string {
    return explode('-', $codice)[0];
}

/** Il codice e' una lingua da imparare valida? */
function fc_lingua_valida(string $codice): bool {
    return isset(FC_LINGUE[$codice]);
}

/**
 * La lingua che si CONOSCE si salva senza variante (chi parla inglese lo
 * parla, britannico o americano non cambia niente per le traduzioni): basta
 * che la base esista nell'elenco.
 */
function fc_lingua_nota_valida(string $codice): bool {
    foreach (FC_LINGUE as $c => $_) if (fc_lingua_base($c) === $codice) return true;
    return false;
}

/** La voce dell'elenco per un codice, anche solo di base ('en' -> la prima inglese). */
function fc_lingua_voce(string $codice): ?array {
    if (isset(FC_LINGUE[$codice])) return FC_LINGUE[$codice] + ['codice' => $codice];
    foreach (FC_LINGUE as $c => $v) if (fc_lingua_base($c) === $codice) return $v + ['codice' => $c];
    return null;
}

/**
 * Nome da mostrare, nella lingua dell'interfaccia.
 * $conVariante: "Inglese (Regno Unito)" invece di "Inglese". La variante si
 * mostra solo dove serve a distinguere, non sulla lingua che si conosce.
 */
function fc_lingua_nome(string $codice, bool $conVariante = true, ?string $ui = null): string {
    $v = fc_lingua_voce($codice);
    if (!$v) return $codice;
    $ui = $ui ?? (function_exists('standout_lingua') ? standout_lingua() : 'it');
    [$nome, $variante] = $ui === 'en' ? [$v[4], $v[5]] : [$v[2], $v[3]];
    if (!$conVariante || strpos($codice, '-') === false || $variante === '') return $nome;
    return $nome . ' (' . $variante . ')';
}

/** Il nome della lingua nella lingua stessa: "Deutsch", "日本語". */
function fc_lingua_nativo(string $codice): string {
    $v = fc_lingua_voce($codice);
    return $v ? $v[6] : $codice;
}

/** File della bandiera (svg/flags/xx.svg). */
function fc_lingua_bandiera(string $codice): string {
    $v = fc_lingua_voce($codice);
    return PWA_BASE . '_assets/svg/flags/' . ($v ? $v[0] : 'xx') . '.svg';
}

/** Voce per sintesi e riconoscimento vocale: 'en-GB', 'de-DE'... */
function fc_lingua_voce_tts(string $codice): string {
    $v = fc_lingua_voce($codice);
    return $v ? $v[1] : $codice;
}

/** La lingua che l'utente conosce, se non ha ancora scelto: quella dell'interfaccia. */
function fc_lingua_nota_predefinita(): string {
    $ui = function_exists('standout_lingua') ? standout_lingua() : 'it';
    return fc_lingua_nota_valida($ui) ? $ui : 'it';
}

/**
 * Tutto quello che il JavaScript di una pagina deve sapere sul tipo: va in
 * pagina come blocco JSON (come i18n), cosi' i link costruiti dal JavaScript
 * possono portarsi dietro il corso esattamente come quelli scritti dal PHP.
 */
function fc_boot_json(): string {
    $c = fc_corso();
    $dati = ['tipo' => fc_tipo(), 'qs' => fc_qs(), 'immagini' => fc_cartella_immagini()];
    if ($c) {
        $dati['corso'] = (int)$c['id'];
        $dati['impara'] = ['codice' => $c['learn_lang'], 'voce' => fc_lingua_voce_tts($c['learn_lang']),
                           'nome' => fc_lingua_nome($c['learn_lang']), 'bandiera' => fc_lingua_bandiera($c['learn_lang'])];
        $dati['conosce'] = ['codice' => $c['known_lang'], 'voce' => fc_lingua_voce_tts($c['known_lang']),
                            'nome' => fc_lingua_nome($c['known_lang'], false), 'bandiera' => fc_lingua_bandiera($c['known_lang'])];
    }
    return '<script type="application/json" id="fc-boot">'
         . json_encode($dati, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) . '</script>'
         . "\n<script>window.FC = (function(){ try { return JSON.parse(document.getElementById('fc-boot').textContent); } catch (e) { return {tipo:'study', qs:''}; } })();"
         . " window.fcUrl = function (pagina) { if (!FC.qs) return pagina; return pagina + (pagina.indexOf('?') === -1 ? '?' : '&') + FC.qs; };</script>\n";
}
