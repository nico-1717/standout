-- =====================================================================
-- «Esci dagli altri dispositivi» anche per le sessioni del browser
--
-- users.session_version : un contatore. Ogni accesso (login o «ricordami») scrive
-- nella sessione PHP il valore che ha in quel momento; quando l'utente cambia la
-- password, o esce da TUTTI i dispositivi, il contatore sale di uno e le sessioni
-- aperte prima non coincidono piu': alla prossima richiesta escono.
--
-- PERCHE' UN CONTATORE E NON UNA DATA: i due orologi (PHP e database) possono
-- non andare d'accordo di qualche secondo; un numero non dipende da nessun orologio.
-- Le sessioni nate prima di questa migrazione non hanno il numero e valgono 0:
-- finche' l'utente non cambia password, restano valide come prima.
--
-- Si puo' rilanciare senza danni. Da eseguire in phpMyAdmin (scheda SQL)
-- PRIMA di pubblicare il codice: lib/auth_core.php legge la colonna a ogni richiesta
-- e senza di lei nessuno potrebbe entrare.
-- =====================================================================

SET @esiste := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'session_version');

SET @sql := IF(@esiste = 0,
    'ALTER TABLE `users` ADD COLUMN `session_version` INT NOT NULL DEFAULT 0 COMMENT ''Sale di uno quando cambia la password o esce da tutti i dispositivi (invalida le sessioni del browser aperte prima)'' AFTER `terms_version`',
    'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
