<?php
/* =====================================================================
   STANDOUT — il manifest della sincronizzazione (una sola fonte di verita')

   Elenca le tabelle che l'app sincronizza e, per ognuna, come stanno sul filo:
   quali campi viaggiano, quali sono chiavi esterne (sul filo SEMPRE come uuid,
   mai come id numerico del server), come si ordina, a chi appartiene la riga.
   Lo leggono: il registro dei cambiamenti (lib/sync.php), l'invio e la ricezione
   (lib/sync_api.php) e, in copia, l'app (npm run sync:manifest). Aggiungere una
   tabella = aggiungerla qui + la migrazione SQL (tools/genera_migrazioni_sync.py).

   Campi di una tabella (chiave = nome sul filo):
     'col'   colonna del database (se diverso dal nome sul filo)
     'tipo'  s=testo, i=intero, b=booleano, d=data, o=ora, e=enum, fk=chiave esterna, k=sort_key
     'solo_lettura' => true   il telefono lo riceve ma non lo scrive (lo decide il server)
     'solo_creazione' => true si scrive solo quando la riga nasce
     'sql'   espressione calcolata: finisce nella SELECT cosi' com'e', alias «t» = la tabella (si legge soltanto; scriverla spetta all'applier della tabella, che la traduce: es. max_card_uuid, target_uuid)
     'sistema' => true  (su un fk) la riga puntata puo' essere di sistema (user_id 0): vale per tutti
   Di una tabella:
     'sistema' => true          le righe con user_id 0 sono di tutti: arrivano a ogni utente, ma sola lettura
     'chiave_naturale' => [...]  la riga e' unica per queste colonne (es. un'abitudine + un giorno): la sua
                                 identita' sul filo e' un UUIDv5 calcolato da quella chiave (decisione D4),
                                 cosi' sito e telefono, offline, creano LA STESSA riga e non due.
   Fase 1: to do e to buy. Fase 2: abitudini e pastiglie. Le altre tabelle si aggiungono area per area.
   ===================================================================== */

