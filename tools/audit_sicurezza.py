# -*- coding: utf-8 -*-
"""Elenco dei punti deboli, file per file, ordinato per gravita' (docs/STANDOUT_PROGETTO.md §7.2).

Cerca in TUTTI i file PHP di public/ e lib/ quello che il documento chiede:
  - query costruite per concatenazione / interpolazione
  - $_GET / $_POST / $_REQUEST usati senza passare da una sec_*
  - echo di variabili senza sec_h()
  - pagine senza controllo di accesso
  - UPDATE / DELETE senza user_id nella WHERE
  - scritture senza CSRF (e scritture raggiungibili con GET)
  - file caricati senza controllo del tipo vero
  - eval / system / exec / unserialize e simili
  - percorsi di file composti con dati esterni, redirect aperti, errori mostrati

NON e' una prova di sicurezza: e' un setaccio. Segnala dove guardare; ogni segnalazione
va letta da una persona. Una voce "ok" qui vuol dire "non ho trovato il difetto che so
cercare", non "e' sicuro".

Uso:
    python3 tools/audit_sicurezza.py                 # tutto, ordinato per gravita'
    python3 tools/audit_sicurezza.py --md > elenco.md
    python3 tools/audit_sicurezza.py public/fc/fc_game.php
Esce con 1 se ci sono voci CRITICA o ALTA (per poterlo usare come controllo prima di pubblicare).
"""
import io, os, re, sys, glob

RADICE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
GRAVITA = ['CRITICA', 'ALTA', 'MEDIA', 'BASSA']

# file che per natura non sono "pagine": librerie incluse da altri
def e_libreria(p):
    return p.startswith('lib/') or '/partials/' in p or p.startswith('tools/')

def leggi(p):
    return io.open(os.path.join(RADICE, p), encoding='utf-8', newline='').read().replace('\r\n', '\n')

def togli_commenti(s):
    """Toglie i commenti PHP/JS in blocco e di riga lasciando le righe al loro posto (cosi' i numeri tornano)."""
    def blanca(m):
        return re.sub(r'[^\n]', ' ', m.group(0))
    s = re.sub(r'/\*[\s\S]*?\*/', blanca, s)
    # commenti di riga: solo se // o # sono a inizio riga o dopo spazi (evita https://)
    s = re.sub(r'(?m)^[ \t]*(?://|#(?!\[)).*$', lambda m: ' ' * len(m.group(0)), s)
    return s

def riga_di(s, pos):
    return s.count('\n', 0, pos) + 1

# ---------- elementi riconosciuti come "sicuri per costruzione" ----------
CAST = r'(?:\(int\)|\(float\)|intval\(|floatval\(|sec_int\(|sec_float\(|sec_enum\(|sec_bool\(|sec_date\(|sec_time\(|sec_color\(|count\(|strlen\(|mysqli_insert_id|sec_insert_id|time\(\)|date\(|random_int\(|abs\()'
# nomi di variabile che nel progetto contengono solo segnaposto "?, ?, ?" o nomi scelti da elenchi chiusi
VAR_SICURE = re.compile(r'^(?:ph\w*|\w*_ph\d*|segn\w*|\w*segnapost\w*|placeholders?|marks|col\w*|order_by|sql_order|where\w*|filtro\w*|clausole|assign|set_sql|CC|CCMAP|T|tab\w*|table|tbl|p[SV]|corsoCol|soloFoglie|in|ids_sql|list|inlist)$')
SORGENTI = re.compile(r'\$_(?:GET|POST|REQUEST|COOKIE|FILES|SERVER)\b|\bsec_in\(|\bsec_body\(\)|\$input\b|\$body\b|\$data\b|\$payload\b|\$_SESSION')

def assegnazioni(s, nome):
    """Tutte le espressioni assegnate a $nome nel file (approssimazione)."""
    out = []
    for m in re.finditer(r'\$' + re.escape(nome) + r'\s*(?:\.|\+|\?\?)?=\s*([^;]+);', s):
        out.append(m.group(1).strip())
    # foreach (... as $nome) e foreach (... as $k => $nome)
    for m in re.finditer(r'foreach\s*\(([^)]*?)\bas\s+(?:\$\w+\s*=>\s*)?\$' + re.escape(nome) + r'\b', s):
        out.append('FOREACH ' + m.group(1))
    return out

