# -*- coding: utf-8 -*-
"""Prova della sincronizzazione di rubrica, invitati degli eventi e luoghi salvati.

    STANDOUT_PASSWORD=... python3 tools/prova_sync_rubrica.py --url http://127.0.0.1:8099 --user prova

Solo su indirizzi locali. Toglie da sola quello che crea.
"""
import argparse, getpass, json, os, sys, uuid, urllib.request, urllib.error

NOME = 'prova rubrica'
errori = []
def ok(c, testo):
    print(('  ok   ' if c else '  KO   ') + testo)
    if not c: errori.append(testo)

class Tel:
    def __init__(s, base, utente, pw, nome):
        s.base = base.rstrip('/')
        r = s.chiama('POST', '/api/v1/auth/login', {'identifier': utente, 'password': pw, 'device_name': nome}, auth=False)
        s.tok = r['access_token']; s.copia = {}; s.cursore = 0
    def chiama(s, metodo, percorso, corpo=None, auth=True):
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
    def copia_completa(s):
        s.copia = {}; cur = 'snapshot'
        while True:
            r = s.chiama('GET', f'/api/v1/sync/pull?cursor={cur}&limit=500')
            assert r['ok'], r
            s.applica(r['changes']); cur = r['cursor']
            if not r['more']: break
        s.cursore = int(cur)
    def aggiorna(s):
        while True:
            r = s.chiama('GET', f'/api/v1/sync/pull?cursor={s.cursore}&limit=500')
            assert r['ok'], r
            if r.get('resync_required'): s.copia_completa(); return
            s.applica(r['changes']); s.cursore = int(r['cursor'])
            if not r['more']: return
    def push(s, m): return s.chiama('POST', '/api/v1/sync/push', {'mutations': m})

def mut(t, u, patch, op='upsert'): return {'mutation_id': str(uuid.uuid4()), 'table': t, 'op': op, 'uuid': u, 'patch': patch}
def nuovo(): return str(uuid.uuid4())
def esito(r, i=0): return r['results'][i]

