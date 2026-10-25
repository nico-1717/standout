-- =====================================================================
-- STANDOUT — migrazione del 25/10/2026 (la pagina To do non ha più «to study»)
-- Da eseguire una volta. Le attività che erano nell'elenco «to study» non
-- spariscono con la linguetta: diventano normali attività «to do», così
-- restano visibili e si possono completare o eliminare. Lo studio vero e
-- proprio vive nel planner (blocchi di studio) e nei progetti, non qui.
-- L'enum della colonna resta com'è: toglierlo non porta niente e rende la
-- migrazione irreversibile.
-- =====================================================================
UPDATE todos SET list_type = 'todo' WHERE list_type = 'study';