def e_sicura_espr(espr):
    e = espr.strip()
    if re.match(r'^' + CAST, e):
        return True
    if re.match(r'^\(int\)|^\(float\)|^[0-9.\-]+$|^["\'][^"\']*["\']$|^null$|^true$|^false$|^\[\]$', e):
        return True
    if re.search(r"implode\(\s*['\"],\s*['\"]\s*,\s*array_map\(\s*['\"]intval", e):
        return True
    if re.search(r'str_repeat\(', e):
        return True
    if re.match(r'^(?:fc_t\(|FC_TABELLE\[)', e):
        return True
    return False

def valuta_variabile(s, nome, profondita=0):
    """'sicura' | 'esterna' | 'incerta' per una variabile interpolata in una query."""
    if VAR_SICURE.match(nome):
        return 'sicura'
    ass = assegnazioni(s, nome)
    if not ass:
        return 'incerta'     # parametro di funzione o variabile d'ambiente
    esito = 'sicura'
    for a in ass:
        if a.startswith('FOREACH'):
            # un ciclo su valori gia' casted e' sicuro; su dati grezzi no: si guarda l'array
            if re.search(CAST, a) or re.search(r'array_map\(\s*[\'"]intval', a):
                continue
            esito = peggiore(esito, 'incerta')
            continue
        if e_sicura_espr(a):
            continue
        if SORGENTI.search(a) and not re.search(CAST, a):
            return 'esterna'
        # espressione composta da altre variabili: si guarda in profondita'
        sotto = re.findall(r'\$(\w+)', a)
        if sotto and profondita < 3 and not re.search(r'\bfunction\b', a):
            for v in sotto:
                if v == nome: continue
                ev = valuta_variabile(s, v, profondita + 1)
                if ev == 'esterna': return 'esterna'
                if ev == 'incerta': esito = peggiore(esito, 'incerta')
        else:
            esito = peggiore(esito, 'incerta')
    return esito

def peggiore(a, b):
    ordine = ['sicura', 'incerta', 'esterna']
    return a if ordine.index(a) >= ordine.index(b) else b

