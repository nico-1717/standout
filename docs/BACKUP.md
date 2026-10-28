# Copie di sicurezza (backup)

Finché il sito sta su Altervista le copie sono **manuali**. Questo file dice cosa
copiare, quando, dove tenerlo, come provarlo e quando buttarlo (l'informativa
privacy promette **al massimo 12 mesi**: `lib/legale/privacy_*.php`).

## Cosa copiare

| Cosa | Come | Perché |
|---|---|---|
| **Database** | phpMyAdmin → database → **Esporta** → Rapido, SQL | tutti i dati degli utenti |
| **File caricati** (`media/`) | FTP / gestione file di Altervista: scarica la cartella `media/` | immagini e audio non stanno nel database |
| **`lib/config.php`** | copia a parte, in un gestore di password o in una cartella cifrata | chiavi e password; non è su GitHub e se si perde va rifatto a mano |

Il codice **non** serve copiarlo: è su GitHub.

## Quando

- **Ogni domenica** (il database), **una volta al mese** (anche `media/`).
- **Sempre prima di una migrazione** (`sql/migrations/`): è la rete di sicurezza.

## Dove tenerle

- **Mai nel repository.** `.gitignore` ferma i nomi più comuni (`my_nisama*.sql`,
  `*backup*.sql`, `*.zip`...), ma il controllo vero sei tu: la copia contiene le
  password cifrate e i dati di tutti.
- In un posto privato con accesso protetto (cartella personale cifrata o un cloud
  con la verifica in due passaggi). Non su un computer condiviso.
- Nome: `my_nisama_AAAA-MM-GG.sql`, così si vede l'età a colpo d'occhio.

## Quanto tenerle (promessa dell'informativa: massimo 12 mesi)

- Le ultime **4 settimanali**, poi **una al mese** fino a 12 mesi.
- **Tutto ciò che ha più di 12 mesi si cancella**, database e `media/`. Una copia
  contiene anche i dati di chi ha cancellato l'account dopo: finché esiste, quei
  dati esistono. Per questo il limite non si sfora.

## Provare una copia (una volta, e dopo ogni cambio importante)

Una copia mai provata è solo una speranza.

1. Crea un database di prova **in locale** (mai sul sito).
2. Importa il file `.sql`.
3. Controlla che le tabelle ci siano (`sql/schema.sql` ne ha 97) e che `users`
   abbia righe.
4. Cancella il database di prova.

(La copia di ottobre 2026 è stata provata così.)

## Se un giorno devi ripristinare

1. **Prima** di importare, esporta dal sito le righe di `security_audit_log` con
   `action = 'account_elimina'`: dicono chi ha cancellato l'account dopo la data
   della copia («utente N»).
2. Ripristina.
3. **Cancella di nuovo** quegli utenti: chi ha chiesto la cancellazione non deve
   tornare in vita per colpa di un ripristino. (`account_elimina($id)` in
   `lib/account_dati.php` fa il lavoro.)
