<?php
/**
 * fc_ai_panel.php — il pannello di inserimento carte.
 *
 * Si include con una riga sola dentro fc_insert.php:
 *
 *     <?php include __DIR__ . '/fc_ai_panel.php'; ?>
 *
 * Sostituisce, in quella pagina: il riquadro del prompt da copiare
 * (.format-help), la scheda "Immagini (opzionale)" e la scheda dei separatori
 * dentro .setup-grid, e il pulsante #btn-generate-table. La scheda "Categoria
 * di destinazione" NON si tocca: non c'entra con l'import.
 *
 * PERCHE' UN FILE A PARTE
 * fc_insert.php e' 77 KB: ogni modifica la' dentro e' un rischio che
 * non serve correre. Qui il pannello nasce isolato, e l'unico punto di contatto
 * con la pagina sono quattro funzioni che gia' esistono e che non tocchiamo:
 * appendRowHTML(), markDirty(), showToast(), showConfirm().
 *
 * LINGUE
 * I testi passano dal dizionario del sito: te()/t() lato PHP in questo file,
 * T() lato JS in fc_ai_panel.js e fc_ai_match.js. Le chiavi nuove
 * introdotte da questo pannello stanno tutte sotto il prefisso "fc.ibfai_"
 * in lang/it.json e lang/en.json — un prefisso solo da cercare quando serve
 * ritoccarne una.
 */
?>
<?php
/* Il "?v=" forza il browser a ricaricare il CSS ogni volta che il file cambia
   davvero, invece di tenersi in cache la versione vecchia fino a un refresh
   forzato manuale: filemtime() e' la data di ultima modifica del file, quindi
   il numero cambia da solo a ogni nuova versione che si carica sul server. */
$fcai_css_v = @filemtime(__DIR__ . '/fc_ai_panel.css') ?: 1;
?>
<link rel="stylesheet" href="fc_ai_panel.css?v=<?php echo $fcai_css_v; ?>">

<?php
/* Il livello condiviso di movimento e attesa (scheletri, entrate, pressione,
   ricerca). Una volta sola anche se il pannello comparisse due volte. */
if (!defined('STANDOUT_UI')) {
    define('STANDOUT_UI', 1);
    echo '<link rel="stylesheet" href="' . PWA_BASE . '_assets/ui/standout_ui.css?v='
       . (@filemtime(__DIR__ . '/../_assets/ui/standout_ui.css') ?: 1) . '">' . "\n";
    echo '<script src="' . PWA_BASE . '_assets/ui/standout_ui.js?v='
       . (@filemtime(__DIR__ . '/../_assets/ui/standout_ui.js') ?: 1) . '"></script>' . "\n";
}

/* Le scelte di stile vengono dallo stesso elenco con cui il server le valida
   (fc_ai_stile_valori): una voce aggiunta li' compare qui da sola, e una
   tendina non puo' offrire un valore che il server rifiuterebbe. */
require_once __DIR__ . '/../../lib/fc/fc_ai_style.php';

/* Flashcard di lingua: il pannello resta lo stesso, ma senza la sezione
   dell'IA (per le parole arrivera' piu' avanti, vedi STANDOUT_PROGETTO.md) e
   con un'immagine sola per carta. L'import legge quattro colonne: parola,
   traduzione, significato, esempio. */
