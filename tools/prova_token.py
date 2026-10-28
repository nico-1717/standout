# -*- coding: utf-8 -*-
"""Prova dell'accesso con token (PROGETTO §7.3): il giro completo contro un sito vero.

Fa, nell'ordine, quello che fara' l'app:
  1. login con nome utente + password      -> due token
  2. GET /api/v1/me con "Authorization: Bearer <access>"
  3. (con --xtoken) lo stesso con l'intestazione di riserva X-Standout-Token
  4. rinnovo con il refresh token           -> coppia nuova
  5. il vecchio refresh token NON deve piu' funzionare (e chiude la sessione)
  6. di nuovo login, poi logout             -> l'access token smette di funzionare
  7. (con --tasks) crea, legge, spunta e toglie un'attivita' di prova: /api/v1/tasks
  8. (con --all) lo stesso per un evento del planner: /api/v1/events
  9. (con --all) un'abitudine di prova col suo check-in: /api/v1/habits
 10. (con --all) un articolo e una categoria del to buy: /api/v1/shopping

Serve a due cose: provare il server locale prima del push, e provare il sito vero
dopo il deploy (soprattutto che Apache lasci passare l'intestazione Authorization).

La password NON si passa mai come argomento (resterebbe nella cronologia della
shell): si legge da STANDOUT_PASSWORD oppure la si digita, senza che compaia.
Anche i token non vengono mai stampati per intero.

    STANDOUT_URL=https://nisama.altervista.org STANDOUT_USER=nome python3 tools/prova_token.py
    python3 tools/prova_token.py --url http://127.0.0.1:8099 --user ana --xtoken
    python3 tools/prova_token.py --url https://nisama.altervista.org --user nome --tasks
    python3 tools/prova_token.py --url https://nisama.altervista.org --user nome --all

Con --tasks scrive nel tuo account UNA attivita' chiamata «prova API (si cancella da sola)»
e la toglie alla fine, anche se qualcosa va storto a meta'. Con --all fa lo stesso per
un evento, un'abitudine (col suo check-in) e un articolo con la sua categoria: ognuno si
chiama «prova API (si cancella da sola)» e si toglie alla fine, anche se qualcosa va storto.
Gli articoli di prova non vengono mai spuntati: spuntarli li metterebbe nei suggerimenti
del to buy, e dall'API non si possono togliere di li'.

Usa solo la libreria standard di Python: niente da installare.
"""
import argparse, getpass, json, os, sys
import urllib.request, urllib.error

verdi = rossi = 0


def esito(ok, testo, dettaglio=''):
    global verdi, rossi
    if ok:
        verdi += 1
    else:
        rossi += 1
    print(('  OK      ' if ok else '  FALLITO ') + testo + (('  -> ' + dettaglio) if (dettaglio and not ok) else ''))
    return ok


def chiama(base, metodo, percorso, corpo=None, intestazioni=None):
    """Una richiesta all'API. Ritorna (stato HTTP, JSON o None, intestazioni)."""
    dati = json.dumps(corpo).encode('utf-8') if corpo is not None else None
    h = {'Accept': 'application/json', 'User-Agent': 'standout-prova-token/1'}
    if dati is not None:
        h['Content-Type'] = 'application/json'
    h.update(intestazioni or {})
    req = urllib.request.Request(base.rstrip('/') + '/api/v1/' + percorso, data=dati, headers=h, method=metodo)
    try:
        with urllib.request.urlopen(req, timeout=30) as r:
            stato, testo, hdr = r.status, r.read().decode('utf-8', 'replace'), r.headers
    except urllib.error.HTTPError as e:
        stato, testo, hdr = e.code, e.read().decode('utf-8', 'replace'), e.headers
    try:
        js = json.loads(testo)
    except ValueError:
        js = None
    return stato, js, hdr


def bearer(token):
    return {'Authorization': 'Bearer ' + token}


def breve(token):
    return token[:7] + '…' if token else '(vuoto)'


