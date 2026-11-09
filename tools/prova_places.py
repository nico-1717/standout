#!/usr/bin/env python3
"""Prova di GET /api/v1/places/search e /places/details: solo con il token, regole come la pagina del planner.

Non chiama Google né OpenStreetMap (la macchina di prova non ha rete verso fuori): guarda le regole del nostro lato,
cioè token, minimo di tre lettere, ref obbligatorio, forma della risposta e limite di 40 al minuto.
"""
import argparse, getpass, os, sys
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from prova_sync_progetti import Tel, ok


def main():
    ap = argparse.ArgumentParser(); ap.add_argument('--url', required=True); ap.add_argument('--user', required=True)
    a = ap.parse_args()
    if not any(x in a.url for x in ('127.0.0.1', 'localhost')): sys.exit('Solo indirizzi locali.')
    pw = os.environ.get('STANDOUT_PASSWORD') or getpass.getpass('Password: ')
    t = Tel(a.url, a.user, pw, 'prova luoghi')
    ok(t.chiama('GET', '/api/v1/places/search?q=duomo', auth=False)['_http'] == 401, 'search senza token: 401')
    ok(t.chiama('GET', '/api/v1/places/details?ref=x', auth=False)['_http'] == 401, 'details senza token: 401')
    r = t.chiama('GET', '/api/v1/places/search?q=ab')
    ok(r.get('ok') is True and r['places'] == [], f'meno di tre lettere: lista vuota ({r})')
    r = t.chiama('GET', '/api/v1/places/search?q=' + '%20%20')
    ok(r.get('ok') is True and r['places'] == [], 'solo spazi: lista vuota')
    r = t.chiama('GET', '/api/v1/places/search?q=duomo%20milano')
    ok(r.get('ok') is True and isinstance(r['places'], list), f'tre lettere o più: una lista, anche vuota senza rete ({r["_http"]})')
    for p in r['places']:
        ok(all(k in p for k in ('name', 'detail', 'full', 'ref', 'lat', 'lon')), f'forma di un posto: {p}')
    r = t.chiama('GET', '/api/v1/places/details')
    ok(r['_http'] == 422, f'details senza ref: 422 ({r["_http"]})')
    r = t.chiama('GET', '/api/v1/places/details?ref=non-esiste')
    ok(r['_http'] in (404, 200), f'details con un ref sconosciuto: 404 (o 200 se la cache lo conosce): {r["_http"]}')
    # il limite: 40 chiamate al minuto per utente, in comune tra search e details
    stati = [t.chiama('GET', '/api/v1/places/details?ref=r%d' % i)['_http'] for i in range(45)]
    ok(429 in stati, f'oltre 40 al minuto: 429 ({stati.count(429)} rifiutate)')
    print('Tutto a posto.')


if __name__ == '__main__':
    main()
