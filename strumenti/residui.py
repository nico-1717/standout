import io, sys, re
sys.path.insert(0,'/home/claude')
from audit_lingua import controlla
CODICE = re.compile(r'''
      ^<|>$|/>|</|^["'\s]*$|^\s*[,;:]
    | px[;)]|\bpx\b|%\)|\bem\b|\brem\b|\bvh\b|\bvw\b
    | var\(--|calc\(|linear-gradient|repeat\(|url\(|translate|rotate|scale\(
    | stroke|fill=|viewBox|cx=|cy=|\bd="|points=|offset=
    | ^\[|\]$|^\.|^\#[0-9A-Fa-f]{3,8}$
    | \.(php|js|css|json|png|svg|jpg|webp)\b
    | ^(?:https?:)?//|^&|^\?|=&|^[A-Za-z-]+/[A-Za-z]
    | ^\w+:\s|;\s*$|inset\s|min-width|max-width|pointer:|orientation:
    | ^[\d\s.,%:+\-]+$
''', re.X)
# parole inglesi/tecniche che compaiono come valori interni
NOTE = set('''use strict day todo late agenda buy study pills base pagehide visibilitychange primary danger
div span svg class width height line path circle star rain firefly bird swallow snow month week touchstart
touchmove touchend wheel pointerdown nowrap label mood energy preview status noopener disable enable pointerup
contacts event pointer prio blur string normal high highest mini static task toggle fold map pomo list all
textarea learned save page taken skipped pending priority delete mouse pointermove pointercancel pointerleave
smooth scroll transform transition resize touchcancel hold touch habit project stay temporary create scrolling
paging early routine numeric one dur daily weekly monthly yearly custom year name granted denied off iframe lazy
allowfullscreen keydown focus select option projectselected plev series routines planner diaryupdated sage mint
blush lavender butter sky clay rose sea olive plum sand ambop contrast daysv duplicate disconnect repair
calendars sync reauth down gesturestart dblclick orientationchange Enter Escape Notification GET POST'''.split())
for p in sys.argv[1:]:
    r=controlla(p); visti=set(); out=[]
    for riga,tipo,v in r:
        if v in visti: continue
        visti.add(v)
        if CODICE.search(v) or v.strip() in NOTE: continue
        if re.search(r"\b(?:planner|abitudini|comune|todo)\.[a-z0-9_]+", v): continue
        out.append((riga,tipo,v))
    print(f"\n{p.split('/')[-1]}: {len(out)}")
    for riga,tipo,v in out: print(f"  {riga:6d} {tipo:4} {v[:88]}")
