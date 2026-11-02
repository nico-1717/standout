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
     'sql'   espressione calcolata (solo lettura): finisce nella SELECT cosi' com'e', alias «t» = la tabella
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
            'routine_group'    => ['tipo' => 's', 'solo_lettura' => true],
            'routine_collapsed'=> ['tipo' => 'b', 'solo_lettura' => true],
            'sort_order'       => ['tipo' => 'i', 'solo_lettura' => true],
            'created_at'       => ['tipo' => 's', 'solo_lettura' => true],
            'updated_at'       => ['tipo' => 's', 'solo_lettura' => true],
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

/** L'identita' del diario di un giorno: da utente + giorno. */
function sync_uuid_diario(int $uid, string $giorno): string {
    return sync_uuid5('habit_daily_journal|' . $uid . '|' . $giorno);
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
