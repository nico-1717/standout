# -*- coding: utf-8 -*-
"""Secondo controllo: apici sbilanciati e blocchi PHP non chiusi.

Il primo controllo trova i tag finiti dentro una stringa. Questo trova il caso
gemello: una stringa aperta e mai chiusa, che fa "mangiare" al parser tutto il
resto del file. Si vede da un conteggio: dentro il codice PHP, gli apici di
apertura e chiusura devono tornare.
"""
import io, re, sys
from controlla_php import pezzi

def controlla(p):
    t=io.open(p,encoding='utf-8',newline='').read()
    zone=pezzi(t)
    # ricostruisco il codice PHP senza stringhe ne' commenti
    codice=[]
    coperte=[(a,b) for tipo,a,b in zone if tipo in ('str','com','html')]
    pos=0; fuori=[]
    for a,b in coperte:
        if a>pos: fuori.append(t[pos:a])
        pos=max(pos,b)
    fuori.append(t[pos:])
    c=''.join(fuori)
    guai=[]
    for ap,ch,nome in [('{','}','graffe'),('(',')','tonde'),('[',']','quadre')]:
        if c.count(ap)!=c.count(ch): guai.append(f"{nome}: {c.count(ap)} aperte, {c.count(ch)} chiuse")
    if "'" in c or '"' in c: guai.append(f"apice rimasto nel codice: {c.count(chr(39))} singoli, {c.count(chr(34))} doppi")
    print(f"{p.split('/')[-1]:26} {'OK' if not guai else 'DA GUARDARE'}")
    for g in guai: print("    ", g)
    return not guai

def interpolazioni_valide(p):
    """Segnala ${...} dentro una stringa con apici singoli o doppi.

    In JavaScript l'interpolazione ${} funziona SOLO nei template letterali, fra
    apici inversi. Dentro 'apici singoli' il testo ${T('chiave')} viene stampato
    cosi' com'e' e l'utente vede il codice invece della traduzione: un errore che
    non da' nessun sintomo finche' non si guarda la pagina.

    Serve un lettore a stati perche' i template si annidano: dentro ${...} di un
    template puo' cominciarne un altro, e le virgolette di un attributo HTML
    scritto in un template non sono una stringa a se'.
    """
    t = io.open(p, encoding='utf-8', newline='').read()
    guai = []
    for blocco in re.finditer(r"<script(?![^>]*\bsrc=)[^>]*>([\s\S]*?)</script>", t):
        c = blocco.group(1)
        base = blocco.start(1)
        pila = []      # 'tpl' = template letterale, 'expr' = dentro ${ } di un template
        i = 0
        while i < len(c):
            ch = c[i]
            if ch == '\\': i += 2; continue

            dentro_tpl = bool(pila) and pila[-1] == 'tpl'

            if ch == '`':
                if dentro_tpl: pila.pop()
                else: pila.append('tpl')
                i += 1; continue

            if dentro_tpl and c[i:i+2] == '${':
                pila.append('expr'); i += 2; continue
            if pila and pila[-1] == 'expr' and ch == '}':
                pila.pop(); i += 1; continue

            if ch in '\'"' and not dentro_tpl:
                # una stringa normale: si legge fino alla chiusura
                q = ch; j = i + 1; testo = ''
                while j < len(c):
                    if c[j] == '\\': testo += c[j:j+2]; j += 2; continue
                    if c[j] == q or c[j] == '\n': break
                    testo += c[j]; j += 1
                if '${' in testo:
                    riga = t[:base + i].count('\n') + 1
                    guai.append((riga, (q + testo + q)[:70]))
                i = j + 1; continue
            i += 1

    if guai:
        print(f"{p.split('/')[-1]:26} interpolazione in una stringa non template:")
        for r, z in guai: print(f"    riga {r}: {z}")
    return not guai

if __name__=='__main__':
    ok=all([controlla(p) and interpolazioni_valide(p) for p in sys.argv[1:]])
    sys.exit(0 if ok else 1)