$fcai_vocab = function_exists('fc_is_vocab') && fc_is_vocab();
?>
<div class="fcai-panel" id="fcaiPanel">

    <!-- ============================================================
         GENERAZIONE CON L'INTELLIGENZA ARTIFICIALE
         ============================================================ -->
    <section class="fcai-sec" data-sec="ia" id="fcaiSecIa"<?php if ($fcai_vocab) echo ' hidden style="display:none"'; ?>>
        <button type="button" class="fcai-head" data-apri="ia">
            <span class="fcai-ic">
                <svg viewBox="0 0 24 24" fill="currentColor">
                    <path d="M9 2 11 7 16 9 11 11 9 16 7 11 2 9 7 7Z"/>
                    <path d="M18 3 19 5 21 6 19 7 18 9 17 7 15 6 17 5Z"/>
                </svg>
            </span>
            <span class="fcai-titoli">
                <b><?php te('fc.ibfai_sec_ia_titolo'); ?></b>
                <span><?php te('fc.ibfai_sec_ia_sub'); ?></span>
            </span>
            <svg class="fcai-freccia" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m9 6 6 6-6 6"/></svg>
        </button>

        <div class="fcai-body"><div><div class="fcai-inner">

            <div class="fcai-chips" id="fcaiFonti">
                <button type="button" class="fcai-chip is-on" data-fonte="file"><?php te('fc.ibfai_fonte_file'); ?></button>
                <button type="button" class="fcai-chip" data-fonte="elenco"><?php te('fc.ibfai_fonte_elenco'); ?></button>
                <button type="button" class="fcai-chip" data-fonte="argomento"><?php te('fc.ibfai_fonte_argomento'); ?></button>
            </div>

            <!-- fonte: file -->
            <div data-fonte-pan="file">
                <label class="fcai-drop" id="fcaiDrop">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8Z"/><path d="M14 3v5h5"/>
                    </svg>
                    <span><?php te('fc.ibfai_drop_testo'); ?></span>
                    <input type="file" id="fcaiFile" accept=".pdf,.docx,.xlsx,.xlsm,.txt,.md,.csv,.tsv" hidden>
                </label>
                <div id="fcaiFileInfo"></div>
            </div>

            <!-- fonte: elenco scritto a mano -->
            <div data-fonte-pan="elenco" hidden>
                <label class="fcai-label"><?php te('fc.ibfai_elenco_titolo_lbl'); ?></label>
                <input type="text" id="fcaiElencoTitolo" placeholder="<?php echo sec_h(t('fc.ibfai_elenco_titolo_ph')); ?>">
                <label class="fcai-label"><?php te('fc.ibfai_elenco_concetti_lbl'); ?></label>
                <textarea id="fcaiElencoTesto" placeholder="<?php echo sec_h(t('fc.ibfai_elenco_concetti_ph')); ?>"></textarea>
            </div>

            <!-- fonte: solo argomento -->
            <div data-fonte-pan="argomento" hidden>
                <label class="fcai-label"><?php te('fc.ibfai_argomento_lbl'); ?></label>
                <input type="text" id="fcaiArgomento" placeholder="<?php echo sec_h(t('fc.ibfai_argomento_ph')); ?>">
            </div>

            <!-- Le indicazioni valgono per tutte e tre le fonti, e arrivano sia
                 alla scelta dei concetti sia alla scrittura delle carte. Prima
                 stavano solo sotto "argomento" e si fermavano ai concetti: chi
                 chiedeva una forma precisa per le risposte non la vedeva mai. -->
            <label class="fcai-label"><?php te('fc.ibfai_indicazioni_generali_lbl'); ?></label>
            <textarea id="fcaiIndicazioni" placeholder="<?php echo sec_h(t('fc.ibfai_indicazioni_generali_ph')); ?>" style="min-height:80px;"></textarea>

            <button type="button" class="fcai-riep" id="fcaiApriImpostazioni">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M4 6h10M18 6h2M4 12h2M10 12h10M4 18h10M18 18h2"/>
                    <circle cx="16" cy="6" r="2"/><circle cx="8" cy="12" r="2"/><circle cx="16" cy="18" r="2"/>
                </svg>
                <span id="fcaiRiepilogo"><?php te('fc.ibfai_impostazioni'); ?></span>
                <svg class="fcai-freccia" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m9 6 6 6-6 6"/></svg>
            </button>

            <div class="fcai-azioni">
                <button type="button" class="btn btn-primary ns-preme" id="fcaiBtnConcetti">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 6h16M4 12h11M4 18h7"/></svg>
                    <?php te('fc.ibfai_trova_concetti'); ?>
                </button>
            </div>
            <div class="fcai-nota" id="fcaiStato"></div>

            <!-- elenco dei concetti + generazione -->
            <div id="fcaiRisultato" hidden>
                <?php /* La ricerca compare solo quando serve davvero (oltre una
                         trentina di concetti): su otto voci un campo di ricerca
                         e' un ingombro, su ottocento e' l'unico modo di trovarne
                         uno. La mostra e la nasconde disegnaElenco(). */ ?>
                <div class="fcai-cerca-riga" id="fcaiCercaRiga" hidden>
                    <input type="search" id="fcaiCerca" class="ns-cerca"
                           placeholder="<?php echo sec_h(t('fc.ibfai_cerca_ph')); ?>"
                           autocomplete="off" autocorrect="off" spellcheck="false">
                    <span class="ns-conta" id="fcaiCercaConta"></span>
                </div>
                <div class="fcai-elenco" id="fcaiElenco"></div>
                <div class="fcai-azioni">
                    <button type="button" class="btn btn-ghost btn-sm ns-preme" id="fcaiMeno"><?php te('fc.ibfai_meno_concetti'); ?></button>
                    <button type="button" class="btn btn-ghost btn-sm ns-preme" id="fcaiPiu"><?php te('fc.ibfai_piu_concetti'); ?></button>
                </div>
                <div class="fcai-azioni">
                    <button type="button" class="btn btn-success ns-preme" id="fcaiBtnCarte">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M12 5v14"/></svg>
                        <?php te('fc.ibfai_genera_flashcard'); ?>
                    </button>
                    <button type="button" class="btn btn-ghost" id="fcaiRigeneraTutte" hidden>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-3-6.7"/><path d="M21 4v5h-5"/></svg>
                        <?php te('fc.ibfai_rigenera_tutte_btn'); ?>
                    </button>
                </div>
                <div class="fcai-progresso" id="fcaiProgresso"></div>
            </div>

            <!-- Il campo del vecchio prompt resta, nascosto: updateAIPrompt() della
                 pagina continua a riempirlo a ogni cambio di separatore, e il tasto
                 "copia il prompt" qui sotto legge da li'. Toglierlo avrebbe voluto
                 dire riscrivere quella funzione senza motivo. -->
            <textarea id="ai_prompt_textarea" readonly hidden></textarea>

            <!-- ripiego senza token: il prompt negli appunti -->
            <div class="fcai-nota" style="margin-top:14px;">
                <?php te('fc.ibfai_niente_token_domanda'); ?>
                <a href="#" id="fcaiCopiaPrompt" style="color:var(--blue); font-weight:600;"><?php te('fc.ibfai_copia_prompt_link'); ?></a>
                <?php te('fc.ibfai_niente_token_seguito'); ?>
            </div>

        </div></div></div>
    </section>

    <!-- ============================================================
         GENERAZIONE DA IMMAGINI
         ============================================================ -->
    <?php if ($fcai_vocab): ?>
    <?php /* GENERA CON L'IA — le parole. Le flashcard di studio hanno il loro
             percorso (concetti -> carte, sopra); per una lingua basta dire
             l'argomento, o incollare le parole da completare. */ ?>
    <section class="fcai-sec" data-sec="ia-parole" id="fcaiSecParole">
        <button type="button" class="fcai-head" data-apri="ia-parole">
            <span class="fcai-ic fcai-ic-ia">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 3l1.8 4.6L18.5 9l-4.7 1.5L12 15l-1.8-4.5L5.5 9l4.7-1.4z"/><path d="M19 15l.8 2 2 .8-2 .7-.8 2-.7-2-2-.7 2-.8z"/>
                </svg>
            </span>
            <span class="fcai-titoli">
                <b><?php te('fc.vai_titolo'); ?></b>
                <span><?php te('fc.vai_sub'); ?></span>
            </span>
            <svg class="fcai-freccia" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m9 6 6 6-6 6"/></svg>
        </button>
        <div class="fcai-body"><div><div class="fcai-inner">
            <div class="fcai-chips" id="fcaiParoleModi">
                <button type="button" class="fcai-chip is-on" data-modo="argomento"><?php te('fc.vai_modo_argomento'); ?></button>
                <button type="button" class="fcai-chip" data-modo="elenco"><?php te('fc.vai_modo_elenco'); ?></button>
            </div>
            <div data-parole-pan="argomento">
                <input type="text" id="fcaiParoleArg" maxlength="300" enterkeyhint="go"
                       placeholder="<?php echo sec_h(t('fc.vai_argomento_ph')); ?>">
                <label class="fcai-label"><?php te('fc.vai_livello'); ?></label>
                <div class="fcai-chips fcai-chips-sm" id="fcaiParoleLiv">
                    <button type="button" class="fcai-chip is-on" data-v=""><?php te('fc.vai_livello_qualsiasi'); ?></button>
                    <?php foreach (['a1', 'a2', 'b1', 'b2', 'c1', 'c2'] as $lv): ?>
                    <button type="button" class="fcai-chip" data-v="<?php echo $lv; ?>"><?php echo strtoupper($lv); ?></button>
                    <?php endforeach; ?>
                </div>
                <label class="fcai-label"><?php te('fc.vai_quante'); ?></label>
                <div class="fcai-chips fcai-chips-sm" id="fcaiParoleQuante">
                    <?php foreach ([10, 20, 30, 50] as $nq): ?>
                    <button type="button" class="fcai-chip<?php echo $nq === 20 ? ' is-on' : ''; ?>" data-v="<?php echo $nq; ?>"><?php echo $nq; ?></button>
                    <?php endforeach; ?>
                </div>
            </div>
            <div data-parole-pan="elenco" hidden>
                <textarea id="fcaiParoleElenco" spellcheck="false" placeholder="<?php echo sec_h(t('fc.vai_elenco_ph')); ?>"></textarea>
            </div>
            <div class="fcai-spunte">
                <label><input type="checkbox" id="fcaiParoleSig" checked> <?php te('fc.vai_con_significato'); ?></label>
                <label><input type="checkbox" id="fcaiParoleEs" checked> <?php te('fc.vai_con_esempio'); ?></label>
            </div>
            <input type="text" id="fcaiParoleInd" maxlength="400" placeholder="<?php echo sec_h(t('fc.vai_indicazioni_ph')); ?>">
            <div class="fcai-azioni">
                <button type="button" class="btn btn-primary ns-preme" id="fcaiBtnParole"><?php te('fc.vai_genera'); ?></button>
            </div>
            <div class="fcai-nota" id="fcaiParoleCosto"></div>
            <div class="fcai-nota" id="fcaiParoleNota"></div>
        </div></div></div>
    </section>
    <?php endif; ?>

    <section class="fcai-sec" data-sec="immagini">
        <button type="button" class="fcai-head" data-apri="immagini">
            <span class="fcai-ic">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="4" width="18" height="16" rx="2.5"/><circle cx="8.5" cy="9.5" r="1.6"/><path d="m4 17 4.5-4.5L12 16l3-3 5 5"/>
                </svg>
            </span>
            <span class="fcai-titoli">
                <b><?php te('fc.ibfai_sec_img_titolo'); ?></b>
                <span><?php echo $fcai_vocab ? sec_h(t('fcv.imm_parole_sub')) : sec_h(t('fc.ibfai_sec_img_sub')); ?></span>
            </span>
            <svg class="fcai-freccia" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m9 6 6 6-6 6"/></svg>
        </button>

        <div class="fcai-body"><div><div class="fcai-inner">
            <label class="fcai-label"><?php echo $fcai_vocab ? sec_h(t('fcv.imm_parole')) : sec_h(t('fc.ibfai_immagini_domande')); ?></label>
            <input type="file" id="question_images" accept="image/*" multiple>
            <?php if ($fcai_vocab): /* una parola ha un'immagine sola: il campo resta (lo legge il JS) ma vuoto e nascosto */ ?>
            <input type="file" id="answer_images" accept="image/*" multiple hidden style="display:none">
            <?php else: ?>
            <label class="fcai-label"><?php te('fc.ibfai_immagini_risposte'); ?></label>
            <input type="file" id="answer_images" accept="image/*" multiple>
            <?php endif; ?>
            <div class="fcai-nota" id="fcaiImgNota"></div>
            <div class="fcai-azioni">
                <button type="button" class="btn btn-primary" id="fcaiBtnImmagini"><?php te('fc.ibfai_crea_carte'); ?></button>
            </div>
        </div></div></div>
    </section>

    <!-- ============================================================
         IMPORTA
         ============================================================ -->
    <section class="fcai-sec" data-sec="importa">
        <button type="button" class="fcai-head" data-apri="importa">
            <span class="fcai-ic">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 3v12"/><path d="m8 11 4 4 4-4"/><path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"/>
                </svg>
            </span>
            <span class="fcai-titoli">
                <b><?php te('fc.ibfai_sec_importa_titolo'); ?></b>
                <span><?php te('fc.ibfai_sec_importa_sub'); ?></span>
            </span>
            <svg class="fcai-freccia" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m9 6 6 6-6 6"/></svg>
        </button>

        <div class="fcai-body"><div><div class="fcai-inner">

            <?php /* Una casella sola: si incolla quello che si ha (celle di Excel o
                     Fogli, un CSV, un JSON, righe "parola - traduzione") e sotto
                     compare cosa e' stato capito, colonna per colonna, con la
                     possibilita' di cambiarlo. La logica e' in fc_smart_import.js. */ ?>
            <textarea id="fcaiSmart" class="fcai-smart-in" spellcheck="false"
                      placeholder="<?php echo sec_h($fcai_vocab ? t('fc.smi_ph_vocab') : t('fc.smi_ph')); ?>"></textarea>
            <div class="fcai-smart-esito" id="fcaiSmartEsito" hidden aria-live="polite"></div>

            <?php /* I controlli di prima restano nascosti: il "copia il prompt"
                     dell'IA legge ancora i separatori scelti qui. */ ?>
            <div hidden style="display:none">
            <div class="fcai-chips" id="fcaiModiImportVecchi">
                <button type="button" class="fcai-chip is-on" data-modo="excel"><?php te('fc.ibfai_da_excel'); ?></button>
                <button type="button" class="fcai-chip" data-modo="testo"><?php te('fc.ibfai_da_testo'); ?></button>
            </div>

            <!-- Excel -->
            <div data-modo-pan="excel">
                <div class="fcai-nota">
                    <?php echo $fcai_vocab ? sec_h(t('fcv.excel_istruzioni')) : sec_h(t('fc.ibfai_excel_istruzioni')); ?>
                </div>
                <textarea id="fcaiExcel" placeholder="<?php echo sec_h($fcai_vocab ? t('fcv.excel_ph') : t('fc.ibfai_excel_ph')); ?>"></textarea>
            </div>

            <!-- testo con separatori, come prima -->
            <div data-modo-pan="testo" hidden>
                <label style="display:flex; gap:9px; align-items:center; font-size:14px;">
                    <input type="checkbox" id="format_switch" style="width:auto; flex:0 0 auto;">
                    <?php te('fc.ibfai_separatori_personalizzati'); ?>
                </label>
                <div class="fcai-riga" id="custom_separators_row" style="display:none; margin-top:10px;">
                    <div>
                        <label class="fcai-label"><?php te('fc.ibfai_fra_carte'); ?></label>
                        <select id="import_row_separator">
                            <option value="\n"><?php te('fc.ibfai_sep_a_capo'); ?></option>
                            <option value=";">;</option>
                            <option value="|">|</option>
                            <option value="###">###</option>
                        </select>
                    </div>
                    <div>
                        <label class="fcai-label"><?php echo $fcai_vocab ? sec_h(t('fcv.fra_campi')) : sec_h(t('fc.ibfai_fra_domanda_risposta')); ?></label>
                        <select id="import_separator">
                            <option value="\t"><?php te('fc.ibfai_sep_tab'); ?></option>
                            <option value=";">;</option>
                            <option value="-">-</option>
                            <option value="=">=</option>
                        </select>
                    </div>
                </div>
                <label class="fcai-label"><?php te('fc.ibfai_testo_lbl'); ?></label>
                <textarea id="import_text" placeholder="<?php echo sec_h($fcai_vocab ? t('fcv.import_text_ph') : t('fc.ibfai_import_text_ph')); ?>"></textarea>
            </div>

            </div>

            <div class="fcai-azioni">
                <button type="button" class="btn btn-primary" id="fcaiBtnImporta"><?php te('fc.ibfai_crea_carte'); ?></button>
            </div>
            <div class="fcai-nota" id="fcaiImportNota"></div>

        </div></div></div>
    </section>

