# -*- coding: utf-8 -*-
"""Prova della rotta dei FILE delle righe sincronizzate: POST/DELETE /api/v1/media/{tabella}/{uuid}/{campo}.

    STANDOUT_PASSWORD=... python3 tools/prova_media.py --url http://127.0.0.1:8099 --user prova

Solo su indirizzi locali, e va lanciata sulla stessa macchina del sito (guarda i file su disco in media/).
Toglie da sola quello che crea.
"""
import argparse, getpass, os, struct, subprocess, sys, tempfile, uuid, zlib, urllib.request, urllib.error
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from prova_sync_progetti import Tel, mut, nuovo, esito, ok

RADICE = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'media')


def png(w=8, h=8):
    """Un PNG vero, piccolo."""
    riga = b'\x00' + b'\xff\x00\x00' * w
    dati = zlib.compress(riga * h)
    def pezzo(t, d): return struct.pack('>I', len(d)) + t + d + struct.pack('>I', zlib.crc32(t + d) & 0xffffffff)
    return b'\x89PNG\r\n\x1a\n' + pezzo(b'IHDR', struct.pack('>IIBBBBB', w, h, 8, 2, 0, 0, 0)) + pezzo(b'IDAT', dati) + pezzo(b'IEND', b'')


def m4a():
    """Un file audio AAC in m4a vero (ffmpeg), mezzo secondo."""
    d = tempfile.mkdtemp(); f = os.path.join(d, 'a.m4a')
    subprocess.run(['ffmpeg', '-loglevel', 'error', '-f', 'lavfi', '-i', 'sine=frequency=440:duration=0.5', '-c:a', 'aac', f], check=True)
    return open(f, 'rb').read()


def carica(tel, tbl, u, campo, contenuto, nome='file.bin', tipo='application/octet-stream', auth=True, campo_form='file'):
    conf = '----prova' + uuid.uuid4().hex
    corpo = (f'--{conf}\r\nContent-Disposition: form-data; name="{campo_form}"; filename="{nome}"\r\nContent-Type: {tipo}\r\n\r\n').encode() + contenuto + f'\r\n--{conf}--\r\n'.encode()
    rq = urllib.request.Request(tel.base + f'/api/v1/media/{tbl}/{u}/{campo}', data=corpo, method='POST')
    rq.add_header('Content-Type', f'multipart/form-data; boundary={conf}')
    if auth: rq.add_header('Authorization', 'Bearer ' + tel.tok)
    try:
        with urllib.request.urlopen(rq, timeout=60) as r: testo = r.read().decode(); stato = r.status
    except urllib.error.HTTPError as e:
        testo = e.read().decode(); stato = e.code
    import json
    try: j = json.loads(testo)
    except Exception: j = {'ok': False, 'raw': testo[:200]}
    j['_http'] = stato
    return j


PROVA_FILE = {}

def su_disco(cartella, nome):
    PROVA_FILE.setdefault(cartella, []).append(nome)
    return os.path.isfile(os.path.join(RADICE, cartella, nome))


