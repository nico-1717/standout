# -*- coding: utf-8 -*-
"""Prova dell'accesso con token (PROGETTO §7.3): il giro completo contro un sito vero.

Fa, nell'ordine, quello che fara' l'app:
  1. login con nome utente + password      -> due token
  2. GET /api/v1/me con "Authorization: Bearer <access>"
  3. (con --xtoken) lo stesso con l'intestazione di riserva X-Standout-Token
  4. rinnovo con il refresh token           -> coppia nuova
  5. il vecchio refresh token NON deve piu' funzionare (e chiude la sessione)
  6. di nuovo login, poi logout             -> l'access token smette di funzionare

Serve a due cose: provare il server locale prima del push, e provare il sito vero
dopo il deploy (soprattutto che Apache lasci passare l'intestazione Authorization).

La password NON si passa mai come argomento (resterebbe nella cronologia della
shell): si legge da STANDOUT_PASSWORD oppure la si digita, senza che compaia.
Anche i token non vengono mai stampati per intero.

    STANDOUT_URL=https://nisama.altervista.org STANDOUT_USER=nome python3 tools/prova_token.py
    python3 tools/prova_token.py --url http://127.0.0.1:8099 --user ana --xtoken

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


def main():
    ap = argparse.ArgumentParser(description="Prova l'accesso con token di Standout.")
    ap.add_argument('--url', default=os.environ.get('STANDOUT_URL', ''), help='indirizzo del sito, senza /api/v1')
    ap.add_argument('--user', default=os.environ.get('STANDOUT_USER', ''), help='nome utente o email')
    ap.add_argument('--xtoken', action='store_true', help="prova anche l'intestazione X-Standout-Token")
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

    print('\nRisultato: %d ok, %d falliti' % (verdi, rossi))
    sys.exit(1 if rossi else 0)


if __name__ == '__main__':
    main()
