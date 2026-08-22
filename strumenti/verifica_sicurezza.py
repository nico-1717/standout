# -*- coding: utf-8 -*-
"""Controlla che una pagina rispetti lo standard di SICUREZZA.md.

Ogni voce corrisponde a un capitolo del documento. Serve a poter dire "questa
pagina e' a norma" con una misura invece che a memoria - e a ricontrollarlo
gratis dopo ogni modifica futura.
"""
import io, re, sys

def controlla(percorso):
    s = io.open(percorso, encoding='utf-8', newline='').read().replace('\r\n', '\n')
    php = s[:s.index('<body>')] if '<body>' in s else s
    # I commenti si togliono prima di cercare: "real_escape_string" citato in un
    # commento non e' una chiamata, e un verificatore che segnala il proprio
    # commento insegna a ignorarlo.
    php_nudo = re.sub(r'/\*[\s\S]*?\*/', '', php)
    php_nudo = re.sub(r'(?m)^\s*(?://|#).*$', '', php_nudo)
    nome = percorso.split('/')[-1]
    print(f"\n{nome}")
    ok = True

    def voce(etichetta, condizione, dettaglio=''):
        nonlocal ok
        ok &= condizione
        print(f"  [{'ok' if condizione else 'NO'}] {etichetta}{(' — ' + dettaglio) if dettaglio and not condizione else ''}")

    # §3 iniezione SQL
    vecchie = re.findall(r'mysqli_query|mysqli_fetch_assoc|real_escape_string', php_nudo)
    voce("prepared statement ovunque", not vecchie, f"{len(vecchie)} residui")
    interpolate = [m.group(1) for m in re.finditer(r'sec_(?:q|one|all)\(\s*"((?:[^"\\]|\\.)*)"', php, re.S)
                   if re.search(r'\$\w+', m.group(1)) # $ph, $segn, $segnaposto contengono "?, ?, ?" - sono segnaposto, non valori;
    # $col e $filtro sono nomi di colonna/clausole scelti da un if chiuso
    and not re.search(r'\$(?:col\w*|\w*ph\d?|ph\w*|assign|segn\w*|gruppi|clausole|filtro\w*|where\w*|order_by|sql_order|cat_filter|CC|CCMAP)\b', m.group(1))]
    voce("nessun valore incollato nella query", not interpolate, str(interpolate[:2]))

    # §4 rivalidazione lato server
    grezzi = re.findall(r'(?:intval|floatval|trim)\(\s*\$_(?:POST|GET)', php_nudo)
    voce("ingressi rivalidati con sec_*", not grezzi, f"{len(grezzi)} letture non validate")

    # §4 controllo di proprieta'
    # sec_owns ha senso solo dove un id arriva da fuori: una pagina che legge
    # soltanto i propri dati partendo dalla sessione non ha niente da verificare.
    prende_id = bool(re.search(r"\$_(?:POST|GET)\[\s*'[^']*(?:id|ids)'", php_nudo))
    # sec_owns() vale quando la riga ha una colonna user_id. Dove la proprieta'
    # e' indiretta (una scheda appartiene all'utente attraverso la categoria) la
    # verifica e' una query con la JOIN e "c.user_id = ?": vale lo stesso, e
    # pretendere sec_owns costringerebbe a scriverla in un modo che non funziona.
    verifica = (len(re.findall(r'sec_owns\(|_miei\(|_solo_miei\(', php_nudo)) > 0
                or re.search(r'user_id\s*=\s*\?', php_nudo) is not None)
    voce("proprieta' verificata dove arrivano id", (not prende_id) or verifica)

    # §4 output
    nudi = []
    # il ";" prima di "?>" e' facoltativo in PHP: senza questa alternativa gli
    # echo scritti "<?php echo $x ?>" sfuggivano al controllo
    for m in re.finditer(r'<\?(?:php\s+echo|=)\s+([^;?]{1,120})[;\s]*\?>', s):
        e = m.group(1).strip()
        if e.startswith(('sec_h(', 'te(', 'th(', 'nisama_i18n_json', 'json_encode',
                         '(int)', '(float)', 'pwa_head_html')): continue
        # Funzioni che fanno l'escape al loro interno: si dichiarano una volta
        # sola qui invece di aggiungere un secondo sec_h a ogni chiamata, che
        # trasformerebbe "&amp;" in "&amp;amp;" a schermo.
        # urlencode/rawurlencode producono solo caratteri innocui in un attributo:
        # un sec_h dopo raddoppierebbe le entita' senza guadagnare niente
        if re.match(r'^(?:urlencode|rawurlencode)\(', e): continue
        if re.match(r'^(?:eng_text|eng_search|eng_example_text|pwa_head_html|hb_nome_categoria|pl_nome_tradotto|badgeIconSvg|sec_csrf_token)\(', e): continue
        # le costanti definite in config.php sono valori nostri, non dati
        # dell'utente: PWA_BASE, IMAGE_PATH_VIEW e simili non vanno filtrate
        if re.match(r'^[A-Z][A-Z0-9_]*$', e): continue
        # Espressioni composte: basta che OGNI pezzo che stampa una variabile
        # passi da sec_h. "IMAGE_PATH_VIEW . '/' . sec_h($x)" e' a posto, e
        # guardare solo l'inizio la segnalerebbe a torto.
        pezzi = [p.strip() for p in re.split(r'(?<![\w)])\.(?![\w(])', e)]
        if len(pezzi) > 1 and all(
                p.startswith(('sec_h(', 't(', '(int)', '(float)'))
                or re.match(r"^'[^']*'$|^\"[^\"]*\"$", p)
                or re.match(r'^[A-Z][A-Z0-9_]*$', p)
                for p in pezzi if p):
            continue
        if re.match(r'^intval\(|^number_format\(|^count\(|^@filemtime|^time\(\)|^date\(', e): continue
        if re.match(r'^\$\w*(?:Esc|Note)\b', e): continue   # gia' passati da sec_h all'assegnazione
        nudi.append(e[:50])
    voce("output sempre da sec_h()", not nudi, str(nudi[:3]))

    # §5 CSRF + §7 limiti di frequenza
    # Un file "scrive" se tocca il database con INSERT/UPDATE/DELETE, non se legge
    # $_POST: game_fc.php faceva dieci scritture con azioni passate via GET, e
    # cercando solo $_POST il controllo non le vedeva.
    scrive = bool(re.search(r'sec_q\(\s*["\']\s*(?:INSERT|UPDATE|DELETE|REPLACE)', php_nudo, re.I))
    voce("scritture protette da sec_guard_write()",
         (not scrive) or len(re.findall(r'sec_guard_write\(', php_nudo)) > 0)

    # §6 nessuno <style> inline (serve per togliere 'unsafe-inline' dalla CSP)
    # "<styleSheet" e' un tag dei file .xlsx generati, non un blocco di stile:
    # si guarda che dopo "<style" ci sia > o uno spazio.
    # i commenti PHP si togliono anche qui: la parola "<style>" citata in un
    # commento non e' un blocco di stile
    senza_commenti = re.sub(r'/\*[\s\S]*?\*/', '', s)
    stili = len(re.findall(r'<style(?=[ >])', senza_commenti))
    voce("nessun blocco di stile nella pagina", stili == 0, f"{stili} blocchi")

    return ok

if __name__ == '__main__':
    tutte = all(controlla(p) for p in sys.argv[1:])
    print("\n" + ("Tutte a norma." if tutte else "Ci sono voci da sistemare."))
    sys.exit(0 if tutte else 1)