# ---------- le regole ----------
def analizza(p):
    s = leggi(p)
    nudo = togli_commenti(s)
    trovati = []   # (gravita, regola, riga, dettaglio)

    def segnala(g, regola, pos, dettaglio):
        trovati.append((g, regola, riga_di(s, pos) if isinstance(pos, int) else pos, dettaglio.strip()[:170]))

    # ---- 1. QUERY CON VALORI INCOLLATI ----
    # funzioni che eseguono SQL: la stringa e' il primo argomento (o il secondo per mysqli_query)
    for m in re.finditer(r'(?:sec_q|sec_one|sec_all|mysqli_query|mysqli_prepare|->query|->prepare|->exec)\s*\(\s*(?:\$\w+\s*,\s*)?("(?:[^"\\]|\\.)*"|\'(?:[^\'\\]|\\.)*\')', nudo, re.S):
        testo = m.group(1)
        if testo[0] == "'":
            continue                # le virgolette singole non interpolano
        interp = set(re.findall(r'(?<!\\)\{?\$(\w+)', testo))
        interp |= set(re.findall(r'\{\$(\w+)', testo))
        interp -= {'this'}
        peg = 'sicura'; cattive = []
        for v in sorted(interp):
            ev = valuta_variabile(nudo, v)
            if ev == 'esterna':
                peg = 'esterna'; cattive.append(v + ' (da dati esterni)')
            elif ev == 'incerta':
                peg = peggiore(peg, 'incerta'); cattive.append(v)
        if re.search(r'\{\$T\[|\{\$\w+\[|fc_t\(', testo):
            pass
        if peg == 'esterna':
            segnala('CRITICA', 'SQL: valore esterno incollato nella query', m.start(), ', '.join(cattive) + '  →  ' + re.sub(r'\s+', ' ', testo[:90]))
        elif peg == 'incerta' and cattive:
            segnala('MEDIA', 'SQL: variabile incollata nella query (origine da verificare)', m.start(), ', '.join(cattive) + '  →  ' + re.sub(r'\s+', ' ', testo[:90]))
    # concatenazione con punto: "... WHERE id = " . $x
    for m in re.finditer(r'(?:sec_q|sec_one|sec_all|mysqli_query)\s*\(\s*(?:\$\w+\s*,\s*)?(?:"[^"\n]*"|\'[^\'\n]*\')\s*\.\s*(\$\w+|\w+\()', nudo):
        v = m.group(1)
        if v.endswith('(') and (v.startswith('implode') or v.startswith('fc_t')): continue
        if v.startswith('$') and valuta_variabile(nudo, v[1:]) == 'sicura': continue
        segnala('MEDIA', 'SQL: query costruita per concatenazione', m.start(), re.sub(r'\s+', ' ', m.group(0)[:110]))

    # API vecchia: mysqli_query senza prepared statement (non e' di per se' un buco, ma e' la via per cui entra)
    n_vecchie = len(re.findall(r'\bmysqli_query\s*\(', nudo))
    if n_vecchie:
        segnala('BASSA', 'API vecchia: mysqli_query (da portare a sec_*)', nudo.find('mysqli_query'), f'{n_vecchie} chiamate')

    # ---- 2. INGRESSI NON VALIDATI ----
    for m in re.finditer(r'\$_(GET|POST|REQUEST)\s*\[\s*[\'"](\w+)[\'"]\s*\]', nudo):
        # contesto: la riga intera
        i0 = nudo.rfind('\n', 0, m.start()) + 1
        i1 = nudo.find('\n', m.end())
        riga = nudo[i0:i1 if i1 != -1 else len(nudo)]
        if re.search(r'sec_(?:int|str|text|enum|bool|date|time|color|float|email|key|in)\(|\(int\)|\(float\)|intval\(|isset\(|empty\(|array_key_exists|is_array|in_array\(|\?\?\s*[\'"\d\[]|==|!=|!\$_|\(bool\)|sec_h\(|strlen\(|ctype_|filter_var', riga):
            continue
        segnala('MEDIA', 'Ingresso usato senza validazione sec_*', m.start(), riga)
    # l'ingresso grezzo stampato a schermo: XSS riflesso
    for m in re.finditer(r'(?:echo|print|<\?=)\s*[^;\n]*\$_(?:GET|POST|REQUEST)\b[^;\n]*', nudo):
        if re.search(r'sec_h\(|htmlspecialchars|json_encode|\(int\)|intval', m.group(0)): continue
        segnala('ALTA', 'XSS riflesso: ingresso stampato senza sec_h()', m.start(), m.group(0))

    # ---- 3. OUTPUT SENZA ESCAPE ----
    for m in re.finditer(r'<\?(?:php\s+echo|=)\s+([^;?]{1,160})[;\s]*\?>', nudo):
        e = m.group(1).strip()
        if re.match(r'^(?:sec_h|te|th|t|json_encode|standout_i18n_json|\(int\)|\(float\)|intval|number_format|count|date|time|pwa_head_html|urlencode|rawurlencode|sec_csrf_token|@filemtime|filemtime|htmlspecialchars|round|floor|ceil|implode|max|min|abs|PWA_BASE|[A-Z][A-Z0-9_]*\b)', e):
            continue
        if e.startswith('$') or re.match(r'^[a-z_]\w*\(', e):
            # una variabile o una funzione nostra: puo' essere gia' escapata a monte
            if re.match(r'^\$\w*(?:Esc|Note|esc|html|Html|json|Json|safe|id|Id|n|num|count|tot|pct)\b', e): continue
            segnala('MEDIA', 'Output senza sec_h()', m.start(), e)
    for m in re.finditer(r'\becho\s+([^;\n]{1,200});', nudo):
        e = m.group(1).strip()
        if not re.search(r'\$\w', e): continue
        if re.search(r'sec_h\(|htmlspecialchars|json_encode|\(int\)|\(float\)|intval|number_format|sec_json|sec_csrf_token|\bt\(|\bte\(|round\(|count\(|date\(|PWA_BASE', e):
            # se ci sono anche variabili nude fuori dalle funzioni sicure, e' da guardare
            resto = re.sub(r'(?:sec_h|htmlspecialchars|json_encode|intval|number_format|t|te|round|count|date)\((?:[^()]|\([^()]*\))*\)|\(int\)\s*\$\w+(?:\[[^\]]*\])*|\(float\)\s*\$\w+(?:\[[^\]]*\])*', '', e)
            if not re.search(r'\$\w', resto): continue
        if re.match(r'^[\'"]\s*[<{\[]?\s*[\'"]$', e): continue
        segnala('BASSA', 'echo di variabile (verificare l\'escape)', m.start(), e)

    # ---- 4. ACCESSO ----
    if not e_libreria(p) and p.startswith('public/') and not p.startswith('public/_assets'):
        usa_auth = re.search(r'auth_check(?:_api)?\.php|auth_core\.php|standout_utente_corrente\(|fr_utente_corrente\(', nudo)
        include_da_altri = bool(re.search(r'^public/(?:partials)/', p))
        if not usa_auth and not include_da_altri:
            segnala('ALTA', 'Nessun controllo di accesso (non include auth_check/auth_core)', 1, 'verificare se la pagina e\' pubblica per scelta')
        elif usa_auth and re.search(r'auth_core\.php', nudo) and not re.search(r'auth_check(?:_api)?\.php', nudo) and not re.search(r'standout_utente_corrente\(|fr_utente_corrente\(|\$_SESSION\[\s*[\'"]user_id', nudo):
            segnala('MEDIA', 'Include solo auth_core.php e non controlla mai chi e\' l\'utente', 1, '')

    # ---- 5. SCRITTURE ----
    scrive = bool(re.search(r'\b(?:INSERT\s+(?:IGNORE\s+)?INTO|UPDATE\s+`?\w+`?\s+SET|DELETE\s+FROM|REPLACE\s+INTO)\b', nudo, re.I))
    ha_guardia = bool(re.search(r'sec_guard_write\(|sec_require_csrf\(|standout_csrf_verifica\(|sec_csrf_ok\(', nudo))
    if scrive and not ha_guardia and not e_libreria(p):
        segnala('ALTA', 'Scrive nel database senza CSRF (sec_guard_write/sec_require_csrf)', 1, 'cercare chi chiama questo file')
    # scritture raggiungibili con GET: il controllo CSRF salta i GET
    if scrive and not e_libreria(p):
        az_get = re.search(r'\$_GET\[\s*[\'"](?:action|azione|op|cmd)[\'"]\s*\]', nudo) or re.search(r"sec_in\(\s*['\"](?:action|azione)['\"]", nudo)
        solo_post = re.search(r"REQUEST_METHOD['\"]?\]?\s*(?:!==|!=|===|==)\s*['\"]POST", nudo)
        if az_get and not solo_post:
            segnala('MEDIA', 'Azione scelta da GET in un file che scrive (CSRF non protegge i GET)', az_get.start(), az_get.group(0))

    # ---- 6. UPDATE / DELETE SENZA user_id ----
    for m in re.finditer(r'(?:sec_q|mysqli_query|mysqli_prepare)\s*\(\s*(?:\$\w+\s*,\s*)?("(?:[^"\\]|\\.)*"|\'(?:[^\'\\]|\\.)*\')', nudo, re.S):
        q = re.sub(r'\s+', ' ', m.group(1)).strip('"\'')
        if re.match(r'\s*(?:UPDATE|DELETE)\b', q, re.I) and not re.search(r'user_id|owner_id|u\.id|\bfrom_user_id\b|\bto_user_id\b|\buser_id_a\b|login_attempts|rate_limits|remember_tokens|WHERE\s+id\s*=\s*\?\s*$', q, re.I):
            segnala('MEDIA', 'UPDATE/DELETE senza user_id nella WHERE (serve un controllo di proprieta\' prima)', m.start(), q[:110])

    # ---- 7. FILE CARICATI ----
    if re.search(r'\$_FILES|move_uploaded_file', nudo):
        if not re.search(r'finfo_|sec_file\(|getimagesize|mime_content_type', nudo):
            segnala('ALTA', 'Upload senza controllo del tipo reale (finfo/sec_file)', nudo.find('$_FILES') if '$_FILES' in nudo else 1, '')
        if re.search(r'\$_FILES\[[^\]]+\]\[\s*[\'"]name[\'"]\s*\]', nudo) and not re.search(r'sec_safe_filename|random_bytes|uniqid|bin2hex', nudo):
            segnala('ALTA', 'Upload: nome del file scelto dal client', nudo.find("['name']"), '')
        if re.search(r'move_uploaded_file', nudo) and not re.search(r'imagecreate|imagewebp|imagejpeg|imagepng|Imagick|sec_file\(', nudo):
            segnala('MEDIA', 'Upload: il file viene salvato cosi\' com\'e\' (le immagini andrebbero rigenerate, §7.5)', nudo.find('move_uploaded_file'), '')

    # ---- 8. FUNZIONI PERICOLOSE ----
    for m in re.finditer(r'\b(eval|system|exec|shell_exec|passthru|popen|proc_open|pcntl_exec|assert)\s*\(', nudo):
        segnala('CRITICA', f'Funzione pericolosa: {m.group(1)}()', m.start(), nudo[m.start():m.start() + 90].split('\n')[0])
    for m in re.finditer(r'\bunserialize\s*\(([^)]*)\)', nudo):
        gr = 'CRITICA' if SORGENTI.search(m.group(1)) else 'ALTA'
        segnala(gr, 'unserialize() su dati', m.start(), m.group(0))
    for m in re.finditer(r'\b(?:include|require)(?:_once)?\s*\(?\s*([^;]+);', nudo):
        arg = m.group(1)
        if SORGENTI.search(arg) or re.search(r'\$_', arg):
            segnala('CRITICA', 'include/require con dati esterni', m.start(), m.group(0))
    for m in re.finditer(r'\b(md5|sha1|crypt)\s*\(([^)]*)\)', nudo):
        if re.search(r'pass|pwd|secret|token', m.group(2), re.I):
            segnala('ALTA', f'{m.group(1)}() su password/token', m.start(), m.group(0))
    for m in re.finditer(r'\b(?:mt_rand|rand|uniqid|lcg_value)\s*\(', nudo):
        i0 = nudo.rfind('\n', 0, m.start()) + 1
        riga = nudo[i0:nudo.find('\n', m.end())]
        if re.search(r'token|secret|csrf|selector|session|password|nonce|key', riga, re.I):
            segnala('ALTA', 'Valore casuale debole usato per un segreto', m.start(), riga)

    # ---- 9. PERCORSI DI FILE ----
    for m in re.finditer(r'\b(unlink|file_put_contents|rename|copy|mkdir|rmdir|fopen|file_get_contents|readfile|file|scandir|glob|touch)\s*\(\s*([^,)]+)', nudo):
        arg = m.group(2)
        if not re.search(r'\$', arg): continue
        if re.search(r'https?://', arg): continue
        if re.search(r'basename\(|realpath\(|sec_safe_filename|__DIR__\s*\.\s*[\'"][^$]*[\'"]\s*$', arg): continue
        if re.match(r'^\s*\$(?:tmp|tmpFile|tmp_name)\b', arg): continue
        # l'argomento e' una variabile: si guarda se viene da fuori
        vs = re.findall(r'\$(\w+)', arg)
        esterno = False; incerto = False
        for v in vs:
            if v in ('dir', 'cartella', 'path_base', 'base'): continue
            r = valuta_variabile(nudo, v)
            if r == 'esterna': esterno = True
            elif r == 'incerta': incerto = True
        if esterno:
            segnala('ALTA', f'{m.group(1)}(): percorso composto con dati esterni (path traversal)', m.start(), m.group(0).strip())
        elif incerto and m.group(1) in ('unlink', 'file_put_contents', 'rename', 'copy', 'rmdir'):
            segnala('MEDIA', f'{m.group(1)}() su un percorso variabile (origine da verificare)', m.start(), m.group(0).strip())

    # ---- 10. RETE: SSRF E REDIRECT ----
    for m in re.finditer(r'curl_init\s*\(\s*([^)]*)\)|curl_setopt\s*\([^,]+,\s*CURLOPT_URL\s*,\s*([^)]+)\)|file_get_contents\s*\(\s*(\$\w+|[\'"]https?:[^)]*\$[^)]*)\)', nudo):
        arg = (m.group(1) or m.group(2) or m.group(3) or '')
        if re.search(r'\$', arg) and not re.search(r'\$(?:url|endpoint)\s*$', arg):
            vs = re.findall(r'\$(\w+)', arg)
            if any(valuta_variabile(nudo, v) == 'esterna' for v in vs):
                segnala('ALTA', 'Richiesta in uscita verso un indirizzo scelto dall\'utente (SSRF)', m.start(), m.group(0)[:110])
    for m in re.finditer(r'header\s*\(\s*[\'"]Location:\s*[\'"]?\s*\.?\s*([^)]*)\)', nudo):
        arg = m.group(1)
        if SORGENTI.search(arg) or re.search(r'\$_(?:GET|POST|SERVER\[.(?:HTTP_REFERER|HTTP_HOST))', arg):
            segnala('MEDIA', 'Redirect verso un indirizzo preso dalla richiesta (open redirect)', m.start(), m.group(0)[:110])

    # ---- 11. ERRORI E DEBUG ----
    for m in re.finditer(r'(?:display_errors|error_reporting)\s*[(=]|\bphpinfo\s*\(|\bvar_dump\s*\(|\bprint_r\s*\(|\bdebug_print_backtrace\s*\(', nudo):
        segnala('BASSA', 'Debug/errori a schermo', m.start(), m.group(0))
    for m in re.finditer(r'(?:echo|die|exit|sec_die_json|sec_json|json_encode)\s*\([^;]*?(?:getMessage\(\)|mysqli_error\(|mysqli_connect_error\(|->error\b|getTraceAsString)', nudo):
        segnala('MEDIA', 'Messaggio d\'errore tecnico mostrato all\'utente', m.start(), re.sub(r'\s+', ' ', m.group(0)[:110]))
    for m in re.finditer(r'(?:echo|print)\s+[^;]*?(?:getMessage\(\)|mysqli_error\()', nudo):
        segnala('MEDIA', 'Messaggio d\'errore tecnico mostrato all\'utente', m.start(), re.sub(r'\s+', ' ', m.group(0)[:110]))

    # ---- 12. SEGRETI NEL CODICE ----
    for m in re.finditer(r'(AIza[0-9A-Za-z_\-]{30,}|sk-[A-Za-z0-9_\-]{20,}|sk_live_[0-9a-zA-Z]{10,}|ghp_[0-9A-Za-z]{30,}|-----BEGIN (?:RSA |EC )?PRIVATE KEY-----|xox[baprs]-[0-9A-Za-z\-]{10,})', s):
        segnala('CRITICA', 'Chiave o segreto scritto nel codice', m.start(), m.group(1)[:12] + '…')
    for m in re.finditer(r'(?i)\b(?:password|passwd|secret|api_?key|token)\w*\s*(?:=>|=)\s*[\'"]([^\'"$]{8,})[\'"]', nudo):
        if re.search(r'(?i)example|esempio|placeholder|your|xxx|\*\*\*|test|change', m.group(1)): continue
        segnala('ALTA', 'Possibile segreto scritto nel codice', m.start(), m.group(0)[:80])

    # ---- 13. SESSIONE ----
    if re.search(r'\$_SESSION\[\s*[\'"]user_id[\'"]\s*\]\s*=', nudo) and not p.endswith('auth_core.php'):
        segnala('MEDIA', 'La sessione viene impostata fuori da auth_core.php', nudo.find("['user_id']"), '')

    # ---- 14. CSP: JavaScript e stile dentro le pagine ----
    inline_on = len(re.findall(r'\son(?:click|change|input|submit|load|error|keydown|keyup|focus|blur|mouseover)\s*=\s*["\']', nudo))
    if inline_on:
        segnala('BASSA', 'JavaScript negli attributi (onclick...): blocca la CSP senza unsafe-inline', 1, f'{inline_on} attributi')
    return trovati

