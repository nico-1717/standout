<?php
/**
 * fc_ai_pannello.php — il pannello di inserimento carte.
 *
 * Si include con una riga sola dentro inserimento_big_fc.php:
 *
 *     <?php include 'fc_ai_pannello.php'; ?>
 *
 * Sostituisce, in quella pagina: il riquadro del prompt da copiare
 * (.format-help), la scheda "Immagini (opzionale)" e la scheda dei separatori
 * dentro .setup-grid, e il pulsante #btn-generate-table. La scheda "Categoria
 * di destinazione" NON si tocca: non c'entra con l'import.
 *
 * PERCHE' UN FILE A PARTE
 * inserimento_big_fc.php e' 77 KB: ogni modifica la' dentro e' un rischio che
 * non serve correre. Qui il pannello nasce isolato, e l'unico punto di contatto
 * con la pagina sono quattro funzioni che gia' esistono e che non tocchiamo:
 * appendRowHTML(), markDirty(), showToast(), showConfirm().
 *
 * LINGUE
 * I testi passano dal dizionario del sito: te()/t() lato PHP in questo file,
 * T() lato JS in fc_ai_pannello.js e fc_ai_abbina.js. Le chiavi nuove
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
$fcai_css_v = @filemtime(__DIR__ . '/fc_ai_pannello.css') ?: 1;
?>
<link rel="stylesheet" href="fc_ai_pannello.css?v=<?php echo $fcai_css_v; ?>">

<div class="fcai-panel" id="fcaiPanel">

    <!-- ============================================================
         GENERAZIONE CON L'INTELLIGENZA ARTIFICIALE
         ============================================================ -->
    <section class="fcai-sec" data-sec="ia" id="fcaiSecIa">
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
                <label class="fcai-label"><?php te('fc.ibfai_indicazioni_lbl'); ?></label>
                <textarea id="fcaiIndicazioni" placeholder="<?php echo sec_h(t('fc.ibfai_indicazioni_ph')); ?>" style="min-height:80px;"></textarea>
            </div>

            <button type="button" class="fcai-riep" id="fcaiApriImpostazioni">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M4 6h10M18 6h2M4 12h2M10 12h10M4 18h10M18 18h2"/>
                    <circle cx="16" cy="6" r="2"/><circle cx="8" cy="12" r="2"/><circle cx="16" cy="18" r="2"/>
                </svg>
                <span id="fcaiRiepilogo"><?php te('fc.ibfai_impostazioni'); ?></span>
                <svg class="fcai-freccia" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m9 6 6 6-6 6"/></svg>
            </button>

            <div class="fcai-azioni">
                <button type="button" class="btn btn-primary" id="fcaiBtnConcetti">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 6h16M4 12h11M4 18h7"/></svg>
                    <?php te('fc.ibfai_trova_concetti'); ?>
                </button>
            </div>
            <div class="fcai-nota" id="fcaiStato"></div>

            <!-- elenco dei concetti + generazione -->
            <div id="fcaiRisultato" hidden>
                <div class="fcai-elenco" id="fcaiElenco"></div>
                <div class="fcai-azioni">
                    <button type="button" class="btn btn-ghost btn-sm" id="fcaiMeno"><?php te('fc.ibfai_meno_concetti'); ?></button>
                    <button type="button" class="btn btn-ghost btn-sm" id="fcaiPiu"><?php te('fc.ibfai_piu_concetti'); ?></button>
                </div>
                <div class="fcai-azioni">
                    <button type="button" class="btn btn-success" id="fcaiBtnCarte">
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
    <section class="fcai-sec" data-sec="immagini">
        <button type="button" class="fcai-head" data-apri="immagini">
            <span class="fcai-ic">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="4" width="18" height="16" rx="2.5"/><circle cx="8.5" cy="9.5" r="1.6"/><path d="m4 17 4.5-4.5L12 16l3-3 5 5"/>
                </svg>
            </span>
            <span class="fcai-titoli">
                <b><?php te('fc.ibfai_sec_img_titolo'); ?></b>
                <span><?php te('fc.ibfai_sec_img_sub'); ?></span>
            </span>
            <svg class="fcai-freccia" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m9 6 6 6-6 6"/></svg>
        </button>

        <div class="fcai-body"><div><div class="fcai-inner">
            <label class="fcai-label"><?php te('fc.ibfai_immagini_domande'); ?></label>
            <input type="file" id="question_images" accept="image/*" multiple>
            <label class="fcai-label"><?php te('fc.ibfai_immagini_risposte'); ?></label>
            <input type="file" id="answer_images" accept="image/*" multiple>
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

            <div class="fcai-chips" id="fcaiModiImport">
                <button type="button" class="fcai-chip is-on" data-modo="excel"><?php te('fc.ibfai_da_excel'); ?></button>
                <button type="button" class="fcai-chip" data-modo="testo"><?php te('fc.ibfai_da_testo'); ?></button>
            </div>

            <!-- Excel -->
            <div data-modo-pan="excel">
                <div class="fcai-nota">
                    <?php te('fc.ibfai_excel_istruzioni'); ?>
                </div>
                <textarea id="fcaiExcel" placeholder="<?php echo sec_h(t('fc.ibfai_excel_ph')); ?>"></textarea>
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
                        <label class="fcai-label"><?php te('fc.ibfai_fra_domanda_risposta'); ?></label>
                        <select id="import_separator">
                            <option value="\t"><?php te('fc.ibfai_sep_tab'); ?></option>
                            <option value=";">;</option>
                            <option value="-">-</option>
                            <option value="=">=</option>
                        </select>
                    </div>
                </div>
                <label class="fcai-label"><?php te('fc.ibfai_testo_lbl'); ?></label>
                <textarea id="import_text" placeholder="<?php echo sec_h(t('fc.ibfai_import_text_ph')); ?>"></textarea>
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

            <div class="fcai-modale-conto" id="fcaiModaleConto" hidden></div>
        </div>

        <div class="fcai-modale-piede">
            <button type="button" class="btn btn-ghost btn-sm" id="fcaiModaleNo"><?php te('fc.ibfai_annulla'); ?></button>
            <button type="button" class="btn btn-primary btn-sm" id="fcaiModaleSi"><?php te('fc.ibfai_fatto'); ?></button>
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

<script src="fc_ai_client.js"></script>
<script src="fc_ai_abbina.js"></script>

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
<script src="fc_ai_pannello.js"></script>