</div>

<!-- ============================================================
     POPUP IMPOSTAZIONI / CONFERMA
     ============================================================
     Uno solo per due usi: aperto dal pannello mostra le sole scelte, aperto
     prima di generare mostra le stesse scelte piu' il riepilogo e il costo.
     Due popup separati avrebbero voluto dire due elenchi di interruttori da
     tenere allineati, e il secondo sarebbe rimasto indietro al primo ritocco. -->
<div class="fcai-modale" id="fcaiModale" hidden>
    <div class="fcai-modale-scheda">
        <div class="fcai-modale-tit">
            <b id="fcaiModaleTit"><?php te('fc.ibfai_impostazioni'); ?></b>
            <span id="fcaiModaleSub"><?php te('fc.ibfai_modale_impostazioni_sub'); ?></span>
        </div>

        <div class="fcai-modale-corpo">

            <!-- IMPEGNO — in cima apposta: e' la scelta che cambia di piu' il
                 prezzo, e nel popup di conferma il costo si ricalcola proprio
                 qui sotto mentre la si fa. -->
            <div class="fcai-opz-sep" style="margin-top:6px;"><?php te('fc.ibfai_impegno_sep'); ?></div>
            <?php fc_ai_tendina_impegno('fcaiImpegno', 'medio'); ?>
            <div class="fcai-nota" id="fcaiImpegnoDesc"><?php te('fc.ibfai_impegno_medio_desc'); ?></div>

            <div class="fcai-opz-sep"><?php te('fc.ibfai_sezione_contenuto'); ?></div>

            <label class="fcai-opz">
                <span class="fcai-opz-testo">
                    <b><?php te('fc.ibfai_opz_esempi_tit'); ?></b>
                    <small><?php te('fc.ibfai_opz_esempi_desc'); ?></small>
                </span>
                <input type="checkbox" id="fcaiOpzEsempi">
                <span class="fcai-lev"></span>
            </label>

            <label class="fcai-opz" data-dipende="fcaiOpzEsempi fcaiOpzGerarchia">
                <span class="fcai-opz-testo">
                    <b><?php te('fc.ibfai_opz_esempi_figli_tit'); ?></b>
                    <small><?php te('fc.ibfai_opz_esempi_figli_desc'); ?></small>
                </span>
                <input type="checkbox" id="fcaiOpzEsempiFigli">
                <span class="fcai-lev"></span>
            </label>

            <label class="fcai-opz">
                <span class="fcai-opz-testo">
                    <b><?php te('fc.ibfai_opz_gerarchia_tit'); ?></b>
                    <small><?php te('fc.ibfai_opz_gerarchia_desc'); ?></small>
                </span>
                <input type="checkbox" id="fcaiOpzGerarchia">
                <span class="fcai-lev"></span>
            </label>

            <!-- "Da dove prende le informazioni" ha senso solo quando c'e' una fonte
                 esterna da rispettare o meno: un file (puo' attenersi a quello o
                 integrare) o un elenco scritto a mano (puo' restare quello o
                 allargarsi). Da un argomento libero non c'e' nessuna fonte a cui
                 essere fedeli o meno, quindi la sezione intera sparisce. -->
            <div id="fcaiSezioneFonti" hidden>
                <div class="fcai-opz-sep"><?php te('fc.ibfai_sezione_fonti'); ?></div>

                <div data-fonte-impostazioni="file" hidden>
                    <label class="fcai-opz">
                        <span class="fcai-opz-testo">
                            <b><?php te('fc.ibfai_opz_integra_tit'); ?></b>
                            <small><?php te('fc.ibfai_opz_integra_desc'); ?></small>
                        </span>
                        <input type="checkbox" id="fcaiOpzIntegra">
                        <span class="fcai-lev"></span>
                    </label>

                    <label class="fcai-opz">
                        <span class="fcai-opz-testo">
                            <b><?php te('fc.ibfai_opz_corregge_tit'); ?></b>
                            <small><?php te('fc.ibfai_opz_corregge_desc'); ?></small>
                        </span>
                        <input type="checkbox" id="fcaiOpzCorregge">
                        <span class="fcai-lev"></span>
                    </label>

                    <label class="fcai-opz">
                        <span class="fcai-opz-testo">
                            <b><?php te('fc.ibfai_opz_testuale_tit'); ?></b>
                            <small><?php te('fc.ibfai_opz_testuale_desc'); ?></small>
                        </span>
                        <input type="checkbox" id="fcaiOpzTestuale">
                        <span class="fcai-lev"></span>
                    </label>
                </div>

                <div data-fonte-impostazioni="elenco" hidden>
                    <label class="fcai-opz">
                        <span class="fcai-opz-testo">
                            <b><?php te('fc.ibfai_opz_solo_elenco_tit'); ?></b>
                            <small><?php te('fc.ibfai_opz_solo_elenco_desc'); ?></small>
                        </span>
                        <input type="checkbox" id="fcaiSoloElenco">
                        <span class="fcai-lev"></span>
                    </label>
                </div>
            </div>

            <!-- COME SCRIVERE LE CARTE — tutto parte da "Automatico", che vuol
                 dire "come prima": nessuna riga in piu' nel prompt e nessun
                 controllo in piu'. Ogni scelta fatta qui invece viene
                 VERIFICATA sulla risposta, e una carta che non la rispetta
                 viene fatta riscrivere con il motivo preciso. -->
            <div class="fcai-opz-sep"><?php te('fc.ibfai_stile_sep'); ?></div>
            <div class="fcai-nota" style="margin-top:0;"><?php te('fc.ibfai_stile_sub'); ?></div>
            <div class="fcai-stile" id="fcaiStile">
                <?php foreach (fc_ai_stile_valori() as $campo => $valori): ?>
                    <label class="fcai-stile-campo">
                        <span class="fcai-label"><?php te('fc.ibfai_stile_' . $campo); ?></span>
                        <select data-stile="<?php echo $campo; ?>">
                            <?php foreach ($valori as $v): ?>
                                <option value="<?php echo $v; ?>"><?php te($v === 'auto' ? 'fc.ibfai_stile_auto' : 'fc.ibfai_stile_' . $campo . '_' . $v); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                <?php endforeach; ?>
            </div>
            <div id="fcaiStileSchema" hidden>
                <label class="fcai-label"><?php te('fc.ibfai_stile_etichette_lbl'); ?></label>
                <textarea id="fcaiStileEtichette" placeholder="<?php echo sec_h(t('fc.ibfai_stile_etichette_ph')); ?>" style="min-height:84px;"></textarea>
            </div>
            <details class="fcai-modello" id="fcaiModello">
                <summary><?php te('fc.ibfai_stile_modello_sum'); ?></summary>
                <div class="fcai-nota" style="margin-top:6px;"><?php te('fc.ibfai_stile_modello_desc'); ?></div>
                <textarea id="fcaiModelloQ" placeholder="<?php echo sec_h(t('fc.ibfai_stile_modello_q_ph')); ?>" style="min-height:44px;"></textarea>
                <textarea id="fcaiModelloA" placeholder="<?php echo sec_h(t('fc.ibfai_stile_modello_a_ph')); ?>" style="min-height:110px;"></textarea>
            </details>
            <button type="button" class="btn btn-ghost btn-sm" id="fcaiStileAzzera" style="margin-top:10px;"><?php te('fc.ibfai_stile_ripristina'); ?></button>

            <?php /* Da qui si va a vedere cosa si e' speso: e' la domanda che
                     viene subito dopo "quanto mi costa questa generazione", ed
                     e' giusto che la risposta sia a un tocco.
                     Un tocco, non un viaggio: si apre un popup sopra a quello
                     che si stava facendo. Cambiare pagina qui vorrebbe dire
                     perdere l'elenco dei concetti, le impostazioni appena
                     scelte e, se sta girando, la generazione in corso. */ ?>
            <button type="button" class="fcai-link-crediti" id="fcaiApriCrediti"><?php te('fc.cred_link'); ?></button>

            <div class="fcai-modale-conto" id="fcaiModaleConto" hidden></div>
        </div>

        <div class="fcai-modale-piede">
            <button type="button" class="btn btn-ghost btn-sm" id="fcaiModaleNo"><?php te('fc.ibfai_annulla'); ?></button>
            <button type="button" class="btn btn-primary btn-sm" id="fcaiModaleSi"><?php te('fc.ibfai_fatto'); ?></button>
        </div>
    </div>
