# -*- coding: utf-8 -*-
"""Prova della sincronizzazione del BOZZE veloci e mazzo collegato a un progetto.

    STANDOUT_PASSWORD=... python3 tools/prova_sync_bozze.py --url http://127.0.0.1:8099 --user prova

Solo su indirizzi locali (come prova_sync.py). Toglie da sola quello che crea.
"""
import argparse, getpass, json, os, sys, uuid, urllib.request, urllib.error
NS_UUID5 = uuid.UUID('7d4f1b0e-3c52-4e8a-9a61-5b2c8d0f6e13')

NOME = 'prova bozze (si cancella da sola)'
errori = []
def ok(c, testo):
    print(('  ok   ' if c else '  KO   ') + testo)
    if not c: errori.append(testo)

class Tel:
    """Un telefono: un login, i suoi token e la sua copia locale (uuid -> riga)."""
    def __init__(s, base, utente, pw, nome):
        s.base = base.rstrip('/')
        r = s.chiama('POST', '/api/v1/auth/login', {'identifier': utente, 'password': pw, 'device_name': nome}, auth=False)
        s.tok = r['access_token']; s.rinnovo = r['refresh_token']
        s.copia = {}      # (tabella, uuid) -> riga
        s.cursore = 0
    def chiama(s, metodo, percorso, corpo=None, auth=True, atteso=None):
        dati = json.dumps(corpo).encode() if corpo is not None else None
        rq = urllib.request.Request(s.base + percorso, data=dati, method=metodo)
        rq.add_header('Content-Type', 'application/json')
        if auth: rq.add_header('Authorization', 'Bearer ' + s.tok)
        try:
            with urllib.request.urlopen(rq, timeout=60) as r: testo = r.read().decode(); stato = r.status
        except urllib.error.HTTPError as e:
            testo = e.read().decode(); stato = e.code
        try: j = json.loads(testo)
        except Exception: j = {'ok': False, 'raw': testo[:300]}
        j['_http'] = stato
        return j
    def applica(s, cambi):
        for c in cambi:
            k = (c['table'], c['uuid'])
            if c['op'] == 'delete': s.copia.pop(k, None)
            else: s.copia[k] = c['row']
    def copia_completa(s, limite=500):
        s.copia = {}; cur = 'snapshot'; pagine = 0
        while True:
            r = s.chiama('GET', f'/api/v1/sync/pull?cursor={cur}&limit={limite}')
            assert r['ok'], r
            s.applica(r['changes']); pagine += 1
            cur = r['cursor']
            if not r['more']: break
        s.cursore = int(cur)
        return pagine
    def aggiorna(s):
        giri = 0
        while True:
            r = s.chiama('GET', f'/api/v1/sync/pull?cursor={s.cursore}&limit=500')
            assert r['ok'], r
            if r.get('resync_required'): s.copia_completa(); return giri
            s.applica(r['changes']); s.cursore = int(r['cursor']); giri += 1
            if not r['more']: return giri
    def push(s, mutazioni):
        return s.chiama('POST', '/api/v1/sync/push', {'mutations': mutazioni})

def mut(tabella, uuid_, patch, op='upsert'):
    return {'mutation_id': str(uuid.uuid4()), 'table': tabella, 'op': op, 'uuid': uuid_, 'patch': patch}


def mut(tabella, uuid_, patch, op='upsert'):
    return {'mutation_id': str(uuid.uuid4()), 'table': tabella, 'op': op, 'uuid': uuid_, 'patch': patch}
def nuovo(): return str(uuid.uuid4())

def esito(r, i=0): return r['results'][i]


def u5(nome): return str(uuid.uuid5(NS_UUID5, nome))
def uuid_utente(tbl, uid): return u5(f'{tbl}|{uid}')

