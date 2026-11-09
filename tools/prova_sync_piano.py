# -*- coding: utf-8 -*-
"""Prova della sincronizzazione del PIANO dei progetti: obiettivi, giorni evidenziati, blocchi, il tuo tempo, modelli.

    STANDOUT_PASSWORD=... python3 tools/prova_sync_piano.py --url http://127.0.0.1:8099 --user prova

Solo su indirizzi locali (come prova_sync.py). Toglie da sola quello che crea.
"""
import argparse, getpass, json, os, sys, uuid, urllib.request, urllib.error
NS_UUID5 = uuid.UUID('7d4f1b0e-3c52-4e8a-9a61-5b2c8d0f6e13')

NOME = 'prova piano (si cancella da sola)'
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
    A = Tel(a.url, a.user, pw, 'prova piano A'); B = Tel(a.url, a.user, pw, 'prova piano B')
    me = A.chiama('GET', '/api/v1/me'); uid = (me.get('user') or me).get('id')
    A.copia_completa()
    def pulisci():
        A.copia_completa()
        for (t, u), x in list(A.copia.items()):
            if t == 'projects' and str(x.get('name', '')).startswith(NOME): A.push([mut(t, u, {}, op='delete')])
            if t == 'plan_templates' and str(x.get('name', '')).startswith(NOME): A.push([mut(t, u, {}, op='delete')])
        A.push([mut('plan_settings', uuid_utente('plan_settings', uid), {}, op='delete')])
    pulisci()
    try:
        st = A.chiama('GET', '/api/v1/sync/state')
        ok(all(t in st['tables'] for t in ['plan_goals', 'plan_paint', 'plan_days', 'plan_settings', 'plan_templates']), 'stato: le tabelle del piano ci sono')
        A.copia_completa(); B.copia_completa()

        P1, P2 = nuovo(), nuovo()
        r = A.push([mut('projects', P1, {'name': NOME + ' esame', 'sort_key': 'a0'}), mut('projects', P2, {'name': NOME + ' argomento', 'parent_uuid': P1, 'sort_key': 'a0'})])
        ok([x['status'] for x in r['results']] == ['applied', 'applied'], 'due progetti creati')

        print('1. obiettivi')
        g1 = u5('plan_goals|' + P1); g2 = u5('plan_goals|' + P2)
        r = A.push([mut('plan_goals', g1, {'project_uuid': P1, 'active': True, 'minutes': 6000, 'locked': False, 'kind': 'exam', 'priority': 'must',
                                           'start_date': '2030-01-02', 'deadline': '2030-02-20', 'since': '2030-01-02', 'items': None, 'unit_minutes': None})])
        ok(esito(r)['status'] == 'applied', f"obiettivo creato: {esito(r).get('message')}")
        B.aggiorna(); c = B.copia.get(('plan_goals', g1), {})
        ok(c.get('project_uuid') == P1 and c.get('minutes') == 6000 and c.get('kind') == 'exam' and c.get('priority') == 'must' and c.get('active') is True and c.get('locked') is False
           and c.get('deadline') == '2030-02-20' and c.get('start_date') == '2030-01-02' and c.get('items') is None, f"il secondo telefono lo vede: {c}")
        r = A.push([mut('plan_goals', g1, {'minutes': 7200, 'locked': True, 'kind': 'count', 'items': 120, 'unit_minutes': 60})]); B.aggiorna()
        c = B.copia[('plan_goals', g1)]
        ok(esito(r)['status'] == 'applied' and c['minutes'] == 7200 and c['locked'] is True and c['kind'] == 'count' and c['items'] == 120 and c['unit_minutes'] == 60 and c['priority'] == 'must', 'modificato (i campi non nominati restano)')
        r = A.push([mut('plan_goals', g1, {'deadline': None, 'start_date': None})]); B.aggiorna()
        ok(B.copia[('plan_goals', g1)]['deadline'] is None, 'scadenza tolta')
        r = A.push([mut('plan_goals', g2, {'project_uuid': P2, 'minutes': 1200, 'kind': 'plain', 'priority': 'nice'})]); ok(esito(r)['status'] == 'applied', 'obiettivo del figlio (campi non nominati: valori di partenza)')
        B.aggiorna(); c = B.copia[('plan_goals', g2)]
        ok(c['active'] is True and c['locked'] is False and c['since'] is None, 'valori di partenza')
        for patch, campo in [({'kind': 'boh'}, 'kind'), ({'priority': 'urgente'}, 'priority'), ({'minutes': -1}, 'minutes'), ({'minutes': 600001}, 'minutes'), ({'minutes': '5'}, 'minutes'),
                             ({'deadline': '2030-02-31'}, 'deadline'), ({'active': 1}, 'active'), ({'items': 100001}, 'items'), ({'unit_minutes': 1441}, 'unit_minutes'),
                             ({'updated_at': 'x'}, 'updated_at'), ({'project_uuid': P2}, 'project_uuid'), ({'cosa': 1}, 'cosa')]:
            r = A.push([mut('plan_goals', g1, patch)]); ok(esito(r)['status'] == 'rejected', f"{campo} sbagliato: rifiutato ({esito(r).get('message')})")
        r = A.push([mut('plan_goals', nuovo(), {'project_uuid': P1, 'minutes': 5})]); ok(esito(r)['status'] == 'rejected', 'uuid che non e quello calcolato: rifiutato')
        r = A.push([mut('plan_goals', u5('plan_goals|' + nuovo()), {'project_uuid': nuovo(), 'minutes': 5})]); ok(esito(r)['status'] == 'rejected' and esito(r)['code'] == 'not_found', 'progetto che non esiste')
        r = A.push([mut('plan_goals', g1, {'minutes': 7200})]); ok(esito(r)['status'] == 'applied', 'rimandata la stessa modifica: va bene')

        print('2. giorni evidenziati')
        d1 = u5(f'plan_paint|{P1}|2030-01-10'); d2 = u5(f'plan_paint|{P1}|2030-01-11')
        r = A.push([mut('plan_paint', d1, {'project_uuid': P1, 'day': '2030-01-10'}), mut('plan_paint', d2, {'project_uuid': P1, 'day': '2030-01-11'})])
        ok([x['status'] for x in r['results']] == ['applied', 'applied'], 'due giorni evidenziati')
        B.aggiorna(); ok(B.copia.get(('plan_paint', d1), {}).get('day') == '2030-01-10' and B.copia[('plan_paint', d1)]['project_uuid'] == P1, 'il secondo telefono li vede')
        r = A.push([mut('plan_paint', d1, {'project_uuid': P1, 'day': '2030-01-10'})]); ok(esito(r)['status'] == 'applied', 'rimandato lo stesso: nessun doppione')
        B.copia_completa(); ok(sum(1 for (t, u) in B.copia if t == 'plan_paint') == 2, 'in tutto due righe')
        r = A.push([mut('plan_paint', nuovo(), {'project_uuid': P1, 'day': '2030-01-12'})]); ok(esito(r)['status'] == 'rejected', 'uuid sbagliato: rifiutato')
        r = A.push([mut('plan_paint', u5(f'plan_paint|{P1}|2030-01-13'), {'project_uuid': P1, 'day': '2030-02-30'})]); ok(esito(r)['status'] == 'rejected', 'giorno sbagliato: rifiutato')
        r = A.push([mut('plan_paint', d2, {'day': '2030-01-12'})]); ok(esito(r)['status'] == 'rejected', 'un giorno evidenziato non si cambia')
        r = A.push([mut('plan_paint', d1, {}, op='delete')]); B.aggiorna(); ok(esito(r)['status'] == 'applied' and ('plan_paint', d1) not in B.copia and ('plan_paint', d2) in B.copia, 'tolto uno')

        print('3. blocchi del piano')
        b1 = u5(f'plan_days|{P2}|2030-01-15|study'); b2 = u5(f'plan_days|{P2}|2030-01-15|review')
        r = A.push([mut('plan_days', b1, {'project_uuid': P2, 'day': '2030-01-15', 'kind': 'study', 'minutes': 90, 'source': 'auto'}),
                    mut('plan_days', b2, {'project_uuid': P2, 'day': '2030-01-15', 'kind': 'review', 'minutes': 10, 'source': 'auto'})])
        ok([x['status'] for x in r['results']] == ['applied', 'applied'], f"due blocchi: {[x.get('message') for x in r['results']]}")
        B.aggiorna(); c = B.copia.get(('plan_days', b1), {})
        ok(c.get('minutes') == 90 and c.get('source') == 'auto' and c.get('kind') == 'study' and c.get('day') == '2030-01-15' and c.get('project_uuid') == P2, f"il secondo telefono lo vede: {c}")
        r = A.push([mut('plan_days', b1, {'minutes': 45, 'source': 'manual'})]); B.aggiorna()
        ok(esito(r)['status'] == 'applied' and B.copia[('plan_days', b1)]['minutes'] == 45 and B.copia[('plan_days', b1)]['source'] == 'manual', 'modificato a mano')
        for patch, campo in [({'minutes': 0}, 'minutes'), ({'minutes': 1441}, 'minutes'), ({'source': 'boh'}, 'source'), ({'kind': 'review'}, 'kind'), ({'day': '2030-01-16'}, 'day')]:
            r = A.push([mut('plan_days', b1, patch)]); ok(esito(r)['status'] == 'rejected', f"{campo} sbagliato: rifiutato")
        r = A.push([mut('plan_days', u5(f'plan_days|{P2}|2030-01-16|boh'), {'project_uuid': P2, 'day': '2030-01-16', 'kind': 'boh', 'minutes': 5})]); ok(esito(r)['status'] == 'rejected', 'tipo sbagliato in creazione')
        r = A.push([mut('plan_days', u5(f'plan_days|{P2}|2030-01-16|study'), {'project_uuid': P2, 'day': '2030-01-16', 'kind': 'study'})]); ok(esito(r)['status'] == 'rejected', 'senza minuti: rifiutato')
        r = A.push([mut('plan_days', b2, {}, op='delete')]); B.aggiorna(); ok(('plan_days', b2) not in B.copia and ('plan_days', b1) in B.copia, 'tolto un blocco')

        print('4. il tuo tempo')
        s = uuid_utente('plan_settings', uid)
        r = A.push([mut('plan_settings', s, {'weekday_min': 180, 'weekend_min': None, 'rest_dow': 7, 'buffer_days': 2, 'floor_min': 20, 'max_subjects': 4, 'reviews': True,
                                             'overrides': json.dumps({'2030-01-20': 0, '2030-01-21': 300})})])
        ok(esito(r)['status'] == 'applied', f"impostazioni salvate: {esito(r).get('message')}")
        B.aggiorna(); c = B.copia.get(('plan_settings', s), {})
        ok(c.get('weekday_min') == 180 and c.get('weekend_min') is None and c.get('rest_dow') == 7 and c.get('buffer_days') == 2 and c.get('floor_min') == 20 and c.get('max_subjects') == 4 and c.get('reviews') is True
           and json.loads(c.get('overrides') or '{}') == {'2030-01-20': 0, '2030-01-21': 300}, f"il secondo telefono le vede: {c}")
        r = A.push([mut('plan_settings', s, {'weekend_min': 240, 'overrides': None})]); B.aggiorna()
        ok(B.copia[('plan_settings', s)]['weekend_min'] == 240 and B.copia[('plan_settings', s)]['overrides'] is None and B.copia[('plan_settings', s)]['weekday_min'] == 180, 'modificate')
        for patch, campo in [({'rest_dow': 8}, 'rest_dow'), ({'buffer_days': 15}, 'buffer_days'), ({'floor_min': 4}, 'floor_min'), ({'max_subjects': 0}, 'max_subjects'), ({'weekday_min': 1441}, 'weekday_min'),
                             ({'reviews': 1}, 'reviews'), ({'overrides': 'non json'}, 'overrides'), ({'overrides': json.dumps({'2030-02-31': 5})}, 'overrides'), ({'overrides': json.dumps({'2030-01-01': 2000})}, 'overrides')]:
            r = A.push([mut('plan_settings', s, patch)]); ok(esito(r)['status'] == 'rejected', f"{campo} sbagliato: rifiutato")
        r = A.push([mut('plan_settings', nuovo(), {'floor_min': 10})]); ok(esito(r)['status'] == 'rejected', 'uuid di altri: rifiutato')

        print('5. modelli')
        t1 = nuovo()
        forma = json.dumps([{'path': NOME + ' esame', 'minutes': 6000, 'kind': 'exam', 'priority': 'must', 'locked': False}, {'path': NOME + ' esame / argomento', 'minutes': 1200, 'kind': 'plain', 'priority': 'should', 'locked': True}])
        r = A.push([mut('plan_templates', t1, {'name': NOME + ' sessione', 'body': forma})]); ok(esito(r)['status'] == 'applied', f"modello creato: {esito(r).get('message')}")
        B.aggiorna(); c = B.copia.get(('plan_templates', t1), {})
        ok(c.get('name') == NOME + ' sessione' and json.loads(c.get('body') or '[]')[1]['minutes'] == 1200, 'il secondo telefono lo vede')
        r = A.push([mut('plan_templates', t1, {'name': NOME + ' sessione 2'})]); B.aggiorna(); ok(B.copia[('plan_templates', t1)]['name'] == NOME + ' sessione 2', 'rinominato')
        for patch, campo in [({'name': '  '}, 'name'), ({'name': 'x' * 81}, 'name'), ({'body': 'non json'}, 'body'), ({'body': '{"a":1}'}, 'body'), ({'body': json.dumps([{'minutes': 5}])}, 'body'),
                             ({'body': json.dumps([{'path': 'a', 'minutes': -5}])}, 'body'), ({'body': json.dumps([{'path': 'a', 'minutes': 5, 'kind': 'boh'}])}, 'body'), ({'created_at': 'x'}, 'created_at')]:
            r = A.push([mut('plan_templates', t1, patch)]); ok(esito(r)['status'] == 'rejected', f"{campo} sbagliato: rifiutato")
        r = A.push([mut('plan_templates', nuovo(), {'name': 'solo nome'})]); ok(esito(r)['status'] == 'rejected', 'senza forma: rifiutato')
        r = A.push([mut('plan_templates', t1, {}, op='delete')]); B.aggiorna(); ok(('plan_templates', t1) not in B.copia, 'tolto')

        print('6. tolto il progetto')
        r = A.push([mut('projects', P1, {}, op='delete')]); ok(esito(r)['status'] == 'applied', 'progetto tolto')
        B.copia_completa()
        ok(not any(t in ('plan_goals', 'plan_paint', 'plan_days') and x.get('project_uuid') in (P1, P2) for (t, u), x in B.copia.items()), 'una copia nuova non porta righe di progetti spariti')
    finally:
        pulisci()
    print()
    if errori:
        print('FALLITE %d prove:' % len(errori)); [print('  -', e) for e in errori]; sys.exit(1)
    print('Tutto bene.')

if __name__ == '__main__': main()