</div>

<!-- ============================================================
     I TUOI CREDITI (popup)
     ============================================================
     Il contenuto arriva da credits.php?riquadro=1, cioe' dalla stessa pagina
     che si apre anche per intero: un elenco di movimenti scritto due volte
     sarebbe diventato diverso al primo ritocco. Si carica quando si apre e
     non prima — chi non lo guarda non paga nessuna query. -->
<?php
echo '<link rel="stylesheet" href="' . PWA_BASE . 'ai/credits.css?v='
   . (@filemtime(__DIR__ . '/../ai/credits.css') ?: 1) . '">' . "\n";
?>
<div class="fcai-modale" id="fcaiCreditiModale" hidden>
    <div class="fcai-modale-scheda">
        <div class="fcai-modale-tit">
            <b><?php te('fc.cred_titolo'); ?></b>
        </div>
        <div class="fcai-modale-corpo" id="fcaiCreditiCorpo"></div>
        <div class="fcai-modale-piede">
            <a class="btn btn-ghost btn-sm" href="<?php echo PWA_BASE; ?>ai/credits.php"><?php te('fc.cred_apri_pagina'); ?></a>
            <button type="button" class="btn btn-primary btn-sm" id="fcaiCreditiChiudi"><?php te('fc.ibfai_job_chiudi_btn'); ?></button>
        </div>
    </div>