def prova_attivita(base, utente, password):
    """Il giro delle attivita': crea, legge, spunta, toglie. Toglie sempre quella di prova."""
    print('\n7) Attivita\' (/api/v1/tasks)')
    s, j, _ = chiama(base, 'POST', 'auth/login', {'identifier': utente, 'password': password, 'device_name': 'prova_token.py (attivita)'})
    if not esito(s == 200 and j and j.get('ok'), 'login per la prova delle attivita\''):
        return
    h = bearer(j['access_token'])
    if j.get('terms_required'):
        print('  NOTA    Accetta prima le condizioni dal sito: senza, le attivita\' rispondono 403.')
        return
    nuovo = None
    try:
        s, c, _ = chiama(base, 'POST', 'tasks', {'title': 'prova API (si cancella da sola)', 'date': '2099-01-01', 'time': '10:00'}, h)
        if not esito(s == 201 and c and c.get('ok'), 'POST /tasks crea (201)', f'stato {s}, {c}'):
            return
        nuovo = c['task']['id']
        esito(c['task']['title'] == 'prova API (si cancella da sola)' and c['task']['done'] is False, 'la risposta ha titolo e done=false')
        s, e, _ = chiama(base, 'GET', 'tasks', intestazioni=h)
        esito(s == 200 and any(t['id'] == nuovo for t in e.get('tasks', [])), 'GET /tasks la mostra', f'stato {s}')
        s, p, _ = chiama(base, 'PATCH', f'tasks/{nuovo}', {'done': True}, h)
        esito(s == 200 and p['task']['done'] is True, 'PATCH {done:true} la spunta (se qui c\'e\' 405 o 403: il server blocca PATCH)', f'stato {s}, {p}')
        s, v, _ = chiama(base, 'PATCH', f'tasks/{nuovo}', {'title': ''}, h)
        esito(s == 422 and v.get('code') == 'validation', 'un titolo vuoto e\' rifiutato (422)', f'stato {s}')
        s, x, _ = chiama(base, 'GET', 'tasks')
        esito(s == 401, 'senza token /tasks risponde 401', f'stato {s}')
    finally:
        if nuovo is not None:
            s, d, _ = chiama(base, 'DELETE', f'tasks/{nuovo}', intestazioni=h)
            esito(s == 200, 'DELETE toglie l\'attivita\' di prova', f'stato {s}')
        chiama(base, 'POST', 'auth/logout', intestazioni=h)


NOME_PROVA = 'prova API (si cancella da sola)'


def accesso_prova(base, utente, password, etichetta):
    """Login per una prova. Ritorna le intestazioni col token, o None (e dice perche')."""
    s, j, _ = chiama(base, 'POST', 'auth/login', {'identifier': utente, 'password': password, 'device_name': 'prova_token.py (%s)' % etichetta})
    if not esito(s == 200 and j and j.get('ok'), 'login per la prova: ' + etichetta):
        return None
    if j.get('terms_required'):
        print('  NOTA    Accetta prima le condizioni dal sito: senza, queste rotte rispondono 403.')
        return None
    return bearer(j['access_token'])


