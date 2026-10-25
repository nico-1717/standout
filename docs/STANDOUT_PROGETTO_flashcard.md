# Flashcard: studio e lingue — stato dopo la ristrutturazione (ottobre 2026)

Da incollare in STANDOUT_PROGETTO.md (sezione flashcard).

## Come funziona adesso

- **Un solo insieme di pagine per due tipi di flashcard.** Studio (domanda/risposta, testo ricco, immagini, audio) e lingua (parola, traduzione, significato, esempi, un'immagine, pronuncia con la sintesi vocale del browser). Le pagine sono le stesse (`fc_decks`, `fc_view`, `fc_card_edit`, `fc_insert`, `fc_game_*`, `fc_result`, `fc_history`): una correzione vale per tutti e due.
- **Il tipo lo decide `fc_type.php`.** Con `?course=N` nell'indirizzo la pagina lavora sulle tabelle `fc_vocab_*` del corso N (che deve essere dell'utente); senza, su `fc_study_*`. I nomi di tabella vengono da un elenco chiuso (`fc_t('cards')`, `fc_t('decks')`…), mai dal browser.
- **Le partite sanno di che tipo sono:** `fc_games.kind` (`study`/`vocab`) e `fc_games.course_id`. Le pagine di una partita leggono il tipo dalla partita, non dall'indirizzo.
- **Verso delle partite di lingua** (scelta dell'utente in `fc_game_setup`): nel campo `type` — `w2t` parola→traduzione, `t2w` traduzione→parola, `mix` alternato per posizione; `_L` finale = solo carte imparate (come `fc_L`). Le vecchie partite (`en_it`, `quiz`…) sono lette come `w2t`/`t2w`.
- **Pagine nuove:** `fc_home.php` (pagina principale: Studio / Lingue + serie, obiettivo, settimana, precisione), `fc_languages.php` (+ `fc_languages.js`, `fc_hub.css`: i corsi con bandiera, aggiunta con ricerca, varianti come inglese UK/US), `fc_summary.php` (numeri condivisi con la home del sito), `fc_speak.js` (pronuncia), `svg/flags/` (bandiere quadrate, flag-icons, licenza MIT).
- **Corso = progetto:** aggiungendo una lingua nasce (o si collega, se esiste già con quel nome) un progetto in `progetti.php`; il progetto mostra la lingua con la bandiera e porta alle sue categorie.
- **`fc_parser.js`, `format_toolbar.php` e MathJax esistono solo nelle flashcard di studio:** le pagine di lingua non li caricano.
- **Struttura condivisa delle tabelle:** le colonne strutturali di `fc_vocab_*` hanno gli stessi nomi di quelle di studio (`category_id`, `fc_image_id`, `fc_child_id`, `fc_category_id`, `id_child`, `custom_order`), così le query cambiano solo nel nome della tabella. Le colonne di contenuto sono diverse; in lettura `fc_colonne_carta()` le rinomina come quelle di studio.

## Rinomina delle tabelle

| Prima | Adesso |
|---|---|
| `fc_images` | `fc_study_cards` |
| `fc_child_cards` | `fc_study_subcards` |
| `fc_categories` | `fc_study_decks` |
| `fc_examples` | `fc_study_examples` |
| `fc_ai_data` | `fc_study_ai` |
| `user_learnt_fc` | `fc_study_learnt` |
| `user_favorites_fc` | `fc_study_favorites` |
| `fc_gameplays` | `fc_study_plays` |
| `fc_game_sequence` | `fc_study_sequence` |
| `game_fc_categories` | `fc_study_game_decks` |
| `project_fc_categories` | `fc_study_deck_projects` |
| `games` | `fc_games` |
| `session_durations` | `fc_game_durations` |
| `fc_user_goals` | `fc_goals` |
| `categories` | `fc_vocab_decks` |
| `images` | `fc_vocab_cards` |
| `english_child_words` | `fc_vocab_subcards` |
| `user_learnt_english` | `fc_vocab_learnt` |
| `gameplays` | `fc_vocab_plays` |
| `game_categories` | `fc_vocab_game_decks` |

Nuove: `fc_vocab_courses`, `fc_vocab_examples`, `fc_vocab_favorites`, `fc_vocab_sequence`. Tutto in `migration_01_flashcards.sql`, che migra anche le parole, le immagini, le categorie e le partite esistenti (gli esempi della vecchia colonna `example` diventano righe di `fc_vocab_examples`).

## Rinomina dei file

| Prima | Adesso |
|---|---|
| `admin_ia.css` | `admin_ai.css` |
| `admin_ia.php` | `admin_ai.php` |
| `ai_crediti.php` | `ai_credits.php` |
| `crediti.css` | `credits.css` |
| `crediti.php` | `credits.php` |
| `crediti_corpo.php` | `credits_body.php` |
| `fc_ai_formato.php` | `fc_ai_format.php` |
| `fc_ai_abbina.js` | `fc_ai_match.js` |
| `fc_ai_pannello.css` | `fc_ai_panel.css` |
| `fc_ai_pannello.js` | `fc_ai_panel.js` |
| `fc_ai_pannello.php` | `fc_ai_panel.php` |
| `fc_ai_stile.php` | `fc_ai_style.php` |
| `api_get_counts_fc.php` | `fc_api_counts.php` |
| `floating_player.css` | `fc_audio_player.css` |
| `floating_player.php` | `fc_audio_player.php` |
| `autosave_bar.css` | `fc_autosave_bar.css` |
| `autosave_bar.js` | `fc_autosave_bar.js` |
| `autosave_bar.php` | `fc_autosave_bar.php` |
| `modifica_immagine_fc.css` | `fc_card_edit.css` |
| `modifica_immagine_fc.php` | `fc_card_edit.php` |
| `category_selector.css` | `fc_deck_selector.css` |
| `category_selector.php` | `fc_deck_selector.php` |
| `categories_fc.css` | `fc_decks.css` |
| `categories_fc.php` | `fc_decks.php` |
| `fc_editor_rifiniture.css` | `fc_editor_polish.css` |
| `examples_api.php` | `fc_examples_api.php` |
| `examples_box.css` | `fc_examples_box.css` |
| `examples_box.php` | `fc_examples_box.php` |
| `examples_modal.css` | `fc_examples_modal.css` |
| `examples_modal.js` | `fc_examples_modal.js` |
| `examples_modal.php` | `fc_examples_modal.php` |
| `game_fc.css` | `fc_game.css` |
| `game_fc.php` | `fc_game.php` |
| `precaricamento-e-anteprima-swipe.md` | `fc_game_prefetch.md` |
| `start_retry.php` | `fc_game_retry.php` |
| `categories_game_fc.css` | `fc_game_setup.css` |
| `categories_game_fc.php` | `fc_game_setup.php` |
| `start_game_fc.php` | `fc_game_start.php` |
| `history_fc.css` | `fc_history.css` |
| `history_fc.php` | `fc_history.php` |
| `fc_editor_immagine.js` | `fc_image_editor.js` |
| `fc_editor_immagine.php` | `fc_image_editor.php` |
| `fc_misure_immagini.php` | `fc_image_sizes.php` |
| `fc_misure_backfill.php` | `fc_image_sizes_backfill.php` |
| `fc_migliora_carta.css` | `fc_improve_card.css` |
| `fc_migliora_carta.js` | `fc_improve_card.js` |
| `fc_migliora_carta.php` | `fc_improve_card.php` |
| `inserimento_big_fc.css` | `fc_insert.css` |
| `inserimento_big_fc.php` | `fc_insert.php` |
| `inserimento_fc.css` | `fc_insert_card.css` |
| `inserimento_fc.php` | `fc_insert_card.php` |
| `big_edit_save_fc.php` | `fc_insert_save.php` |
| `update_learnt.php` | `fc_learnt.php` |
| `script_read.js` | `fc_read_aloud.js` |
| `result_fc.css` | `fc_result.css` |
| `result_fc.php` | `fc_result.php` |
| `fc_sequenza_backfill.php` | `fc_sequence_backfill.php` |
| `stats_fc.css` | `fc_stats.css` |
| `stats_fc.php` | `fc_stats.php` |
| `visualizzazione_fc.css` | `fc_view.css` |
| `visualizzazione_fc.php` | `fc_view.php` |
| `voice_game_mode.css` | `fc_voice_mode.css` |
| `voice_game_mode.php` | `fc_voice_mode.php` |
| `azione_post.php` | `post_action.php` |

## Lingue: livelli, esempi, scelte (ottobre 2026)

- **Livelli delle parole** (`lib/fc/fc_vocab_gioco.php`, gemello JS in `fc_game_juice.js`): nuova, da imparare, sembra facile, la sai (ultime 5 giuste), insidiosa (1 errore nelle ultime 5, ripassi esclusi), difficile (2 errori), difficilissima (difficile e sbagliata anche nei ripassi), in ripresa (era difficile, l'ultima giusta), quasi (era difficile, 2-3 giuste di fila). L'elenco ordinato è `FCV_LIVELLI`; le due funzioni devono restare identiche.
- **Carte che sai** (`fcv_conosciute()`): segnate come imparate **oppure** al livello "la sai". La usano percentuali, cruscotto (`fc_home`, per giorno/settimana/mese) e `fc_stats`.
- **Parte nascosta degli esempi** (`gap_text`, `lib/fc/fc_vocab_gap.php` + `public/fc/fc_vocab_gap.js`, identici): vuota = automatica; un testo = un pezzo; `[[pos,"testo"],...]` = più pezzi ("I want … to"). Le contrazioni si spezzano (I'm → I ' m). Il componente di scelta è lo stesso in modifica, inserimento multiplo e import.
- **Significato ed esempi nel gioco** (aperti / al tocco / dopo la risposta) si salvano per utente in `fc_settings` (`lib/fc/fc_impostazioni.php`, `fc_settings_api.php`). La prima volta: aperti.
- **Modalità voce:** niente più comando "avanti": dopo "giusto"/"sbagliato" si legge subito la domanda dopo. I comandi si riconoscono in italiano e in inglese insieme; la lingua del microfono si sceglie nel pannello.

## Modalità voce nelle parole (25/10/2026)

- **Si risponde a voce.** Finché il microfono non sente la risposta giusta non succede niente (parole sbagliate ignorate, nessun voto d'ufficio). Quando la sente: mostra la carta, legge quello che si è scelto e, dopo 3 secondi (e a lettura finita), segna *corretta* e passa alla carta dopo. «Risposta» e «sbagliato» restano per chi non la sa; «corretto» durante i 3 secondi vale subito. Logica in `fc_voice_mode.php` (sezione 3-bis: `provaRispostaParola`, `rispostaDettaGiusta`).
- **Cosa legge** (pannello di avvio, solo parole): domanda sì/no · risposta 1 o 2 volte (la seconda piano) · significato sì/no · esempi nessuno/uno/tutti. Quattro cifre in `fc_settings.voice_read` (default `1100`), regole in `fc_lettura_valida()`.
- «password»/«passwords» sono alias di «answer» nel dizionario dei comandi (il riconoscitore le sente così).

## Rimandato (da fare)

- **IA per le parole.** Nel gioco di lingua non ci sono Leggi/Spiega/Metafora/Frantuma, e nell'inserimento multiplo manca la sezione "Genera con l'IA". Le vecchie `generate_ai_text*.php` / `generate_ai_image*.php` del vocabolario non sono state portate: andranno rifatte sul motore IA delle flashcard di studio (`fc_ai_lib.php`).
- **Statistiche delle lingue.** `fc_stats.php` conta solo lo studio (partite `kind='study'`). Per le lingue esistono solo i numeri di `fc_home`/`fc_languages` (parole, imparate, serie).
- **Terzo giro di nomi:** colonne strutturali ancora in italiano/vecchio stile (`category_id` → `deck_id`, `fc_image_id` → `card_id`, `fc_child_id`, `id_child`, `fc_category_id`, `is_father`…), in entrambe le famiglie di tabelle insieme.
- **Vecchie partite di vocabolario:** le posizioni partono da 0 e non hanno sequenza; si vedono nello storico e si possono ripassare (il ripasso crea una partita nuova normale), ma non si "riprendono".
- **Cruscotto:** la percentuale "padroneggiate" confronta carte imparate (padri) con carte totali (figlie e carte singole), come faceva prima.
- Chiavi di traduzione già mancanti prima di questo lavoro: `fc.cred_apri_pagina`, `fc.cred_non_caricato`, `fc.ibfai_job_fase_carte_avvio`, `fc.ibfai_job_fase_concetti_avvio`, `fc.ibfai_job_pillola_avvio`.
