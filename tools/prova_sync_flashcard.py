# -*- coding: utf-8 -*-
"""Prova della sincronizzazione delle FLASHCARD DI STUDIO (mazzi, carte, carte figlie).

    STANDOUT_PASSWORD=... python3 tools/prova_sync_flashcard.py --url http://127.0.0.1:8099 --user prova

Solo su indirizzi locali. Toglie da sola quello che crea.
"""
import argparse, getpass, os, sys
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import prova_sync_progetti as P
from prova_sync_progetti import Tel, mut, nuovo, esito, ok


def main():
    ap = argparse.ArgumentParser(); ap.add_argument('--url', required=True); ap.add_argument('--user', required=True)
    a = ap.parse_args()
    if not any(x in a.url for x in ('127.0.0.1', 'localhost')): sys.exit('Solo indirizzi locali.')
    pw = os.environ.get('STANDOUT_PASSWORD') or getpass.getpass('Password: ')
    A = Tel(a.url, a.user, pw, 'prova fc A'); B = Tel(a.url, a.user, pw, 'prova fc B')
    A.copia_completa(); B.copia_completa()
    d1 = nuovo(); d2 = nuovo(); d3 = nuovo(); c1 = nuovo(); c2 = nuovo(); s1 = nuovo(); s2 = nuovo()

    print('Mazzi')
    r = A.push([mut('fc_study_decks', d1, {'name': 'Fisica', 'color': '#ff9500', 'color_edited': True, 'icon': None, 'exam_date': '2026-12-20'}),
                mut('fc_study_decks', d2, {'name': 'Meccanica', 'parent_uuid': d1, 'color': '#FF9500'})])
    ok(all(x['status'] == 'applied' for x in r['results']), 'due mazzi creati (uno dentro l\'altro)')
    B.aggiorna()
    ok(B.copia[('fc_study_decks', d1)]['color'] == '#FF9500', 'colore in maiuscolo')
    ok(B.copia[('fc_study_decks', d1)]['parent_uuid'] is None, 'il mazzo di primo livello non ha padre')
    ok(B.copia[('fc_study_decks', d2)]['parent_uuid'] == d1, 'B vede il padre del sotto-mazzo')
    ok(B.copia[('fc_study_decks', d1)]['exam_date'] == '2026-12-20', 'data d\'esame')
    r = A.push([mut('fc_study_decks', d1, {'parent_uuid': d2})])
    ok(esito(r)['status'] == 'rejected', 'un mazzo dentro un suo discendente: rifiutato')
    r = A.push([mut('fc_study_decks', d1, {'parent_uuid': d1})])
    ok(esito(r)['status'] == 'rejected', 'un mazzo dentro se stesso: rifiutato')
    r = A.push([mut('fc_study_decks', d1, {'name': '   '})])
    ok(esito(r)['status'] == 'rejected', 'nome vuoto rifiutato')
    r = A.push([mut('fc_study_decks', d1, {'color': 'rosso'})])
    ok(esito(r)['status'] == 'rejected', 'colore non valido rifiutato')
    r = A.push([mut('fc_study_decks', d1, {'icon': 'icona-che-non-esiste'})])
    ok(esito(r)['status'] == 'rejected', 'icona sconosciuta rifiutata')
    r = A.push([mut('fc_study_decks', d1, {'exam_date': '2026-13-45'})])
    ok(esito(r)['status'] == 'rejected', 'data impossibile rifiutata')
    r = A.push([mut('fc_study_decks', d1, {'updated_at': '2026-01-01 00:00:00'})])
    ok(esito(r)['status'] == 'rejected', 'updated_at e\' di sola lettura')
    r = A.push([mut('fc_study_decks', d1, {'pinned': True, 'archived': False, 'notes': 'Capitoli 1-5'})])
    B.aggiorna()
    ok(B.copia[('fc_study_decks', d1)]['pinned'] is True and B.copia[('fc_study_decks', d1)]['notes'] == 'Capitoli 1-5', 'fissato e note')

    print('Carte')
    doc = '{"v":2,"blocks":[{"t":"p","runs":[{"x":"Secondo principio","b":1}]}]}'
    r = A.push([mut('fc_study_cards', c1, {'category_uuid': d2, 'question_desc': doc, 'answer_desc': 'F = m a'}),
                mut('fc_study_cards', c2, {'category_uuid': d2, 'question_desc': 'Inerzia?', 'answer_desc': 'Resistenza'})])
    ok(all(x['status'] == 'applied' for x in r['results']), 'due carte create')
    B.aggiorna()
    ok(B.copia[('fc_study_cards', c1)]['question_desc'] == doc, 'il documento passa com\'e\'')
    ok(B.copia[('fc_study_cards', c1)]['category_uuid'] == d2, 'B vede il mazzo della carta')
    ok(B.copia[('fc_study_cards', c1)]['custom_order'] == 1 and B.copia[('fc_study_cards', c2)]['custom_order'] == 2, 'le carte nuove vanno in fondo')
    ok(B.copia[('fc_study_cards', c1)]['is_father'] is False, 'senza figli non e\' padre')
    r = A.push([mut('fc_study_cards', c1, {'custom_order': 9})])
    ok(esito(r)['status'] == 'rejected', 'custom_order e\' di sola lettura')
    r = A.push([mut('fc_study_cards', nuovo(), {'question_desc': 'x'})])
    ok(esito(r)['status'] == 'rejected', 'carta senza mazzo rifiutata')
    r = A.push([mut('fc_study_cards', nuovo(), {'category_uuid': nuovo(), 'question_desc': 'x'})])
    ok(esito(r)['status'] == 'rejected', 'mazzo che non c\'e\' rifiutato')
    r = A.push([mut('fc_study_cards', c2, {'question_desc': 'x' * 70000})])
    ok(esito(r)['status'] == 'rejected', 'documento troppo grande rifiutato')
    r = A.push([mut('fc_study_cards', c2, {'answer_desc': 'Resistenza al cambiamento di moto'})])
    B.aggiorna()
    ok(B.copia[('fc_study_cards', c2)]['answer_desc'] == 'Resistenza al cambiamento di moto', 'modifica della risposta')

    print('Carte figlie')
    r = A.push([mut('fc_study_subcards', s1, {'parent_uuid': c1, 'question_desc': 'Unita\'?', 'answer_desc': 'Newton'}),
                mut('fc_study_subcards', s2, {'parent_uuid': c1, 'question_desc': 'Simbolo?', 'answer_desc': 'N'})])
    ok(all(x['status'] == 'applied' for x in r['results']), 'due figlie create')
    B.aggiorna()
    ok(B.copia[('fc_study_subcards', s1)]['parent_uuid'] == c1, 'B vede il padre della figlia')
    ok(B.copia[('fc_study_subcards', s2)]['custom_order'] == 2, 'figlia in fondo')
    ok(B.copia[('fc_study_cards', c1)]['is_father'] is True, 'il padre ora e\' padre (e B lo sa)')
    r = A.push([mut('fc_study_subcards', s1, {'parent_uuid': c2})])
    ok(esito(r)['status'] == 'rejected', 'una figlia non cambia padre')
    r = A.push([mut('fc_study_subcards', nuovo(), {'parent_uuid': nuovo()})])
    ok(esito(r)['status'] == 'rejected', 'padre che non c\'e\' rifiutato')
    r = A.push([mut('fc_study_subcards', s1, {}, 'delete')])
    B.aggiorna()
    ok(('fc_study_subcards', s1) not in B.copia, 'figlia tolta anche da B')
    ok(B.copia[('fc_study_cards', c1)]['is_father'] is True, 'ne resta una: ancora padre')
    r = A.push([mut('fc_study_subcards', s2, {}, 'delete')])
    B.aggiorna()
    ok(B.copia[('fc_study_cards', c1)]['is_father'] is False, 'nessuna figlia: non e\' piu\' padre')

    print('Spostare una carta')
    r = A.push([mut('fc_study_decks', d3, {'name': 'Termodinamica', 'parent_uuid': d1}),
                mut('fc_study_cards', c2, {'category_uuid': d3})])
    ok(all(x['status'] == 'applied' for x in r['results']), 'carta spostata in un altro mazzo')
    B.aggiorna()
    ok(B.copia[('fc_study_cards', c2)]['category_uuid'] == d3 and B.copia[('fc_study_cards', c2)]['custom_order'] == 1, 'in fondo al nuovo mazzo')

    print('Togliere un ramo')
    r = A.push([mut('fc_study_subcards', s1, {'parent_uuid': c1, 'question_desc': 'Di nuovo', 'answer_desc': 'ok'})])
    ok(esito(r)['status'] == 'applied', 'figlia ricreata (per provare il ramo)')
    r = A.push([mut('fc_study_decks', d1, {}, 'delete')])
    ok(esito(r)['status'] == 'applied', 'mazzo con sotto-mazzi tolto')
    B.aggiorna()
    gone = [('fc_study_decks', d1), ('fc_study_decks', d2), ('fc_study_decks', d3), ('fc_study_cards', c1), ('fc_study_cards', c2), ('fc_study_subcards', s1)]
    ok(not any(k in B.copia for k in gone), 'mazzi, carte e figlie del ramo spariscono tutti da B')

    print('Copia completa di un telefono nuovo')
    C = Tel(a.url, a.user, pw, 'prova fc C'); C.copia_completa()
    ok(not any(k in C.copia for k in gone), 'un telefono nuovo non vede il ramo tolto')
    print()
    if P.errori:
        print('FALLITE %d prove' % len(P.errori)); sys.exit(1)
    print('Tutte le prove sono passate.')

main()