def prova_eventi(base, utente, password):
    """Il giro del planner: crea, legge, spunta, toglie. Toglie sempre quello di prova."""
    print('\n8) Planner (/api/v1/events)')
    h = accesso_prova(base, utente, password, 'eventi')
    if not h:
        return
    nuovo = None
    try:
        s, c, _ = chiama(base, 'POST', 'events', {'title': NOME_PROVA, 'date': '2099-01-01', 'start': '10:00'}, h)
        if not esito(s == 201 and c and c.get('ok'), 'POST /events crea (201)', f'stato {s}, {c}'):
            return
        nuovo = c['event']['id']
        esito(c['event']['title'] == NOME_PROVA and c['event']['date'] == '2099-01-01', 'la risposta ha titolo e data')
        s, e, _ = chiama(base, 'GET', 'events?from=2099-01-01&to=2099-01-02', intestazioni=h)
        esito(s == 200 and any(x['id'] == nuovo for x in e.get('events', [])), 'GET /events lo mostra nel suo giorno', f'stato {s}')
        s, p, _ = chiama(base, 'PATCH', f'events/{nuovo}', {'done': True}, h)
        esito(s == 200 and p['event']['done'] is True, 'PATCH {done:true} lo spunta', f'stato {s}, {p}')
        s, v, _ = chiama(base, 'POST', 'events', {'title': ''}, h)
        esito(s == 422 and v.get('code') == 'validation', 'un titolo vuoto e\' rifiutato (422)', f'stato {s}')
        s, x, _ = chiama(base, 'GET', 'events?from=2099-01-01&to=2099-01-02')
        esito(s == 401, 'senza token /events risponde 401', f'stato {s}')
    finally:
        if nuovo is not None:
            s, d, _ = chiama(base, 'DELETE', f'events/{nuovo}', intestazioni=h)
            esito(s == 200, 'DELETE toglie l\'evento di prova', f'stato {s}')
        chiama(base, 'POST', 'auth/logout', intestazioni=h)


def prova_abitudini(base, utente, password):
    """Il giro delle abitudini: crea, check-in, annulla, toglie. Toglierla toglie anche il suo check-in."""
    print('\n9) Abitudini (/api/v1/habits)')
    h = accesso_prova(base, utente, password, 'abitudini')
    if not h:
        return
    nuovo = None
    try:
        s, c, _ = chiama(base, 'POST', 'habits', {'name': NOME_PROVA}, h)
        if not esito(s == 201 and c and c.get('ok'), 'POST /habits crea (201)', f'stato {s}, {c}'):
            return
        nuovo = c['habit']['id']
        s, e, _ = chiama(base, 'GET', 'habits', intestazioni=h)
        esito(s == 200 and any(x['id'] == nuovo for x in e.get('habits', [])), 'GET /habits la mostra', f'stato {s}')
        s, k, _ = chiama(base, 'POST', f'habits/{nuovo}/log', {}, h)
        esito(s == 200 and k['checkin']['status'] == 'done' and k['checkin']['streak'] == 1, 'POST /log: fatto oggi, serie 1', f'stato {s}, {k}')
        s, k, _ = chiama(base, 'POST', f'habits/{nuovo}/log', {'mode': 'toggle'}, h)
        esito(s == 200 and k['checkin']['removed'] is True, 'di nuovo: il check-in si annulla', f'stato {s}, {k}')
        s, v, _ = chiama(base, 'POST', f'habits/{nuovo}/log', {'date': '2099-01-01'}, h)
        esito(s == 422 and v.get('field') == 'date', 'un giorno futuro e\' rifiutato (422)', f'stato {s}')
        s, x, _ = chiama(base, 'GET', 'habits')
        esito(s == 401, 'senza token /habits risponde 401', f'stato {s}')
    finally:
        if nuovo is not None:
            s, d, _ = chiama(base, 'DELETE', f'habits/{nuovo}', intestazioni=h)
            esito(s == 200, 'DELETE toglie l\'abitudine di prova', f'stato {s}')
        chiama(base, 'POST', 'auth/logout', intestazioni=h)