</div>

<!-- ============================================================
     RIGENERA TUTTE LE CARTE
     ============================================================
     Popup a se', non il popup impostazioni/conferma: qui non ci sono scelte
     da rivedere (quelle restano quelle di prima), solo la possibilita' di
     dare un'indicazione in piu' prima di ripartire dallo stesso elenco. -->
<div class="fcai-modale" id="fcaiRigeneraModale" hidden>
    <div class="fcai-modale-scheda">
        <div class="fcai-modale-tit">
            <b><?php te('fc.ibfai_rigenera_tutte_tit'); ?></b>
            <span><?php te('fc.ibfai_rigenera_modale_sub'); ?></span>
        </div>
        <div class="fcai-modale-corpo">
            <label class="fcai-label"><?php te('fc.ibfai_rigenera_nota_lbl'); ?></label>
            <textarea id="fcaiRigeneraNota" placeholder="<?php echo sec_h(t('fc.ibfai_rigenera_nota_ph')); ?>"></textarea>
        </div>
        <div class="fcai-modale-piede">
            <button type="button" class="btn btn-ghost btn-sm" id="fcaiRigeneraNo"><?php te('fc.ibfai_annulla'); ?></button>
            <button type="button" class="btn btn-primary btn-sm" id="fcaiRigeneraSi"><?php te('fc.ibfai_rigenera_bottone'); ?></button>
        </div>
    </div>
