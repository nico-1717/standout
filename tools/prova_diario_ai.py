#!/usr/bin/env python3
"""Prova di GET /api/v1/diary/ai (+ /reports/{id}, /books/{id}): solo lettura, solo del proprio utente.
Scrive un resoconto e un libro finti direttamente nel database locale con `mysql` e li toglie alla fine."""
import argparse, getpass, json, os, subprocess, sys
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from prova_sync_progetti import Tel, ok


def sql(q):
    return subprocess.run(['mysql', '-N', '-B', os.environ.get('PROVA_DB', 'standout')], input=q, capture_output=True, text=True, check=True).stdout.strip()


def main():
    ap = argparse.ArgumentParser(); ap.add_argument('--url', required=True); ap.add_argument('--user', required=True)
    a = ap.parse_args()
    pw = os.environ.get('STANDOUT_PASSWORD') or getpass.getpass('Password: ')
    t = Tel(a.url, a.user, pw, 'prova diario ai')
    uid = int(sql(f"SELECT id FROM users WHERE username = '{a.user}'"))
    contenuto = json.dumps({'titolo': 'Un trimestre di prova', 'in_breve': 'Breve', 'sentimenti': ['uno', 'due'], 'alti': ['a'], 'bassi': [], 'temi': [{'tema': 'T', 'testo': 'x'}], 'abitudini': 'ok', 'concentrazione': 'ok', 'spunti': ['s'], 'pensieri': ['p']})
    numeri = json.dumps({'ora': {'scritti': 5, 'umore': 3.5, 'energia': 3.0, 'abitudini_pct': 80, 'pomodori': 4}, 'prima': {}, 'abitudini': []})
    capitoli = json.dumps([{'titolo': 'Uno', 'testo': 'Testo uno\n\nSecondo paragrafo'}, {'titolo': 'Vuoto', 'testo': ''}, {'titolo': 'Due', 'testo': 'Testo due'}])
    q = lambda s: s.replace('\\', '\\\\').replace("'", "''")
    try:
        sql(f"INSERT INTO diary_ai_reports (user_id, anno, trimestre, periodo_da, periodo_a, contenuto, numeri) VALUES ({uid}, 2099, 1, '2099-01-01', '2099-03-31', '{q(contenuto)}', '{q(numeri)}')")
        sql(f"INSERT INTO diary_ai_books (user_id, titolo, periodo_da, periodo_a, stato, piano, capitoli) VALUES ({uid}, 'Libro di prova', '2099-01-01', '2099-03-31', 'fatto', '[]', '{q(capitoli)}')")
        rid = int(sql(f"SELECT id FROM diary_ai_reports WHERE user_id = {uid} AND anno = 2099"))
        bid = int(sql(f"SELECT id FROM diary_ai_books WHERE user_id = {uid} AND titolo = 'Libro di prova'"))
        r = t.chiama('GET', '/api/v1/diary/ai')
        ok(r['_http'] == 200 and r['ok'], f'elenco: {r["_http"]}')
        rep = [x for x in r['reports'] if x['id'] == rid]; bk = [x for x in r['books'] if x['id'] == bid]
        ok(rep and rep[0]['title'] == 'Un trimestre di prova' and rep[0]['year'] == 2099 and rep[0]['quarter'] == 1, f'resoconto in elenco: {rep}')
        ok(bk and bk[0]['chapters'] == 2 and bk[0]['done'] is True, f'libro in elenco (2 capitoli con testo): {bk}')
        ok('content' not in rep[0] and 'chapters_text' not in bk[0], 'l\'elenco non porta i testi')
        r = t.chiama('GET', f'/api/v1/diary/ai/reports/{rid}')
        ok(r['_http'] == 200 and r['report']['content']['in_breve'] == 'Breve' and r['report']['numbers']['ora']['scritti'] == 5, 'il resoconto per intero')
        r = t.chiama('GET', f'/api/v1/diary/ai/books/{bid}')
        ok(r['_http'] == 200 and [c['title'] for c in r['book']['chapters']] == ['Uno', 'Due'], f'il libro: i capitoli vuoti non ci sono')
        ok(t.chiama('GET', f'/api/v1/diary/ai/reports/{bid + 9999}')['_http'] == 404, 'resoconto che non c\'e: 404')
        ok(t.chiama('GET', f'/api/v1/diary/ai/books/{bid + 9999}')['_http'] == 404, 'libro che non c\'e: 404')
        ok(t.chiama('GET', '/api/v1/diary/ai', auth=False)['_http'] == 401, 'senza token: 401')
    finally:
        sql(f"DELETE FROM diary_ai_reports WHERE user_id = {uid} AND anno = 2099")
        sql(f"DELETE FROM diary_ai_books WHERE user_id = {uid} AND titolo = 'Libro di prova'")
    print('Tutto a posto.')


if __name__ == '__main__':
    main()
