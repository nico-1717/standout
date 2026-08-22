# -*- coding: utf-8 -*-
"""Elenca OGNI stringa che potrebbe essere testo per l'utente e non e' tradotta.

Perche' esiste: i filtri "sembra italiano" sbagliano per costruzione. Modifica,
Salva, Categoria, lista non hanno accenti ne' parole-spia, e restavano indietro.
Qui il criterio e' rovesciato al massimo: e' candidata QUALUNQUE stringa con due
lettere di fila che non sia gia' dentro T()/t()/te(), compreso il testo dentro
gli attributi degli HTML costruiti a pezzi. Poi si guarda l'elenco a mano.

Meglio duecento righe da scorrere che una frase lasciata in italiano.
"""
import io, re, sys

def stringhe(t):
    """(inizio, fine, apice, contenuto) di ogni stringa vera, saltando commenti."""
    out=[]; i=0; n=len(t)
    while i<n:
        c=t[i]
        if c=='/' and t[i:i+2]=='//': i=t.find('\n',i); i=n if i<0 else i+1; continue
        if c=='/' and t[i:i+2]=='/*': j=t.find('*/',i+2); i=n if j<0 else j+2; continue
        if c in '\'"`':
            j=i+1
            while j<n:
                if t[j]=='\\': j+=2; continue
                if t[j]==c: break
                if c!='`' and t[j]=='\n': break
                j+=1
            if j<n and t[j]==c: out.append((i,j+1,c,t[i+1:j]))
            i=j+1; continue
        i+=1
    return out

# forme che sono certamente codice e non testo
TECNICA = re.compile(r"""^(?:
      [\w\-]+                                  # una parola-identificatore
    | [.#][\w\-].*                             # selettore
    | [a-z\-]+\s*:\s*[^;]*;?                   # dichiarazione CSS
    | [\d\s.,%:+\-()]*                         # solo numeri e punteggiatura
    | (?:https?:)?//.*                         # indirizzo
    | [\w\-]+\.(?:php|js|css|json|png|svg|jpg)(?:\?.*)?   # file
    | [a-z][\w]*-[\w\-]*(?:\s+[a-z][\w]*-[\w\-]*)*   # elenco di classi: OGNI parola ha un trattino
  )$""", re.X)

INGLESE = {'true','false','null','undefined','none','auto','block','flex','grid','left','right','top',
 'bottom','center','start','end','hidden','visible','click','change','input','submit','button','text',
 'date','time','number','color','range','checkbox','radio','password','email','url','search','file',
 'GET','POST','PUT','DELETE','application/json'}

def tecnica(v):
    v=v.strip()
    if v in INGLESE: return True
    if re.search(r'[àèéìòùÀÈÉÌÒÙ]', v): return False     # accento = testo, sempre
    if not TECNICA.match(v): return False
    # "Salva" e "Modifica" passerebbero come "una parola": si salvano se hanno
    # la maiuscola iniziale, che nei nomi di classe non c'e' quasi mai
    if re.match(r'^[A-Z][a-zà-ù]{2,}$', v): return False
    # E anche in minuscolo: "lista", "giorno", "meno" sono parole, non classi.
    # Una classe CSS di una parola sola quasi sempre ha un trattino o un prefisso
    # (pl-, hb-); una parola di dizionario no. Meglio segnalarla per niente che
    # perderla: e' esattamente l'errore che ha lasciato indietro "tutto il giorno".
    if re.match(r'^[a-zà-ù]{3,}$', v) and '-' not in v and '_' not in v: return False
    return True

def testo_umano(v):
    """Due lettere di fila e non e' una forma tecnica."""
    if len(v) < 3 or not re.search(r'[A-Za-zÀ-ù]{2}', v): return False
    return not tecnica(v)

def controlla(percorso):
    t=io.open(percorso,encoding='utf-8',newline='').read()
    fuori=[]
    for a,b,q,v in stringhe(t):
        # gia' tradotta? la stringa e' l'argomento di T/t/te/th
        prima=t[max(0,a-30):a]
        if re.search(r"\b(?:T|t|te|th)\(\s*$", prima): continue

        if '<' in v and '>' in v:
            # dentro l'HTML: testo fra i tag E attributi che si vedono
            for m in re.finditer(r'>([^<>]{2,})<', v):
                if testo_umano(m.group(1).strip()):
                    fuori.append((t[:a].count('\n')+1, 'tag', m.group(1).strip()))
            for m in re.finditer(r'\b(placeholder|title|aria-label|alt)="([^"]{2,})"', v):
                if testo_umano(m.group(2)):
                    fuori.append((t[:a].count('\n')+1, 'attr', m.group(2)))
            continue

        if testo_umano(v):
            fuori.append((t[:a].count('\n')+1, 'str', v))

    # Nei file .php c'e' anche HTML scritto direttamente in pagina: quel testo non
    # sta dentro nessuna stringa, quindi il giro qui sopra non lo vede. E' il buco
    # che ha lasciato indietro "Nessuna categoria", "Salva", "Annulla" in
    # categories.php. Qui si guardano le zone di puro HTML: fuori dai blocchi
    # <?php ?>, fuori da <script> e da <style>.
    if percorso.endswith('.php'):
        html = re.sub(r'<\?(?:php|=)[\s\S]*?\?>', ' ', t)
        html = re.sub(r'<script[\s\S]*?</script\s*>', ' ', html, flags=re.I)
        html = re.sub(r'<style[\s\S]*?</style\s*>', ' ', html, flags=re.I)
        for m in re.finditer(r'>([^<>]{2,})<', html):
            v2 = m.group(1).strip()
            if testo_umano(v2):
                fuori.append((t[:t.find(m.group(0))].count('\n') + 1 if m.group(0) in t else 0, 'html', v2))
        for m in re.finditer(r'\b(placeholder|title|aria-label|alt)="([^"]{2,})"', html):
            if testo_umano(m.group(2)):
                fuori.append((0, 'hattr', m.group(2)))

    # Le frasi spezzate su piu' righe con "+" sfuggono al controllo qui sopra:
    # ogni pezzo preso da solo puo' sembrare un frammento innocuo. Si ricompone
    # ogni catena di stringhe unite da "+" e si guarda il risultato.
    for m in re.finditer(r"(['\"][^'\"\n]*['\"]\s*\+\s*){1,}['\"][^'\"\n]*['\"]", t):
        pezzi = re.findall(r"['\"]([^'\"\n]*)['\"]", m.group(0))
        intero = ''.join(pezzi)
        if '<' in intero and '>' in intero:
            for mm in re.finditer(r'>([^<>]{2,})<', intero):
                if testo_umano(mm.group(1).strip()):
                    fuori.append((t[:m.start()].count('\n')+1, 'cat', mm.group(1).strip()))
        elif testo_umano(intero):
            fuori.append((t[:m.start()].count('\n')+1, 'cat', intero))
    return fuori

if __name__=='__main__':
    tot=0
    for p in sys.argv[1:]:
        r=controlla(p)
        tot+=len(r)
        print(f"\n===== {p.split('/')[-1]}: {len(r)} da guardare")
        visti=set()
        for riga,tipo,v in r:
            if v in visti: continue
            visti.add(v)
            print(f"  {riga:5d} {tipo:4} {v[:95]}")
    print(f"\nTOTALE: {tot}")
