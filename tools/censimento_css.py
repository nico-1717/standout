#!/usr/bin/env python3
"""Censimento dei fogli di stile (PROGETTO §13.1): serve a decidere cosa spostare in
_assets/ui/base.css e quali pagine migrare per prime. Non modifica nulla.

Uso (dalla radice del progetto):
  python3 tools/censimento_css.py token  [N]   variabili dichiarate in piu' file, con i valori trovati
  python3 tools/censimento_css.py root         byte dei blocchi :root per file
  python3 tools/censimento_css.py classi [N]   classi definite in N o piu' file (componenti riscritti a mano)
  python3 tools/censimento_css.py usati        variabili usate ma non dichiarate in ciascun foglio
"""
import collections, glob, os, re, sys

RADICE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

def fogli():
    return sorted(f for f in glob.glob(RADICE + '/public/**/*.css', recursive=True) if '/vendor/' not in f)

def leggi(f):
    return re.sub(r'/\*.*?\*/', '', open(f, encoding='utf-8', errors='replace').read(), flags=re.S)

def rel(f):
    return os.path.relpath(f, RADICE + '/public')

def token(n):
    dich = collections.defaultdict(lambda: collections.defaultdict(set))
    for f in fogli():
        for m in re.finditer(r'(--[\w-]+)\s*:\s*([^;}]+)', leggi(f)):
            dich[m.group(1)][re.sub(r'\s+', ' ', m.group(2).strip())].add(rel(f))
    righe = sorted(((len(set().union(*v.values())), k, v) for k, v in dich.items()), key=lambda r: -r[0])
    for nf, k, v in righe[:n]:
        print(f'{k}  ({nf} file, {len(v)} valori)')
        for val, fs in sorted(v.items(), key=lambda kv: -len(kv[1]))[:4]:
            print(f'    {len(fs):2d}x  {val[:80]}')

def root():
    tot = 0
    for f in fogli():
        b = sum(len(x) for x in re.findall(r':root[^{]*\{[^{}]*\}', leggi(f)))
        if b:
            print(f'{rel(f):36s} {b:6d} B'); tot += b
    print('TOTALE', tot, 'B')

def classi(n):
    dove = collections.defaultdict(set)
    for f in fogli():
        for m in re.finditer(r'([^{}@][^{}]*)\{[^{}]*\}', leggi(f)):
            for sel in m.group(1).split(','):
                sel = sel.strip()
                if re.fullmatch(r'\.[a-zA-Z][\w-]*', sel):
                    dove[sel].add(rel(f))
    for sel, fs in sorted(dove.items(), key=lambda kv: -len(kv[1])):
        if len(fs) >= n:
            print(f'{sel:24s} {len(fs):3d} file')

def usati():
    for f in fogli():
        s = leggi(f)
        mancanti = sorted(set(re.findall(r'var\(\s*(--[\w-]+)', s)) - set(re.findall(r'(--[\w-]+)\s*:', s)))
        if mancanti:
            print(f'{rel(f):36s} {mancanti}')

if __name__ == '__main__':
    cmd = sys.argv[1] if len(sys.argv) > 1 else ''
    arg = int(sys.argv[2]) if len(sys.argv) > 2 else (40 if cmd == 'token' else 4)
    {'token': lambda: token(arg), 'root': root, 'classi': lambda: classi(arg), 'usati': usati}.get(cmd, lambda: print(__doc__))()