def main():
    ap = argparse.ArgumentParser(); ap.add_argument('--url', required=True); ap.add_argument('--user', required=True)
    a = ap.parse_args()
    if not any(x in a.url for x in ('127.0.0.1', 'localhost')): sys.exit('Solo indirizzi locali.')
    pw = os.environ.get('STANDOUT_PASSWORD') or getpass.getpass('Password: ')
    A = Tel(a.url, a.user, pw, 'prova bozze A'); B = Tel(a.url, a.user, pw, 'prova bozze B')
    A.copia_completa()
    def pulisci():
        A.copia_completa()
        for (t, u), x in list(A.copia.items()):
            if t in ('projects', 'fc_study_decks') and str(x.get('name', '')).startswith(NOME): A.push([mut(t, u, {}, op='delete')])
            if t == 'fc_quick_drafts' and str(x.get('front', '')).startswith(NOME): A.push([mut(t, u, {}, op='delete')])
    pulisci()
    try:
        st = A.chiama('GET', '/api/v1/sync/state')
        ok(all(t in st['tables'] for t in ['fc_quick_drafts', 'fc_study_deck_projects']), 'stato: le due tabelle ci sono')
        A.copia_completa(); B.copia_completa()

        print('1. bozze')
        D1, D2 = nuovo(), nuovo()
        r = A.push([mut('fc_quick_drafts', D1, {'front': NOME + ' fronte', 'back': ''})])
        ok(esito(r)['status'] == 'applied', f"bozza creata: {esito(r).get('message')}")
        B.aggiorna(); c = B.copia.get(('fc_quick_drafts', D1), {})
        ok(c.get('front') == NOME + ' fronte' and c.get('back') == '' and c.get('deck_uuid') is None, f'il secondo telefono la vede: {c}')
        r = A.push([mut('fc_quick_drafts', D1, {'back': 'retro scritto dopo'})]); B.aggiorna()
        ok(esito(r)['status'] == 'applied' and B.copia[('fc_quick_drafts', D1)]['back'] == 'retro scritto dopo' and B.copia[('fc_quick_drafts', D1)]['front'] == NOME + ' fronte', 'retro aggiunto, il fronte resta')
        for patch, campo in [({'front': ''}, 'front'), ({'front': 5}, 'front'), ({'back': 5}, 'back'), ({'deck_uuid': nuovo()}, 'deck_uuid'), ({'created_at': 'x'}, 'created_at'), ({'cosa': 1}, 'cosa')]:
            r = A.push([mut('fc_quick_drafts', D1, patch)]); ok(esito(r)['status'] == 'rejected', f"{campo} sbagliato: rifiutato ({esito(r).get('message')})")
        r = A.push([mut('fc_quick_drafts', nuovo(), {'back': 'senza fronte'})]); ok(esito(r)['status'] == 'rejected', 'una bozza nuova senza fronte: rifiutata')
        r = A.push([mut('fc_quick_drafts', nuovo(), {'front': 'x' * 600})]); ok(esito(r)['status'] == 'applied', 'fronte troppo lungo: tagliato a 500')
        A.copia_completa()
        lunga = [u for (t, u), x in A.copia.items() if t == 'fc_quick_drafts' and x['front'] == 'x' * 500]
        ok(len(lunga) == 1, 'tagliato a 500 caratteri')
        for u in lunga: A.push([mut('fc_quick_drafts', u, {}, op='delete')])
        r = A.push([mut('fc_quick_drafts', D1, {}, op='delete')]); B.aggiorna()
        ok(esito(r)['status'] == 'applied' and ('fc_quick_drafts', D1) not in B.copia, 'bozza cancellata')
        r = A.push([mut('fc_quick_drafts', D1, {}, op='delete')]); ok(esito(r)['status'] == 'applied', 'cancellata due volte: va bene')

        print('2. mazzo collegato a un progetto')
        M1, M2, M3, P1, P2 = nuovo(), nuovo(), nuovo(), nuovo(), nuovo()
        r = A.push([mut('fc_study_decks', M1, {'name': NOME + ' materia', 'color': '#336699'}),
                    mut('fc_study_decks', M2, {'name': NOME + ' capitolo', 'parent_uuid': M1, 'color': '#336699'}),
                    mut('fc_study_decks', M3, {'name': NOME + ' altra', 'color': '#993366'}),
                    mut('projects', P1, {'name': NOME + ' p1', 'sort_key': 'a0'}), mut('projects', P2, {'name': NOME + ' p2', 'sort_key': 'a1'})])
        ok(all(x['status'] == 'applied' for x in r['results']), 'mazzi e progetti creati')
        L1, L2, L3 = nuovo(), nuovo(), nuovo()
        r = A.push([mut('fc_study_deck_projects', L1, {'project_uuid': P1, 'deck_uuid': M1})]); B.aggiorna()
        c = B.copia.get(('fc_study_deck_projects', L1), {})
        ok(esito(r)['status'] == 'applied' and c.get('project_uuid') == P1 and c.get('deck_uuid') == M1, f'collegamento creato e visto: {c}')
        r = A.push([mut('fc_study_deck_projects', L2, {'project_uuid': P2, 'deck_uuid': M2})])
        ok(esito(r)['status'] == 'rejected' and esito(r)['code'] == 'forbidden', 'un capitolo di una materia gia di un altro progetto: rifiutato')
        r = A.push([mut('fc_study_deck_projects', L2, {'project_uuid': P2, 'deck_uuid': M3})]); ok(esito(r)['status'] == 'applied', 'un altro progetto, un altro mazzo: va bene')
        r = A.push([mut('fc_study_deck_projects', L3, {'project_uuid': P1, 'deck_uuid': M3})]); ok(esito(r)['status'] == 'rejected', 'M3 e gia di P2: rifiutato')
        r = A.push([mut('fc_study_deck_projects', L1, {}, op='delete'), mut('fc_study_deck_projects', L3, {'project_uuid': P1, 'deck_uuid': M2})]); B.aggiorna()
        ok([x['status'] for x in r['results']] == ['applied', 'applied'] and ('fc_study_deck_projects', L1) not in B.copia and B.copia[('fc_study_deck_projects', L3)]['deck_uuid'] == M2, 'si cambia mazzo: via la riga di prima, una nuova')
        r = A.push([mut('fc_study_deck_projects', nuovo(), {'project_uuid': P1, 'deck_uuid': M1})]); ok(esito(r)['status'] == 'applied', 'un progetto ha un solo mazzo: il nuovo prende il posto')
        A.copia_completa(); ok(sum(1 for (t, u), x in A.copia.items() if t == 'fc_study_deck_projects' and x['project_uuid'] == P1) == 1, 'una sola riga per P1')
        for patch, campo in [({'project_uuid': nuovo(), 'deck_uuid': M3}, 'project_uuid'), ({'project_uuid': P2, 'deck_uuid': nuovo()}, 'deck_uuid'), ({'project_uuid': P2}, 'deck_uuid mancante'), ({'created_at': 'x', 'project_uuid': P2, 'deck_uuid': M3}, 'created_at')]:
            r = A.push([mut('fc_study_deck_projects', nuovo(), patch)]); ok(esito(r)['status'] == 'rejected', f"{campo}: rifiutato ({esito(r).get('message')})")
        r = A.push([mut('projects', P1, {}, op='delete')]); B.aggiorna()
        ok(not any(t == 'fc_study_deck_projects' and x['project_uuid'] == P1 for (t, u), x in B.copia.items()), 'cancellando il progetto se ne va anche il collegamento')
    finally:
        pulisci()
    print('\n' + ('TUTTO OK' if not errori else f'{len(errori)} errori'))
    sys.exit(1 if errori else 0)

main()
