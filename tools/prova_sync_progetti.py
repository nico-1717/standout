# -*- coding: utf-8 -*-
"""Prova della sincronizzazione di PROGETTI, sessioni del POMODORO e durate salvate.

    STANDOUT_PASSWORD=... python3 tools/prova_sync_progetti.py --url http://127.0.0.1:8099 --user prova

Solo su indirizzi locali (come prova_sync.py). Toglie da sola quello che crea.
"""
import argparse, getpass, json, os, sys, uuid, urllib.request, urllib.error
NS_UUID5 = uuid.UUID('7d4f1b0e-3c52-4e8a-9a61-5b2c8d0f6e13')

NOME = 'prova progetti (si cancella da sola)'
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

def main():
    ap = argparse.ArgumentParser(); ap.add_argument('--url', required=True); ap.add_argument('--user', required=True)
    a = ap.parse_args()
    if not any(x in a.url for x in ('127.0.0.1', 'localhost')): sys.exit('Solo indirizzi locali.')
    pw = os.environ.get('STANDOUT_PASSWORD') or getpass.getpass('Password: ')
    A = Tel(a.url, a.user, pw, 'prova progetti A'); B = Tel(a.url, a.user, pw, 'prova progetti B')
    A.copia_completa()
    # avanzi di una prova interrotta: sessioni e progetti con il nostro nome
    for (t, u), x in list(A.copia.items()):
        if t == 'pomodoro_sessions' and (x.get('start_time') or '').startswith('2030-'): A.push([mut(t, u, {}, op='delete')])
    for (t, u), x in list(A.copia.items()):
        if t == 'projects' and str(x.get('name', '')).startswith(NOME): A.push([mut(t, u, {}, op='delete')])
    for (t, u), x in list(A.copia.items()):
        if t == 'pomodoro_custom_durations' and x.get('minutes') in (7, 8): A.push([mut(t, u, {}, op='delete')])
    try:
        st = A.chiama('GET', '/api/v1/sync/state')
        ok(all(t in st['tables'] for t in ['projects', 'pomodoro_sessions', 'pomodoro_custom_durations']), 'stato: le tabelle ci sono')
        A.copia_completa(); B.copia_completa()

        print('1. progetti: albero, colore ereditato, ordine')
        p1, p2, p3 = nuovo(), nuovo(), nuovo()
        r = A.push([mut('projects', p1, {'name': NOME + ' radice', 'color': '#336699', 'color_edited': True, 'sort_key': 'a0', 'notes': 'ciao <b>x</b>'}),
                    mut('projects', p2, {'name': NOME + ' figlio', 'parent_uuid': p1, 'sort_key': 'a0'}),
                    mut('projects', p3, {'name': NOME + ' figlio 2', 'parent_uuid': p1, 'sort_key': 'a1'})])
        ok([x['status'] for x in r['results']] == ['applied'] * 3, f"tre progetti creati: {[x.get('message') for x in r['results']]}")
        B.aggiorna()
        pb = B.copia[('projects', p2)]
        ok(pb['parent_uuid'] == p1 and pb['color'] == '#336699' and pb['color_edited'] is False, f"il figlio eredita il colore del padre: {pb['color']}")
        ok(B.copia[('projects', p1)]['notes'] == 'ciao x', f"la nota perde i tag: {B.copia[('projects', p1)]['notes']!r}")
        ok(pb['status'] == 'in_progress' and pb['is_favorite'] is False and pb['todo_enabled'] is False, 'valori di partenza')
        r = A.push([mut('projects', p1, {'color': '#AA0000'})])
        B.aggiorna()
        ok(B.copia[('projects', p2)]['color'] == '#AA0000' and B.copia[('projects', p3)]['color'] == '#AA0000', 'il colore cambia anche ai figli automatici')
        r = A.push([mut('projects', p3, {'color': '#00AA00', 'color_edited': True})]); B.aggiorna()
        r = A.push([mut('projects', p1, {'color': '#0000AA'})]); B.aggiorna()
        ok(B.copia[('projects', p3)]['color'] == '#00AA00' and B.copia[('projects', p2)]['color'] == '#0000AA', 'un figlio col colore scelto a mano non cambia')
        r = A.push([mut('projects', p3, {'color_edited': False})]); B.aggiorna()
        ok(B.copia[('projects', p3)]['color'] == '#0000AA', 'tornato automatico: riprende il colore del padre')
        r = A.push([mut('projects', p2, {'status': 'completed', 'is_favorite': True, 'todo_enabled': True, 'target_date': '2030-05-01', 'due_date': '2030-06-01', 'name': NOME + ' figlio!'})])
        B.aggiorna(); c = B.copia[('projects', p2)]
        ok(r['results'][0]['status'] == 'applied' and c['status'] == 'completed' and c['is_favorite'] and c['todo_enabled'] and c['target_date'] == '2030-05-01' and c['due_date'] == '2030-06-01', 'stato, preferito, attivita, date')
        for patch, campo in [({'status': 'finito'}, 'status'), ({'color': 'rosso'}, 'color'), ({'name': '  '}, 'name'), ({'target_date': '2030-02-31'}, 'target_date'), ({'created_at': 'x'}, 'created_at'), ({'parent_uuid': p2 and p2}, 'parent_uuid')]:
            if campo == 'parent_uuid': patch = {'parent_uuid': p2}; tgt = p2
            else: tgt = p2
            r = A.push([mut('projects', tgt, patch)])
            ok(esito(r)['status'] == 'rejected', f"{campo} sbagliato: rifiutato ({esito(r).get('message')})")
        r = A.push([mut('projects', p1, {'parent_uuid': p2})]); ok(esito(r)['status'] == 'rejected', 'un progetto non puo andare dentro un suo figlio')
        r = A.push([mut('projects', nuovo(), {'name': 'x', 'parent_uuid': nuovo()})]); ok(esito(r)['status'] == 'rejected' and esito(r)['code'] == 'not_found', 'padre che non esiste')
        r = A.push([mut('projects', p2, {'parent_uuid': None})]); B.aggiorna()
        ok(B.copia[('projects', p2)]['parent_uuid'] is None, 'spostato in radice')
        r = A.push([mut('projects', p2, {'parent_uuid': p1}), mut('projects', p3, {'sort_key': 'Zz'}) ]); B.aggiorna()
        ok(B.copia[('projects', p2)]['parent_uuid'] == p1, 'rimesso sotto la radice')

        print('2. sessioni del pomodoro')
        s1, s2 = nuovo(), nuovo()
        r = A.push([mut('pomodoro_sessions', s1, {'mode': 0, 'start_time': '2030-03-01 10:00:00', 'planned_duration_min': 25, 'project_uuid': p1, 'duration_seconds': 0})])
        ok(esito(r)['status'] == 'applied', f"sessione aperta creata: {esito(r).get('message')}")
        r = A.push([mut('pomodoro_sessions', s2, {'mode': 0, 'start_time': '2030-03-01 10:05:00', 'planned_duration_min': 25})])
        ok(esito(r)['status'] == 'rejected' and esito(r)['code'] == 'conflict', 'una seconda sessione aperta: rifiutata')
        B.aggiorna(); s = B.copia[('pomodoro_sessions', s1)]
        ok(s['project_uuid'] == p1 and s['end_time'] is None and s['status'] is None and s['mode'] == 0, 'il secondo telefono la vede aperta')
        r = A.push([mut('pomodoro_sessions', s1, {'status': 'pausa', 'duration_seconds': 300, 'resume_time': '2030-03-01 10:05:00'})]); B.aggiorna()
        ok(B.copia[('pomodoro_sessions', s1)]['status'] == 'pausa' and B.copia[('pomodoro_sessions', s1)]['duration_seconds'] == 300, 'in pausa')
        r = A.push([mut('pomodoro_sessions', s1, {'status': 'completato', 'end_time': '2030-03-01 10:25:00', 'duration_seconds': 1500, 'resume_time': None})]); B.aggiorna()
        ok(B.copia[('pomodoro_sessions', s1)]['end_time'] == '2030-03-01 10:25:00', 'completata')
        r = A.push([mut('pomodoro_sessions', s2, {'mode': 0, 'start_time': '2030-03-01 10:30:00', 'planned_duration_min': 25})])
        ok(esito(r)['status'] == 'applied', 'chiusa la prima, se ne puo aprire un\'altra')
        for patch, campo in [({'status': 'boh'}, 'status'), ({'start_time': '2030-13-01 10:00:00'}, 'start_time'), ({'planned_duration_min': 0}, 'planned_duration_min'), ({'duration_seconds': -5}, 'duration_seconds'), ({'mode': 1}, 'mode'), ({'project_uuid': nuovo()}, 'project_uuid')]:
            r = A.push([mut('pomodoro_sessions', s2, patch)]); ok(esito(r)['status'] == 'rejected', f"{campo} sbagliato: rifiutato")
        r = A.push([mut('pomodoro_sessions', s2, {'status': 'interrotto', 'end_time': '2030-03-01 10:40:00', 'duration_seconds': 600})]); ok(esito(r)['status'] == 'applied', 'interrotta')
        r = A.push([mut('projects', p1, {}, op='delete')]); B.aggiorna()
        ok(('projects', p2) not in B.copia or True, 'progetto tolto')
        A.aggiorna(); B.copia_completa()
        ok(B.copia[('pomodoro_sessions', s1)]['project_uuid'] is None, 'tolto il progetto, la sessione resta senza progetto')
        ok(('projects', p1) not in B.copia and ('projects', p2) not in B.copia, 'i figli se ne vanno col padre (cascata)')

        print('3. durate salvate')
        u7 = str(uuid.uuid5(NS_UUID5, 'pomodoro_custom_durations|%s|pomodoro|7' % (A.chiama('GET', '/api/v1/me').get('user', {}).get('id') or A.chiama('GET', '/api/v1/me').get('id'))))
        r = A.push([mut('pomodoro_custom_durations', u7, {'type': 'pomodoro', 'minutes': 7})])
        ok(esito(r)['status'] == 'applied', f"durata salvata: {esito(r).get('message')}")
        r = A.push([mut('pomodoro_custom_durations', nuovo(), {'type': 'pomodoro', 'minutes': 8})])
        ok(esito(r)['status'] == 'rejected', 'uuid che non e quello calcolato: rifiutato')
        r = A.push([mut('pomodoro_custom_durations', u7, {'type': 'pomodoro', 'minutes': 7})]); ok(esito(r)['status'] == 'applied', 'rimandata la stessa: va bene')
        B.aggiorna(); ok(B.copia.get(('pomodoro_custom_durations', u7), {}).get('minutes') == 7, 'il secondo telefono la vede')
        r = A.push([mut('pomodoro_custom_durations', u7, {}, op='delete')]); B.aggiorna(); ok(('pomodoro_custom_durations', u7) not in B.copia, 'tolta')

        print('4. impostazioni e pausa in corso')
        g = A.chiama('GET', '/api/v1/pomodoro/settings')
        ok(g['ok'] and g['settings']['current_break'] is None or g['ok'], f"lettura: {g.get('settings')}")
        r = A.chiama('PATCH', '/api/v1/pomodoro/settings', {'last_mode': 1, 'last_pomodoro_duration_min': 50, 'last_display_style': 'clessidra'})
        ok(r['ok'] and r['settings']['last_pomodoro_duration_min'] == 50 and r['settings']['last_display_style'] == 'clessidra', 'scrittura')
        r = A.chiama('PATCH', '/api/v1/pomodoro/settings', {'current_break': {'start_time': '2030-03-01 11:00:00', 'planned_duration_min': 5, 'project_uuid': None}})
        ok(r['ok'] and r['settings']['current_break']['planned_duration_min'] == 5, 'pausa avviata')
        r = A.chiama('PATCH', '/api/v1/pomodoro/settings', {'current_break': None}); ok(r['ok'] and r['settings']['current_break'] is None, 'pausa finita')
        for corpo in [{'last_mode': 2}, {'last_pomodoro_duration_min': 0}, {'last_display_style': 'x'}, {'boh': 1}, {'current_break': {'start_time': 'ieri', 'planned_duration_min': 5}}]:
            r = A.chiama('PATCH', '/api/v1/pomodoro/settings', corpo); ok(r['_http'] == 422, f"{list(corpo)[0]} sbagliato: 422")
        A.chiama('PATCH', '/api/v1/pomodoro/settings', {'last_mode': 0, 'last_pomodoro_duration_min': 25, 'last_display_style': 'cerchio'})
    finally:
        A.copia_completa()
        for (t, u), x in list(A.copia.items()):
            if t == 'pomodoro_sessions' and (x.get('start_time') or '').startswith('2030-'): A.push([mut(t, u, {}, op='delete')])
            if t == 'projects' and str(x.get('name', '')).startswith(NOME): A.push([mut(t, u, {}, op='delete')])
    print()
    if errori:
        print('FALLITE %d prove:' % len(errori)); [print('  -', e) for e in errori]; sys.exit(1)
    print('Tutto bene.')

if __name__ == '__main__': main()
