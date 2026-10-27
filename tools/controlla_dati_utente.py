# -*- coding: utf-8 -*-
"""Controlla che ogni tabella dello schema sia coperta da "scarica i miei dati" e
"elimina il mio account" (lib/account_dati.php).

Nasce dal rischio opposto a un 500: nessun errore, ma una tabella nuova che
conserva per sempre i dati di chi ha cancellato l'account. Una tabella e' a posto se:
  - ha una colonna user_id (la trova da sola account_dati.php), oppure
  - e' in ACCOUNT_CATENE (appartiene a un utente per via di un'altra tabella), oppure
  - e' in ACCOUNT_TABELLE_GLOBALI (dati comuni, cache, contatori).
Uso:  python3 tools/controlla_dati_utente.py sql/schema.sql
"""
import io, re, sys

def main():
    schema = sys.argv[1] if len(sys.argv) > 1 else 'sql/schema.sql'
    t = io.open(schema, encoding='utf-8', errors='replace').read()
    tabelle = {m.group(1): set(re.findall(r'^\s*`(\w+)`', m.group(2), re.M))
               for m in re.finditer(r'CREATE TABLE `(\w+)` \(([\s\S]*?)\n\)', t)}
    php = io.open('lib/account_dati.php', encoding='utf-8').read()
    catene_blocco = re.search(r'const ACCOUNT_CATENE = \[([\s\S]*?)\n\];', php).group(1)
    catene = set(re.findall(r"^\s*'(\w+)'\s*=>", catene_blocco, re.M))
    globali_blocco = re.search(r'const ACCOUNT_TABELLE_GLOBALI = \[([\s\S]*?)\];', php).group(1)
    globali = set(re.findall(r"'(\w+)'", globali_blocco))
    guai = []
    for nome, cols in sorted(tabelle.items()):
        if 'user_id' in cols and nome != 'users':
            continue
        if nome in catene or nome in globali:
            continue
        guai.append(nome)
    for n in sorted(catene | globali):
        if n not in tabelle:
            guai.append(n + '  (elencata in account_dati.php ma non esiste nello schema)')
    if guai:
        print('TABELLE NON DECISE (aggiungile a ACCOUNT_CATENE o ACCOUNT_TABELLE_GLOBALI):')
        for g in guai: print('  -', g)
        sys.exit(1)
    print('OK: %d tabelle, tutte coperte (%d con user_id, %d a catena, %d globali)' % (
        len(tabelle), sum(1 for n, c in tabelle.items() if 'user_id' in c and n != 'users'), len(catene), len(globali)))

main()