def main():
    ap = argparse.ArgumentParser(); ap.add_argument('--url', required=True); ap.add_argument('--user', required=True)
    a = ap.parse_args()
    if not any(x in a.url for x in ('127.0.0.1', 'localhost')): sys.exit('Solo indirizzi locali.')
    pw = os.environ.get('STANDOUT_PASSWORD') or getpass.getpass('Password: ')
    A = Tel(a.url, a.user, pw, 'prova media A'); B = Tel(a.url, a.user, pw, 'prova media B')
    A.copia_completa(); B.copia_completa()
    d1, c1, s1, art, att = nuovo(), nuovo(), nuovo(), nuovo(), nuovo()
    try:
        r = A.push([mut('fc_study_decks', d1, {'name': 'prova media (si cancella da sola)'}),
                    mut('fc_study_cards', c1, {'category_uuid': d1, 'question_desc': 'q', 'answer_desc': 'a'}),
                    mut('fc_study_subcards', s1, {'parent_uuid': c1, 'question_desc': 'qs', 'answer_desc': 'as'}),
                    mut('todos', art, {'list_type': 'buy', 'title': 'prova media articolo'}),
                    mut('todos', att, {'list_type': 'todo', 'title': 'prova media attivita'})])
        ok([x['status'] for x in r['results']] == ['applied'] * 5, f"righe di partenza: {[x['status'] for x in r['results']]}")
        B.aggiorna()

        print('1. immagine di una carta')
        r = carica(A, 'fc_study_cards', c1, 'question_image', png(), 'foto.png', 'image/png')
        n1 = r.get('name', '')
        ok(r['_http'] == 200 and r.get('ok') and n1.endswith('.png'), f"caricata: {r}")
        ok(su_disco('flashcard_images', n1), 'il file e nella cartella del sito')
        B.aggiorna(); ok(B.copia[('fc_study_cards', c1)]['question_image'] == n1, 'il secondo telefono vede il nome con la pull')

        print('2. sostituzione: il file vecchio sparisce')
        r = carica(A, 'fc_study_cards', c1, 'question_image', png(9, 9), 'altra.png', 'image/png'); n2 = r.get('name', '')
        ok(r['_http'] == 200 and n2 and n2 != n1, 'nome nuovo')
        ok(not su_disco('flashcard_images', n1) and su_disco('flashcard_images', n2), 'il vecchio e stato tolto, il nuovo c\'e')
        B.aggiorna(); ok(B.copia[('fc_study_cards', c1)]['question_image'] == n2, 'la pull porta il nome nuovo')

        print('3. audio')
        r = carica(A, 'fc_study_cards', c1, 'answer_audio', m4a(), 'voce.m4a', 'audio/mp4'); au = r.get('name', '')
        ok(r['_http'] == 200 and au.endswith('.m4a'), f"m4a accettato: {r}")
        ok(su_disco('flashcard_audio', au), 'nella cartella degli audio')
        # contenitore mp4 vero: finfo lo vede come video/mp4
        d4 = tempfile.mkdtemp(); f4 = os.path.join(d4, 'a.mp4')
        subprocess.run(['ffmpeg', '-loglevel', 'error', '-y', '-f', 'lavfi', '-i', 'sine=frequency=440:duration=0.5', '-c:a', 'aac', '-f', 'mp4', f4], check=True)
        r = carica(A, 'fc_study_cards', c1, 'answer_audio', open(f4, 'rb').read(), 'voce.mp4', 'audio/mp4')
        ok(r['_http'] == 200 and r.get('name', '').endswith('.m4a'), f"audio AAC in mp4 accettato: {r}")
        r = carica(A, 'fc_study_cards', c1, 'answer_audio', png(), 'finto.m4a', 'audio/mp4')
        ok(r['_http'] == 422 and r.get('code') == 'validation', 'un\'immagine nel campo audio e rifiutata')
        r = carica(A, 'fc_study_cards', c1, 'answer_image', m4a(), 'finta.png', 'image/png')
        ok(r['_http'] == 422, 'un audio nel campo immagine e rifiutato')

        print('4. contenuti sbagliati')
        for nome, dati in [('testo con estensione png', b'questo non e un\'immagine ' * 20), ('codice php', b'<?php echo 1; ?>' * 10), ('vuoto', b'')]:
            r = carica(A, 'fc_study_cards', c1, 'question_image', dati, 'x.png', 'image/png')
            ok(r['_http'] == 422, f'{nome}: rifiutato ({r["_http"]})')
        r = carica(A, 'fc_study_cards', c1, 'question_image', png() + b'0' * (5 * 1024 * 1024), 'grande.png', 'image/png')
        ok(r['_http'] == 422, f'oltre 5 MB: rifiutato ({r["_http"]})')
        r = carica(A, 'fc_study_cards', c1, 'question_image', png(), 'x.png', 'image/png', campo_form='altro')
        ok(r['_http'] == 422, 'campo del form con un altro nome: rifiutato')
        B.aggiorna(); ok(B.copia[('fc_study_cards', c1)]['question_image'] == n2, 'dopo i rifiuti la riga non e cambiata')

        print('5. destinazioni non ammesse')
        r = carica(A, 'fc_study_cards', c1, 'question_desc', png(), 'x.png', 'image/png'); ok(r['_http'] == 422, 'un campo di testo non e un file')
        r = carica(A, 'fc_study_decks', d1, 'icon', png(), 'x.png', 'image/png'); ok(r['_http'] == 422, 'una tabella fuori elenco')
        r = carica(A, 'users', c1, 'avatar', png(), 'x.png', 'image/png'); ok(r['_http'] == 422, 'la tabella degli utenti no')
        r = carica(A, 'fc_study_cards', nuovo(), 'question_image', png(), 'x.png', 'image/png'); ok(r['_http'] == 404, 'riga che non esiste: 404')
        r = carica(A, 'fc_study_cards', 'non-un-uuid', 'question_image', png(), 'x.png', 'image/png'); ok(r['_http'] == 404, 'uuid con la forma sbagliata: 404')
        r = carica(A, '../etc', c1, 'question_image', png(), 'x.png', 'image/png'); ok(r['_http'] == 404, 'percorso con ..: 404')
        r = carica(A, 'fc_study_cards', c1, 'question_image', png(), 'x.png', 'image/png', auth=False); ok(r['_http'] == 401, 'senza token: 401')

        print('6. carta figlia')
        r = carica(A, 'fc_study_subcards', s1, 'answer_image', png(), 'f.png', 'image/png'); ns = r.get('name', '')
        ok(r['_http'] == 200 and su_disco('flashcard_images', ns), 'immagine della carta figlia')
        B.aggiorna(); ok(B.copia[('fc_study_subcards', s1)]['answer_image'] == ns, 'la pull la porta')

        print('7. articolo del to buy')
        r = carica(A, 'todos', art, 'buy_image', png(), 'a.png', 'image/png'); nb = r.get('name', '')
        ok(r['_http'] == 200 and nb.endswith('.png'), f"immagine dell'articolo: {r}")
        B.aggiorna(); ok(B.copia[('todos', art)]['buy_image'] == nb, 'la pull la porta')
        r = carica(A, 'todos', att, 'buy_image', png(), 'a.png', 'image/png'); ok(r['_http'] == 404, 'una attivita del to do non ha immagine')

        print('8. togliere')
        r = A.chiama('DELETE', f'/api/v1/media/fc_study_cards/{c1}/question_image')
        ok(r['_http'] == 200 and not su_disco('flashcard_images', n2), 'tolta: anche il file')
        B.aggiorna(); ok(B.copia[('fc_study_cards', c1)]['question_image'] is None, 'la pull porta il vuoto')
        r = A.chiama('DELETE', f'/api/v1/media/fc_study_cards/{c1}/question_image'); ok(r['_http'] == 200, 'toglierla di nuovo va bene')
        r = A.chiama('DELETE', f'/api/v1/media/fc_study_cards/{nuovo()}/question_image'); ok(r['_http'] == 404, 'riga che non esiste: 404')
        r = A.chiama('DELETE', f'/api/v1/media/fc_study_cards/{c1}/question_desc'); ok(r['_http'] == 422, 'un campo di testo non si toglie cosi')
        r = A.chiama('DELETE', f'/api/v1/media/todos/{art}/buy_image'); ok(r['_http'] == 200, 'immagine dell\'articolo tolta')

        print('9. una riga cancellata: i suoi file restano finche non li ripulisce il sito (non si perde niente)')
    finally:
        for t, u in [('fc_study_subcards', s1), ('fc_study_cards', c1), ('fc_study_decks', d1), ('todos', art), ('todos', att)]:
            A.push([mut(t, u, {}, op='delete')])
        # i file di una riga cancellata restano (il sito li ripulisce da se'): quelli della prova si tolgono qui
        for cart in ('flashcard_images', 'flashcard_audio'):
            for nome in PROVA_FILE.get(cart, []):
                try: os.unlink(os.path.join(RADICE, cart, nome))
                except OSError: pass
    print()
    if P_errori(): sys.exit(1)
    print('Tutto a posto.')


def P_errori():
    import prova_sync_progetti as P
    if P.errori:
        print('\nFALLITE:'); [print(' -', e) for e in P.errori]; return True
    return False


if __name__ == '__main__':
    main()