const SYNC_MANIFESTO = [
    /* l'ordine e' quello di dipendenza: il padre arriva prima del figlio */
    'todo_buy_categories' => [
        'ordine'       => 10,
        'proprietario' => 'user_id',          // colonna con l'utente (per le tabelle figlie: vedi 'catena' nelle fasi dopo)
        'gruppo'       => ['user_id'],        // le voci che si ordinano fra loro (sort_key)
        'campi' => [
            'name'       => ['tipo' => 's'],
            'color'      => ['tipo' => 's'],
            'sort_key'   => ['tipo' => 'k'],
            'created_at' => ['tipo' => 's', 'solo_lettura' => true],
            'updated_at' => ['tipo' => 's', 'solo_lettura' => true],
        ],
    ],
    'todos' => [
        'ordine'       => 20,
        'proprietario' => 'user_id',
        'gruppo'       => ['user_id', 'list_type'],
        'filtro'       => "list_type IN ('todo','buy')",   // la lista «study» e' vecchia: vive solo nei Progetti
        'campi' => [
            'list_type'           => ['tipo' => 'e', 'solo_creazione' => true],
            'parent_uuid'         => ['tipo' => 'fk', 'col' => 'parent_id', 'tabella' => 'todos', 'solo_creazione' => true],
            'buy_category_uuid'   => ['tipo' => 'fk', 'col' => 'buy_category_id', 'tabella' => 'todo_buy_categories'],
            'project_uuid'        => ['tipo' => 'fk', 'col' => 'project_id', 'tabella' => 'projects'],   // solo i to do: l'attivita' collegata a un progetto
            'buy_priority'        => ['tipo' => 'i'],
            'buy_price_cents'     => ['tipo' => 'i'],
            'buy_url'             => ['tipo' => 's'],
            'buy_image'           => ['tipo' => 's', 'solo_lettura' => true],   // il file viaggia nella coda dei media, non qui
            'title'               => ['tipo' => 's'],
            'is_done'             => ['tipo' => 'b'],
            'done_at'             => ['tipo' => 's', 'solo_lettura' => true],
            'due_date'            => ['tipo' => 'd'],
            'due_time'            => ['tipo' => 'o'],
            'duration_minutes'    => ['tipo' => 'i'],
            'sort_key'            => ['tipo' => 'k'],
            'created_at'          => ['tipo' => 's', 'solo_lettura' => true],
            'updated_at'          => ['tipo' => 's', 'solo_lettura' => true],
        ],
    ],

    /* ---- abitudini ---- */
    'habit_categories' => [
        'ordine'       => 30,
        'proprietario' => 'user_id',
        'sistema'      => true,                 // le categorie di partenza (user_id 0) sono di tutti
        'gruppo'       => ['user_id'],
        'campi' => [
            // niente sort_key: il sito non riordina le categorie, ordina per sort_order (sistema + proprie insieme)
            'slug'       => ['tipo' => 's', 'solo_lettura' => true],   // chiave stabile: l'app traduce il nome delle categorie di sistema
            'name'       => ['tipo' => 's'],
            'icon'       => ['tipo' => 's'],
            'color'      => ['tipo' => 's'],
            'sort_order' => ['tipo' => 'i', 'solo_lettura' => true],
            'is_system'  => ['tipo' => 'b', 'sql' => '(t.user_id = 0)', 'solo_lettura' => true],
            'created_at' => ['tipo' => 's', 'solo_lettura' => true],
            'updated_at' => ['tipo' => 's', 'solo_lettura' => true],
        ],
    ],
    'habits' => [
        'ordine'       => 40,
        'proprietario' => 'user_id',
        'gruppo'       => ['user_id'],
        'campi' => [
            'category_uuid'   => ['tipo' => 'fk', 'col' => 'category_id', 'tabella' => 'habit_categories', 'sistema' => true],
            'preset_key'      => ['tipo' => 's'],
            'name'            => ['tipo' => 's'],
            'icon'            => ['tipo' => 's'],
            'color'           => ['tipo' => 's'],
            'motivation'      => ['tipo' => 's'],
            'kind'            => ['tipo' => 'e', 'col' => 'habit_kind'],
            'goal_type'       => ['tipo' => 'e'],
            'target_amount'   => ['tipo' => 'n'],
            'step_amount'     => ['tipo' => 'n'],
            'unit'            => ['tipo' => 's'],
            'frequency_type'  => ['tipo' => 'e'],
            'weekdays'        => ['tipo' => 's'],
            'times_per_week'  => ['tipo' => 'i'],
            'times_per_month' => ['tipo' => 'i'],
            'interval_days'   => ['tipo' => 'i'],
            'day_part'        => ['tipo' => 'e'],
            'duration_type'   => ['tipo' => 'e'],
            'duration_days'   => ['tipo' => 'i'],
            'start_date'      => ['tipo' => 'd'],
            'end_date'        => ['tipo' => 'd'],
            'planner_minutes' => ['tipo' => 'i'],
            'is_archived'     => ['tipo' => 'b'],
            'archived_at'     => ['tipo' => 's', 'solo_lettura' => true],
            'sort_key'        => ['tipo' => 'k'],
            'created_at'      => ['tipo' => 's', 'solo_lettura' => true],
            'updated_at'      => ['tipo' => 's', 'solo_lettura' => true],
        ],
    ],
    // Un check-in = una riga per (abitudine, giorno). Sul filo viaggia lo STATO del giorno (quantita' e stato),
    // non i singoli «tap»: due telefoni che toccano lo stesso giorno non si sommano, vince l'ultimo che scrive.
    'habit_logs' => [
        'ordine'          => 50,
        'proprietario'    => 'user_id',
        'gruppo'          => [],
        'chiave_naturale' => ['habit_id', 'log_date'],
        'campi' => [
            'habit_uuid'      => ['tipo' => 'fk', 'col' => 'habit_id', 'tabella' => 'habits', 'solo_creazione' => true],
            'log_date'        => ['tipo' => 'd', 'solo_creazione' => true],
            'status'          => ['tipo' => 'e'],
            'amount'          => ['tipo' => 'n'],
            'completions'     => ['tipo' => 'i', 'solo_lettura' => true],   // lo ricalcola il server
            'target_amount'   => ['tipo' => 'n', 'solo_lettura' => true],   // la fotografia del giorno la scrive il server
            'note'            => ['tipo' => 's'],
            'mood'            => ['tipo' => 'i'],
            'first_logged_at' => ['tipo' => 's', 'solo_lettura' => true],
            'updated_at'      => ['tipo' => 's', 'solo_lettura' => true],
        ],
    ],

    /* ---- pastiglie ---- */
    // La terapia: il ritmo (orari, giorni) viaggia come testo, esattamente come sta sul sito. schedule_from lo decide il server
    // (td_pill_scrivi): il telefono lo ricalcola per mostrarlo subito, poi vince il valore del server.
    'pill_plans' => [
        'ordine'       => 60,
        'proprietario' => 'user_id',
        'gruppo'       => ['user_id'],
        'campi' => [
            'name'          => ['tipo' => 's'],
            'dose'          => ['tipo' => 's'],
            'notes'         => ['tipo' => 's'],
            'color'         => ['tipo' => 's'],
            'freq_type'     => ['tipo' => 'e'],
            'weekdays'      => ['tipo' => 's'],
            'month_days'    => ['tipo' => 's'],
            'interval_days' => ['tipo' => 'i'],
            'times'         => ['tipo' => 's'],
            'start_date'    => ['tipo' => 'd'],
            'end_date'      => ['tipo' => 'd'],
            'is_active'     => ['tipo' => 'b'],
            'schedule_from' => ['tipo' => 'd', 'solo_lettura' => true],
            'created_at'    => ['tipo' => 's', 'solo_lettura' => true],
            'updated_at'    => ['tipo' => 's', 'solo_lettura' => true],
        ],
    ],
    // Una presa = una riga per (terapia, giorno, orario, n.). Le prese NASCONO da una regola (td_pills_ensure_giorni): due
    // telefoni offline ne creano di identiche, quindi l'identita' e' un UUIDv5 della chiave (decisione D4). Sul filo
    // viaggia solo lo stato (presa / saltata / da prendere) e l'ora vera della spunta.
    'pill_doses' => [
        'ordine'          => 70,
        'proprietario'    => 'user_id',
        'gruppo'          => [],
        'chiave_naturale' => ['plan_id', 'due_date', 'due_time', 'seq'],
        'campi' => [
            'plan_uuid'  => ['tipo' => 'fk', 'col' => 'plan_id', 'tabella' => 'pill_plans', 'solo_creazione' => true],
            'due_date'   => ['tipo' => 'd', 'solo_creazione' => true],
            'due_time'   => ['tipo' => 'o', 'solo_creazione' => true],
            'seq'        => ['tipo' => 'i', 'solo_creazione' => true],
            'status'     => ['tipo' => 'e'],
            'taken_at'   => ['tipo' => 's'],
            'updated_at' => ['tipo' => 's', 'solo_lettura' => true],
        ],
    ],

    /* ---- diario ---- */
    // Il giorno del diario: una riga per (utente, giorno). Nata dal sito o da un telefono offline e' LA STESSA riga:
    // identita' = UUIDv5 di (utente, giorno). `note_rev` e' l'impronta del testo sul server: il telefono la rimanda
    // con la sua modifica (campo «solo_lettura»: non si scrive mai, serve solo a capire se il testo e' cambiato
    // nel frattempo; se si', il server tiene TUTTE E DUE le versioni, vedi sync_diario.php).
    'habit_daily_journal' => [
        'ordine'          => 80,
        'proprietario'    => 'user_id',
        'gruppo'          => [],
        'chiave_naturale' => ['user_id', 'entry_date'],
        'campi' => [
            'entry_date' => ['tipo' => 'd', 'solo_creazione' => true],
            'mood'       => ['tipo' => 'i'],
            'energy'     => ['tipo' => 'i'],
            'note'       => ['tipo' => 's'],
            'note_rev'   => ['tipo' => 's', 'solo_lettura' => true, 'sql' => "SHA1(COALESCE(t.note, ''))"],
            'created_at' => ['tipo' => 's', 'solo_lettura' => true],
            'updated_at' => ['tipo' => 's', 'solo_lettura' => true],
        ],
    ],
    // Le «cose belle» e «cose imparate» di un giorno. L'ordine e' quello del sito (sort_order, solo lettura): le voci
    // nascono in fondo all'elenco e non si riordinano, quindi non serve una sort_key.
    'diary_list_items' => [
        'ordine'       => 90,
        'proprietario' => 'user_id',
        'gruppo'       => [],
        'campi' => [
            'entry_date' => ['tipo' => 'd', 'solo_creazione' => true],
            'list_type'  => ['tipo' => 'e', 'solo_creazione' => true],
            'content'    => ['tipo' => 's'],
            'sort_order' => ['tipo' => 'i', 'solo_lettura' => true],
            'created_at' => ['tipo' => 's', 'solo_lettura' => true],
            'updated_at' => ['tipo' => 's', 'solo_lettura' => true],
        ],
    ],

    /* ---- planner ---- */
    // I calendari. Il «principale» (is_default) lo crea il server e non si cancella; i campi di Google non viaggiano.
    'planner_calendars' => [
        'ordine'       => 100,
        'proprietario' => 'user_id',
        'gruppo'       => ['user_id'],
        'campi' => [
            'name'       => ['tipo' => 's'],
            'color'      => ['tipo' => 's'],
            'is_visible' => ['tipo' => 'b'],
            'is_default' => ['tipo' => 'b', 'solo_lettura' => true],
            'role'       => ['tipo' => 's', 'solo_lettura' => true],      // study = ci vivono i blocchi del to study
            'keywords'   => ['tipo' => 's'],
            'def_repeat' => ['tipo' => 'e'],
            'def_notify' => ['tipo' => 'e'],
            'sort_key'   => ['tipo' => 'k'],
            'created_at' => ['tipo' => 's', 'solo_lettura' => true],
            'updated_at' => ['tipo' => 's', 'solo_lettura' => true],
        ],
    ],
    'planner_categories' => [
        'ordine'       => 110,
        'proprietario' => 'user_id',
        'gruppo'       => ['user_id'],
        'campi' => [
            'name'       => ['tipo' => 's'],
            'color'      => ['tipo' => 's'],
            'is_hidden'  => ['tipo' => 'b'],
            'role'       => ['tipo' => 's', 'solo_lettura' => true],
            'sort_key'   => ['tipo' => 'k'],
            'created_at' => ['tipo' => 's', 'solo_lettura' => true],
            'updated_at' => ['tipo' => 's', 'solo_lettura' => true],
        ],
    ],
    // Le parole che agganciano da sole una categoria al titolo di un evento. Una parola e' di UNA categoria sola.
    'planner_category_keywords' => [
        'ordine'       => 120,
        'proprietario' => 'user_id',
        'gruppo'       => [],
        'campi' => [
            'category_uuid' => ['tipo' => 'fk', 'col' => 'category_id', 'tabella' => 'planner_categories', 'solo_creazione' => true],
            'keyword'       => ['tipo' => 's', 'solo_creazione' => true],
            'created_at'    => ['tipo' => 's', 'solo_lettura' => true],
            'updated_at'    => ['tipo' => 's', 'solo_lettura' => true],
        ],
    ],
    // La settimana del planner: una riga per (utente, lunedi). Il numero della settimana si scrive a mano (number_edited
    // lo mette il server quando arriva un week_number); da lì in poi si ricalcola.
    'planner_weeks' => [
        'ordine'          => 108,
        'proprietario'    => 'user_id',
        'gruppo'          => [],
        'chiave_naturale' => ['user_id', 'week_start'],
        'campi' => [
            'week_start'    => ['tipo' => 'd', 'solo_creazione' => true],
            'week_number'   => ['tipo' => 'i'],
            'number_edited' => ['tipo' => 'b', 'solo_lettura' => true],
            'is_completed'  => ['tipo' => 'b'],
            'learned_note'  => ['tipo' => 's'],
            'created_at'    => ['tipo' => 's', 'solo_lettura' => true],
            'updated_at'    => ['tipo' => 's', 'solo_lettura' => true],
        ],
    ],
    // Le liste della settimana: una per tipo (todo, buy, study) in ogni settimana.
    'planner_week_lists' => [
        'ordine'          => 109,
        'proprietario'    => 'user_id',
        'gruppo'          => [],
        'chiave_naturale' => ['user_id', 'week_start', 'list_type'],
        'campi' => [
            'week_start' => ['tipo' => 'd', 'solo_creazione' => true],
            'list_type'  => ['tipo' => 'e', 'solo_creazione' => true],
            'created_at' => ['tipo' => 's', 'solo_lettura' => true],
        ],
    ],
    'planner_week_list_items' => [
        'ordine'       => 111,
        'proprietario' => 'user_id',
        'gruppo'       => ['list_id'],
        'campi' => [
            'list_uuid'  => ['tipo' => 'fk', 'col' => 'list_id', 'tabella' => 'planner_week_lists', 'solo_creazione' => true],
            'content'    => ['tipo' => 's'],
            'is_done'    => ['tipo' => 'b'],
            'sort_key'   => ['tipo' => 'k'],
            'created_at' => ['tipo' => 's', 'solo_lettura' => true],
        ],
    ],
    // I progetti: un albero. Il colore si eredita dal padre finche' non e' scelto a mano (color_edited).
    // I campi study_* li scrive il piano di studio del sito: il telefono li legge e basta (tranne la stima dei pomodori).
    'projects' => [
        'ordine'        => 140,
        'proprietario'  => 'user_id',
        'gruppo'        => ['user_id', 'parent_id'],
        'gruppo_padre'  => 'parent_id',
        'colonna_ordine' => 'custom_order',
        'campi' => [
            'parent_uuid'            => ['tipo' => 'fk', 'col' => 'parent_id', 'tabella' => 'projects'],
            'name'                   => ['tipo' => 's'],
            'notes'                  => ['tipo' => 's'],
            'status'                 => ['tipo' => 'e'],
            'color'                  => ['tipo' => 's'],
            'color_edited'           => ['tipo' => 'b'],
            'target_date'            => ['tipo' => 'd'],
            'due_date'               => ['tipo' => 'd'],
            'todo_enabled'           => ['tipo' => 'b'],
            'is_favorite'            => ['tipo' => 'b'],
            'sort_key'               => ['tipo' => 'k'],
            'study_event_uuid'       => ['tipo' => 'fk', 'col' => 'study_event_id', 'tabella' => 'planner_events', 'solo_lettura' => true],
            'study_start'            => ['tipo' => 'd', 'solo_lettura' => true],
            'study_end'              => ['tipo' => 'd', 'solo_lettura' => true],
            'study_pomodori_stimati' => ['tipo' => 'i'],
            'study_ripasso_di_uuid'  => ['tipo' => 'fk', 'col' => 'study_ripasso_di', 'tabella' => 'projects', 'solo_lettura' => true],
            'study_ripasso_n'        => ['tipo' => 'i', 'solo_lettura' => true],
            'created_at'             => ['tipo' => 's', 'solo_lettura' => true],
            'updated_at'             => ['tipo' => 's', 'solo_lettura' => true],
        ],
    ],
    // Le sessioni del Pomodoro (e del cronometro). Date e ore come le scrive il sito: 'AAAA-MM-GG HH:MM:SS'.
    'pomodoro_sessions' => [
        'ordine'       => 150,
        'proprietario' => 'user_id',
        'gruppo'       => [],
        'campi' => [
            'mode'                 => ['tipo' => 'i', 'solo_creazione' => true],
            'start_time'           => ['tipo' => 's'],
            'end_time'             => ['tipo' => 's'],
            'status'               => ['tipo' => 's'],
            'resume_time'          => ['tipo' => 's'],
            'duration_seconds'     => ['tipo' => 'i'],
            'planned_duration_min' => ['tipo' => 'i'],
            'project_uuid'         => ['tipo' => 'fk', 'col' => 'project_id', 'tabella' => 'projects'],
            'is_manual'            => ['tipo' => 'i'],
            'updated_at'           => ['tipo' => 's', 'solo_lettura' => true],
        ],
    ],
    /* ---- voti ---- */
    // Prima i gruppi, poi gli esami che puntano a un gruppo. Le impostazioni del corso viaggiano a parte (GET/PATCH university/settings).
    'university_exam_groups' => [
        'ordine'       => 170,
        'proprietario' => 'user_id',
        'gruppo'       => ['user_id'],
        'campi' => [
            'name'                     => ['tipo' => 's'],
            'group_type'               => ['tipo' => 'e'],
            'cfu'                      => ['tipo' => 'n'],
            'overall_grade'            => ['tipo' => 'i'],
            'overall_honors'           => ['tipo' => 'b'],
            'overall_predicted_grade'  => ['tipo' => 'i'],
            'overall_predicted_honors' => ['tipo' => 'b'],
            'sort_key'                 => ['tipo' => 'k'],
            'created_at'               => ['tipo' => 's', 'solo_lettura' => true],
            'updated_at'               => ['tipo' => 's', 'solo_lettura' => true],
        ],
    ],
    'university_exams' => [
        'ordine'       => 171,
        'proprietario' => 'user_id',
        'gruppo'       => ['user_id'],
        'campi' => [
            'name'                  => ['tipo' => 's'],
            'cfu'                   => ['tipo' => 'n'],
            'status'                => ['tipo' => 'e'],
            'grade'                 => ['tipo' => 'i'],
            'has_honors'            => ['tipo' => 'b'],
            'is_pass_fail'          => ['tipo' => 'b'],
            'counts_for_graduation' => ['tipo' => 'b'],
            'exam_date'             => ['tipo' => 'd'],
            'predicted_grade'       => ['tipo' => 'i'],
            'predicted_honors'      => ['tipo' => 'b'],
            'catalog_code'          => ['tipo' => 's'],
            'ssd'                   => ['tipo' => 's'],
            'study_year'            => ['tipo' => 'i'],
            'national_avg'          => ['tipo' => 'n'],
            'group_uuid'            => ['tipo' => 'fk', 'col' => 'group_id', 'tabella' => 'university_exam_groups'],
            'sort_key'              => ['tipo' => 'k'],
            'created_at'            => ['tipo' => 's', 'solo_lettura' => true],
            'updated_at'            => ['tipo' => 's', 'solo_lettura' => true],
        ],
    ],
    'university_bonuses' => [
        'ordine'       => 172,
        'proprietario' => 'user_id',
        'gruppo'       => ['user_id'],
        'campi' => [
            'name'            => ['tipo' => 's'],
            'bonus_type'      => ['tipo' => 'e'],
            'points'          => ['tipo' => 'n'],
            'threshold_value' => ['tipo' => 'i'],
            'cap_points'      => ['tipo' => 'n'],
            'is_enabled'      => ['tipo' => 'b'],
            'sort_key'        => ['tipo' => 'k'],
            'created_at'      => ['tipo' => 's', 'solo_lettura' => true],
            'updated_at'      => ['tipo' => 's', 'solo_lettura' => true],
        ],
    ],
    // Le durate che l'utente si e' salvato (25, 50, 90...): una riga per (tipo, minuti), identita' UUIDv5.
    'pomodoro_custom_durations' => [
        'ordine'          => 160,
        'proprietario'    => 'user_id',
        'gruppo'          => [],
        'chiave_naturale' => ['user_id', 'type', 'minutes'],
        'campi' => [
            'type'       => ['tipo' => 'e', 'solo_creazione' => true],
            'minutes'    => ['tipo' => 'i', 'solo_creazione' => true],
            'updated_at' => ['tipo' => 's', 'solo_lettura' => true],
        ],
    ],
    // Le priorita' del giorno: fino a 6 righe per giorno. Una riga = (giorno, posto): identita' UUIDv5 (come un check-in).
    'planner_priorities' => [
        'ordine'          => 105,
        'proprietario'    => 'user_id',
        'gruppo'          => [],
        'chiave_naturale' => ['user_id', 'priority_date', 'position'],
        'campi' => [
            'priority_date' => ['tipo' => 'd', 'solo_creazione' => true],
            'position'      => ['tipo' => 'i', 'solo_creazione' => true],
            'content'       => ['tipo' => 's'],
            'is_done'       => ['tipo' => 'b'],
            'created_at'    => ['tipo' => 's', 'solo_lettura' => true],
            'updated_at'    => ['tipo' => 's', 'solo_lettura' => true],
        ],
    ],
    // Le routine: una serie di passaggi con la durata, da inserire in un giorno.
    'planner_routines' => [
        'ordine'       => 106,
        'proprietario' => 'user_id',
        'gruppo'       => ['user_id'],
        'campi' => [
            'name'       => ['tipo' => 's'],
            'color'      => ['tipo' => 's'],
            'sort_key'   => ['tipo' => 'k'],
            'created_at' => ['tipo' => 's', 'solo_lettura' => true],
        ],
    ],
    'planner_routine_items' => [
        'ordine'       => 107,
        'proprietario' => 'user_id',
        'gruppo'       => ['routine_id'],
        'campi' => [
            'routine_uuid'     => ['tipo' => 'fk', 'col' => 'routine_id', 'tabella' => 'planner_routines', 'solo_creazione' => true],
            'name'             => ['tipo' => 's'],
            'duration_minutes' => ['tipo' => 'i'],
            'sort_key'         => ['tipo' => 'k'],
        ],
    ],
    // Gli eventi: la riga com'e' sul sito. Una serie e' un capostipite con `rrule`; ogni eccezione («solo questa volta»
    // o «questa volta no») e' una riga con series_uuid + original_date (e is_cancelled se la volta e' tolta). Le
    // occorrenze NON sono righe: le calcola chi legge (il telefono ha lo stesso motore del sito). `rrule` lo scrive
    // il server dai campi repeat_*. I collegamenti a progetti e routine arrivano con le loro tabelle.
    'planner_events' => [
        'ordine'       => 130,
        'proprietario' => 'user_id',
        'gruppo'       => [],
        'campi' => [
            'title'            => ['tipo' => 's'],
            'notes'            => ['tipo' => 's'],
            'location'         => ['tipo' => 's'],
            'place_name'       => ['tipo' => 's'],
            'place_lat'        => ['tipo' => 'n'],
            'place_lon'        => ['tipo' => 'n'],
            'place_ref'        => ['tipo' => 's'],
            'duration_locked'  => ['tipo' => 'b'],
            'event_date'       => ['tipo' => 'd'],
            'end_date'         => ['tipo' => 'd'],
            'start_time'       => ['tipo' => 'o'],
            'end_time'         => ['tipo' => 'o'],
            'is_all_day'       => ['tipo' => 'b'],
            'category_uuid'    => ['tipo' => 'fk', 'col' => 'category_id', 'tabella' => 'planner_categories'],
            'calendar_uuid'    => ['tipo' => 'fk', 'col' => 'calendar_id', 'tabella' => 'planner_calendars'],
            'habit_uuid'       => ['tipo' => 'fk', 'col' => 'habit_id', 'tabella' => 'habits', 'solo_creazione' => true],
            'project_uuid'     => ['tipo' => 'fk', 'col' => 'project_id', 'tabella' => 'projects', 'solo_creazione' => true],
            'importance'       => ['tipo' => 'e'],
            'color'            => ['tipo' => 's'],
            'item_type'        => ['tipo' => 'e'],
            'is_completed'     => ['tipo' => 'b'],
            'completed_at'     => ['tipo' => 's', 'solo_lettura' => true],
            'notify_type'      => ['tipo' => 'e'],
            'notify_minutes'   => ['tipo' => 'i'],
            'repeat_type'      => ['tipo' => 'e'],
            'repeat_interval'  => ['tipo' => 'i'],
            'repeat_unit'      => ['tipo' => 'e'],
            'repeat_until'     => ['tipo' => 'd'],
            'rrule'            => ['tipo' => 's', 'solo_lettura' => true],
            'series_uuid'      => ['tipo' => 'fk', 'col' => 'series_id', 'tabella' => 'planner_events', 'solo_creazione' => true],
            'original_date'    => ['tipo' => 'd', 'solo_creazione' => true],
            'is_cancelled'     => ['tipo' => 'b'],
            'routine_uuid'     => ['tipo' => 'fk', 'col' => 'routine_id', 'tabella' => 'planner_routines', 'solo_creazione' => true],
            'routine_group'    => ['tipo' => 's', 'solo_creazione' => true],   // i pezzi di una routine inserita hanno lo stesso gruppo
            'routine_collapsed'=> ['tipo' => 'b'],
            'sort_order'       => ['tipo' => 'i', 'solo_lettura' => true],
            'created_at'       => ['tipo' => 's', 'solo_lettura' => true],
            'updated_at'       => ['tipo' => 's', 'solo_lettura' => true],
        ],
    ],
    /* ---- flashcard di studio ---- */
    // Il sito cancella un mazzo lasciando in giro le sue carte (non c'e' nessuna chiave esterna): qui chi cancella dall'app
    // toglie tutto il ramo, e il telefono toglie il ramo anche quando e' il sito a cancellare. Le carte non hanno user_id:
    // appartengono all'utente del loro mazzo ('proprietario_sql').
    'fc_study_decks' => [
        'ordine'       => 200,
        'proprietario' => 'user_id',
        'gruppo'       => ['user_id'],
        'campi' => [
            'name'         => ['tipo' => 's'],
            'parent_uuid'  => ['tipo' => 'fk', 'col' => 'parent_id', 'tabella' => 'fc_study_decks'],   // 0 = radice = null
            'color'        => ['tipo' => 's'],
            'color_edited' => ['tipo' => 'b'],
            'archived'     => ['tipo' => 'b'],
            'icon'         => ['tipo' => 's'],
            'notes'        => ['tipo' => 's'],
            'pinned'       => ['tipo' => 'b'],
            'exam_date'    => ['tipo' => 'd'],
            'updated_at'   => ['tipo' => 's', 'solo_lettura' => true],
        ],
    ],
    'fc_study_cards' => [
        'ordine'           => 201,
        'proprietario'     => 'user_id',
        'proprietario_sql' => 'SELECT d.user_id FROM fc_study_decks d WHERE d.id = {t}.category_id',
        'gruppo'           => ['user_id'],
        'campi' => [
            'category_uuid'  => ['tipo' => 'fk', 'col' => 'category_id', 'tabella' => 'fc_study_decks'],
            'question_desc'  => ['tipo' => 's'],
            'answer_desc'    => ['tipo' => 's'],
            'question_image' => ['tipo' => 's', 'solo_lettura' => true],   // i file viaggiano nella coda dei media, non qui
            'answer_image'   => ['tipo' => 's', 'solo_lettura' => true],
            'question_audio' => ['tipo' => 's', 'solo_lettura' => true],
            'answer_audio'   => ['tipo' => 's', 'solo_lettura' => true],
            'is_father'      => ['tipo' => 'b', 'solo_lettura' => true],   // lo decide il server: 1 se ha carte figlie
            'custom_order'   => ['tipo' => 'i', 'solo_lettura' => true],   // il server mette le carte nuove in fondo
            'updated_at'     => ['tipo' => 's', 'solo_lettura' => true],
        ],
    ],
    // La rubrica degli indirizzi (per gli invitati agli eventi): una riga per indirizzo, come sul sito (uq_contact_email).
    'contacts' => [
        'ordine'       => 120,
        'proprietario' => 'user_id',
        'gruppo'       => [],
        'campi' => [
            'name'       => ['tipo' => 's'],
            'email'      => ['tipo' => 's'],
            'created_at' => ['tipo' => 's', 'solo_lettura' => true],
            'updated_at' => ['tipo' => 's', 'solo_lettura' => true],
        ],
    ],
    // I luoghi che ti sei salvato (casa, lavoro, palestra): le parole d'aggancio riempiono da sole il luogo di un evento.
    'planner_places' => [
        'ordine'       => 121,
        'proprietario' => 'user_id',
        'gruppo'       => ['user_id'],
        'campi' => [
            'label'      => ['tipo' => 's'],
            'keywords'   => ['tipo' => 's'],
            'place_name' => ['tipo' => 's'],
            'address'    => ['tipo' => 's'],
            'place_lat'  => ['tipo' => 'n'],
            'place_lon'  => ['tipo' => 'n'],
            'place_ref'  => ['tipo' => 's'],
            'sort_key'   => ['tipo' => 'k'],
            'created_at' => ['tipo' => 's', 'solo_lettura' => true],
            'updated_at' => ['tipo' => 's', 'solo_lettura' => true],
        ],
    ],
    'fc_study_subcards' => [
        'ordine'           => 202,
        'pk'               => 'id_child',
        'proprietario'     => 'user_id',
        'proprietario_sql' => 'SELECT d.user_id FROM fc_study_cards c JOIN fc_study_decks d ON d.id = c.category_id WHERE c.id = {t}.parent_id',
        'gruppo'           => ['user_id'],
        'campi' => [
            'parent_uuid'    => ['tipo' => 'fk', 'col' => 'parent_id', 'tabella' => 'fc_study_cards', 'solo_creazione' => true],
            'question_desc'  => ['tipo' => 's'],
            'answer_desc'    => ['tipo' => 's'],
            'question_image' => ['tipo' => 's', 'solo_lettura' => true],
            'answer_image'   => ['tipo' => 's', 'solo_lettura' => true],
            'question_audio' => ['tipo' => 's', 'solo_lettura' => true],
            'answer_audio'   => ['tipo' => 's', 'solo_lettura' => true],
            'custom_order'   => ['tipo' => 'i', 'solo_lettura' => true],
            'updated_at'     => ['tipo' => 's', 'solo_lettura' => true],
        ],
    ],

    // ---- flashcard di studio: preferiti, imparate, esempi, partite ----
    // I preferiti: una carta (card_uuid) o una carta figlia (child_uuid) per riga. L'uuid e' casuale (il sito li scrive con
    // «INSERT IGNORE» su una colonna o sull'altra): se telefono e sito preferiscono la stessa carta, vince l'uuid del telefono.
    'fc_study_favorites' => [
        'ordine'       => 210,
        'proprietario' => 'user_id',
        'gruppo'       => [],
        'campi' => [
            'card_uuid'      => ['tipo' => 'fk', 'col' => 'fc_image_id', 'tabella' => 'fc_study_cards', 'solo_creazione' => true],
            'child_uuid'     => ['tipo' => 'fk', 'col' => 'fc_child_id', 'tabella' => 'fc_study_subcards', 'solo_creazione' => true],
            'favorited_date' => ['tipo' => 'd', 'solo_creazione' => true],
        ],
    ],
    // Le bozze veloci della Home (solo il fronte, il resto si completa dopo): la bozza diventa carta con una scrittura sulle carte
    // e la cancellazione della bozza. Al massimo 200 per utente, come sul sito.
    'fc_quick_drafts' => [
        'ordine'       => 213,
        'proprietario' => 'user_id',
        'gruppo'       => [],
        'campi' => [
            'front'      => ['tipo' => 's'],
            'back'       => ['tipo' => 's'],
            'deck_uuid'  => ['tipo' => 'fk', 'col' => 'deck_id', 'tabella' => 'fc_study_decks'],
            'created_at' => ['tipo' => 's', 'solo_lettura' => true],
            'updated_at' => ['tipo' => 's', 'solo_lettura' => true],
        ],
    // Gli invitati di un evento: l'indirizzo (anche scritto a mano, senza contatto) e il nome com'era allora. Si scrivono alla nascita.
    'event_guests' => [
        'ordine'       => 131,
        'proprietario' => 'user_id',
        'gruppo'       => [],
        'campi' => [
            'event_uuid'   => ['tipo' => 'fk', 'col' => 'event_id', 'tabella' => 'planner_events', 'solo_creazione' => true],
            'contact_uuid' => ['tipo' => 'fk', 'col' => 'contact_id', 'tabella' => 'contacts'],
            'email'        => ['tipo' => 's', 'solo_creazione' => true],
            'name'         => ['tipo' => 's'],
            'created_at'   => ['tipo' => 's', 'solo_lettura' => true],
            'updated_at'   => ['tipo' => 's', 'solo_lettura' => true],
        ],
    ],
    ],
    // Il mazzo di studio collegato a un progetto (uno solo per progetto). Per cambiarlo si toglie la riga e se ne crea un'altra,
    // come fa il sito. Non ha user_id: l'utente e' quello del progetto.
    'fc_study_deck_projects' => [
        'ordine'           => 214,
        'proprietario'     => 'user_id',
        'proprietario_sql' => 'SELECT p.user_id FROM projects p WHERE p.id = {t}.project_id',
        'gruppo'           => [],
        'campi' => [
            'project_uuid' => ['tipo' => 'fk', 'col' => 'project_id', 'tabella' => 'projects', 'solo_creazione' => true],
            'deck_uuid'    => ['tipo' => 'fk', 'col' => 'fc_category_id', 'tabella' => 'fc_study_decks', 'solo_creazione' => true],
            'created_at'   => ['tipo' => 's', 'solo_lettura' => true],
        ],
    ],
    // Le carte imparate: una per (utente, carta o carta figlia). La colonna fc_image_id vale per l'una o per l'altra
    // secondo is_child: sul filo c'e' un solo uuid (target_uuid) piu' il flag. L'uuid e' UUIDv5 di utente + target + flag.
    'fc_study_learnt' => [
        'ordine'          => 211,
        'proprietario'    => 'user_id',
        'gruppo'          => [],
        'chiave_naturale' => ['user_id', 'fc_image_id', 'is_child'],
        // le imparate di carte cancellate (il sito le lascia) non servono a nessuno
        'filtro'          => "is_child IN (0, 1) AND EXISTS (SELECT 1 FROM fc_study_cards xc WHERE t.is_child = 0 AND xc.id = t.fc_image_id UNION ALL SELECT 1 FROM fc_study_subcards xs WHERE t.is_child = 1 AND xs.id_child = t.fc_image_id)",
        'campi' => [
            'target_uuid'  => ['tipo' => 's', 'solo_creazione' => true, 'sql' => "COALESCE((SELECT xc.uuid FROM fc_study_cards xc WHERE t.is_child = 0 AND xc.id = t.fc_image_id), (SELECT xs.uuid FROM fc_study_subcards xs WHERE t.is_child = 1 AND xs.id_child = t.fc_image_id))"],
            'is_child'     => ['tipo' => 'b', 'solo_creazione' => true],
            'learnt_date'  => ['tipo' => 'd'],
            'updated_at'   => ['tipo' => 's', 'solo_lettura' => true],
        ],
    ],
    // Gli esempi di una carta o di una carta figlia (le immagini e gli audio viaggiano nella coda dei media).
    'fc_study_examples' => [
        'ordine'           => 212,
        'proprietario'     => 'user_id',
        'proprietario_sql' => "SELECT COALESCE((SELECT d.user_id FROM fc_study_cards c JOIN fc_study_decks d ON d.id = c.category_id WHERE c.id = {t}.parent_id), (SELECT d.user_id FROM fc_study_subcards s JOIN fc_study_cards c ON c.id = s.parent_id JOIN fc_study_decks d ON d.id = c.category_id WHERE s.id_child = {t}.child_id))",
        'gruppo'           => [],
        'campi' => [
            'card_uuid'     => ['tipo' => 'fk', 'col' => 'parent_id', 'tabella' => 'fc_study_cards', 'solo_creazione' => true],
            'child_uuid'    => ['tipo' => 'fk', 'col' => 'child_id', 'tabella' => 'fc_study_subcards', 'solo_creazione' => true],
            'example_text'  => ['tipo' => 's'],
            'example_image' => ['tipo' => 's', 'solo_lettura' => true],
            'example_audio' => ['tipo' => 's', 'solo_lettura' => true],
        ],
    ],
    // Le partite di studio (quelle di vocabolario entrano con le lingue). Una partita di ripasso (parent_uuid) ripete gli errori
    // di un'altra. max_card_uuid e' la carta piu' nuova quando la partita e' partita: le carte create dopo sono «nuove».
    'fc_games' => [
        'ordine'       => 220,
        'proprietario' => 'user_id',
        'gruppo'       => [],
        'filtro'       => "kind = 'study'",
        'campi' => [
            'game_date'                 => ['tipo' => 's', 'solo_creazione' => true],
            'score'                     => ['tipo' => 'n'],
            'type'                      => ['tipo' => 's', 'solo_creazione' => true],
            'card_status'               => ['tipo' => 's', 'solo_creazione' => true],
            'parent_uuid'               => ['tipo' => 'fk', 'col' => 'parent_id', 'tabella' => 'fc_games', 'solo_creazione' => true],
            'total_father_words'        => ['tipo' => 'i'],
    /* ---- lingue ---- */
    // I corsi di lingua: una coppia (lingua che conosci, lingua che impari) per utente. Nasce con il suo progetto (lo crea il server
    // se il telefono non ne indica uno). Si cambia solo la variante della lingua che impari.
    'fc_vocab_courses' => [
        'ordine'         => 300,
        'proprietario'   => 'user_id',
        'gruppo'         => ['user_id'],
        'colonna_ordine' => 'custom_order',
        'campi' => [
            'known_lang'   => ['tipo' => 's', 'solo_creazione' => true],
            'learn_lang'   => ['tipo' => 's'],
            'project_uuid' => ['tipo' => 'fk', 'col' => 'project_id', 'tabella' => 'projects'],
            'sort_key'     => ['tipo' => 'k'],
            'created_at'   => ['tipo' => 's', 'solo_lettura' => true],
            'updated_at'   => ['tipo' => 's', 'solo_lettura' => true],
        ],
    ],
    // I mazzi di lingua stanno dentro un corso; cancellare un mazzo toglie il ramo (come per lo studio).
    'fc_vocab_decks' => [
        'ordine'       => 301,
        'proprietario' => 'user_id',
        'gruppo'       => ['user_id'],
        'campi' => [
            'course_uuid'  => ['tipo' => 'fk', 'col' => 'course_id', 'tabella' => 'fc_vocab_courses', 'solo_creazione' => true],
            'name'         => ['tipo' => 's'],
            'parent_uuid'  => ['tipo' => 'fk', 'col' => 'parent_id', 'tabella' => 'fc_vocab_decks'],   // 0 = radice = null
            'color'        => ['tipo' => 's'],
            'color_edited' => ['tipo' => 'b'],
            'archived'     => ['tipo' => 'b'],
            'icon'         => ['tipo' => 's'],
            'notes'        => ['tipo' => 's'],
            'pinned'       => ['tipo' => 'b'],
            'exam_date'    => ['tipo' => 'd'],
            'updated_at'   => ['tipo' => 's', 'solo_lettura' => true],
        ],
    ],
    // Le parole: parola, traduzione, significato, note. L'immagine viaggia nella coda dei media.
    'fc_vocab_cards' => [
        'ordine'           => 302,
        'proprietario'     => 'user_id',
        'proprietario_sql' => 'SELECT d.user_id FROM fc_vocab_decks d WHERE d.id = {t}.category_id',
        'gruppo'           => ['user_id'],
        'campi' => [
            'category_uuid' => ['tipo' => 'fk', 'col' => 'category_id', 'tabella' => 'fc_vocab_decks'],
            'word'          => ['tipo' => 's'],
            'translation'   => ['tipo' => 's'],
            'meaning'       => ['tipo' => 's'],
            'notes'         => ['tipo' => 's'],
            'image'         => ['tipo' => 's', 'solo_lettura' => true],
            'is_father'     => ['tipo' => 'b', 'solo_lettura' => true],
            'custom_order'  => ['tipo' => 'i', 'solo_lettura' => true],
            'updated_at'    => ['tipo' => 's', 'solo_lettura' => true],
        ],
    ],
    'fc_vocab_subcards' => [
        'ordine'           => 303,
        'pk'               => 'id_child',
        'proprietario'     => 'user_id',
        'proprietario_sql' => 'SELECT d.user_id FROM fc_vocab_cards c JOIN fc_vocab_decks d ON d.id = c.category_id WHERE c.id = {t}.parent_id',
        'gruppo'           => ['user_id'],
        'campi' => [
            'parent_uuid'  => ['tipo' => 'fk', 'col' => 'parent_id', 'tabella' => 'fc_vocab_cards', 'solo_creazione' => true],
            'word'         => ['tipo' => 's'],
            'translation'  => ['tipo' => 's'],
            'meaning'      => ['tipo' => 's'],
            'notes'        => ['tipo' => 's'],
            'image'        => ['tipo' => 's', 'solo_lettura' => true],
            'custom_order' => ['tipo' => 'i', 'solo_lettura' => true],
            'updated_at'   => ['tipo' => 's', 'solo_lettura' => true],
        ],
    ],
    'fc_vocab_favorites' => [
        'ordine'       => 304,
        'proprietario' => 'user_id',
        'gruppo'       => [],
        'campi' => [
            'card_uuid'      => ['tipo' => 'fk', 'col' => 'fc_image_id', 'tabella' => 'fc_vocab_cards', 'solo_creazione' => true],
            'child_uuid'     => ['tipo' => 'fk', 'col' => 'fc_child_id', 'tabella' => 'fc_vocab_subcards', 'solo_creazione' => true],
            'favorited_date' => ['tipo' => 'd', 'solo_creazione' => true],
        ],
    ],
    'fc_vocab_learnt' => [
        'ordine'          => 305,
        'proprietario'    => 'user_id',
        'gruppo'          => [],
        'chiave_naturale' => ['user_id', 'fc_image_id', 'is_child'],
        'filtro'          => "is_child IN (0, 1) AND EXISTS (SELECT 1 FROM fc_vocab_cards xc WHERE t.is_child = 0 AND xc.id = t.fc_image_id UNION ALL SELECT 1 FROM fc_vocab_subcards xs WHERE t.is_child = 1 AND xs.id_child = t.fc_image_id)",
        'campi' => [
            'target_uuid'  => ['tipo' => 's', 'solo_creazione' => true, 'sql' => "COALESCE((SELECT xc.uuid FROM fc_vocab_cards xc WHERE t.is_child = 0 AND xc.id = t.fc_image_id), (SELECT xs.uuid FROM fc_vocab_subcards xs WHERE t.is_child = 1 AND xs.id_child = t.fc_image_id))"],
            'is_child'     => ['tipo' => 'b', 'solo_creazione' => true],
            'learnt_date'  => ['tipo' => 'd'],
            'updated_at'   => ['tipo' => 's', 'solo_lettura' => true],
        ],
    ],
    'fc_vocab_examples' => [
        'ordine'           => 306,
        'proprietario'     => 'user_id',
        'proprietario_sql' => "SELECT COALESCE((SELECT d.user_id FROM fc_vocab_cards c JOIN fc_vocab_decks d ON d.id = c.category_id WHERE c.id = {t}.parent_id), (SELECT d.user_id FROM fc_vocab_subcards s JOIN fc_vocab_cards c ON c.id = s.parent_id JOIN fc_vocab_decks d ON d.id = c.category_id WHERE s.id_child = {t}.child_id))",
        'gruppo'           => [],
        'campi' => [
            'card_uuid'     => ['tipo' => 'fk', 'col' => 'parent_id', 'tabella' => 'fc_vocab_cards', 'solo_creazione' => true],
            'child_uuid'    => ['tipo' => 'fk', 'col' => 'child_id', 'tabella' => 'fc_vocab_subcards', 'solo_creazione' => true],
            'example_text'  => ['tipo' => 's'],
            'gap_text'      => ['tipo' => 's'],
            'example_image' => ['tipo' => 's', 'solo_lettura' => true],
            'example_audio' => ['tipo' => 's', 'solo_lettura' => true],
            'updated_at'    => ['tipo' => 's', 'solo_lettura' => true],
        ],
    ],

            'game_order'                => ['tipo' => 's'],
            'total_words_with_children' => ['tipo' => 'i'],
            'max_card_uuid'             => ['tipo' => 's', 'sql' => "(SELECT xc.uuid FROM fc_study_cards xc WHERE xc.id = t.max_card_id)"],
        ],
    ],
    // Il tempo di gioco: una riga per ogni «sessione» della partita (si riprende dopo una pausa).
    'fc_game_durations' => [
        'ordine'           => 221,
        'proprietario'     => 'user_id',
        'proprietario_sql' => 'SELECT g.user_id FROM fc_games g WHERE g.id = {t}.game_id',
        'gruppo'           => [],
        'filtro'           => "game_id IN (SELECT xg.id FROM fc_games xg WHERE xg.kind = 'study')",
        'campi' => [
            'game_uuid'        => ['tipo' => 'fk', 'col' => 'game_id', 'tabella' => 'fc_games', 'solo_creazione' => true],
            'start_time'       => ['tipo' => 's', 'solo_creazione' => true],
            'duration_seconds' => ['tipo' => 'i'],
            'updated_at'       => ['tipo' => 's', 'solo_lettura' => true],
        ],
    ],
    // Le risposte: una per carta (o carta figlia) e posizione della partita. user_answer: Correct | Wrong | Titolo | NULL.
    'fc_study_plays' => [
        'ordine'           => 222,
        'proprietario'     => 'user_id',
        'proprietario_sql' => 'SELECT g.user_id FROM fc_games g WHERE g.id = {t}.game_id',
        'gruppo'           => [],
        'filtro'           => "game_id IN (SELECT xg.id FROM fc_games xg WHERE xg.kind = 'study')",
        'campi' => [
            'game_uuid'      => ['tipo' => 'fk', 'col' => 'game_id', 'tabella' => 'fc_games', 'solo_creazione' => true],
            'card_uuid'      => ['tipo' => 'fk', 'col' => 'fc_image_id', 'tabella' => 'fc_study_cards', 'solo_creazione' => true],
            'child_uuid'     => ['tipo' => 'fk', 'col' => 'fc_child_id', 'tabella' => 'fc_study_subcards', 'solo_creazione' => true],
            'correct_answer' => ['tipo' => 's'],
            'user_answer'    => ['tipo' => 's'],
            'position'       => ['tipo' => 'i', 'solo_creazione' => true],
            'play_date'      => ['tipo' => 's'],
        ],
    ],
    // I mazzi scelti per la partita e la sequenza delle carte (solo i padri: i figli seguono il padre).
    'fc_study_game_decks' => [
        'ordine'           => 223,
        'proprietario'     => 'user_id',
        'proprietario_sql' => 'SELECT g.user_id FROM fc_games g WHERE g.id = {t}.game_id',
        'gruppo'           => [],
        'filtro'           => "game_id IN (SELECT xg.id FROM fc_games xg WHERE xg.kind = 'study')",
        'campi' => [
            'game_uuid'      => ['tipo' => 'fk', 'col' => 'game_id', 'tabella' => 'fc_games', 'solo_creazione' => true],
            'deck_uuid'      => ['tipo' => 'fk', 'col' => 'fc_category_id', 'tabella' => 'fc_study_decks', 'solo_creazione' => true],
            'category_order' => ['tipo' => 'i', 'solo_creazione' => true],
        ],
    ],
    'fc_study_sequence' => [
        'ordine'           => 224,
        'proprietario'     => 'user_id',
        'proprietario_sql' => 'SELECT g.user_id FROM fc_games g WHERE g.id = {t}.game_id',
        'gruppo'           => [],
        'filtro'           => "game_id IN (SELECT xg.id FROM fc_games xg WHERE xg.kind = 'study')",
        'campi' => [
            'game_uuid' => ['tipo' => 'fk', 'col' => 'game_id', 'tabella' => 'fc_games', 'solo_creazione' => true],
            'position'  => ['tipo' => 'i', 'solo_creazione' => true],
            'card_uuid' => ['tipo' => 'fk', 'col' => 'fc_image_id', 'tabella' => 'fc_study_cards', 'solo_creazione' => true],
        ],
    ],
    // I record (serie piu' lunga, ...): uno per (utente, tipo). UUIDv5 di utente + tipo.
    'fc_study_records' => [
        'ordine'          => 230,
        'proprietario'    => 'user_id',
        'gruppo'          => [],
        'chiave_naturale' => ['user_id', 'kind'],
        'campi' => [
            'kind'        => ['tipo' => 's', 'solo_creazione' => true],
            'value'       => ['tipo' => 'n'],
            'game_uuid'   => ['tipo' => 'fk', 'col' => 'game_id', 'tabella' => 'fc_games'],
            'achieved_at' => ['tipo' => 's'],
            'updated_at'  => ['tipo' => 's', 'solo_lettura' => true],
        ],
    ],
    // Le impostazioni di studio e l'obiettivo giornaliero: una riga per utente (chiave primaria user_id), UUIDv5 di tabella + utente.
    'fc_settings' => [
        'ordine'          => 240,
        'pk'              => 'user_id',
        'proprietario'    => 'user_id',
        'gruppo'          => [],
        'chiave_naturale' => ['user_id'],
        'campi' => [
            'vocab_meaning'  => ['tipo' => 'e'],
            'vocab_examples' => ['tipo' => 'e'],
            'voice_read'     => ['tipo' => 's'],
            'updated_at'     => ['tipo' => 's', 'solo_lettura' => true],
        ],
    ],
    'fc_goals' => [
        'ordine'          => 241,
        'pk'              => 'user_id',
        'proprietario'    => 'user_id',
        'gruppo'          => [],
        'chiave_naturale' => ['user_id'],
        'campi' => [
            'daily_goal' => ['tipo' => 'i'],
            'updated_at' => ['tipo' => 's', 'solo_lettura' => true],
        ],
    ],
];

