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
 11. (con --all) l'immagine di un articolo (carica, rileggi, file sbagliato, togli)
 12. (con --all) una terapia di prova tra le pastiglie: /api/v1/pills
 13. (con --all) il diario: letture, una voce di elenco, errori di controllo
 14. (con --all) registrazione (solo i rifiuti: nessun account si crea) e «password dimenticata»

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
La terapia di prova parte nel 2099 (nessuna presa di oggi) e non si segna mai come presa.
Il diario non viene riscritto: la prova legge soltanto e aggiunge UNA voce di elenco
al 1 gennaio 2000, che toglie alla fine; il testo e l'umore dei tuoi giorni non si toccano.

Usa solo la libreria standard di Python: niente da installare.
"""
import argparse, base64, getpass, json, os, sys
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
        # preferenze della pagina: si cambia UN valore, si controlla, si rimette com'era
        s, p0, _ = chiama(base, 'GET', 'habits/settings', intestazioni=h)
        if esito(s == 200 and 'visible_days' in p0.get('settings', {}), 'GET /habits/settings risponde', f'stato {s}'):
            prima = p0['settings']['visible_days']
            s, p1, _ = chiama(base, 'PATCH', 'habits/settings', {'visible_days': 12 if prima != 12 else 13}, h)
            esito(s == 200 and p1['settings']['visible_days'] != prima, 'PATCH cambia solo il campo inviato', f'stato {s}')
            s, p2, _ = chiama(base, 'PATCH', 'habits/settings', {'visible_days': 99}, h)
            esito(s == 422 and p2.get('field') == 'visible_days', 'un valore fuori scala e\' rifiutato (422)', f'stato {s}')
            chiama(base, 'PATCH', 'habits/settings', {'visible_days': prima}, h)
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


# Un PNG di 2x2 pixel (75 byte): basta per far passare i controlli sul contenuto.
PNG_PROVA = base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAAEklEQVR4XmM8ISfHwMDAxAAGAA0EAQiQ5XCBAAAAAElFTkSuQmCC')


def chiama_file(base, metodo, percorso, contenuto, nome_file, intestazioni):
    """Carica un file come multipart/form-data (campo «file»). Ritorna (stato, JSON o None)."""
    conf = '----prova' + os.urandom(6).hex()
    corpo = (('--%s\r\nContent-Disposition: form-data; name="file"; filename="%s"\r\nContent-Type: application/octet-stream\r\n\r\n'
              % (conf, nome_file)).encode() + contenuto + ('\r\n--%s--\r\n' % conf).encode())
    h = {'Accept': 'application/json', 'User-Agent': 'standout-prova-token/1', 'Content-Type': 'multipart/form-data; boundary=' + conf}
    h.update(intestazioni)
    req = urllib.request.Request(base.rstrip('/') + '/api/v1/' + percorso, data=corpo, headers=h, method=metodo)
    try:
        with urllib.request.urlopen(req, timeout=30) as r:
            stato, testo = r.status, r.read().decode('utf-8', 'replace')
    except urllib.error.HTTPError as e:
        stato, testo = e.code, e.read().decode('utf-8', 'replace')
    try:
        return stato, json.loads(testo)
    except ValueError:
        return stato, None


def leggi_file(base, percorso, intestazioni):
    """GET che torna il file: (stato, tipo, byte)."""
    h = {'User-Agent': 'standout-prova-token/1'}
    h.update(intestazioni)
    req = urllib.request.Request(base.rstrip('/') + '/api/v1/' + percorso, headers=h)
    try:
        with urllib.request.urlopen(req, timeout=30) as r:
            return r.status, r.headers.get('Content-Type', ''), r.read()
    except urllib.error.HTTPError as e:
        return e.code, e.headers.get('Content-Type', ''), e.read()


def prova_immagine(base, utente, password):
    """L'immagine di un articolo: carica, rileggi gli stessi byte, un file finto e' rifiutato, togli."""
    print('\n11) Immagine di un articolo (/api/v1/shopping/{id}/image)')
    h = accesso_prova(base, utente, password, 'immagine')
    if not h:
        return
    art = None
    try:
        s, c, _ = chiama(base, 'POST', 'shopping', {'title': NOME_PROVA}, h)
        if not esito(s == 201 and c and c.get('ok'), 'POST /shopping crea l\'articolo di prova (201)', f'stato {s}, {c}'):
            return
        art = c['item']['id']
        s, r = chiama_file(base, 'POST', f'shopping/{art}/image', PNG_PROVA, 'prova.png', h)
        esito(s == 200 and r and r.get('has_image') is True, 'POST /image carica il png (se qui c\'e\' 413 o 400: il server limita i caricamenti)', f'stato {s}, {r}')
        s, tipo, byte = leggi_file(base, f'shopping/{art}/image', h)
        esito(s == 200 and tipo.startswith('image/png') and byte == PNG_PROVA, 'GET /image da gli stessi byte, tipo image/png', f'stato {s}, tipo {tipo}, {len(byte)} byte')
        s, e, _ = chiama(base, 'GET', 'shopping', intestazioni=h)
        esito(s == 200 and any(x['id'] == art and x.get('has_image') is True for x in e.get('items', [])), 'l\'elenco segna has_image=true', f'stato {s}')
        s, r = chiama_file(base, 'POST', f'shopping/{art}/image', b'questo non e\' un\'immagine\n', 'finto.png', h)
        esito(s == 422 and r and r.get('field') == 'file', 'un testo con nome .png e\' rifiutato (422)', f'stato {s}, {r}')
        s, tipo, byte = leggi_file(base, f'shopping/{art}/image', {})
        esito(s == 401, 'senza token l\'immagine non si legge (401)', f'stato {s}')
        s, d, _ = chiama(base, 'DELETE', f'shopping/{art}/image', intestazioni=h)
        esito(s == 200, 'DELETE /image la toglie', f'stato {s}')
        s, tipo, byte = leggi_file(base, f'shopping/{art}/image', h)
        esito(s == 404, 'dopo, GET /image risponde 404', f'stato {s}')
    finally:
        if art is not None:
            s, d, _ = chiama(base, 'DELETE', f'shopping/{art}', intestazioni=h)
            esito(s == 200, 'DELETE toglie l\'articolo di prova', f'stato {s}')
        chiama(base, 'POST', 'auth/logout', intestazioni=h)


def prova_pillole(base, utente, password):
    """Le pastiglie: una terapia che parte nel 2099 (nessuna presa oggi). Non si segna mai come presa."""
    print('\n12) Pastiglie (/api/v1/pills)')
    h = accesso_prova(base, utente, password, 'pastiglie')
    if not h:
        return
    piano = None
    try:
        s, c, _ = chiama(base, 'POST', 'pills/plans', {'name': NOME_PROVA, 'times': '08:00', 'start_date': '2099-01-01'}, h)
        if not esito(s == 201 and c and c.get('ok'), 'POST /pills/plans crea (201)', f'stato {s}, {c}'):
            return
        piano = c['plan']['id']
        esito(c['plan']['times'] == '08:00x1' and c['plan']['active'] is True, 'la risposta ha l\'orario («08:00x1») e active=true', str(c['plan']))
        s, e, _ = chiama(base, 'GET', 'pills/plans', intestazioni=h)
        esito(s == 200 and any(x['id'] == piano for x in e.get('plans', [])), 'GET /pills/plans la mostra', f'stato {s}')
        s, e, _ = chiama(base, 'GET', 'pills', intestazioni=h)
        esito(s == 200 and e.get('installed') is True and not any(d['plan_id'] == piano for d in e.get('doses', [])), 'GET /pills: la giornata c\'e\' e la terapia del 2099 non ha prese oggi', f'stato {s}')
        s, p, _ = chiama(base, 'PATCH', f'pills/plans/{piano}', {'dose': '1 cp'}, h)
        esito(s == 200 and p['plan']['dose'] == '1 cp' and p['plan']['times'] == '08:00x1', 'PATCH cambia solo la dose', f'stato {s}, {p}')
        s, v, _ = chiama(base, 'PATCH', f'pills/plans/{piano}', {'times': '25:99'}, h)
        esito(s == 422 and v.get('field') == 'times', 'un orario sbagliato e\' rifiutato (422)', f'stato {s}')
        s, x, _ = chiama(base, 'GET', 'pills')
        esito(s == 401, 'senza token /pills risponde 401', f'stato {s}')
    finally:
        if piano is not None:
            s, d, _ = chiama(base, 'DELETE', f'pills/plans/{piano}', intestazioni=h)
            esito(s == 200, 'DELETE toglie la terapia di prova', f'stato {s}')
            s, e, _ = chiama(base, 'GET', 'pills/plans', intestazioni=h)
            esito(s == 200 and not any(x['id'] == piano for x in e.get('plans', [])), 'e non c\'e\' piu\' nell\'elenco', f'stato {s}')
        chiama(base, 'POST', 'auth/logout', intestazioni=h)


def prova_diario(base, utente, password):
    """Il diario. Il testo e l'umore dei tuoi giorni NON si toccano: si legge, si controllano gli errori
    e si aggiunge una voce di elenco al 2000-01-01 (poi tolta)."""
    print('\n13) Diario (/api/v1/diary)')
    h = accesso_prova(base, utente, password, 'diario')
    if not h:
        return
    voce = None
    GIORNO = '2000-01-01'
    try:
        s, g, _ = chiama(base, 'GET', 'diary/day', intestazioni=h)
        esito(s == 200 and g.get('ok') and 'mood' in g['day'] and 'good' in g['day'] and 'settings' in g, 'GET /diary/day risponde con il giorno e le impostazioni', f'stato {s}')
        s, m, _ = chiama(base, 'GET', 'diary/month', intestazioni=h)
        esito(s == 200 and m.get('ok') and 'days' in m, 'GET /diary/month', f'stato {s}')
        s, n, _ = chiama(base, 'GET', 'diary/insights', intestazioni=h)
        esito(s == 200 and n.get('ok'), 'GET /diary/insights', f'stato {s}')
        s, i, _ = chiama(base, 'GET', 'diary/settings', intestazioni=h)
        esito(s == 200 and i['settings']['font'] and 14 <= i['settings']['size'] <= 26, 'GET /diary/settings', f'stato {s}, {i}')
        s, v, _ = chiama(base, 'PATCH', 'diary/day', {'mood': 9}, h)
        esito(s == 422 and v.get('field') == 'mood', 'un umore fuori da 1-5 e\' rifiutato (422), niente cambia', f'stato {s}')
        s, v, _ = chiama(base, 'PATCH', 'diary/day', {}, h)
        esito(s == 422 and v.get('field') == 'body', 'una PATCH vuota e\' rifiutata (422)', f'stato {s}')
        s, c, _ = chiama(base, 'POST', 'diary/items', {'date': GIORNO, 'list_type': 'good', 'content': NOME_PROVA}, h)
        if not esito(s == 201 and c and c.get('ok'), 'POST /diary/items aggiunge una voce al 2000-01-01 (201)', f'stato {s}, {c}'):
            return
        voce = c['item']['id']
        s, g, _ = chiama(base, 'GET', f'diary/day?date={GIORNO}', intestazioni=h)
        esito(s == 200 and any(x['id'] == voce for x in g['day']['good']), 'GET /diary/day?date= la mostra', f'stato {s}')
        s, p, _ = chiama(base, 'PATCH', f'diary/items/{voce}', {'content': NOME_PROVA + ' 2'}, h)
        esito(s == 200 and p['item']['content'].endswith(' 2'), 'PATCH cambia il testo della voce', f'stato {s}, {p}')
        s, x, _ = chiama(base, 'GET', 'diary/day')
        esito(s == 401, 'senza token /diary risponde 401', f'stato {s}')
    finally:
        if voce is not None:
            s, d, _ = chiama(base, 'DELETE', f'diary/items/{voce}', intestazioni=h)
            esito(s == 200, 'DELETE toglie la voce di prova', f'stato {s}')
        chiama(base, 'POST', 'auth/logout', intestazioni=h)


def prova_account(base):
    """Registrazione e password dimenticata. NON si crea nessun account: si provano solo i rifiuti
    (campi sbagliati, nome gia' usato) e la risposta sempre uguale di «password dimenticata»."""
    print('\n14) Registrazione e password dimenticata (/api/v1/auth/register, /auth/forgot)')
    s, r, _ = chiama(base, 'POST', 'auth/register', {'username': 'x', 'email': 'no', 'password': 'corta', 'password_confirm': 'altra'})
    esito(s == 422 and r and set(r.get('fields', {})) >= {'accetto', 'username', 'email', 'password', 'password_confirm'},
          'una registrazione sbagliata e\' rifiutata (422) e dice QUALI campi', f'stato {s}, {r}')
    s, r, _ = chiama(base, 'POST', 'auth/register', {'username': 'x_prova_qualunque', 'email': 'a@b.it', 'password': 'Tr0ub4dor&3xyz!', 'password_confirm': 'Tr0ub4dor&3xyz!', 'accetto': 'true'})
    esito(s == 422 and 'accetto' in r.get('fields', {}), 'il consenso vale solo con true (non con il testo "true")', f'stato {s}')
    s1, a, _ = chiama(base, 'POST', 'auth/forgot', {'identifier': 'nessuno_' + str(os.getpid())})
    esito(s1 == 200 and a == {'ok': True}, 'password dimenticata risponde ok anche se l\'account non esiste', f'stato {s1}, {a}')
    s, v, _ = chiama(base, 'POST', 'auth/forgot', {'identifier': ''})
    esito(s == 422, 'password dimenticata con il campo vuoto: 422', f'stato {s}')


def prova_nota(base, utente, password):
    """La nota veloce del To do: si legge, si scrive sulla versione giusta, e una versione vecchia e' RIFIUTATA (409)
    con il testo del server (cosi' l'app non perde niente). La nota torna com'era alla fine."""
    print('\n15) Nota veloce (/api/v1/notes/quick)')
    s, j, _ = chiama(base, 'POST', 'auth/login', {'identifier': utente, 'password': password, 'device_name': 'prova_nota'})
    if s != 200:
        return esito(False, 'login per la prova della nota', f'stato {s}')
    h = bearer(j['access_token'])
    s, r, _ = chiama(base, 'GET', 'notes/quick', intestazioni=h)
    esito(s == 200 and 'body' in r.get('note', {}), 'si legge la nota', f'stato {s}, {r}')
    originale = r['note']['body']
    ver = r['note']['updated_at']
    s, r, _ = chiama(base, 'PUT', 'notes/quick', {'body': 'prova ' + str(os.getpid()), 'base_updated_at': ver}, intestazioni=h)
    esito(s == 200 and r['note']['body'].startswith('prova '), 'si scrive sulla versione che si conosce', f'stato {s}, {r}')
    nuova = r['note']['updated_at']
    s, r, _ = chiama(base, 'PUT', 'notes/quick', {'body': 'da un telefono vecchio', 'base_updated_at': '2000-01-01 00:00:00'}, intestazioni=h)
    esito(s == 409 and r.get('code') == 'conflict' and r['note']['body'].startswith('prova '),
          'una versione vecchia e\' rifiutata (409) e il server manda la sua', f'stato {s}, {r}')
    s, r, _ = chiama(base, 'PUT', 'notes/quick', {'body': 5}, intestazioni=h)
    esito(s == 422, 'un testo che non e\' testo: 422', f'stato {s}')
    s, r, _ = chiama(base, 'PUT', 'notes/quick', {'body': 'x' * 20001, 'base_updated_at': nuova}, intestazioni=h)
    esito(s == 422, 'oltre 20000 caratteri: 422', f'stato {s}')
    s, r, _ = chiama(base, 'PUT', 'notes/quick', {'body': originale, 'base_updated_at': nuova}, intestazioni=h)
    esito(s == 200 and r['note']['body'] == originale, 'la nota torna com\'era', f'stato {s}')


def main():
    ap = argparse.ArgumentParser(description="Prova l'accesso con token di Standout.")
    ap.add_argument('--url', default=os.environ.get('STANDOUT_URL', ''), help='indirizzo del sito, senza /api/v1')
    ap.add_argument('--user', default=os.environ.get('STANDOUT_USER', ''), help='nome utente o email')
    ap.add_argument('--xtoken', action='store_true', help="prova anche l'intestazione X-Standout-Token")
    ap.add_argument('--tasks', action='store_true', help="prova anche /api/v1/tasks (crea e toglie un'attivita' di prova)")
    ap.add_argument('--all', action='store_true', help="prova anche attivita', planner, abitudini, to buy (con immagine), pastiglie e diario (ognuno con una cosa di prova che si toglie da sola)")
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
        prova_immagine(base, utente, password)
        prova_pillole(base, utente, password)
        prova_diario(base, utente, password)
        prova_account(base)
        prova_nota(base, utente, password)

    print('\nRisultato: %d ok, %d falliti' % (verdi, rossi))
    sys.exit(1 if rossi else 0)


if __name__ == '__main__':
    main()
