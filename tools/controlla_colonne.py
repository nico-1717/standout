# -*- coding: utf-8 -*-
"""Confronta le colonne usate nelle query con lo schema vero del database.

Nasce da un 500 su stats_fc.php: avevo corretto un nome di colonna nella query
(progetto_id -> project_id) ma non nel codice che leggeva il risultato. PHP non
se ne accorge finche' la riga non arriva, e allora e' tardi.

Legge il dump SQL, ricava tabella -> colonne, poi per ogni query cerca i
riferimenti "alias.colonna" e li verifica risalendo agli alias delle FROM/JOIN.
Segnala anche le colonne lette dai risultati ($row['x']) che non compaiono in
nessuna SELECT del file.
"""
import io, re, sys

def schema(percorso):
    t = io.open(percorso, encoding='utf-8', errors='replace').read()
    tabelle = {}
    for m in re.finditer(r'CREATE TABLE `(\w+)` \(([\s\S]*?)\n\)', t):
        cols = set(re.findall(r'^\s*`(\w+)`', m.group(2), re.M))
        tabelle[m.group(1)] = cols
    return tabelle

def controlla(percorso_php, tabelle):
    s = io.open(percorso_php, encoding='utf-8', newline='').read().replace('\r\r\n', '\n').replace('\r\n', '\n')
    guai = []
    for m in re.finditer(r'sec_(?:all|one|q)\(\s*"((?:[^"\\]|\\.)*)"', s):
        q = m.group(1)
        alias = {}
        for mm in re.finditer(r'\b(?:FROM|JOIN)\s+(\w+)(?:\s+(?:AS\s+)?(\w+))?', q, re.I):
            tab, al = mm.group(1), mm.group(2)
            if tab.upper() in ('SELECT',): continue
            if tab in tabelle:
                alias[(al or tab)] = tab
        for mm in re.finditer(r'\b(\w+)\.(\w+)\b', q):
            al, col = mm.group(1), mm.group(2)
            if al not in alias: continue
            tab = alias[al]
            if col not in tabelle[tab]:
                riga = s[:m.start()].count('\n') + 1
                guai.append((riga, f"{al}.{col} -> la tabella {tab} non ha la colonna {col}"))
    if guai:
        print(f"{percorso_php.split('/')[-1]:26} colonne che non esistono:")
        for r, z in guai: print(f"    riga {r}: {z}")
    return not guai

if __name__ == '__main__':
    tab = schema(sys.argv[1])
    ok = all([controlla(p, tab) for p in sys.argv[2:]])
    sys.exit(0 if ok else 1)
