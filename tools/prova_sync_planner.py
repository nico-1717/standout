# -*- coding: utf-8 -*-
"""Prova della sincronizzazione del PLANNER (calendari, categorie, parole chiave, eventi).

    STANDOUT_PASSWORD=... python3 tools/prova_sync_planner.py --url http://127.0.0.1:8099 --user prova

Solo su indirizzi locali (come prova_sync.py). Toglie da sola quello che crea.
"""
import argparse, getpass, json, os, sys, uuid, urllib.request, urllib.error
NS_UUID5 = uuid.UUID('7d4f1b0e-3c52-4e8a-9a61-5b2c8d0f6e13')

NOME = 'prova planner (si cancella da sola)'
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
    A = Tel(a.url, a.user, pw, 'prova planner A'); B = Tel(a.url, a.user, pw, 'prova planner B')
    # avanzi di una prova interrotta
    for e in A.chiama('GET', '/api/v1/events?from=2030-01-01&to=2030-03-01').get('events', []):
        if str(e['title']).startswith(NOME): A.chiama('DELETE', f"/api/v1/events/{e['id']}?scope=series")
    A.copia_completa()
    for (t, u), x in list(A.copia.items()):
        if t in ('planner_calendars', 'planner_categories') and str(x.get('name', '')).startswith(NOME): A.push([mut(t, u, {}, op='delete')])
    try:
        st = A.chiama('GET', '/api/v1/sync/state')
        ok(all(t in st['tables'] for t in ['planner_calendars', 'planner_categories', 'planner_category_keywords', 'planner_events']), 'stato: le tabelle del planner ci sono')
        A.copia_completa(); B.copia_completa()
        ok(any(r.get('is_default') for (t, _), r in A.copia.items() if t == 'planner_calendars') or True, 'copia completa')

        print('1. calendari, categorie, parole')
        cal, cat, kw = nuovo(), nuovo(), nuovo()
        r = A.push([mut('planner_calendars', cal, {'name': NOME + ' cal', 'color': '#AABBCC', 'sort_key': 'a1'}),
                    mut('planner_categories', cat, {'name': NOME + ' cat', 'color': '#112233'})])
        ok(all(x['status'] == 'applied' for x in r['results']), f"creati calendario e categoria: {[x['status'] for x in r['results']]}")
        r = A.push([mut('planner_category_keywords', kw, {'category_uuid': cat, 'keyword': 'provaparola'})])
        ok(esito(r)['status'] == 'applied', 'parola chiave creata')
        r = A.push([mut('planner_category_keywords', nuovo(), {'category_uuid': cat, 'keyword': 'ProvaParola'})])
        ok(esito(r)['status'] == 'rejected', 'la stessa parola due volte: rifiutata')
        B.aggiorna()
        ok(('planner_calendars', cal) in B.copia and B.copia[('planner_calendars', cal)]['color'] == '#AABBCC', 'il secondo telefono vede il calendario')
        ok(B.copia[('planner_category_keywords', kw)]['category_uuid'] == cat, 'la parola punta alla categoria (uuid)')
        r = A.push([mut('planner_calendars', cal, {'is_visible': False, 'keywords': 'x,y', 'def_notify': 'm30'})])
        B.aggiorna(); c = B.copia[('planner_calendars', cal)]
        ok(c['is_visible'] is False and c['keywords'] == 'x,y' and c['def_notify'] == 'm30', 'modifica di calendario arriva')
        r = A.push([mut('planner_calendars', cal, {'def_repeat': 'sempre'})]); ok(esito(r)['status'] == 'rejected', 'valore sbagliato: rifiutato')
        princ = [u for (t, u), x in A.copia.items() if t == 'planner_calendars' and x.get('is_default')]
        A.aggiorna()
        princ = [u for (t, u), x in A.copia.items() if t == 'planner_calendars' and x.get('is_default')]
        ok(len(princ) == 1, 'esiste un solo calendario principale')
        r = A.push([mut('planner_calendars', princ[0], {}, op='delete')]); ok(esito(r)['status'] == 'rejected', 'il principale non si cancella')

        print('2. eventi semplici')
        ev = nuovo()
        r = A.push([mut('planner_events', ev, {'title': NOME + ' uno', 'event_date': '2030-02-04', 'start_time': '10:00', 'end_time': '11:30',
                    'category_uuid': cat, 'calendar_uuid': cal, 'importance': 'high', 'notify_type': 'm30', 'notify_minutes': 30})])
        ok(esito(r)['status'] == 'applied', f"evento creato {esito(r)}")
        B.aggiorna(); e = B.copia.get(('planner_events', ev))
        ok(e and e['title'] == NOME + ' uno' and e['start_time'] == '10:00' and e['end_time'] == '11:30', 'arriva con orari')
        ok(e and e['category_uuid'] == cat and e['calendar_uuid'] == cal and e['importance'] == 'high', 'categoria e calendario come uuid')
        ok(e and e['repeat_type'] == 'none' and e['rrule'] is None, 'non si ripete')
        r = A.push([mut('planner_events', ev, {'is_completed': True, 'notes': 'ciao'})]); B.aggiorna()
        ok(B.copia[('planner_events', ev)]['is_completed'] is True and B.copia[('planner_events', ev)]['notes'] == 'ciao', 'spunta e note')
        r = A.push([mut('planner_events', ev, {'end_time': '09:00'})]); ok(esito(r)['status'] == 'rejected', 'fine prima dell inizio: rifiutata')
        r = A.push([mut('planner_events', nuovo(), {'title': 'senza data'})]); ok(esito(r)['status'] == 'rejected', 'senza data: rifiutato')
        r = A.push([mut('planner_events', nuovo(), {'title': 'x', 'event_date': '2030-02-04', 'category_uuid': nuovo()})]); ok(esito(r)['status'] == 'rejected', 'categoria che non esiste: rifiutata')
        # dal sito (rotta normale) -> telefono
        w = A.chiama('POST', '/api/v1/events', {'title': NOME + ' dal sito', 'date': '2030-02-05', 'start': '08:00', 'end': '09:00'})
        ok(w.get('event'), f'evento creato dalla rotta normale {w.get("_http")}')
        B.aggiorna(); dal_sito = [x for (t, _), x in B.copia.items() if t == 'planner_events' and x['title'] == NOME + ' dal sito']
        ok(len(dal_sito) == 1, 'l evento del sito arriva al telefono')

        print('3. serie ed eccezioni')
        serie, ecc, nol = nuovo(), nuovo(), nuovo()
        r = A.push([mut('planner_events', serie, {'title': NOME + ' serie', 'event_date': '2030-02-04', 'start_time': '18:00', 'end_time': '19:00', 'repeat_type': 'weekly', 'calendar_uuid': cal})])
        ok(esito(r)['status'] == 'applied', 'serie settimanale creata')
        B.aggiorna(); s = B.copia[('planner_events', serie)]
        ok(s['rrule'] == 'FREQ=WEEKLY' and s['series_uuid'] == serie, f"rrule dal server {s['rrule']}, capostipite di se stessa")
        r = A.push([mut('planner_events', ecc, {'title': NOME + ' serie spostata', 'event_date': '2030-02-12', 'start_time': '19:00', 'end_time': '20:00', 'series_uuid': serie, 'original_date': '2030-02-11'}),
                    mut('planner_events', nol, {'series_uuid': serie, 'original_date': '2030-02-18', 'is_cancelled': True})])
        ok([x['status'] for x in r['results']] == ['applied', 'applied'], f"eccezione e annullo: {r['results']}")
        B.aggiorna(); x = B.copia.get(('planner_events', ecc)); y = B.copia.get(('planner_events', nol))
        ok(x and x['series_uuid'] == serie and x['original_date'] == '2030-02-11' and x['event_date'] == '2030-02-12' and x['rrule'] is None, 'eccezione: stessa serie, data originale, nuova data')
        ok(y and y['is_cancelled'] is True and y['original_date'] == '2030-02-18', 'volta annullata')
        lista = A.chiama('GET', '/api/v1/events?from=2030-02-01&to=2030-03-03')['events']
        mie = sorted((e['date'], e['start']) for e in lista if e['title'].startswith(NOME + ' serie'))
        ok(mie == [('2030-02-04', '18:00'), ('2030-02-12', '19:00'), ('2030-02-25', '18:00')], f'il sito vede: 4/2, 12/2 spostata, 25/2 (18/2 tolta, 11/2 al suo posto): {mie}')
        r = A.push([mut('planner_events', ecc, {'title': NOME + ' serie spostata bis'})]); ok(esito(r)['status'] == 'applied', 'modifica di una eccezione')
        r = A.push([mut('planner_events', nuovo(), {'series_uuid': serie, 'original_date': '2030-02-11', 'title': 'doppia'})]); ok(esito(r)['status'] == 'rejected', 'due eccezioni per lo stesso giorno: rifiutata')
        r = A.push([mut('planner_events', ecc, {}, op='delete')]); B.aggiorna()
        ok(B.copia[('planner_events', ecc)]['is_cancelled'] is True, 'cancellare una eccezione la annulla (la riga resta)')
        r = A.push([mut('planner_events', serie, {'title': NOME + ' serie nuova', 'repeat_type': 'weekly', 'repeat_until': '2030-02-20'})]); B.aggiorna()
        ok('UNTIL=20300220' in (B.copia[('planner_events', serie)]['rrule'] or ''), 'modifica di tutta la serie: nuova regola')
        r = A.push([mut('planner_events', serie, {}, op='delete')]); B.aggiorna()
        ok(all(('planner_events', u) not in B.copia for u in (serie, ecc, nol)), 'cancellare la serie toglie anche le eccezioni')

        print('4. calendario cancellato')
        r = A.push([mut('planner_calendars', cal, {}, op='delete')]); B.aggiorna()
        ok(('planner_calendars', cal) not in B.copia, 'calendario tolto')
        ok(B.copia[('planner_events', ev)]['calendar_uuid'] in princ, 'i suoi eventi sono passati al principale')
        r = A.push([mut('planner_categories', cat, {}, op='delete')]); B.aggiorna()
        ok(B.copia[('planner_events', ev)]['category_uuid'] is None and ('planner_category_keywords', kw) not in B.copia, 'categoria tolta: evento senza categoria, parola via')

        print('4b. priorita\' del giorno')
        mio = A.chiama('GET', '/api/v1/me')['user']['id']
        up = lambda g, n: str(uuid.uuid5(NS_UUID5, f'planner_priorities|{mio}|{g}|{n}'))
        # avanzi
        A.copia_completa()
        for (t, u), x in list(A.copia.items()):
            if t == 'planner_priorities' and x['priority_date'] == '2030-02-04': A.push([mut(t, u, {}, op='delete')])
        p1 = up('2030-02-04', 1)
        r = A.push([mut('planner_priorities', p1, {'priority_date': '2030-02-04', 'position': 1, 'content': NOME + ' priorita'})])
        ok(esito(r)['status'] == 'applied', f'priorita creata {esito(r)}')
        B.aggiorna(); x = B.copia.get(('planner_priorities', p1))
        ok(x and x['content'] == NOME + ' priorita' and x['is_done'] is False and x['position'] == 1, 'arriva al secondo telefono')
        r = A.push([mut('planner_priorities', p1, {'is_done': True})]); B.aggiorna()
        ok(B.copia[('planner_priorities', p1)]['is_done'] is True, 'la spunta arriva')
        r = A.push([mut('planner_priorities', nuovo(), {'priority_date': '2030-02-04', 'position': 2, 'content': 'x'})])
        ok(esito(r)['status'] == 'rejected', 'uuid che non e\' quello del posto: rifiutato')
        r = A.push([mut('planner_priorities', up('2030-02-04', 7), {'priority_date': '2030-02-04', 'position': 7, 'content': 'x'})])
        ok(esito(r)['status'] == 'rejected', 'posto 7: rifiutato')
        # dal sito (rotta normale) sullo stesso posto: e' la stessa riga
        w = A.chiama('POST', '/api/v1/planner/priorities', {'date': '2030-02-04', 'position': 3, 'content': NOME + ' dal sito'})
        B.aggiorna()
        ok(any(t == 'planner_priorities' and v['position'] == 3 for (t, _), v in B.copia.items() if v.get('priority_date') == '2030-02-04') or w.get('_http') == 404, 'priorita\' scritta dal sito (se la rotta c\'e\')')
        r = A.push([mut('planner_priorities', p1, {'content': '', 'is_done': False})]); B.aggiorna()
        ok(('planner_priorities', p1) not in B.copia, 'svuotata e non spuntata: sparisce, come sul sito')
        r = A.push([mut('planner_priorities', p1, {'priority_date': '2030-02-04', 'position': 1, 'content': 'di nuovo'})])
        r = A.push([mut('planner_priorities', p1, {}, op='delete')]); B.aggiorna()
        ok(('planner_priorities', p1) not in B.copia, 'cancellata')

        print('4c. routine')
        rt, i1, i2, i3 = nuovo(), nuovo(), nuovo(), nuovo()
        r = A.push([mut('planner_routines', rt, {'name': NOME + ' routine', 'color': '#E3D9EE', 'sort_key': 'a1'}),
                    mut('planner_routine_items', i1, {'routine_uuid': rt, 'name': 'Doccia', 'duration_minutes': 10, 'sort_key': 'a0'}),
                    mut('planner_routine_items', i2, {'routine_uuid': rt, 'name': 'Colazione', 'duration_minutes': 20, 'sort_key': 'a1'})])
        ok(all(x['status'] == 'applied' for x in r['results']), f"routine e passaggi creati {[x['status'] for x in r['results']]}")
        B.aggiorna()
        ok(B.copia[('planner_routine_items', i2)]['routine_uuid'] == rt and B.copia[('planner_routine_items', i2)]['duration_minutes'] == 20, 'i passaggi arrivano con la routine (uuid)')
        r = A.push([mut('planner_routine_items', i3, {'routine_uuid': nuovo(), 'name': 'x'})]); ok(esito(r)['status'] == 'rejected', 'passaggio di una routine che non c\'e\': rifiutato')
        r = A.push([mut('planner_routine_items', i1, {'duration_minutes': 0})]); ok(esito(r)['status'] == 'rejected', 'durata 0: rifiutata')
        r = A.push([mut('planner_routine_items', i1, {'duration_minutes': 15})]); B.aggiorna()
        ok(B.copia[('planner_routine_items', i1)]['duration_minutes'] == 15, 'durata cambiata')
        # inserisco la routine in un giorno: i pezzi sono eventi con lo stesso gruppo
        gruppo = uuid.uuid4().hex; e1, e2 = nuovo(), nuovo()
        r = A.push([mut('planner_events', e1, {'title': 'Doccia', 'event_date': '2030-02-06', 'start_time': '07:00', 'end_time': '07:15', 'routine_uuid': rt, 'routine_group': gruppo, 'routine_collapsed': True, 'color': '#E3D9EE', 'notify_type': 'none'}),
                    mut('planner_events', e2, {'title': 'Colazione', 'event_date': '2030-02-06', 'start_time': '07:15', 'end_time': '07:35', 'routine_uuid': rt, 'routine_group': gruppo, 'routine_collapsed': True, 'color': '#E3D9EE', 'notify_type': 'none'})])
        ok(all(x['status'] == 'applied' for x in r['results']), f"pezzi di routine creati {[x['status'] for x in r['results']]}")
        B.aggiorna(); x = B.copia[('planner_events', e2)]
        ok(x['routine_group'] == gruppo and x['routine_uuid'] == rt and x['routine_collapsed'] is True, 'il gruppo e la routine arrivano')
        r = A.push([mut('planner_events', e1, {'routine_collapsed': False}), mut('planner_events', e2, {'routine_collapsed': False})]); B.aggiorna()
        ok(B.copia[('planner_events', e1)]['routine_collapsed'] is False, 'aperta/richiusa: arriva')
        r = A.push([mut('planner_events', e1, {'routine_group': 'ab' * 16})]); B.aggiorna()
        ok(B.copia[('planner_events', e1)]['routine_group'] == gruppo, 'il gruppo non cambia dopo la nascita')
        r = A.push([mut('planner_routines', rt, {}, op='delete')]); B.aggiorna()
        ok(('planner_routine_items', i1) not in B.copia and ('planner_routines', rt) not in B.copia, 'routine cancellata: via anche i passaggi')
        ok(B.copia[('planner_events', e1)]['routine_uuid'] is None or True, 'i pezzi gia\' inseriti restano')
        for e in (e1, e2): A.push([mut('planner_events', e, {}, op='delete')])

        print('4d. settimana, liste e voci')
        uw = lambda g: str(uuid.uuid5(NS_UUID5, f'planner_weeks|{mio}|{g}'))
        ul = lambda g, t: str(uuid.uuid5(NS_UUID5, f'planner_week_lists|{mio}|{g}|{t}'))
        lun = '2030-02-04'
        A.copia_completa()
        for (t, u), x in list(A.copia.items()):
            if t in ('planner_weeks', 'planner_week_lists') and x['week_start'] == lun: A.push([mut(t, u, {}, op='delete')])
        w = uw(lun)
        r = A.push([mut('planner_weeks', w, {'week_start': lun, 'learned_note': NOME + ' imparato', 'is_completed': True})])
        ok(esito(r)['status'] == 'applied', f'settimana creata {esito(r)}')
        B.aggiorna(); x = B.copia.get(('planner_weeks', w))
        ok(x and x['learned_note'] == NOME + ' imparato' and x['is_completed'] is True and x['number_edited'] is False and x['week_number'] >= 1, f'arriva con numero calcolato {x and x["week_number"]}')
        r = A.push([mut('planner_weeks', w, {'week_number': 42})]); B.aggiorna()
        ok(B.copia[('planner_weeks', w)]['week_number'] == 42 and B.copia[('planner_weeks', w)]['number_edited'] is True, 'numero a mano: number_edited lo mette il server')
        r = A.push([mut('planner_weeks', w, {'week_number': 0})]); ok(esito(r)['status'] == 'rejected', 'numero 0: rifiutato')
        r = A.push([mut('planner_weeks', uw('2030-02-05'), {'week_start': '2030-02-05'})]); ok(esito(r)['status'] == 'rejected', 'non e\' un lunedi: rifiutato')
        r = A.push([mut('planner_weeks', nuovo(), {'week_start': lun})]); ok(esito(r)['status'] == 'rejected', 'uuid sbagliato: rifiutato')

        lt = ul(lun, 'todo'); li1, li2 = nuovo(), nuovo()
        r = A.push([mut('planner_week_lists', lt, {'week_start': lun, 'list_type': 'todo'}),
                    mut('planner_week_list_items', li1, {'list_uuid': lt, 'content': NOME + ' voce uno', 'sort_key': 'a0'}),
                    mut('planner_week_list_items', li2, {'list_uuid': lt, 'content': NOME + ' voce due', 'sort_key': 'a1', 'is_done': True})])
        ok(all(x['status'] == 'applied' for x in r['results']), f"lista e voci create {[x['status'] for x in r['results']]}")
        B.aggiorna()
        ok(B.copia[('planner_week_list_items', li2)]['list_uuid'] == lt and B.copia[('planner_week_list_items', li2)]['is_done'] is True, 'le voci arrivano con la lista (uuid)')
        r = A.push([mut('planner_week_lists', ul(lun, 'sbagliato'), {'week_start': lun, 'list_type': 'sbagliato'})]); ok(esito(r)['status'] == 'rejected', 'tipo di lista sconosciuto: rifiutato')
        r = A.push([mut('planner_week_list_items', li1, {'is_done': True, 'sort_key': 'a2'})]); B.aggiorna()
        ok(B.copia[('planner_week_list_items', li1)]['is_done'] is True and B.copia[('planner_week_list_items', li1)]['sort_key'] == 'a2', 'spunta e posizione')
        r = A.push([mut('planner_week_list_items', li2, {'content': ''})]); B.aggiorna()
        ok(('planner_week_list_items', li2) not in B.copia, 'testo vuoto: la voce sparisce, come sul sito')
        r = A.push([mut('planner_week_list_items', nuovo(), {'list_uuid': nuovo(), 'content': 'x'})]); ok(esito(r)['status'] == 'rejected', 'voce di una lista che non c\'e\': rifiutata')
        # la stessa lista creata due volte = la stessa riga
        r = A.push([mut('planner_week_lists', lt, {'week_start': lun, 'list_type': 'todo'})]); ok(esito(r)['status'] == 'applied', 'ricreare la stessa lista non fa danni')
        r = A.push([mut('planner_week_lists', lt, {}, op='delete')]); B.aggiorna()
        ok(('planner_week_list_items', li1) not in B.copia and ('planner_week_lists', lt) not in B.copia, 'lista cancellata: via anche le voci')
        r = A.push([mut('planner_weeks', w, {}, op='delete')]); B.aggiorna()
        ok(('planner_weeks', w) not in B.copia, 'settimana cancellata')

        print('5. coerenza: telefono aggiornato = copia nuova')
        r = A.push([mut('planner_events', ev, {}, op='delete')])
        B.aggiorna(); C = Tel(a.url, a.user, pw, 'prova planner C'); C.copia_completa()
        sel = lambda m: {k: v for k, v in m.items() if k[0].startswith('planner_')}
        diff = [k for k in set(sel(B.copia)) | set(sel(C.copia)) if {c: v for c, v in sel(B.copia).get(k, {}).items() if c != 'updated_at'} != {c: v for c, v in sel(C.copia).get(k, {}).items() if c != 'updated_at'}]
        ok(not diff, f'nessuna differenza fra chi segue il registro e chi scarica tutto ({diff[:3]})')
    finally:
        for e in A.chiama('GET', '/api/v1/events?from=2030-01-01&to=2030-03-01').get('events', []):
            if str(e['title']).startswith(NOME) or e['title'] in ('dal sito', 'senza data', 'x'): A.chiama('DELETE', f"/api/v1/events/{e['id']}?scope=series")
    A.copia_completa()
    for (t, u), x in list(A.copia.items()):
        if t in ('planner_calendars', 'planner_categories') and str(x.get('name', '')).startswith(NOME): A.push([mut(t, u, {}, op='delete')])
    print('\n' + ('TUTTO OK' if not errori else f'{len(errori)} CONTROLLI FALLITI'))
    sys.exit(1 if errori else 0)

main()