def prova_acquisti(base, utente, password):
    """Il giro del to buy: una categoria e un articolo. Non si spunta (finirebbe nei suggerimenti)."""
    print('\n10) To buy (/api/v1/shopping)')
    h = accesso_prova(base, utente, password, 'to buy')
    if not h:
        return
    cat = art = None
    try:
        s, c, _ = chiama(base, 'POST', 'shopping/categories', {'name': NOME_PROVA, 'color': '#336699'}, h)
        if not esito(s == 201 and c and c.get('ok'), 'POST /shopping/categories crea (201)', f'stato {s}, {c}'):
            return
        cat = c['category']['id']
        s, c, _ = chiama(base, 'POST', 'shopping', {'title': NOME_PROVA, 'category_id': cat, 'price_cents': 199, 'priority': 1}, h)
        if not esito(s == 201 and c and c.get('ok'), 'POST /shopping crea l\'articolo (201)', f'stato {s}, {c}'):
            return
        art = c['item']['id']
        esito(c['item']['category_id'] == cat and c['item']['price_cents'] == 199, 'la risposta ha categoria e prezzo in centesimi')
        s, e, _ = chiama(base, 'GET', 'shopping', intestazioni=h)
        esito(s == 200 and any(x['id'] == art for x in e.get('items', [])) and any(x['id'] == cat for x in e.get('categories', [])), 'GET /shopping mostra articolo e categoria', f'stato {s}')
        s, p, _ = chiama(base, 'PATCH', f'shopping/{art}', {'priority': 2}, h)
        esito(s == 200 and p['item']['priority'] == 2, 'PATCH cambia solo la priorita\'', f'stato {s}, {p}')
        s, v, _ = chiama(base, 'POST', 'shopping', {'title': 'x', 'url': 'javascript:alert(1)'}, h)
        esito(s == 422 and v.get('field') == 'url', 'un indirizzo che non e\' http/https e\' rifiutato (422)', f'stato {s}')
        s, x, _ = chiama(base, 'GET', 'shopping')
        esito(s == 401, 'senza token /shopping risponde 401', f'stato {s}')
    finally:
        if art is not None:
            s, d, _ = chiama(base, 'DELETE', f'shopping/{art}', intestazioni=h)
            esito(s == 200, 'DELETE toglie l\'articolo di prova', f'stato {s}')
        if cat is not None:
            s, d, _ = chiama(base, 'DELETE', f'shopping/categories/{cat}', intestazioni=h)
            esito(s == 200, 'DELETE toglie la categoria di prova', f'stato {s}')
        chiama(base, 'POST', 'auth/logout', intestazioni=h)


