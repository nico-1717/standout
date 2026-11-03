# -*- coding: utf-8 -*-
"""Prova della sincronizzazione dei VOTI (esami, gruppi, bonus) e delle impostazioni del corso.

    STANDOUT_PASSWORD=... python3 tools/prova_sync_voti.py --url http://127.0.0.1:8099 --user prova

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
    A = Tel(a.url, a.user, pw, 'prova voti A'); B = Tel(a.url, a.user, pw, 'prova voti B')
    A.copia_completa(); B.copia_completa()
    g = nuovo(); e1 = nuovo(); e2 = nuovo(); e3 = nuovo(); b1 = nuovo()
    creati = []

    print('Gruppo ed esami')
    r = A.push([mut('university_exam_groups', g, {'name': 'A scelta', 'group_type': 'choice', 'cfu': 6.0, 'sort_key': 'a0'})])
    ok(esito(r)['status'] == 'applied', 'gruppo creato')
    r = A.push([
        mut('university_exams', e1, {'name': 'Analisi', 'cfu': 9, 'status': 'todo', 'group_uuid': g, 'sort_key': 'a0'}),
        mut('university_exams', e2, {'name': 'Fisica', 'cfu': 6.5, 'status': 'done', 'grade': 28, 'exam_date': '2026-06-10', 'sort_key': 'a1'}),
        mut('university_exams', e3, {'name': 'Lingua', 'cfu': 3, 'status': 'done', 'is_pass_fail': True, 'grade': 25, 'sort_key': 'a2'}),
    ])
    ok(all(x['status'] == 'applied' for x in r['results']), 'tre esami creati')
    B.aggiorna()
    ok(B.copia.get(('university_exams', e1), {}).get('group_uuid') == g, 'B vede il gruppo dell\'esame')
    ok(B.copia[('university_exams', e2)]['cfu'] == 6.5, 'cfu con la virgola')
    ok(B.copia[('university_exams', e3)]['grade'] is None, 'idoneita\': il voto sparisce')

    print('Regole del sito')
    r = A.push([mut('university_exams', e1, {'status': 'done', 'has_honors': True, 'exam_date': '2026-07-01'})])
    ok(esito(r)['status'] == 'applied', 'esame superato con lode')
    B.aggiorna()
    ok(B.copia[('university_exams', e1)]['grade'] == 30, 'la lode mette il voto a 30')
    r = A.push([mut('university_exams', e1, {'status': 'todo'})])
    B.aggiorna()
    ok(B.copia[('university_exams', e1)]['exam_date'] is None, 'da sostenere: la data si toglie')
    r = A.push([mut('university_exams', e1, {'grade': 45})])
    ok(esito(r)['status'] == 'rejected', 'voto 45 rifiutato')
    r = A.push([mut('university_exams', e1, {'cfu': 200})])
    ok(esito(r)['status'] == 'rejected', 'cfu 200 rifiutati')
    r = A.push([mut('university_exams', e1, {'name': '  '})])
    ok(esito(r)['status'] == 'rejected', 'nome vuoto rifiutato')
    r = A.push([mut('university_exams', e1, {'group_uuid': nuovo()})])
    ok(esito(r)['status'] == 'rejected', 'gruppo che non c\'e\' rifiutato')
    r = A.push([mut('university_exams', e1, {'campo_strano': 1})])
    ok(esito(r)['status'] == 'rejected', 'campo sconosciuto rifiutato')
    r = A.push([mut('university_exams', e1, {'predicted_honors': True})])
    B.aggiorna()
    ok(B.copia[('university_exams', e1)]['predicted_grade'] == 30, 'lode prevista = 30 previsto')

    print('Gruppo: modifica, sciogliere')
    r = A.push([mut('university_exam_groups', g, {'overall_grade': 27, 'overall_honors': False})])
    B.aggiorna()
    ok(B.copia[('university_exam_groups', g)]['overall_grade'] == 27, 'voto complessivo del gruppo')
    r = A.push([mut('university_exams', e1, {'group_uuid': None}), mut('university_exam_groups', g, {}, 'delete')])
    ok(all(x['status'] == 'applied' for x in r['results']), 'gruppo sciolto')
    B.aggiorna()
    ok(('university_exam_groups', g) not in B.copia, 'il gruppo sparisce anche da B')
    ok(B.copia[('university_exams', e1)]['group_uuid'] is None, 'l\'esame resta, senza gruppo')

    print('Bonus')
    r = A.push([mut('university_bonuses', b1, {'name': 'Lodi', 'bonus_type': 'honors_each', 'points': 0.5, 'cap_points': 2, 'sort_key': 'a0'})])
    ok(esito(r)['status'] == 'applied', 'bonus creato')
    B.aggiorna()
    ok(B.copia[('university_bonuses', b1)]['points'] == 0.5 and B.copia[('university_bonuses', b1)]['is_enabled'] is True, 'B vede il bonus (acceso)')
    r = A.push([mut('university_bonuses', b1, {'bonus_type': 'boh'})])
    ok(esito(r)['status'] == 'rejected', 'tipo di bonus sconosciuto rifiutato')
    r = A.push([mut('university_bonuses', b1, {'points': 99})])
    ok(esito(r)['status'] == 'rejected', 'punti fuori misura rifiutati')

    print('Impostazioni')
    s = A.chiama('GET', '/api/v1/university/settings')
    ok(s.get('ok') and s['settings']['grade_base'] == 110, 'impostazioni lette')
    s = A.chiama('PATCH', '/api/v1/university/settings', {'total_cfu': 120, 'honors_value': 31, 'thesis_min_points': 1, 'thesis_max_points': 5, 'round_half_up': False, 'target_average': 27.5, 'target_source': 'manual'})
    ok(s.get('ok') and s['settings']['total_cfu'] == 120 and s['settings']['round_half_up'] is False, 'impostazioni salvate')
    s = A.chiama('PATCH', '/api/v1/university/settings', {'grade_base': 5})
    ok(s['_http'] == 422, 'base di laurea fuori misura rifiutata')
    s = A.chiama('PATCH', '/api/v1/university/settings', {'thesis_min_points': 6})
    ok(s['_http'] == 422, 'tesi: massimo sotto il minimo rifiutato')
    s = A.chiama('PATCH', '/api/v1/university/settings', {'total_cfu': 180, 'honors_value': 30, 'thesis_min_points': 0, 'thesis_max_points': 0, 'round_half_up': True, 'target_average': None})
    ok(s.get('ok'), 'impostazioni rimesse')

    print('Pulizia')
    A.push([mut('university_exams', x, {}, 'delete') for x in (e1, e2, e3)] + [mut('university_bonuses', b1, {}, 'delete')])
    B.aggiorna()
    ok(not any(k[1] in (e1, e2, e3, b1) for k in B.copia), 'tutto tolto')
    print()
    if P.errori:
        print('FALLITE %d prove' % len(P.errori)); sys.exit(1)
    print('Tutte le prove sono passate.')

main()