/** UUIDv5: namespace fisso dell'app (lo stesso nell'app, vedi src/core/sync/uuid5). */
const SYNC_NS_UUID5 = '7d4f1b0e-3c52-4e8a-9a61-5b2c8d0f6e13';

/** Un UUIDv5 (RFC 4122) da namespace + nome: identico alla libreria `uuid` di JavaScript. */
function sync_uuid5(string $nome, string $ns = SYNC_NS_UUID5): string {
    $h = substr(sha1(hex2bin(str_replace('-', '', $ns)) . $nome), 0, 32);
    $h[12] = '5';
    $h[16] = dechex((hexdec($h[16]) & 0x3) | 0x8);
    return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20, 12);
}

/** L'identita' di un check-in: da abitudine (uuid) + giorno. */
function sync_uuid_check_in(string $habitUuid, string $giorno): string {
    return sync_uuid5('habit_logs|' . $habitUuid . '|' . $giorno);
}

/** L'identita' di una presa: da terapia (uuid) + giorno + orario (HH:MM) + n. */
function sync_uuid_presa(string $planUuid, string $giorno, string $ora, int $seq): string {
    return sync_uuid5('pill_doses|' . $planUuid . '|' . $giorno . '|' . substr($ora, 0, 5) . '|' . $seq);
}

