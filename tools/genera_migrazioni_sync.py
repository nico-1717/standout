# -*- coding: utf-8 -*-
"""Genera le migrazioni SQL della sincronizzazione (app, passo 12).

PERCHE' UNO SCRIPT: servono ~70 tabelle con le stesse 4-5 modifiche (uuid,
updated_at, sort_key). Scriverle a mano vorrebbe dire sbagliarne una; cosi' le
regole stanno in UN elenco (TABELLE qui sotto) e i file SQL sono il risultato.
Non si lancia sul sito: si lancia qui, e si committano i file prodotti.

    python3 tools/genera_migrazioni_sync.py            # riscrive sql/migrations/2026-11-01_sync_*.sql
    python3 tools/genera_migrazioni_sync.py --schema   # stampa il blocco da aggiungere a sql/schema.sql

Legge sql/schema.sql per sapere quali colonne esistono gia' (updated_at, sort_order...):
una tabella che ha gia' updated_at non la tocca.

TIPI DI TABELLA
  riga      ha un id suo: aggiunge `uuid` (CHAR(36), univoco). E' l'identita' che l'app usa.
  singola   una riga per utente (PK user_id): niente uuid, l'identita' e' UUIDv5(tabella|user).
  naturale  chiave fatta di campi (user_id, kind...): niente uuid, identita' = UUIDv5(tabella|chiave).
  append    come «riga» ma si scrive e non si modifica mai (log): niente updated_at.
  solo_server  scritta dal server (AI, cache): l'app la legge e basta; come «riga».

Per le righe ordinabili aggiunge `sort_key` (stringhe di «fractional-indexing»: si
inserisce fra due righe senza rinumerare tutto). La colonna e' ASCII BINARIA: con una
collation che ignora le maiuscole «Z» e «a» si ordinerebbero a rovescio.
"""
import os, re, sys

RADICE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DATA = '2026-11-01'

# nome: (tipo, area, ordine) ; ordine = (colonna_ordine, [colonne di partizione]) oppure None
# Le aree decidono in quale file finisce la tabella (e quando Nico lo deve eseguire).
AREE = {
    'attivita':   ('02', 'attivita',   "to do e to buy"),
    'abitudini':  ('03', 'abitudini',  "abitudini, pastiglie, diario, home"),
    'planner':    ('04', 'planner',    "planner"),
    'progetti':   ('05', 'progetti',   "progetti, Pomodoro, piano, voti"),
    'flashcard':  ('06', 'flashcard',  "flashcard di studio e di vocabolario"),
    'flashcard2': ('07', 'flashcard2', "flashcard: imparate, record, sequenze delle partite, preferenze, obiettivi (le tabelle senza id)"),
}

