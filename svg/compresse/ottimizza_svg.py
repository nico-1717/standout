#!/usr/bin/env python3
"""
Alleggerisce un SVG arrotondando le coordinate dei tracciati.

PERCHE' NON BASTA UN CERCA-E-SOSTITUISCI.
Questi tracciati usano quasi solo comandi relativi (c, m, s, l): ogni numero
non e' una posizione ma uno spostamento rispetto al punto precedente.
Arrotondare ogni spostamento per conto suo fa accumulare l'errore lungo tutto
il tracciato, e dopo qualche centinaio di nodi il disegno e' visibilmente
storto ("deriva").

Qui invece si tiene sempre la posizione assoluta corrente: si arrotonda la
posizione di ARRIVO, e lo spostamento da scrivere e' la differenza fra le due
posizioni gia' arrotondate. Cosi' l'errore non si somma mai: ogni punto sta
al massimo a mezza unita' da dov'era, per tutto il tracciato.

Uso: python3 ottimizza_svg.py file.svg [decimali]
"""
import re, sys, os

# quanti parametri prende ogni comando, e quali di quelli sono coordinate
PARAMS = {
    'M': 2, 'L': 2, 'T': 2, 'H': 1, 'V': 1,
    'C': 6, 'S': 4, 'Q': 4, 'A': 7, 'Z': 0,
}
NUM = re.compile(r'[-+]?(?:\d*\.\d+|\d+\.?)(?:[eE][-+]?\d+)?')


def fmt(v, dec):
    """Numero il piu' corto possibile: niente zeri finali, niente '0' iniziale."""
    s = ('%.*f' % (dec, v)) if dec > 0 else ('%d' % round(v))
    if dec > 0:
        s = s.rstrip('0').rstrip('.')
        if s in ('', '-', '-0'):
            s = '0'
        if s.startswith('0.'):
            s = s[1:]
        elif s.startswith('-0.'):
            s = '-' + s[2:]
    return s


def unisci(pezzi):
    """Rimette insieme i numeri. Lo spazio si toglie solo davanti a un segno
    meno, che fa gia' da separatore: qualunque altra scorciatoia rischia di
    incollare due numeri in uno (5 e 3 che diventano 53)."""
    testo = ' '.join(pezzi)
    return testo.replace(' -', '-')


def arrotonda_path(d, dec):
    token = re.findall(r'[MmLlHhVvCcSsQqTtAaZz]|[-+]?(?:\d*\.\d+|\d+\.?)(?:[eE][-+]?\d+)?', d)
    i = 0
    cx = cy = 0.0          # posizione assoluta vera
    rx_ = ry_ = 0.0        # posizione assoluta gia' arrotondata (quella che scriviamo)
    sx = sy = 0.0          # inizio del sottotracciato (per Z)
    srx = sry = 0.0
    fuori = []
    cmd = None

    def r(v):
        return round(v, dec) if dec > 0 else float(round(v))

    while i < len(token):
        t = token[i]
        if re.match(r'[A-Za-z]', t):
            cmd = t
            i += 1
            if cmd in 'Zz':
                fuori.append('z')
                cx, cy = sx, sy
                rx_, ry_ = srx, sry
                continue
        if cmd is None:
            i += 1
            continue

        maiusc = cmd.upper()
        n = PARAMS[maiusc]
        if i + n > len(token):
            break
        vals = [float(x) for x in token[i:i + n]]
        i += n
        rel = cmd.islower()

        pezzi = []
        if maiusc == 'A':
            # rx ry rotazione grande spazzata x y: solo gli ultimi due sono punti
            ax, ay = vals[5], vals[6]
            nx = cx + ax if rel else ax
            ny = cy + ay if rel else ay
            rnx, rny = r(nx), r(ny)
            for v in vals[:3]:
                pezzi.append(fmt(r(v), dec))
            pezzi.append(fmt(vals[3], 0))
            pezzi.append(fmt(vals[4], 0))
            if rel:
                pezzi.append(fmt(rnx - rx_, dec)); pezzi.append(fmt(rny - ry_, dec))
            else:
                pezzi.append(fmt(rnx, dec)); pezzi.append(fmt(rny, dec))
            cx, cy, rx_, ry_ = nx, ny, rnx, rny

        elif maiusc == 'H':
            nx = cx + vals[0] if rel else vals[0]
            rnx = r(nx)
            pezzi.append(fmt(rnx - rx_ if rel else rnx, dec))
            cx, rx_ = nx, rnx

        elif maiusc == 'V':
            ny = cy + vals[0] if rel else vals[0]
            rny = r(ny)
            pezzi.append(fmt(rny - ry_ if rel else rny, dec))
            cy, ry_ = ny, rny

        else:
            # coppie x,y: i punti di controllo si arrotondano rispetto al
            # punto di partenza gia' arrotondato, l'ultimo aggiorna la posizione
            px, py = cx, cy
            prx, pry = rx_, ry_
            for k in range(0, n, 2):
                ax, ay = vals[k], vals[k + 1]
                nx = px + ax if rel else ax
                ny = py + ay if rel else ay
                rnx, rny = r(nx), r(ny)
                if rel:
                    pezzi.append(fmt(rnx - prx, dec)); pezzi.append(fmt(rny - pry, dec))
                else:
                    pezzi.append(fmt(rnx, dec)); pezzi.append(fmt(rny, dec))
                if k + 2 == n:          # e' il punto d'arrivo
                    cx, cy, rx_, ry_ = nx, ny, rnx, rny
        # il comando da scrivere e' quello con cui siamo entrati, non quello
        # eventualmente cambiato qui sotto per i valori successivi
        fuori.append(cmd + unisci(pezzi))

        if maiusc == 'M':
            sx, sy, srx, sry = cx, cy, rx_, ry_
            cmd = 'l' if rel else 'L'   # dopo una M i valori extra sono L

    # ricompatta: comandi ripetuti di fila si possono omettere
    testo = ''
    prec = None
    for pezzo in fuori:
        c = pezzo[0]
        corpo = pezzo[1:]
        if c == prec and c not in 'Mm':
            if corpo and not corpo.startswith('-') and not corpo.startswith('.'):
                testo += ' '
            testo += corpo
        else:
            testo += pezzo
            prec = c
    return testo


def ottimizza(percorso, dec=0):
    s = open(percorso, encoding='utf-8').read()

    def sostituisci(m):
        return ' d="' + arrotonda_path(m.group(1), dec) + '"'

    fuori = re.sub(r'\sd="([^"]*)"', sostituisci, s)
    fuori = re.sub(r'>\s+<', '><', fuori).strip()
    return fuori


if __name__ == '__main__':
    src = sys.argv[1]
    dec = int(sys.argv[2]) if len(sys.argv) > 2 else 0
    out = ottimizza(src, dec)
    prima = os.path.getsize(src)
    dopo = len(out.encode())
    dest = sys.argv[3] if len(sys.argv) > 3 else src.replace('.svg', '.min.svg')
    open(dest, 'w', encoding='utf-8').write(out)
    print('%s: %d B -> %d B (-%d%%)' % (os.path.basename(src), prima, dopo,
                                        round(100 * (1 - dopo / prima))))