</div>

<?php /* La data di modifica in coda all'indirizzo: senza, il browser continua a
         usare la copia che ha gia' in cache anche quando sul server c'e' un
         file nuovo. Su hosting con scadenze lunghe (Altervista) questo vuol
         dire HTML nuovo + JavaScript vecchio, e il risultato e' un pezzo di
         interfaccia disegnato ma morto: nessun errore in console, perche' il
         codice vecchio cerca elementi che non esistono piu' e non trova
         niente da agganciare. E' esattamente cosi' che la tendina
         dell'impegno e' arrivata vuota e non cliccabile. */ ?>
<script src="fc_ai_client.js?v=<?php echo @filemtime(__DIR__ . '/fc_ai_client.js') ?: 1; ?>"></script>
<script src="fc_ai_match.js?v=<?php echo @filemtime(__DIR__ . '/fc_ai_match.js') ?: 1; ?>"></script>

<!-- ============================================================
     MIGLIORA UNA CARTA CON L'IA
     ============================================================
     Aperto dal tasto nel piede di OGNI riga della tabella — anche una vuota,
     anche una scritta a mano: non serve essere nati dall'estrazione IA. Su
     una carta con contenuto, l'IA lo riscrive spiegando lo stesso concetto in
     un altro modo; su una carta vuota, le indicazioni qui sotto sono l'unica
     traccia da cui partire. -->
