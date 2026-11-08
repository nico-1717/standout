# -*- coding: utf-8 -*-
"""Prova della sincronizzazione delle lingue (corsi, mazzi, parole, figlie, esempi, preferiti, imparate).

    STANDOUT_PASSWORD=... python3 tools/prova_sync_lingue.py --url http://127.0.0.1:8099 --user prova

Solo su indirizzi locali. Toglie da sola quello che crea.
"""
import argparse, getpass, json, os, sys, uuid, urllib.request, urllib.error

NOME = 'prova lingue'
NS_UUID5 = uuid.UUID('7d4f1b0e-3c52-4e8a-9a61-5b2c8d0f6e13')
errori = []
def ok(c, testo):
    print(('  ok   ' if c else '  KO   ') + testo)
    if not c: errori.append(testo)

class Tel:
    def __init__(s, base, utente, pw, nome):
        s.base = base.rstrip('/')
        r = s.chiama('POST', '/api/v1/auth/login', {'identifier': utente, 'password': pw, 'device_name': nome}, auth=False)
        s.tok = r['access_token']; s.uid = int(r['user']['id']); s.copia = {}; s.cursore = 0
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


def u5(nome): return str(uuid.uuid5(NS_UUID5, nome))
def learnt_uuid(uid, target, figlia): return u5(f'fc_study_learnt|{uid}|{target}|{1 if figlia else 0}')