TABELLE = {
    # --- to do / to buy
    'todos':                ('riga', 'attivita', ('sort_order', ['user_id', 'list_type'])),
    'todo_buy_categories':  ('riga', 'attivita', ('sort_order', ['user_id'])),
    'todo_notes':           ('singola', 'attivita', None),
    'todo_buy_memory':      ('naturale', 'attivita', None),
    # --- abitudini, pastiglie, diario, home
    'habit_categories':     ('riga', 'abitudini', ('sort_order', ['user_id'])),
    'habits':               ('riga', 'abitudini', ('sort_order', ['user_id'])),
    'habit_logs':           ('riga', 'abitudini', None),
    'habit_log_events':     ('append', 'abitudini', None),
    'habit_daily_journal':  ('riga', 'abitudini', None),
    'habit_user_achievements': ('append', 'abitudini', None),
    'habit_reminders':      ('riga', 'abitudini', None),
    'habit_settings':       ('singola', 'abitudini', None),
    'pill_plans':           ('riga', 'abitudini', ('sort_order', ['user_id'])),
    'pill_doses':           ('riga', 'abitudini', None),
    'diary_list_items':     ('riga', 'abitudini', ('sort_order', ['user_id', 'entry_date', 'list_type'])),
    'diary_settings':       ('singola', 'abitudini', None),
    'diary_ai_books':       ('solo_server', 'abitudini', None),
    'diary_ai_reports':     ('solo_server', 'abitudini', None),
    'home_layout':          ('singola', 'abitudini', None),
    'fc_quick_drafts':      ('riga', 'abitudini', None),
    # --- planner
    'planner_calendars':    ('riga', 'planner', ('sort_order', ['user_id'])),
    'planner_categories':   ('riga', 'planner', ('sort_order', ['user_id'])),
    'planner_category_keywords': ('riga', 'planner', None),
    'planner_places':       ('riga', 'planner', ('sort_order', ['user_id'])),
    'planner_priorities':   ('riga', 'planner', ('position', ['user_id', 'priority_date'])),
    'planner_routines':     ('riga', 'planner', ('sort_order', ['user_id'])),
    'planner_routine_items': ('riga', 'planner', ('sort_order', ['routine_id'])),
    'planner_events':       ('riga', 'planner', ('sort_order', ['user_id'])),
    'planner_weeks':        ('riga', 'planner', None),
    'planner_week_lists':   ('riga', 'planner', ('sort_order', ['user_id', 'week_start', 'list_type'])),
    'planner_week_list_items': ('riga', 'planner', ('sort_order', ['list_id'])),
    'event_guests':         ('riga', 'planner', None),
    'contacts':             ('riga', 'planner', None),
    'planner_settings':     ('singola', 'planner', None),
    # --- progetti, Pomodoro, piano, voti
    'projects':             ('riga', 'progetti', ('custom_order', ['user_id', 'parent_id'])),
    'pomodoro_sessions':    ('riga', 'progetti', None),
    'pomodoro_custom_durations': ('riga', 'progetti', None),
    'pomodoro_settings':    ('singola', 'progetti', None),
    'pomodoro_current_breaks': ('singola', 'progetti', None),
    'plan_goals':           ('naturale', 'progetti', None),
    'plan_paint':           ('naturale', 'progetti', None),
    'plan_days':            ('naturale', 'progetti', None),
    'plan_settings':        ('singola', 'progetti', None),
    'plan_templates':       ('riga', 'progetti', None),
    'study_templates':      ('riga', 'progetti', None),
    'university_exams':     ('riga', 'progetti', ('sort_order', ['user_id'])),
    'university_exam_groups': ('riga', 'progetti', ('sort_order', ['user_id'])),
    'university_bonuses':   ('riga', 'progetti', ('sort_order', ['user_id'])),
    'university_settings':  ('singola', 'progetti', None),
    # --- flashcard (studio)
    'fc_study_decks':       ('riga', 'flashcard', None),
    'fc_study_cards':       ('riga', 'flashcard', ('custom_order', ['category_id'])),
    'fc_study_subcards':    ('riga', 'flashcard', ('custom_order', ['parent_id'])),
    'fc_study_examples':    ('riga', 'flashcard', None),
    'fc_study_favorites':   ('riga', 'flashcard', None),
    'fc_study_learnt':       ('nat_id_u', 'flashcard2', None),
    'fc_study_records':      ('nat_id_u', 'flashcard2', None),
    'fc_study_deck_projects': ('nat_id', 'flashcard2', None),
    'fc_study_ai':           ('uuid_u', 'flashcard2', None),
    'fc_study_ai_child':     ('uuid_u', 'flashcard2', None),
    'fc_games':             ('riga', 'flashcard', None),
    'fc_game_durations':    ('append', 'flashcard', None),
    'fc_study_game_decks':   ('nat_id', 'flashcard2', None),
    'fc_study_sequence':     ('nat_id', 'flashcard2', None),
    'fc_study_plays':       ('append', 'flashcard', None),
    # --- flashcard (vocabolario)
    'fc_vocab_courses':     ('riga', 'flashcard', ('custom_order', ['user_id'])),
    'fc_vocab_decks':       ('riga', 'flashcard', None),
    'fc_vocab_cards':       ('riga', 'flashcard', ('custom_order', ['category_id'])),
    'fc_vocab_subcards':    ('riga', 'flashcard', ('custom_order', ['parent_id'])),
    'fc_vocab_examples':    ('riga', 'flashcard', None),
    'fc_vocab_favorites':   ('riga', 'flashcard', None),
    'fc_vocab_learnt':       ('nat_id_u', 'flashcard2', None),
    'fc_vocab_records':      ('nat_id_u', 'flashcard2', None),
    'fc_vocab_game_decks':   ('nat_id', 'flashcard2', None),
    'fc_vocab_sequence':     ('nat_id', 'flashcard2', None),
    'fc_vocab_plays':       ('append', 'flashcard', None),
    'fc_settings':           ('uuid_u', 'flashcard2', None),
    'fc_goals':              ('uuid_u', 'flashcard2', None),
}

