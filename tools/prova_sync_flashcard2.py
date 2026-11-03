# -*- coding: utf-8 -*-
"""Prova della sincronizzazione dei DATI DI STUDIO (preferiti, imparate, esempi, partite, risposte, record, impostazioni, obiettivo).

    STANDOUT_PASSWORD=... python3 tools/prova_sync_flashcard2.py --url http://127.0.0.1:8099 --user prova

Solo su indirizzi locali. Toglie da sola quello che crea.
"""
import argparse, getpass, os, sys, uuid as _uuid
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import prova_sync_progetti as P
from prova_sync_progetti import Tel, mut, nuovo, esito, ok

def v5(nome):
    """UUIDv5 come lo calcolano server e app (namespace fisso dell'app)."""
    return str(_uuid.uuid5(P.NS_UUID5, nome))


def main():
    ap = argparse.ArgumentParser(); ap.add_argument('--url', required=True); ap.add_argument('--user', required=True)
    a = ap.parse_args()
    if not any(x in a.url for x in ('127.0.0.1', 'localhost')): sys.exit('Solo indirizzi locali.')
    pw = os.environ.get('STANDOUT_PASSWORD') or getpass.getpass('Password: ')
    A = Tel(a.url, a.user, pw, 'prova st A'); B = Tel(a.url, a.user, pw, 'prova st B')
    me = A.chiama('GET', '/api/v1/me'); A.user_id = (me.get('user', {}).get('id') or me.get('id'))
    A.copia_completa(); B.copia_completa()
    d1 = nuovo(); c1 = nuovo(); c2 = nuovo(); s1 = nuovo()

    r = A.push([mut('fc_study_decks', d1, {'name': 'Studio prova'}),
                mut('fc_study_cards', c1, {'category_uuid': d1, 'question_desc': 'Q1', 'answer_desc': 'A1'}),
                mut('fc_study_cards', c2, {'category_uuid': d1, 'question_desc': 'Q2', 'answer_desc': 'A2'}),
                mut('fc_study_subcards', s1, {'parent_uuid': c1, 'question_desc': 'q', 'answer_desc': 'a'})])
    ok(all(x['status'] == 'applied' for x in r['results']), 'mazzo, due carte e una figlia per le prove')

    print('Preferiti')
    f1 = nuovo(); f2 = nuovo(); f3 = nuovo()
    r = A.push([mut('fc_study_favorites', f1, {'card_uuid': c1, 'child_uuid': None, 'favorited_date': '2026-11-02'}),
                mut('fc_study_favorites', f2, {'card_uuid': None, 'child_uuid': s1, 'favorited_date': '2026-11-02'})])
    ok(all(x['status'] == 'applied' for x in r['results']), 'preferiti di una carta e di una figlia')
    B.aggiorna()
    ok(B.copia[('fc_study_favorites', f1)]['card_uuid'] == c1 and B.copia[('fc_study_favorites', f2)]['child_uuid'] == s1, 'B li vede')
    r = A.push([mut('fc_study_favorites', f3, {'card_uuid': c1, 'child_uuid': None})])
    ok(esito(r)['status'] == 'applied', 'la stessa carta con un altro uuid: vince il nuovo')
    B.aggiorna()
    ok(('fc_study_favorites', f1) not in B.copia and ('fc_study_favorites', f3) in B.copia, 'B perde il vecchio uuid e vede il nuovo')
    r = A.push([mut('fc_study_favorites', nuovo(), {'card_uuid': c1, 'child_uuid': s1})])
    ok(esito(r)['status'] == 'rejected', 'carta e figlia insieme: rifiutato')
    r = A.push([mut('fc_study_favorites', nuovo(), {'card_uuid': None, 'child_uuid': None})])
    ok(esito(r)['status'] == 'rejected', 'nessuna delle due: rifiutato')
    r = A.push([mut('fc_study_favorites', nuovo(), {'card_uuid': nuovo()})])
    ok(esito(r)['status'] == 'rejected', 'carta che non c\'e\': rifiutato')
    r = A.push([mut('fc_study_favorites', f3, {}, 'delete')])
    B.aggiorna()
    ok(('fc_study_favorites', f3) not in B.copia, 'preferito tolto anche da B')

    print('Imparate')
    l1 = v5('fc_study_learnt|%d|%s|0' % (A.user_id, c1)); l2 = v5('fc_study_learnt|%d|%s|1' % (A.user_id, s1))
    r = A.push([mut('fc_study_learnt', l1, {'target_uuid': c1, 'is_child': False, 'learnt_date': '2026-11-01'}),
                mut('fc_study_learnt', l2, {'target_uuid': s1, 'is_child': True, 'learnt_date': '2026-11-01'})])
    ok(all(x['status'] == 'applied' for x in r['results']), 'una carta e una figlia imparate')
    B.aggiorna()
    ok(B.copia[('fc_study_learnt', l1)]['target_uuid'] == c1 and B.copia[('fc_study_learnt', l1)]['is_child'] is False, 'B vede la carta imparata')
    ok(B.copia[('fc_study_learnt', l2)]['is_child'] is True, 'B vede la figlia imparata')
    r = A.push([mut('fc_study_learnt', nuovo(), {'target_uuid': c2, 'is_child': False})])
    ok(esito(r)['status'] == 'rejected', 'uuid diverso da quello calcolato: rifiutato')
    r = A.push([mut('fc_study_learnt', l1, {'learnt_date': '2026-11-02'})])
    B.aggiorna()
    ok(B.copia[('fc_study_learnt', l1)]['learnt_date'] == '2026-11-02', 'la data si puo\' cambiare')
    r = A.push([mut('fc_study_learnt', l1, {}, 'delete')])
    B.aggiorna()
    ok(('fc_study_learnt', l1) not in B.copia, 'non piu\' imparata anche per B')

    print('Esempi')
    e1 = nuovo()
    r = A.push([mut('fc_study_examples', e1, {'card_uuid': c1, 'child_uuid': None, 'example_text': 'Un esempio'})])
    ok(esito(r)['status'] == 'applied', 'esempio creato')
    r = A.push([mut('fc_study_examples', e1, {'example_text': 'Esempio migliore'})])
    B.aggiorna()
    ok(B.copia[('fc_study_examples', e1)]['example_text'] == 'Esempio migliore' and B.copia[('fc_study_examples', e1)]['card_uuid'] == c1, 'B vede esempio e carta')
    r = A.push([mut('fc_study_examples', e1, {'example_image': 'x.png'})])
    ok(esito(r)['status'] == 'rejected', 'l\'immagine dell\'esempio e\' di sola lettura')

    print('Partite')
    g1 = nuovo(); g2 = nuovo(); t1 = nuovo(); p1 = nuovo(); p2 = nuovo(); gd = nuovo(); q1 = nuovo(); q2 = nuovo()
    r = A.push([mut('fc_games', g1, {'game_date': '2026-11-02 10:00:00', 'score': 0, 'type': 'fc', 'card_status': 'all', 'game_order': 'random',
                                      'total_father_words': 2, 'total_words_with_children': 3, 'max_card_uuid': c2})])
    ok(esito(r)['status'] == 'applied', 'partita creata')
    r = A.push([mut('fc_game_durations', t1, {'game_uuid': g1, 'start_time': '2026-11-02 10:00:00', 'duration_seconds': 0}),
                mut('fc_study_game_decks', gd, {'game_uuid': g1, 'deck_uuid': d1, 'category_order': 0}),
                mut('fc_study_sequence', q1, {'game_uuid': g1, 'position': 0, 'card_uuid': c1}),
                mut('fc_study_sequence', q2, {'game_uuid': g1, 'position': 1, 'card_uuid': c2}),
                mut('fc_study_plays', p1, {'game_uuid': g1, 'card_uuid': c1, 'child_uuid': None, 'correct_answer': 'A1', 'user_answer': '', 'position': 0}),
                mut('fc_study_plays', p2, {'game_uuid': g1, 'card_uuid': None, 'child_uuid': s1, 'correct_answer': 'a', 'user_answer': '', 'position': 1})])
    ok(all(x['status'] == 'applied' for x in r['results']), 'sessione, mazzo, sequenza e risposte create')
    B.aggiorna()
    ok(B.copia[('fc_games', g1)]['max_card_uuid'] == c2 and B.copia[('fc_games', g1)]['total_words_with_children'] == 3, 'B vede la partita e la carta piu\' nuova')
    ok(B.copia[('fc_study_plays', p2)]['child_uuid'] == s1 and B.copia[('fc_study_sequence', q2)]['card_uuid'] == c2, 'B vede risposte e sequenza')
    ok(B.copia[('fc_study_game_decks', gd)]['deck_uuid'] == d1, 'B vede il mazzo della partita')
    r = A.push([mut('fc_study_plays', p1, {'user_answer': 'Correct', 'play_date': '2026-11-02 10:00:20'}),
                mut('fc_game_durations', t1, {'duration_seconds': 95}),
                mut('fc_games', g1, {'score': 50.5})])
    ok(all(x['status'] == 'applied' for x in r['results']), 'risposta data, tempo e punteggio')
    B.aggiorna()
    ok(B.copia[('fc_study_plays', p1)]['user_answer'] == 'Correct' and B.copia[('fc_game_durations', t1)]['duration_seconds'] == 95 and B.copia[('fc_games', g1)]['score'] == 50.5, 'B vede i cambi')
    r = A.push([mut('fc_study_plays', nuovo(), {'game_uuid': g1, 'card_uuid': c1, 'position': 0, 'user_answer': ''})])
    ok(esito(r)['status'] == 'rejected', 'due risposte per la stessa carta e posizione: rifiutato')
    r = A.push([mut('fc_study_plays', p1, {'user_answer': 'Forse'})])
    ok(esito(r)['status'] == 'rejected', 'risposta non valida rifiutata')
    r = A.push([mut('fc_study_sequence', nuovo(), {'game_uuid': g1, 'position': 0, 'card_uuid': c2})])
    ok(esito(r)['status'] == 'rejected', 'posizione della sequenza gia\' usata: rifiutato')
    r = A.push([mut('fc_games', nuovo(), {'type': 'strano'})])
    ok(esito(r)['status'] == 'rejected', 'tipo di partita non valido rifiutato')
    r = A.push([mut('fc_games', g1, {'type': 'fc_L'})])
    ok(esito(r)['status'] == 'rejected', 'il tipo non cambia dopo la nascita')
    r = A.push([mut('fc_games', g2, {'game_date': '2026-11-02 11:00:00', 'type': 'fc', 'card_status': '0', 'game_order': 'in_order', 'parent_uuid': g1})])
    ok(esito(r)['status'] == 'applied', 'ripasso di un\'altra partita')
    B.aggiorna()
    ok(B.copia[('fc_games', g2)]['parent_uuid'] == g1 and B.copia[('fc_games', g2)]['card_status'] == '0', 'B vede il ripasso e il suo legame')

    print('Record, impostazioni, obiettivo')
    rec = v5('fc_study_records|%d|combo' % A.user_id)
    r = A.push([mut('fc_study_records', rec, {'kind': 'combo', 'value': 7, 'game_uuid': g1, 'achieved_at': '2026-11-02 10:05:00'})])
    ok(esito(r)['status'] == 'applied', 'record creato')
    r = A.push([mut('fc_study_records', nuovo(), {'kind': 'combo', 'value': 9})])
    ok(esito(r)['status'] == 'rejected', 'record con uuid sbagliato rifiutato')
    r = A.push([mut('fc_study_records', rec, {'value': 12})])
    B.aggiorna()
    ok(B.copia[('fc_study_records', rec)]['value'] == 12 and B.copia[('fc_study_records', rec)]['game_uuid'] == g1, 'B vede il record')
    st = v5('fc_settings|%d' % A.user_id); go = v5('fc_goals|%d' % A.user_id)
    r = A.push([mut('fc_settings', st, {'vocab_meaning': 'tocco', 'vocab_examples': 'dopo', 'voice_read': '1101'}),
                mut('fc_goals', go, {'daily_goal': 35})])
    ok(all(x['status'] == 'applied' for x in r['results']), 'impostazioni e obiettivo')
    B.aggiorna()
    ok(B.copia[('fc_settings', st)]['vocab_meaning'] == 'tocco' and B.copia[('fc_settings', st)]['voice_read'] == '1101' and B.copia[('fc_goals', go)]['daily_goal'] == 35, 'B li vede')
    r = A.push([mut('fc_settings', st, {'vocab_meaning': 'boh'})])
    ok(esito(r)['status'] == 'rejected', 'impostazione non valida rifiutata')
    r = A.push([mut('fc_goals', go, {'daily_goal': 0})])
    ok(esito(r)['status'] == 'rejected', 'obiettivo 0 rifiutato')
    r = A.push([mut('fc_goals', nuovo(), {'daily_goal': 10})])
    ok(esito(r)['status'] == 'rejected', 'obiettivo con uuid sbagliato rifiutato')
    r = A.push([mut('fc_settings', st, {'vocab_examples': 'aperto'})])
    B.aggiorna()
    ok(B.copia[('fc_settings', st)]['vocab_examples'] == 'aperto' and B.copia[('fc_settings', st)]['vocab_meaning'] == 'tocco', 'una modifica non cancella gli altri campi')

    print('Togliere la partita')
    r = A.push([mut('fc_games', g1, {}, 'delete')])
    ok(esito(r)['status'] == 'applied', 'partita tolta')
    B.aggiorna()
    gone = [('fc_games', g1), ('fc_game_durations', t1), ('fc_study_plays', p1), ('fc_study_plays', p2), ('fc_study_game_decks', gd), ('fc_study_sequence', q1), ('fc_study_sequence', q2)]
    ok(not any(k in B.copia for k in gone), 'partita, sessione, risposte, mazzi e sequenza spariscono da B')
    ok(B.copia[('fc_games', g2)]['parent_uuid'] is None, 'il ripasso resta, senza legame')

    print('Pulizia')
    r = A.push([mut('fc_games', g2, {}, 'delete'), mut('fc_study_records', rec, {}, 'delete'), mut('fc_study_examples', e1, {}, 'delete'),
                mut('fc_study_learnt', l2, {}, 'delete'), mut('fc_study_favorites', f2, {}, 'delete'),
                mut('fc_settings', st, {}, 'delete'), mut('fc_goals', go, {}, 'delete'), mut('fc_study_decks', d1, {}, 'delete')])
    ok(all(x['status'] == 'applied' for x in r['results']), 'tutto tolto')
    C = Tel(a.url, a.user, pw, 'prova st C'); C.copia_completa()
    ok(not any(k[0] in ('fc_games', 'fc_study_plays', 'fc_study_learnt', 'fc_study_favorites', 'fc_study_examples') for k in C.copia), 'un telefono nuovo non vede avanzi')
    print()
    if P.errori:
        print('FALLITE %d prove' % len(P.errori)); sys.exit(1)
    print('Tutte le prove sono passate.')

main()
