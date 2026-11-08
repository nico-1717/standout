# -*- coding: utf-8 -*-
"""Prova della sincronizzazione del gioco delle lingue (partite, tempo, risposte, mazzi scelti, sequenza, record).

    STANDOUT_PASSWORD=... python3 tools/prova_sync_lingue_gioco.py --url http://127.0.0.1:8099 --user prova

Solo su indirizzi locali. Toglie da sola quello che crea.
"""
import argparse, getpass, json, os, sys, uuid, urllib.request, urllib.error

NOME = 'prova gioco lingue'
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


def record_uuid(uid, corso, tipo): return u5(f'fc_vocab_records|{uid}|{corso}|{tipo}')
GD = '2026-11-01 10:00:00'

def main():
    ap = argparse.ArgumentParser(); ap.add_argument('--url', required=True); ap.add_argument('--user', required=True)
    a = ap.parse_args()
    if not any(x in a.url for x in ('127.0.0.1', 'localhost')): sys.exit('Solo indirizzi locali.')
    pw = os.environ.get('STANDOUT_PASSWORD') or getpass.getpass('Password: ')
    A = Tel(a.url, a.user, pw, 'prova gioco A'); B = Tel(a.url, a.user, pw, 'prova gioco B')
    A.copia_completa()
    progetti_prima = {u for (t, u) in A.copia if t == 'projects'}
    def pulisci():
        A.copia_completa()
        for (t, u), x in list(A.copia.items()):
            if t == 'fc_vocab_courses' and x.get('known_lang') == 'fr': A.push([mut(t, u, {}, op='delete')])
            if t == 'fc_games' and x.get('kind') == 'study' and x.get('total_father_words') == 4242: A.push([mut(t, u, {}, op='delete')])
        A.copia_completa()
        for (t, u), x in list(A.copia.items()):
            if t == 'projects' and u not in progetti_prima: A.push([mut(t, u, {}, op='delete')])
    pulisci()
    try:
        st = A.chiama('GET', '/api/v1/sync/state')
        ok(all(t in st['tables'] for t in ['fc_vocab_plays', 'fc_vocab_game_decks', 'fc_vocab_sequence', 'fc_vocab_records']), 'stato: le tabelle del gioco ci sono')
        A.copia_completa(); B.copia_completa()
        uid = A.uid

        print('0. un corso con parole')
        K, K2, M, M2, W1, W2, S1 = nuovo(), nuovo(), nuovo(), nuovo(), nuovo(), nuovo(), nuovo()
        r = A.push([mut('fc_vocab_courses', K, {'known_lang': 'fr', 'learn_lang': 'en-GB', 'sort_key': 'a0'}),
                    mut('fc_vocab_courses', K2, {'known_lang': 'fr', 'learn_lang': 'de', 'sort_key': 'a1'}),
                    mut('fc_vocab_decks', M, {'course_uuid': K, 'name': NOME}),
                    mut('fc_vocab_decks', M2, {'course_uuid': K2, 'name': NOME + ' altro'}),
                    mut('fc_vocab_cards', W1, {'category_uuid': M, 'word': 'house', 'translation': 'maison'}),
                    mut('fc_vocab_cards', W2, {'category_uuid': M, 'word': 'run', 'translation': 'courir'}),
                    mut('fc_vocab_subcards', S1, {'parent_uuid': W1, 'word': 'houses', 'translation': 'maisons'})])
        ok(all(x['status'] == 'applied' for x in r['results']), f"preparazione: {[x.get('message') for x in r['results']]}")

        print('1. la partita')
        G, G2, GS = nuovo(), nuovo(), nuovo()
        r = A.push([mut('fc_games', G, {'kind': 'vocab', 'course_uuid': K, 'game_date': GD, 'score': 0, 'type': 'mix', 'card_status': 'all',
                                         'game_order': 'in_order', 'total_father_words': 2, 'total_words_with_children': 3, 'max_card_uuid': W2})]); B.aggiorna()
        g = B.copia.get(('fc_games', G), {})
        ok(esito(r)['status'] == 'applied' and g.get('kind') == 'vocab' and g.get('course_uuid') == K and g.get('type') == 'mix' and g.get('max_card_uuid') == W2,
           f'partita di lingua creata: {g} {esito(r).get("message")}')
        r = A.push([mut('fc_games', GS, {'game_date': GD, 'type': 'fc', 'total_father_words': 4242})]); B.aggiorna()
        g = B.copia.get(('fc_games', GS), {})
        ok(esito(r)['status'] == 'applied' and g.get('kind') == 'study' and g.get('course_uuid') is None, f'partita di studio senza kind: resta di studio ({g})')
        for patch, campo in [({'kind': 'vocab', 'type': 'w2t'}, 'lingua senza corso'), ({'kind': 'study', 'course_uuid': K, 'type': 'fc'}, 'studio con un corso'),
                             ({'kind': 'vocab', 'course_uuid': K, 'type': 'fc'}, 'tipo di studio in una partita di lingua'), ({'kind': 'study', 'type': 'w2t'}, 'tipo di lingua in una partita di studio'),
                             ({'kind': 'vocab', 'course_uuid': nuovo(), 'type': 'w2t'}, 'corso che non c\'e'), ({'kind': 'altro', 'type': 'fc'}, 'kind sconosciuto'),
                             ({'kind': 'vocab', 'course_uuid': K, 'type': 'w2t', 'parent_uuid': GS}, 'ripasso di una partita di studio'),
                             ({'kind': 'vocab', 'course_uuid': K, 'type': 'w2t', 'max_card_uuid': nuovo()}, 'carta piu\' nuova che non c\'e')]:
            r = A.push([mut('fc_games', nuovo(), dict(patch, game_date=GD))]); ok(esito(r)['status'] == 'rejected', f"{campo}: rifiutata ({esito(r).get('message')})")
        r = A.push([mut('fc_games', G, {'kind': 'study'})]); ok(esito(r)['status'] == 'rejected', 'il kind non cambia')
        r = A.push([mut('fc_games', G, {'course_uuid': K2})]); ok(esito(r)['status'] == 'rejected', 'il corso non cambia')
        r = A.push([mut('fc_games', G, {'score': 66.5, 'total_father_words': 2})]); B.aggiorna()
        ok(esito(r)['status'] == 'applied' and B.copia[('fc_games', G)]['score'] == 66.5, 'punteggio aggiornato')
        r = A.push([mut('fc_games', G2, {'kind': 'vocab', 'course_uuid': K, 'game_date': GD, 'type': 't2w_L', 'parent_uuid': G, 'max_card_uuid': None})]); B.aggiorna()
        ok(esito(r)['status'] == 'applied' and B.copia[('fc_games', G2)]['parent_uuid'] == G, 'ripasso (parent_uuid) di una partita di lingua')

        print('2. tempo, mazzi, sequenza')
        D1, GD1, GD2, Q1, Q2 = nuovo(), nuovo(), nuovo(), nuovo(), nuovo()
        r = A.push([mut('fc_game_durations', D1, {'game_uuid': G, 'start_time': GD, 'duration_seconds': 30})]); B.aggiorna()
        ok(esito(r)['status'] == 'applied' and B.copia[('fc_game_durations', D1)]['duration_seconds'] == 30, 'sessione di tempo di una partita di lingua')
        r = A.push([mut('fc_game_durations', D1, {'duration_seconds': 95})]); B.aggiorna()
        ok(esito(r)['status'] == 'applied' and B.copia[('fc_game_durations', D1)]['duration_seconds'] == 95, 'tempo aggiornato')
        r = A.push([mut('fc_vocab_game_decks', GD1, {'game_uuid': G, 'deck_uuid': M, 'category_order': 0})]); B.aggiorna()
        ok(esito(r)['status'] == 'applied' and B.copia[('fc_vocab_game_decks', GD1)]['deck_uuid'] == M, 'mazzo scelto')
        r = A.push([mut('fc_vocab_game_decks', GD2, {'game_uuid': G, 'deck_uuid': M})]); ok(esito(r)['status'] == 'rejected', 'stesso mazzo due volte: rifiutato')
        r = A.push([mut('fc_vocab_game_decks', GD2, {'game_uuid': G, 'deck_uuid': M2})]); ok(esito(r)['status'] == 'rejected', f"mazzo di un altro corso: rifiutato ({esito(r).get('message')})")
        r = A.push([mut('fc_vocab_game_decks', GD2, {'game_uuid': GS, 'deck_uuid': M})]); ok(esito(r)['status'] == 'rejected', 'mazzo in una partita di studio: rifiutato')
        r = A.push([mut('fc_vocab_sequence', Q1, {'game_uuid': G, 'position': 0, 'card_uuid': W1}), mut('fc_vocab_sequence', Q2, {'game_uuid': G, 'position': 1, 'card_uuid': W2})]); B.aggiorna()
        ok(all(x['status'] == 'applied' for x in r['results']) and B.copia[('fc_vocab_sequence', Q2)]['card_uuid'] == W2, 'sequenza')
        r = A.push([mut('fc_vocab_sequence', nuovo(), {'game_uuid': G, 'position': 1, 'card_uuid': W1})]); ok(esito(r)['status'] == 'rejected', 'posizione gia\' usata: rifiutata')
        r = A.push([mut('fc_vocab_sequence', nuovo(), {'game_uuid': G, 'position': 5, 'card_uuid': nuovo()})]); ok(esito(r)['status'] == 'rejected', 'parola che non c\'e\': rifiutata')
        r = A.push([mut('fc_vocab_sequence', nuovo(), {'game_uuid': GS, 'position': 5, 'card_uuid': W1})]); ok(esito(r)['status'] == 'rejected', 'sequenza in una partita di studio: rifiutata')

        print('3. risposte')
        P1, P2 = nuovo(), nuovo()
        r = A.push([mut('fc_vocab_plays', P1, {'game_uuid': G, 'card_uuid': W1, 'position': 0, 'correct_answer': 'maison', 'user_answer': ''}),
                    mut('fc_vocab_plays', P2, {'game_uuid': G, 'child_uuid': S1, 'position': 2, 'correct_answer': 'houses', 'user_answer': ''})]); B.aggiorna()
        ok(all(x['status'] == 'applied' for x in r['results']) and B.copia[('fc_vocab_plays', P2)]['child_uuid'] == S1, 'risposte create (parola e figlia)')
        r = A.push([mut('fc_vocab_plays', P1, {'user_answer': 'Correct', 'play_date': '2026-11-01 10:00:20'})]); B.aggiorna()
        ok(esito(r)['status'] == 'applied' and B.copia[('fc_vocab_plays', P1)]['user_answer'] == 'Correct', 'risposta data')
        for patch, campo in [({'game_uuid': G, 'card_uuid': W1, 'position': 0}, 'stessa parola e posizione'), ({'game_uuid': G, 'card_uuid': W1, 'child_uuid': S1, 'position': 7}, 'due bersagli'),
                             ({'game_uuid': G, 'position': 7}, 'nessun bersaglio'), ({'game_uuid': G, 'card_uuid': W2, 'position': 7, 'user_answer': 'Titolo'}, 'Titolo non esiste in lingua'),
                             ({'game_uuid': GS, 'card_uuid': W2, 'position': 7}, 'risposta in una partita di studio'), ({'game_uuid': G, 'card_uuid': nuovo(), 'position': 7}, 'parola che non c\'e'),
                             ({'game_uuid': G, 'card_uuid': W2}, 'manca la posizione')]:
            r = A.push([mut('fc_vocab_plays', nuovo(), patch)]); ok(esito(r)['status'] == 'rejected', f"{campo}: rifiutata ({esito(r).get('message')})")

        print('4. record')
        R = record_uuid(uid, K, 'streak')
        r = A.push([mut('fc_vocab_records', R, {'kind': 'streak', 'course_uuid': K, 'value': 7, 'game_uuid': G, 'achieved_at': '2026-11-01 10:05:00'})]); B.aggiorna()
        x = B.copia.get(('fc_vocab_records', R), {})
        ok(esito(r)['status'] == 'applied' and x.get('value') == 7 and x.get('course_uuid') == K and x.get('game_uuid') == G, f'record creato: {x} {esito(r).get("message")}')
        r = A.push([mut('fc_vocab_records', R, {'value': 9.5})]); B.aggiorna()
        ok(esito(r)['status'] == 'applied' and B.copia[('fc_vocab_records', R)]['value'] == 9.5, 'record migliorato')
        r = A.push([mut('fc_vocab_records', nuovo(), {'kind': 'streak', 'course_uuid': K, 'value': 1})]); ok(esito(r)['status'] == 'rejected', 'record con uuid sbagliato: rifiutato')
        r = A.push([mut('fc_vocab_records', record_uuid(uid, K, 'streak'), {'kind': 'streak', 'course_uuid': K2, 'value': 1})]); ok(esito(r)['status'] == 'rejected', 'uuid di un altro corso: rifiutato')
        R2 = record_uuid(uid, K2, 'streak')
        r = A.push([mut('fc_vocab_records', R2, {'kind': 'streak', 'course_uuid': K2, 'value': 3})]); B.aggiorna()
        ok(esito(r)['status'] == 'applied' and R2 != R and B.copia[('fc_vocab_records', R2)]['course_uuid'] == K2, 'lo stesso tipo in un altro corso e\' un altro record')
        r = A.push([mut('fc_vocab_records', record_uuid(uid, K, 'Bad Kind'), {'kind': 'Bad Kind', 'course_uuid': K, 'value': 1})]); ok(esito(r)['status'] == 'rejected', 'tipo non valido: rifiutato')
        r = A.push([mut('fc_vocab_records', record_uuid(uid, K, 'best'), {'kind': 'best', 'course_uuid': K, 'value': 1, 'game_uuid': GS})]); ok(esito(r)['status'] == 'rejected', 'record che punta a una partita di studio: rifiutato')

        print('5. dal sito')
        # il sito non scrive il kind: le partite di studio non devono mescolarsi con quelle di lingua
        A.copia_completa()
        ok(A.copia.get(('fc_games', GS), {}).get('kind') == 'study' and A.copia.get(('fc_games', G), {}).get('kind') == 'vocab', 'copia completa: i generi sono giusti')
        ok(not any(t == 'fc_study_plays' and x.get('game_uuid') == G for (t, u), x in A.copia.items()), 'nessuna riga di studio sulle partite di lingua')

        print('6. cancellare')
        r = A.push([mut('fc_games', G, {}, op='delete')]); B.aggiorna()
        resto = [(t, u) for (t, u), x in B.copia.items() if x.get('game_uuid') == G and t != 'fc_vocab_records']
        ok(esito(r)['status'] == 'applied' and ('fc_games', G) not in B.copia and not resto, f'partita cancellata con tutto il suo: {resto}')
        ok(B.copia[('fc_games', G2)]['parent_uuid'] is None, 'il ripasso resta, senza il legame')
        ok(B.copia[('fc_vocab_records', R)]['game_uuid'] is None, 'il record resta, senza il legame')
        r = A.push([mut('fc_games', G2, {}, op='delete'), mut('fc_games', GS, {}, op='delete')]); B.aggiorna()
        ok(all(x['status'] == 'applied' for x in r['results']), 'ripasso e partita di studio cancellati')
        G3, P3 = nuovo(), nuovo()
        A.push([mut('fc_games', G3, {'kind': 'vocab', 'course_uuid': K2, 'game_date': GD, 'type': 'w2t'}), mut('fc_vocab_plays', P3, {'game_uuid': G3, 'card_uuid': W1, 'position': 0})])
        r = A.push([mut('fc_vocab_courses', K2, {}, op='delete')]); B.aggiorna()
        ok(esito(r)['status'] == 'applied' and ('fc_games', G3) not in B.copia and ('fc_vocab_plays', P3) not in B.copia and ('fc_vocab_records', R2) not in B.copia,
           'cancellando il corso se ne vanno partite, risposte e record')
        r = A.push([mut('fc_vocab_courses', K, {}, op='delete')]); B.aggiorna()
        ok(esito(r)['status'] == 'applied' and ('fc_vocab_records', R) not in B.copia, 'primo corso cancellato, con il suo record')
    finally:
        pulisci()
    print('\n' + ('TUTTO OK' if not errori else f'{len(errori)} errori'))
    sys.exit(1 if errori else 0)

main()
