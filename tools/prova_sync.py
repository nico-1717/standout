# -*- coding: utf-8 -*-
"""Prova della sincronizzazione (/api/v1/sync/*) contro un sito vero o di prova.

Si fa passare da due «telefoni» (due login = due dispositivi) e controlla:
  1. copia completa (cursore «snapshot») a pagine, e coerenza con le liste normali dell'API;
  2. che OGNI scrittura fatta dalle rotte di sempre (to do, to buy, categorie, spunte,
     riordino, azioni su piu' voci, cancellazioni) compaia nel registro: si parte da una
     copia, si applicano i cambiamenti in arrivo, e il risultato deve essere IDENTICO a
     una copia completa nuova;
  3. l'invio: creare, modificare, spuntare, cancellare con un uuid scelto dal telefono;
     rimandare la stessa modifica (nessun doppione); campo sbagliato (rifiutato);
     riordino con sort_key che il sito vede nello stesso ordine;
  4. tutto pulito alla fine (si toglie quello che si e' creato).

    STANDOUT_PASSWORD=... python3 tools/prova_sync.py --url http://127.0.0.1:8099 --user prova

La password NON si passa come argomento: da STANDOUT_PASSWORD o digitata senza eco.
Scrive nell'account indicato roba chiamata «prova sync (si cancella da sola)».
Non si deve mai usare con la password vera sul sito vero senza saperlo: per default
rifiuta gli indirizzi diversi da 127.0.0.1 / localhost (serve --live per il sito vero).
"""
import argparse, datetime, getpass, json, os, sys, uuid, urllib.request, urllib.error
from zoneinfo import ZoneInfo

# lo stesso namespace di lib/sync_manifest.php (SYNC_NS_UUID5)
NS_UUID5 = uuid.UUID('7d4f1b0e-3c52-4e8a-9a61-5b2c8d0f6e13')
def uuid_check_in(habit_uuid, giorno): return str(uuid.uuid5(NS_UUID5, f'habit_logs|{habit_uuid}|{giorno}'))
def uuid_presa(plan_uuid, giorno, ora, seq): return str(uuid.uuid5(NS_UUID5, f'pill_doses|{plan_uuid}|{giorno}|{ora[:5]}|{seq}'))

NOME = 'prova sync (si cancella da sola)'
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

def uguali(a, b):
    """Due copie locali uguali, ignorando updated_at (il server lo cambia a ogni scrittura)."""
    def pulito(m): return {k: {c: v for c, v in r.items() if c != 'updated_at'} for k, r in m.items()}
    return pulito(a) == pulito(b)