TIPI_CON_UUID = ('riga', 'append', 'solo_server', 'nat_id', 'nat_id_u', 'uuid_u')
TIPI_CON_ID = ('nat_id', 'nat_id_u')   # tabelle a chiave composta senza id: ne ricevono uno (serve a scorrerle a pagine)
TIPI_CON_UPDATED_AT = ('riga', 'solo_server', 'singola', 'nat_id_u', 'uuid_u')   # append e naturale non si modificano (o non servono)

ALFABETO = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz'


def leggi_schema():
    s = open(os.path.join(RADICE, 'sql', 'schema.sql'), encoding='utf-8').read()
    out = {}
    for n, corpo in re.findall(r'CREATE TABLE (?:IF NOT EXISTS )?`?(\w+)`?\s*\((.*?)\n\)\s*(?:ENGINE[^;]*)?;', s, re.S):
        cols = []
        for riga in corpo.split('\n'):
            r = riga.strip().rstrip(',')
            if not r or re.match(r'(PRIMARY|UNIQUE|KEY|INDEX|CONSTRAINT|FOREIGN|FULLTEXT)', r, re.I):
                continue
            m = re.match(r'`?(\w+)`?\s', r)
            if m:
                cols.append(m.group(1))
        out[n] = cols
    return out


def passo(tabella, condizione_sql, istruzione):
    """Un'istruzione che si esegue solo se la condizione e' vera: cosi' il file si rilancia senza danni
    (MySQL non ha ADD COLUMN IF NOT EXISTS). Le virgolette dell'istruzione si raddoppiano."""
    ist = istruzione.replace("'", "''")
    return (f"SET @ok := ({condizione_sql});\n"
            f"SET @sql := IF(@ok = 0, '{ist}', 'SELECT 1');\n"
            f"PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;\n")


def manca_colonna(t, c):
    return (f"SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() "
            f"AND TABLE_NAME = '{t}' AND COLUMN_NAME = '{c}'")


def manca_indice(t, nome):
    return (f"SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() "
            f"AND TABLE_NAME = '{t}' AND INDEX_NAME = '{nome}'")


def espr_chiave(r):
    """La chiave di «fractional-indexing» per la riga numero r (1, 2, 3...), come SQL.
    Stessa sequenza della libreria: a0..az (62 chiavi), poi b00..bzz, poi c000...
    Oltre ~242.000 righe nella stessa lista torna NULL: l'app in quel caso riordina a runtime."""
    a = f"'{ALFABETO}'"
    n = f"({r} - 1)"
    m1 = f"({n} - 62)"
    m2 = f"({n} - 3906)"
    return (f"CASE "
            f"WHEN {n} < 62 THEN CONCAT('a', SUBSTRING({a}, {n} + 1, 1)) "
            f"WHEN {n} < 3906 THEN CONCAT('b', SUBSTRING({a}, FLOOR({m1} / 62) + 1, 1), SUBSTRING({a}, MOD({m1}, 62) + 1, 1)) "
            f"WHEN {n} < 242234 THEN CONCAT('c', SUBSTRING({a}, FLOOR({m2} / 3844) + 1, 1), "
            f"SUBSTRING({a}, MOD(FLOOR({m2} / 62), 62) + 1, 1), SUBSTRING({a}, MOD({m2}, 62) + 1, 1)) "
            f"ELSE NULL END")


