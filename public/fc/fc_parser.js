// fc_doc.js
// ================================================================
// COME VANNO CONFIGURATE LE FORMULE — E PERCHE' QUI
// ================================================================
// Questa configurazione stava scritta a mano in ogni pagina che disegna
// formule, e non era la stessa dappertutto: fc_card_edit.php
// conosceva \cancel, fc_view.php no, e la stessa carta risultava
// barrata nel popup di modifica e con un "\cancel" rosso nell'elenco. Un
// difetto di questo tipo non lo si trova leggendo il codice: lo si trova per
// caso, guardando la stessa cosa in due posti.
//
// Sta qui perche' e' questo file a produrre le formule: e' fcdDisegnaFormule()
// a racchiudere il LaTeX in \( \), quindi e' questo file a sapere che cosa
// MathJax dovra' essere in grado di leggere. Chi lo carica non deve piu'
// saperlo.
//
// UNA CONDIZIONE, E VA RISPETTATA: window.MathJax dev'essere gia' scritto
// quando MathJax parte, perche' MathJax lo legge una volta sola all'avvio.
// Quindi in ogni pagina questo file va incluso PRIMA del <script> di MathJax.
// (In fc_card_edit.php lo era gia'; in fc_view.php e' stato
// spostato apposta.)
//
// Il "|| " non e' prudenza generica: una pagina che si e' scritta la sua
// configurazione prima di questa riga continua a vincere, quindi aggiungere
// questo blocco non puo' rompere niente di quello che c'era.
if (typeof window !== 'undefined') {
    window.MathJax = window.MathJax || {
        /* \cancel, \bcancel, \xcancel non sono nel pacchetto base: senza
           caricarne l'estensione, MathJax stampa il comando cosi' com'e', in
           rosso, dentro la formula. */
        loader: { load: ['[tex]/cancel'] },
        tex: {
            packages: { '[+]': ['cancel'] },

            /* I DOLLARI NON SONO DELIMITATORI DI FORMULA.
               Col formato nuovo la formattazione non sta dentro il testo: una
               formula e' un elemento suo, e il LaTeX arriva a MathJax gia'
               racchiuso in \( \) o \[ \] da fcdDisegnaFormule(). Lasciare
               attivo il "$" vorrebbe dire che "il libro costa 5$ e il quaderno
               3$" viene preso per una formula e stampato in corsivo
               matematico — lo stesso identico difetto che il formato nuovo
               esiste per eliminare. */
            inlineMath: [['\\(', '\\)']],
            displayMath: [['\\[', '\\]']]
        }
    };
}

// fc_doc.js
// ================================================================
// MOTORE DEL DOCUMENTO — sostituisce il formato a tag annidati.
//
// PERCHE' E' CAMBIATO TUTTO
//
// Il formato precedente (/bold{...}, /red{...}) era un ALBERO: ogni tag un
// contenitore, i contenitori uno dentro l'altro. Da li' venivano, tutti
// insieme, i difetti che si vedevano usandolo:
//
//   - ricliccare "grassetto" annidava /bold{/bold{x}} invece di togliere,
//     perche' in un albero "applica" e' l'unica operazione possibile;
//   - meta' di un grassetto rossa e meta' gialla era impossibile da scrivere,
//     perche' due contenitori non possono accavallarsi: o uno contiene
//     l'altro, o sono separati;
//   - un "$" in mezzo a una frase ("costa 5$ e 3$") diventava una formula,
//     perche' la formattazione stava dentro il testo e andava indovinata
//     rileggendola.
//
// Il modello nuovo e' quello di Word e di Google Docs: il testo e' PIATTO, e
// la formattazione sono ETICHETTE indipendenti appiccicate ai pezzi di testo.
// "Grassetto" e "rosso" sono due etichette diverse sullo stesso pezzo: non si
// contengono, quindi possono accavallarsi come vogliono. Togliere un'etichetta
// e' un'operazione precisa quanto metterla — da cui gli interruttori che si
// spengono davvero.
//
// E siccome la formattazione non e' piu' scritta DENTRO il testo, non esiste
// piu' nessun carattere speciale da proteggere: si puo' scrivere "5$",
// "/bold{", "}" e qualunque altra cosa senza che venga interpretata. La classe
// di bug dell'escaping non e' stata risolta, e' stata resa impossibile.
//
// COM'E' FATTO UN DOCUMENTO
//
//   { v:2, blocks:[ ... ] }
//
//   blocco paragrafo   { t:'p',  runs:[...] }
//   blocco elenco      { t:'li', lvl:1, c:'red'|null, runs:[...] }
//   blocco formula     { t:'fx', tex:'x^2' }              (quella "seria")
//
//   run di testo       { s:'ciao', m:{b:1,i:1,u:1,h:1,c:'red'} }
//   run formula        { x:'x^2' }                        (in linea)
//
// I marchi (m) sono: b grassetto, i corsivo, u sottolineato, h evidenziato,
// c colore. Assenti quando spenti — cosi' due run con la stessa formattazione
// sono identici anche a confronto diretto, e si possono fondere.
//
// COMPATIBILITA'
//
// fcdParse() riconosce da sola se quello che legge e' il formato nuovo (JSON)
// o quello vecchio a tag, e nel secondo caso lo converte. Niente migrazione
// del database: le schede vecchie continuano ad aprirsi, e si salvano nel
// formato nuovo la prima volta che le si tocca.
// ================================================================

var FCD_V = 2;

var FCD_PALETTE = {
    red:    'var(--red, #FF3B30)',
    blue:   'var(--blue, #007AFF)',
    green:  'var(--green, #34C759)',
    purple: 'var(--purple, #AF52DE)',
    orange: 'var(--orange, #FF9500)',
    gray:   'var(--text-2, #8A8A8E)',
    /* Colori in piu' per il testo (non per i pallini degli elenchi, che
       restano i sei di FCD_LIST_COLORS). Chi rilegge un documento accetta
       qualunque chiave di questa tabella: aggiungere un colore qui basta. */
    gold:   'var(--yellow-text, #D99A00)',
    teal:   'var(--teal, #1FA9A0)',
    indigo: 'var(--indigo, #5856D6)',
    pink:   'var(--pink, #E8387A)',
    brown:  'var(--brown, #A2845E)'
};
var FCD_COLORS = ['red', 'orange', 'gold', 'green', 'teal', 'blue', 'indigo', 'purple', 'pink', 'brown', 'gray'];

/* Un colore del TESTO e' valido se sta nella tavolozza. Non si usa
   FCD_LIST_COLORS: quella e' la lista dei colori dei pallini. */
function fcdColoreTestoValido(c) {
    return typeof c === 'string' && Object.prototype.hasOwnProperty.call(FCD_PALETTE, c);
}
var FCD_LIST_COLORS = ['red', 'orange', 'green', 'blue', 'purple', 'gray'];
var FCD_FLAGS = ['b', 'i', 'u', 'h'];

/* I pallini cambiano forma scendendo di livello, come in Word. Oltre il
   sesto si ricomincia: nessuno arriva li', e un elenco senza pallino
   sarebbe peggio di un pallino ripetuto. */
var FCD_BULLETS = ['\u2022', '\u25E6', '\u25AA', '\u2023', '\u2043', '\u25AB'];

function fcdBullet(lvl) {
    var i = (Math.max(1, lvl | 0) - 1) % FCD_BULLETS.length;
    return FCD_BULLETS[i];
}

// ================================================================
// 1) COSTRUZIONE E NORMALIZZAZIONE
// ================================================================

function fcdEmpty() { return { v: FCD_V, blocks: [{ t: 'p', runs: [] }] }; }

function fcdCloneMarks(m) {
    if (!m) return null;
    var out = null;
    for (var k in m) {
        if (!m.hasOwnProperty(k)) continue;
        if (m[k] === null || m[k] === undefined || m[k] === false || m[k] === '') continue;
        (out || (out = {}))[k] = m[k];
    }
    return out;
}

/* Due formattazioni sono uguali se hanno le stesse etichette con gli stessi
   valori. Serve a fondere i pezzi contigui: senza, scrivere una lettera per
   volta produrrebbe un run per lettera e il documento crescerebbe all'infinito
   pur contenendo la stessa cosa. */
function fcdSameMarks(a, b) {
    a = a || {}; b = b || {};
    var ka = Object.keys(a), kb = Object.keys(b);
    if (ka.length !== kb.length) return false;
    for (var i = 0; i < ka.length; i++) if (a[ka[i]] !== b[ka[i]]) return false;
    return true;
}

/* Forma canonica: via i run vuoti, fusi quelli contigui con la stessa
   formattazione, blocchi sempre validi. Ogni operazione finisce qui, cosi'
   due documenti che appaiono uguali SONO uguali anche come dati — che e' cio'
   che rende affidabile il confronto "e' cambiato qualcosa?" del salvataggio. */
function fcdNorm(doc) {
    if (!doc || !Array.isArray(doc.blocks)) return fcdEmpty();
    var out = [];

    doc.blocks.forEach(function (b) {
        if (!b || typeof b !== 'object') return;

        if (b.t === 'fx') {
            var fx = { t: 'fx', tex: String(b.tex == null ? '' : b.tex) };
            /* Una formula puo' stare dentro un elenco: e' il caso di una lista
               di formule, che senza questo finiva sempre fuori dai punti. */
            var lv = parseInt(b.lvl, 10);
            if (lv >= 1) { fx.lvl = Math.min(lv, 12); if (FCD_LIST_COLORS.indexOf(b.c) !== -1) fx.c = b.c; }
            var mFx2 = fcdCloneMarks(b.m);
            if (mFx2) fx.m = mFx2;
            out.push(fx);
            return;
        }

        var runs = [];
        (Array.isArray(b.runs) ? b.runs : []).forEach(function (r) {
            if (!r || typeof r !== 'object') return;

            if (typeof r.x === 'string') {
                var mf = fcdCloneMarks(r.m);
                runs.push(mf ? { x: r.x, m: mf } : { x: r.x });   // formula vuota = chip da riempire
                return;
            }
            var s = String(r.s == null ? '' : r.s).replace(/\u200B/g, '');
            if (s === '') return;
            var m = fcdCloneMarks(r.m);
            var last = runs[runs.length - 1];
            if (last && typeof last.s === 'string' && fcdSameMarks(last.m, m)) last.s += s;
            else runs.push(m ? { s: s, m: m } : { s: s });
        });

        if (b.t === 'li') {
            var lvl = parseInt(b.lvl, 10); if (!(lvl >= 1)) lvl = 1; if (lvl > 12) lvl = 12;
            var col = (FCD_LIST_COLORS.indexOf(b.c) !== -1) ? b.c : null;
            var li = { t: 'li', lvl: lvl, runs: runs };
            if (col) li.c = col;
            /* punto di una checklist: 0 da fare, 1 fatto */
            var ck = parseInt(b.ck, 10);
            if (ck === 0 || ck === 1) li.ck = ck;
            out.push(li);
        } else {
            /* Le righe vuote salvate dalla versione che contava il <br> segnaposto
               come a-capo (un blocco col solo "\n"): si leggono come righe vuote vere,
               cosi' i testi gia' incollati tornano a misura senza doverli rifare. */
            if (runs.length === 1 && runs[0].s === '\n' && !runs[0].m) runs = [];
            var p = { t: 'p', runs: runs };
            /* Un paragrafo normale puo' portare un .lvl senza diventare un
               punto elenco: e' la riga di continuo dentro una lista, stessa
               rientranza ma senza pallino proprio. Nessun colore qui: quello
               vive solo sui punti elenco veri, non ha senso su una riga che
               un pallino non lo disegna nemmeno. */
            var lvp = parseInt(b.lvl, 10);
            if (lvp >= 1) p.lvl = Math.min(lvp, 12);
            /* riga di codice: niente rientro da elenco, la lingua dev'essere nota */
            if (b.code && fcdCodeOk(b.code)) {
                p.code = b.code; delete p.lvl;
                if (b.hl) p.hl = 1;            // riga evidenziata (striscia gialla a sinistra)
            } else if (b.q) p.q = 1;           // citazione
            out.push(p);
        }
    });

    if (!out.length) out.push({ t: 'p', runs: [] });
    return { v: FCD_V, blocks: out };
}

// ================================================================
// 2) TESTO E POSIZIONI
// ================================================================
/* La posizione del cursore e' una coppia (blocco, carattere). Ogni run di
   testo vale la sua lunghezza; una formula in linea vale la lunghezza del suo
   TeX, perche' nell'editor la formula si scrive e si corregge come testo
   normale. Cosi' il conto dei caratteri nel modello e quello nel DOM sono la
   stessa cosa, e non serve nessuna tabella di conversione: e' li' che di
   solito gli editor si rompono. */

function fcdRunText(r) { return typeof r.x === 'string' ? r.x : (r.s || ''); }

function fcdBlockText(b) {
    if (!b) return '';
    if (b.t === 'fx') return String(b.tex || '');
    return (b.runs || []).map(fcdRunText).join('');
}

function fcdBlockLen(b) { return fcdBlockText(b).length; }

/* Spezza i run in modo che ci sia un confine esatto alla posizione data.
   Tutte le operazioni sui marchi passano di qui: lavorare su confini gia'
   esistenti e' l'unico modo di non dover trattare mille casi particolari. */
function fcdSplitAt(block, pos) {
    var runs = block.runs || (block.runs = []);
    var acc = 0;
    for (var i = 0; i < runs.length; i++) {
        var len = fcdRunText(runs[i]).length;
        if (pos === acc) return i;
        if (pos < acc + len) {
            if (typeof runs[i].x === 'string') return pos - acc < len / 2 ? i : i + 1;  // le formule non si spezzano
            var cut = pos - acc;
            var a = { s: runs[i].s.slice(0, cut) }, bb = { s: runs[i].s.slice(cut) };
            if (runs[i].m) { a.m = fcdCloneMarks(runs[i].m); bb.m = fcdCloneMarks(runs[i].m); }
            runs.splice(i, 1, a, bb);
            return i + 1;
        }
        acc += len;
    }
    return runs.length;
}

// ================================================================
// 3) I MARCHI — mettere, togliere, interrogare
// ================================================================

/* Vero se OGNI carattere del tratto ha gia' quel marchio. E' la domanda che
   decide se un tasto deve accendere o spegnere: se e' gia' tutto grassetto lo
   toglie, altrimenti lo mette. E' il comportamento di Word, ed e' quello che
   la gente si aspetta senza doverlo imparare. */
function fcdRangeHas(doc, sel, key, val) {
    var vuoto = true, ok = true;
    fcdEachRunInRange(doc, sel, function (run) {
        vuoto = false;
        var m = run.m || {};
        if (val === undefined) { if (!m[key]) ok = false; }
        else if (m[key] !== val) ok = false;
    });
    return vuoto ? false : ok;
}

function fcdEachRunInRange(doc, sel, fn) {
    var s = fcdOrder(sel);
    for (var bi = s.a.b; bi <= s.f.b; bi++) {
        var block = doc.blocks[bi];
        if (!block) continue;

        if (block.t === 'fx') {
            var iniziaPrima = (bi > s.a.b) || (s.a.o < fcdBlockLen(block));
            var finiscePoi = (bi < s.f.b) || (s.f.o > 0);
            if (iniziaPrima && finiscePoi) fn({ m: block.m }, block);
            continue;
        }

        var from = (bi === s.a.b) ? s.a.o : 0;
        var to   = (bi === s.f.b) ? s.f.o : fcdBlockLen(block);
        if (from === to && s.a.b !== s.f.b) continue;
        var acc = 0;
        (block.runs || []).forEach(function (r) {
            var len = fcdRunText(r).length;
            if (acc < to && acc + len > from) fn(r, block);
            acc += len;
        });
    }
}

/* Mette o toglie un marchio sul tratto selezionato. val === null toglie. */
function fcdSetMark(doc, sel, key, val) {
    var s = fcdOrder(sel);
    for (var bi = s.a.b; bi <= s.f.b; bi++) {
        var block = doc.blocks[bi];
        if (!block) continue;

        if (block.t === 'fx') {
            /* Un blocco intero, non un pezzo: se la selezione lo tocca in
               un punto qualsiasi (le regole di fcdSplitAt sulle formule
               arrotondano gia' a "tutta dentro o tutta fuori"), il marchio
               si applica a tutto il blocco. */
            var toccaFx = (bi > s.a.b || s.a.o < fcdBlockLen(block)) && (bi < s.f.b || s.f.o > 0);
            if (!toccaFx) continue;
            var mFx = fcdCloneMarks(block.m) || {};
            if (val === null || val === undefined || val === false) delete mFx[key];
            else mFx[key] = val;
            var nmFx = fcdCloneMarks(mFx);
            if (nmFx) block.m = nmFx; else delete block.m;
            continue;
        }

        var from = (bi === s.a.b) ? s.a.o : 0;
        var to   = (bi === s.f.b) ? s.f.o : fcdBlockLen(block);
        if (from >= to) continue;

        var i0 = fcdSplitAt(block, from);
        var i1 = fcdSplitAt(block, to);
        for (var i = i0; i < i1 && i < block.runs.length; i++) {
            var r = block.runs[i];
            var m = fcdCloneMarks(r.m) || {};
            if (val === null || val === undefined || val === false) delete m[key];
            else m[key] = val;
            var nm = fcdCloneMarks(m);
            if (nm) r.m = nm; else delete r.m;
        }
    }
    return fcdNorm(doc);
}