def elenco_file():
    out = []
    for base in ('public', 'lib'):
        for r, _, fs in os.walk(os.path.join(RADICE, base)):
            for f in fs:
                if f.endswith('.php'):
                    out.append(os.path.relpath(os.path.join(r, f), RADICE).replace(os.sep, '/'))
    return sorted(out)

def controlli_di_struttura():
    """Cose che non stanno in un file solo: cartelle, .htaccess, segreti nel repository."""
    trovati = []
    import subprocess
    try:
        tracciati = subprocess.check_output(['git', '-C', RADICE, 'ls-files'], text=True).split('\n')
    except Exception:
        tracciati = []
    for f in tracciati:
        if re.search(r'(?:^|/)(?:config\.php|\.env|.*\.pem|.*\.key|id_rsa|.*\.sqlite|.*\.bak|.*\.old|.*~|.*\.zip|.*\.tar\.gz)$', f) and not f.endswith('config.example.php'):
            trovati.append(('CRITICA', 'File sensibile nel repository', f, 'togliere dal versionamento e cambiare le chiavi'))
    # nessun file eseguibile raggiungibile dentro media/ o uploads
    for f in tracciati:
        if f.startswith('media/') and f.endswith('.php'):
            trovati.append(('CRITICA', 'PHP dentro la cartella dei file caricati', f, ''))
    # .htaccess: lib/, sql/, data/ non devono essere raggiungibili
    esterni_pubblici = [d for d in ('lib', 'sql', 'data', 'tools', 'docs') if os.path.isdir(os.path.join(RADICE, 'public', d))]
    for d in esterni_pubblici:
        trovati.append(('ALTA', 'Cartella interna dentro public/', 'public/' + d, 'raggiungibile dal browser'))
    return trovati