<div class="fcai-modale" id="fcaiMigliora" hidden>
    <div class="fcai-modale-scheda">
        <div class="fcai-modale-tit">
            <b id="fcaiMiglioraTit"><?php te('fc.ibfai_migliora_titolo'); ?></b>
            <span id="fcaiMiglioraSub"><?php te('fc.ibfai_migliora_sub_default'); ?></span>
        </div>
        <div class="fcai-modale-corpo">
            <div class="fcai-migliora-anteprima" id="fcaiMiglioraAnteprima" hidden></div>

            <!-- Pastiglie rapide: solo quando la carta ha gia' una risposta.
                 Toccarne una scrive l'indicazione al posto dell'utente nel
                 campo sotto, che resta comunque modificabile — non e' un
                 invio diretto, solo una scorciatoia per non dover scrivere. -->
            <div class="fcai-migliora-chips" id="fcaiMiglioraChips" hidden>
                <button type="button" class="fcai-chip-mini" data-nota="<?php echo sec_h(t('fc.ibfai_nota_precisa')); ?>"><?php te('fc.ibfai_chip_precisa'); ?></button>
                <button type="button" class="fcai-chip-mini" data-nota="<?php echo sec_h(t('fc.ibfai_nota_semplice')); ?>"><?php te('fc.ibfai_chip_semplice'); ?></button>
                <button type="button" class="fcai-chip-mini" data-nota="<?php echo sec_h(t('fc.ibfai_nota_breve')); ?>"><?php te('fc.ibfai_chip_breve'); ?></button>
                <button type="button" class="fcai-chip-mini" data-nota="<?php echo sec_h(t('fc.ibfai_nota_dettagliata')); ?>"><?php te('fc.ibfai_chip_dettagliata'); ?></button>
            </div>

            <label class="fcai-label"><?php te('fc.ibfai_migliora_indicazioni_lbl'); ?></label>
            <textarea id="fcaiMiglioraNota" placeholder="<?php echo sec_h(t('fc.ibfai_migliora_nota_ph_default')); ?>"></textarea>

            <label class="fcai-opz" style="border-bottom:none; padding-bottom:0;">
                <span class="fcai-opz-testo"><b><?php te('fc.ibfai_migliora_esempio'); ?></b></span>
                <input type="checkbox" id="fcaiMiglioraEsempio">
                <span class="fcai-lev"></span>
            </label>

            <div class="fcai-modale-conto is-warn" id="fcaiMiglioraErrore" hidden></div>
        </div>
        <div class="fcai-modale-piede">
            <button type="button" class="btn btn-ghost btn-sm" id="fcaiMiglioraNo"><?php te('fc.ibfai_annulla'); ?></button>
            <button type="button" class="btn btn-primary btn-sm" id="fcaiMiglioraSi"><?php te('fc.ibfai_migliora_bottone'); ?></button>
        </div>
    </div>
