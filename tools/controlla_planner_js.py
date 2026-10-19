# -*- coding: utf-8 -*-
"""Controlla la sintassi del motore del planner.

Il JavaScript del planner sta in public/planner/js/, in 13 parti numerate che
da sole NON sono JavaScript valido: la funzione unica si apre nella prima e si
chiude nell'ultima (le unisce public/planner/planner_js.php). Quindi `node
--check` su una parte sola darebbe sempre errore. Questo script fa quello che fa
il server, le unisce nello stesso ordine, e controlla il risultato.

Uso:  python tools/controlla_planner_js.py      (serve node nel PATH)
"""
import glob, os, subprocess, sys, tempfile

QUI = os.path.dirname(os.path.abspath(__file__))
CARTELLA = os.path.join(QUI, '..', 'public', 'planner', 'js')
parti = sorted(glob.glob(os.path.join(CARTELLA, '[0-9][0-9]_*.js')))
if not parti:
    sys.exit('Nessuna parte trovata in ' + CARTELLA)

testo = '\n'.join(open(p, encoding='utf-8').read() for p in parti)
with tempfile.NamedTemporaryFile('w', suffix='.js', delete=False, encoding='utf-8') as f:
    f.write(testo)
    nome = f.name
try:
    r = subprocess.run(['node', '--check', nome], capture_output=True, text=True)
finally:
    os.unlink(nome)

if r.returncode:
    print(r.stderr)
    sys.exit('ERRORE di sintassi (riga riferita al file unito, %d parti)' % len(parti))
print('ok: %d parti, %d righe, sintassi valida' % (len(parti), testo.count('\n') + 1))
