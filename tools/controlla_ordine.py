# -*- coding: utf-8 -*-
"""Controlla che le funzioni sec_* non vengano usate prima di essere caricate.

Nasce da un 500 su visualizzazione.php: il file chiamava sec_db() subito dopo
config.php, ma le sec_* stanno in security_core.php, che viene caricato da
auth_check.php (o auth_core.php). Chiamarle prima e' un errore fatale, e la
pagina risponde 500 senza scrivere altro a schermo.

Il controllo e' banale ma prende una classe di errori che non si vede leggendo:
si trova la prima riga che carica auth e la prima che usa una sec_*, e si
guarda quale viene prima.
"""
import io, re, sys

def controlla(p):
    t = io.open(p, encoding='utf-8', newline='').read().replace('\r\n', '\n')
    # via i commenti: una sec_* citata in un commento non e' una chiamata, e
    # segnalarla insegna a ignorare il verificatore
    t = re.sub(r'/\*[\s\S]*?\*/', '', t)
    t = re.sub(r'(?m)^\s*(?://|#).*$', '', t)
    # solo la parte PHP prima dell'HTML: e' li' che si fanno gli include
    m_auth = re.search(r"require(?:_once)?\s*[('\"]*[^'\"]*(?:auth_check|auth_core|security_core)[^'\"]*['\")]*\s*;", t)
    m_sec  = re.search(r"\bsec_[a-z_]+\s*\(", t)
    if not m_sec:
        print(f"{p.split('/')[-1]:26} nessuna sec_* usata")
        return True
    if not m_auth:
        # un file puo' non caricare auth direttamente se e' incluso da un altro
        print(f"{p.split('/')[-1]:26} usa sec_* ma non carica auth: verifica che sia sempre incluso da una pagina che lo fa")
        return True
    if m_sec.start() < m_auth.start():
        riga_sec = t[:m_sec.start()].count('\n') + 1
        riga_auth = t[:m_auth.start()].count('\n') + 1
        print(f"{p.split('/')[-1]:26} ERRORE: {m_sec.group(0)} alla riga {riga_sec}, "
              f"ma auth viene caricato alla {riga_auth}")
        return False
    print(f"{p.split('/')[-1]:26} OK")
    return True

if __name__ == '__main__':
    ok = all([controlla(p) for p in sys.argv[1:]])
    sys.exit(0 if ok else 1)