def main():
    ap = argparse.ArgumentParser(); ap.add_argument('--url', required=True); ap.add_argument('--user', required=True)
    a = ap.parse_args()
    if not any(x in a.url for x in ('127.0.0.1', 'localhost')): sys.exit('Solo indirizzi locali.')
    pw = os.environ.get('STANDOUT_PASSWORD') or getpass.getpass('Password: ')
    A = Tel(a.url, a.user, pw, 'prova rubrica A'); B = Tel(a.url, a.user, pw, 'prova rubrica B')
    def pulisci():
        A.copia_completa()
        for (t, u), x in list(A.copia.items()):
            if t == 'planner_events' and str(x.get('title', '')).startswith(NOME): A.push([mut(t, u, {}, op='delete')])
            if t == 'contacts' and str(x.get('email', '')).startswith('prova.rubrica'): A.push([mut(t, u, {}, op='delete')])
            if t == 'planner_places' and str(x.get('label', '')).startswith(NOME): A.push([mut(t, u, {}, op='delete')])
    pulisci()
    try:
        st = A.chiama('GET', '/api/v1/sync/state')
        ok(all(t in st['tables'] for t in ['contacts', 'event_guests', 'planner_places']), 'stato: le tre tabelle ci sono')
        A.copia_completa(); B.copia_completa()

        print('1. contatti')
        C1, C2 = nuovo(), nuovo()
        r = A.push([mut('contacts', C1, {'email': 'Prova.Rubrica1@Example.com', 'name': ''})]); B.aggiorna()
        c = B.copia.get(('contacts', C1), {})
        ok(esito(r)['status'] == 'applied' and c.get('email') == 'prova.rubrica1@example.com' and c.get('name') == 'prova.rubrica1', f'creato, e-mail in minuscolo, nome dalla e-mail: {c}')
        r = A.push([mut('contacts', C2, {'email': 'prova.rubrica1@example.com', 'name': 'Doppio'})]); ok(esito(r)['status'] == 'rejected', 'stesso indirizzo due volte: rifiutato')
        for patch in [{'email': 'non-una-mail'}, {'email': 5}, {'name': 5}, {'created_at': 'x', 'email': 'prova.rubrica9@example.com'}, {'cosa': 1, 'email': 'prova.rubrica9@example.com'}]:
            r = A.push([mut('contacts', nuovo(), patch)]); ok(esito(r)['status'] == 'rejected', f"{patch}: rifiutato ({esito(r).get('message')})")
        r = A.push([mut('contacts', nuovo(), {'name': 'senza mail'})]); ok(esito(r)['status'] == 'rejected', 'senza e-mail: rifiutato')
        r = A.push([mut('contacts', C1, {'name': 'Anna Rossi'})]); B.aggiorna()
        ok(esito(r)['status'] == 'applied' and B.copia[('contacts', C1)]['name'] == 'Anna Rossi', 'nome cambiato')
        r = A.push([mut('contacts', C2, {'email': 'prova.rubrica2@example.com', 'name': 'x' * 120})]); A.copia_completa()
        ok(esito(r)['status'] == 'applied' and len(A.copia[('contacts', C2)]['name']) == 80, 'nome lungo tagliato a 80')
        r = A.push([mut('contacts', C2, {'email': 'prova.rubrica1@example.com'})]); ok(esito(r)['status'] == 'rejected', 'cambiare in un indirizzo gia usato: rifiutato')

        print('2. invitati')
        E1, E2 = nuovo(), nuovo()
        r = A.push([mut('planner_events', E1, {'title': NOME + ' evento', 'event_date': '2030-05-05', 'start_time': '10:00:00', 'end_time': '11:00:00'}),
                    mut('planner_events', E2, {'title': NOME + ' altro', 'event_date': '2030-05-06', 'start_time': '10:00:00', 'end_time': '11:00:00'})])
        ok(all(x['status'] == 'applied' for x in r['results']), f"eventi creati: {[x.get('message') for x in r['results']]}")
        G1, G2, G3 = nuovo(), nuovo(), nuovo()
        r = A.push([mut('event_guests', G1, {'event_uuid': E1, 'email': 'prova.rubrica1@example.com', 'contact_uuid': C1})]); B.aggiorna()
        g = B.copia.get(('event_guests', G1), {})
        ok(esito(r)['status'] == 'applied' and g.get('event_uuid') == E1 and g.get('contact_uuid') == C1 and g.get('name') == 'Anna Rossi', f'invitato dalla rubrica, nome copiato: {g}')
        r = A.push([mut('event_guests', G2, {'event_uuid': E1, 'email': 'prova.rubrica1@example.com'})]); ok(esito(r)['status'] == 'rejected', 'stesso invitato due volte: rifiutato')
        r = A.push([mut('event_guests', G2, {'event_uuid': E2, 'email': 'prova.rubrica1@example.com'})]); ok(esito(r)['status'] == 'applied', 'stesso indirizzo in un altro evento: va bene')
        r = A.push([mut('event_guests', G3, {'event_uuid': E1, 'email': 'prova.rubrica7@example.com', 'name': 'Scritto a mano'})]); B.aggiorna()
        ok(esito(r)['status'] == 'applied' and B.copia[('event_guests', G3)]['contact_uuid'] is None and B.copia[('event_guests', G3)]['name'] == 'Scritto a mano', 'invitato scritto a mano')
        for patch, campo in [({'event_uuid': nuovo(), 'email': 'prova.rubrica8@example.com'}, 'evento che non c\'e'), ({'event_uuid': E1, 'email': 'sbagliata'}, 'e-mail'), ({'email': 'prova.rubrica8@example.com'}, 'evento mancante'),
                             ({'event_uuid': E1, 'email': 'prova.rubrica8@example.com', 'contact_uuid': nuovo()}, 'contatto che non c\'e'), ({'event_uuid': E1, 'email': 'prova.rubrica8@example.com', 'created_at': 'x'}, 'created_at')]:
            r = A.push([mut('event_guests', nuovo(), patch)]); ok(esito(r)['status'] == 'rejected', f"{campo}: rifiutato ({esito(r).get('message')})")
        r = A.push([mut('event_guests', G3, {'event_uuid': E2})]); ok(esito(r)['status'] == 'rejected', "l'evento di un invitato non si cambia")
        r = A.push([mut('event_guests', G3, {'name': 'Nome nuovo'})]); B.aggiorna(); ok(esito(r)['status'] == 'applied' and B.copia[('event_guests', G3)]['name'] == 'Nome nuovo', 'nome di un invitato cambiato')
        # il limite di 30 per evento
        E3 = nuovo(); A.push([mut('planner_events', E3, {'title': NOME + ' pieno', 'event_date': '2030-05-07', 'start_time': '10:00:00', 'end_time': '11:00:00'})])
        mm = [mut('event_guests', nuovo(), {'event_uuid': E3, 'email': f'prova.rubrica.g{i}@example.com'}) for i in range(31)]
        r = A.push(mm); st_ = [x['status'] for x in r['results']]
        ok(st_[:30] == ['applied'] * 30 and st_[30] == 'rejected', 'al massimo 30 invitati per evento')
        print('3. cancellare un contatto lascia gli invitati, un evento porta via i suoi')
        r = A.push([mut('contacts', C1, {}, op='delete')]); B.aggiorna()
        ok(esito(r)['status'] == 'applied' and ('contacts', C1) not in B.copia and ('event_guests', G1) in B.copia and B.copia[('event_guests', G1)]['contact_uuid'] is None, "contatto cancellato, l'invitato resta scritto a mano")
        r = A.push([mut('planner_events', E1, {}, op='delete')]); B.aggiorna()
        ok(not any(t == 'event_guests' and x['event_uuid'] == E1 for (t, u), x in B.copia.items()), "cancellando l'evento se ne vanno gli invitati")

        print('4. luoghi salvati')
        L1, L2 = nuovo(), nuovo()
        r = A.push([mut('planner_places', L1, {'label': NOME + ' casa', 'keywords': 'Casa, Home', 'address': 'Via Roma 1', 'place_lat': 45.4642, 'place_lon': 9.19, 'sort_key': 'a0'})]); B.aggiorna()
        p = B.copia.get(('planner_places', L1), {})
        ok(esito(r)['status'] == 'applied' and p.get('keywords') == 'casa, home' and abs(p.get('place_lat') - 45.4642) < 1e-6 and p.get('sort_key') == 'a0', f'luogo creato, parole in minuscolo: {p}')
        r = A.push([mut('planner_places', L2, {'label': NOME + ' ufficio', 'keywords': 'lavoro', 'sort_key': 'a1'})]); ok(esito(r)['status'] == 'applied', 'luogo senza coordinate: va bene')
        for patch, campo in [({'label': ''}, 'nome vuoto'), ({'place_lat': 45}, 'solo latitudine'), ({'place_lat': 95, 'place_lon': 1}, 'latitudine fuori'), ({'place_lat': 1, 'place_lon': 181}, 'longitudine fuori'),
                             ({'place_lat': 'x', 'place_lon': 1}, 'numero sbagliato'), ({'keywords': 5}, 'parole'), ({'created_at': 'x'}, 'created_at'), ({'sort_key': 'AAA!'}, 'sort_key')]:
            r = A.push([mut('planner_places', L2 if campo == 'solo latitudine' else L1, patch)]); ok(esito(r)['status'] == 'rejected', f"{campo}: rifiutato ({esito(r).get('message')})")
        r = A.push([mut('planner_places', nuovo(), {'keywords': 'senza nome'})]); ok(esito(r)['status'] == 'rejected', 'luogo nuovo senza nome: rifiutato')
        r = A.push([mut('planner_places', L1, {'place_lat': None, 'place_lon': None, 'label': NOME + ' casa mia'})]); B.aggiorna()
        p = B.copia[('planner_places', L1)]
        ok(esito(r)['status'] == 'applied' and p['place_lat'] is None and p['label'] == NOME + ' casa mia', 'le coordinate si tolgono insieme')
        r = A.push([mut('planner_places', L1, {}, op='delete'), mut('planner_places', L2, {}, op='delete')]); B.aggiorna()
        ok(all(x['status'] == 'applied' for x in r['results']) and ('planner_places', L1) not in B.copia, 'luoghi cancellati')
    finally:
        pulisci()
    print('\n' + ('TUTTO OK' if not errori else f'{len(errori)} errori'))
    sys.exit(1 if errori else 0)

main()