/* L'interruttore vero: guarda com'e' e fa il contrario. */
function fcdToggleMark(doc, sel, key, val) {
    var acceso = (val === undefined)
        ? fcdRangeHas(doc, sel, key)
        : fcdRangeHas(doc, sel, key, val);
    return fcdSetMark(doc, sel, key, acceso ? null : (val === undefined ? 1 : val));
}

/* L'EVIDENZIATORE SEGUE IL COLORE. Il marchio h vale 1 (giallo) oppure il nome di
   un colore della tavolozza. Acceso, prende il colore del testo che evidenzia;
   cambiando il colore a un testo gia' evidenziato, cambia anche l'evidenziatore. */
/* Colore del testo e colore dell'evidenziatore sono DUE scelte separate
   (02/11/2026): prima l'evidenziatore copiava il colore del testo, e metterlo
   rosso colorava di rosso anche le lettere. Ora l'interruttore della barra
   (testo / evidenziatore) decide su quale dei due agiscono i pallini; il tasto
   del pennarello mette sempre il giallo. */
function fcdToggleHighlight(doc, sel) {
    if (fcdRangeHas(doc, sel, 'h')) return fcdSetMark(doc, sel, 'h', null);
    return fcdSetMark(doc, sel, 'h', 1);
}
function fcdSetColor(doc, sel, colore) {
    return fcdSetMark(doc, sel, 'c', colore || null);
}

function fcdOrder(sel) {
    var a = sel.a, f = sel.f;
    if (a.b > f.b || (a.b === f.b && a.o > f.o)) return { a: f, f: a };
    return { a: a, f: f };
}

// ================================================================
// 4) BLOCCHI — elenchi, rientri, formule a blocco
// ================================================================

function fcdEachBlockInSel(doc, sel, fn) {
    var s = fcdOrder(sel);
    for (var i = s.a.b; i <= s.f.b; i++) if (doc.blocks[i]) fn(doc.blocks[i], i);
}

// ================================================================
// BLOCCHI DI CODICE
// ================================================================
// Un blocco di codice NON e' un tipo nuovo di blocco: e' una serie di
// paragrafi di fila con la proprieta' code ("auto", "python", "cpp"...), uno
// per riga. Cosi' tutto quello che gia' funziona per i paragrafi — cursore,
// Invio, incolla, annulla, salvataggio — funziona senza una riga di codice in
// piu': premere Invio in una riga di codice ne apre un'altra, e l'altra e'
// ancora codice (vedi fcdSplitBlock). La lingua vale per tutto il gruppo ed e'
// quella della PRIMA riga.
// ================================================================
var FCD_CODE_LANGS = {
    python: 'Python', cpp: 'C++', c: 'C', java: 'Java', js: 'JavaScript', ts: 'TypeScript',
    sql: 'SQL', html: 'HTML', css: 'CSS', bash: 'Bash', json: 'JSON', csharp: 'C#', php: 'PHP', other: 'Code'
};
function fcdCodeOk(v) { return v === 'auto' || Object.prototype.hasOwnProperty.call(FCD_CODE_LANGS, v); }

var FCD_KW = (function () {
    function s(x) { var o = {}; x.split(' ').forEach(function (k) { o[k] = 1; }); return o; }
    var cLike = 'if else for while do switch case break continue return goto sizeof typedef struct union enum static const extern void int char float double long short unsigned signed auto register volatile';
    var cpp = cLike + ' class public private protected virtual override namespace using template typename new delete this try catch throw bool true false nullptr inline operator friend explicit constexpr include define string vector std cout cin endl';
    var java = 'abstract assert boolean break byte case catch char class const continue default do double else enum extends final finally float for if implements import instanceof int interface long new package private protected public return short static super switch synchronized this throw throws try void volatile while true false null var String';
    var js = 'async await break case catch class const continue debugger default delete do else export extends finally for function if import in instanceof let new return super switch this throw try typeof var void while with yield true false null undefined of from';
    return {
        python: s('and as assert async await break class continue def del elif else except finally for from global if import in is lambda None nonlocal not or pass raise return try while with yield True False self print'),
        cpp: s(cpp), c: s(cLike + ' include define'), java: s(java), csharp: s(java + ' namespace using foreach var string bool object partial readonly override virtual abstract async await'),
        js: s(js), ts: s(js + ' interface type enum implements readonly private public protected number string boolean any unknown never'),
        sql: s('select from where and or not in is null insert into values update set delete create table drop alter add join inner left right outer on group by order having limit offset as distinct union all primary key foreign references index view like between exists case when then else end asc desc count sum avg min max'),
        bash: s('if then else elif fi for while do done case esac function in echo cd ls sudo export source return exit pip npm git mkdir rm cp mv cat grep'),
        php: s('function class public private protected static return if else elseif foreach for while echo new use namespace try catch throw null true false array'),
        css: {}, html: {}, json: { true: 1, false: 1, null: 1 }, other: {}
    };
})();

/* Riconosce la lingua dal testo: ogni lingua guadagna un punto per ogni indizio
   che il codice mostra. Pochi indizi netti battono tanti vaghi; senza indizi
   resta "other" (colorato in modo generico). */