def main():
    md = '--md' in sys.argv
    args = [a for a in sys.argv[1:] if not a.startswith('--')]
    files = args or elenco_file()
    per_file = {}
    for p in files:
        try:
            t = analizza(p)
        except Exception as ex:
            t = [('MEDIA', 'Il verificatore non e\' riuscito a leggere il file', 1, str(ex))]
        if t: per_file[p] = t
    struttura = [] if args else controlli_di_struttura()

    # raggruppa per regola, per gravita'
    tutte = []
    for p, ts in per_file.items():
        for g, r, ln, d in ts:
            tutte.append((g, r, p, ln, d))
    for g, r, f, d in struttura:
        tutte.append((g, r, f, 0, d))

    if md:
        print('# Elenco dei punti deboli (generato da tools/audit_sicurezza.py)\n')
    totali = {g: sum(1 for t in tutte if t[0] == g) for g in GRAVITA}
    print(('## Riepilogo\n' if md else 'RIEPILOGO'))
    for g in GRAVITA:
        print(f'- {g}: {totali[g]}' if md else f'  {g:8} {totali[g]}')
    print()
    for g in GRAVITA:
        voci = [t for t in tutte if t[0] == g]
        if not voci: continue
        print(f'## {g}\n' if md else f'\n===== {g} ({len(voci)}) =====')
        regole = {}
        for t in voci: regole.setdefault(t[1], []).append(t)
        for r, ts in sorted(regole.items(), key=lambda kv: -len(kv[1])):
            print(f'\n### {r}  ({len(ts)})\n' if md else f'\n-- {r}  ({len(ts)})')
            for _, _, f, ln, d in sorted(ts, key=lambda x: (x[2], x[3])):
                print((f'- `{f}:{ln}` {d}' if md else f'   {f}:{ln}  {d}'))
    gravi = totali['CRITICA'] + totali['ALTA']
    sys.exit(1 if gravi else 0)

if __name__ == '__main__':
    main()