</div>
<!-- ============================================================
     GENERAZIONE IN CORSO
     ============================================================
     La schermata del motore dei job (fc_ai_job.js). Resta aperta mentre il
     lavoro avanza, si riduce a una pastiglia in basso per continuare a
     lavorare sulle carte gia' arrivate, e si riapre toccandola. -->
<div class="fcai-modale" id="fcajModale" hidden>
    <div class="fcai-modale-scheda">
        <div class="fcai-modale-tit">
            <b id="fcajTit"><?php te('fc.ibfai_job_tit'); ?></b>
            <span id="fcajFase"></span>
        </div>
        <div class="fcai-modale-corpo">
            <div class="fcai-barra fcaj-barra" id="fcajBarra"><i style="width:0"></i></div>
            <div class="fcaj-righe">
                <span id="fcajTempo"></span>
                <span id="fcajCrediti"></span>
            </div>
            <div class="fcai-modale-conto" id="fcajNota" role="status" aria-live="polite"></div>
        </div>
        <div class="fcai-modale-piede">
            <button type="button" class="btn btn-ghost btn-sm" id="fcajAnnulla"><?php te('fc.ibfai_job_annulla_btn'); ?></button>
            <button type="button" class="btn btn-primary btn-sm" id="fcajRiduci"><?php te('fc.ibfai_job_riduci_btn'); ?></button>
            <button type="button" class="btn btn-primary btn-sm" id="fcajRiprendi" hidden><?php te('fc.ibfai_job_riprendi_btn'); ?></button>
            <button type="button" class="btn btn-primary btn-sm" id="fcajRiprova" hidden><?php te('fc.ibfai_job_riprova_btn'); ?></button>
            <button type="button" class="btn btn-ghost btn-sm" id="fcajChiudi" hidden><?php te('fc.ibfai_job_chiudi_btn'); ?></button>
        </div>
    </div>
</div>

<button type="button" class="fcaj-pillola" id="fcajPillola" hidden>
    <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M9 2 11 7 16 9 11 11 9 16 7 11 2 9 7 7Z"/></svg>
    <span id="fcajPillolaTesto"></span>
    <b class="fcaj-pillola-barra"><i style="width:0"></i></b>
</button>

<?php $fcaj_v = @filemtime(__DIR__ . '/fc_ai_job.js') ?: 1; ?>
<script src="fc_ai_job.js?v=<?php echo $fcaj_v; ?>"></script>
<script src="fc_smart_import.js?v=<?php echo @filemtime(__DIR__ . '/fc_smart_import.js') ?: 1; ?>"></script>
<script src="fc_ai_panel.js?v=<?php echo @filemtime(__DIR__ . '/fc_ai_panel.js') ?: 1; ?>"></script>
