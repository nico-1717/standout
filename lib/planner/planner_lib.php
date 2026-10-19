<?php
/* =====================================================================
   STANDOUT — PLANNER: LA LOGICA
   Tutte le funzioni pl_* che usano la pagina (public/planner/planner.php)
   e l'API (public/planner/planner_api.php). Prima stavano in testa alla
   pagina, 2.000 righe prima del primo tag HTML; ora sono divise per
   argomento, e ogni file si apre e si legge da solo:

     pl_base.php        date, impostazioni, frasi, numero della settimana
     pl_categorie.php   categorie, calendari, routine, preferiti
     pl_eventi.php      eventi, ricorrenze, intervalli
     pl_giornata.php    pastiglie, priorita', liste, abitudini, diario
     pl_luoghi.php      ricerca dei luoghi e rubrica
     pl_azioni.php      supporto alle scritture (ricorrenze, Google, todo)

   Le funzioni usano sec_* (security_core.php): va caricato prima.
   ===================================================================== */
if (defined('STANDOUT_PLANNER_LIB')) return;
define('STANDOUT_PLANNER_LIB', true);

date_default_timezone_set('Europe/Rome');

define('PL_PHRASES_FILE', __DIR__ . '/../data/planner_phrases.json');
define('PL_MAX_OCCURRENCES', 400);

require_once __DIR__ . '/pl_base.php';
require_once __DIR__ . '/pl_categorie.php';
require_once __DIR__ . '/pl_eventi.php';
require_once __DIR__ . '/pl_giornata.php';
require_once __DIR__ . '/pl_luoghi.php';
require_once __DIR__ . '/pl_azioni.php';
