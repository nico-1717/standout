# -*- coding: utf-8 -*-
"""Cerca i tag PHP finiti DENTRO una stringa PHP.

E' l'errore che ha prodotto il 500: la conversione ha sostituito il contenuto di
  echo '<style>...</style>';
lasciando l'echo e gli apici attorno, quindi in pagina e' finito un <?php ... ?>
scritto dentro una stringa - e il file ha smesso di essere PHP valido.

Il controllo legge il file distinguendo codice, stringhe e commenti, e segnala
ogni "<?php" o "?>" che si trovi dentro una stringa.
"""
import io, re, sys

def pezzi(t):
    """Restituisce (tipo, inizio, fine) per ogni zona: 'php', 'str', 'com', 'html'."""
    out=[]; i=0; n=len(t); dentro_php=False
    while i<n:
        if not dentro_php:
            j=t.find('<?php',i)
            j2=t.find('<?=',i)
            if j2>=0 and (j<0 or j2<j): j=j2
            if j<0: out.append(('html',i,n)); break
            out.append(('html',i,j)); i=j; dentro_php=True; continue
        c=t[i]
        if c=='?' and t[i:i+2]=='?>': dentro_php=False; i+=2; continue
        if c=='/' and t[i:i+2]=='//':
            k=t.find('\n',i); k=n if k<0 else k; out.append(('com',i,k)); i=k; continue
        if c=='#' :
            k=t.find('\n',i); k=n if k<0 else k; out.append(('com',i,k)); i=k; continue
        if c=='/' and t[i:i+2]=='/*':
            k=t.find('*/',i+2); k=n if k<0 else k+2; out.append(('com',i,k)); i=k; continue
        if c in '\'"':
            j=i+1
            while j<n:
                if t[j]=='\\': j+=2; continue
                if t[j]==c: break
                j+=1
            out.append(('str',i,min(j+1,n))); i=j+1; continue
        i+=1
    return out

def controlla(p):
    t=io.open(p,encoding='utf-8',newline='').read()
    guai=[]
    for tipo,a,b in pezzi(t):
        if tipo!='str': continue
        z=t[a:b]
        # "<?xml ...?>" dentro una stringa e' l'intestazione di un file XML che il
        # codice genera (i .xlsx sono XML): non e' un tag PHP e non va segnalato.
        z_pulito = z.replace('<?xml', '').replace('?>', '', z.count('<?xml'))
        if '<?php' in z_pulito or '<?=' in z_pulito or '?>' in z_pulito:
            guai.append((t[:a].count('\n')+1, re.sub(r'\s+',' ',z)[:110]))
    print(f"{p.split('/')[-1]:26} {'OK' if not guai else str(len(guai))+' PROBLEMI'}")
    for r,z in guai: print(f"    riga {r}: {z}")
    return not guai

def script_chiusi_bene(p):
    """Segnala un "</script>" scritto dentro un blocco <script>.

    Il browser chiude il blocco appena vede quella sequenza, anche dentro una
    stringa o un commento: il resto del codice finisce interpretato come HTML.
    E' un errore che si fa scrivendo un commento su come NON scriverlo, ed e'
    esattamente quello che e' successo qui.
    """
    t=io.open(p,encoding='utf-8',newline='').read()
    guai=[]
    for m in re.finditer(r'<script(?![^>]*\bsrc=)[^>]*>', t):
        fine=t.find('</script>', m.end())
        if fine<0: continue
        corpo=t[m.end():fine]
        # se dopo la prima chiusura il blocco "continua" con del codice, e' segno
        # che la chiusura era dentro il codice
        if re.search(r'</\s*script', corpo, re.I):
            guai.append(t[:m.start()].count('\n')+1)
    if guai: print(f"{p.split('/')[-1]:26} script chiuso dentro il codice alle righe {guai}")
    return not guai

if __name__=='__main__':
    ok=all([controlla(p) and script_chiusi_bene(p) for p in sys.argv[1:]])
    sys.exit(0 if ok else 1)