def sql_tabella(t, tipo, ordine, colonne, per_schema=False):
    """Le istruzioni per una tabella. per_schema=True: versione secca (senza controlli) per schema.sql."""
    blocchi = []
    ha = lambda c: c in colonne

    def agg(nome_col, definizione, dopo=None):
        ist = f"ALTER TABLE `{t}` ADD COLUMN `{nome_col}` {definizione}"
        if per_schema:
            return ist + ";\n"
        return passo(t, manca_colonna(t, nome_col), ist)

    def agg_indice(nome, definizione):
        ist = f"ALTER TABLE `{t}` ADD {definizione}"
        if per_schema:
            return ist + ";\n"
        return passo(t, manca_indice(t, nome), ist)

    if tipo in TIPI_CON_ID and not ha('id'):
        # una colonna auto-incrementale deve avere un indice: nello stesso ALTER. Le righe esistenti ricevono 1, 2, 3...
        ist = f"ALTER TABLE `{t}` ADD COLUMN `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Solo per scorrere la tabella a pagine (l''identita'' e'' uuid)', ADD UNIQUE KEY `uq_{t}_id` (`id`)"
        blocchi.append(ist + ";\n" if per_schema else passo(t, manca_colonna(t, 'id'), ist))
    if tipo in TIPI_CON_UUID and not ha('uuid'):
        # 3 passi (colonna vuota -> riempita riga per riga -> obbligatoria con valore automatico):
        # un ADD COLUMN ... DEFAULT (UUID()) in un colpo solo puo' dare lo stesso valore a tutte le righe esistenti.
        blocchi.append(agg('uuid', "CHAR(36) NULL COMMENT 'Identita'' della riga per l''app (UUID); l''id numerico resta quello del sito'"))
        blocchi.append(f"UPDATE `{t}` SET `uuid` = UUID() WHERE `uuid` IS NULL;\n")
        blocchi.append(f"ALTER TABLE `{t}` MODIFY COLUMN `uuid` CHAR(36) NOT NULL DEFAULT (UUID());\n")
        blocchi.append(agg_indice(f'uq_{t}_uuid', f"UNIQUE KEY `uq_{t}_uuid` (`uuid`)"))
    if tipo in TIPI_CON_UPDATED_AT and not ha('updated_at'):
        blocchi.append(agg('updated_at', "DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"))
    if ordine and not ha('sort_key'):
        blocchi.append(agg('sort_key', "VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL "
                                       "COMMENT 'Posizione nella lista: stringa di fractional-indexing (si ordina come testo binario)'"))
        col, parti = ordine
        part = ', '.join(f'`{p}`' for p in parti)
        blocchi.append(
            f"UPDATE `{t}` x JOIN (SELECT id AS rid, ROW_NUMBER() OVER (PARTITION BY {part} ORDER BY `{col}`, id) AS r FROM `{t}`) k "
            f"ON k.rid = x.id SET x.`sort_key` = {espr_chiave('k.r')} WHERE x.`sort_key` IS NULL;\n"
            if t not in ('fc_study_subcards', 'fc_vocab_subcards') else
            f"UPDATE `{t}` x JOIN (SELECT id_child AS rid, ROW_NUMBER() OVER (PARTITION BY {part} ORDER BY `{col}`, id_child) AS r FROM `{t}`) k "
            f"ON k.rid = x.id_child SET x.`sort_key` = {espr_chiave('k.r')} WHERE x.`sort_key` IS NULL;\n")
    return blocchi


INFRA = """-- =====================================================================
-- App, passo 12 — sincronizzazione, parte 1: le tabelle del registro dei cambiamenti
--
-- sync_changes    il registro: ogni scrittura (del sito O dell'app) di una tabella
--                 sincronizzata scrive qui una riga. Il «cursore» di un telefono e'
--                 l'ultimo `seq` che ha visto. Una cancellazione e' una riga con
--                 op='delete': il registro STESSO e' la lapide, quindi le righe vere
--                 si cancellano davvero come oggi (vedi docs/ARCHITETTURA_APP.md §3.2).
-- sync_mutations  le modifiche gia' applicate, per non applicarle due volte se la
--                 risposta si perde e il telefono riprova (idempotenza). Pulita a 30 giorni.
-- sync_conflicts  l'altra versione di un testo lungo (diario, note) quando due
--                 dispositivi lo hanno cambiato insieme: non si perde mai una parola.
-- sync_meta       il «pavimento» di pulizia: un telefono col cursore sotto il pavimento
--                 deve riscaricare tutto (resync_required).
--
-- Si puo' rilanciare senza danni (CREATE TABLE IF NOT EXISTS). Il sito non le usa
-- ancora: eseguirla prima del codice non cambia nulla di visibile.
-- Da eseguire in phpMyAdmin (scheda SQL).
-- =====================================================================

CREATE TABLE IF NOT EXISTS `sync_changes` (
  `seq` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `tbl` VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'Nome della tabella sincronizzata',
  `row_uuid` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `op` ENUM('upsert','delete') CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `changed_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `device_id` INT NULL DEFAULT NULL COMMENT 'api_sessions.id di chi ha scritto (NULL = il sito): quel telefono non riscarica il proprio cambio',
  PRIMARY KEY (`seq`),
  KEY `idx_sync_utente_seq` (`user_id`, `seq`),
  KEY `idx_sync_riga` (`user_id`, `tbl`, `row_uuid`, `seq`),
  KEY `idx_sync_data` (`changed_at`),
  CONSTRAINT `fk_sync_changes_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sync_mutations` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `mutation_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `result` VARCHAR(255) NOT NULL COMMENT 'Esito salvato: applied | conflict_resolved | rejected:<codice>',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sync_mutazione` (`user_id`, `mutation_id`),
  KEY `idx_sync_mut_data` (`created_at`),
  CONSTRAINT `fk_sync_mutations_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sync_conflicts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `tbl` VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `row_uuid` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `field` VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `other_value` MEDIUMTEXT NOT NULL COMMENT 'La versione che NON e'' rimasta come testo principale',
  `device_id` INT NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `resolved_at` DATETIME NULL DEFAULT NULL COMMENT 'L''utente l''ha guardata (o l''ha tenuta)',
  PRIMARY KEY (`id`),
  KEY `idx_sync_conf_utente` (`user_id`, `resolved_at`),
  CONSTRAINT `fk_sync_conflicts_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sync_meta` (
  `k` VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `v` BIGINT NOT NULL DEFAULT 0,
  PRIMARY KEY (`k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- il pavimento parte da 0: nessun telefono e' ancora fuori sincrono
INSERT IGNORE INTO `sync_meta` (`k`, `v`) VALUES ('purged_up_to_seq', 0);
"""