function fcdDetectLang(text) {
    var t = String(text || '');
    var P = {
        python: [/^\s*def\s+\w+\s*\(.*\)\s*(->\s*[\w\[\], .]+)?\s*:/m, /^\s*(from\s+[\w.]+\s+import|import\s+\w+)/m, /\bprint\s*\(/, /\belif\b|\bself\b|\bNone\b|\bTrue\b|\bFalse\b/, /^\s*(if|for|while|class|else|try|with)\b.*:\s*$/m, /\brange\(|\blen\(/],
        cpp: [/#include\s*[<"]/, /\bstd::/, /\b(cout|cin|endl)\b/, /\bint\s+main\s*\(/, /\busing\s+namespace\b/, /\b(vector|unique_ptr|nullptr)\b/],
        c: [/#include\s*<std(io|lib)\.h>/, /\bprintf\s*\(/, /\bint\s+main\s*\(/],
        java: [/\bpublic\s+(static\s+)?(class|void|int)\b/, /System\.out\.print/, /\bString\[\]\s+args\b/, /\bimport\s+java\./],
        csharp: [/\busing\s+System/, /Console\.Write/, /\bnamespace\s+\w+/, /\bstatic\s+void\s+Main/],
        js: [/\b(const|let)\s+\w+\s*=/, /=>/, /console\.log/, /\bfunction\s*\w*\s*\(/, /\brequire\(|\bmodule\.exports|\bdocument\./],
        ts: [/:\s*(string|number|boolean|any)\b/, /\binterface\s+\w+/, /\btype\s+\w+\s*=/, /<[A-Z]\w*>/],
        sql: [/\bselect\b[\s\S]*\bfrom\b/i, /\binsert\s+into\b/i, /\bcreate\s+table\b/i, /\bwhere\b[\s\S]*=/i, /\bgroup\s+by\b|\border\s+by\b/i],
        html: [/<\/?(div|html|body|span|head|script|ul|li|p|a|h[1-6])[\s>]/i, /<!doctype/i],
        css: [/[.#]?[\w-]+\s*\{[^}]*:[^}]*;/],
        bash: [/^#!\/bin\/(ba)?sh/m, /^\s*\$\s+\w+/m, /^\s*(sudo|pip|npm|git|cd|ls|echo|mkdir)\s+/m],
        json: [/^\s*[\{\[]\s*$/m, /"[\w-]+"\s*:\s*("|\d|\{|\[|true|false|null)/],
        php: [/<\?php/, /\$\w+\s*=/]
    };
    var best = 'other', bestN = 0;
    Object.keys(P).forEach(function (lang) {
        var n = 0;
        P[lang].forEach(function (re) { if (re.test(t)) n++; });
        if (n > bestN || (n === bestN && n > 0 && lang === 'cpp' && best === 'c')) { best = lang; bestN = n; }
    });
    return bestN ? best : 'other';
}

/* Colora una riga alla volta, portandosi dietro lo stato per i commenti e le
   stringhe che continuano sulla riga dopo (st.m). Niente di sofisticato di
   proposito: parole chiave, stringhe, commenti, numeri e nomi di funzione bastano
   a far leggere il codice; una grammatica vera peserebbe di piu' del resto. */
function fcdHlLine(line, lang, st) {
    var kw = FCD_KW[lang] || {};
    var lineC = (lang === 'python' || lang === 'bash') ? ['#'] : (lang === 'sql' ? ['--'] : (lang === 'html' || lang === 'json' ? [] : ['//']));
    if (lang === 'php') lineC = ['//', '#'];
    var blockC = (lang === 'python' || lang === 'bash' || lang === 'json') ? null : (lang === 'html' ? ['<!--', '-->'] : ['/*', '*/']);
    var out = '', i = 0, n = line.length;
    function sp(cls, txt) { return '<span class="fc-tk fc-tk-' + cls + '">' + fcdEscape(txt) + '</span>'; }
    function plain(txt) { return fcdEscape(txt); }

    while (i < n) {
        if (st.m) {                                       // dentro un commento/stringa che veniva da sopra
            var fine = st.m === 'tq' ? st.q : blockC[1];
            var k = line.indexOf(fine, i);
            var cls = st.m === 'tq' ? 's' : 'c';
            if (k === -1) { out += sp(cls, line.slice(i)); i = n; }
            else { out += sp(cls, line.slice(i, k + fine.length)); i = k + fine.length; st.m = null; }
            continue;
        }
        var rest = line.slice(i);
        var lc = null;
        for (var a = 0; a < lineC.length; a++) if (rest.indexOf(lineC[a]) === 0) { lc = lineC[a]; break; }
        if (lc && !(lang === 'css' && false)) { out += sp('c', rest); i = n; break; }
        if (blockC && rest.indexOf(blockC[0]) === 0) {
            var kk = line.indexOf(blockC[1], i + blockC[0].length);
            if (kk === -1) { out += sp('c', rest); st.m = 'bc'; i = n; }
            else { out += sp('c', line.slice(i, kk + blockC[1].length)); i = kk + blockC[1].length; }
            continue;
        }
        if (lang === 'python' && (rest.indexOf('"""') === 0 || rest.indexOf("'''") === 0)) {
            var q3 = rest.slice(0, 3), k3 = line.indexOf(q3, i + 3);
            if (k3 === -1) { out += sp('s', rest); st.m = 'tq'; st.q = q3; i = n; }
            else { out += sp('s', line.slice(i, k3 + 3)); i = k3 + 3; }
            continue;
        }
        var ch = line.charAt(i);
        if (ch === '"' || ch === "'" || (ch === '`' && lang !== 'python' && lang !== 'sql')) {
            var j = i + 1;
            while (j < n && line.charAt(j) !== ch) { if (line.charAt(j) === '\\') j++; j++; }
            out += sp('s', line.slice(i, Math.min(j + 1, n))); i = Math.min(j + 1, n);
            continue;
        }
        var mNum = /^(0[xX][0-9a-fA-F_]+|\d[\d_]*\.?\d*([eE][+-]?\d+)?)/.exec(rest);
        if (mNum && !/[\w$]/.test(i ? line.charAt(i - 1) : ' ')) { out += sp('n', mNum[0]); i += mNum[0].length; continue; }
        var mId = /^[A-Za-z_$][\w$]*/.exec(rest);
        if (mId) {
            var w = mId[0], key = (lang === 'sql') ? w.toLowerCase() : w;
            var next = line.charAt(i + w.length);
            if (kw[key]) out += sp('k', w);
            else if (next === '(' && lang !== 'json') out += sp('f', w);
            else if (/^[A-Z][a-z]/.test(w) && lang !== 'sql' && lang !== 'css' && lang !== 'html') out += sp('t', w);
            else out += plain(w);
            i += w.length; continue;
        }
        out += plain(ch); i++;
    }
    return out;
}

/* Colora un gruppo di righe, ognuna col suo HTML. */
function fcdHlGroup(lines, lang) {
    var st = { m: null, q: null };
    return lines.map(function (l) { return fcdHlLine(l, lang, st); });
}

/* Una proprieta' "code" su un blocco ne fa una riga di codice. */
function fcdIsCode(b) { return !!(b && b.t === 'p' && b.code); }

/* Lingua del gruppo che contiene il blocco i (quella della prima riga del gruppo). */
function fcdCodeGroup(doc, i) {
    if (!fcdIsCode(doc.blocks[i])) return null;
    var a = i, z = i;
    while (a > 0 && fcdIsCode(doc.blocks[a - 1])) a--;
    while (z < doc.blocks.length - 1 && fcdIsCode(doc.blocks[z + 1])) z++;
    return { a: a, z: z, code: doc.blocks[a].code };
}

/* Il tasto del codice. Come per l'elenco: se tutte le righe toccate sono gia'
   codice tornano testo; altrimenti diventano codice (lingua "auto" se non se ne
   sceglie una). I marchi (grassetto, colore) si tolgono: dentro il codice non
   hanno senso, e il colore lo danno le parole. */
function fcdToggleCode(doc, sel, lang) {
    var tutti = true, n = 0;
    fcdEachBlockInSel(doc, sel, function (b) { if (b.t === 'fx') return; n++; if (!fcdIsCode(b)) tutti = false; });
    if (!n) return { doc: fcdNorm(doc), sel: sel };
    fcdEachBlockInSel(doc, sel, function (b) {
        if (b.t === 'fx') return;
        if (tutti && !lang) { delete b.code; return; }
        b.t = 'p'; delete b.lvl; delete b.c; delete b.ck; delete b.q;
        b.code = lang || 'auto';
        b.runs = [{ s: (b.runs || []).map(function (r) { return typeof r.x === 'string' ? r.x : r.s; }).join('') }];
    });
    return { doc: fcdNorm(doc), sel: sel };
}

/* Cambia la lingua del gruppo sotto il cursore. Se il cursore non e' su una riga
   di codice, la riga diventa codice con quella lingua. */
function fcdSetCodeLang(doc, sel, lang) {
    var s = fcdOrder(sel);
    var g = fcdCodeGroup(doc, s.a.b);
    if (!g) return fcdToggleCode(doc, sel, lang);
    for (var i = g.a; i <= g.z; i++) doc.blocks[i].code = lang;
    return { doc: fcdNorm(doc), sel: sel };
}

/* Evidenzia (o toglie l'evidenziazione) alle righe di codice toccate dalla
   selezione: una striscia gialla a sinistra, come nei diff. */
function fcdToggleCodeHl(doc, sel) {
    var tutti = true, n = 0;
    fcdEachBlockInSel(doc, sel, function (b) { if (!fcdIsCode(b)) return; n++; if (!b.hl) tutti = false; });
    if (!n) return doc;
    fcdEachBlockInSel(doc, sel, function (b) {
        if (!fcdIsCode(b)) return;
        if (tutti) delete b.hl; else b.hl = 1;
    });
    return fcdNorm(doc);
}

/* La checklist e' un punto elenco con un "fatto / da fare" (ck 0 o 1): resta
   un punto, quindi rientri, Invio e cancella funzionano come per gli elenchi. */
function fcdToggleChecklist(doc, sel) {
    var tutti = true, n = 0;
    fcdEachBlockInSel(doc, sel, function (b) {
        if (b.t === 'fx' || fcdIsCode(b)) return;
        n++; if (!(b.t === 'li' && (b.ck === 0 || b.ck === 1))) tutti = false;
    });
    if (!n) return doc;
    fcdEachBlockInSel(doc, sel, function (b) {
        if (b.t === 'fx' || fcdIsCode(b)) return;
        if (tutti) { b.t = 'p'; delete b.lvl; delete b.c; delete b.ck; return; }
        if (b.t !== 'li') { b.t = 'li'; b.lvl = 1; delete b.q; }
        if (b.ck !== 1) b.ck = 0;
    });
    return fcdNorm(doc);
}

/* Spunta / toglie la spunta a un punto della checklist (tocco sul cerchio). */
function fcdFlipCheck(doc, bi) {
    var b = doc.blocks[bi];
    if (!b || b.t !== 'li' || !(b.ck === 0 || b.ck === 1)) return doc;
    b.ck = b.ck ? 0 : 1;
    return fcdNorm(doc);
}

/* Citazione: un paragrafo con la barra blu a sinistra. Come gli altri
   interruttori: se sono tutte citazioni tornano testo. */
function fcdToggleQuote(doc, sel) {
    var tutti = true, n = 0;
    fcdEachBlockInSel(doc, sel, function (b) {
        if (b.t === 'fx' || fcdIsCode(b)) return;
        n++; if (!(b.t === 'p' && b.q)) tutti = false;
    });
    if (!n) return doc;
    fcdEachBlockInSel(doc, sel, function (b) {
        if (b.t === 'fx' || fcdIsCode(b)) return;
        if (tutti) { delete b.q; return; }
        b.t = 'p'; delete b.lvl; delete b.c; delete b.ck; b.q = 1;
    });
    return fcdNorm(doc);
}

/* Toglie ogni formattazione del testo (grassetto, corsivo, sottolineato,
   evidenziatore, colore) dalla selezione; senza selezione, dalla riga intera.
   Il tipo di blocco (elenco, citazione, codice) non si tocca. */
function fcdClearMarks(doc, sel) {
    var s = fcdOrder(sel);
    if (s.a.b === s.f.b && s.a.o === s.f.o) {
        var b = doc.blocks[s.a.b];
        s = { a: { b: s.a.b, o: 0 }, f: { b: s.a.b, o: b ? fcdBlockLen(b) : 0 } };
    }
    FCD_FLAGS.concat(['c']).forEach(function (k) { doc = fcdSetMark(doc, s, k, null); });
    return fcdNorm(doc);
}

/* Il testo incollato "sembra Markdown"? Bastano un titolo (#), un punto di
   elenco, un **grassetto** o un blocco ```: cosi' il testo normale con un
   asterisco o un trattino in mezzo non viene toccato. */
/* Il testo incollato e' CODICE (Python, C, JS...) e non Markdown? In codice una
   riga «# commento» non e' un titolo: leggerla come tale la rendeva grassetto e
   ne toglieva il «#», e dopo, col tasto del codice, non era piu' un commento.
   Si guardano le righe che NON sono commenti: se almeno due e piu' della meta'
   hanno la forma del codice (def/if/for... con i due punti, import, assegnazioni,
   chiamate di funzione, righe che finiscono con ; { }), e' codice. Le righe
   «#» non contano ne' a favore ne' contro: possono essere sia commenti sia titoli. */
function fcdSembraCodice(t) {
    if (/^```/m.test(t)) return false;             // con i recinti e' Markdown, ci pensa fcdFromFences
    var righe = t.split('\n').filter(function (l) { return l.trim() !== '' && !/^\s*#/.test(l); });
    if (righe.length < 1) return false;            // solo righe «#»: puo' essere un titolo, si lascia Markdown
    var codice = 0, forti = 0;
    righe.forEach(function (l) {
        var forte = /^\s*(def|class|if|elif|else|for|while|try|except|finally|with|async\s+def)\b.*:\s*(#.*)?$/.test(l)
            || /^\s*(import\s+\S+|from\s+\S+\s+import\s+\S+)/.test(l)
            || /^\s*(return|print|raise|yield|assert|del|pass|break|continue)\b/.test(l)
            || /^\s*[A-Za-z_][\w.\[\]'"]*\s*([-+*\/%|&^]|\/\/|\*\*)?=\s*\S/.test(l)       // x = ..., x += ...
            || /^\s*[A-Za-z_][\w.]*\(.*\)\s*(#.*)?$/.test(l)                                 // chiamata
            || /^\s*(#include|using\s+namespace|public\s|private\s|int\s|void\s|const\s|let\s|var\s|function\s)/.test(l);
        if (forte) { codice++; forti++; }
        else if (/[;{}]\s*$/.test(l)) codice++;   // segno debole: conta solo insieme ad altre righe
    });
    /* Una sola riga di codice basta se e' chiaramente codice e tutto il resto
       sono commenti (il caso «# ... / # ... / print(5 | 3)  # ...»): prima ne
       servivano due, e quei pezzi diventavano titoli in grassetto. */
    if (righe.length === 1) return forti === 1;
    return codice >= 2 && codice / righe.length > 0.5;
}

function fcdHaMarkdown(t) {
    if (fcdSembraCodice(t)) return false;
    return /^```/m.test(t) || /^\s{0,3}#{1,6}\s+\S/m.test(t) || /^\s*(?:[-*+]|\d+[.)])\s+\S/m.test(t) || /\*\*[^*\s][^*\n]*[^*\s]\*\*/.test(t);
}

/* Il testo di una riga Markdown, con **grassetto**, *corsivo* e `codice` in
   linea e le formule $...$, diventa i pezzi (run) del modello. I marchi sono
   quelli di sempre (b, i): nessun carattere speciale resta nel testo. */
function fcdMdRuns(t, base) {
    var runs = [];
    function conMarchio(b, k) { var o = {}; for (var x in (b || {})) o[x] = b[x]; o[k] = 1; return o; }
    function formule(txt, m) {
        txt.split(/(\$[^$\n]+\$)/).forEach(function (pz) {
            if (!pz) return;
            var f = /^\$([^$\n]+)\$$/.exec(pz);
            if (f) { var r = { x: f[1] }; if (m) r.m = fcdCloneMarks(m); runs.push(r); }
            else { var r2 = { s: pz }; if (m) r2.m = fcdCloneMarks(m); runs.push(r2); }
        });
    }
    var re = /\*\*(?=\S)([^\n]+?)(?<=\S)\*\*|(?<![\w*])\*(?=[^\s*])([^*\n]+?)(?<=[^\s*])\*(?![\w*])|`([^`\n]+)`/g, last = 0, m;
    while ((m = re.exec(t))) {
        if (m.index > last) formule(t.slice(last, m.index), base);
        if (m[1] != null) { formule(m[1], conMarchio(base, 'b')); }
        else if (m[2] != null) { formule(m[2], conMarchio(base, 'i')); }
        else formule(m[3], base);            // `codice` in linea: resta testo, senza i apici
        last = re.lastIndex;
    }
    if (last < t.length) formule(t.slice(last), base);
    return runs;
}

/* Una riga Markdown -> un blocco: «- voce» e «1. voce» diventano punti (con il
   livello dato dal rientro), «# Titolo» una riga in grassetto, «> citazione»
   perde il segno. */
function fcdMdLine(l) {
    var m = /^(\s*)(?:[-*+]|\d+[.)])\s+(.*)$/.exec(l);
    if (m) {
        var rientro = m[1].replace(/\t/g, '    ').length;
        return { t: 'li', lvl: Math.min(6, 1 + Math.ceil(rientro / 4)), runs: fcdMdRuns(m[2]) };
    }
    m = /^\s{0,3}#{1,6}\s+(.*?)\s*#*\s*$/.exec(l);
    if (m) return { t: 'p', runs: fcdMdRuns(m[1], { b: 1 }) };
    m = /^\s{0,3}>\s?(.*)$/.exec(l);
    if (m) l = m[1];
    if (/^\s{0,3}([-*_])(\s*\1){2,}\s*$/.test(l)) return { t: 'p', runs: [] };   // la riga ---
    return { t: 'p', runs: l ? fcdMdRuns(l) : [] };
}

/* Il testo con i blocchi ```lingua ... ``` (come lo scrivono le chat e Markdown)
   diventa blocchi di codice. Il resto e' Markdown letto riga per riga. */
function fcdFromFences(text) {
    var out = [], re = /```([\w+#-]*)[ \t]*\n([\s\S]*?)(?:\n```|$)/g, last = 0, m;
    var MAP = { py: 'python', python: 'python', 'c++': 'cpp', cpp: 'cpp', c: 'c', java: 'java', javascript: 'js', js: 'js', typescript: 'ts', ts: 'ts', sql: 'sql', html: 'html', css: 'css', bash: 'bash', sh: 'bash', shell: 'bash', json: 'json', 'c#': 'csharp', csharp: 'csharp', php: 'php' };
    function righe(t, code) {
        t.split('\n').forEach(function (l) {
            if (!code) { out.push(fcdMdLine(l)); return; }
            var b = { t: 'p', runs: l ? [{ s: l }] : [] };
            b.code = code;
            out.push(b);
        });
    }
    while ((m = re.exec(text))) {
        var prima = text.slice(last, m.index).replace(/\n$/, '');
        if (prima || m.index > last) righe(prima, null);
        righe(m[2].replace(/\n$/, ''), MAP[(m[1] || '').toLowerCase()] || 'auto');
        last = re.lastIndex;
    }
    var dopo = text.slice(last).replace(/^\n/, '');
    if (dopo) righe(dopo, null);
    return out;
}

/* Un solo tasto per l'elenco: se i blocchi toccati sono gia' tutti punti,
   tornano paragrafi; altrimenti diventano punti. Come per il grassetto, non
   esiste un modo di "applicarlo due volte". */
function fcdToggleList(doc, sel) {
    var tutti = true, n = 0;
    /* Per un paragrafo "e' gia' un elenco" vuol dire t==='li'; per una
       formula a blocco vuol dire avere gia' un .lvl — non puo' cambiare
       tipo, il pallino le si affianca e basta (vedi .fc-fx-li, gia'
       pronto da quando le formule possono stare dentro una lista). */
    fcdEachBlockInSel(doc, sel, function (b) {
        n++;
        if (b.t === 'fx' ? !b.lvl : b.t !== 'li') tutti = false;
    });
    if (!n) return doc;
    fcdEachBlockInSel(doc, sel, function (b) {
        if (b.t === 'fx') {
            if (tutti) { delete b.lvl; delete b.c; }
            else if (!b.lvl) b.lvl = 1;
            return;
        }
        if (tutti) { b.t = 'p'; delete b.lvl; delete b.c; delete b.ck; }
        else if (b.t !== 'li') { b.t = 'li'; b.lvl = 1; delete b.q; }
    });
    return fcdNorm(doc);
}

/* Rientro. Un punto non puo' saltare piu' di un livello rispetto a quello
   sopra: un elenco dove il primo punto e' gia' al terzo livello non vuol dire
   niente, e i pallini di Word farebbero la stessa cosa. */
function fcdIndent(doc, sel, verso) {
    fcdEachBlockInSel(doc, sel, function (b, i) {
        if (b.t !== 'li' && !(b.t === 'fx' && b.lvl)) return;
        var lvl = (b.lvl | 0) || 1;
        if (verso > 0) {
            var prev = null;
            for (var k = i - 1; k >= 0; k--) { if (doc.blocks[k].t === 'li' || (doc.blocks[k].t === 'fx' && doc.blocks[k].lvl)) { prev = doc.blocks[k]; break; } if (doc.blocks[k].t !== 'fx') break; }
            var max = prev ? ((prev.lvl | 0) || 1) + 1 : 1;
            b.lvl = Math.min(lvl + 1, max, 12);
        } else {
            b.lvl = Math.max(1, lvl - 1);
        }
    });
    return fcdNorm(doc);
}

function fcdSetListColor(doc, sel, color) {
    fcdEachBlockInSel(doc, sel, function (b) {
        if (b.t !== 'li' && !(b.t === 'fx' && b.lvl)) return;
        if (color && FCD_LIST_COLORS.indexOf(color) !== -1) b.c = color; else delete b.c;
    });
    return fcdNorm(doc);
}

/* Formula a blocco. Le tre regole servono a non produrre mai righe vuote di
   troppo, che era il difetto piu' fastidioso:
     - sei gia' su una formula a blocco  -> la spegne e torna paragrafo;
     - sei su un blocco vuoto            -> lo TRASFORMA (niente riga in piu');
     - sei su un blocco con del testo    -> ne apre una subito sotto. */
function fcdToggleBlockMath(doc, sel) {
    var s = fcdOrder(sel);
    var i = s.a.b;
    var b = doc.blocks[i];
    if (!b) return { doc: fcdNorm(doc), caret: { b: 0, o: 0 } };

    if (b.t === 'fx') {
        doc.blocks[i] = { t: 'p', runs: b.tex ? [{ s: b.tex }] : [] };
        return { doc: fcdNorm(doc), caret: { b: i, o: (b.tex || '').length } };
    }

    /* Il cursore tocca una formula IN LINEA (senza una vera selezione di
       testo)? Il tasto "a blocco" non ne apre una nuova sotto: trasforma
       QUELLA, portandola sulla sua riga — il gemello di quello che fa
       fcdToggleInlineMath quando trova il cursore dentro una formula a
       blocco, qui sopra. Stessa identica meccanica dello spezzare in tre
       usata per il testo selezionato, qui sotto: prima, la formula, dopo. */
    if (s.a.b === s.f.b && s.a.o === s.f.o) {
        var accF = 0, runsB = b.runs || [];
        for (var kf = 0; kf < runsB.length; kf++) {
            var lenF = fcdRunText(runsB[kf]).length;
            if (typeof runsB[kf].x === 'string' && s.a.o >= accF && s.a.o <= accF + lenF) {
                var texF = runsB[kf].x;
                var idx0f = fcdSplitAt(b, accF), idx1f = fcdSplitAt(b, accF + lenF);
                var dopoRunsF = b.runs.splice(idx1f);
                var mConversione = fcdCloneMarks(runsB[kf].m);
                b.runs.splice(idx0f);
                var nuovoF = { t: 'fx', tex: texF };
                if (mConversione) nuovoF.m = mConversione;
                if (b.t === 'li') { nuovoF.lvl = b.lvl; if (b.c) nuovoF.c = b.c; }

                var blocchiF = [];
                if (fcdBlockLen(b) > 0) blocchiF.push(b);
                blocchiF.push(nuovoF);
                var dopoBloccoF = { t: b.t === 'li' ? 'li' : 'p', runs: dopoRunsF };
                if (b.t === 'li') { dopoBloccoF.lvl = b.lvl; if (b.c) dopoBloccoF.c = b.c; }
                if (fcdBlockLen(dopoBloccoF) > 0) blocchiF.push(dopoBloccoF);

                var indiceNuovoF = blocchiF.indexOf(nuovoF);
                doc.blocks.splice.apply(doc.blocks, [i, 1].concat(blocchiF));
                return { doc: fcdNorm(doc), caret: { b: i + indiceNuovoF, o: texF.length } };
            }
            accF += lenF;
        }
    }

    /* Con del testo selezionato la formula si fa CON QUEL TESTO, come gia'
       faceva la formula in linea. Prima ne apriva una vuota sotto e il testo
       scelto restava dov'era: due comandi gemelli che si comportavano in modo
       diverso, cioe' il tipo di incoerenza che costringe a provare per
       ricordare quale fa cosa. */
    if (!(s.a.b === s.f.b && s.a.o === s.f.o) && s.a.b === s.f.b) {
        var testo = fcdBlockText(b).slice(s.a.o, s.f.o);
        if (testo) {
            var i0 = fcdSplitAt(b, s.a.o), i1 = fcdSplitAt(b, s.f.o);
            /* "A B C", selezionato "B": va spezzato in TRE — quello che viene
               prima, la formula, quello che viene dopo — mantenendo "A" e "C"
               al loro posto. Prima si toglieva la selezione e basta: "A" e
               "C" restavano nello STESSO blocco e si ricongiungevano da soli
               in "A C", e la formula finiva in fondo invece che in mezzo. */
            var dopoRuns = b.runs.splice(i1);      // tutto cio' che viene DOPO la selezione
            b.runs.splice(i0);                      // b.runs resta solo "prima" della selezione
            var nuovo = { t: 'fx', tex: testo };
            /* Un punto elenco resta un punto elenco: la formula va sotto il
               suo pallino, non fuori dall'elenco — e lo stesso vale per il
               pezzo che segue, che continua la stessa lista. */
            if (b.t === 'li') { nuovo.lvl = b.lvl; if (b.c) nuovo.c = b.c; }

            var blocchi = [];
            if (fcdBlockLen(b) > 0) blocchi.push(b);
            blocchi.push(nuovo);
            var dopoBlocco = { t: b.t === 'li' ? 'li' : 'p', runs: dopoRuns };
            if (b.t === 'li') { dopoBlocco.lvl = b.lvl; if (b.c) dopoBlocco.c = b.c; }
            if (fcdBlockLen(dopoBlocco) > 0) blocchi.push(dopoBlocco);

            var indiceNuovo = blocchi.indexOf(nuovo);
            doc.blocks.splice.apply(doc.blocks, [i, 1].concat(blocchi));
            return { doc: fcdNorm(doc), caret: { b: i + indiceNuovo, o: testo.length } };
        }
    }

    if (fcdBlockText(b).trim() === '') {
        /* fcdBlockLen(b) === 0 e basta era troppo rigido: una riga che
           sembra vuota ma ha uno spazio residuo (un tasto premuto per sbaglio
           e mai cancellato del tutto, molto comune dopo qualche modifica)
           risultava "non vuota" per un soffio, e il tasto apriva una riga
           NUOVA sotto invece di usare quella su cui si era gia' messi —
           esattamente lo spazio di troppo segnalato. Uno spazio solitario
           non e' un contenuto da preservare: e' rumore, e la riga conta come
           vuota anche con lui dentro. */
        var vuota = { t: 'fx', tex: '' };
        if (b.t === 'li') { vuota.lvl = b.lvl; if (b.c) vuota.c = b.c; }
        doc.blocks[i] = vuota;
        return { doc: fcdNorm(doc), caret: { b: i, o: 0 } };
    }
    var giu = { t: 'fx', tex: '' };
    if (b.t === 'li') { giu.lvl = b.lvl; if (b.c) giu.c = b.c; }
    doc.blocks.splice(i + 1, 0, giu);
    return { doc: fcdNorm(doc), caret: { b: i + 1, o: 0 } };
}

/* Formula in linea. Le stesse tre regole della formula a blocco, cosi' non
   c'e' niente di nuovo da imparare:
     - il cursore e' dentro una formula -> la scioglie in testo normale
       (e' il "riclicco e si disattiva");
     - c'e' del testo selezionato       -> quel testo diventa la formula;
     - il cursore e' fermo altrove      -> apre una formula vuota da riempire.
   Nell'editor la formula si scrive come testo normale e il cursore ne esce
   liberamente: i dollari sono disegnati dal CSS, non sono caratteri. */
function fcdToggleInlineMath(doc, sel) {
    var s = fcdOrder(sel);
    var bi = s.a.b;
    var block = doc.blocks[bi];
    if (!block) return { doc: fcdNorm(doc), caret: s.a };

    if (block.t === 'fx') {
        /* Dentro una formula A BLOCCO il tasto "in linea" non la scioglie: la
           trasforma. Stesso LaTeX, ma dentro una riga di testo invece che
           sulla sua — il gemello di quello che fa fcdToggleBlockMath quando
           trova il cursore dentro una formula in linea, qui sotto. Se era in
           una lista, la riga che ne prende il posto resta un punto della
           stessa lista. */
        var nuovoBlocco = { t: block.lvl ? 'li' : 'p', runs: [{ x: block.tex || '' }] };
        if (block.lvl) { nuovoBlocco.lvl = block.lvl; if (block.c) nuovoBlocco.c = block.c; }
        doc.blocks[bi] = nuovoBlocco;
        return { doc: fcdNorm(doc), caret: { b: bi, o: (block.tex || '').length } };
    }

    var runs = block.runs || (block.runs = []);

    // dentro una formula?
    var acc = 0;
    for (var i = 0; i < runs.length; i++) {
        var len = fcdRunText(runs[i]).length;
        if (typeof runs[i].x === 'string' && s.a.o >= acc && s.a.o <= acc + len && s.f.b === bi && s.f.o <= acc + len) {
            var tex = runs[i].x;
            if (tex === '') { runs.splice(i, 1); return { doc: fcdNorm(doc), caret: { b: bi, o: acc } }; }
            runs.splice(i, 1, { s: tex });
            return { doc: fcdNorm(doc), caret: { b: bi, o: acc + tex.length } };
        }
        acc += len;
    }

    // del testo selezionato diventa formula
    if (!(s.a.b === s.f.b && s.a.o === s.f.o)) {
        var from = s.a.o, to = (s.f.b === bi) ? s.f.o : fcdBlockLen(block);
        var testo = fcdBlockText(block).slice(from, to);
        var i0 = fcdSplitAt(block, from), i1 = fcdSplitAt(block, to);
        block.runs.splice(i0, i1 - i0, { x: testo });
        return { doc: fcdNorm(doc), caret: { b: bi, o: from + testo.length } };
    }

    // formula vuota, cursore dentro
    var idx = fcdSplitAt(block, s.a.o);
    block.runs.splice(idx, 0, { x: '' });
    /* "dentro" non si puo' dire con un numero: una formula vuota e' lunga
       zero, quindi la sua posizione coincide con quella del testo che la
       precede, e il browser ci mette il cursore fuori. Lo dice questo
       segnale, e chi ridisegna lo mette dentro l'elemento giusto. */
    return { doc: fcdNorm(doc), caret: { b: bi, o: s.a.o }, dentro: true };
}

/* A capo DENTRO lo stesso blocco: la riga continua sotto lo stesso pallino,
   senza aprirne uno nuovo. E' l'unica cosa che l'invio non sa fare — se
   facesse la stessa cosa, questo tasto non avrebbe motivo di esistere. */
function fcdInsertSoftBreak(doc, sel) {
    var s = fcdOrder(sel);
    var bi = s.a.b;
    var block = doc.blocks[bi];
    if (!block || block.t === 'fx') return { doc: fcdNorm(doc), caret: s.a };

    if (!(s.a.b === s.f.b && s.a.o === s.f.o)) doc = fcdDeleteRange(doc, s).doc;
    block = doc.blocks[bi];

    var idx = fcdSplitAt(block, s.a.o);
    block.runs.splice(idx, 0, { s: '\n' });
    return { doc: fcdNorm(doc), caret: { b: bi, o: s.a.o + 1 } };
}

/* Invio. Spezza il blocco al cursore. Su un punto elenco vuoto non ne apre un
   altro vuoto: esce dall'elenco, come fanno Word e tutti gli altri — premere
   invio due volte per chiudere una lista e' un gesto che la gente ha gia'
   nelle dita. */
function fcdSplitBlock(doc, sel) {
    var s = fcdOrder(sel);
    var bi = s.a.b;
    var block = doc.blocks[bi];
    if (!block) return { doc: fcdNorm(doc), caret: { b: 0, o: 0 } };

    if (block.t === 'fx') {
        if (s.a.o === 0) {
            /* Il cursore e' prima della prima lettera: qui non si esce dalla
               formula, si sposta lei di una riga in giu' e ci si resta
               dentro, nello stesso punto. E' lo stesso identico invio che
               premuto altrove apre una riga nuova SOPRA a dove si e' — solo
               che "dove si e'" qui e' l'inizio della formula, non il suo
               interno: e infatti la riga nuova nasce sopra ANCHE a lei. */
            doc.blocks.splice(bi, 0, { t: 'p', runs: [] });
            return { doc: fcdNorm(doc), caret: { b: bi + 1, o: 0 } };
        }
        doc.blocks.splice(bi + 1, 0, { t: 'p', runs: [] });
        return { doc: fcdNorm(doc), caret: { b: bi + 1, o: 0 } };
    }

    if (block.t === 'li' && fcdBlockLen(block) === 0) {
        if ((block.lvl | 0) > 1) { block.lvl = block.lvl - 1; return { doc: fcdNorm(doc), caret: { b: bi, o: 0 } }; }
        doc.blocks[bi] = { t: 'p', runs: [] };
        /* La lista finisce qui: se subito dopo c'era una formula con un suo
           pallino, allo stesso livello, anche lei smette di farne parte —
           altrimenti "finire la lista" lascerebbe comunque un pallino
           isolato subito sotto una riga appena tornata normale. */
        var dopo = doc.blocks[bi + 1];
        if (dopo && dopo.t === 'fx' && dopo.lvl) { delete dopo.lvl; delete dopo.c; }
        return { doc: fcdNorm(doc), caret: { b: bi, o: 0 } };
    }

    /* Stessa cosa per una citazione vuota: Invio la chiude. */
    if (block.q && !block.code && block.t === 'p' && fcdBlockLen(block) === 0) {
        delete block.q;
        return { doc: fcdNorm(doc), caret: { b: bi, o: 0 } };
    }

    /* Invio su una riga di codice vuota: si esce dal blocco, la riga torna testo
       (come uscire da un elenco). */
    if (fcdIsCode(block) && fcdBlockLen(block) === 0) {
        delete block.code;
        return { doc: fcdNorm(doc), caret: { b: bi, o: 0 } };
    }

    // cancella prima l'eventuale testo selezionato, poi spezza
    if (!(s.a.b === s.f.b && s.a.o === s.f.o)) doc = fcdDeleteRange(doc, s).doc;
    block = doc.blocks[bi];

    var idx = fcdSplitAt(block, s.a.o);
    var coda = block.runs.splice(idx);
    var nuovo = { t: block.t, runs: coda };
    if (block.code) nuovo.code = block.code;
    if (block.t === 'li') { nuovo.lvl = block.lvl; if (block.c) nuovo.c = block.c; if (block.ck === 0 || block.ck === 1) nuovo.ck = 0; }
    if (block.q && block.t === 'p') nuovo.q = 1;
    doc.blocks.splice(bi + 1, 0, nuovo);
    return { doc: fcdNorm(doc), caret: { b: bi + 1, o: 0 } };
}

/* Estrae la porzione di documento coperta dalla selezione COSI' COM'E' —
   marchi (grassetto, colore...), livello e colore di lista, formule comprese
   — senza toccare il documento di partenza: e' una copia, pensata per finire
   da un'altra parte della stessa pagina o su un'altra pagina del sito
   attraverso gli appunti. E' il gemello di fcdDeleteRange, che la stessa
   porzione la toglie invece di restituirla. */
function fcdEstraiSelezione(doc, sel) {
    var s = fcdOrder(sel);
    var blocchi = [];

    for (var bi = s.a.b; bi <= s.f.b; bi++) {
        var b = doc.blocks[bi];
        if (!b) continue;

        if (b.t === 'fx') {
            /* Una formula a blocco si copia per intera se la selezione la
               tocca anche solo in un punto: non esiste "mezza formula". */
            var nuovaF = { t: 'fx', tex: b.tex || '' };
            if (b.lvl) { nuovaF.lvl = b.lvl; if (b.c) nuovaF.c = b.c; }
            var mCopia = fcdCloneMarks(b.m);
            if (mCopia) nuovaF.m = mCopia;
            blocchi.push(nuovaF);
            continue;
        }

        var da = (bi === s.a.b) ? s.a.o : 0;
        var a = (bi === s.f.b) ? s.f.o : fcdBlockLen(b);
        var runsNuovi = [], acc = 0;
        (b.runs || []).forEach(function (r) {
            var len = fcdRunText(r).length;
            var inizio = Math.max(da, acc), fine = Math.min(a, acc + len);
            if (fine > inizio) {
                var pezzo = fcdRunText(r).slice(inizio - acc, fine - acc);
                var nuovo = (typeof r.x === 'string') ? { x: pezzo } : { s: pezzo };
                var m = fcdCloneMarks(r.m);
                if (m) nuovo.m = m;
                runsNuovi.push(nuovo);
            }
            acc += len;
        });

        /* Il pallino e' un pezzo della riga, non del testo: si copia solo se la
           selezione parte dall'inizio della riga (cioe' include anche il
           pallino). Partendo a meta' riga si copia solo testo, che incollato
           altrove non deve portarsi dietro un elenco. */
        var conPallino = b.t === 'li' && !(bi === s.a.b && da > 0);
        var nuovoBlocco = { t: conPallino ? 'li' : 'p', runs: runsNuovi };
        if (conPallino) { nuovoBlocco.lvl = b.lvl; if (b.c) nuovoBlocco.c = b.c; }
        if (b.code) nuovoBlocco.code = b.code;      // una riga di codice copiata resta codice
        blocchi.push(nuovoBlocco);
    }

    if (!blocchi.length) blocchi.push({ t: 'p', runs: [] });
    return fcdNorm({ v: 2, blocks: blocchi });
}

/* Incolla, in una posizione FERMA del cursore (non una selezione: chi chiama
   toglie prima l'eventuale testo selezionato, con fcdDeleteRange), i blocchi
   presi da fcdEstraiSelezione — marchi, liste, formule compresi. E' l'incollare
   VERO, distinto dall'incollare solo testo semplice che il sito faceva finora. */
function fcdIncollaSottodocumento(doc, pos, sotto) {
    var blocchiIncollati = (sotto.blocks || []).map(function (bl) {
        if (bl.t === 'fx') {
            var f = { t: 'fx', tex: bl.tex || '' };
            if (bl.lvl) { f.lvl = bl.lvl; if (bl.c) f.c = bl.c; }
            var mIncolla = fcdCloneMarks(bl.m);
            if (mIncolla) f.m = mIncolla;
            return f;
        }
        var runs = (bl.runs || []).map(function (r) {
            var rc = (typeof r.x === 'string') ? { x: r.x } : { s: r.s || '' };
            var m = fcdCloneMarks(r.m);
            if (m) rc.m = m;
            return rc;
        });
        var nuovo = { t: bl.t === 'li' ? 'li' : 'p', runs: runs };
        if (bl.t === 'li') { nuovo.lvl = bl.lvl; if (bl.c) nuovo.c = bl.c; }
        if (bl.code) nuovo.code = bl.code;
        return nuovo;
    });
    if (!blocchiIncollati.length) return { doc: fcdNorm(doc), caret: pos };

    var bi = pos.b, o = pos.o;
    var b = doc.blocks[bi];

    if (!b || b.t === 'fx') {
        /* Non si incolla DENTRO una formula a blocco: si mette subito dopo,
           come farebbe chi scrive a mano dopo averla chiusa. */
        doc.blocks.splice.apply(doc.blocks, [bi + 1, 0].concat(blocchiIncollati));
        var ultimoF = blocchiIncollati[blocchiIncollati.length - 1];
        return {
            doc: fcdNorm(doc),
            caret: { b: bi + blocchiIncollati.length, o: ultimoF.t === 'fx' ? ultimoF.tex.length : fcdBlockLen(ultimoF) }
        };
    }

    if (blocchiIncollati.length === 1 && blocchiIncollati[0].t === 'p') {
        /* Un paragrafo solo, testo normale: si infila dentro la riga
           attuale, non se ne apre una nuova — incollare "rosso" in mezzo a
           "colore _ acceso" deve dare "colore rosso acceso" su una riga
           sola, non spezzarla in tre. Un punto elenco copiato invece
           resta un blocco a se', anche se e' uno solo: merita la sua riga
           e il suo pallino, non finire in mezzo a un'altra frase. */
        var idx = fcdSplitAt(b, o);
        b.runs.splice.apply(b.runs, [idx, 0].concat(blocchiIncollati[0].runs));
        return { doc: fcdNorm(doc), caret: { b: bi, o: o + fcdBlockLen(blocchiIncollati[0]) } };
    }

    /* Piu' blocchi (o un blocco solo che e' un punto elenco o una formula):
       la riga attuale si spezza in "prima" e "dopo", e in mezzo vanno i
       blocchi incollati — la stessa idea, sempre, con cui fcdToggleBlockMath
       spezza una riga attorno a una formula estratta dalla selezione. */
    var idx0 = fcdSplitAt(b, o);
    var dopoRuns = b.runs.splice(idx0);
    var dopoBlocco = { t: b.t === 'li' ? 'li' : 'p', runs: dopoRuns };
    if (b.t === 'li') { dopoBlocco.lvl = b.lvl; if (b.c) dopoBlocco.c = b.c; }

    var pezzi = [];
    if (fcdBlockLen(b) > 0 || (b.runs && b.runs.length)) pezzi.push(b);
    pezzi = pezzi.concat(blocchiIncollati);
    if (fcdBlockLen(dopoBlocco) > 0) pezzi.push(dopoBlocco);
    if (!pezzi.length) pezzi.push({ t: 'p', runs: [] });

    doc.blocks.splice.apply(doc.blocks, [bi, 1].concat(pezzi));

    var ultimoIncollato = blocchiIncollati[blocchiIncollati.length - 1];
    var idxUltimo = bi + pezzi.indexOf(ultimoIncollato);
    var fineUltimo = ultimoIncollato.t === 'fx' ? ultimoIncollato.tex.length : fcdBlockLen(ultimoIncollato);
    return { doc: fcdNorm(doc), caret: { b: idxUltimo, o: fineUltimo } };
}

function fcdDeleteRange(doc, sel) {
    var s = fcdOrder(sel);
    if (s.a.b === s.f.b) {
        var b = doc.blocks[s.a.b];
        if (!b || b.t === 'fx') return { doc: fcdNorm(doc), caret: s.a };
        var i0 = fcdSplitAt(b, s.a.o), i1 = fcdSplitAt(b, s.f.o);
        b.runs.splice(i0, i1 - i0);
        return { doc: fcdNorm(doc), caret: s.a };
    }
    var primo = doc.blocks[s.a.b], ultimo = doc.blocks[s.f.b];
    if (primo && primo.t !== 'fx') primo.runs.splice(fcdSplitAt(primo, s.a.o));
    var coda = [];
    if (ultimo && ultimo.t !== 'fx') coda = ultimo.runs.slice(fcdSplitAt(ultimo, s.f.o));
    doc.blocks.splice(s.a.b + 1, s.f.b - s.a.b);
    if (primo && primo.t !== 'fx') primo.runs = primo.runs.concat(coda);
    return { doc: fcdNorm(doc), caret: s.a };
}

/* Cancella all'inizio di un punto: prima lo fa risalire di livello, poi lo fa
   uscire dall'elenco. Senza questa regola il tasto cancella unirebbe due
   punti in modi imprevedibili. */
function fcdBackspaceAtStart(doc, sel) {
    var bi = fcdOrder(sel).a.b;
    var b = doc.blocks[bi];
    if (!b) return null;
    if (b.t === 'li') {
        if ((b.lvl | 0) > 1) { b.lvl = b.lvl - 1; return { doc: fcdNorm(doc), caret: { b: bi, o: 0 } }; }
        doc.blocks[bi] = { t: 'p', runs: b.runs };
        return { doc: fcdNorm(doc), caret: { b: bi, o: 0 } };
    }
    if (b.t === 'fx') {
        doc.blocks[bi] = { t: 'p', runs: b.tex ? [{ s: b.tex }] : [] };
        return { doc: fcdNorm(doc), caret: { b: bi, o: 0 } };
    }
    return null;   // caso normale: se ne occupa il browser
}

// ================================================================
// 5) LETTURA E SCRITTURA (quello che finisce nel database)
// ================================================================

function fcdStringify(doc) { return JSON.stringify(fcdNorm(doc)); }

/* Toglie spazi e a capo superflui in testa e in coda al documento — non alla
   stringa JSON che lo contiene (li' non ce ne sono mai), ma al CONTENUTO: i
   paragrafi vuoti che restano quando si cancella tutto e si ricomincia a
   scrivere piu' sotto, e gli spazi rimasti a inizio o fine dell'ultimo
   paragrafo rimasto. Un a capo qui e' un blocco intero, non un carattere "\n"
   dentro il testo: per questo la pulizia lavora a due livelli — prima i
   blocchi (gli a capo), poi il testo del primo e dell'ultimo blocco rimasto
   (gli spazi). Non tocca l'INTERNO del testo: un a capo in mezzo al
   testo e' voluto, resta.

   Le formule VUOTE sono un'eccezione a questa regola, e si tolgono ovunque
   si trovino, non solo ai bordi: mentre si scrive una formula senza contenuto
   e' normale, e' un lavoro in corso, ma nel documento SALVATO non ha alcun
   senso — non ha ne' un LaTeX da mostrare ne' del testo, solo un segnaposto
   che nessuno saprebbe piu' come raggiungere per toglierlo (non essendoci
   niente da vedere, non essendoci niente su cui cliccare). Chi la lascia
   vuota uscendone col cursore la vede sparire subito (se ne occupa
   ftMathRefresh in format_toolbar.php); questo e' il secondo livello di
   sicurezza, per quelle che sfuggono a quel controllo — un'altra scheda,
   un salvataggio automatico partito nel mezzo.
   Usata al salvataggio, su domanda e risposta di ogni carta. */
function fcdTrim(doc) {
    var d = fcdNorm(doc);
    var blocks = d.blocks;

    function vuoto(b) {
        if (b.t === 'fx') return String(b.tex || '').trim() === '';
        return fcdBlockText(b).trim() === '';
    }

    // formule vuote, in QUALSIASI punto del documento: a blocco si toglie
    // tutto il blocco, in linea si toglie solo il run, lasciando il resto
    // del paragrafo (e l'eventuale pallino della lista) cosi' com'e'.
    for (var bi = blocks.length - 1; bi >= 0; bi--) {
        var bl = blocks[bi];
        if (bl.t === 'fx') {
            if (String(bl.tex || '').trim() === '') blocks.splice(bi, 1);
            continue;
        }
        if (bl.runs) {
            for (var ri = bl.runs.length - 1; ri >= 0; ri--) {
                if (typeof bl.runs[ri].x === 'string' && bl.runs[ri].x.trim() === '') bl.runs.splice(ri, 1);
            }
        }
    }
    if (blocks.length === 0) blocks.push({ t: 'p', runs: [] });

    // blocchi vuoti in testa e in coda: sono gli "a capo" di troppo. Se ne
    // tiene sempre almeno uno, altrimenti un documento tutto vuoto non
    // avrebbe piu' un blocco su cui scrivere.
    while (blocks.length > 1 && vuoto(blocks[0])) blocks.shift();
    while (blocks.length > 1 && vuoto(blocks[blocks.length - 1])) blocks.pop();

    // spazi bianchi: solo ai due bordi del documento, cioe' l'inizio del
    // primo run del primo blocco e la fine dell'ultimo run dell'ultimo
    // blocco. Un run che resta vuoto dopo il taglio si toglie, per non
    // lasciare un run fantasma senza testo ne' formula.
    var primo = blocks[0];
    if (primo && primo.t !== 'fx' && primo.runs && primo.runs.length) {
        var r0 = primo.runs[0];
        if (typeof r0.s === 'string') r0.s = r0.s.replace(/^[ \t\u00A0]+/, '');
        while (primo.runs.length > 1 && typeof primo.runs[0].s === 'string' && primo.runs[0].s === '') {
            primo.runs.shift();
        }
    }
    var ultimo = blocks[blocks.length - 1];
    if (ultimo && ultimo.t !== 'fx' && ultimo.runs && ultimo.runs.length) {
        var rn = ultimo.runs[ultimo.runs.length - 1];
        if (typeof rn.s === 'string') rn.s = rn.s.replace(/[ \t\u00A0]+$/, '');
        while (ultimo.runs.length > 1 && typeof ultimo.runs[ultimo.runs.length - 1].s === 'string'
               && ultimo.runs[ultimo.runs.length - 1].s === '') {
            ultimo.runs.pop();
        }
    }

    return d;
}

/* Come fcdTrim, ma prende e restituisce la stringa serializzata (quella che
   viaggia nei campi nascosti e nel salvataggio): fcdParse il testo cosi'
   com'e' arriva (JSON nuovo o vecchi tag, fcdParse riconosce da solo),
   fcdTrim il modello, fcdStringify il risultato. */
function fcdTrimText(raw) {
    if (!raw) return raw;
    try {
        return fcdStringify(fcdTrim(fcdParse(raw)));
    } catch (e) {
        return raw; // testo non riconoscibile: si lascia intatto piuttosto che romperlo
    }
}

/* Riconosce da sola cosa sta leggendo. Un documento nuovo e' JSON e comincia
   per "{"; tutto il resto e' formato vecchio, o testo semplice — che del
   formato vecchio e' un caso particolare. */
function fcdParse(text) {
    if (text == null) return fcdEmpty();
    if (typeof text === 'object') return fcdNorm(text);
    var s = String(text);
    var t = s.trim();
    if (t.charAt(0) === '{' && t.indexOf('"blocks"') !== -1) {
        try {
            var o = JSON.parse(t);
            if (o && Array.isArray(o.blocks)) return fcdNorm(o);
        } catch (e) { /* non era JSON valido: lo si legge come formato vecchio */ }
    }
    return fcdFromLegacy(s);
}

// ---------------- il formato vecchio, letto e convertito ----------------
/* Non e' una migrazione del database: e' un lettore. Le schede vecchie si
   aprono, e si riscrivono nel formato nuovo solo quando le si salva. Se
   qualcosa qui sbagliasse, il dato originale sarebbe ancora al suo posto. */
function fcdFromLegacy(str) {
    var LEG_SIMPLE = { bold: 'b', italic: 'i', underline: 'u', yellow: 'h' };
    var LEG_COLORS = ['red', 'blue', 'green', 'purple', 'orange', 'gray'];

    var blocks = [];
    var cur = { t: 'p', runs: [] };

    function pushBlock() { blocks.push(cur); cur = { t: 'p', runs: [] }; }
    function addText(s, m) { if (s) cur.runs.push(m ? { s: s, m: fcdCloneMarks(m) } : { s: s }); }

    /* Le etichette attive si portano dietro mentre si scende nell'albero
       vecchio: e' esattamente qui che l'annidamento diventa un insieme piatto
       di marchi, ed e' il motivo per cui /red{/bold{x}} e /bold{/red{x}}
       finiscono per essere la stessa identica cosa nel modello nuovo. */
    function walk(s, marks, inLista) {
        var i = 0, n = s.length, buf = '';
        function flush() { addText(buf, marks); buf = ''; }

        while (i < n) {
            var ch = s[i];

            if (ch === '$') {
                var disp = s[i + 1] === '$';
                var d = disp ? '$$' : '$';
                var j = i + d.length;
                var close = s.indexOf(d, j);
                if (close !== -1) {
                    var tex = s.substring(j, close);
                    flush();
                    /* Dentro un punto elenco una formula "a blocco" resta IN
                       LINEA. Nel formato vecchio si scriveva $$...$$ anche in
                       mezzo a un punto, e trattarla come blocco spezzava il
                       punto in tre pezzi: mezzo punto, la formula, l'altro
                       mezzo — l'elenco si sfasciava. Il testo si sarebbe
                       salvato lo stesso, ma la struttura no. */
                    if (disp && !inLista) {
                        /* Niente paragrafo vuoto davanti: una scheda che
                           cominciava con $$...$$ si sarebbe aperta con una
                           riga bianca che non c'era nell'originale. */
                        if (fcdBlockLen(cur)) pushBlock(); else cur = { t: 'p', runs: [] };
                        blocks.push({ t: 'fx', tex: tex });
                    }
                    else cur.runs.push({ x: tex });
                    i = close + d.length;
                    continue;
                }
            }

            if (ch === '/') {
                var mL = /^\/list(-[a-zA-Z]+)?\{/.exec(s.slice(i));
                if (mL) {
                    var col = mL[1] ? mL[1].slice(1).toLowerCase() : '';
                    if (LEG_COLORS.indexOf(col) === -1) col = '';
                    var c0 = i + mL[0].length, d1 = 1, k = c0;
                    while (k < n && d1 > 0) { if (s[k] === '{') d1++; else if (s[k] === '}') { d1--; if (!d1) break; } k++; }
                    flush();
                    if (fcdBlockLen(cur)) pushBlock();
                    legacyList(s.substring(c0, k), col, marks);
                    i = k + 1;
                    continue;
                }
                var mT = /^\/([a-zA-Z]+)\{/.exec(s.slice(i));
                if (mT) {
                    var name = mT[1].toLowerCase();
                    var flag = LEG_SIMPLE[name];
                    var isCol = LEG_COLORS.indexOf(name) !== -1;
                    if (flag || isCol) {
                        var c1 = i + mT[0].length, d2 = 1, k2 = c1;
                        while (k2 < n && d2 > 0) { if (s[k2] === '{') d2++; else if (s[k2] === '}') { d2--; if (!d2) break; } k2++; }
                        flush();
                        var sotto = fcdCloneMarks(marks) || {};
                        if (flag) sotto[flag] = 1; else sotto.c = name;
                        walk(s.substring(c1, k2), sotto, inLista);
                        i = k2 + 1;
                        continue;
                    }
                }
            }

            /* A capo dentro un punto elenco: resta dentro il punto, non ne
               apre uno nuovo. */
            if (ch === '\n') {
                flush();
                if (inLista) cur.runs.push({ s: '\n' }); else pushBlock();
                i++; continue;
            }

            buf += ch;
            i++;
        }
        flush();
    }

    function legacyList(inner, color, marks) {
        String(inner).replace(/^\n/, '').replace(/\n$/, '').split('\n').forEach(function (line) {
            var t = line.trim();
            if (t === '') return;
            var m = /^([-\u2022]+)\s*(.*)$/.exec(t);

            /* Una riga SENZA trattino non e' un punto nuovo: e' la
               continuazione di quello sopra, andata a capo. Cosi' la
               interpretava il formato vecchio, e chi ha scritto quelle schede
               contava su questo — trattarla come punto a se' avrebbe
               moltiplicato i pallini di ogni formula scritta su due righe. */
            if (!m) {
                var ultimo = blocks[blocks.length - 1];
                if (ultimo && ultimo.t === 'li') {
                    cur = ultimo;
                    cur.runs.push({ s: '\n' });
                    walk(t, marks, true);
                    cur = { t: 'p', runs: [] };
                    return;
                }
            }

            var lvl = 1, testo = t;
            if (m) { lvl = m[1].replace(/\u2022/g, '-').length; testo = m[2]; }
            cur = { t: 'li', lvl: lvl, runs: [] };
            if (color) cur.c = color;
            walk(testo, marks, true);
            blocks.push(cur);
            cur = { t: 'p', runs: [] };
        });
    }

    walk(String(str).replace(/\r\n/g, '\n').replace(/\r/g, '\n'), null, false);
    if (fcdBlockLen(cur) || !blocks.length) blocks.push(cur);
    return fcdNorm({ v: FCD_V, blocks: blocks });
}

// ================================================================
// 6) DAL MODELLO AL DOM (l'editor) E ALL'HTML (le pagine di lettura)
// ================================================================

function fcdEscape(s) {
    return String(s == null ? '' : s)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function fcdMarkAttrs(m) {
    var out = '';
    if (!m) return out;
    FCD_FLAGS.forEach(function (k) {
        if (!m[k]) return;
        /* l'evidenziatore porta il suo colore ("red", "blue"...); 1 = giallo */
        out += ' data-' + k + '="' + (k === 'h' && fcdColoreTestoValido(m.h) ? m.h : '1') + '"';
    });
    if (m.c) out += ' data-c="' + fcdEscape(m.c) + '"';
    return out;
}

function fcdRunHTML(r) {
    if (typeof r.x === 'string') {
        /* I due caratteri invisibili ai lati non sono un trucco sporco: senza,
           "dentro la formula" e "subito dopo la formula" sono la STESSA
           posizione del cursore, e il browser ne sceglie una a caso — di solito
           quella dentro, cosi' il testo scritto dopo finiva nella formula.
           Con loro il fuori esiste davvero e le frecce ci arrivano.
           Valgono zero caratteri nel conto delle posizioni e fcdNorm li toglie
           dal modello: nel salvato non compaiono mai. */
        /* Un marchio (colore, grassetto...) va DIRETTAMENTE sullo stesso span,
           non in un avvolgente separato. E' una scelta obbligata, non
           estetica: tutto l'ingresso e l'uscita dal cursore (le frecce, il
           backspace al bordo) cammina sui fratelli DOM di questo span — "il
           nodo di testo appena prima", "appena dopo". Un avvolgente in mezzo
           sposta la formula di un livello, e quei fratelli non sono piu' i
           fratelli veri nel blocco ma quelli dentro l'avvolgente (spesso
           nessuno): il cursore andava a finire nel posto sbagliato, ed era
           quello a sembrare "la formula si cancella invece di aprirsi".
           Aggiungendo qui la classe fc-t insieme a fc-x, le stesse regole CSS
           gia' scritte per il testo colorato (".fc-t[data-c=...]") si
           applicano identiche, senza bisogno di nuove regole. */
        var classi = 'fc-x' + (r.m ? ' fc-t' : '');
        var attrMarchi = r.m ? fcdMarkAttrs(r.m) : '';
        return '\u200B<span class="' + classi + '" data-x="1" data-tex="' + fcdEscape(r.x) + '"' + attrMarchi + '>' + fcdEscape(r.x) + '</span>\u200B';
    }
    /* Il carattere invisibile dopo ogni <br> impedisce al browser di
       considerarlo un riempitivo e di eliminarlo appena si scrive: senza,
       l'a capo in fondo alla riga spariva al primo carattere digitato. */
    var testo = fcdEscape(r.s).replace(/\n/g, '<br>\u200B');
    if (!r.m) return testo;
    return '<span class="fc-t"' + fcdMarkAttrs(r.m) + '>' + testo + '</span>';
}

function fcdBlockHTML(b) {
    if (b.t === 'fx') {
        var attrFx = '';
        if (b.lvl) {
            attrFx = ' data-lvl="' + (b.lvl | 0) + '"';
            if (b.c) attrFx += ' data-c="' + fcdEscape(b.c) + '"';
        }
        var classiFx = 'fc-fx' + (b.lvl ? ' fc-fx-li' : '');
        if (b.m) {
            classiFx += ' fc-t';
            /* fcdMarkAttrs scrive "data-c" per il colore — giusto per il
               testo normale, ma qui su un blocco che puo' gia' avere un suo
               "data-c" per il pallino (vedi sopra). Si rinomina in data-mc,
               senza toccare fcdMarkAttrs: la stessa funzione serve anche
               altrove, dove "data-c" va benissimo cosi' com'e'. */
            attrFx += fcdMarkAttrs(b.m).replace(/ data-c="/, ' data-mc="');
        }
        return '<div class="' + classiFx + '" data-fx="1"' + attrFx +
               ' data-tex="' + fcdEscape(b.tex) + '">' + fcdEscape(b.tex) + '</div>';
    }
    /* Il <br> che tiene aperta una riga vuota si DICHIARA con data-fill,
       invece di essere indovinato da "e' l'ultimo figlio". Indovinare non
       funzionava: un a capo voluto in fondo alla riga e' anche lui l'ultimo
       figlio, e veniva buttato via insieme al riempitivo. */
    var inner = (b.runs || []).map(fcdRunHTML).join('') || '<br data-fill="1">';
    if (b.t === 'li') {
        var attr = ' data-lvl="' + ((b.lvl | 0) || 1) + '"';
        if (b.c) attr += ' data-c="' + fcdEscape(b.c) + '"';
        if (b.ck === 0 || b.ck === 1) attr += ' data-ck="' + b.ck + '"';
        return '<div class="fc-li"' + attr + '>' + inner + '</div>';
    }
    /* Un paragrafo normale puo' avere un .lvl senza per questo diventare un
       punto elenco: e' la riga di continuo dentro una lista — stessa
       rientranza, nessun pallino proprio. Non e' un punto elenco a meta',
       e' un a capo che sta all'altezza giusta. */
    if (b.lvl) return '<div class="fc-p fc-p-lvl" data-lvl="' + (b.lvl | 0) + '">' + inner + '</div>';
    if (b.q) return '<div class="fc-p fc-q">' + inner + '</div>';
    return '<div class="fc-p">' + inner + '</div>';
}

/* Gli stessi marchi del testo (grassetto, evidenziatore...) dentro una riga di
   codice gia' colorata: si guarda carattere per carattere quale marchio vale e
   si avvolge ogni tratto. I colori delle parole restano sotto. Se i conti non
   tornano (a-capo dentro la riga) si lascia la riga com'e': meglio senza
   marchi che con l'HTML rotto. */
function fcdCodiceConMarchi(colorata, runs) {
    var per = [];
    var ha = false;
    (runs || []).forEach(function (r) {
        var t = fcdRunText(r);
        for (var i = 0; i < t.length; i++) per.push(r.m || null);
        if (r.m) ha = true;
    });
    if (!ha) return colorata;
    var pezzi = colorata.split(/(<[^>]+>)/), pos = 0, out = '', ok = true;
    var chiave = function (m) { return m ? fcdMarkAttrs(m) : ''; };
    pezzi.forEach(function (pz) {
        if (!pz) return;
        if (pz.charAt(0) === '<') { out += pz; return; }
        var chars = pz.match(/&(?:amp|lt|gt|quot|#39);|[\s\S]/g) || [];
        var i = 0;
        while (i < chars.length) {
            var k = chiave(per[pos]), j = i, seg = '';
            while (j < chars.length && chiave(per[pos + (j - i)]) === k) { seg += chars[j]; j++; }
            out += k ? '<span class="fc-t"' + k + '>' + seg + '</span>' : seg;
            pos += j - i; i = j;
        }
    });
    if (pos !== per.length) ok = false;
    return ok ? out : colorata;
}

function fcdToHTML(doc) {
    var blocks = fcdNorm(doc).blocks, out = [], i = 0;
    while (i < blocks.length) {
        if (!fcdIsCode(blocks[i])) { out.push(fcdBlockHTML(blocks[i])); i++; continue; }
        /* un gruppo di righe di codice: si colora tutto insieme (i commenti e le
           stringhe su piu' righe) e la lingua e' quella della prima riga */
        var z = i;
        while (z + 1 < blocks.length && fcdIsCode(blocks[z + 1])) z++;
        var testi = [];
        for (var k = i; k <= z; k++) testi.push(fcdBlockText(blocks[k]).replace(/\u200B/g, ''));
        var scelta = blocks[i].code;
        var lingua = scelta === 'auto' ? fcdDetectLang(testi.join('\n')) : scelta;
        var colorate = fcdHlGroup(testi, lingua);
        for (var r = 0; r <= z - i; r++) {
            var b = blocks[i + r];
            out.push('<div class="fc-p fc-code" data-code="' + fcdEscape(b.code) + '" data-lang="' + fcdEscape(FCD_CODE_LANGS[lingua] || 'Code') + '"' + (b.hl ? ' data-hl="1"' : '') + '>' +
                     (colorate[r] ? fcdCodiceConMarchi(colorate[r], b.runs) : '<br data-fill="1">') + '</div>');
        }
        i = z + 1;
    }
    return out.join('');
}

/* Punto d'ingresso storico, stesso nome di sempre: le pagine di sola lettura
   che gia' lo chiamano continuano a funzionare senza sapere che sotto e'
   cambiato tutto. Legge indifferentemente formato nuovo e vecchio. */
function parseTextForPreview(text) { return fcdToHTML(fcdParse(text)); }

// ---------------- dal DOM al modello ----------------
/* Rilegge quello che c'e' nell'editor. E' anche la difesa contro l'incolla:
   qualunque HTML arrivi da fuori viene ridotto a quello che il modello sa
   rappresentare, e tutto il resto sparisce. Non serve un filtro a parte —
   quello che non e' previsto qui semplicemente non entra. */
function fcdFromDom(root) {
    var blocks = [];

    function marksOf(el, ereditati) {
        var m = fcdCloneMarks(ereditati) || {};
        var tag = el.tagName ? el.tagName.toLowerCase() : '';
        if (tag === 'b' || tag === 'strong') m.b = 1;
        if (tag === 'i' || tag === 'em') m.i = 1;
        if (tag === 'u' || tag === 'ins') m.u = 1;
        if (tag === 'mark') m.h = 1;
        if (el.dataset) {
            if (el.dataset.b) m.b = 1;
            if (el.dataset.i) m.i = 1;
            if (el.dataset.u) m.u = 1;
            if (el.dataset.h) m.h = fcdColoreTestoValido(el.dataset.h) ? el.dataset.h : 1;
            if (el.dataset.c && fcdColoreTestoValido(el.dataset.c)) m.c = el.dataset.c;
        }
        return fcdCloneMarks(m);
    }

    function inline(node, marks, runs, saltare) {
        node.childNodes.forEach(function (ch) {
            if (ch === saltare) return;
            if (ch.nodeType === 3) {
                var s = ch.nodeValue;
                if (s) runs.push(marks ? { s: s, m: fcdCloneMarks(marks) } : { s: s });
                return;
            }
            if (ch.nodeType !== 1) return;
            var tag = ch.tagName.toLowerCase();
            /* Il riempitivo non e' contenuto: contarlo faceva crescere di una
               riga vuota il testo a ogni salvataggio. */
            if (tag === 'br') { if (!ch.dataset || !ch.dataset.fill) runs.push({ s: '\n' }); return; }
            if (ch.classList && ch.classList.contains('fc-dict')) return;   // testo provvisorio della dettatura
            if (ch.classList && ch.classList.contains('fc-x')) {
                var runFormula = { x: fcdTexDi(ch) };
                /* I marchi stanno DIRETTAMENTE su questo span (vedi fcdRunHTML):
                   marksOf legge i suoi data-* propri, uniti a quelli ereditati
                   da un eventuale antenato — che di norma qui non c'e', ma la
                   fusione resta corretta anche se un giorno ci fosse. */
                var mFormula = marksOf(ch, marks);
                if (mFormula) runFormula.m = mFormula;
                runs.push(runFormula);
                return;
            }
            inline(ch, marksOf(ch, marks), runs);
        });
    }

    function blockDi(el) {
        if (el.classList && el.classList.contains('fc-fx')) {
            var bfx = { t: 'fx', tex: fcdTexDi(el) };
            var lv = parseInt(el.dataset.lvl, 10);
            if (lv >= 1) { bfx.lvl = lv; if (el.dataset.c && FCD_LIST_COLORS.indexOf(el.dataset.c) !== -1) bfx.c = el.dataset.c; }
            /* Il colore del TESTO sta in data-mc (vedi fcdBlockHTML): il resto
               dei marchi (grassetto...) sta dove sta sempre, su data-b ecc. —
               solo il colore va rimappato per non confonderlo col colore del
               pallino, che e' data-c e un'altra cosa. */
            var mFx = {};
            if (el.dataset.b) mFx.b = 1;
            if (el.dataset.i) mFx.i = 1;
            if (el.dataset.u) mFx.u = 1;
            if (el.dataset.h) mFx.h = fcdColoreTestoValido(el.dataset.h) ? el.dataset.h : 1;
            if (el.dataset.mc && fcdColoreTestoValido(el.dataset.mc)) mFx.c = el.dataset.mc;
            var mFxPulito = fcdCloneMarks(mFx);
            if (mFxPulito) bfx.m = mFxPulito;
            return bfx;
        }
        var b = { t: 'p', runs: [] };
        if (el.classList && el.classList.contains('fc-li')) {
            b.t = 'li';
            b.lvl = parseInt(el.dataset.lvl, 10) || 1;
            if (el.dataset.c && FCD_LIST_COLORS.indexOf(el.dataset.c) !== -1) b.c = el.dataset.c;
            var ckd = parseInt(el.dataset.ck, 10);
            if (ckd === 0 || ckd === 1) b.ck = ckd;
        } else if (el.classList && el.classList.contains('fc-q')) {
            b.q = 1;
        } else if (el.classList && el.classList.contains('fc-p-lvl')) {
            /* Un paragrafo comune, solo rientrato: nessun pallino, quindi
               nessun colore da rileggere — quello vive solo sui punti
               elenco veri. */
            var lvp = parseInt(el.dataset.lvl, 10);
            if (lvp >= 1) b.lvl = lvp;
        }
        if (b.t === 'p' && el.classList && el.classList.contains('fc-code') && el.dataset && fcdCodeOk(el.dataset.code)) { b.code = el.dataset.code; if (el.dataset.hl) b.hl = 1; }
        inline(el, null, b.runs, null);
        /* Una riga vuota fatta dal browser (incollando, o premendo Invio) e' un
           blocco con dentro il solo <br> segnaposto: non e' un a-capo scritto da
           chi usa l'editor. Contarlo come "\n" faceva diventare doppia ogni riga
           vuota (e il testo cresceva a ogni salvataggio). Un a-capo vero
           (Maiusc+Invio) sta in mezzo al testo, o ha comunque altro intorno. */
        if (b.runs.length === 1 && b.runs[0].s === '\n' && el.childNodes.length === 1 && el.firstChild.nodeType === 1
            && el.firstChild.tagName.toLowerCase() === 'br' && !(el.firstChild.dataset && el.firstChild.dataset.fill)) b.runs = [];
        return b;
    }

    var sciolti = { t: 'p', runs: [] };
    root.childNodes.forEach(function (n) {
        if (n.nodeType === 1 && n.tagName.toLowerCase() === 'div') {
            if (fcdBlockLen(sciolti)) { blocks.push(sciolti); sciolti = { t: 'p', runs: [] }; }
            blocks.push(blockDi(n));
            return;
        }
        /* Roba fuori da un blocco: capita col primo carattere digitato in un
           editor vuoto, o incollando. Finisce in un paragrafo suo. */
        if (n.nodeType === 3) { if (n.nodeValue) sciolti.runs.push({ s: n.nodeValue }); return; }
        if (n.nodeType === 1) {
            if (n.tagName.toLowerCase() === 'br') { blocks.push(sciolti); sciolti = { t: 'p', runs: [] }; return; }
            inline(n, marksOf(n, null), sciolti.runs);
        }
    });
    if (fcdBlockLen(sciolti)) blocks.push(sciolti);

    return fcdNorm({ v: FCD_V, blocks: blocks });
}

// ================================================================
// 7) POSIZIONE DEL CURSORE: DOM <-> MODELLO
// ================================================================
/* Il cursore si segna come (blocco, carattere) e si rimette dov'era dopo ogni
   comando. E' la parte che, sbagliata, fa "saltare" il cursore a ogni tasto:
   qui il conto dei caratteri e' lo stesso identico nei due mondi, quindi non
   c'e' niente da arrotondare. */

function fcdBlockEls(root) {
    return Array.prototype.filter.call(root.childNodes, function (n) {
        return n.nodeType === 1 && n.tagName.toLowerCase() === 'div';
    });
}

function fcdPosFromDom(root, node, offset) {
    var els = fcdBlockEls(root);
    if (!els.length) return { b: 0, o: 0 };

    /* Il nodo puo' essere l'editor stesso: succede con "seleziona tutto" e
       ogni volta che il browser mette il cursore fra un blocco e l'altro.
       Qui offset conta i BLOCCHI, non i caratteri: confonderli faceva
       collassare la selezione sul primo blocco, e il comando successivo
       lavorava sul punto sbagliato. */
    if (node === root) {
        if (offset >= els.length) {
            var ultimo = els.length - 1;
            /* MAI textContent: dentro c'e' il DISEGNO della formula, non il suo
               LaTeX. "\omega" e' lungo sei caratteri per il documento e uno
               solo a schermo, e il cursore finiva sei meno uno = cinque
               caratteri piu' indietro del punto giusto. Si somma figlio per
               figlio con la stessa regola usata ovunque. */
            return { b: ultimo, o: fcdLenFigli(els[ultimo]) };
        }
        return { b: Math.max(0, offset), o: 0 };
    }

    for (var i = 0; i < els.length; i++) {
        if (els[i] === node) {
            /* offset e' un indice di figlio, non di carattere */
            var acc = 0;
            for (var k = 0; k < offset && k < els[i].childNodes.length; k++) acc += fcdTextLen(els[i].childNodes[k]);
            return { b: i, o: acc };
        }
        if (els[i].contains(node)) return { b: i, o: fcdOffsetIn(els[i], node, offset) };
    }
    return { b: Math.max(0, els.length - 1), o: 0 };
}

/* Nel conto delle posizioni i caratteri di appoggio non esistono: il modello
   non li ha, il DOM si'. Contarli sfaserebbe il cursore di una posizione per
   ogni formula presente nella riga — l'errore classico che fa "ballare" il
   cursore negli editor scritti in fretta. */
function fcdSenzaZw(s) { return String(s == null ? '' : s).replace(/\u200B/g, ''); }

/* Il TeX sta in data-tex, non in cio' che si vede: quando la formula e'
   disegnata, il testo dell'elemento e' il disegno di MathJax e leggerlo
   significherebbe salvare al posto della formula la sua immagine a parole. */
function fcdTexDi(el) {
    if (el.dataset && el.dataset.tex != null) return el.dataset.tex;
    return el.textContent || '';
}

/* Cose che stanno nel DOM ma non nel documento. Oggi solo una: il testo
   provvisorio della dettatura.

   Qui c'era anche la formula disegnata, e sbagliava. Una formula disegnata NON
   vale zero: vale quanto il suo LaTeX, che e' quello che il documento
   contiene. Contarla zero spostava il cursore all'indietro di tutta la
   differenza fra "\omega" e "w" — cinque caratteri per una lettera greca — e
   premendo invio la riga si spezzava cinque lettere prima del punto giusto.
   La sua lunghezza la calcola fcdTextLen, che legge data-tex. */
function fcdFinto(n) {
    return !!(n && n.nodeType === 1 && n.classList && n.classList.contains('fc-dict'));
}

/* Vero per una formula, disegnata o no. E' l'unico punto in cui si decide
   "questo elemento e' una formula": prima la stessa domanda era scritta in
   sei posti leggermente diversi, e bastava che uno solo dimenticasse .fc-fx
   perche' il conto tornasse sbagliato solo in quel caso. */
function fcdEFormula(n) {
    return !!(n && n.nodeType === 1 && n.classList &&
              (n.classList.contains('fc-x') || n.classList.contains('fc-fx')));
}

/* Vero se dentro c'e' il disegno di MathJax. Serve a non credere mai a quello
   che si vede quando sotto c'e' un disegno: il testo di un disegno e' una
   descrizione a parole (la "ω"), non il codice che il documento contiene. */
function fcdEDisegnata(n) {
    return !!(n && n.nodeType === 1 && n.querySelector &&
              (n.classList.contains('fc-resa') || n.querySelector('mjx-container, .MathJax')));
}

function fcdTextLen(n) {
    if (fcdFinto(n)) return 0;
    if (n.nodeType === 3) return fcdSenzaZw(n.nodeValue).length;
    if (n.nodeType !== 1) return 0;
    if (n.tagName.toLowerCase() === 'br') return 1;
    if (fcdEFormula(n)) return fcdTexDi(n).length;
    /* Un pezzo di testo marcato che CONTIENE una formula non si misura col suo
       textContent, o la formula dentro tornerebbe a valere quanto il disegno.
       Si scende, e ogni figlio si misura con questa stessa regola. */
    if (n.querySelector && n.querySelector('.fc-x, .fc-fx')) return fcdLenFigli(n);
    return fcdSenzaZw(n.textContent).length;
}

function fcdLenFigli(el) {
    var t = 0;
    for (var i = 0; i < el.childNodes.length; i++) t += fcdTextLen(el.childNodes[i]);
    return t;
}

/* Il punto (nodo, offset) sta sul bordo INIZIALE di questo elemento? Vero solo
   se davanti non c'e' nemmeno un carattere. Serve a decidere da che parte far
   uscire il cursore quando cade dentro una formula disegnata. */
function fcdAlBordoIniziale(el, node, offset) {
    if (node === el) return offset <= 0;
    var n = node, p;
    if (node.nodeType === 3 && fcdSenzaZw((node.nodeValue || '').slice(0, offset)).length) return false;
    if (node.nodeType === 1 && offset > 0) return false;
    while (n && n !== el) {
        p = n.parentNode;
        if (!p) return false;
        for (var i = 0; i < p.childNodes.length; i++) {
            if (p.childNodes[i] === n) break;
            if (fcdSenzaZw(p.childNodes[i].textContent || p.childNodes[i].nodeValue || '').length) return false;
        }
        n = p;
    }
    return true;
}

function fcdOffsetIn(blockEl, node, offset) {
    var acc = 0, trovato = false;
    (function walk(el) {
        for (var i = 0; i < el.childNodes.length && !trovato; i++) {
            var ch = el.childNodes[i];
            if (ch === node) {
                if (ch.nodeType === 3) { acc += fcdSenzaZw((ch.nodeValue || '').slice(0, offset)).length; trovato = true; return; }
                for (var k = 0; k < offset && k < ch.childNodes.length; k++) acc += fcdTextLen(ch.childNodes[k]);
                trovato = true; return;
            }
            if (ch.nodeType === 3) { acc += fcdSenzaZw(ch.nodeValue).length; continue; }
            if (ch.nodeType === 1) {
                if (ch.tagName.toLowerCase() === 'br') { acc += 1; continue; }
                /* Una formula DISEGNATA vale quanto il suo LaTeX, non quanto
                   il disegno: "\omega" sono sei caratteri per il documento e
                   uno solo a schermo. Contare quello che si vede spostava il
                   cursore di quella differenza, e premendo invio la riga si
                   spezzava qualche lettera piu' indietro. */
                var lenEl = fcdTextLen(ch);
                if (ch.contains(node)) {
                    if (fcdEFormula(ch) && fcdEDisegnata(ch)) {
                        /* Dentro il DISEGNO non esistono posizioni del
                           documento: i nodi li' dentro sono di MathJax. Si
                           esce dalla parte giusta — prima se si e' sul suo
                           bordo iniziale, dopo in ogni altro caso. Uscire
                           sempre dalla parte dell'inizio (cio' che si faceva)
                           riportava indietro il cursore di tutta la formula
                           ogni volta che una selezione la attraversava.

                           Una formula ANCORA IN MODIFICA (non disegnata) non
                           rientra qui: e' testo normale, con un carattere
                           dopo l'altro, e in quel caso si scende dentro con
                           walk() come per qualunque altro elemento — trattarla
                           gia' come un unico blocco atomico e' esattamente
                           l'errore che faceva saltare il cursore di sei
                           caratteri in mezzo a "\\omega" ancora da correggere. */
                        if (!fcdAlBordoIniziale(ch, node, offset)) acc += lenEl;
                        trovato = true; return;
                    }
                    walk(ch); return;
                }
                acc += lenEl;
            }
        }
    })(blockEl);
    return acc;
}

function fcdDomFromPos(root, pos) {
    var els = fcdBlockEls(root);
    var el = els[Math.min(pos.b, els.length - 1)];
    if (!el) return null;
    var resto = pos.o, res = null;

    (function walk(n) {
        for (var i = 0; i < n.childNodes.length && !res; i++) {
            var ch = n.childNodes[i];
            if (ch.nodeType === 3) {
                var testo = ch.nodeValue || '';
                var len = fcdSenzaZw(testo).length;
                if (resto <= len) {
                    /* Va ritradotto in posizione REALE dentro il nodo, che
                       include i caratteri di appoggio saltati fin qui. */
                    var vere = 0, contate = 0;
                    while (vere < testo.length && contate < resto) {
                        if (testo.charAt(vere) !== '\u200B') contate++;
                        vere++;
                    }
                    res = { node: ch, offset: vere };
                    return;
                }
                resto -= len;
                continue;
            }
            if (ch.nodeType === 1) {
                if (ch.tagName.toLowerCase() === 'br') {
                    if (resto === 0) { res = { node: n, offset: i }; return; }
                    resto -= 1; continue;
                }
                var t = fcdTextLen(ch);
                var formula = fcdEFormula(ch);
                if (formula && resto === t && i + 1 < n.childNodes.length) {
                    /* Al bordo di CHIUSURA di una formula, con dell'altro
                       subito dopo, il valore predefinito e' stare FUORI: e'
                       quello che ci si aspetta continuando a scrivere dopo
                       una formula appena chiusa. Chi ha bisogno dell'altro
                       lato — le frecce, che devono poter tornare dentro —
                       piazza il cursore da se', senza passare di qui. */
                    resto -= t;
                    continue;
                }
                if (resto <= t) {
                    /* Dentro una formula disegnata il cursore non ci puo'
                       stare: si mette prima o dopo, secondo da che parte
                       cadrebbe. */
                    if (formula && fcdEDisegnata(ch)) {
                        res = { node: n, offset: resto === 0 ? i : i + 1 };
                        return;
                    }
                    walk(ch); return;
                }
                resto -= t;
            }
        }
        if (!res) res = { node: n, offset: n.childNodes.length };
    })(el);

    return res;
}

function fcdGetSel(root) {
    var s = window.getSelection();
    if (!s || !s.rangeCount) return null;
    var r = s.getRangeAt(0);
    if (!root.contains(r.startContainer) || !root.contains(r.endContainer)) return null;
    return {
        a: fcdPosFromDom(root, r.startContainer, r.startOffset),
        f: fcdPosFromDom(root, r.endContainer, r.endOffset)
    };
}

function fcdSetSel(root, sel) {
    var a = fcdDomFromPos(root, sel.a), f = fcdDomFromPos(root, sel.f || sel.a);
    if (!a || !f) return;
    var r = document.createRange();
    try {
        r.setStart(a.node, a.offset);
        r.setEnd(f.node, f.offset);
    } catch (e) { return; }
    var s = window.getSelection();
    s.removeAllRanges();
    s.addRange(r);
}

// ================================================================
// 8) STILE — un blocco solo, iniettato una volta
// ================================================================
(function fcdStyles() {
    if (typeof document === 'undefined' || document.getElementById('fc-doc-styles')) return;
    var bul = FCD_BULLETS.map(function (ch, i) {
        return '.fc-li[data-lvl="' + (i + 1) + '"]::before{ content:"' + ch + '"; }';
    }).join('');
    var col = Object.keys(FCD_PALETTE).map(function (k) {
        return '.fc-t[data-c="' + k + '"]{ color:' + FCD_PALETTE[k] + '; }' +
               '.fc-li[data-c="' + k + '"]{ --fc-bullet:' + FCD_PALETTE[k] + '; }';
    }).join('');

    var css =
        '.fc-t[data-b="1"]{ font-weight:700; }' +
        '.fc-t[data-i="1"]{ font-style:italic; }' +
        '.fc-t[data-u="1"]{ text-decoration:underline; text-underline-offset:2px; }' +
        '.fc-t[data-h]{ background:#FFE066; color:#1C1C1E; border-radius:3px; padding:0 2px; box-decoration-break:clone; -webkit-box-decoration-break:clone; }' +
        col +
        '.fc-p{ min-height:1.65em; }' +
        /* le righe di codice di fila formano un riquadro scuro: la prima porta
           il nome della lingua, l'ultima chiude gli angoli. Si riconoscono dai
           vicini (non da classi scritte nel DOM) cosi' anche una riga aperta
           premendo Invio si compone da sola nel gruppo. */
        '.fc-p.fc-code{ font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace; font-size:.88em; line-height:1.55; white-space:pre-wrap; tab-size:4;' +
            ' background:var(--fc-code-bg,#14161b); color:var(--fc-code-fg,#e6e6ea); padding:0 14px; margin:0; min-height:1.55em; caret-color:#fff; }' +
        '.fc-p.fc-code:not(.fc-code + .fc-code){ border-radius:12px 12px 0 0; margin-top:8px; padding-top:34px; position:relative; }' +
        '.fc-p.fc-code:not(.fc-code + .fc-code)::before{ content:attr(data-lang); position:absolute; left:0; right:0; top:0; padding:7px 14px; border-radius:12px 12px 0 0;' +
            ' background:rgba(255,255,255,.07); color:#9a9aa3; font:700 11.5px/1.2 -apple-system,system-ui,sans-serif; letter-spacing:.05em; text-transform:uppercase; pointer-events:none; user-select:none; }' +
        '.fc-p.fc-code:not(:has(+ .fc-code)){ border-radius:0 0 12px 12px; padding-bottom:12px; margin-bottom:8px; }' +
        '.fc-p.fc-code:not(.fc-code + .fc-code):not(:has(+ .fc-code)){ border-radius:12px; }' +
        /* riga evidenziata e marchi dentro il codice */
        '.fc-p.fc-code[data-hl]{ background-image:linear-gradient(rgba(255,214,10,.16),rgba(255,214,10,.16)); box-shadow:inset 3px 0 0 #FFD60A; }' +
        '.fc-code .fc-t[data-h]{ background:rgba(255,224,102,.38); color:inherit; }' +
        /* Pezzo in attesa (solo il carattere invisibile): niente margine, o uno vuoto si vede come uno spazio. */
        '.fc-t[data-zw]{ padding:0 !important; }' +
        '.fc-code .fc-t[data-b="1"]{ font-weight:900; color:#fff; -webkit-text-stroke:.7px #fff; background:rgba(255,255,255,.16); border-radius:4px; padding:0 3px; }' +
        /* citazione: barra blu a sinistra e fondo leggero; piu' righe di fila fanno un riquadro solo */
        '.fc-p.fc-q{ border-left:4px solid var(--blue,#007AFF); background:color-mix(in srgb, var(--blue,#007AFF) 8%, transparent); padding:5px 14px; margin:0; font-style:italic; color:var(--text-2,#3a3a3c); border-radius:0 10px 10px 0; }' +
        '.fc-p.fc-q:not(.fc-q + .fc-q){ margin-top:6px; } .fc-p.fc-q:not(:has(+ .fc-q)){ margin-bottom:6px; }' +
        '.fc-p.fc-q + .fc-q{ border-top-right-radius:0; } .fc-p.fc-q:has(+ .fc-q){ border-bottom-right-radius:0; }' +
        /* checklist: cerchio da toccare, verde con la spunta quando e' fatto */
        '.fc-li[data-ck][data-lvl]::before{ content:""; width:1.15em; height:1.15em; border-radius:50%; border:2px solid var(--text-3,#B5B5BA); box-sizing:border-box; top:.3em; cursor:pointer; background:transparent; }' +
        '.fc-li[data-ck="1"][data-lvl]::before{ background:var(--green,#34C759) url("data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 24 24%22 fill=%22none%22 stroke=%22white%22 stroke-width=%223.6%22 stroke-linecap=%22round%22 stroke-linejoin=%22round%22%3E%3Cpath d=%22M5 12.5 10 17l9-10%22/%3E%3C/svg%3E") center/72% no-repeat; border-color:var(--green,#34C759); }' +
        '.fc-li[data-ck="1"]{ color:var(--text-2,#8A8A8E); text-decoration:line-through; }' +
        '.fc-tk-k{ color:#ff7ab2; } .fc-tk-s{ color:#fc6a5d; } .fc-tk-c{ color:#7f8c98; font-style:italic; } .fc-tk-n{ color:#d9c97c; } .fc-tk-f{ color:#67b7a4; } .fc-tk-t{ color:#9ecbff; }' +
        '.fc-p, .fc-li{ overflow-wrap:anywhere; min-width:0; }' +
        /* La regola che tiene insieme tutto: l'editor non cresce mai oltre il
           suo contenitore, qualunque cosa ci sia dentro. */
        '.fc-editor{ max-width:100%; min-width:0; overflow-wrap:anywhere; }' +
        /* I pallini cambiano forma per livello, come in Word, e il rientro
           cresce con esso: la forma da sola non basterebbe a far vedere la
           gerarchia su un elenco lungo. */
        '.fc-li{ position:relative; padding-left:calc(1.5em + var(--fc-lvl,1) * 1.15em); min-height:1.65em; }' +
        /* Stessa rientranza di un punto elenco, nessun pallino: la riga di
           continuo dentro una lista, davanti a una formula puntata. */
        '.fc-p-lvl{ padding-left:calc(1.5em + var(--fc-lvl,1) * 1.15em); }' +
        FCD_BULLETS.map(function (_, i) { return '.fc-p-lvl[data-lvl="' + (i + 1) + '"]{ --fc-lvl:' + (i + 1) + '; }'; }).join('') +
        '.fc-li br + *, .fc-li br{ }' +
        '.fc-li::before{ content:"\\2022"; position:absolute; left:calc(var(--fc-lvl,1) * 1.15em); color:var(--fc-bullet,var(--text-2,#8A8A8E)); font-weight:700; line-height:inherit; }' +
        bul +
        FCD_BULLETS.map(function (_, i) { return '.fc-li[data-lvl="' + (i + 1) + '"]{ --fc-lvl:' + (i + 1) + '; }'; }).join('') +
        /* La formula si scrive come testo: i dollari sono disegnati, non
           digitati. Cosi' "costa 5$" resta "costa 5$" e non diventa niente. */
        /* Il codice di una formula e' lunghissimo per natura. In linea deve
           andare a capo come una parola qualsiasi: se non lo facesse,
           allargherebbe la cella o uscirebbe dallo schermo, e la nota
           diventerebbe illeggibile mentre la si scrive. */
        '.fc-x{ font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:.94em; color:inherit; background:var(--blue-soft,rgba(0,122,255,.09)); border-radius:6px; padding:0 3px; box-shadow:inset 0 0 0 1px rgba(0,122,255,.22); white-space:pre-wrap; overflow-wrap:anywhere; word-break:break-word; min-width:0; }' +
        /* Una formula ancora vuota deve occupare spazio: larga zero non si
           vedrebbe e non ci si potrebbe cliccare dentro per riprenderla. */
        '.fc-x:empty{ display:inline-block; min-width:1.4em; }' +
        /* ---- FORMULA IN LINEA, DISEGNATA ----
           Resta un blocco unico e indivisibile: MathJax impila i segni con
           margini NEGATIVI misurati sul proprio line-height azzerato — la
           barra di \overline{} sta sulla lettera solo grazie a un
           "margin-bottom:-0.248em". Toccare display o line-height del suo
           contenitore, come avevo provato per farla andare a capo, sposta il
           riferimento di quei margini e la barra se ne va per conto suo,
           molto piu' in alto della lettera. Quella strada e' chiusa: la
           formula in linea si tiene com'e', e per non allargare la casella
           bastano max-width e il min-width:0 sulla colonna.
           (Andare a capo su una formula lunghissima va risolto spezzando il
           LaTeX agli spazi in piu' pezzi disegnati separatamente, non con la
           CSS: e' il prossimo passo, non questo.) */
        /* ---- FORMULA IN LINEA, DISEGNATA ----
           MathJax tiene la formula intera su una riga sola con due regole
           sue: "display:inline-block" e "white-space:nowrap" sul
           contenitore della formula (mjx-math) — cioe' ESATTAMENTE "sono un
           blocco e non vado a capo mai". Vanno scavalcate per fare in modo
           che, se non ci sta sulla riga, vada a capo come farebbe una parola
           lunga — i pezzi che MathJax mette dentro (mjx-mi per una
           variabile, mjx-mo per un operatore) sono gia' inline-block, cioe'
           mattoni accostati: tolto il nowrap, il browser li manda a capo tra
           l'uno e l'altro da solo, senza spezzare nessun simbolo a meta'.

           line-height va rimesso (era azzerato) perche' senza, quando la
           formula va a capo su piu' righe, quelle righe si sovrappongono.

           C'e' pero' un effetto collaterale a cui stare molto attenti: il
           line-height si eredita, e alcuni pezzi di MathJax — la barra di
           \overline{}, il trattino di \frac{}{}, il segno di \sqrt{},
           i limiti sotto e sopra di \sum e \int — impilano le loro parti
           mettendole ognuna "display:block" UNA SOPRA L'ALTRA e poi le
           incollano con un MARGINE NEGATIVO, un trucco che funziona SOLO
           se il loro line-height resta a zero: e' li' che vive il "quanto in
           alto" di ogni pezzo. Ereditando il nostro line-height normale
           invece di zero, ogni riga di quell'impilamento diventa alta come
           una riga di testo vero, il margine negativo non basta piu' a
           correggerla, e il segno che dovrebbe stare aderente (la barra di
           overline, per dire) schizza in alto per conto suo — ed e'
           ESATTAMENTE il difetto che si vedeva. Vanno quindi rimessi a zero
           uno per uno, cosi' che l'eredita' si fermi li' e quello che sta
           dentro torni a impilarsi come MathJax si aspetta. */
        '.fc-x.fc-resa{ display:inline; max-width:100%; min-width:0; }' +
        '.fc-x.fc-resa mjx-container{ display:inline; line-height:inherit; max-width:100%; }' +
        '.fc-x.fc-resa mjx-math{ display:inline; white-space:normal; word-wrap:break-word; line-height:inherit; }' +
        '.fc-x.fc-resa mjx-mover, .fc-x.fc-resa mjx-munder, .fc-x.fc-resa mjx-munderover, ' +
        '.fc-x.fc-resa mjx-mfrac, .fc-x.fc-resa mjx-msqrt, .fc-x.fc-resa mjx-mroot, ' +
        /* Pedice ED esponente insieme ($A_i^c$) sono un impilamento anche
           loro: MathJax li mette uno sopra l'altro dentro un mjx-script,
           separati da un mjx-spacer con un margine — stessa identica
           meccanica della barra di \overline{}, stessa dipendenza dal
           line-height azzerato. Con il solo esponente ($A^c$) invece il
           mjx-script ha un figlio solo, non impila niente e infatti non
           dava problemi: ecco perche' il difetto si vedeva solo quando
           c'erano tutti e due. */
        '.fc-x.fc-resa mjx-msubsup, .fc-x.fc-resa mjx-msub, .fc-x.fc-resa mjx-msup, ' +
        '.fc-x.fc-resa mjx-script, .fc-x.fc-resa mjx-stack, ' +
        /* Stessa storia per "|x|": senza \left...\right e' un semplice
           operatore (mjx-mo), non un impilamento — ma acceso vicino a un
           mjx-msup che QUELLA correzione ce l'ha gia' (line-height:0), i due
           finiscono fratelli nella stessa riga con altezze di riga diverse,
           e un browser allinea fratelli inline anche in base a quella:
           la barra del valore assoluto si vedeva spostata rispetto
           all'esponente accanto. Stessa cura, stesso motivo. */
        '.fc-x.fc-resa mjx-mo, ' +
        /* \cancel, \boxed e affini passano da mjx-menclose: la barra o il
           riquadro sono un pezzo impilato SOPRA il contenuto con lo stesso
           trucco del margine negativo (vedi il commento in cima a questo
           blocco per \overline{}), quindi hanno la stessa identica
           dipendenza dal line-height azzerato. Senza, la barra di \cancel
           non cadeva sulla lettera ma altrove: esattamente il difetto delle
           altre correzioni qui sopra, semplicemente su un comando diverso. */
        '.fc-x.fc-resa mjx-menclose, ' +
        '.fc-x.fc-resa mjx-mtable{ line-height:0; }' +
        /* La stessa correzione, mai estesa alla formula A BLOCCO: una
           frazione o un \overline{} li' dentro avrebbe lo stesso identico
           difetto, semplicemente nessuno l'aveva ancora notato. */
        '.fc-fx.fc-resa mjx-mover, .fc-fx.fc-resa mjx-munder, .fc-fx.fc-resa mjx-munderover, ' +
        '.fc-fx.fc-resa mjx-mfrac, .fc-fx.fc-resa mjx-msqrt, .fc-fx.fc-resa mjx-mroot, ' +
        '.fc-fx.fc-resa mjx-msubsup, .fc-fx.fc-resa mjx-msub, .fc-fx.fc-resa mjx-msup, ' +
        '.fc-fx.fc-resa mjx-script, .fc-fx.fc-resa mjx-stack, ' +
        '.fc-fx.fc-resa mjx-mo, ' +
        '.fc-fx.fc-resa mjx-menclose, ' +
        '.fc-fx.fc-resa mjx-mtable{ line-height:0; }' +
        /* I due dollari decorativi ai lati sono stati tolti su richiesta: lo
           sfondo azzurro del riquadro basta gia' a dire "qui c'e' una
           formula", senza bisogno del simbolo. */
        /* La formula che ha una riga sua invece NON va a capo: una formula
           spezzata a meta' non si legge. Scorre in orizzontale, come un
           blocco di codice. */
        /* ---- FORMULA CON RIGA PROPRIA ----
           Mentre si scrive e' CODICE, e il codice va a capo come il testo:
           altrimenti una formula lunga allarga la cella dell'editor e sfascia
           la pagina attorno. Disegnata invece non si spezza, e diventa un
           riquadro a se' che si scorre.
           width:100% con box-sizing e min-width:0 sono quello che le impedisce
           di contribuire alla larghezza naturale del contenitore: senza,
           un contenitore che si adatta al contenuto — come la cella di
           big_edit — verrebbe allargato dalla formula invece di contenerla. */
        '.fc-fx{ display:block; margin:8px 0; padding:8px 12px; text-align:center; font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:.95em; color:inherit; background:var(--blue-soft,rgba(0,122,255,.09)); border-radius:10px; box-shadow:inset 0 0 0 1px rgba(0,122,255,.22); width:100%; max-width:100%; min-width:0; box-sizing:border-box; white-space:pre-wrap; overflow-wrap:anywhere; }' +
        /* min-width:0 qui e' ripetuto apposta, non solo ereditato da .fc-fx:
           e' la stessa rete di sicurezza vista sopra per .fc-x, e in un
           punto cosi' delicato meglio scriverla dov'e' visibile che
           affidarsi al fatto che nessuno la tolga per sbaglio da .fc-fx
           in futuro. */
        /* touch-action con ENTRAMBI gli assi, non solo pan-x: "pan-x" da
           solo dice al browser che su questo elemento l'unico gesto
           ammesso e' quello orizzontale, quindi una passata del dito verso
           il basso che parte da qui non scorre niente — ne' la formula (che
           in verticale non ha nulla da scorrere) ne' la pagina sotto. In
           una scheda alta, ogni formula diventava una zona morta che si
           mangiava lo scorrimento. Ammettendo anche pan-y il gesto
           verticale non trova nulla da scorrere qui e passa alla pagina,
           che e' il comportamento voluto; quello orizzontale resta alla
           formula. overscroll-behavior-x:contain evita che, arrivati in
           fondo alla formula, il gesto continui trascinando la pagina. */
        /* Lo scorrimento orizzontale delle formule larghe vive ORA
           sull'involucro INTERNO (.fc-fx-vetro), non piu' su questo elemento:
           qui sopra c'e' anche il pallino della lista, posizionato fuori dal
           bordo con uno scostamento negativo — e un overflow su questo
           stesso elemento lo ritaglierebbe via, esattamente il difetto che
           faceva sparire il pallino appena la formula veniva disegnata.
           .fc-fx resta libero, testo allineato a sinistra come prima. */
        '.fc-fx.fc-resa{ text-align:left; }' +
        '.fc-fx-vetro{ display:block; white-space:nowrap; overflow-x:auto; overflow-y:hidden; min-width:0; max-width:100%; -webkit-overflow-scrolling:touch; touch-action:pan-x pan-y; overscroll-behavior-x:contain; }' +
        /* Qui il nowrap di MathJax va benissimo — anzi serve: e' cio' che
           tiene la formula su una riga sola da scorrere. L'unica cosa da
           impedire e' che il contenitore interno imponga la propria
           larghezza a quello esterno: senza max-width, invece di comparire
           la barra di scorrimento, il riquadro si allargherebbe fino a
           contenere tutta la formula, ed e' quello che si vedeva. */
        '.fc-fx.fc-resa mjx-container{ max-width:none; }' +
        '.fc-fx::-webkit-scrollbar{ height:5px; }' +
        '.fc-fx::-webkit-scrollbar-thumb{ background:color-mix(in srgb, var(--blue,#007AFF) 30%, transparent); border-radius:3px; }' +
        /* Formula dentro un elenco: rientra come il punto e tiene il pallino
           alla sua sinistra. */
        /* Il pallino di una formula a blocco sta FUORI dal suo riquadro, non
           dentro. Prima lo spazio per il pallino era imbottitura INTERNA
           (padding-left) e lo sfondo azzurro se lo mangiava: il pallino
           finiva dentro la casella, come se facesse parte della formula.
           Ora lo spazio e' margine ESTERNO, la casella si sposta a destra di
           quel tanto, e il pallino si mette nel margine appena creato — che
           e' fuori dallo sfondo. La larghezza va rifatta di conseguenza:
           .fc-fx ha width:100%, che con un margine in piu' sfonderebbe a
           destra. */
        '.fc-fx-li{ position:relative; text-align:left;' +
        '  margin-left:calc(1.6em + var(--fc-lvl,1) * 1.15em);' +
        '  width:auto; max-width:calc(100% - (1.6em + var(--fc-lvl,1) * 1.15em)); }' +
        '.fc-fx-li::before{ content:"\\2022"; position:absolute; left:-1.15em; top:8px; color:var(--fc-bullet,var(--text-2,#8A8A8E)); font-weight:700; font-family:inherit; }' +
        FCD_BULLETS.map(function (ch, i) { return '.fc-fx-li[data-lvl="' + (i + 1) + '"]::before{ content:"' + ch + '"; }' +
            '.fc-fx-li[data-lvl="' + (i + 1) + '"]{ --fc-lvl:' + (i + 1) + '; }'; }).join('') +
        /* Niente dollari sulla formula a blocco. Su un elemento di blocco
           finivano su righe loro, e una formula diventava tre righe: dollari,
           formula, dollari. Che si tratti di una formula lo dice gia' il
           riquadro; i dollari restano solo sulla formula in linea, dove
           servono a distinguerla dal testo che la circonda. */
        '.fc-fx:empty::before{ content:"formula"; opacity:.35; }' +
        /* Formula disegnata: niente cornice ne' dollari, e' gia' una formula.
           Non modificabile: si torna al codice cliccandoci sopra. */
        '.fc-x.fc-resa, .fc-fx.fc-resa{ font-family:inherit; background:transparent; box-shadow:none; color:inherit; cursor:pointer; }' +
        '.fc-x.fc-resa::before, .fc-x.fc-resa::after, .fc-fx.fc-resa::before, .fc-fx.fc-resa::after{ content:""; }' +
        /* ...ma il pallino non e' una decorazione della formula: e' il segno
           che sta in un elenco, e deve restare anche a formula disegnata.
           La riga qui sopra azzera il contenuto di TUTTI gli pseudo-elementi
           (serviva per i dollari) e portava via anche lui: restava lo spazio
           a sinistra e nessun pallino dentro. Qui glielo si rimette, per ogni
           livello, con una regola piu' forte perche' viene dopo e nomina una
           classe in piu'. */
        '.fc-fx-li.fc-resa::before{ content:"\\2022"; }' +
        FCD_BULLETS.map(function (ch, i) {
            return '.fc-fx-li.fc-resa[data-lvl="' + (i + 1) + '"]::before{ content:"' + ch + '"; }';
        }).join('') +
        '.fc-x.fc-resa{ padding:0 2px; border-radius:5px; }' +
        '.fc-x.fc-resa:hover, .fc-fx.fc-resa:hover{ background:var(--blue-soft,rgba(0,122,255,.09)); }' +
        /* Il colore scelto su una formula deve sopravvivere anche da
           DISEGNATA. Senza queste righe non succedeva, e per un motivo
           preciso: ".fc-t[data-c=...]" (che mette il colore) e
           ".fc-x.fc-resa" (che rimette color:inherit) hanno esattamente la
           stessa forza, e a parita' di forza vince quella scritta dopo — cioe'
           l'inherit, che riportava la formula al nero del testo intorno. Era
           esattamente questo a lasciare le formule nere mentre tutto il resto
           diventava verde. Nominando entrambe le classi la regola diventa piu'
           forte, e il colore vince in tutti e tre gli stati: grezzo,
           disegnato, e dentro MathQuill. */
        Object.keys(FCD_PALETTE).map(function (k) {
            return '.fc-x.fc-t[data-c="' + k + '"]{ color:' + FCD_PALETTE[k] + '; }' +
                   '.fc-x.fc-t[data-c="' + k + '"] .mq-root-block, .fc-x.fc-t[data-c="' + k + '"] mjx-container{ color:' + FCD_PALETTE[k] + '; }' +
                   /* Una formula a blocco puo' avere GIA' un data-c, per il
                      colore del suo pallino se e' in una lista — un
                      concetto del tutto separato dal colore del suo testo.
                      Usare qui lo stesso attributo lo avrebbe sovrascritto,
                      forzando bollino e testo sempre allo stesso colore.
                      "data-mc" (colore del marchio) e' suo, non si scontra
                      con niente. */
                   '.fc-fx.fc-t[data-mc="' + k + '"]{ color:' + FCD_PALETTE[k] + '; }' +
                   '.fc-fx.fc-t[data-mc="' + k + '"] .mq-root-block, .fc-fx.fc-t[data-mc="' + k + '"] mjx-container{ color:' + FCD_PALETTE[k] + '; }';
        }).join('') +
        /* ANCHE L'EVIDENZIATORE, non solo il colore.
           Stessa storia delle righe qui sopra, stesso rimedio: ".fc-x.fc-resa"
           mette background:transparent, ha la stessa forza di una regola con
           una sola classe e viene dopo — quindi vincerebbe lei, e la formula
           resterebbe bianca in mezzo a una frase tutta gialla. Nominando due
           classi (".fc-x.fc-t") la regola diventa piu' forte e il giallo
           tiene in tutti e tre gli stati: grezzo, disegnato e dentro
           MathQuill.
           Il testo va forzato scuro come fa gia' l'evidenziatore sul testo
           normale: su un fondo giallo un colore chiaro non si leggerebbe. E'
           anche il motivo per cui queste righe stanno DOPO quelle del colore
           — a parita' di forza vince l'ultima, e qui e' giusto che vinca il
           giallo.
           box-shadow:none toglie il bordino azzurro della formula grezza, che
           sul giallo sembrerebbe una svista.
           I simboli disegnati da MathJax hanno un colore loro, quindi non
           basta dirlo al contenitore: va detto anche a mjx-container e al
           blocco di MathQuill, esattamente come per il colore. */
        '.fc-x.fc-t[data-h], .fc-x.fc-resa.fc-t[data-h]{ background:#FFE066; color:#1C1C1E; box-shadow:none; }' +
        '.fc-x.fc-t[data-h] .mq-root-block, .fc-x.fc-t[data-h] mjx-container{ color:#1C1C1E; }' +
        /* Per la formula a blocco vale il discorso di data-mc fatto sopra: il
           colore del pallino usa data-c, quindi il marchio del testo viaggia
           su data-mc. L'evidenziatore invece non si scontra con niente e
           resta data-h. */
        '.fc-fx.fc-t[data-h], .fc-fx.fc-resa.fc-t[data-h]{ background:#FFE066; color:#1C1C1E; box-shadow:none; }' +
        '.fc-fx.fc-t[data-h] .mq-root-block, .fc-fx.fc-t[data-h] mjx-container{ color:#1C1C1E; }' +
        /* evidenziatore colorato: un tono chiaro del colore scelto, con testo scuro
           (come il giallo); dentro il codice e' piu' trasparente, per lasciar
           vedere i colori delle parole */
        FCD_COLORS.map(function (k) {
            var pal = FCD_PALETTE[k];
            return '.fc-t[data-h="' + k + '"], .fc-x.fc-t[data-h="' + k + '"], .fc-x.fc-resa.fc-t[data-h="' + k + '"], .fc-fx.fc-t[data-h="' + k + '"], .fc-fx.fc-resa.fc-t[data-h="' + k + '"]{ background:color-mix(in srgb, ' + pal + ' 38%, #fff); }' +
                   '.fc-code .fc-t[data-h="' + k + '"]{ background:color-mix(in srgb, ' + pal + ' 46%, transparent); }';
        }).join('') +
        /* L'EVIDENZIAZIONE della formula quando la selezione la attraversa.
           Sta qui in fondo, e nomina anche .fc-resa, per un motivo preciso:
           due righe piu' su c'e' ".fc-x.fc-resa{ background:transparent }",
           che ha la stessa forza e viene dopo — quindi vinceva lei, e la
           formula restava senza evidenziazione come se la selezione le
           passasse attraverso. Serve stare dopo E essere piu' specifici,
           altrimenti il colore non si vede e basta.
           (Il colore va messo a mano perche' i simboli di MathJax sono
           contenuto generato dalla CSS, che il browser non evidenzia mai:
           se ne occupa ftMarcaFormuleSelezionate.) */
        '.fc-x.fc-sel, .fc-fx.fc-sel,' +
        '.fc-x.fc-resa.fc-sel, .fc-fx.fc-resa.fc-sel{' +
        '  background:color-mix(in srgb, var(--blue,#007AFF) 30%, transparent); border-radius:4px;' +
        '  box-shadow:0 0 0 1px color-mix(in srgb, var(--blue,#007AFF) 30%, transparent);' +
        '}' +
        '.fc-fx.fc-resa{ padding:6px 12px; }' +
        /* Il testo mentre lo si detta: si vede che e' provvisorio, e infatti
           non fa ancora parte del documento. */
        '.fc-dict{ opacity:.5; font-style:italic; }';

    /* Un <style> creato da JavaScript e' accettato dalla CSP solo col nonce della
       pagina; lo porta il <meta name="csp-nonce"> messo da pwa_head_html().
       Quel meta finisce in fondo al <head>, DOPO gli script che stanno nel <head>:
       se questo file gira prima, si aspetta la fine del caricamento. */
    function inietta() {
        var el = document.createElement('style');
        el.id = 'fc-doc-styles';
        var metaNonce = document.querySelector('meta[name="csp-nonce"]');
        if (metaNonce && metaNonce.content) el.setAttribute('nonce', metaNonce.content);
        el.textContent = css;
        document.head.appendChild(el);
    }
    if (document.querySelector('meta[name="csp-nonce"]') || document.readyState !== 'loading') inietta();
    else document.addEventListener('DOMContentLoaded', inietta);
})();

// ================================================================
// 9) COMPATIBILITA' CON LE PAGINE DI SOLA LETTURA
// ================================================================
/* Disegna le formule di un pezzo di pagina. Serve alle pagine di sola lettura:
   fcdToHTML produce il TeX come TESTO (e' quello che vuole l'editor, dove la
   formula si corregge a mano), ma chi legge la formula la vuole vedere fatta.

   La conversione consiste nel mettere i delimitatori che MathJax riconosce.
   Si usano \\( \\) e \\[ \\], non i dollari: col nuovo formato un "$" nel testo
   e' solo un dollaro — "costa 5$" deve restare "costa 5$" e non diventare
   l'inizio di una formula. */
function fcdDisegnaFormule(root) {
    var scope = root || document;
    var trovate = [];
    scope.querySelectorAll('.fc-x, .fc-fx').forEach(function (el) {
        if (el.classList.contains('fc-resa')) return;
        var tex = fcdTexDi(el);
        el.dataset.tex = tex;
        if (!tex) return;
        el.classList.add('fc-resa');
        el.textContent = el.classList.contains('fc-fx') ? '\\[' + tex + '\\]' : '\\(' + tex + '\\)';
        trovate.push(el);
    });
    if (trovate.length) fcdTypesetMath([scope === document ? document.body : scope]);
}

function fcdTypesetMath(elements) {
    if (typeof window === 'undefined') return;
    if (window.MathJax && MathJax.typesetPromise) MathJax.typesetPromise(elements).catch(function () {});
    else setTimeout(function () { fcdTypesetMath(elements); }, 200);
}

function renderAllRawTexts(root) {
    var scope = root || document;
    var trovato = false;
    scope.querySelectorAll('.raw-text-content').forEach(function (el) {
        /* Se dentro c'e' gia' qualcosa e non e' il segnaposto del server, il
           disegno l'ha gia' fatto qualcun altro: oggi lo fa il server, che
           manda il markup finito. Rifarlo sarebbe buttare via il lavoro — e,
           con le formule, chiedere a MathJax di disegnarle due volte.
           Chi vuole essere disegnato da qui lascia il contenitore vuoto,
           oppure ci mette dentro un elemento marcato data-fc-pre: e' la stessa
           condizione che usa disegnaTestiGrezzi() in fc_view.php. */
        if (el.innerHTML.trim() !== '' && !el.querySelector('[data-fc-pre]')) return;
        el.innerHTML = parseTextForPreview(el.getAttribute('data-raw'));
        trovato = true;
    });
    if (trovato) fcdDisegnaFormule(scope === document ? document : scope);
}

if (typeof document !== 'undefined') {
    document.addEventListener('DOMContentLoaded', function () { renderAllRawTexts(); });
}

/* Nomi pubblici del file precedente. Le pagine di sola lettura che chiamavano
   escapeHTML() o fcTypesetMath() continuano a funzionare senza modifiche: e'
   il motivo per cui questo file si puo' anche salvare col nome del vecchio e
   non toccare nient'altro. */
function escapeHTML(text) { return fcdEscape(text); }
function fcTypesetMath(elements) { return fcdTypesetMath(elements); }
var FC_PALETTE = FCD_PALETTE;

if (typeof module !== 'undefined' && module.exports) {
    module.exports = { fcdSembraCodice: fcdSembraCodice, fcdParse: fcdParse, fcdStringify: fcdStringify, fcdNorm: fcdNorm,
        fcdTrim: fcdTrim, fcdTrimText: fcdTrimText,
        fcdToggleMark: fcdToggleMark, fcdSetMark: fcdSetMark, fcdRangeHas: fcdRangeHas,
        fcdToggleList: fcdToggleList, fcdIndent: fcdIndent, fcdSetListColor: fcdSetListColor,
        fcdToggleBlockMath: fcdToggleBlockMath, fcdToHTML: fcdToHTML, fcdFromLegacy: fcdFromLegacy,
        fcdToggleInlineMath: fcdToggleInlineMath, fcdSplitBlock: fcdSplitBlock,
        fcdInsertSoftBreak: fcdInsertSoftBreak, fcdTexDi: fcdTexDi,
        fcdDeleteRange: fcdDeleteRange, fcdBackspaceAtStart: fcdBackspaceAtStart,
        fcdEstraiSelezione: fcdEstraiSelezione, fcdIncollaSottodocumento: fcdIncollaSottodocumento,
        fcdBlockText: fcdBlockText, fcdBullet: fcdBullet,
        fcdDisegnaFormule: fcdDisegnaFormule, parseTextForPreview: parseTextForPreview };
}
