# -*- coding: utf-8 -*-
"""Segnala gli script e i fogli di stile caricati da domini che la CSP blocca.

Nasce da un "Chart is not defined" su stats_fc.php: la pagina caricava Chart.js
da cdnjs.cloudflare.com, che la CSP del sito non consente. Il browser blocca il
file, la libreria non c'e', e il primo grafico lancia un errore che ferma tutto
il resto dello script: pagina vuota e numeri a zero.

Il difetto era gia' stato corretto una volta; e' tornato perche' a un certo punto
sono ripartito dal file originale e ho perso la correzione. Un controllo lo
prende ogni volta, una correzione a mano solo la prima.

I domini consentiti si leggono dalla CSP vera, che sta in security/security_core.php:
cosi' se un giorno la si cambia, questo strumento la segue senza doverlo ritoccare.

    python3 controlla_csp.py security_core.php *.php
"""
import io, re, sys, os

def domini_consentiti(percorso_csp):
    """Estrae i domini di script-src dalla CSP del sito."""
    try:
        t = io.open(percorso_csp, encoding='utf-8', newline='').read()
    except OSError:
        return None
    ok = {}
    for direttiva in ('script-src', 'style-src', 'font-src'):
        # ci si ferma al punto e virgola, non al primo apice: la direttiva
        # contiene 'self' e 'unsafe-inline', che di apici ne hanno parecchi
        m = re.search(direttiva + r"([^;]*)", t)
        domini = set()
        if m:
            for pezzo in m.group(1).split():
                if pezzo.startswith("'"):
                    continue          # 'self', 'unsafe-inline'...
                domini.add(pezzo.replace('https://', '').replace('http://', '').strip())
        ok[direttiva] = domini
    return ok if any(ok.values()) else None

def controlla(p, ok):
    t = io.open(p, encoding='utf-8', newline='').read()
    guai = []
    for m in re.finditer(r'<(script|link)[^>]*\b(?:src|href)="(https?://([^/"]+))[^"]*"', t):
        tag, dominio = m.group(1).lower(), m.group(3)
        # un <link> puo' essere un foglio di stile o un font: si accetta se sta
        # in una delle due direttive, senza pretendere di capire quale
        consentiti = ok['script-src'] if tag == 'script' else (ok['style-src'] | ok['font-src'])
        if dominio not in consentiti:
            riga = t[:m.start()].count('\n') + 1
            guai.append((riga, f"{dominio} (in un <{tag}>)"))
    if guai:
        print(f"{os.path.basename(p):26} dominio non consentito dalla CSP:")
        for r, d in guai:
            print(f"    riga {r}: {d}")
    return not guai

if __name__ == '__main__':
    ok = domini_consentiti(sys.argv[1])
    if ok is None:
        print("non riesco a leggere la CSP da", sys.argv[1]); sys.exit(2)
    for d, v in ok.items():
        print(f"{d}: {', '.join(sorted(v)) or 'solo self'}")
    esito = all([controlla(p, ok) for p in sys.argv[2:]])
    sys.exit(0 if esito else 1)