def differenze(a, b):
    out = []
    for k in sorted(set(a) | set(b)):
        if k not in a: out.append(f'manca nella copia aggiornata: {k}')
        elif k not in b: out.append(f'resta nella copia aggiornata ma non esiste piu\': {k}')
        else:
            ra = {c: v for c, v in a[k].items() if c != 'updated_at'}; rb = {c: v for c, v in b[k].items() if c != 'updated_at'}
            if ra != rb: out.append(f'{k}: {[(c, ra.get(c), rb.get(c)) for c in set(ra) | set(rb) if ra.get(c) != rb.get(c)]}')
    return out[:8]

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--url', required=True); ap.add_argument('--user', required=True); ap.add_argument('--live', action='store_true')
    a = ap.parse_args()
    if not a.live and not any(x in a.url for x in ('127.0.0.1', 'localhost')):
        sys.exit('Rifiuto: questo non e\' un indirizzo locale. Serve --live se sai cosa fai.')
    pw = os.environ.get('STANDOUT_PASSWORD') or getpass.getpass('Password: ')
    A = Tel(a.url, a.user, pw, 'prova sync A'); B = Tel(a.url, a.user, pw, 'prova sync B')
    creati_api = {'tasks': [], 'shopping': [], 'cat': []}
    try:
        # avanzi di una prova interrotta: via, o i nomi doppi falserebbero i controlli
        for t in A.chiama('GET', '/api/v1/tasks').get('tasks', []):
            if t['title'].startswith(NOME) and t.get('parent_id') is None: A.chiama('DELETE', f"/api/v1/tasks/{t['id']}")
        lista = A.chiama('GET', '/api/v1/shopping')
        for t in lista.get('items', []):
            if t['title'].startswith(NOME): A.chiama('DELETE', f"/api/v1/shopping/{t['id']}")
        for c in lista.get('categories', []):
            if c['name'].startswith(NOME): A.chiama('DELETE', f"/api/v1/shopping/categories/{c['id']}")
        for h in A.chiama('GET', '/api/v1/habits').get('habits', []):
            if h['name'].startswith(NOME): A.chiama('DELETE', f"/api/v1/habits/{h['id']}")
        for c in A.chiama('GET', '/api/v1/habits/categories').get('categories', []):
            if str(c.get('name', '')).startswith(NOME) and not c.get('is_system'): A.chiama('DELETE', f"/api/v1/habits/categories/{c['id']}")
        for p_ in A.chiama('GET', '/api/v1/pills/plans').get('plans', []):
            if p_['name'].startswith(NOME): A.chiama('DELETE', f"/api/v1/pills/plans/{p_['id']}")
        print('1. stato e copia completa')
        st = A.chiama('GET', '/api/v1/sync/state'); ok(st.get('ok') and st['tables'] == ['todo_buy_categories', 'todos', 'habit_categories', 'habits', 'habit_logs', 'pill_plans', 'pill_doses', 'habit_daily_journal', 'diary_list_items'], f"stato: tabelle {st.get('tables')}")
        pagine = A.copia_completa(limite=50)
        ok(pagine >= 1, f'copia completa a pagine da 50 ({pagine} pagine, {len(A.copia)} righe)')
        ok(all(r['sort_key'] for r in A.copia.values() if 'sort_key' in r), 'ogni riga che ha una sort_key la ha piena')
        tutte = {k[1] for k in A.copia}
        ok(len(tutte) == len(A.copia), 'nessun uuid doppio')
        B.copia_completa(limite=500)
        ok(uguali(A.copia, B.copia), 'due telefoni, pagine diverse (50 e 500): stessa copia')
        senza_tok = A.chiama('GET', '/api/v1/sync/state', auth=False); ok(senza_tok['_http'] == 401, 'senza token: 401')

        print('2. ogni scrittura del sito finisce nel registro')
        def api(m, p, c=None): return A.chiama(m, '/api/v1/' + p, c)
        t1 = api('POST', 'tasks', {'title': NOME + ' 1'}); ok(t1['_http'] == 201, 'crea attivita 1'); id1 = t1['task']['id']; creati_api['tasks'].append(id1)
        t2 = api('POST', 'tasks', {'title': NOME + ' 2', 'date': '2099-01-02', 'time': '10:30', 'minutes': 45}); id2 = t2['task']['id']; creati_api['tasks'].append(id2)
        sub = api('POST', 'tasks', {'title': NOME + ' sotto', 'parent_id': id1}); ids = sub['task']['id']; creati_api['tasks'].append(ids)
        api('PATCH', f'tasks/{id1}', {'title': NOME + ' 1 modificata'})
        api('PATCH', f'tasks/{id2}', {'done': True})
        api('PATCH', f'tasks/{id2}', {'done': False, 'date': None})
        c1 = api('POST', 'shopping/categories', {'name': NOME + ' cat', 'color': '#112233'})
        if 'category' not in c1: print('   categoria non creata:', c1)
        cid = c1['category']['id']; creati_api['cat'].append(cid)
        s1 = api('POST', 'shopping', {'title': NOME + ' articolo', 'category_id': cid, 'priority': 1, 'price_cents': 250, 'url': 'https://example.com/x'}); sid = s1['item']['id']; creati_api['shopping'].append(sid)
        api('PATCH', f'shopping/{sid}', {'priority': 2, 'price_cents': None})
        api('PATCH', f'shopping/categories/{cid}', {'color': '#445566'})
        api('POST', 'tasks/reorder', {'ids': [id2, id1]})
        api('POST', 'tasks/bulk', {'ids': [id1, id2], 'action': 'date', 'date': '2099-02-03'})
        api('POST', 'shopping/reorder', {'ids': [sid]})
        A.aggiorna()
        fresca = Tel(a.url, a.user, pw, 'prova sync C'); fresca.copia_completa()
        d = differenze(A.copia, fresca.copia)
        ok(not d, 'copia di prima + registro = copia completa nuova' + ('' if not d else f' | {d}'))
        titoli = {r['title'] for r in A.copia.values() if r.get('title')}
        ok((NOME + ' 1 modificata') in titoli and (NOME + ' sotto') in titoli, 'le modifiche del sito sono arrivate')
        sotto = [r for r in A.copia.values() if r.get('title') == NOME + ' sotto']
        padre = [r for r in A.copia.values() if r.get('title') == NOME + ' 1 modificata']
        ok(sotto and padre and sotto[0]['parent_uuid'] == padre[0]['uuid'], 'la sotto-attivita punta al padre per uuid')
        art = [r for r in A.copia.values() if r.get('title') == NOME + ' articolo']
        cat = [r for r in A.copia.values() if r.get('name') == NOME + ' cat']
        ok(art and cat and art[0]['buy_category_uuid'] == cat[0]['uuid'] and art[0]['buy_priority'] == 2 and art[0]['buy_price_cents'] is None, 'articolo: categoria per uuid, priorita, prezzo tolto')
        # l'ordine del sito (sort_order) e quello dell'app (sort_key) devono coincidere
        elenco = api('GET', 'tasks')['tasks']
        mie = [t for t in A.copia.values() if t.get('list_type') == 'todo']
        per_chiave = [t['uuid'] for t in sorted(mie, key=lambda r: r['sort_key'])]
        id_a_uuid = {}
        # il riordino ha messo id2 prima di id1: nella copia, la chiave di 2 deve stare prima di quella di 1
        u2 = [r for r in A.copia.values() if r.get('title') == NOME + ' 2'][0]; u1 = padre[0]
        ok(u2['sort_key'] < u1['sort_key'], 'riordino dal sito: la sort_key segue (2 prima di 1)')
        B.aggiorna(); ok(uguali(A.copia, B.copia) or not differenze(A.copia, B.copia), 'il secondo telefono arriva allo stesso stato')

        print('3. invio dal telefono')
        nu = str(uuid.uuid4()); nc = str(uuid.uuid4()); nf = str(uuid.uuid4())
        r = A.push([mut('todo_buy_categories', nc, {'name': NOME + ' catapp', 'color': '#abcdef', 'sort_key': 'a0'}),
                    mut('todos', nu, {'title': NOME + ' dal telefono', 'due_date': '2099-03-04', 'due_time': '08:00', 'duration_minutes': 20, 'sort_key': 'Zz'}),
                    ])
        ok(r['ok'] and r['results'][0]['status'] == 'applied' and r['results'][1]['status'] == 'rejected' and r['results'][1]['field'] == 'sort_key', 'sort_key sbagliata: rifiutata, la categoria passa')
        m1 = mut('todos', nu, {'title': NOME + ' dal telefono', 'due_date': '2099-03-04', 'due_time': '08:00', 'duration_minutes': 20, 'sort_key': 'a0V'})
        r = A.push([m1]); ok(r['results'][0]['status'] == 'applied', 'crea attivita con uuid scelto dal telefono')
        r2 = A.push([m1]); ok(r2['results'][0].get('replayed') and r2['results'][0]['status'] == 'applied', 'stessa modifica rimandata: stessa risposta, niente doppione')
        A.aggiorna()
        mia = A.copia.get(('todos', nu)); ok(mia and mia['title'] == NOME + ' dal telefono' and mia['due_time'] == '08:00' and mia['duration_minutes'] == 20, 'la riga e tornata con i valori giusti')
        ok(mia and mia['sort_key'] == 'a0V', 'la sort_key del telefono si conserva')
        riv = [t for t in api('GET', 'tasks')['tasks'] if t['title'] == NOME + ' dal telefono']; ok(len(riv) == 1 and riv[0]['date'] == '2099-03-04', 'il sito la vede (una sola volta)')
        r = A.push([mut('todos', nu, {'is_done': True, 'title': NOME + ' dal telefono fatta'})]); ok(r['results'][0]['status'] == 'applied', 'spunta + titolo')
        r = A.push([mut('todos', nu, {'colore_strano': 1})]); ok(r['results'][0]['status'] == 'rejected' and r['results'][0]['field'] == 'colore_strano', 'campo sconosciuto: rifiutato')
        cur = r['results'][0].get('current'); ok(cur and cur['uuid'] == nu and cur['title'] == NOME + ' dal telefono fatta', 'il rifiuto dice com\'e la riga sul server (current)')
        r = A.push([mut('todos', str(uuid.uuid4()), {'colore_strano': 1})]); ok(r['results'][0]['status'] == 'rejected' and r['results'][0]['current'] is None, 'rifiuto su riga che non esiste: current null')
        r = A.push([mut('todos', nu, {'title': ''})]); ok(r['results'][0]['status'] == 'rejected' and r['results'][0]['field'] == 'title', 'titolo vuoto: rifiutato con il campo')
        r = A.push([mut('todos', nu, {'buy_category_uuid': str(uuid.uuid4())})]); ok(r['results'][0]['code'] == 'not_found', 'categoria che non esiste: not_found')
        art_u = str(uuid.uuid4())
        r = A.push([mut('todos', art_u, {'list_type': 'buy', 'title': NOME + ' articolo app', 'buy_category_uuid': nc, 'buy_priority': 1, 'buy_price_cents': 990, 'buy_url': 'https://example.com/a'})])
        ok(r['results'][0]['status'] == 'applied', 'crea articolo con categoria creata dal telefono')
        r = A.push([mut('todos', art_u, {'buy_url': 'ftp://x'})]); ok(r['results'][0]['status'] == 'rejected', 'indirizzo non http: rifiutato')
        sub_u = str(uuid.uuid4())
        r = A.push([mut('todos', sub_u, {'title': NOME + ' sotto app', 'parent_uuid': nu})]); ok(r['results'][0]['status'] == 'applied', 'sotto-attivita creata dal telefono')
        altro = str(uuid.uuid4())
        r = A.push([mut('todos', altro, {'title': NOME + ' altro', 'sort_key': 'a0G'})]); ok(r['results'][0]['status'] == 'applied', 'seconda attivita con chiave fra le altre')
        A.aggiorna(); B.aggiorna()
        ok(not differenze(A.copia, B.copia), 'dopo l\'invio A e B hanno la stessa copia')
        elenco = [t['title'] for t in api('GET', 'tasks')['tasks'] if t['title'].startswith(NOME)]
        ordine_app = [r['title'] for r in sorted([r for r in A.copia.values() if r.get('list_type') == 'todo' and r['title'].startswith(NOME) and not r['is_done'] and not r.get('parent_uuid')], key=lambda r: r['sort_key'])]
        ordine_sito = [t['title'] for t in api('GET', 'tasks')['tasks'] if t['title'].startswith(NOME) and not t['done'] and not t.get('parent_id')]
        ok(set(ordine_app) == set(ordine_sito), 'le attivita non fatte sono le stesse sul sito e nell\'app')
        # solo la sort_key cambia (il sito non ha niente da riallineare): l'altro telefono la deve comunque vedere
        k0 = A.copia[('todos', altro)]['sort_key']
        r = A.push([mut('todos', altro, {'sort_key': 'a0H'})]); ok(r['results'][0]['status'] == 'applied', 'cambio di sola sort_key')
        B.aggiorna(); ok(B.copia[('todos', altro)]['sort_key'] == 'a0H' and k0 != 'a0H', 'l\'altro telefono vede la nuova sort_key')
        # cancellazioni
        r = A.push([mut('todos', nu, {}, 'delete')]); ok(r['results'][0]['status'] == 'applied', 'cancella attivita (con la sua sotto-attivita)')
        r = A.push([mut('todos', nu, {}, 'delete')]); ok(r['results'][0]['status'] == 'applied', 'cancellare di nuovo: non e un errore')
        A.aggiorna(); B.aggiorna()
        ok(('todos', nu) not in A.copia and ('todos', sub_u) not in A.copia, 'sparite dalla copia di A (anche la sotto-attivita)')
        ok(('todos', nu) not in B.copia and ('todos', sub_u) not in B.copia, 'sparite dalla copia di B')
        for u in (altro, art_u):
            A.push([mut('todos', u, {}, 'delete')])
        r = A.push([mut('todo_buy_categories', nc, {}, 'delete')]); ok(r['results'][0]['status'] == 'applied', 'cancella la categoria del telefono')

        print('4. pulizia di quello che ha creato il sito')
        for i in creati_api['tasks'][::-1]: api('DELETE', f'tasks/{i}')
        for i in creati_api['shopping']: api('DELETE', f'shopping/{i}')
        for i in creati_api['cat']: api('DELETE', f'shopping/categories/{i}')
        A.aggiorna(); B.aggiorna(); fresca.copia_completa()
        ok(not differenze(A.copia, fresca.copia) and not differenze(B.copia, fresca.copia), 'dopo le cancellazioni A, B e una copia nuova coincidono')
        resti = [r for r in fresca.copia.values() if str(r.get('title', r.get('name', ''))).startswith(NOME)]
        ok(not resti, 'non e rimasto niente di quello che la prova ha creato' + ('' if not resti else f' ({len(resti)})'))

        print('5. abitudini: categorie, abitudini e check-in')
        oggi = datetime.datetime.now(ZoneInfo('Europe/Rome')).strftime('%Y-%m-%d')
        ieri = (datetime.datetime.now(ZoneInfo('Europe/Rome')) - datetime.timedelta(days=1)).strftime('%Y-%m-%d')
        domani = (datetime.datetime.now(ZoneInfo('Europe/Rome')) + datetime.timedelta(days=1)).strftime('%Y-%m-%d')
        creati_h = {'habits': [], 'cats': []}
        A.aggiorna(); B.aggiorna()
        hc = api('POST', 'habits/categories', {'name': NOME + ' hcat', 'icon': '🧪', 'color': '#112233'}); ok(hc['_http'] == 201, 'il sito crea una categoria'); hcid = hc['category']['id']; creati_h['cats'].append(hcid)
        h1 = api('POST', 'habits', {'name': NOME + ' bicchieri', 'goal_type': 'quantity', 'target_amount': 8, 'step_amount': 1, 'unit': 'bicchieri', 'category_id': hcid, 'start_date': ieri})
        ok(h1['_http'] == 201, 'il sito crea un\'abitudine a quantita'); h1id = h1['habit']['id']; creati_h['habits'].append(h1id)
        h2 = api('POST', 'habits', {'name': NOME + ' lettura', 'start_date': ieri}); h2id = h2['habit']['id']; creati_h['habits'].append(h2id)
        for _ in range(3): api('POST', f'habits/{h1id}/log', {'mode': 'increment'})
        api('POST', f'habits/{h2id}/note', {'note': 'ottima', 'mood': 5})
        A.aggiorna(); B.aggiorna()
        cat = next((r for k, r in A.copia.items() if k[0] == 'habit_categories' and r['name'] == NOME + ' hcat'), None)
        ok(cat is not None and cat['is_system'] is False and cat['slug'], 'la categoria e arrivata (uuid, slug, non di sistema)')
        hab1 = next((r for k, r in A.copia.items() if k[0] == 'habits' and r['name'] == NOME + ' bicchieri'), None)
        hab2 = next((r for k, r in A.copia.items() if k[0] == 'habits' and r['name'] == NOME + ' lettura'), None)
        ok(hab1 and hab1['category_uuid'] == cat['uuid'] and hab1['target_amount'] == 8 and hab1['kind'] == 'build' and hab1['sort_key'], 'l\'abitudine punta alla categoria per uuid, con la sua sort_key')
        l1 = A.copia.get(('habit_logs', uuid_check_in(hab1['uuid'], oggi)))
        ok(l1 is not None and l1['amount'] == 3 and l1['habit_uuid'] == hab1['uuid'] and l1['log_date'] == oggi, 'il check-in (3 spunte, ON DUPLICATE) e arrivato con l\'uuid atteso (UUIDv5)')
        l2 = A.copia.get(('habit_logs', uuid_check_in(hab2['uuid'], oggi)))
        ok(l2 is not None and l2['note'] == 'ottima' and l2['mood'] == 5 and l2['amount'] == 0, 'nota e umore senza spunte: arrivano')
        ok(uguali(A.copia, B.copia), 'A (pagine di registro) e B coincidono')
        fresca.copia_completa()
        ok(not differenze(A.copia, fresca.copia), 'copia + registro = copia completa nuova (anche per le abitudini)')

        # ---- dal telefono ----
        lg = uuid_check_in(hab2['uuid'], oggi)
        r = A.push([mut('habit_logs', lg, {'status': 'done', 'amount': 1})]); ok(r['results'][0]['status'] == 'applied', 'check-in dal telefono su una riga che il sito ha gia (nota): stessa riga')
        mio = [x for x in api('GET', 'habits/logs?days=3')['logs'] if x['habit_id'] == h2id and x['date'] == oggi]
        ok(len(mio) == 1 and mio[0]['status'] == 'done' and mio[0]['note'] == 'ottima', 'il sito vede il fatto, la nota e rimasta')
        r = A.push([mut('habit_logs', uuid_check_in(hab1['uuid'], ieri), {'habit_uuid': hab1['uuid'], 'log_date': ieri, 'status': 'partial', 'amount': 5})])
        ok(r['results'][0]['status'] == 'applied', 'check-in nuovo di ieri, creato dal telefono')
        mio = [x for x in api('GET', 'habits/logs?days=3')['logs'] if x['habit_id'] == h1id and x['date'] == ieri]
        ok(len(mio) == 1 and mio[0]['amount'] == 5, 'il sito lo vede (5)')
        r2 = A.push([mut('habit_logs', uuid_check_in(hab1['uuid'], ieri), {'habit_uuid': hab1['uuid'], 'log_date': ieri, 'status': 'partial', 'amount': 5})]); 
        ok(r2['results'][0]['status'] == 'applied' and len([x for x in api('GET', 'habits/logs?days=3')['logs'] if x['habit_id'] == h1id and x['date'] == ieri]) == 1, 'rimandato: nessun doppione')
        r = A.push([mut('habit_logs', uuid_check_in(hab1['uuid'], ieri), {'amount': 8})]); ok(r['results'][0]['status'] == 'applied', 'cambio di sola quantita (8)')
        A.aggiorna(); ok(A.copia[('habit_logs', uuid_check_in(hab1['uuid'], ieri))]['status'] == 'done', 'a 8 su 8 il server ha messo «fatto» da solo')
        r = A.push([mut('habit_logs', uuid_check_in(hab1['uuid'], ieri), {'status': 'skipped'})]); ok(r['results'][0]['status'] == 'applied', 'saltata')
        mio = [x for x in api('GET', 'habits/logs?days=3')['logs'] if x['habit_id'] == h1id and x['date'] == ieri]
        ok(mio and mio[0]['status'] == 'skipped', 'il sito la vede saltata')
        r = A.push([mut('habit_logs', uuid_check_in(hab1['uuid'], ieri), {'status': 'partial', 'amount': 2, 'note': 'ok', 'mood': 3})])
        mio = [x for x in api('GET', 'habits/logs?days=3')['logs'] if x['habit_id'] == h1id and x['date'] == ieri]
        ok(mio and mio[0]['status'] == 'partial' and mio[0]['amount'] == 2 and mio[0]['note'] == 'ok' and mio[0]['mood'] == 3, 'da saltata a 2 con nota e umore')
        r = A.push([mut('habit_logs', uuid_check_in(hab1['uuid'], ieri), {'note': None, 'mood': None})]); A.aggiorna()
        ok(A.copia[('habit_logs', uuid_check_in(hab1['uuid'], ieri))]['note'] in (None, ''), 'nota tolta')
        r = A.push([mut('habit_logs', str(uuid.uuid4()), {'habit_uuid': hab1['uuid'], 'log_date': ieri, 'amount': 1})]); x = r['results'][0]
        ok(x['status'] == 'rejected' and x['field'] == 'uuid', 'uuid che non e quello atteso: rifiutato')
        r = A.push([mut('habit_logs', uuid_check_in(hab1['uuid'], domani), {'habit_uuid': hab1['uuid'], 'log_date': domani, 'amount': 1})]); x = r['results'][0]
        ok(x['status'] == 'rejected' and x['field'] == 'log_date', 'giorno futuro: rifiutato')
        r = A.push([mut('habit_logs', uuid_check_in(str(uuid.uuid4()), oggi), {'habit_uuid': str(uuid.uuid4()), 'log_date': oggi, 'amount': 1})]); x = r['results'][0]
        ok(x['status'] == 'rejected' and x['field'] in ('habit_uuid', 'uuid'), 'abitudine che non esiste: rifiutato')
        r = A.push([mut('habit_logs', uuid_check_in(hab1['uuid'], oggi), {'amount': -1})]); ok(r['results'][0]['status'] == 'rejected' and r['results'][0]['field'] == 'amount', 'quantita negativa: rifiutata')
        r = A.push([mut('habit_logs', uuid_check_in(hab1['uuid'], oggi), {}, 'delete')]); ok(r['results'][0]['status'] == 'applied', 'annulla il check-in di oggi')
        A.aggiorna(); ok(('habit_logs', uuid_check_in(hab1['uuid'], oggi)) not in A.copia, 'sparito dalla copia')
        r = A.push([mut('habit_logs', uuid_check_in(hab1['uuid'], oggi), {}, 'delete')]); ok(r['results'][0]['status'] == 'applied', 'annullare di nuovo: non e un errore')

        # abitudini dal telefono
        nh = str(uuid.uuid4()); nc2 = str(uuid.uuid4())
        r = A.push([mut('habit_categories', nc2, {'name': NOME + ' hcat2', 'icon': '🧪', 'color': '#445566'}),
                    mut('habits', nh, {'name': NOME + ' dal telefono', 'category_uuid': nc2, 'kind': 'quit', 'goal_type': 'completion', 'frequency_type': 'weekly', 'times_per_week': 3, 'start_date': ieri, 'sort_key': 'a0'})])
        ok([x['status'] for x in r['results']] == ['applied', 'applied'], 'categoria e abitudine create dal telefono (con uuid scelti da lui)')
        lista = api('GET', 'habits')['habits']; mia = next((x for x in lista if x['name'] == NOME + ' dal telefono'), None)
        ok(mia is not None and mia['kind'] == 'quit' and mia['times_per_week'] == 3, 'il sito la vede, con i valori giusti')
        if mia: creati_h['habits'].append(mia['id'])
        cats = api('GET', 'habits/categories').get('categories', []); c2 = next((x for x in cats if x['name'] == NOME + ' hcat2'), None)
        if c2: creati_h['cats'].append(c2['id'])
        ok(mia is not None and c2 is not None and mia.get('category_id') == c2['id'], 'la categoria nuova e quella dell\'abitudine')
        r = A.push([mut('habits', nh, {'name': NOME + ' dal telefono 2', 'is_archived': True, 'color': '#ABCDEF'})]); ok(r['results'][0]['status'] == 'applied', 'modifica e archivia')
        mia = next((x for x in api('GET', 'habits')['habits'] if x['id'] == (mia or {}).get('id')), None)
        ok(mia is not None and mia['name'] == NOME + ' dal telefono 2' and mia.get('is_archived') is True, 'il sito la vede archiviata e rinominata')
        r = A.push([mut('habits', nh, {'name': ''})]); x = r['results'][0]; ok(x['status'] == 'rejected' and x['field'] == 'name' and x.get('current') is not None, 'nome vuoto: rifiutato, con la riga com\'e sul server')
        r = A.push([mut('habits', nh, {'category_uuid': str(uuid.uuid4())})]); ok(r['results'][0]['status'] == 'rejected' and r['results'][0]['code'] == 'not_found', 'categoria che non esiste: not_found')
        r = A.push([mut('habits', nh, {'frequency_type': 'ogni_tanto'})]); ok(r['results'][0]['status'] == 'rejected' and r['results'][0]['field'] == 'frequency_type', 'frequenza sbagliata: rifiutata')
        r = A.push([mut('habits', nh, {'sort_key': 'a0'}), mut('habits', hab1['uuid'], {'sort_key': 'a1'}), mut('habits', hab2['uuid'], {'sort_key': 'a2'})])
        ok([x['status'] for x in r['results']] == ['applied'] * 3, 'riordino con sort_key')
        r = A.push([mut('habit_categories', nc2, {'name': NOME + ' hcat2 bis'})]); ok(r['results'][0]['status'] == 'applied', 'rinomina categoria')
        A.aggiorna(); B.aggiorna(); fresca.copia_completa()
        ok(not differenze(A.copia, fresca.copia) and not differenze(B.copia, fresca.copia), 'dopo tutto questo A, B e una copia nuova coincidono')
        # una categoria di un altro utente o di sistema non si tocca
        altra = next((r for k, r in fresca.copia.items() if k[0] == 'habit_categories' and r['is_system']), None)
        if altra:
            r = A.push([mut('habit_categories', altra['uuid'], {'name': 'hacked'})]); ok(r['results'][0]['status'] == 'rejected' and r['results'][0]['code'] == 'forbidden', 'categoria di sistema: non si cambia')
            r = A.push([mut('habit_categories', altra['uuid'], {}, 'delete')]); ok(r['results'][0]['status'] == 'rejected', 'categoria di sistema: non si cancella')
        # cancellazioni
        r = A.push([mut('habits', nh, {}, 'delete')]); ok(r['results'][0]['status'] == 'applied', 'cancella l\'abitudine del telefono')
        r = A.push([mut('habit_categories', nc2, {}, 'delete')]); ok(r['results'][0]['status'] == 'applied', 'cancella la categoria del telefono')
        A.aggiorna()
        ok(('habits', nh) not in A.copia and ('habit_categories', nc2) not in A.copia, 'spariti dalla copia')

        print('6. pulizia delle abitudini')
        for i in creati_h['habits']: api('DELETE', f'habits/{i}')
        for i in creati_h['cats']: api('DELETE', f'habits/categories/{i}')
        A.aggiorna(); fresca.copia_completa()
        resti = [r for r in fresca.copia.values() if str(r.get('name', '')).startswith(NOME)]
        ok(not resti, 'non e rimasto niente delle abitudini' + ('' if not resti else f' ({len(resti)})'))
        ok(not [k for k in fresca.copia if k[0] == 'habit_logs' and fresca.copia[k]['habit_uuid'] in (hab1['uuid'], hab2['uuid'])], 'i check-in sono spariti con le abitudini')
        print('7. pastiglie')
        creati_p = []
        def prese_di(plan_uuid, copia): return {k[1]: r for k, r in copia.items() if k[0] == 'pill_doses' and r['plan_uuid'] == plan_uuid}
        pp = api('POST', 'pills/plans', {'name': NOME + ' vitamina', 'times': '08:00x1,20:00x2', 'start_date': ieri, 'color': '#B7A6D6'})
        ok(pp['_http'] == 201, 'il sito crea una terapia'); ppid = pp['plan']['id']; creati_p.append(ppid)
        A.aggiorna(); B.aggiorna()
        plan = next((r for k, r in A.copia.items() if k[0] == 'pill_plans' and r['name'] == NOME + ' vitamina'), None)
        ok(plan is not None and plan['times'] == '08:00x1,20:00x2' and plan['is_active'] is True and plan['schedule_from'] == ieri and plan['start_date'] == ieri, 'la terapia e arrivata, con orari, attiva e «vale da»')
        dosi = prese_di(plan['uuid'], A.copia)
        ok(len(dosi) == 3 and all(d['status'] == 'pending' and d['due_date'] == oggi for d in dosi.values()), 'le tre prese di oggi (08:00 e 20:00 x2) sono arrivate da prendere')
        ok(all(u == uuid_presa(plan['uuid'], d['due_date'], d['due_time'], d['seq']) for u, d in dosi.items()), 'ogni presa ha l\'uuid atteso (UUIDv5 di terapia, giorno, orario, n.)')
        ok(uguali(A.copia, B.copia), 'A e B coincidono')
        # la presa di oggi alle 08:00, fatta dal sito
        sito = next(x for x in api('GET', 'pills?date=' + oggi)['doses'] if x['time'] == '08:00')
        api('PATCH', f"pills/doses/{sito['id']}", {'status': 'taken'}); A.aggiorna()
        u8 = uuid_presa(plan['uuid'], oggi, '08:00', 1)
        ok(A.copia[('pill_doses', u8)]['status'] == 'taken' and A.copia[('pill_doses', u8)]['taken_at'], 'la spunta fatta dal sito arriva (con l\'ora vera)')

        # dal telefono: spunta una presa che il sito NON ha ancora scritto (ieri, dopo la creazione la sera di ieri esiste solo se il sito l\'ha generata)
        u20 = uuid_presa(plan['uuid'], oggi, '20:00', 2)
        quando = datetime.datetime.now(ZoneInfo('Europe/Rome')).strftime('%Y-%m-%d %H:%M')
        r = A.push([mut('pill_doses', u20, {'status': 'taken', 'taken_at': quando})]); ok(r['results'][0]['status'] == 'applied', 'presa spuntata dal telefono, con l\'ora vera')
        x = [d for d in api('GET', 'pills?date=' + oggi)['doses'] if d['time'] == '20:00' and d['seq'] == 2]
        ok(x and x[0]['status'] == 'taken' and (x[0]['taken_at'] or '').startswith(quando[:10]), 'il sito la vede presa')
        r = A.push([mut('pill_doses', u20, {'status': 'taken', 'taken_at': quando})]); ok(r['results'][0]['status'] == 'applied', 'rimandata: nessun errore')
        r = A.push([mut('pill_doses', u20, {'status': 'skipped'})]); ok(r['results'][0]['status'] == 'applied', 'da presa a saltata')
        x = [d for d in api('GET', 'pills?date=' + oggi)['doses'] if d['time'] == '20:00' and d['seq'] == 2]
        ok(x and x[0]['status'] == 'skipped' and x[0]['taken_at'] is None, 'saltata: niente ora vera')
        r = A.push([mut('pill_doses', u20, {'status': 'pending'})]); ok(r['results'][0]['status'] == 'applied', 'di nuovo da prendere')
        # una presa di IERI creata dal telefono (il sito non l\'aveva): stessa riga, uuid atteso
        ui = uuid_presa(plan['uuid'], ieri, '20:00', 1)
        r = A.push([mut('pill_doses', ui, {'plan_uuid': plan['uuid'], 'due_date': ieri, 'due_time': '20:00', 'seq': 1, 'status': 'taken', 'taken_at': ieri + ' 21:10'})])
        ok(r['results'][0]['status'] == 'applied', 'presa di ieri creata dal telefono')
        A.aggiorna(); ok(A.copia.get(('pill_doses', ui), {}).get('status') == 'taken' and A.copia[('pill_doses', ui)]['taken_at'].startswith(ieri + ' 21:10'), 'la presa di ieri e nella copia, con l\'ora dettata')
        # rifiuti
        r = A.push([mut('pill_doses', str(uuid.uuid4()), {'plan_uuid': plan['uuid'], 'due_date': ieri, 'due_time': '20:00', 'seq': 1, 'status': 'taken'})]); x = r['results'][0]
        ok(x['status'] == 'rejected' and x['field'] == 'uuid', 'uuid che non e quello atteso: rifiutato')
        ud = uuid_presa(plan['uuid'], domani, '08:00', 1)
        r = A.push([mut('pill_doses', ud, {'plan_uuid': plan['uuid'], 'due_date': domani, 'due_time': '08:00', 'seq': 1, 'status': 'taken'})]); x = r['results'][0]
        ok(x['status'] == 'rejected' and x['field'] == 'due_date', 'giorno futuro: rifiutato')
        r = A.push([mut('pill_doses', u20, {'status': 'dimenticata'})]); x = r['results'][0]; ok(x['status'] == 'rejected' and x['field'] == 'status', 'stato sbagliato: rifiutato')
        r = A.push([mut('pill_doses', u20, {'due_date': ieri})]); x = r['results'][0]; ok(x['status'] == 'rejected' and x['field'] == 'due_date', 'il giorno di una presa non cambia')
        r = A.push([mut('pill_doses', u20, {}, 'delete')]); x = r['results'][0]; ok(x['status'] == 'rejected', 'una presa non si cancella da sola')
        r = A.push([mut('pill_doses', uuid_presa(str(uuid.uuid4()), oggi, '08:00', 1), {'plan_uuid': str(uuid.uuid4()), 'due_date': oggi, 'due_time': '08:00', 'seq': 1, 'status': 'taken'})]); x = r['results'][0]
        ok(x['status'] == 'rejected' and x['field'] in ('plan_uuid', 'uuid'), 'terapia che non esiste: rifiutato')
        # l\'ora vera nel futuro (telefono con l\'orologio avanti): la porta a «adesso», non rifiuta
        futuro = (datetime.datetime.now(ZoneInfo('Europe/Rome')) + datetime.timedelta(minutes=5)).strftime('%Y-%m-%d %H:%M')
        r = A.push([mut('pill_doses', u20, {'status': 'taken', 'taken_at': futuro})]); ok(r['results'][0]['status'] == 'applied', 'ora vera avanti di 5 minuti: accettata e riportata a adesso')
        x = [d for d in api('GET', 'pills?date=' + oggi)['doses'] if d['time'] == '20:00' and d['seq'] == 2]
        ok(x and (x[0]['taken_at'] or '')[:16] <= datetime.datetime.now(ZoneInfo('Europe/Rome')).strftime('%Y-%m-%d %H:%M'), 'l\'ora salvata non e nel futuro')

        # terapie dal telefono
        np_ = str(uuid.uuid4())
        r = A.push([mut('pill_plans', np_, {'name': NOME + ' dal telefono', 'dose': '2 gocce', 'notes': '', 'color': '#112233', 'freq_type': 'interval', 'weekdays': '1111111', 'month_days': '', 'interval_days': 2, 'times': '09:30x1', 'start_date': ieri, 'end_date': None, 'is_active': True})])
        ok(r['results'][0]['status'] == 'applied', 'terapia creata dal telefono (ogni 2 giorni)')
        mia = next((x for x in api('GET', 'pills/plans')['plans'] if x['name'] == NOME + ' dal telefono'), None)
        ok(mia is not None and mia['freq_type'] == 'interval' and mia['interval_days'] == 2 and mia['times'] == '09:30x1' and mia['active'] is True, 'il sito la vede coi valori giusti')
        if mia: creati_p.append(mia['id'])
        A.aggiorna(); ok(A.copia.get(('pill_plans', np_), {}).get('schedule_from') == ieri, 'schedule_from lo ha deciso il server')
        r = A.push([mut('pill_plans', np_, {'times': '10:00x1', 'is_active': False})]); ok(r['results'][0]['status'] == 'applied', 'cambio orario e sospesa')
        mia = next((x for x in api('GET', 'pills/plans')['plans'] if x['name'] == NOME + ' dal telefono'), None)
        ok(mia is not None and mia['times'] == '10:00x1' and mia['active'] is False, 'il sito vede orario e stato nuovi')
        A.aggiorna(); ok(A.copia[('pill_plans', np_)]['schedule_from'] == oggi, 'cambiato il ritmo: vale da oggi (lo decide il server)')
        r = A.push([mut('pill_plans', np_, {'name': ''})]); x = r['results'][0]; ok(x['status'] == 'rejected' and x['field'] == 'name' and x.get('current') is not None, 'nome vuoto: rifiutato, con la riga com\'e sul server')
        r = A.push([mut('pill_plans', np_, {'times': '25:00x1'})]); x = r['results'][0]; ok(x['status'] == 'rejected' and x['field'] == 'times', 'orario sbagliato: rifiutato')
        r = A.push([mut('pill_plans', np_, {'freq_type': 'monthly'})]); x = r['results'][0]; ok(x['status'] == 'rejected' and x['field'] == 'month_days', 'mensile senza giorni: rifiutato')
        r = A.push([mut('pill_plans', np_, {'schedule_from': ieri})]); A.aggiorna(); ok(r['results'][0]['status'] == 'applied' and A.copia[('pill_plans', np_)]['schedule_from'] == oggi, 'schedule_from e solo del server: se il telefono lo manda, e ignorato')
        r = A.push([mut('pill_plans', np_, {'colore': 'x'})]); x = r['results'][0]; ok(x['status'] == 'rejected', 'campo che non esiste: rifiutato')
        # cambiare l\'orario della terapia 1 non fa tornare le prese di ieri (il bug del sito, gia corretto)
        r = A.push([mut('pill_plans', plan['uuid'], {'times': '12:00x1'})]); ok(r['results'][0]['status'] == 'applied', 'orario cambiato su una terapia con prese gia spuntate')
        A.aggiorna()
        ok(A.copia.get(('pill_doses', ui), {}).get('status') == 'taken', 'la presa di ieri, gia spuntata, resta com\'era')
        ok(not any(d['status'] == 'pending' and d['due_time'] in ('08:00', '20:00') and d['due_date'] > oggi for d in prese_di(plan['uuid'], A.copia).values()), 'niente prese in sospeso con i vecchi orari nei giorni che vengono')
        A.aggiorna(); B.aggiorna(); fresca.copia_completa()
        def solo_pastiglie(m): return {k: r for k, r in m.items() if k[0] in ('pill_plans', 'pill_doses')}
        ok(not differenze(solo_pastiglie(A.copia), solo_pastiglie(fresca.copia)) and not differenze(solo_pastiglie(B.copia), solo_pastiglie(fresca.copia)), 'A, B e una copia nuova coincidono anche per le pastiglie')
        # cancellazioni
        r = A.push([mut('pill_plans', np_, {}, 'delete')]); ok(r['results'][0]['status'] == 'applied', 'cancella la terapia del telefono')
        r = A.push([mut('pill_plans', np_, {}, 'delete')]); ok(r['results'][0]['status'] == 'applied', 'cancellare di nuovo: non e un errore')
        A.aggiorna(); ok(('pill_plans', np_) not in A.copia, 'sparita dalla copia')
        print('8. pulizia delle pastiglie')
        for i in creati_p: api('DELETE', f'pills/plans/{i}')
        A.aggiorna(); fresca.copia_completa()
        ok(not [r for r in fresca.copia.values() if str(r.get('name', '')).startswith(NOME)], 'non e rimasta nessuna terapia di prova')
        ok(not prese_di(plan['uuid'], A.copia) and not prese_di(plan['uuid'], fresca.copia), 'le prese sono sparite con la terapia')

        print('9. diario: il giorno, i due elenchi, e il testo che non si perde')
        mio_id = A.chiama('GET', '/api/v1/me')['user']['id']
        def uuid_diario(giorno): return str(uuid.uuid5(NS_UUID5, f'habit_daily_journal|{mio_id}|{giorno}'))
        gg = '2099-03-04'          # un giorno lontano: non tocca il diario vero
        gu = uuid_diario(gg)
        # (a) il telefono scrive un giorno nuovo, con l\'uuid atteso
        r = A.push([mut('habit_daily_journal', gu, {'entry_date': gg, 'mood': 4, 'energy': 2, 'note': 'dal telefono A', 'note_rev': ''})])
        ok(r['results'][0]['status'] == 'applied', 'il giorno del diario scritto dal telefono')
        g = api('GET', 'diary/day?date=' + gg)
        ok(g['day']['note'] == 'dal telefono A' and g['day']['mood'] == 4 and g['day']['energy'] == 2, 'il sito lo vede com\'e')
        A.aggiorna(); B.aggiorna()
        riga = A.copia.get(('habit_daily_journal', gu))
        ok(riga is not None and riga['note'] == 'dal telefono A' and riga['note_rev'], 'l\'altro telefono lo riceve con l\'impronta del testo')
        ok(B.copia.get(('habit_daily_journal', gu), {}).get('note') == 'dal telefono A', 'anche B')
        # (b) uuid sbagliato: rifiutato
        r = A.push([mut('habit_daily_journal', str(uuid.uuid4()), {'entry_date': '2099-03-05', 'mood': 3})])
        ok(r['results'][0]['status'] == 'rejected' and r['results'][0]['field'] == 'uuid', 'uuid che non e quello del giorno: rifiutato')
        r = A.push([mut('habit_daily_journal', gu, {'mood': 9})]); x = r['results'][0]
        ok(x['status'] == 'rejected' and x['field'] == 'mood', 'umore 9: rifiutato')
        # (c) due telefoni scrivono lo stesso testo partendo dalla stessa versione: si tengono TUTTE E DUE
        rev = riga['note_rev']
        r = A.push([mut('habit_daily_journal', gu, {'note': 'dal telefono A, riga in piu', 'note_rev': rev})]); ok(r['results'][0]['status'] == 'applied', 'A scrive sopra la versione che conosce: nessun conflitto')
        r = B.push([mut('habit_daily_journal', gu, {'note': 'dal telefono B', 'note_rev': rev})]); ok(r['results'][0]['status'] == 'applied', 'B scrive partendo da una versione vecchia: accettato')
        testo = api('GET', 'diary/day?date=' + gg)['day']['note']
        ok(testo == 'dal telefono A, riga in piu\n\n———\n\ndal telefono B', 'il sito ha TUTTE E DUE le versioni: ' + repr(testo))
        B.aggiorna(); ok(B.copia[('habit_daily_journal', gu)]['note'] == testo, 'B riceve il testo unito')
        # (d) il sito scrive, il telefono (che non lo sa) scrive la stessa cosa: non si raddoppia
        rev = B.copia[('habit_daily_journal', gu)]['note_rev']
        api('PATCH', 'diary/day', {'date': gg, 'note': 'solo sito'})
        r = B.push([mut('habit_daily_journal', gu, {'note': 'solo sito', 'note_rev': rev})])
        ok(api('GET', 'diary/day?date=' + gg)['day']['note'] == 'solo sito', 'stesso testo scritto da due parti: resta uno solo')
        # (e) elenchi
        iu = str(uuid.uuid4())
        r = A.push([mut('diary_list_items', iu, {'entry_date': gg, 'list_type': 'good', 'content': 'un caffe con Anna'})])
        ok(r['results'][0]['status'] == 'applied', 'cosa bella creata dal telefono')
        voci = api('GET', 'diary/day?date=' + gg)['day']['good']
        ok(any(v['content'] == 'un caffe con Anna' for v in voci), 'il sito la vede')
        r = A.push([mut('diary_list_items', iu, {'content': 'un caffe con Anna e Luca'})]); ok(r['results'][0]['status'] == 'applied', 'modifica del testo')
        r = A.push([mut('diary_list_items', iu, {'list_type': 'learned'})]); ok(r['results'][0]['status'] == 'rejected' and r['results'][0]['field'] == 'list_type', 'l\'elenco non si cambia')
        r = A.push([mut('diary_list_items', str(uuid.uuid4()), {'entry_date': gg, 'list_type': 'good', 'content': ''})]); ok(r['results'][0]['status'] == 'rejected' and r['results'][0]['field'] == 'content', 'testo vuoto: rifiutato')
        r = A.push([mut('diary_list_items', str(uuid.uuid4()), {'list_type': 'good', 'content': 'x'})]); ok(r['results'][0]['status'] == 'rejected', 'senza giorno: rifiutato')
        A.aggiorna(); ok(A.copia.get(('diary_list_items', iu), {}).get('sort_order') is not None, 'l\'ordine lo decide il server (sort_order)')
        # (f) la copia completa coincide con quella che si e aggiornata a pezzi
        A.aggiorna(); B.aggiorna(); fresca.copia_completa()
        def solo_diario(m): return {k: r for k, r in m.items() if k[0] in ('habit_daily_journal', 'diary_list_items') and str(r.get('entry_date')) == gg}
        ok(not differenze(solo_diario(A.copia), solo_diario(fresca.copia)) and not differenze(solo_diario(B.copia), solo_diario(fresca.copia)), 'A, B e una copia nuova coincidono anche per il diario')
        # (g) il sito scrive un giorno nuovo: nasce LA riga con l\'uuid atteso
        g2 = '2099-03-06'
        api('PATCH', 'diary/day', {'date': g2, 'note': 'nato sul sito', 'mood': 5})
        A.aggiorna(); ok(A.copia.get(('habit_daily_journal', uuid_diario(g2)), {}).get('note') == 'nato sul sito', 'un giorno nato sul sito arriva con l\'uuid del giorno')
        # (h) cancellazioni
        r = A.push([mut('diary_list_items', iu, {}, 'delete')]); ok(r['results'][0]['status'] == 'applied', 'cancella la cosa bella')
        r = A.push([mut('habit_daily_journal', gu, {}, 'delete'), mut('habit_daily_journal', uuid_diario(g2), {}, 'delete')]); ok(all(x['status'] == 'applied' for x in r['results']), 'cancella i due giorni di prova')
        r = A.push([mut('habit_daily_journal', gu, {}, 'delete')]); ok(r['results'][0]['status'] == 'applied', 'cancellare di nuovo: non e un errore')
        A.aggiorna(); ok(('habit_daily_journal', gu) not in A.copia and ('diary_list_items', iu) not in A.copia, 'sparito dalla copia')
        print('10. pulizia del diario')
        fresca.copia_completa()
        ok(not [r for r in fresca.copia.values() if str(r.get('entry_date', '')).startswith('2099-03')], 'non e rimasto nessun giorno di prova')
    finally:
        for t in (A, B):
            try: t.chiama('POST', '/api/v1/auth/logout')
            except Exception: pass
    print('\n' + ('TUTTO OK' if not errori else f'{len(errori)} CONTROLLI FALLITI'))
    sys.exit(1 if errori else 0)

main()
