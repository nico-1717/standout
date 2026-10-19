import re,sys
import os
W=os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'public', 'planner') + '/'
FILES=['css/planner_base.css','css/planner_griglia.css','css/planner_liste.css','css/planner_fogli.css','css/planner_extra.css','css/planner_responsive.css','diario.css','todo_widget.css','to_study.css','todo.css']
MAP={8:10.5,8.5:11,9:11.5,9.5:12,10:12.5,10.5:12.5,11:13,11.5:13.5,12:14}
SKIP=re.compile(r'pl-mini-static|pl-wxg|pl-mev|pl-wk-wxg|pl-ev-icon|dy-ring|dy-size|svg')
def parse(css):
    css=re.sub(r'/\*.*?\*/','',css,flags=re.S)
    out=[];i=0;stack=[]
    buf=''
    while i<len(css):
        c=css[i]
        if c=='{':
            sel=buf.strip(); buf=''
            if sel.startswith('@'):
                stack.append(sel)
            else:
                j=css.index('}',i); body=css[i+1:j]; out.append((tuple(stack),sel,body)); i=j
        elif c=='}':
            if stack: stack.pop()
            buf=''
        else: buf+=c
        i+=1
    return out
rules=[]
for f in FILES:
    for media,sel,body in parse(open(W+f,encoding='utf-8').read()):
        if any(m.startswith('@keyframes') or m.startswith('@font-face') for m in media): continue
        m=re.search(r'font-size:\s*([\d.]+)px',body)
        if not m: continue
        v=float(m.group(1))
        if v not in MAP or SKIP.search(sel): continue
        sels=[s.strip() for s in sel.split(',') if s.strip()]
        pref=', '.join('html[data-stile="standard"] '+s for s in sels)
        ls=' letter-spacing:.01em;' if re.search(r'letter-spacing:\s*\.[1-9]',body) else ''
        rules.append((media,f'{pref}{{ font-size:{MAP[v]}px;{ls} }}'))
print('/* ------------------------------------------------------------------ */')
print('/* 7. CARATTERI PIU\' GRANDI (generato da tools/gen_standard_sizes.py) */')
print('/* La carta usa etichette minuscole e spaziate (8-11px): qui salgono   */')
print('/* di 2-3px e perdono la spaziatura, come nel resto del sito.          */')
print('/* ------------------------------------------------------------------ */')
cur=None
for media,r in rules:
    key=' '.join(media)
    if key!=cur:
        if cur: print('}')
        cur=key
        if key: print(key+'{')
    print(('    ' if key else '')+r)
if cur: print('}')