def main():
    ap = argparse.ArgumentParser(); ap.add_argument('--url', required=True); ap.add_argument('--user', required=True)
    a = ap.parse_args()
    if not any(x in a.url for x in ('127.0.0.1', 'localhost')): sys.exit('Solo indirizzi locali.')
    pw = os.environ.get('STANDOUT_PASSWORD') or getpass.getpass('Password: ')
    A = Tel(a.url, a.user, pw, 'prova lingue A'); B = Tel(a.url, a.user, pw, 'prova lingue B')
    A.copia_completa()
    progetti_prima = {u for (t, u) in A.copia if t == 'projects'}
    def pulisci():
        A.copia_completa()
        for (t, u), x in list(A.copia.items()):
            if t == 'fc_vocab_courses' and x.get('known_lang') == 'fr': A.push([mut(t, u, {}, op='delete')])
        A.copia_completa()
        for (t, u), x in list(A.copia.items()):
            if t == 'projects' and u not in progetti_prima: A.push([mut(t, u, {}, op='delete')])
    pulisci()
    try:
        st = A.chiama('GET', '/api/v1/sync/state')
        ok(all(t in st['tables'] for t in ['fc_vocab_courses', 'fc_vocab_decks', 'fc_vocab_cards', 'fc_vocab_subcards', 'fc_vocab_examples', 'fc_vocab_favorites', 'fc_vocab_learnt']), 'stato: le tabelle delle lingue ci sono')
        A.copia_completa(); B.copia_completa()
        uid = A.uid

        print('1. corsi')
        K1, K2 = nuovo(), nuovo()
        r = A.push([mut('fc_vocab_courses', K1, {'known_lang': 'fr', 'learn_lang': 'en-GB', 'sort_key': 'a0'})]); B.aggiorna()
        c = B.copia.get(('fc_vocab_courses', K1), {})
        ok(esito(r)['status'] == 'applied' and c.get('learn_lang') == 'en-GB' and c.get('known_lang') == 'fr', f'corso creato: {c}')
        ok(c.get('project_uuid') is not None and ('projects', c.get('project_uuid')) in B.copia, 'il corso ha il suo progetto')
        for patch, campo in [({'known_lang': 'fr', 'learn_lang': 'en-GB'}, 'corso doppio'), ({'known_lang': 'fr', 'learn_lang': 'fr'}, 'stessa lingua'),
                             ({'known_lang': 'xx', 'learn_lang': 'en-GB'}, 'lingua nota inesistente'), ({'known_lang': 'fr', 'learn_lang': 'zz'}, 'lingua inesistente'),
                             ({'known_lang': 'fr'}, 'manca la lingua'), ({'known_lang': 'fr', 'learn_lang': 'de', 'cosa': 1}, 'campo sconosciuto')]:
            r = A.push([mut('fc_vocab_courses', K2, patch)]); ok(esito(r)['status'] == 'rejected', f"{campo}: rifiutato ({esito(r).get('message')})")
        r = A.push([mut('fc_vocab_courses', K1, {'learn_lang': 'en-US'})]); B.aggiorna()
        ok(esito(r)['status'] == 'applied' and B.copia[('fc_vocab_courses', K1)]['learn_lang'] == 'en-US', 'variante cambiata')
        r = A.push([mut('fc_vocab_courses', K1, {'learn_lang': 'de'})]); ok(esito(r)['status'] == 'rejected', 'cambiare lingua: rifiutato')
        r = A.push([mut('fc_vocab_courses', K1, {'known_lang': 'it'})]); ok(esito(r)['status'] == 'rejected', 'cambiare la lingua nota: rifiutato')
        r = A.push([mut('fc_vocab_courses', K2, {'known_lang': 'fr', 'learn_lang': 'de', 'sort_key': 'a1'})]); ok(esito(r)['status'] == 'applied', 'secondo corso')
        r = A.push([mut('fc_vocab_courses', K2, {'sort_key': 'a2'})]); B.aggiorna()
        ok(esito(r)['status'] == 'applied' and B.copia[('fc_vocab_courses', K2)]['sort_key'] == 'a2', 'ordine cambiato')

        print('2. mazzi')
        M1, M2, M3 = nuovo(), nuovo(), nuovo()
        r = A.push([mut('fc_vocab_decks', M1, {'course_uuid': K1, 'name': NOME + ' base', 'color': '#336699'}),
                    mut('fc_vocab_decks', M2, {'course_uuid': K1, 'name': NOME + ' verbi', 'parent_uuid': M1, 'color': '#336699'}),
                    mut('fc_vocab_decks', M3, {'course_uuid': K2, 'name': NOME + ' altro corso'})]); B.aggiorna()
        ok(all(x['status'] == 'applied' for x in r['results']), f"mazzi creati: {[x.get('message') for x in r['results']]}")
        ok(B.copia[('fc_vocab_decks', M2)]['parent_uuid'] == M1 and B.copia[('fc_vocab_decks', M2)]['course_uuid'] == K1, 'padre e corso visti')
        for patch, campo in [({'course_uuid': K1}, 'manca il nome'), ({'name': 'x'}, 'manca il corso'), ({'course_uuid': nuovo(), 'name': 'x'}, 'corso che non c\'e'),
                             ({'course_uuid': K1, 'name': 'x', 'parent_uuid': M3}, 'padre di un altro corso'), ({'course_uuid': K1, 'name': 'x', 'color': 'rosso'}, 'colore'),
                             ({'course_uuid': K1, 'name': 'x', 'icon': 'non-esiste'}, 'icona')]:
            r = A.push([mut('fc_vocab_decks', nuovo(), patch)]); ok(esito(r)['status'] == 'rejected', f"{campo}: rifiutato ({esito(r).get('message')})")
        r = A.push([mut('fc_vocab_decks', M1, {'parent_uuid': M2})]); ok(esito(r)['status'] == 'rejected', 'un mazzo dentro un suo discendente: rifiutato')
        r = A.push([mut('fc_vocab_decks', M1, {'course_uuid': K2})]); ok(esito(r)['status'] == 'rejected', 'il corso di un mazzo non cambia')

        print('3. parole e figlie')
        W1, W2, S1, S2 = nuovo(), nuovo(), nuovo(), nuovo()
        r = A.push([mut('fc_vocab_cards', W1, {'category_uuid': M1, 'word': 'house', 'translation': 'maison', 'meaning': 'a place to live', 'notes': 'n1'}),
                    mut('fc_vocab_cards', W2, {'category_uuid': M2, 'word': 'run', 'translation': 'courir'})]); B.aggiorna()
        w = B.copia.get(('fc_vocab_cards', W1), {})
        ok(all(x['status'] == 'applied' for x in r['results']) and w.get('word') == 'house' and w.get('translation') == 'maison' and w.get('meaning') == 'a place to live' and w.get('is_father') in (False, 0), f'parole create: {w}')
        r = A.push([mut('fc_vocab_subcards', S1, {'parent_uuid': W1, 'word': 'houses', 'translation': 'maisons'}),
                    mut('fc_vocab_subcards', S2, {'parent_uuid': W1, 'word': 'home'})]); B.aggiorna()
        ok(all(x['status'] == 'applied' for x in r['results']) and B.copia[('fc_vocab_subcards', S2)]['translation'] == '', 'figlie create (traduzione vuota ammessa)')
        ok(B.copia[('fc_vocab_cards', W1)]['is_father'] in (True, 1), 'il padre ha is_father')
        for patch, campo in [({'category_uuid': M1}, 'manca la parola'), ({'category_uuid': M1, 'word': ''}, 'parola vuota'), ({'category_uuid': M1, 'word': 5}, 'parola non testo'),
                             ({'category_uuid': nuovo(), 'word': 'x'}, 'mazzo che non c\'e'), ({'category_uuid': M1, 'word': 'x', 'image': 'a.png'}, 'immagine'),
                             ({'category_uuid': M1, 'word': 'x', 'is_father': True}, 'is_father')]:
            r = A.push([mut('fc_vocab_cards', nuovo(), patch)]); ok(esito(r)['status'] == 'rejected', f"{campo}: rifiutata ({esito(r).get('message')})")
        r = A.push([mut('fc_vocab_subcards', nuovo(), {'word': 'x'})]); ok(esito(r)['status'] == 'rejected', 'figlia senza padre: rifiutata')
        r = A.push([mut('fc_vocab_subcards', S1, {'parent_uuid': W2})]); ok(esito(r)['status'] == 'rejected', 'il padre di una figlia non cambia')
        r = A.push([mut('fc_vocab_cards', W1, {'word': 'house!', 'meaning': ''}), mut('fc_vocab_subcards', S1, {'translation': 'les maisons'})]); B.aggiorna()
        ok(B.copia[('fc_vocab_cards', W1)]['word'] == 'house!' and B.copia[('fc_vocab_cards', W1)]['meaning'] is None and B.copia[('fc_vocab_subcards', S1)]['translation'] == 'les maisons', 'modifiche viste')
        r = A.push([mut('fc_vocab_cards', nuovo(), {'category_uuid': M1, 'word': 'w' * 400, 'translation': 't' * 700, 'notes': 'n' * 4000})]); A.copia_completa()
        lunga = [x for (t, u), x in A.copia.items() if t == 'fc_vocab_cards' and x['word'] == 'w' * 255]
        ok(esito(r)['status'] == 'applied' and len(lunga) == 1 and len(lunga[0]['translation']) == 500 and len(lunga[0]['notes']) == 3000, 'testi lunghi tagliati (255/500/3000)')
        r = A.push([mut('fc_vocab_subcards', S2, {}, op='delete')]); B.aggiorna(); ok(esito(r)['status'] == 'applied' and ('fc_vocab_subcards', S2) not in B.copia, 'figlia cancellata')
        r = A.push([mut('fc_vocab_cards', W1, {'category_uuid': M2})]); B.aggiorna()
        ok(esito(r)['status'] == 'applied' and B.copia[('fc_vocab_cards', W1)]['category_uuid'] == M2, 'parola spostata in un altro mazzo')

        print('4. esempi, preferiti, imparate')
        E1, E2, F1, F2 = nuovo(), nuovo(), nuovo(), nuovo()
        r = A.push([mut('fc_vocab_examples', E1, {'card_uuid': W1, 'example_text': 'My house is big.'}),
                    mut('fc_vocab_examples', E2, {'child_uuid': S1, 'example_text': 'Two houses.', 'gap_text': 'houses'})]); B.aggiorna()
        ok(all(x['status'] == 'applied' for x in r['results']) and B.copia[('fc_vocab_examples', E2)]['gap_text'] == 'houses' and B.copia[('fc_vocab_examples', E1)]['card_uuid'] == W1, 'esempi creati')
        r = A.push([mut('fc_vocab_examples', nuovo(), {'example_text': 'x'})]); ok(esito(r)['status'] == 'rejected', 'esempio senza bersaglio: rifiutato')
        r = A.push([mut('fc_vocab_examples', nuovo(), {'card_uuid': W1, 'child_uuid': S1, 'example_text': 'x'})]); ok(esito(r)['status'] == 'rejected', 'due bersagli: rifiutato')
        r = A.push([mut('fc_vocab_examples', E1, {'example_text': 'My house is huge.', 'gap_text': None})]); B.aggiorna()
        ok(esito(r)['status'] == 'applied' and B.copia[('fc_vocab_examples', E1)]['example_text'] == 'My house is huge.', 'esempio cambiato')
        r = A.push([mut('fc_vocab_favorites', F1, {'card_uuid': W1}), mut('fc_vocab_favorites', F2, {'child_uuid': S1})]); B.aggiorna()
        ok(all(x['status'] == 'applied' for x in r['results']) and ('fc_vocab_favorites', F1) in B.copia, 'preferiti creati')
        r = A.push([mut('fc_vocab_favorites', nuovo(), {'card_uuid': W1})]); B.aggiorna()
        ok(esito(r)['status'] == 'applied' and ('fc_vocab_favorites', F1) not in B.copia, 'stessa parola preferita due volte: vince l\'ultimo uuid')
        U = learnt_uuid(uid, W1, False)
        r = A.push([mut('fc_vocab_learnt', U, {'target_uuid': W1, 'is_child': False, 'learnt_date': '2026-11-01'})]); B.aggiorna()
        ok(esito(r)['status'] == 'applied' and B.copia.get(('fc_vocab_learnt', U), {}).get('learnt_date') == '2026-11-01', f"imparata: {B.copia.get(('fc_vocab_learnt', U))}")
        r = A.push([mut('fc_vocab_learnt', nuovo(), {'target_uuid': W1, 'is_child': False})]); ok(esito(r)['status'] == 'rejected', 'imparata con uuid sbagliato: rifiutata')
        U2 = learnt_uuid(uid, S1, True)
        r = A.push([mut('fc_vocab_learnt', U2, {'target_uuid': S1, 'is_child': True, 'learnt_date': '2026-11-02'})]); ok(esito(r)['status'] == 'applied', 'imparata di una figlia')

        print('5. cancellare')
        r = A.push([mut('fc_vocab_cards', W1, {}, op='delete')]); B.aggiorna()
        ok(esito(r)['status'] == 'applied' and not any(t in ('fc_vocab_subcards', 'fc_vocab_examples', 'fc_vocab_favorites', 'fc_vocab_learnt') and (x.get('card_uuid') == W1 or x.get('parent_uuid') == W1 or x.get('target_uuid') in (W1, S1) or x.get('child_uuid') == S1)
                                                          for (t, u), x in B.copia.items()), 'cancellando la parola se ne va tutto quello che aveva')
        r = A.push([mut('fc_vocab_decks', M1, {}, op='delete')]); B.aggiorna()
        ok(esito(r)['status'] == 'applied' and ('fc_vocab_decks', M1) not in B.copia and ('fc_vocab_decks', M2) not in B.copia and ('fc_vocab_cards', W2) not in B.copia, 'cancellando un mazzo se ne va il ramo con le parole')
        r = A.push([mut('fc_vocab_courses', K1, {}, op='delete')]); B.aggiorna()
        ok(esito(r)['status'] == 'applied' and ('fc_vocab_courses', K1) not in B.copia, 'corso cancellato')
        ok(('fc_vocab_decks', M3) in B.copia, 'i mazzi di un altro corso restano')
        r = A.push([mut('fc_vocab_courses', K2, {}, op='delete')]); B.aggiorna()
        ok(esito(r)['status'] == 'applied' and ('fc_vocab_decks', M3) not in B.copia, 'secondo corso cancellato, con i suoi mazzi')
    finally:
        pulisci()
    print('\n' + ('TUTTO OK' if not errori else f'{len(errori)} errori'))
    sys.exit(1 if errori else 0)

main()
