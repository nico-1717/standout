# -*- coding: utf-8 -*-
"""Controlla che ogni pagina entri da lib/bootstrap.php e non da config.php.

Un solo punto d'ingresso (PROGETTO §4): se una pagina include ancora config.php
per conto suo, il giorno che si sposta qualcosa o si aggiunge un controllo comune
quella pagina resta indietro, e in silenzio. Fallisce se trova un require/include
di config.php fuori da lib/bootstrap.php.
Uso:  python3 tools/controlla_bootstrap.py
"""
import io, os, re, sys

guai = []
for radice in ('public', 'lib', 'tools'):
    for cartella, _, files in os.walk(radice):
        for nome in files:
            if not nome.endswith('.php'): continue
            p = os.path.join(cartella, nome)
            if p in ('lib/bootstrap.php', 'lib/config.php', 'lib/config.example.php'): continue
            for n, riga in enumerate(io.open(p, encoding='utf-8', errors='replace'), 1):
                if re.match(r'\s*(require|include)(_once)?\b.*config\.php', riga):
                    guai.append('%s:%d  %s' % (p, n, riga.strip()))
if guai:
    print('INCLUDONO ANCORA config.php (usare lib/bootstrap.php):')
    for g in guai: print('  -', g)
    sys.exit(1)
print('OK: nessun file include config.php direttamente')