def intestazione(num, area, nota):
    return (f"-- =====================================================================\n"
            f"-- App, passo 12 — sincronizzazione, parte {int(num)}: {nota}\n"
            f"--\n"
            f"-- Aggiunge alle tabelle di quest'area (vedi docs/ARCHITETTURA_APP.md §3.1):\n"
            f"--   uuid       l'identita' della riga per l'app (le righe esistenti ne ricevono uno)\n"
            f"--   updated_at dove mancava (si aggiorna da solo a ogni UPDATE: il codice esistente non cambia)\n"
            f"--   sort_key   la posizione nelle liste ordinabili (fractional-indexing)\n"
            f"-- Il sito funziona identico prima e dopo: nessuna query esistente nomina le colonne nuove.\n"
            f"--\n"
            f"-- FILE GENERATO da tools/genera_migrazioni_sync.py: non si modifica a mano.\n"
            f"-- Si puo' rilanciare senza danni. Da eseguire in phpMyAdmin (scheda SQL), DOPO\n"
            f"-- 2026-11-01_sync_01_infra.sql. Richiede MySQL >= 8.0.13 (o MariaDB >= 10.2.1)\n"
            f"-- per `DEFAULT (UUID())` e le funzioni finestra: controlla con SELECT VERSION();\n"
            f"-- =====================================================================\n\n")


def main():
    schema = leggi_schema()
    mancano = [t for t in TABELLE if t not in schema]
    if mancano:
        sys.exit('Tabelle non in schema.sql: ' + ', '.join(mancano))

    if '--schema' in sys.argv:
        print('\n-- ===== SINCRONIZZAZIONE APP (generato da tools/genera_migrazioni_sync.py; stesse modifiche delle migrazioni 2026-11-01_sync_*) =====')
        print(INFRA.split('-- Da eseguire in phpMyAdmin (scheda SQL).\n-- =====================================================================\n', 1)[1].strip())
        for t, (tipo, area, ordine) in TABELLE.items():
            for b in sql_tabella(t, tipo, ordine, schema[t], per_schema=True):
                if b.startswith('UPDATE'):
                    continue   # le righe riempite servono solo a un database che ha gia' dati; schema.sql parte vuoto
                print(b, end='')
        return

    cartella = os.path.join(RADICE, 'sql', 'migrations')
    open(os.path.join(cartella, f'{DATA}_sync_01_infra.sql'), 'w', encoding='utf-8').write(INFRA)
    for chiave, (num, nome, nota) in AREE.items():
        testo = intestazione(num, nome, nota)
        for t, (tipo, area, ordine) in TABELLE.items():
            if area != chiave:
                continue
            blocchi = sql_tabella(t, tipo, ordine, schema[t])
            if not blocchi:
                testo += f"-- {t} ({tipo}): niente da cambiare\n\n"
                continue
            testo += f"-- ---------- {t} ({tipo})\n" + ''.join(blocchi) + '\n'
        open(os.path.join(cartella, f'{DATA}_sync_{num}_{nome}.sql'), 'w', encoding='utf-8').write(testo)
    print('Scritti i file in', cartella)


if __name__ == '__main__':
    main()