def main():
    ap = argparse.ArgumentParser(description="Prova l'accesso con token di Standout.")
    ap.add_argument('--url', default=os.environ.get('STANDOUT_URL', ''), help='indirizzo del sito, senza /api/v1')
    ap.add_argument('--user', default=os.environ.get('STANDOUT_USER', ''), help='nome utente o email')
    ap.add_argument('--xtoken', action='store_true', help="prova anche l'intestazione X-Standout-Token")
    ap.add_argument('--tasks', action='store_true', help="prova anche /api/v1/tasks (crea e toglie un'attivita' di prova)")
    ap.add_argument('--all', action='store_true', help="prova anche attivita', planner, abitudini e to buy (ognuno con una cosa di prova che si toglie da sola)")
    a = ap.parse_args()

    if not a.url:
        sys.exit('Manca l\'indirizzo: --url oppure STANDOUT_URL')
    utente = a.user or input('Nome utente o email: ').strip()
    password = os.environ.get('STANDOUT_PASSWORD') or getpass.getpass('Password (non si vede mentre scrivi): ')
    base = a.url

    print('\n1) Login')
    s, j, _ = chiama(base, 'POST', 'auth/login', {'identifier': utente, 'password': password, 'device_name': 'prova_token.py'})
    if not esito(s == 200 and j and j.get('ok') is True, 'login con le credenziali giuste', f'stato {s}, risposta {j}'):
        print('\nSenza login non si puo\' andare avanti.')
        if j and j.get('code') == 'invalid_credentials':
            print('Controlla nome utente e password.')
        sys.exit(1)
    acc, rin = j['access_token'], j['refresh_token']
    esito(j.get('token_type') == 'Bearer' and acc.startswith('sta.') and rin.startswith('str.'), 'i token hanno la forma attesa', f'{breve(acc)} {breve(rin)}')
    esito(isinstance(j.get('expires_in'), int) and j['expires_in'] > 0, "c'e' la durata dell'access token", str(j.get('expires_in')))
    if j.get('terms_required'):
        print('  NOTA    Questo utente deve ancora accettare le condizioni: /me rispondera 403 terms_required.')
        print('          Accettale dal sito, oppure con POST /api/v1/auth/accept_terms.')

    print('\n2) /me con Authorization: Bearer')
    s, m, _ = chiama(base, 'GET', 'me', intestazioni=bearer(acc))
    if j.get('terms_required'):
        esito(s == 403 and m.get('code') == 'terms_required', 'senza condizioni accettate: 403 terms_required', f'stato {s}')
    else:
        esito(s == 200 and m.get('ok') and m['user'].get('username'), 'GET /me con il Bearer risponde 200', f'stato {s}, {m}')
        if s == 200:
            esito('email_verified' in m['user'], "/me mostra email_verified")
            esito(not any(k in json.dumps(m) for k in ('hash', 'password', 'refresh')), '/me non mostra campi segreti')
    if s == 401 and m and m.get('code') == 'unauthenticated':
        print('  ATTENZIONE: il login ha dato un token ma /me non lo vede. Quasi certamente Apache')
        print('              butta via l\'intestazione Authorization. Riprova con --xtoken.')

    if a.xtoken:
        print('\n3) /me con X-Standout-Token (intestazione di riserva)')
        s, m, _ = chiama(base, 'GET', 'me', intestazioni={'X-Standout-Token': acc})
        esito(s in (200, 403), "l'intestazione di riserva e' accettata", f'stato {s}')

    print('\n4) Rinnovo')
    s, n, _ = chiama(base, 'POST', 'auth/refresh', {'refresh_token': rin})
    if not esito(s == 200 and n and n.get('ok'), 'il refresh token da una coppia nuova', f'stato {s}, {n}'):
        sys.exit(1)
    acc2, rin2 = n['access_token'], n['refresh_token']
    esito(acc2 != acc and rin2 != rin, 'i due token sono cambiati (rotazione)')
    s, _, _ = chiama(base, 'GET', 'me', intestazioni=bearer(acc2))
    esito(s in (200, 403), 'il nuovo access token funziona', f'stato {s}')
    s, _, _ = chiama(base, 'GET', 'me', intestazioni=bearer(acc))
    esito(s == 401, "il vecchio access token non vale piu'", f'stato {s}')

    print('\n5) Il vecchio refresh token')
    s, r, _ = chiama(base, 'POST', 'auth/refresh', {'refresh_token': rin})
    esito(s == 401 and r.get('code') == 'token_reused', 'riusarlo e\' rifiutato (token_reused)', f'stato {s}, {r}')
    s, r, _ = chiama(base, 'GET', 'me', intestazioni=bearer(acc2))
    esito(s == 401, 'e la sessione di quel dispositivo e\' chiusa (anche il token nuovo)', f'stato {s}')

    print('\n6) Logout')
    s, j2, _ = chiama(base, 'POST', 'auth/login', {'identifier': utente, 'password': password, 'device_name': 'prova_token.py (2)'})
    if not esito(s == 200 and j2.get('ok'), 'secondo login'):
        sys.exit(1)
    acc3 = j2['access_token']
    s, d, _ = chiama(base, 'GET', 'auth/devices', intestazioni=bearer(acc3))
    if s == 200:
        esito(any(x.get('current') for x in d['devices']), "l'elenco dei dispositivi segna quello attuale")
        esito(not any(k in json.dumps(d) for k in ('hash', 'selector', 'refresh')), "l'elenco non mostra campi segreti")
    s, o, _ = chiama(base, 'POST', 'auth/logout', intestazioni=bearer(acc3))
    esito(s == 200 and o.get('ok'), 'logout', f'stato {s}, {o}')
    s, _, _ = chiama(base, 'GET', 'me', intestazioni=bearer(acc3))
    esito(s == 401, "dopo il logout l'access token non funziona piu'", f'stato {s}')

    if a.tasks or a.all:
        prova_attivita(base, utente, password)
    if a.all:
        prova_eventi(base, utente, password)
        prova_abitudini(base, utente, password)
        prova_acquisti(base, utente, password)

    print('\nRisultato: %d ok, %d falliti' % (verdi, rossi))
    sys.exit(1 if rossi else 0)


if __name__ == '__main__':
    main()
