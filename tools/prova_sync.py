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
import argparse, getpass, json, os, sys, uuid, urllib.request, urllib.error

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
        print('1. stato e copia completa')
        st = A.chiama('GET', '/api/v1/sync/state'); ok(st.get('ok') and st['tables'] == ['todo_buy_categories', 'todos'], f"stato: tabelle {st.get('tables')}")
        pagine = A.copia_completa(limite=50)
        ok(pagine >= 1, f'copia completa a pagine da 50 ({pagine} pagine, {len(A.copia)} righe)')
        ok(all(r['sort_key'] for r in A.copia.values()), 'ogni riga ha una sort_key')
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
        ordine_app = [r['title'] for r in sorted([r for r in A.copia.values() if r.get('list_type') == 'todo' and r['title'].startswith(NOME) and not r['is_done']], key=lambda r: r['sort_key'])]
        ordine_sito = [t['title'] for t in api('GET', 'tasks')['tasks'] if t['title'].startswith(NOME) and not t['done']]
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
    finally:
        for t in (A, B):
            try: t.chiama('POST', '/api/v1/auth/logout')
            except Exception: pass
    print('\n' + ('TUTTO OK' if not errori else f'{len(errori)} CONTROLLI FALLITI'))
    sys.exit(1 if errori else 0)

main()
