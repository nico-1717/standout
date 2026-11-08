#!/usr/bin/env python3
"""Prova di GET /api/v1/home/ai_usage: serve il token, risponde con il piano e i tre consumi del mese."""
import argparse, getpass, os, sys
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from prova_sync_progetti import Tel, ok


def main():
    ap = argparse.ArgumentParser(); ap.add_argument('--url', required=True); ap.add_argument('--user', required=True)
    a = ap.parse_args()
    pw = os.environ.get('STANDOUT_PASSWORD') or getpass.getpass('Password: ')
    t = Tel(a.url, a.user, pw, 'prova ai usage')
    r = t.chiama('GET', '/api/v1/home/ai_usage')
    ok(r.get('ok') is True and 'plan' in r, f'risponde: {r}')
    p = r['plan']
    if p is not None:
        ok(isinstance(p['name'], str) and p['name'], 'il nome del piano')
        ok(len(p['resets']) == 10 and p['resets'].endswith('-01'), f"il primo del mese dopo: {p['resets']}")
        for k in ('audio', 'images', 'tokens'):
            ok(isinstance(p[k]['used'], int) and (p[k]['limit'] is None or isinstance(p[k]['limit'], int)), f'{k}: {p[k]}')
    ok(t.chiama('GET', '/api/v1/home/ai_usage', auth=False)['_http'] == 401, 'senza token: 401')
    print('Tutto a posto.')


if __name__ == '__main__':
    main()