/** L'identita' di una priorita' del giorno: da utente + giorno + posto (1..6). */
function sync_uuid_priorita(int $uid, string $giorno, int $posto): string {
    return sync_uuid5('planner_priorities|' . $uid . '|' . $giorno . '|' . $posto);
}

/** L'identita' di una settimana del planner: da utente + lunedi. */
function sync_uuid_settimana(int $uid, string $lunedi): string {
    return sync_uuid5('planner_weeks|' . $uid . '|' . $lunedi);
}

/** L'identita' di una lista della settimana: da utente + lunedi + tipo. */
function sync_uuid_lista_sett(int $uid, string $lunedi, string $tipo): string {
    return sync_uuid5('planner_week_lists|' . $uid . '|' . $lunedi . '|' . $tipo);
}

/** L'identita' di una durata salvata del Pomodoro: da utente + tipo + minuti. */
function sync_uuid_preset(int $uid, string $tipo, int $minuti): string {
    return sync_uuid5('pomodoro_custom_durations|' . $uid . '|' . $tipo . '|' . $minuti);
}

/** L'identita' del diario di un giorno: da utente + giorno. */
function sync_uuid_diario(int $uid, string $giorno): string {
    return sync_uuid5('habit_daily_journal|' . $uid . '|' . $giorno);
}

/** L'identita' di una carta imparata: da utente + carta (o carta figlia) + flag. */
function sync_uuid_imparata(int $uid, string $targetUuid, int $figlia): string {
    return sync_uuid5('fc_study_learnt|' . $uid . '|' . $targetUuid . '|' . $figlia);
}

/** L'identita' di un record: da utente + tipo. */
function sync_uuid_record(int $uid, string $tipo): string {
    return sync_uuid5('fc_study_records|' . $uid . '|' . $tipo);
}

/** L'identita' di una riga per utente (impostazioni, obiettivo): da tabella + utente. */
function sync_uuid_per_utente(string $tabella, int $uid): string {
    return sync_uuid5($tabella . '|' . $uid);
}

/** Le tabelle sincronizzate, nell'ordine di dipendenza. */
function sync_tabelle(): array {
    $t = SYNC_MANIFESTO;
    uasort($t, fn($a, $b) => $a['ordine'] <=> $b['ordine']);
    return array_keys($t);
}

/** Un uuid scritto bene (qualunque versione): 36 caratteri, minuscole. */
function sync_uuid_valido($u): bool {
    return is_string($u) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $u) === 1;
}
