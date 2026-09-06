<?php

/*

 * format_toolbar.php — editor WYSIWYG "plug & play" con toolbar di formattazione.

 *

 * Uso, prima dell'include:

 *   $ft_field       (obbligatorio) nome del campo finale, es. 'quest' o 'answ'

 *                    -> genera <input type="hidden" name="$ft_field" id="$ft_field">

 *                       già pronto per essere letto lato server come sempre

 *   $ft_raw         (opzionale) testo grezzo iniziale da idratare (es. $row['question_desc'])

 *   $ft_placeholder (opzionale) placeholder mostrato a editor vuoto

 *

 * Il campo hidden risultante contiene SEMPRE il testo nel formato a tag storico

 * (/bold{}, /list{}, $formula$...) letto e scritto da fc_parser.js: nessun cambio

 * nel formato dati salvato a DB, cambia solo l'esperienza di modifica.

 *

 * Si può includere più volte nella stessa pagina (una per campo): CSS e motore JS

 * vengono emessi una sola volta, il markup dell'editor invece una volta per include.

 */



if (!isset($ft_field)) $ft_field = 'quest';

if (!isset($ft_raw)) $ft_raw = '';

if (!isset($ft_placeholder)) $ft_placeholder = 'Scrivi qui…';
/* Aperta o chiusa al caricamento. Predefinito: aperta, perché è com'era prima
   ovunque e nessuna pagina esistente deve cambiare comportamento senza che
   qualcuno lo chieda. Chi la vuole chiusa — progetti, dove la nota è un
   dettaglio — imposta $ft_open = false prima dell'include. */
if (!isset($ft_open)) $ft_open = true;

$ft_editor_id = $ft_field . '_editor';



if (!defined('FC_PARSER_JS_INCLUDED')) {
    define('FC_PARSER_JS_INCLUDED', true);
    echo "<script>
        // Controllo intelligente: inietta lo script solo se non esiste già nel DOM
        if (!document.querySelector('script[src*=\"fc_parser.js\"]')) {
            var script = document.createElement('script');
            script.src = '" . PWA_BASE . "fc_parser.js';
            document.head.appendChild(script);
        }
        /* MathQuill: la modifica interattiva delle formule a blocco (lo stesso
           motore di Desmos). Vuole jQuery, quindi l'ordine conta: prima
           jQuery, e solo quando e' arrivato si chiede MathQuill. Se uno dei
           due non arriva non succede niente di male — l'editor se ne accorge
           da solo e resta al LaTeX scritto a mano, come prima. */
        if (!document.querySelector('link[href*=\"mathquill.css\"]')) {
            var mqCss = document.createElement('link');
            mqCss.rel = 'stylesheet';
            mqCss.href = '" . PWA_BASE . "mathquill/mathquill.css';
            document.head.appendChild(mqCss);
        }
        if (!window.jQuery && !document.querySelector('script[src*=\"jquery.min.js\"]')) {
            var jq = document.createElement('script');
            jq.src = '" . PWA_BASE . "mathquill/jquery.min.js';
            jq.onload = function () { caricaMathQuill(); };
            document.head.appendChild(jq);
        } else {
            caricaMathQuill();
        }
        function caricaMathQuill() {
            if (window.MathQuill) return;
            if (document.querySelector('script[src*=\"mathquill.min.js\"]')) return;
            var mq = document.createElement('script');
            mq.src = '" . PWA_BASE . "mathquill/mathquill.min.js';
            document.head.appendChild(mq);
        }
    </script>\n";
}

?>

<div class="fmt-editor-wrap<?= $ft_open ? '' : ' fmt-chiusa' ?>" data-editor-id="<?= htmlspecialchars($ft_editor_id) ?>" data-field="<?= htmlspecialchars($ft_field) ?>" data-raw="<?= htmlspecialchars($ft_raw, ENT_QUOTES) ?>">

    <!-- ============================================================
         LA BARRA, IN DUE SEZIONI (impianto GoodNotes)

         Sopra: le famiglie di strumenti. Un tocco apre la sezione di sotto,
         un altro tocco sullo stesso tasto la richiude — il tasto resta
         acceso finché il suo pannello è aperto, così si vede sempre da dove
         è uscito quello che si sta guardando.

         Sotto: il pannello del momento. Non compare dal nulla: scorre fuori
         da dietro la barra di sopra, e rientrandoci sparisce. È il motivo
         per cui il cassetto ha overflow:hidden e il pannello parte da
         translateY(-100%): quello che si vede è un foglio che scivola da
         sotto l'altro, non un blocco che appare.
         ============================================================ -->
    <div class="fmt-toolbar" id="<?= htmlspecialchars($ft_editor_id) ?>_bar" data-target="<?= htmlspecialchars($ft_editor_id) ?>">

        <div class="fmt-row">

            <!-- Il tastino sta DENTRO la barra, primo a sinistra: e' il perno
                 attorno a cui il resto si srotola e si riavvolge. Fuori, la
                 barra sembrava sparire e ricomparire; qui si vede sempre dove
                 e' andata a finire. -->
            <button type="button" class="fmt-switch" aria-expanded="<?= $ft_open ? 'true' : 'false' ?>"
                    aria-controls="<?= htmlspecialchars($ft_editor_id) ?>_bar" title="Formattazione">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M4 6h9M4 12h6M4 18h9"/><path d="M15.5 15.5l5-5"/><path d="M18 8l3 3-6.5 6.5-3.5.5.5-3.5z"/>
                </svg>
                <span class="fmt-switch-pallino" aria-hidden="true"></span>
            </button>

            <div class="fmt-tools">

            <!-- TESTO — apre grassetto/corsivo/sottolineato/evidenziatore + colori -->
            <button type="button" class="fmt-btn fmt-tab" data-panel="font" title="Testo" aria-expanded="false">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M4.8 16.6L10.4 4.6h1.5l5.6 12"/>
                    <path d="M7.1 12.6h8.1"/>
                    <path d="M4.4 20.2h15.2" opacity=".45"/>
                </svg>
            </button>

            <!-- ELENCO — apre rientri, a capo e colore dei pallini -->
            <button type="button" class="fmt-btn fmt-tab" data-panel="list" title="Elenco" aria-expanded="false">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="4.6" cy="6.5" r="1.35" fill="currentColor" stroke="none"/><path d="M9.5 6.5h10.4"/>
                    <circle cx="4.6" cy="12" r="1.35" fill="currentColor" stroke="none"/><path d="M9.5 12h10.4"/>
                    <circle cx="4.6" cy="17.5" r="1.35" fill="currentColor" stroke="none"/><path d="M9.5 17.5h10.4"/>
                </svg>
            </button>

            <!-- FORMULA — apre in linea / a blocco -->
            <button type="button" class="fmt-btn fmt-tab" data-panel="math" title="Formula" aria-expanded="false">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M2.8 12.9l2.3 1.2 2.4-8.4h13.7"/>
                    <path d="M11.8 11.2l5.4 6.6"/>
                    <path d="M17.2 11.2l-5.4 6.6"/>
                </svg>
            </button>

            <span class="fmt-sep"></span>

            <!-- DETTATURA. Nascosta dove il riconoscimento vocale non esiste:
                 un tasto che non puo' funzionare e' peggio di nessun tasto. -->
            <button type="button" class="fmt-btn fmt-mic fmt-tab" data-panel="mic" data-cmd="dictate" title="Detta invece di scrivere" aria-expanded="false" hidden>
                <svg viewBox="0 0 24 24" width="19" height="19" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="9.1" y="3" width="5.8" height="10.6" rx="2.9"/>
                    <path d="M5.6 11.4a6.4 6.4 0 0012.8 0"/>
                    <path d="M12 17.8V21"/>
                </svg>
            </button>

            </div><!-- /fmt-tools -->
        </div>

        <!-- il cassetto: alto zero da chiuso, la sua altezza la misura il JS -->
        <div class="fmt-drawer">

            <!-- ---------- TESTO ---------- -->
            <div class="fmt-panel" data-panel="font" hidden>
                <div class="fmt-group">
                    <button type="button" class="fmt-btn" data-cmd="bold" title="Grassetto"><b>B</b></button>
                    <button type="button" class="fmt-btn" data-cmd="italic" title="Corsivo"><i>I</i></button>
                    <button type="button" class="fmt-btn" data-cmd="underline" title="Sottolineato"><u>U</u></button>
                    <!-- L'evidenziatore di GoodNotes: pennarello inclinato, punta
                         a scalpello, e la striscia che lascia sotto. -->
                    <button type="button" class="fmt-btn" data-cmd="yellow" title="Evidenziatore">
                        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M14.6 3.9l5.5 5.5-7.7 7.7-5.5-5.5z"/>
                            <path d="M6.9 11.6l-1.6 3.1 3.9 3.9 3.2-1.5"/>
                            <path d="M5.3 14.7l-1.4 3.6 3.6-1.4"/>
                            <path d="M4 21.4h16" stroke="var(--yellow,#FFCC00)" stroke-width="2.6"/>
                        </svg>
                    </button>
                </div>

                <span class="fmt-sep"></span>

                <!-- I colori scorrono: ce ne stanno quanti se ne vuole senza
                     che la barra vada a capo o si stringa. -->
                <div class="fmt-swatches" role="group" aria-label="Colore del testo">
                    <button type="button" class="fmt-pop-dot fmt-pop-dot-default" data-color="" title="Predefinito">
                        <svg viewBox="0 0 24 24" width="11" height="11" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>
                    </button>
                    <button type="button" class="fmt-pop-dot" data-color="red"    style="background:var(--red,#FF3B30)"      title="Rosso"></button>
                    <button type="button" class="fmt-pop-dot" data-color="orange" style="background:var(--orange,#FF9500)"   title="Arancione"></button>
                    <button type="button" class="fmt-pop-dot" data-color="green"  style="background:var(--green,#34C759)"    title="Verde"></button>
                    <button type="button" class="fmt-pop-dot" data-color="blue"   style="background:var(--blue,#007AFF)"     title="Blu"></button>
                    <button type="button" class="fmt-pop-dot" data-color="purple" style="background:var(--purple,#AF52DE)"   title="Viola"></button>
                </div>
            </div>

            <!-- ---------- ELENCO ---------- -->
            <div class="fmt-panel" data-panel="list" hidden>
                <div class="fmt-group">
                    <!-- Rientro a sinistra: righe e un triangolo che dice da che
                         parte va. Sono gli stessi delle tue immagini. -->
                    <button type="button" class="fmt-btn" data-cmd="outdent" title="Riduci livello elenco">
                        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M3.8 5.2h16.4"/><path d="M3.8 9.7h9.6"/><path d="M3.8 14.3h9.6"/><path d="M3.8 18.8h16.4"/>
                            <path d="M20.2 9.4l-3.6 2.6 3.6 2.6z" fill="currentColor" stroke="none"/>
                        </svg>
                    </button>
                    <button type="button" class="fmt-btn" data-cmd="indent" title="Crea sotto-punto (livello successivo)">
                        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M3.8 5.2h16.4"/><path d="M10.6 9.7h9.6"/><path d="M10.6 14.3h9.6"/><path d="M3.8 18.8h16.4"/>
                            <path d="M3.8 9.4l3.6 2.6-3.6 2.6z" fill="currentColor" stroke="none"/>
                        </svg>
                    </button>
                    <!-- A capo senza aprire un punto nuovo: la riga che continua
                         sotto lo stesso pallino. -->
                    <button type="button" class="fmt-btn" data-cmd="soft-break" title="A capo (senza nuovo punto)">
                        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="4.6" cy="6.8" r="1.35" fill="currentColor" stroke="none"/>
                            <path d="M9.5 6.8h10.4"/>
                            <path d="M20 12.4v2.2a2 2 0 01-2 2H10"/>
                            <path d="M12.6 14.1L9.9 16.6l2.7 2.5" fill="none"/>
                        </svg>
                    </button>
                </div>

                <span class="fmt-sep"></span>

                <div class="fmt-swatches" role="group" aria-label="Colore dei pallini">
                    <button type="button" class="fmt-pop-dot fmt-pop-dot-default" data-list-color="" title="Predefinito">
                        <svg viewBox="0 0 24 24" width="11" height="11" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>
                    </button>
                    <button type="button" class="fmt-pop-dot" data-list-color="red"    style="background:var(--red,#FF3B30)"    title="Rosso"></button>
                    <button type="button" class="fmt-pop-dot" data-list-color="orange" style="background:var(--orange,#FF9500)" title="Arancione"></button>
                    <button type="button" class="fmt-pop-dot" data-list-color="green"  style="background:var(--green,#34C759)"  title="Verde"></button>
                    <button type="button" class="fmt-pop-dot" data-list-color="blue"   style="background:var(--blue,#007AFF)"   title="Blu"></button>
                    <button type="button" class="fmt-pop-dot" data-list-color="purple" style="background:var(--purple,#AF52DE)" title="Viola"></button>
                    <button type="button" class="fmt-pop-dot" data-list-color="gray"   style="background:var(--text-2,#8A8A8E)" title="Grigio"></button>
                </div>
            </div>

            <!-- ---------- DETTATURA ---------- -->
            <!-- Le due cose che servono davvero mentre si detta: in che lingua
                 si sta parlando, e se la punteggiatura la mette il programma o
                 la si detta a voce. Stanno qui sotto perche' si cambiano a
                 microfono acceso, senza fermarsi. -->
            <div class="fmt-panel" data-panel="mic" hidden>
                <!-- Un tasto solo con la lingua in uso: sette sigle in fila
                     occupavano tutta la barra per un'impostazione che si tocca
                     una volta ogni tanto. Le altre lingue stanno un piano piu'
                     sotto, che si apre solo se serve. -->
                <button type="button" class="fmt-btn fmt-btn-wide fmt-lingua-btn" data-panel2="lingue" aria-expanded="false">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="8.6"/><path d="M3.4 12h17.2"/>
                        <path d="M12 3.4c2.2 2.4 3.3 5.4 3.3 8.6s-1.1 6.2-3.3 8.6c-2.2-2.4-3.3-5.4-3.3-8.6S9.8 5.8 12 3.4z"/>
                    </svg>
                    <span class="fmt-btn-lab fmt-lingua-ora">IT</span>
                </button>

                <span class="fmt-sep"></span>

                <!-- Punteggiatura: solo il disegno. La parola per esteso
                     allargava la barra piu' della riga di sopra. -->
                <button type="button" class="fmt-btn fmt-punt" data-cmd="punteggiatura"
                        title="Punteggiatura automatica" aria-pressed="false">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor" stroke="none">
                        <circle cx="4.4" cy="16.6" r="1.5"/>
                        <path d="M9.6 15.1a1.5 1.5 0 110 3c-.2 0-.4 0-.6-.1l.5 1.7-1 .3-.7-2.4a1.5 1.5 0 011.8-2.5z"/>
                        <circle cx="15" cy="9.2" r="1.5"/><circle cx="15" cy="16.6" r="1.5"/>
                        <rect x="18.9" y="6.2" width="2.6" height="7.6" rx="1.3"/>
                        <circle cx="20.2" cy="16.6" r="1.5"/>
                    </svg>
                </button>

                <!-- Comandi vocali: acceso, sotto compaiono le parole che il
                     programma sta ascoltando in questo momento. Non e' una
                     decorazione: dettando non si vede niente, e senza l'elenco
                     davanti nessuno indovinerebbe che dire. -->
                <button type="button" class="fmt-btn fmt-comandi-btn" data-cmd="comandi"
                        title="Comandi vocali" aria-pressed="false">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M4 8.5v7"/><path d="M8 5.5v13"/><path d="M12 9.5v5"/><path d="M16 4.5v15"/><path d="M20 8.5v7"/>
                    </svg>
                </button>
            </div>

            <!-- ---------- FORMULA ---------- -->
            <div class="fmt-panel" data-panel="math" hidden>
                <div class="fmt-group">
                    <button type="button" class="fmt-btn fmt-btn-wide" data-cmd="math-inline" title="Formula in linea">
                        <span class="fmt-ix">x</span>
                        <span class="fmt-btn-lab">in linea</span>
                    </button>
                    <button type="button" class="fmt-btn fmt-btn-wide" data-cmd="math-display" title="Formula evidenziata, a capo">
                        <span class="fmt-blockmath"><span class="fmt-ix">x</span></span>
                        <span class="fmt-btn-lab">a blocco</span>
                    </button>
                </div>

                <span class="fmt-sep"></span>

                <!-- Interruttore: scrivendo $...$ o $$...$$ si crea la formula
                     al volo, senza passare dai due tasti sopra. Spento per
                     difetto: chi scrive un prezzo ("costa 5$") non si aspetta
                     che il dollaro faccia altro che se stesso. -->
                <button type="button" class="fmt-btn fmt-dollari" data-cmd="dollari-auto"
                        title="Scrivendo $...$ o $$...$$ si crea subito la formula" aria-pressed="false">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 3.5v17"/>
                        <path d="M16.4 7.3c0-1.9-2-3.1-4.4-3.1s-4.4 1.2-4.4 3.1c0 4.1 8.8 2.4 8.8 6.5 0 1.9-2 3.1-4.4 3.1s-4.4-1.2-4.4-3.1"/>
                    </svg>
                </button>

                <!-- Interruttore: riconosce da solo un comando LaTeX appena
                     digitato ("\omega", "ciao_x", "a^2") e lo mette tra i dollari al
                     posto dell'utente, senza nemmeno doverli scrivere. In
                     linea se non e' l'unica cosa sulla riga, a blocco se lo
                     e'. Spento per difetto, come l'altro: un "_" o una "\"
                     dentro una parola normale (un nome di file, un
                     nickname...) non deve trasformarsi in una formula senza
                     che nessuno l'abbia chiesto. -->
                <button type="button" class="fmt-btn fmt-intelligente" data-cmd="intelligente-auto"
                        title="Riconosce da solo un comando LaTeX appena scritto e lo mette in formula" aria-pressed="false">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor" stroke="none">
                        <!-- Scintilla a due punte, non piu' un cervello stile
                             cartone animato: le altre icone della barra sono
                             tutte forme geometriche pulite (punti, un
                             segmento, un rettangolo), e quella coppia di
                             lobi arrotondati stonava — leggeva come
                             un'emoji incollata, non come parte dello stesso
                             linguaggio visivo. Una scintilla e' il segno
                             comune per "succede da solo, in automatico": si
                             capisce a colpo d'occhio anche senza il titolo. -->
                        <path d="M12 3.2c.4 3 1 4.9 2.1 6 1.1 1.1 3 1.7 6 2.1-3 .4-4.9 1-6 2.1-1.1 1.1-1.7 3-2.1 6-.4-3-1-4.9-2.1-6-1.1-1.1-3-1.7-6-2.1 3-.4 4.9-1 6-2.1 1.1-1.1 1.7-3 2.1-6z"/>
                        <path d="M18.6 3.4c.2 1.3.5 2.1.9 2.5.4.4 1.2.7 2.5.9-1.3.2-2.1.5-2.5.9-.4.4-.7 1.2-.9 2.5-.2-1.3-.5-2.1-.9-2.5-.4-.4-1.2-.7-2.5-.9 1.3-.2 2.1-.5 2.5-.9.4-.4.7-1.2.9-2.5z"/>
                    </svg>
                </button>
            </div>

        </div>

        <!-- Terza sezione: le lingue. Un piano sotto quello della dettatura,
             cosi' la barra resta di due righe finche' non serve davvero. -->
        <div class="fmt-drawer-2">
            <div class="fmt-panel fmt-panel-2" data-panel2="lingue" hidden>
                <div class="fmt-lingue" role="group" aria-label="Lingua della dettatura"></div>
            </div>

            <div class="fmt-panel fmt-panel-2" data-panel2="comandi" hidden>
                <div class="fmt-sugg" role="group" aria-label="Comandi vocali disponibili"></div>
            </div>
        </div>
    </div>

    <div class="fc-editor" id="<?= htmlspecialchars($ft_editor_id) ?>" contenteditable="true" data-placeholder="<?= htmlspecialchars($ft_placeholder) ?>"></div>

    <input type="hidden" name="<?= htmlspecialchars($ft_field) ?>" id="<?= htmlspecialchars($ft_field) ?>">

</div>



<?php if (!defined('FT_ASSETS_EMITTED')): ?>

<?php define('FT_ASSETS_EMITTED', true); ?>

<style>
/* ================================================================
   TOOLBAR DI FORMATTAZIONE + EDITOR WYSIWYG — componente condiviso.

   Impianto GoodNotes: una barra sola, due sezioni. Sopra le famiglie di
   strumenti, sotto il pannello di quella scelta. I tasti non hanno più una
   pastiglia ciascuno: su fondo piatto, acceso è solo quello attivo — è ciò
   che rende leggibile una barra con dodici icone.

   Le variabili --blue/--surface/ecc si aspettano quelle della pagina ospite
   (tutte le pagine dell'app le definiscono già); ogni valore ha comunque un
   fallback per restare robusto altrove.
   ================================================================ */

.fmt-editor-wrap{ position:relative; }

/* ---- il tastino, dentro la barra ---- */
/* Non e' un tasto sopra la barra: e' il PRIMO tasto della barra, e chiudendo
   resta l'unico. Tutto il resto si riavvolge dietro di lui e da li' riesce,
   allargandosi verso destra: cosi' si vede dove va e da dove torna, invece di
   un blocco che sparisce e riappare da un'altra parte. */
.fmt-switch{
    display:inline-flex; align-items:center; justify-content:center;
    position:relative; flex:0 0 auto;
    background:transparent; border:none; border-radius:11px; cursor:pointer;
    width:40px; height:38px; padding:0; color:var(--text-2,#8A8A8E);
    transition:transform .14s var(--spring,cubic-bezier(.32,.72,0,1)), color .18s, background .18s;
}
.fmt-switch:active{ transform:scale(.88); }
.fmt-editor-wrap.fmt-aperta .fmt-switch{ color:var(--text,#1C1C1E); }

/* Il cerchietto: da chiuso dice che sotto quel tasto c'e' altro. Aperto non
   serve piu' — quello che c'e' e' li' in vista. */
.fmt-switch-pallino{
    position:absolute; right:5px; bottom:6px;
    width:5px; height:5px; border-radius:50%;
    background:var(--blue,#007AFF);
    opacity:0; transform:scale(.4);
    transition:opacity .2s, transform .24s var(--spring,cubic-bezier(.32,.72,0,1));
}
.fmt-editor-wrap.fmt-chiusa .fmt-switch-pallino{ opacity:1; transform:scale(1); }

/* Lo srotolamento. max-width invece di width perche' il contenuto non ha una
   larghezza fissa: cambia con la lingua e con quali tasti ci sono. Il valore
   e' generoso di proposito — serve solo come tetto per l'animazione, non deve
   mai essere lui a decidere dove la fila di tasti finisce. */
.fmt-tools{
    display:flex; align-items:center; gap:2px;
    max-width:1200px; opacity:1;
    overflow:hidden;
    transition:max-width .42s var(--spring,cubic-bezier(.32,.72,0,1)),
               opacity .26s ease .06s,
               margin-left .42s var(--spring,cubic-bezier(.32,.72,0,1));
}
.fmt-editor-wrap.fmt-chiusa .fmt-tools{
    max-width:0; opacity:0; margin-left:-4px;
    transition:max-width .38s var(--spring,cubic-bezier(.32,.72,0,1)),
               opacity .18s ease,
               margin-left .38s var(--spring,cubic-bezier(.32,.72,0,1));
}

/* ---------------- la barra ---------------- */
.fmt-toolbar{
    background:var(--surface-2,#EFEFF4);
    border-radius:16px;
    box-shadow:inset 0 0 0 .5px var(--hairline,rgba(60,60,67,.12));
    overflow:hidden;
    /* Chiusa si stringe sul tastino; aperta prende tutta la larghezza.
       Questa distinzione non e' estetica: con la barra larga quanto il suo
       contenuto, la riga dei colori non traboccava mai — e una riga che non
       trabocca non si puo' scorrere. Era il motivo per cui il dito non
       spostava niente. */
    width:-moz-fit-content; width:fit-content; max-width:100%;
    margin-bottom:8px;
    transition:border-radius .3s var(--spring,cubic-bezier(.32,.72,0,1));
    /* Scorrendo una casella lunga, la barra si ferma in cima allo schermo
       invece di uscire di scena: resta raggiungibile mentre si scrive piu'
       in basso. "sticky" e non "fixed" di proposito — cosi' la barra resta
       agganciata alla SUA casella (scorrendo oltre la casella se ne va con
       lei) e non se ne sta appiccicata allo schermo per tutta la pagina,
       sopra caselle a cui non appartiene. Lo sfondo non e' trasparente e lo
       z-index la tiene sopra il testo che le passa sotto. */
    /* Non "sticky": nella pagina vera la carta che contiene questa casella ha
       overflow:hidden (serve agli angoli arrotondati), e basta un solo
       antenato cosi' — non importa quanto lontano — perche' sticky smetta di
       funzionare e si comporti come se non ci fosse. Chi tiene la barra
       agganciata allo schermo e' il JavaScript qui sotto, che le mette
       position:fixed quando serve davvero. */
}
.fmt-toolbar.fmt-agganciata{
    position:fixed; top:6px; z-index:1000;
}

/* La copia in sovraimpressione vive qui, in fondo al body. Non e' un vezzo:
   position:fixed si misura dallo schermo solo finche' nessun antenato ha una
   trasformazione, un filtro o un will-change:transform — basta uno solo di
   questi, non importa quanto lontano, perche' "fixed" cominci a misurarsi da
   QUELL'antenato. Nella pagina vera la carta che contiene la casella ha
   will-change:transform (le serve per lo scorrimento laterale) e
   overflow:hidden (gli angoli arrotondati): il primo manderebbe la copia
   dentro la carta invece che in cima allo schermo, il secondo la ritaglierebbe
   via del tutto. Fuori da li', fixed torna a voler dire fixed.
   L'involucro e' senza posizione propria: e' la copia dentro di lui a restare
   fixed, quindi in pagina non occupa nessuno spazio — e l'originale, che non
   si e' mai mossa, continua a occupare il suo. */
.fmt-portale{ position:static; }
/* Nella copia la larghezza la detta il JavaScript (quella dell'originale),
   quindi niente margine sotto e nessuna delle riduzioni di altezza che servono
   a far comparire e sparire l'originale: la copia esiste solo quando deve
   vedersi, quindi e' sempre visibile. */
.fmt-portale .fmt-toolbar{ margin-bottom:0; max-height:none; opacity:1; }
.fmt-portale.fmt-chiusa .fmt-toolbar{ border-radius:999px; }
.fmt-portale.fmt-chiusa .fmt-row{ border-radius:999px; padding:5px; }
.fmt-portale.fmt-chiusa .fmt-switch{ border-radius:999px; width:38px; }
.fmt-portale.fmt-chiusa .fmt-tools{ max-width:0; opacity:0; margin-left:-4px; }
.fmt-portale.fmt-chiusa .fmt-switch-pallino{ opacity:1; transform:scale(1); }

/* La barra si vede solo mentre si sta scrivendo in quella casella. Non con
   display:none ma richiudendola in altezza: cosi' il comparire e lo sparire
   sono un movimento e non uno scatto, e lo spazio che occupa torna alla
   pagina invece di restare vuoto sopra ogni casella della carta.
   Il tetto e' generoso di proposito — serve solo come capolinea
   dell'animazione, non deve mai essere lui a decidere quanto la barra e'
   alta quando un pannello e' aperto. */
.fmt-editor-wrap .fmt-toolbar{
    max-height:700px;
    transition:border-radius .3s var(--spring,cubic-bezier(.32,.72,0,1)),
               max-height .28s var(--spring,cubic-bezier(.32,.72,0,1)),
               opacity .18s ease,
               margin-bottom .28s var(--spring,cubic-bezier(.32,.72,0,1));
}
.fmt-editor-wrap:not(.fmt-attiva) .fmt-toolbar{
    max-height:0; opacity:0; margin-bottom:0; pointer-events:none;
}

/* Aperta prende TUTTA la larghezza disponibile, non solo quella dei suoi
   tasti: e' cio' che fa entrare piu' colori nella riga senza doverla
   scorrere. Con fit-content da solo la barra si stringeva sul contenuto piu'
   corto (la fila dei tasti) e la riga dei colori restava schiacciata li'
   dentro. Quando i colori sono comunque troppi per starci, la riga trabocca
   e si scorre come prima — le due cose non sono in conflitto. */
.fmt-editor-wrap:not(.fmt-chiusa) .fmt-toolbar{ width:100%; }
/* Chiusa la barra e' un solo tasto: e allora che sia un tasto tondo, non un
   rettangolo con dentro una cosa sola. */
.fmt-editor-wrap.fmt-chiusa .fmt-toolbar{ border-radius:999px; }
.fmt-editor-wrap.fmt-chiusa .fmt-row{ border-radius:999px; padding:5px; }
.fmt-editor-wrap.fmt-chiusa .fmt-switch{ border-radius:999px; width:38px; }
/* La larghezza la decide la riga di SOPRA, e nessun altro. Il cassetto e i
   suoi pannelli non devono poter allargare la barra: se lo facessero, aprire
   i colori la farebbe crescere di colpo, e le righe di colori non avrebbero
   mai bisogno di scorrere (un contenuto che allarga il contenitore non
   trabocca mai). Il trucco e' width:0 — che nel calcolo della larghezza
   naturale vale zero — piu' min-width:100%, che poi lo riporta esattamente
   alla larghezza decisa dalla riga di sopra. */
.fmt-drawer, .fmt-drawer-2{ width:0; min-width:100%; box-sizing:border-box; }
.fmt-panel{ box-sizing:border-box; width:100%; }

/* sezione di sopra: le famiglie */
.fmt-row{
    display:flex; align-items:center; gap:2px;
    padding:6px 8px;
    position:relative; z-index:2;                 /* sta SOPRA il cassetto */
    background:var(--surface-2,#EFEFF4);          /* copre il pannello che rientra */
    border-radius:16px 16px 0 0;
}

/* ---------------- i tasti ---------------- */
.fmt-btn{
    background:transparent; border:none; border-radius:11px; cursor:pointer;
    min-width:40px; height:38px; padding:0 8px;
    font-size:15px; font-weight:650; font-family:inherit;
    color:var(--text,#1C1C1E);
    display:flex; align-items:center; justify-content:center; gap:6px;
    transition:transform .13s var(--spring,cubic-bezier(.32,.72,0,1)), background .16s, color .16s;
}
.fmt-btn:active{ transform:scale(.9); }
.fmt-btn svg{ display:block; }
.fmt-btn b, .fmt-btn i, .fmt-btn u{ font-size:16px; line-height:1; }

/* Acceso: la pastiglia chiara dietro l'icona, come lo strumento scelto in
   GoodNotes. Vale sia per la famiglia aperta sopra, sia per un formato
   attivo sotto. */
.fmt-btn.is-open,
.fmt-btn.is-active{
    background:var(--chip-bg,var(--surface,#fff));
    color:var(--blue,#007AFF);
    box-shadow:var(--pill-hl,inset 0 0 0 .5px rgba(60,60,67,.10)), var(--raise-sm,0 1px 2px rgba(0,0,0,.07));
}
@media (hover:hover){ .fmt-btn:hover{ background:rgba(120,120,128,.10); } }

.fmt-btn-wide{ padding:0 12px; }
.fmt-btn-lab{ font-size:12.5px; font-weight:600; color:var(--text-2,#8A8A8E); }
.fmt-btn.is-active .fmt-btn-lab{ color:inherit; }

/* Dentro i pannelli i tasti sono un po' piu' stretti della riga di sopra: la
   larghezza totale e' fissa, quindi ogni pixel tolto qui e' un pixel in piu'
   per i colori, che sono la parte che deve scorrere. */
.fmt-panel .fmt-btn{ min-width:36px; padding:0 5px; }
.fmt-panel .fmt-sep{ margin:0 4px; }

.fmt-sep{ width:1px; height:22px; background:var(--hairline-2,rgba(60,60,67,.18)); margin:0 6px; flex-shrink:0; }

.fmt-ix{ font-style:italic; font-family:Georgia,"Times New Roman",serif; font-size:17px; color:var(--blue,#007AFF); }

/* la x fra due righe: quello che fa una formula a blocco, mandarla a capo */
.fmt-blockmath{
    display:flex; flex-direction:column; align-items:center; justify-content:center;
    gap:1.5px; width:17px;
}
.fmt-blockmath::before, .fmt-blockmath::after{
    content:""; display:block; width:13px; height:1.5px; border-radius:1px;
    background:currentColor; opacity:.45;
}

/* microfono che sta ascoltando: pulsa, come in diario */
.fmt-mic.is-rec{ background:var(--red,#FF3B30); color:#fff; animation:fmtPulse 1.5s ease-in-out infinite; }
@keyframes fmtPulse{ 50%{ opacity:.62; } }

/* ---------------- il cassetto ----------------
   height la mette il JS (misurata sul pannello): serve un valore in pixel
   perché `auto` non si può animare. Il pannello parte da -100%: scorre fuori
   da sotto la barra di sopra invece di comparire dal nulla. */
.fmt-drawer{
    height:0; overflow:hidden; position:relative; z-index:1;
    transition:height .34s var(--spring,cubic-bezier(.32,.72,0,1));
}
.fmt-panel{
    display:flex; align-items:center; gap:2px;
    padding:6px 8px 8px;
    transform:translateY(-100%);
    transition:transform .34s var(--spring,cubic-bezier(.32,.72,0,1));
}
.fmt-panel.is-open{ transform:translateY(0); }
.fmt-panel[hidden]{ display:none; }

.fmt-group{ display:flex; align-items:center; gap:2px; flex-shrink:0; }

/* ---------------- i colori, scorribili ----------------
   Stanno su una riga che scorre col dito: aggiungerne uno non stringe i
   tasti né manda la barra a capo. La sfumatura ai bordi dice che c'è altro
   oltre il bordo, senza aggiungere frecce. */
.fmt-swatches, .fmt-lingue{
    display:flex; align-items:center; gap:7px;
    flex:1; min-width:0;
    overflow-x:auto; overflow-y:hidden;
    /* pan-x dice al browser: qui il dito scorre in orizzontale, non trascina
       la pagina. Senza, il gesto viene preso dalla pagina e la riga non si
       muove. overscroll-behavior evita che arrivati in fondo il gesto
       prosegua trascinando quello che c'e' sotto. */
    touch-action:pan-x;
    overscroll-behavior-x:contain;
    padding:3px 2px;
    scrollbar-width:none; -webkit-overflow-scrolling:touch;
    scroll-snap-type:x proximity;
    -webkit-mask-image:linear-gradient(90deg,transparent 0,#000 10px,#000 calc(100% - 14px),transparent 100%);
            mask-image:linear-gradient(90deg,transparent 0,#000 10px,#000 calc(100% - 14px),transparent 100%);
}
.fmt-swatches::-webkit-scrollbar, .fmt-lingue::-webkit-scrollbar{ display:none; }
.fmt-lingue{ gap:6px; }

.fmt-pop-dot{
    width:24px; height:24px; border-radius:50%; border:none; cursor:pointer;
    padding:0; flex-shrink:0; scroll-snap-align:center;
    box-shadow:inset 0 0 0 1px rgba(0,0,0,.07);
    transition:transform .14s var(--spring,cubic-bezier(.32,.72,0,1));
}
.fmt-pop-dot:active{ transform:scale(.84); }
/* Il colore in uso si vede: un anello attorno al pallino. Senza, per sapere
   di che colore si sta scrivendo bisogna guardare il testo e indovinare. */
.fmt-pop-dot.is-scelto{ box-shadow:inset 0 0 0 1px rgba(0,0,0,.07), 0 0 0 2.5px var(--surface,#fff), 0 0 0 4.5px var(--blue,#007AFF); }

/* I PALLINI DELLA LISTA, DIVERSI DA QUELLI DEL TESTO
   Le due file di colori sono le stesse tinte negli stessi identici cerchi:
   aperto il pannello non c'e' modo di sapere a colpo d'occhio se si sta
   scegliendo il colore del TESTO o quello dei PALLINI dell'elenco — e si
   sbaglia di continuo. Qui i secondi diventano riconoscibili senza dover
   leggere niente: piu' piccoli (che e' poi la loro misura vera nel testo,
   quindi il pannello mostra quello che si otterra'), con un bordo scuro
   attorno e un riflesso in alto che li fa sembrare pastiglie in rilievo
   invece che campioni di colore piatti.
   Il pulsante resta grande quanto prima come area da toccare: a rimpicciolire
   e' solo il disegno, con l'anello del colore di sfondo che fa da cornice. */
.fmt-pop-dot[data-list-color]{
    /* Rimpicciolito direttamente, non con un bordo trasparente piu'
       background-clip: quei pulsanti hanno il colore scritto nello style in
       linea, e la scorciatoia "background:" li' dentro riazzera il
       background-clip — la cornice trasparente si sarebbe colorata anche lei
       e il pallino sarebbe rimasto grande come prima. */
    width:18px; height:18px;
    /* Rilievo morbido, non un bordo nero e una lucina dura: quelli facevano
       sembrare i pallini bottoni di un'interfaccia vecchia. Il contorno e'
       appena accennato, il riflesso e' largo e sfumato in alto, l'ombra
       interna sotto e' altrettanto morbida — la differenza dai colori del
       testo (piatti e piu' grandi) resta netta, ma senza spigoli. */
    box-shadow:
        inset 0 0 0 .5px rgba(0,0,0,.15),                 /* contorno appena accennato */
        inset 0 3.5px 4px -2px rgba(255,255,255,.9),      /* riflesso ampio e sfumato */
        inset 0 -3.5px 4px -2.5px rgba(0,0,0,.18),        /* ombra morbida sotto */
        0 1px 2.5px rgba(0,0,0,.16);                      /* stacco leggero dallo sfondo */
}
.fmt-pop-dot[data-list-color].is-scelto{
    box-shadow:
        inset 0 0 0 .5px rgba(0,0,0,.15),
        inset 0 3.5px 4px -2px rgba(255,255,255,.9),
        inset 0 -3.5px 4px -2.5px rgba(0,0,0,.18),
        0 0 0 2px var(--surface,#fff),
        0 0 0 3.5px var(--blue,#007AFF);
}
/* Il "predefinito" della lista segue la stessa misura degli altri della sua
   fila, altrimenti sarebbe l'unico grande e sembrerebbe un'altra cosa. */
.fmt-pop-dot-default[data-list-color]{
    width:18px; height:18px;
    box-shadow:inset 0 0 0 1.5px var(--hairline-2,rgba(60,60,67,.22));
}
.fmt-pop-dot-default[data-list-color] svg{ width:11px; height:11px; }
/* Il terzo piano: stesso meccanismo del secondo, cassetto suo. */
.fmt-drawer-2{
    height:0; overflow:hidden; position:relative; z-index:0;
    transition:height .32s var(--spring,cubic-bezier(.32,.72,0,1));
}
.fmt-panel-2{
    display:flex; align-items:center;
    padding:2px 8px 8px;
    transform:translateY(-100%);
    transition:transform .32s var(--spring,cubic-bezier(.32,.72,0,1));
}
.fmt-panel-2.is-open{ transform:translateY(0); }
.fmt-panel-2[hidden]{ display:none; }

/* La sigla della lingua nel tasto: due lettere, larghezza fissa, cosi' il
   tasto non cambia dimensione passando da IT a EN. */
.fmt-lingua-ora{ font-weight:700; letter-spacing:.4px; min-width:20px; text-align:center; }
.fmt-lingua-btn.is-open{ background:var(--chip-bg,var(--surface,#fff)); color:var(--blue,#007AFF); }

/* Le lingue fra cui scegliere: sigla e nome per esteso, perche' qui lo spazio
   c'e' e "PT" da solo non dice molto. La riga scorre. */
.fmt-lingua{
    flex-shrink:0; scroll-snap-align:center;
    display:flex; align-items:center; gap:6px;
    height:32px; padding:0 11px;
    border:none; border-radius:9px; cursor:pointer;
    background:var(--chip-bg,var(--surface,#fff)); color:var(--text-2,#8A8A8E);
    font:inherit; font-size:12.5px;
    box-shadow:var(--pill-hl,inset 0 0 0 .5px rgba(60,60,67,.12));
    transition:transform .13s var(--spring,cubic-bezier(.32,.72,0,1)), background .16s, color .16s;
}
.fmt-lingua:active{ transform:scale(.92); }
.fmt-lingua-sigla{ font-weight:700; letter-spacing:.3px; }
.fmt-lingua-nome{ opacity:.75; }
.fmt-lingua.is-scelto{ background:var(--blue,#007AFF); color:#fff; box-shadow:none; }
.fmt-lingua.is-scelto .fmt-lingua-nome{ opacity:.9; }

/* Punteggiatura automatica accesa: acceso di colore, come chiede un
   interruttore che cambia il modo in cui si parla. */
.fmt-punt.is-active,
.fmt-comandi-btn.is-active,
.fmt-dollari.is-active,
.fmt-intelligente.is-active{ background:var(--blue,#007AFF); color:#fff; box-shadow:none; }

/* La formula a blocco mentre la si modifica con MathQuill. Il campo porta con
   se' un bordo e uno sfondo suoi: qui vanno tolti, perche' la cornice ce
   l'ha gia' il blocco che lo contiene — altrimenti si vedrebbero due
   riquadri uno dentro l'altro. */
.fc-fx.fc-mq{
    cursor:text;
    /* Stessa correzione della formula in linea, qui a scopo preventivo: la
       formula a blocco non mostra i "$" quindi non si vede l'impilamento,
       ma il contenuto di MathQuill non ha comunque bisogno delle regole di
       spezzatura aggressiva pensate per il LaTeX grezzo lungo. Una formula
       davvero larga scorre in orizzontale invece di spezzarsi, come fa gia'
       da disegnata. */
    white-space:nowrap; overflow-wrap:normal; word-break:normal;
    overflow-x:auto; max-width:100%;
}
.fc-fx.fc-mq .mq-editable-field,
.fc-fx.fc-mq .mq-editable-field.mq-focused{
    border:none; box-shadow:none; background:transparent; width:100%;
}
.fc-fx.fc-mq .mq-math-mode{ font-size:1.05em; }

/* Stessa idea per la formula IN LINEA: il campo si inserisce nel testo, non
   deve avere un bordo suo (quello ce l'ha gia' lo span quando e' chiuso, ma
   qui e' aperto) ne' comportarsi da blocco — resta incastonato nella riga,
   alla stessa altezza delle lettere attorno. */
.fc-x.fc-mq{
    display:inline-block; vertical-align:middle; cursor:text;
    background:var(--blue-soft,rgba(0,122,255,.09)); border-radius:6px; padding:0 3px;
    box-shadow:inset 0 0 0 1px rgba(0,122,255,.22);
    /* .fc-x (la regola base, qualche riga sopra) porta overflow-wrap:anywhere
       e word-break:break-word, pensati per un LaTeX grezzo lungo che non deve
       sfondare il contenitore. Applicati al contenuto di MathQuill — il "$"
       prima, il disegno della formula, il "$" dopo — spezzavano proprio li',
       impilando i due dollari sopra e sotto invece di lasciarli ai lati. */
    white-space:nowrap; overflow-wrap:normal; word-break:normal;
    /* Niente max-width/overflow-x qui: un "auto" mostrava la freccina di
       scorrimento anche su una formula piccolissima come "e^Y", per uno
       scarto di un pixel nel calcolo interno di MathQuill — la casella
       sembrava incapace di mostrare tutto pur essendoci lo spazio. La riga
       di testo intorno e' gia' quella che decide quanto spazio c'e': la
       formula lo occupa (inline-block cresce sul proprio contenuto, non
       serve dirglielo) e basta. Se un giorno una formula in linea fosse
       davvero enorme, il rimedio giusto e' scriverla a blocco — quella si'
       pensata per contenuti larghi — non stringere qui quelle piccole. */
}
.fc-x.fc-mq .mq-editable-field,
.fc-x.fc-mq .mq-editable-field.mq-focused{ border:none; box-shadow:none; background:transparent; }
.fc-x.fc-mq .mq-math-mode{ font-size:1em; }

/* I suggerimenti: cosa si puo' dire adesso. Scorrono, e si possono anche
   toccare — a volte una parola non viene riconosciuta, e restare bloccati
   senza via d'uscita sarebbe frustrante. */
.fmt-sugg{
    display:flex; align-items:center; gap:6px;
    flex:1; min-width:0;
    overflow-x:auto; overflow-y:hidden;
    touch-action:pan-x; overscroll-behavior-x:contain;
    padding:3px 2px;
    scrollbar-width:none;
}
.fmt-sugg::-webkit-scrollbar{ display:none; }
.fmt-sugg-voce{
    flex-shrink:0; height:30px; padding:0 12px;
    border:none; border-radius:15px; cursor:pointer;
    background:var(--chip-bg,var(--surface,#fff)); color:var(--text,#1C1C1E);
    font:inherit; font-size:12.5px; font-weight:600;
    box-shadow:var(--pill-hl,inset 0 0 0 .5px rgba(60,60,67,.12));
    transition:transform .13s var(--spring,cubic-bezier(.32,.72,0,1));
}
.fmt-sugg-voce::before{ content:"\201C"; opacity:.4; margin-right:2px; }
.fmt-sugg-voce::after{ content:"\201D"; opacity:.4; margin-left:2px; }
.fmt-sugg-voce:active{ transform:scale(.92); }
/* "denominatore", "fine frazione"... non sono nomi da leggere, sono il
   prossimo tasto da premere: uno sfondo pieno invece del solito pallido
   segnala a colpo d'occhio che quello lì chiude o fa avanzare qualcosa,
   diverso da chi apre una struttura nuova. */
.fmt-sugg-voce.fmt-sugg-chiudi{
    background:var(--blue,#007AFF); color:#fff;
    box-shadow:none;
}
.fmt-sugg-voce.fmt-sugg-chiudi::before, .fmt-sugg-voce.fmt-sugg-chiudi::after{ opacity:.65; }

/* Mentre si detta il cursore deve VEDERSI: e' l'unica cosa che dice dove
   andra' a finire quello che si sta per dire. */
.fmt-editor-wrap.sta-dettando .fc-editor{
    caret-color:var(--red,#FF3B30);
    box-shadow:inset 0 0 0 1.5px var(--red,#FF3B30);
}

.fmt-pop-dot-default{
    background:var(--chip-bg,var(--surface,#fff)); color:var(--text-2,#8A8A8E);
    box-shadow:inset 0 0 0 1.5px var(--hairline-2,rgba(60,60,67,.22));
    display:flex; align-items:center; justify-content:center;
}

/* ---------------- editor WYSIWYG ---------------- */
/* Il segnaposto non puo' usare :empty — l'editor contiene sempre almeno un
   blocco vuoto. Lo accende il modello, che sa davvero se c'e' del testo. */
.fc-editor[data-vuoto="1"]::before{
    content:attr(data-placeholder); color:var(--text-3,#AEAEB2); pointer-events:none;
    position:absolute; padding-top:1px;
}
.fc-editor{ position:relative; }

.fc-editor{
    min-height:110px; padding:14px 15px; background:var(--surface,#fff);
    border:.5px solid var(--hairline-2,rgba(60,60,67,.22)); border-top:none;
    border-radius:0 0 var(--r-field,13px) var(--r-field,13px);
    font-size:16px; line-height:1.65; color:var(--text,#1C1C1E); outline:none;
    word-wrap:break-word; overflow-wrap:break-word; white-space:pre-wrap;
}
.fc-editor:empty:before{ content:attr(data-placeholder); color:var(--text-3,#AEAEB2); }
.fc-editor:focus{ box-shadow:inset 0 0 0 1.5px var(--blue,#007AFF); }

/* Senza barra sopra, la casella deve reggersi da sola: bordo e angoli
   COMPLETI, non solo quelli sotto. Con la barra chiusa via max-height:0 (vedi
   sopra) il div della barra e' ancora li', a zero altezza — la casella non lo
   sa e restava con la cima piatta e senza bordo, come se qualcosa dovesse
   ancora esserci sopra: e' il "non chiusa in alto" segnalato.
   La transizione e' la stessa della barra (stessa durata, stessa curva): le
   due cose si muovono insieme, la barra che si apre e l'angolo che si
   riduce, invece di scattare l'una dopo l'altra. */
.fc-editor{
    transition:border-radius .28s var(--spring,cubic-bezier(.32,.72,0,1)),
               border-top-width .28s var(--spring,cubic-bezier(.32,.72,0,1));
}
.fmt-editor-wrap:not(.fmt-attiva) .fc-editor{
    border-top:.5px solid var(--hairline-2,rgba(60,60,67,.22));
    border-radius:var(--r-field,13px);
}


/* Le formule ora le disegna fc_doc.js (.fc-x in linea, .fc-fx a blocco):
   lo stile sta li' perche' serve identico anche alle pagine di sola
   lettura, che non includono questa barra. */

/* In modalità grezza i comandi che agiscono sulla struttura (non sul testo)
   si spengono: lì non c'è un elenco su cui lavorare. */

/* Chi preferisce che le cose non si muovano: niente scorrimenti, si apre e
   basta. Il pannello resta comunque leggibile. */
@media (prefers-reduced-motion:reduce){
    .fmt-drawer, .fmt-panel, .fmt-btn, .fmt-tools, .fmt-switch-pallino{ transition-duration:.01ms; }
    .fmt-mic.is-rec{ animation:none; }
}
</style>



<script>
// ================================================================
// IL CASSETTO: la seconda sezione della barra
// ================================================================
/* Un pannello per volta. L'altezza e' in pixel e la misura il JS, perche'
   height:auto non si anima; il pannello dentro parte da translateY(-100%), e
   il risultato e' un foglio che scorre fuori da sotto la barra di sopra invece
   di un blocco che appare dal nulla. */
function fcPanelOf(bar, nome) { return bar.querySelector('.fmt-panel[data-panel="' + nome + '"]'); }

/* La terza sezione funziona come la seconda, ma su un cassetto suo: cosi'
   aprirla non ricalcola l'altezza di quello sopra, e le due animazioni non si
   pestano i piedi. */
function fcChiudiSecondo(bar) {
    var d = bar.querySelector('.fmt-drawer-2');
    if (!d) return;
    var ap = d.querySelector('.fmt-panel-2.is-open');
    bar.querySelectorAll('[data-panel2]').forEach(function (t) {
        if (t.tagName === 'BUTTON') t.setAttribute('aria-expanded', 'false');
        t.classList.remove('is-open');
    });
    if (!ap) { d.style.height = '0px'; return; }
    ap.classList.remove('is-open');
    d.style.height = '0px';
    var fine = function () { if (!ap.classList.contains('is-open')) ap.hidden = true; };
    setTimeout(fine, 420);
}

function fcApriSecondo(bar, nome) {
    var d = bar.querySelector('.fmt-drawer-2');
    var p = d && d.querySelector('.fmt-panel-2[data-panel2="' + nome + '"]');
    if (!d || !p) return;
    if (p.classList.contains('is-open')) { fcChiudiSecondo(bar); return; }

    /* Un pannello alla volta: senza chiudere gli altri restavano is-open e
       visibili sotto quello nuovo — "comandi" che si apre sopra "lingue"
       ancora li', invece di al suo posto. L'altezza del cassetto veniva
       calcolata solo sull'ultimo aperto, quindi il piu' vecchio sporgeva
       fuori invece di sparire. */
    d.querySelectorAll('.fmt-panel-2').forEach(function (altro) {
        if (altro !== p) { altro.classList.remove('is-open'); altro.hidden = true; }
    });
    bar.querySelectorAll('[data-panel2]').forEach(function (t) {
        if (t.tagName === 'BUTTON') t.setAttribute('aria-expanded', t.dataset.panel2 === nome ? 'true' : 'false');
        t.classList.toggle('is-open', t.dataset.panel2 === nome);
    });

    p.hidden = false;
    void p.offsetHeight;
    d.style.height = p.offsetHeight + 'px';
    p.classList.add('is-open');
    var b = bar.querySelector('button[data-panel2="' + nome + '"]');
    if (b) { b.classList.add('is-open'); b.setAttribute('aria-expanded', 'true'); }
}

function fcPannelloAperto(bar, nome) {
    var p = fcPanelOf(bar, nome);
    return !!(p && p.classList.contains('is-open'));
}

function fcClosePanel(bar) {
    /* Il terzo piano si chiude SEMPRE, anche se il secondo era gia' chiuso:
       altrimenti chiudendo la barra restava aperto per conto suo, sotto una
       barra che non c'era piu'. */
    fcChiudiSecondo(bar);

    var drawer = bar.querySelector('.fmt-drawer');
    if (!drawer) return;
    var aperto = drawer.querySelector('.fmt-panel.is-open');
    if (!aperto) return;

    aperto.classList.remove('is-open');
    drawer.style.height = '0px';
    bar.querySelectorAll('.fmt-tab').forEach(function (t) {
        t.classList.remove('is-open');
        t.setAttribute('aria-expanded', 'false');
    });

    /* Nascosto solo a scorrimento finito: toglierlo subito farebbe sparire il
       pannello a meta' strada. */
    var fine = function () { if (!aperto.classList.contains('is-open')) aperto.hidden = true; };
    drawer.addEventListener('transitionend', function once(e) {
        if (e.target !== drawer) return;
        drawer.removeEventListener('transitionend', once);
        fine();
    });
    setTimeout(fine, 420);
}

function fcOpenPanel(bar, nome) {
    var panel = fcPanelOf(bar, nome);
    if (!panel) return;

    /* Stesso tasto due volte: si richiude. Senza, un pannello aperto si
       potrebbe solo scambiare con un altro, mai chiudere. */
    if (panel.classList.contains('is-open')) { fcClosePanel(bar); return; }

    fcOpenPanelForce(bar, nome);
}

/* La parte di apertura vera e propria, senza il toggle-chiusura: serve a chi
   deve garantire che il pannello sia aperto — mai chiuderlo — a prescindere
   da come si trovava un attimo prima. Il tasto Elenco e' il primo a
   servirsene: creando un pallino vuole essere sicuro che il pannello si veda,
   non rischiare di richiuderlo se per caso era gia' aperto. */
function fcOpenPanelForce(bar, nome) {
    var drawer = bar.querySelector('.fmt-drawer');
    var panel = fcPanelOf(bar, nome);
    if (!drawer || !panel) return;

    fcChiudiSecondo(bar);
    drawer.querySelectorAll('.fmt-panel').forEach(function (p) {
        if (p !== panel) { p.classList.remove('is-open'); p.hidden = true; }
    });

    panel.hidden = false;
    void panel.offsetHeight;                 // senza, il browser salta l'animazione
    drawer.style.height = panel.offsetHeight + 'px';
    panel.classList.add('is-open');

    bar.querySelectorAll('.fmt-tab').forEach(function (t) {
        var suo = t.dataset.panel === nome;
        t.classList.toggle('is-open', suo);
        t.setAttribute('aria-expanded', suo ? 'true' : 'false');
    });
}

window.addEventListener('resize', function () {
    document.querySelectorAll('.fmt-drawer, .fmt-drawer-2').forEach(function (d) {
        var aperto = d.querySelector('.fmt-panel.is-open, .fmt-panel-2.is-open');
        if (aperto) d.style.height = aperto.offsetHeight + 'px';
    });
});

// ================================================================
// IL TASTINO CHE APRE E CHIUDE LA BARRA
// ================================================================
/* Lo stato NON si ricorda fra una nota e l'altra: com'e' al caricamento lo
   decide la pagina. Una nota che si apre con la barra dispiegata perche'
   mezz'ora fa se ne stava scrivendo un'altra e' una sorpresa, non una
   comodita'. */
function ftSetupSwitch(wrap) {
    var sw = wrap.querySelector('.fmt-switch');
    if (!sw) return;
    if (!wrap.classList.contains('fmt-chiusa')) wrap.classList.add('fmt-aperta');

    sw.addEventListener('mousedown', function (e) { e.preventDefault(); });
    sw.addEventListener('click', function () {
        var chiusa = wrap.classList.toggle('fmt-chiusa');
        wrap.classList.toggle('fmt-aperta', !chiusa);
        sw.setAttribute('aria-expanded', chiusa ? 'false' : 'true');
        if (chiusa) { var bar = ftBarra(wrap); if (bar) fcClosePanel(bar); }
    });
}

// ================================================================
// DETTATURA
// ================================================================
/* Stessa impostazione di diario.js: stesso riconoscimento, stessa
   punteggiatura indovinata, stesso riavvio quando il browser stacca da solo.
   Si inserisce solo cio' che il riconoscimento da' per definitivo, con
   insertText, cosi' il testo entra dove sta il cursore, eredita la
   formattazione attiva e resta annullabile con il tasto annulla del browser. */
function ftLinguaDettatura() {
    /* La lingua della DETTATURA deve seguire la lingua dell'APP, non quella
       del browser. Chiedere l'italiano a un riconoscitore impostato in inglese
       non da' errore: da' parole sbagliate, e sembra che funzioni male invece
       che essere configurato male.

       navigator.language non compare piu' fra le fonti proprio per questo: su
       un telefono in inglese diceva "en-US" anche a chi stava scrivendo in
       italiano, ed era la ragione per cui non ne azzeccava una. In mancanza di
       indicazioni si usa l'italiano, che e' la lingua dell'app. */
    var mappa = { it: 'it-IT', en: 'en-US', es: 'es-ES', fr: 'fr-FR', de: 'de-DE', pt: 'pt-PT', nl: 'nl-NL' };
    var fonti = [
        window.NISAMA_LANG,
        (window.NISAMA_I18N && window.NISAMA_I18N.lang),
        document.documentElement.getAttribute('lang')
    ];
    for (var i = 0; i < fonti.length; i++) {
        var v = (fonti[i] || '').toString().toLowerCase().trim();
        if (!v) continue;
        if (v.length >= 5 && v.charAt(2) === '-') return v.slice(0, 2) + '-' + v.slice(3, 5).toUpperCase();
        if (mappa[v.slice(0, 2)]) return mappa[v.slice(0, 2)];
    }
    return 'it-IT';
}

// ================================================================
// IMPOSTAZIONI DELLA DETTATURA
// ================================================================

var FT_LINGUE = [
    { c: 'it', l: 'it-IT', n: 'Italiano' },
    { c: 'en', l: 'en-US', n: 'English' },
    { c: 'es', l: 'es-ES', n: 'Español' },
    { c: 'fr', l: 'fr-FR', n: 'Français' },
    { c: 'de', l: 'de-DE', n: 'Deutsch' },
    { c: 'pt', l: 'pt-PT', n: 'Português' },
    { c: 'nl', l: 'nl-NL', n: 'Nederlands' }
];

/* Le preferenze restano fra una sessione e l'altra: chi detta in una lingua
   di solito continua a dettare in quella, e ricominciare ogni volta dalla
   lingua dell'app sarebbe una scocciatura quotidiana. Se il browser non
   permette di ricordare (navigazione privata, permessi negati) si prosegue
   con i valori predefiniti invece di rompersi. */
function ftPrefLeggi(chiave, ripiego) {
    try { var v = window.localStorage.getItem('nisama_dict_' + chiave); return v === null ? ripiego : v; }
    catch (e) { return ripiego; }
}
function ftPrefScrivi(chiave, valore) {
    try { window.localStorage.setItem('nisama_dict_' + chiave, valore); } catch (e) {}
}

function ftLinguaScelta() {
    var salvata = ftPrefLeggi('lang', '');
    if (salvata) return salvata;
    return ftLinguaDettatura();          // in mancanza di scelta, la lingua dell'app
}

function ftPunteggiaturaAuto() {
    return ftPrefLeggi('punt', '1') === '1';
}

/* Spento per difetto: senza, chi scrive "costa 5$ e altri 3$" si ritroverebbe
   una formula al posto di due prezzi. Chi lo vuole lo accende una volta, e da
   li' in poi $...$ e $$...$$ diventano formule mentre si scrive. */
function ftDollariAuto() {
    return ftPrefLeggi('dollari', '0') === '1';
}

/* Spento per difetto, stesso motivo del dollaro: un "_" o una "\" dentro una
   parola normale (un nome utente, un percorso di file...) non deve
   trasformarsi in una formula senza che nessuno l'abbia chiesto. */
function ftIntelligenteAuto() {
    return ftPrefLeggi('intelligente', '0') === '1';
}

// ---------------------------------------------------------------
// La punteggiatura dettata a voce
// ---------------------------------------------------------------
/* Quando l'automatismo e' spento, "virgola" e "punto" devono diventare i segni,
   non restare parole. Il riconoscimento vocale li consegna come parole
   qualsiasi: la conversione la facciamo qui.

   Le espressioni piu' lunghe vanno provate per prime, o "punto" mangerebbe
   l'inizio di "punto e virgola" lasciando in giro una "e virgola" orfana. */
var FT_SEGNI = {
    it: [
        ['punto e virgola', ';'], ['punto interrogativo', '?'], ['punto esclamativo', '!'],
        ['punto di domanda', '?'], ['due punti', ':'], ['puntini di sospensione', '\u2026'],
        ['aperta parentesi', '('], ['apri parentesi', '('], ['chiusa parentesi', ')'], ['chiudi parentesi', ')'],
        ['virgolette', '"'], ['nuova riga', '\n'], ['a capo', '\n'], ['punto', '.'], ['virgola', ',']
    ],
    en: [
        ['question mark', '?'], ['exclamation mark', '!'], ['exclamation point', '!'],
        ['full stop', '.'], ['semicolon', ';'], ['ellipsis', '\u2026'],
        ['open bracket', '('], ['close bracket', ')'], ['new line', '\n'], ['new paragraph', '\n'],
        ['colon', ':'], ['period', '.'], ['comma', ',']
    ],
    es: [['punto y coma', ';'], ['dos puntos', ':'], ['signo de interrogación', '?'], ['nueva línea', '\n'], ['punto', '.'], ['coma', ',']],
    fr: [['point-virgule', ';'], ['deux points', ':'], ['point d\'interrogation', '?'], ['nouvelle ligne', '\n'], ['point', '.'], ['virgule', ',']],
    de: [['fragezeichen', '?'], ['ausrufezeichen', '!'], ['doppelpunkt', ':'], ['semikolon', ';'], ['neue zeile', '\n'], ['punkt', '.'], ['komma', ',']],
    pt: [['ponto e vírgula', ';'], ['dois pontos', ':'], ['ponto de interrogação', '?'], ['nova linha', '\n'], ['ponto', '.'], ['vírgula', ',']],
    nl: [['vraagteken', '?'], ['uitroepteken', '!'], ['dubbele punt', ':'], ['puntkomma', ';'], ['nieuwe regel', '\n'], ['punt', '.'], ['komma', ',']]
};

function ftEscapeRegex(s) { return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }

function ftSegniDettati(testo, lingua) {
    var tab = FT_SEGNI[(lingua || 'it').slice(0, 2).toLowerCase()] || FT_SEGNI.it;
    var out = ' ' + testo + ' ';
    tab.forEach(function (coppia) {
        /* Confini di parola veri: senza, "appuntamento" perderebbe il suo
           "punto" e diventerebbe "ap.amento". */
        var re = new RegExp('(^|[\\s.,;:!?])' + ftEscapeRegex(coppia[0]) + '(?=$|[\\s.,;:!?])', 'gi');
        out = out.replace(re, '$1\u0000' + coppia[1] + '\u0000');
    });
    /* I segni si attaccano alla parola che li precede, come li scriverebbe una
       persona: "ciao , come stai" non e' italiano. L'a capo invece mangia gli
       spazi da tutt'e due i lati. */
    out = out.replace(/\s*\u0000\n\u0000\s*/g, '\n');
    out = out.replace(/\s*\u0000(.)\u0000\s*/g, '$1 ');
    return out.replace(/[ \t]+/g, ' ').replace(/ *\n */g, '\n').trim();
}

/* Punto d'ingresso unico: automatica o dettata, a seconda dell'interruttore. */
function ftChiudiFrase(testo, lingua) {
    var t = String(testo || '').trim();
    if (!t) return '';
    if (ftPunteggiaturaAuto()) {
        return window.nisamaPunctuate ? window.nisamaPunctuate(t) : t;
    }
    return ftSegniDettati(t, lingua);
}

/* Vero solo se TUTTO quello che si e' detto, dalla prima all'ultima lettera,
   e' un unico segno dettato ("punto", "punto e virgola"...) e nient'altro —
   non una frase che contiene anche quella parola. Serve a distinguere un
   comando di punteggiatura esplicito da una frase qualunque quando
   l'automatismo e' acceso, cosa che ftGiunzione da sola non sa fare (vedi
   sopra). "a capo"/"nuova riga" non passano da qui: un a capo e' un nuovo
   paragrafo del documento, non un carattere da appendere al testo — se ne
   occupa ftUtteranzaACapo, prima ancora di arrivare a questo punto. */
function ftSegnoDaSolo(testo, lingua) {
    var tab = FT_SEGNI[(lingua || 'it').slice(0, 2).toLowerCase()] || FT_SEGNI.it;
    var t = ftSenzaAccenti(String(testo || '').trim());
    if (!t) return null;
    for (var k = 0; k < tab.length; k++) {
        if (tab[k][1] === '\n') continue;
        if (ftSenzaAccenti(tab[k][0]) === t) return tab[k][1];
    }
    return null;
}

/* Vero se tutto il respiro dettato e' solo "a capo" o "nuova riga" (nelle
   varianti delle lingue supportate). Un a capo VERO va fatto con la stessa
   funzione del tasto Invio (fcdSplitBlock): un semplice carattere "\n"
   infilato nel testo non crea un nuovo blocco per il documento (che lavora a
   paragrafi, non a righe), quindi dettandolo non succedeva nulla di
   visibile — il modello lo trattava come uno spazio qualunque. */
function ftUtteranzaACapo(testo, lingua) {
    var tab = FT_SEGNI[(lingua || 'it').slice(0, 2).toLowerCase()] || FT_SEGNI.it;
    var t = ftSenzaAccenti(String(testo || '').trim());
    if (!t) return false;
    for (var k = 0; k < tab.length; k++) {
        if (tab[k][1] === '\n' && ftSenzaAccenti(tab[k][0]) === t) return true;
    }
    return false;
}

// ---------------------------------------------------------------
// Il pannello
// ---------------------------------------------------------------
function ftCostruisciPannelloMic(bar, alCambio) {
    var riga = bar.querySelector('.fmt-panel-2[data-panel2="lingue"] .fmt-lingue');
    var tasto = bar.querySelector('.fmt-lingua-btn');
    var etichetta = bar.querySelector('.fmt-lingua-ora');
    var punt = bar.querySelector('.fmt-punt');
    if (!riga || !tasto) return;

    riga.innerHTML = '';
    FT_LINGUE.forEach(function (L) {
        var b = document.createElement('button');
        b.type = 'button';
        b.tabIndex = -1;   // mai fuoco da solo: vedi il commento in ftSetupToolbar
        b.className = 'fmt-lingua';
        b.dataset.lang = L.l;
        b.title = L.n;
        b.innerHTML = '<span class="fmt-lingua-sigla">' + L.c.toUpperCase() + '</span>' +
                      '<span class="fmt-lingua-nome">' + L.n + '</span>';
        riga.appendChild(b);
    });

    function segna() {
        var scelta = ftLinguaScelta();
        var trovata = null;
        riga.querySelectorAll('.fmt-lingua').forEach(function (b) {
            var suo = b.dataset.lang === scelta;
            b.classList.toggle('is-scelto', suo);
            if (suo) trovata = b;
        });
        if (etichetta) etichetta.textContent = (scelta || 'it').slice(0, 2).toUpperCase();
        if (tasto) tasto.title = 'Lingua della dettatura' + (trovata ? ' — ' + trovata.title : '');
        if (punt) {
            var on = ftPunteggiaturaAuto();
            punt.classList.toggle('is-active', on);
            punt.setAttribute('aria-pressed', on ? 'true' : 'false');
            punt.title = on
                ? 'Punteggiatura automatica accesa — la mette il programma'
                : 'Punteggiatura automatica spenta — dilla a voce: "virgola", "punto"\u2026';
        }
    }

    tasto.addEventListener('click', function () { fcApriSecondo(bar, 'lingue'); });

    riga.addEventListener('click', function (e) {
        var b = e.target.closest('.fmt-lingua');
        if (!b) return;
        ftPrefScrivi('lang', b.dataset.lang);
        segna();
        if (alCambio) alCambio();
        /* Scelta la lingua, il terzo piano si richiude: ha finito il suo
           lavoro, e lasciarlo aperto ruberebbe spazio all'editor. */
        fcChiudiSecondo(bar);
    });

    if (punt) punt.addEventListener('click', function () {
        ftPrefScrivi('punt', ftPunteggiaturaAuto() ? '0' : '1');
        segna();
    });

    segna();
}

// ================================================================
// COMANDI VOCALI E PUNTEGGIATURA
// ================================================================
/* Dettando non si vede niente: nessun menu, nessun tasto. Quindi due cose
   devono essere vere insieme — che il programma capisca parole dette in modo
   approssimativo, e che l'elenco di cio' che puo' capire sia sempre davanti
   agli occhi. Il pannello dei suggerimenti non e' un abbellimento: senza,
   nessuno indovinerebbe cosa dire. */

function ftSenzaAccenti(s) {
    return String(s || '').toLowerCase()
        .normalize ? String(s || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '')
                   : String(s || '').toLowerCase();
}

/* Distanza di edit, per capire "fracione" quando si e' detto "frazione".
   Il riconoscimento vocale sbaglia una lettera in continuazione, e pretendere
   la parola esatta renderebbe i comandi inutilizzabili. */
function ftDistanza(a, b) {
    var m = a.length, n = b.length;
    if (!m) return n;
    if (!n) return m;
    var riga = [], i, j;
    for (j = 0; j <= n; j++) riga[j] = j;
    for (i = 1; i <= m; i++) {
        var prec = riga[0]; riga[0] = i;
        for (j = 1; j <= n; j++) {
            var tmp = riga[j];
            riga[j] = Math.min(riga[j] + 1, riga[j - 1] + 1, prec + (a[i - 1] === b[j - 1] ? 0 : 1));
            prec = tmp;
        }
    }
    return riga[n];
}

/* Somiglianza sufficiente: una lettera di scarto per le parole corte, due per
   quelle lunghe. Piu' larghi di cosi' e "radice" verrebbe confuso con
   "indice". */
function ftSomiglia(detto, atteso) {
    detto = ftSenzaAccenti(detto); atteso = ftSenzaAccenti(atteso);
    if (detto === atteso) return true;
    /* Una lettera di scarto anche sulle parole corte: "pallina" per "pallino"
       cambia solo la finale, ed e' proprio il tipo di errore che il
       riconoscimento vocale fa in continuazione. Con il limite a zero
       "pallina" non veniva capito. */
    var d = ftDistanza(detto, atteso);
    var lim = atteso.length <= 3 ? 0 : (atteso.length <= 8 ? 1 : 2);
    if (d <= lim) return true;
    /* Stessa radice, finale diversa: "pallino/pallina", "frazione/frazioni". */
    if (atteso.length >= 5 && detto.length >= 5 &&
        detto.slice(0, atteso.length - 1) === atteso.slice(0, atteso.length - 1)) return true;
    return false;
}

// ---------------------------------------------------------------
// Il vocabolario
// ---------------------------------------------------------------
/* Ogni comando ha piu' modi di dirlo: la gente non dice mai la stessa parola,
   e obbligarla a impararne una sola sarebbe il modo piu' rapido per farle
   smettere di usare la dettatura. */
var FT_COMANDI = {
    it: {
        lista:        { dire: ['lista', 'elenco', 'fai una lista', 'inizia lista'], eti: 'lista' },
        puntino:      { dire: ['puntino', 'pallino', 'punto elenco', 'nuovo punto', 'a capo punto'], eti: 'pallino' },
        fineLista:    { dire: ['fine lista', 'fine elenco', 'chiudi lista', 'termina lista'], eti: 'fine lista' },
        formula:      { dire: ['formula', 'formula in linea', 'inizia formula'], eti: 'formula' },
        formulaBlocco:{ dire: ['formula a capo', 'formula a blocco', 'formula centrata'], eti: 'formula a capo' },
        fineFormula:  { dire: ['fine formula', 'chiudi formula', 'termina formula'], eti: 'fine formula' },
        frazione:     { dire: ['frazione', 'fratto', 'fai una frazione'], eti: 'frazione' },
        denominatore: { dire: ['denominatore', 'sotto', 'fratto'], eti: 'denominatore' },
        fineFrazione: { dire: ['fine frazione', 'chiudi frazione'], eti: 'fine frazione' },
        radice:       { dire: ['radice', 'radice quadrata', 'sotto radice'], eti: 'radice' },
        fineRadice:   { dire: ['fine radice', 'chiudi radice'], eti: 'fine radice' },
        potenza:      { dire: ['elevato', 'elevato alla', 'potenza', 'esponente'], eti: 'elevato' },
        finePotenza:  { dire: ['fine potenza', 'fine esponente', 'chiudi potenza'], eti: 'fine potenza' },
        pedice:       { dire: ['pedice', 'con indice', 'sottoscritto'], eti: 'pedice' },
        finePedice:   { dire: ['fine pedice', 'chiudi pedice'], eti: 'fine pedice' },
        integrale:    { dire: ['integrale'], eti: 'integrale' },
        sommatoria:   { dire: ['sommatoria', 'somma da'], eti: 'sommatoria' },
        parentesi:    { dire: ['parentesi', 'apri parentesi'], eti: 'parentesi' },
        fineParentesi:{ dire: ['fine parentesi', 'chiudi parentesi'], eti: 'fine parentesi' }
    },
    en: {
        lista:        { dire: ['list', 'bullet list', 'start list'], eti: 'list' },
        puntino:      { dire: ['bullet', 'new bullet', 'next bullet'], eti: 'bullet' },
        fineLista:    { dire: ['end list', 'close list', 'stop list'], eti: 'end list' },
        formula:      { dire: ['formula', 'inline formula', 'start formula'], eti: 'formula' },
        formulaBlocco:{ dire: ['display formula', 'block formula', 'formula on its own line'], eti: 'display formula' },
        fineFormula:  { dire: ['end formula', 'close formula'], eti: 'end formula' },
        frazione:     { dire: ['fraction', 'over'], eti: 'fraction' },
        denominatore: { dire: ['denominator', 'divided by'], eti: 'denominator' },
        fineFrazione: { dire: ['end fraction', 'close fraction'], eti: 'end fraction' },
        radice:       { dire: ['square root', 'root'], eti: 'root' },
        fineRadice:   { dire: ['end root', 'close root'], eti: 'end root' },
        potenza:      { dire: ['to the power', 'power', 'superscript'], eti: 'power' },
        finePotenza:  { dire: ['end power', 'close power'], eti: 'end power' },
        pedice:       { dire: ['subscript', 'sub'], eti: 'subscript' },
        finePedice:   { dire: ['end subscript', 'close subscript'], eti: 'end subscript' },
        integrale:    { dire: ['integral'], eti: 'integral' },
        sommatoria:   { dire: ['sum', 'summation'], eti: 'sum' },
        parentesi:    { dire: ['bracket', 'open bracket'], eti: 'bracket' },
        fineParentesi:{ dire: ['end bracket', 'close bracket'], eti: 'end bracket' }
    }
};

/* Le lettere greche NON stanno fra i suggerimenti: sono ventiquattro, e
   riempirebbero la barra coprendo i comandi che servono davvero. Restano
   riconosciute: chi sa di doverne dire una la dice, e viene scritta. */
var FT_GRECHE = {
    it: { alfa: 'alpha', beta: 'beta', gamma: 'gamma', delta: 'delta', epsilon: 'epsilon',
          zeta: 'zeta', eta: 'eta', teta: 'theta', theta: 'theta', iota: 'iota', cappa: 'kappa',
          kappa: 'kappa', lambda: 'lambda', mi: 'mu', mu: 'mu', ni: 'nu', nu: 'nu', csi: 'xi',
          xi: 'xi', omicron: 'omicron', pi: 'pi', 'pi greco': 'pi', ro: 'rho', rho: 'rho',
          sigma: 'sigma', tau: 'tau', ipsilon: 'upsilon', fi: 'phi', phi: 'phi', chi: 'chi',
          psi: 'psi', omega: 'omega' },
    en: { alpha: 'alpha', beta: 'beta', gamma: 'gamma', delta: 'delta', epsilon: 'epsilon',
          zeta: 'zeta', eta: 'eta', theta: 'theta', iota: 'iota', kappa: 'kappa', lambda: 'lambda',
          mu: 'mu', nu: 'nu', xi: 'xi', omicron: 'omicron', pi: 'pi', rho: 'rho', sigma: 'sigma',
          tau: 'tau', upsilon: 'upsilon', phi: 'phi', chi: 'chi', psi: 'psi', omega: 'omega' }
};

/* "omega grande" -> \Omega. La maiuscola si chiede a voce, perche' dettando
   non c'e' un tasto Maiusc da premere. */
var FT_GRANDE = { it: ['grande', 'maiuscola', 'maiuscolo'], en: ['capital', 'uppercase', 'big'] };

/* Simboli e operatori dentro le formule. */
var FT_SIMBOLI = {
    it: { /* Gli esponenti detti a parole: "alla terza" e' come si dice a voce,
             e obbligare a "elevato tre fine potenza" per un cubo sarebbe
             assurdo. */
          'al quadrato': '^2', 'alla seconda': '^2', 'al cubo': '^3', 'alla terza': '^3',
          'alla quarta': '^4', 'alla quinta': '^5', 'alla sesta': '^6', 'alla settima': '^7',
          'alla ottava': '^8', 'all ottava': '^8', 'alla nona': '^9', 'alla decima': '^{10}',
          'alla enne': '^n', 'alla ennesima': '^n', 'alla meno uno': '^{-1}',
          'piu': '+', 'più': '+', meno: '-', per: '\\cdot', diviso: '/', uguale: '=',
          'maggiore': '>', 'minore': '<', 'maggiore uguale': '\\geq', 'minore uguale': '\\leq',
          'diverso': '\\neq', infinito: '\\infty',
          'appartiene': '\\in', 'per ogni': '\\forall', 'esiste': '\\exists',
          'tende a': '\\to', 'freccia': '\\to', 'circa': '\\approx', 'virgola': ',', 'punto': '.' },
    en: { 'squared': '^2', 'cubed': '^3', 'to the fourth': '^4', 'to the fifth': '^5',
          'to the n': '^n', 'to the power of n': '^n', 'inverse': '^{-1}',
          plus: '+', minus: '-', times: '\\cdot', 'divided': '/', equals: '=',
          'greater than': '>', 'less than': '<', 'greater or equal': '\\geq', 'less or equal': '\\leq',
          'not equal': '\\neq', infinity: '\\infty',
          'belongs to': '\\in', 'for all': '\\forall', 'there exists': '\\exists',
          'tends to': '\\to', arrow: '\\to', approximately: '\\approx' }
};

/* Da "tre" a "3": in una formula un numero detto a voce deve diventare la
   cifra, non restare la parola scritta com'e'. Le decine e le unita' si
   combinano da sole (venti+tre = ventitre; venti+uno/venti+otto perdono la
   vocale finale della decina, come si scrive davvero in italiano: ventuno,
   ventotto), cosi' non serve elencare a mano cento numeri uno per uno. */
function ftCostruisciNumeriIt() {
    var unita = ['zero', 'uno', 'due', 'tre', 'quattro', 'cinque', 'sei', 'sette', 'otto', 'nove',
                 'dieci', 'undici', 'dodici', 'tredici', 'quattordici', 'quindici', 'sedici',
                 'diciassette', 'diciotto', 'diciannove'];
    var decine = { 2: 'venti', 3: 'trenta', 4: 'quaranta', 5: 'cinquanta', 6: 'sessanta', 7: 'settanta', 8: 'ottanta', 9: 'novanta' };
    var m = {};
    unita.forEach(function (parola, n) { m[parola] = String(n); });
    for (var d = 2; d <= 9; d++) {
        for (var u = 0; u <= 9; u++) {
            var base = decine[d];
            var parola = (u === 1 || u === 8) ? base.slice(0, -1) + unita[u] : base + unita[u];
            m[parola] = String(d * 10 + u);
        }
    }
    m.cento = '100';
    return m;
}
function ftCostruisciNumeriEn() {
    var unita = ['zero', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine',
                 'ten', 'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen', 'sixteen',
                 'seventeen', 'eighteen', 'nineteen'];
    var decine = { 2: 'twenty', 3: 'thirty', 4: 'forty', 5: 'fifty', 6: 'sixty', 7: 'seventy', 8: 'eighty', 9: 'ninety' };
    var m = {};
    unita.forEach(function (parola, n) { m[parola] = String(n); });
    Object.keys(decine).forEach(function (d) {
        m[decine[d]] = String(d * 10);
        /* In inglese decina e unita' restano due parole dette di seguito
           ("twenty" "three"), non una sola come in italiano: la chiave ha
           uno spazio, ed e' lo stesso meccanismo gia' usato per i simboli di
           due parole. */
        for (var u = 1; u <= 9; u++) m[decine[d] + ' ' + unita[u]] = String(d * 10 + u);
    });
    m.hundred = '100';
    return m;
}
var FT_NUMERI = { it: ftCostruisciNumeriIt(), en: ftCostruisciNumeriEn() };

function ftVocabolario(lingua) {
    var c = (lingua || 'it').slice(0, 2).toLowerCase();
    return {
        cmd: FT_COMANDI[c] || FT_COMANDI.it,
        greche: FT_GRECHE[c] || FT_GRECHE.it,
        simboli: FT_SIMBOLI[c] || FT_SIMBOLI.it,
        grande: FT_GRANDE[c] || FT_GRANDE.it,
        numeri: FT_NUMERI[c] || FT_NUMERI.it
    };
}

// ---------------------------------------------------------------
// Quali comandi valgono adesso
// ---------------------------------------------------------------
/* I suggerimenti cambiano con il punto in cui ci si trova: dentro una frazione
   ha senso "denominatore", nel testo normale no. Mostrarli tutti sempre
   costringerebbe a cercare quello giusto ogni volta. */
/* Il colore diverso dei suggerimenti che chiudono o fanno avanzare qualcosa
   (contro chi ne apre uno nuovo) si decide da questo elenco, non da dove il
   comando compare nell'array: in un elenco "fine lista" non e' il primo. */
var FT_COMANDI_CHIUSURA = ['denominatore', 'fineFrazione', 'fineRadice', 'finePotenza',
    'finePedice', 'fineParentesi', 'fineFormula', 'fineLista'];

/* La pila di che cosa e' aperto (una frazione, dentro una radice, dentro
   un'altra frazione...) si RICAVA dal LaTeX vero fino al cursore, non si
   tiene a memoria contando i comandi eseguiti finora. La differenza conta:
   se si chiude una frazione a voce con "fine frazione" la pila si aggiorna,
   ma se invece si chiude scrivendo "}" a mano — perche' si sta correggendo
   la formula, o la si e' aperta col tasto e non a voce — chi contava i
   comandi non se ne accorgerebbe mai, e continuerebbe a proporre "fine
   frazione" su una frazione gia' chiusa da un pezzo. Rileggendo il testo
   ogni volta, il suggeritore vede sempre la formula per quella che e'
   davvero in quel momento, non per come e' stata costruita. */
function ftPilaDaTesto(testo) {
    var pila = [];
    var t = String(testo || '');
    var i = 0, n = t.length;
    while (i < n) {
        if (t.indexOf('\\frac{', i) === i) { pila.push('frac-num'); i += 6; continue; }
        if (t.indexOf('\\sqrt{', i) === i) { pila.push('sqrt'); i += 6; continue; }
        if (t.indexOf('\\left(', i) === i) { pila.push('paren'); i += 6; continue; }
        if (t.indexOf('\\right)', i) === i) {
            if (pila[pila.length - 1] === 'paren') pila.pop();
            i += 7; continue;
        }
        if (t.charAt(i) === '^' && t.charAt(i + 1) === '{') { pila.push('pow'); i += 2; continue; }
        if (t.charAt(i) === '_' && t.charAt(i + 1) === '{') { pila.push('sub'); i += 2; continue; }
        if (t.charAt(i) === '{') { pila.push('brace'); i += 1; continue; }   // graffa scritta a mano
        if (t.charAt(i) === '}') {
            var ultimo = pila[pila.length - 1];
            if (ultimo === 'frac-num' && t.charAt(i + 1) === '{') { pila[pila.length - 1] = 'frac-den'; i += 2; continue; }
            if (ultimo) { pila.pop(); i += 1; continue; }
            i += 1; continue;   // '}' orfana: niente da chiudere, si ignora
        }
        i += 1;
    }
    return pila;
}

/* Trova, a partire dalla posizione del cursore nel documento, la formula che
   lo contiene e restituisce la pila ricavata dal suo testo fino a li'. Null
   se il cursore non e' dentro nessuna formula (il chiamante allora tiene la
   pila che aveva). */
function ftPilaVera(wrap) {
    var ed = ftEditor(wrap);
    if (!ed) return null;
    var sel = fcdGetSel(ed);
    if (!sel || sel.a.b !== sel.f.b || sel.a.o !== sel.f.o) return null;
    var doc = fcdFromDom(ed);
    var blocco = doc.blocks[sel.a.b];
    if (!blocco) return null;
    if (blocco.t === 'fx') return ftPilaDaTesto((blocco.tex || '').slice(0, sel.a.o));
    var acc = 0, runs = blocco.runs || [];
    for (var i = 0; i < runs.length; i++) {
        var len = fcdRunText(runs[i]).length;
        if (typeof runs[i].x === 'string' && sel.a.o >= acc && sel.a.o <= acc + len) {
            return ftPilaDaTesto(runs[i].x.slice(0, sel.a.o - acc));
        }
        acc += len;
    }
    return null;
}

function ftComandiAttivi(stato, perVoce) {
    /* Fuori dalla dettatura i suggerimenti compaiono solo dentro una formula,
       e li' "lista" non vuol dire niente: si puo' solo comporre matematica.
       Mostrarlo sarebbe un invito a premere un tasto che spezza la formula. */
    if (!stato.formula && perVoce === false) return [];

    if (stato.formula) {
        /* Il comando che APRE qualcosa di nuovo vale sempre, a qualunque
           profondita': una formula annidata si costruisce esattamente aprendo
           dell'altro dentro quello che c'e' gia' aperto — una radice dentro un
           numeratore, una frazione dentro quella radice, e cosi' via, senza
           un limite scritto qui.

           Il comando che CHIUDE (o fa avanzare, come "denominatore") invece
           dipende SOLO da cosa sta in cima alla pila: e' li', e non altrove,
           che si trova quello che si sta scrivendo in questo momento. Chiuso
           quello, la cima della pila torna a essere il livello sotto, e il
           suggerimento cambia da solo — e' la pila letta un passo alla volta,
           dall'interno verso l'esterno, a dare l'ordine giusto quando le
           strutture sono annidate: prima si chiude la piu' interna, poi
           quella che la contiene. */
        var apri = ['frazione', 'radice', 'potenza', 'pedice', 'integrale', 'sommatoria', 'parentesi'];
        var ultimo = stato.pila[stato.pila.length - 1];
        var chiudi;
        switch (ultimo) {
            case 'frac-num': chiudi = ['denominatore']; break;
            case 'frac-den': chiudi = ['fineFrazione']; break;
            case 'sqrt':     chiudi = ['fineRadice']; break;
            case 'pow':      chiudi = ['finePotenza']; break;
            case 'sub':      chiudi = ['finePedice']; break;
            case 'paren':    chiudi = ['fineParentesi']; break;
            default:         chiudi = ['fineFormula']; break;
        }
        return chiudi.concat(apri);
    }
    if (stato.lista) return ['puntino', 'fineLista', 'formula', 'formulaBlocco'];
    return ['lista', 'formula', 'formulaBlocco'];
}

/* Riconosce all'inizio della sequenza di parole uno dei comandi validi ora.
   Le espressioni lunghe si provano per prime, o "fine" da sola prenderebbe il
   posto di "fine frazione". */
function ftRiconosci(parole, i, stato, vocab) {
    var attivi = ftComandiAttivi(stato);
    var migliore = null;
    attivi.forEach(function (chiave) {
        var def = vocab.cmd[chiave];
        if (!def) return;
        def.dire.forEach(function (frase) {
            var pezzi = frase.split(' ');
            if (i + pezzi.length > parole.length) return;
            for (var k = 0; k < pezzi.length; k++) {
                if (!ftSomiglia(parole[i + k], pezzi[k])) return;
            }
            if (!migliore || pezzi.length > migliore.quante) migliore = { chiave: chiave, quante: pezzi.length };
        });
    });
    return migliore;
}

/* Dentro una formula una parola sola e lunga e' quasi certamente un comando
   che il riconoscimento vocale ha incollato per sbaglio: "fine frazione" puo'
   arrivare come un'unica parola, "fineestrazione" — la trascrizione sente due
   parole vicine e ogni tanto ne perde il confine. Una VERA variabile
   matematica e' quasi sempre una lettera sola, quindi una parola cosi' lunga
   e' gia' sospetta di suo: vale la pena controllarla contro i comandi attivi
   ANCHE incollati in una parola sola, con una tolleranza piu' larga di quella
   di ftSomiglia — qui la posta in gioco e' piu' alta, o si indovina il
   comando giusto o si scrive spazzatura dentro la formula. E' cio' che vuol
   dire essere "piu' scettici" proprio qui: una parola lunga non si scrive
   subito cosi' com'e', si controlla prima se non sia in realta' un comando
   mancato. */
function ftRiconosciIncollato(parola, stato, vocab) {
    var pulita = ftSenzaAccenti(parola);
    if (pulita.length < 6) return null;   // troppo corta per essere due parole incollate
    var attivi = ftComandiAttivi(stato);
    var migliore = null, migliorDist = Infinity;
    attivi.forEach(function (chiave) {
        var def = vocab.cmd[chiave];
        if (!def) return;
        def.dire.forEach(function (frase) {
            if (frase.indexOf(' ') === -1) return;   // qui interessano solo le frasi di piu' parole
            var incollata = ftSenzaAccenti(frase).replace(/ /g, '');
            var d = ftDistanza(pulita, incollata);
            var lim = Math.max(1, Math.round(incollata.length * 0.3));
            if (d <= lim && d < migliorDist) { migliore = chiave; migliorDist = d; }
        });
    });
    return migliore;
}

// ---------------------------------------------------------------
// Traduzione in LaTeX di cio' che si dice dentro una formula
// ---------------------------------------------------------------
function ftParolaFormula(parole, i, vocab) {
    var p = parole[i];

    /* Un comando LaTeX e' una sequenza di lettere dopo il backslash: senza
       uno spazio dopo, la lettera che viene dopo (un'altra variabile, la
       parola seguente) si salda al nome del comando e lo cambia — "\to"
       seguito da "y" diventerebbe il comando "\toy", che non esiste, invece
       di "\to" e basta. Un simbolo che non e' fatto di sole lettere (^2, +,
       =...) non ha questo problema e resta cosi' com'e'. */
    function conSpazioSeComando(testo) {
        return /^\\[a-zA-Z]+$/.test(testo) ? testo + ' ' : testo;
    }

    // simboli di due parole ("maggiore uguale", "al quadrato")
    for (var frase in vocab.simboli) {
        if (frase.indexOf(' ') === -1) continue;
        var pezzi = frase.split(' ');
        if (i + pezzi.length > parole.length) continue;
        var ok = true;
        for (var k = 0; k < pezzi.length; k++) if (!ftSomiglia(parole[i + k], pezzi[k])) { ok = false; break; }
        if (ok) return { testo: conSpazioSeComando(vocab.simboli[frase]), quante: pezzi.length };
    }

    // numeri di due parole ("twenty three" in inglese; l'italiano li dice
    // gia' come un'unica parola, "ventitre")
    for (var fraseNum in vocab.numeri) {
        if (fraseNum.indexOf(' ') === -1) continue;
        var pezziNum = fraseNum.split(' ');
        if (i + pezziNum.length > parole.length) continue;
        var okNum = true;
        for (var kn = 0; kn < pezziNum.length; kn++) if (!ftSomiglia(parole[i + kn], pezziNum[kn])) { okNum = false; break; }
        if (okNum) return { testo: vocab.numeri[fraseNum], quante: pezziNum.length };
    }

    // lettera greca, eventualmente maiuscola
    for (var g in vocab.greche) {
        if (!ftSomiglia(p, g)) continue;
        var nome = vocab.greche[g];
        var quante = 1;
        if (i + 1 < parole.length) {
            for (var q = 0; q < vocab.grande.length; q++) {
                if (ftSomiglia(parole[i + 1], vocab.grande[q])) {
                    nome = nome.charAt(0).toUpperCase() + nome.slice(1);
                    quante = 2;
                    break;
                }
            }
        }
        return { testo: '\\' + nome + ' ', quante: quante };
    }

    // numero di una parola ("tre" -> "3"): senza, la cifra dettata restava
    // scritta a lettere e la formula non sapeva piu' che farsene
    for (var nu in vocab.numeri) {
        if (nu.indexOf(' ') !== -1) continue;
        if (ftSomiglia(p, nu)) return { testo: vocab.numeri[nu], quante: 1 };
    }

    // simbolo di una parola
    for (var s in vocab.simboli) {
        if (s.indexOf(' ') !== -1) continue;
        if (ftSomiglia(p, s)) return { testo: conSpazioSeComando(vocab.simboli[s]), quante: 1 };
    }

    /* Nient'altro: si scrive com'e'. Sono i nomi delle variabili — "x", "n",
       "T" — che in una formula sono la maggioranza. */
    return { testo: p, quante: 1 };
}

// ---------------------------------------------------------------
// Punteggiatura automatica
// ---------------------------------------------------------------
/* Il riconoscimento vocale consegna ogni pezzo con la maiuscola iniziale,
   perche' crede che ogni pausa sia una frase nuova. Quasi mai lo e': si fa una
   pausa per pensare, non per chiudere un periodo. Da qui i due difetti che si
   vedevano — maiuscole in mezzo alle frasi e nessuna punteggiatura.

   Quello che decide non e' il pezzo in se', ma COME ATTACCA a quello prima:
     - comincia con una congiunzione ("e", "ma", "quindi")  -> virgola, minuscolo
     - il pezzo precedente e' gia' chiuso da . ! ? o a capo -> punto e maiuscolo
     - il precedente e' lungo e questo comincia da capo     -> punto e maiuscolo
     - negli altri casi                                     -> niente, minuscolo
   Non e' comprensione del linguaggio, e non pretende di esserlo: e' un insieme
   di regole che indovina la maggior parte dei casi e sbaglia in modo
   prevedibile, che dettando vale piu' di un modello che sbaglia a sorpresa. */
var FT_CONGIUNZIONI = {
    it: ['e', 'ed', 'ma', 'pero', 'però', 'quindi', 'dunque', 'oppure', 'o', 'mentre', 'che',
         'perche', 'perché', 'poiche', 'poiché', 'se', 'anche', 'cioe', 'cioè', 'ovvero',
         'invece', 'inoltre', 'allora', 'come', 'quando', 'dove', 'con', 'senza', 'per',
         /* Le preposizioni non cominciano quasi mai una frase: "in cui la
            funzione..." e' il seguito di quello che si stava dicendo, non un
            periodo nuovo. Senza di loro il punto cadeva in mezzo alle
            subordinate. */
         'in', 'a', 'ad', 'da', 'di', 'su', 'tra', 'fra', 'nel', 'nella', 'nei', 'negli', 'nelle',
         'del', 'della', 'dei', 'degli', 'delle', 'al', 'alla', 'ai', 'agli', 'alle',
         'dal', 'dalla', 'dai', 'sul', 'sulla', 'col', 'cui'],
    en: ['and', 'but', 'so', 'or', 'while', 'that', 'because', 'if', 'also', 'then',
         'however', 'therefore', 'which', 'when', 'where', 'with', 'without',
         'in', 'on', 'at', 'to', 'of', 'for', 'from', 'by', 'into', 'about']
};
var FT_INIZIO_FRASE = {
    it: ['poi', 'infine', 'adesso', 'ora', 'dopo', 'prima', 'in conclusione', 'per esempio'],
    en: ['then', 'finally', 'now', 'next', 'for example', 'in conclusion']
};

function ftGiunzione(precedente, nuovo, lingua) {
    var c = (lingua || 'it').slice(0, 2).toLowerCase();
    var cong = FT_CONGIUNZIONI[c] || FT_CONGIUNZIONI.it;
    var avvio = FT_INIZIO_FRASE[c] || FT_INIZIO_FRASE.it;

    var pre = String(precedente || '').replace(/[\u200B\s]+$/, '');
    var testo = String(nuovo || '').trim();
    if (!testo) return { segno: '', testo: '' };

    var primaParola = ftSenzaAccenti(testo.split(/\s+/)[0].replace(/[^\p{L}\p{N}]/gu, ''));
    var giaChiuso = pre === '' || /[.!?:;\n\u2026]$/.test(pre);
    var parolePre = pre ? pre.split(/\s+/).length : 0;

    var segno = '', maiuscola;

    if (giaChiuso) {
        segno = '';
        maiuscola = true;
    } else if (cong.indexOf(primaParola) !== -1) {
        /* Una congiunzione non comincia mai una frase nuova: se la si e'
           detta, si sta continuando quella di prima. */
        segno = (primaParola === 'e' || primaParola === 'ed' || primaParola === 'and') ? '' : ',';
        maiuscola = false;
    } else if (avvio.indexOf(primaParola) !== -1 && parolePre >= 3) {
        segno = '.';
        maiuscola = true;
    } else if (parolePre >= 8) {
        /* Un pezzo gia' lungo, seguito da qualcosa che non lo continua: quasi
           sempre e' finita una frase. */
        segno = '.';
        maiuscola = true;
    } else {
        segno = '';
        maiuscola = false;
    }

    testo = maiuscola ? testo.charAt(0).toUpperCase() + testo.slice(1)
                      : testo.charAt(0).toLowerCase() + testo.slice(1);
    return { segno: segno, testo: testo };
}

function ftSetupDictation(wrap, bar) {
    var btn = bar.querySelector('.fmt-mic');
    if (!btn) return;

    var Rec = window.SpeechRecognition || window.webkitSpeechRecognition;
    if (!Rec) return;
    btn.hidden = false;

    var ed = ftEditor(wrap);
    var rec = null, running = false, provv = null;

    /* Dove ci si trova mentre si detta: nel testo, dentro un elenco, dentro
       una formula (e a che punto della formula). E' questo che decide quali
       comandi hanno senso adesso, e quindi cosa mostrare nei suggerimenti. */
    var stato = { lista: false, formula: false, pila: [] };

    function vocab() { return ftVocabolario(ftLinguaScelta()); }

    // ---------- suggerimenti ----------
    /* La si appende al contenitore perche' serve anche a chi entra in una
       formula col tasto, fuori da questa funzione. */
    wrap._ftSugg = function () { aggiornaSuggerimenti(); };
    wrap._ftStato = function () { return stato; };

    function aggiornaSuggerimenti() {
        var cassetto = bar.querySelector('.fmt-panel-2[data-panel2="comandi"] .fmt-sugg');
        if (!cassetto) return;
        if (stato.formula) {
            var pilaVera = ftPilaVera(wrap);
            if (pilaVera) stato.pila = pilaVera;
        }
        var v = vocab();
        cassetto.innerHTML = '';
        var tastoCmd = bar.querySelector('.fmt-comandi-btn');
        var perVoce = !!(tastoCmd && tastoCmd.classList.contains('is-active'));
        ftComandiAttivi(stato, perVoce).forEach(function (ch) {
            var def = v.cmd[ch];
            if (!def) return;
            var b = document.createElement('button');
            b.type = 'button';
            b.tabIndex = -1;   // mai fuoco da solo: vedi il commento in ftSetupToolbar
            b.className = 'fmt-sugg-voce' + (FT_COMANDI_CHIUSURA.indexOf(ch) !== -1 ? ' fmt-sugg-chiudi' : '');
            b.dataset.cmdVoce = ch;
            b.textContent = def.eti;
            b.title = perVoce ? 'Dillo, oppure toccalo' : 'Toccalo per inserirlo';
            cassetto.appendChild(b);
        });
        /* Il cassetto e' alto quanto il suo contenuto: cambiando i
           suggerimenti va rimisurato, o l'ultima riga resterebbe tagliata. */
        var d = bar.querySelector('.fmt-drawer-2');
        var ap = d && d.querySelector('.fmt-panel-2.is-open');
        if (d && ap && ap.dataset.panel2 === 'comandi') d.style.height = ap.offsetHeight + 'px';
    }

    // ---------- il cursore ----------
    /* Accendendo il microfono il cursore deve restare visibile e dov'era: e'
       l'unica cosa che dice DOVE andra' a finire quello che si sta per dire.
       Se non c'era (microfono acceso senza aver mai toccato il testo) lo si
       mette in fondo, invece di lasciare l'utente senza riferimenti. */
    function assicuraCursore() {
        var sel = window.getSelection();
        if (sel && sel.rangeCount && ed.contains(sel.anchorNode)) return;
        var r = document.createRange();
        r.selectNodeContents(ed);
        r.collapse(false);
        sel.removeAllRanges();
        sel.addRange(r);
    }

    /* Se l'utente sposta il cursore mentre detta, il testo deve proseguire
       DA LI'. Prima il punto veniva fissato all'accensione e non cambiava
       piu': cliccare in mezzo al testo non serviva a niente, si continuava a
       scrivere dove si era partiti. */
    function spostaSeIlCursoreSiEMosso() {
        if (!provv || !ed.contains(provv)) return;
        var sel = window.getSelection();
        if (!sel || !sel.rangeCount || !ed.contains(sel.anchorNode)) return;
        var r = sel.getRangeAt(0);
        if (!r.collapsed) return;

        /* Gia' accanto al marcatore: non c'e' niente da spostare. */
        var n = r.startContainer;
        if (n === provv || n.parentNode === provv) return;
        if (n === provv.parentNode) {
            var idx = Array.prototype.indexOf.call(provv.parentNode.childNodes, provv);
            if (r.startOffset === idx || r.startOffset === idx + 1) return;
        }
        if (n.nodeType === 3 && (n.previousSibling === provv || n.nextSibling === provv)) return;

        var r2 = r.cloneRange();
        r2.collapse(true);
        provv.parentNode.removeChild(provv);
        r2.insertNode(provv);
        cursoreDopo(provv);
    }

    function marcatore() {
        if (provv && ed.contains(provv)) { spostaSeIlCursoreSiEMosso(); return provv; }
        provv = document.createElement('span');
        provv.className = 'fc-dict';
        assicuraCursore();
        var sel = window.getSelection();
        var r = sel.getRangeAt(0);
        r.collapse(true);
        r.insertNode(provv);
        cursoreDopo(provv);
        return provv;
    }

    function cursoreDopo(nodo) {
        try {
            var r = document.createRange();
            r.setStartAfter(nodo);
            r.collapse(true);
            var s = window.getSelection();
            s.removeAllRanges();
            s.addRange(r);
        } catch (e) {}
    }

    function chiudiProvvisorio() {
        if (provv && provv.parentNode) provv.parentNode.removeChild(provv);
        provv = null;
    }

    // ---------- scrittura ----------
    function testoPrimaDelCursore() {
        var m = provv && ed.contains(provv) ? provv : null;
        if (!m) return (ed.textContent || '').replace(/\u200B/g, '');
        var pre = '';
        var n = m.previousSibling;
        while (n && pre.length < 200) { pre = (n.textContent || '') + pre; n = n.previousSibling; }
        if (!pre && m.parentNode !== ed) {
            var b = m.parentNode;
            pre = (b.textContent || '');
        }
        return pre.replace(/\u200B/g, '');
    }

    function scriviTesto(t) {
        if (!t) return;

        /* Senza microfono acceso non c'e' nessun testo provvisorio da tenere
           da parte: si scrive dritti al cursore, e cosi' il tasto "annulla"
           del browser continua a funzionare come per il testo digitato. */
        if (!running) {
            ed.focus();
            document.execCommand('insertText', false, t);
            var sel = window.getSelection();
            if (sel && sel.rangeCount) aggiornaTexAttorno(sel.getRangeAt(0).startContainer);
            ftSync(wrap);
            return;
        }

        var m = marcatore();
        m.parentNode.insertBefore(document.createTextNode(t), m);
        cursoreDopo(m);
        aggiornaTexAttorno(m);
        ftSync(wrap);
    }

    /* Se si sta scrivendo DENTRO una formula, il TeX va riportato in
       data-tex: e' li' che il documento va a leggerlo, non in cio' che si
       vede. Senza questo, tutto il LaTeX dettato veniva scritto a schermo e
       poi buttato via al salvataggio — la formula restava vuota. */
    function aggiornaTexAttorno(nodo) {
        var el = nodo && nodo.parentNode;
        while (el && el !== ed && !(el.classList && (el.classList.contains('fc-x') || el.classList.contains('fc-fx')))) el = el.parentNode;
        if (!el || el === ed) return;
        var testo = '';
        el.childNodes.forEach(function (n) {
            if (n.nodeType === 1 && n.classList && n.classList.contains('fc-dict')) return;  // provvisorio
            testo += n.textContent || '';
        });
        el.dataset.tex = testo.replace(/\u200B/g, '');
    }

    /* Un comando cambia la struttura, quindi passa dalle stesse funzioni dei
       tasti della barra: cosi' dettare "lista" e premere il tasto elenco
       producono esattamente la stessa cosa, e non due cose simili. */
    function eseguiStrutturale(fn) {
        chiudiProvvisorio();
        ftCmd(wrap, fn);
        ed.focus();
    }

    function eseguiComando(chiave) {
        var v = vocab();
        switch (chiave) {
            case 'lista':
                eseguiStrutturale(function (d, s) {
                    var blocco = d.blocks[s.a.b];
                    /* "Lista" apre l'elenco dalla prossima frase, non
                       trasforma quella appena detta: se il blocco corrente ha
                       gia' del testo, si va a capo prima di accendere
                       l'elenco — esattamente come dettare "vado a capo, ora
                       faccio un elenco". Su un blocco gia' vuoto (l'inizio
                       della nota, o subito dopo un a capo) accenderlo li' e
                       basta: andare a capo una seconda volta lascerebbe una
                       riga bianca di troppo. */
                    if (blocco && blocco.t !== 'fx' && fcdBlockLen(blocco) > 0) {
                        var r = fcdSplitBlock(d, s);
                        var s2 = { a: r.caret, f: r.caret };
                        return { doc: fcdToggleList(r.doc, s2), sel: s2 };
                    }
                    return { doc: fcdToggleList(d, s), sel: s };
                });
                stato.lista = true;
                break;
            case 'puntino':
                eseguiStrutturale(function (d, s) { return fcdSplitBlock(d, s); });
                break;
            case 'fineLista':
                /* Due volte: la prima chiude il punto corrente, la seconda
                   esce dall'elenco — la stessa cosa che si fa premendo invio
                   due volte a mano. */
                eseguiStrutturale(function (d, s) { return fcdSplitBlock(d, s); });
                eseguiStrutturale(function (d, s) { return fcdSplitBlock(d, s); });
                stato.lista = false;
                break;
            case 'formula':
                eseguiStrutturale(function (d, s) { return fcdToggleInlineMath(d, s); });
                stato.formula = true; stato.pila = [];
                break;
            case 'formulaBlocco':
                eseguiStrutturale(function (d, s) { return fcdToggleBlockMath(d, s); });
                stato.formula = true; stato.pila = [];
                break;
            case 'fineFormula':
                /* Prima si chiude quello che era rimasto aperto dentro la
                   formula: una graffa dimenticata la rende illeggibile. */
                while (stato.pila.length) { scriviTesto('}'); stato.pila.pop(); }
                chiudiProvvisorio();
                ftUsciDallaFormula(wrap);
                stato.formula = false;
                break;
            case 'frazione':      scriviTesto('\\frac{'); stato.pila.push('frac-num'); break;
            case 'denominatore':  scriviTesto('}{');      stato.pila[stato.pila.length - 1] = 'frac-den'; break;
            case 'fineFrazione':  scriviTesto('}');       stato.pila.pop(); break;
            case 'radice':        scriviTesto('\\sqrt{'); stato.pila.push('sqrt'); break;
            case 'fineRadice':    scriviTesto('}');       stato.pila.pop(); break;
            case 'potenza':       scriviTesto('^{');      stato.pila.push('pow'); break;
            case 'finePotenza':   scriviTesto('}');       stato.pila.pop(); break;
            case 'pedice':        scriviTesto('_{');      stato.pila.push('sub'); break;
            case 'finePedice':    scriviTesto('}');       stato.pila.pop(); break;
            case 'parentesi':     scriviTesto('\\left('); stato.pila.push('paren'); break;
            case 'fineParentesi': scriviTesto('\\right)');stato.pila.pop(); break;
            case 'integrale':     scriviTesto('\\int '); break;
            case 'sommatoria':    scriviTesto('\\sum '); break;
        }
        aggiornaSuggerimenti();
    }

    /* Il pezzo definitivo: si scorre parola per parola cercando comandi, e
       quello che non e' un comando e' testo. Dentro una formula il testo passa
       prima dal traduttore in LaTeX. */
    function elabora(frase) {
        var v = vocab();
        var parole = String(frase).trim().split(/\s+/).filter(Boolean);
        if (!parole.length) return;

        /* "A capo"/"nuova riga": un a capo VERO, non un carattere infilato nel
           testo — vedi il commento su ftUtteranzaACapo per il perche'. Va
           controllato PRIMA di ogni altra cosa, sempre (con o senza
           l'automatismo di punteggiatura, con o senza i comandi vocali
           accesi): dettarlo deve andare a capo punto e basta, non essere
           un'opzione fra tante. Dentro una formula "a capo" non significa
           niente di strutturale — la' e' gia' gestito da "fine formula" e
           dai suoi comandi — quindi non si intercetta. */
        if (!stato.formula && ftUtteranzaACapo(parole.join(' '), ftLinguaScelta())) {
            eseguiStrutturale(function (d, s) { return fcdSplitBlock(d, s); });
            return;
        }

        var comandiAttivi = ftComandiAccesi();
        var buffer = [];

        function svuota() {
            if (!buffer.length) return;
            if (stato.formula) {
                /* In formula ogni parola vale un simbolo: gli spazi fra i
                   simboli LaTeX non servono e sporcherebbero il codice. */
                var out = '', i = 0;
                while (i < buffer.length) {
                    var r = ftParolaFormula(buffer, i, v);
                    out += r.testo;
                    i += r.quante;
                }
                scriviTesto(out);
            } else {
                var testo = buffer.join(' ');
                if (!ftPunteggiaturaAuto()) {
                    /* Il riconoscimento vocale consegna ogni pezzo con la
                       maiuscola iniziale, perche' crede che ogni pausa sia
                       una frase nuova. Con la punteggiatura automatica accesa
                       ci pensa ftGiunzione a decidere; spenta, nessuno
                       decideva niente e quella maiuscola restava — anche in
                       mezzo a una parola gia' cominciata. Spenta la
                       punteggiatura si scrive sempre minuscolo: le maiuscole
                       che servono le mette chi scrive, mentre quelle messe a
                       sproposito vanno tolte a mano una per una. */
                    var grezzo = ftSegniDettati(testo, ftLinguaScelta());
                    grezzo = grezzo.charAt(0).toLowerCase() + grezzo.slice(1);
                    scriviTesto((testoPrimaDelCursore().replace(/\s+$/, '') ? ' ' : '') + grezzo);
                } else {
                    /* Anche con la punteggiatura automatica accesa, un segno
                       DETTATO A PAROLE ("punto", "virgola"...) resta un
                       comando esplicito, non una frase da punteggiare da
                       sola. ftGiunzione non lo sapeva distinguere da una
                       parola qualunque: "punto" detto dopo una frase gia'
                       lunga (8 parole o piu') cadeva nella sua regola "pezzo
                       lungo, quindi periodo nuovo" — un punto VERO messo
                       PRIMA — e poi scriveva comunque la parola "punto" come
                       se fosse il contenuto della frase. Risultato: il
                       segno E la parola, uno via l'altro, esattamente il
                       doppione segnalato. Qui si controlla PRIMA se tutto
                       quello che si e' detto e' solo quel segno: se lo e',
                       si scrive solo il segno, attaccato a quello che c'era
                       gia', senza far girare l'automatismo su un contenuto
                       che contenuto non e'. */
                    var segnoSolo = ftSegnoDaSolo(testo, ftLinguaScelta());
                    if (segnoSolo != null) {
                        scriviTesto(segnoSolo);
                    } else {
                        var g = ftGiunzione(testoPrimaDelCursore(), testo, ftLinguaScelta());
                        scriviTesto(g.segno + (testoPrimaDelCursore() ? ' ' : '') + g.testo);
                    }
                }
            }
            buffer = [];
        }

        /* Fuori dalle formule un comando conta solo se e' TUTTO quello che si
           e' detto in quel respiro. Altrimenti "la formula e' questa" aprirebbe
           una formula in mezzo alla frase: la parola "formula" e' comunissima
           in un testo di matematica, e va distinta dal comando. Dentro una
           formula invece si riconoscono anche in mezzo, perche' li' ogni parola
           e' gia' un simbolo e non una frase. */
        if (!stato.formula && comandiAttivi) {
            var soloComando = ftRiconosci(parole, 0, stato, v);
            if (soloComando && soloComando.quante === parole.length) {
                eseguiComando(soloComando.chiave);
                return;
            }
            /* "Pallino" e' un'eccezione voluta a questa regola: a differenza
               di "formula" o "lista", nessuno lo dice per altro che aprire un
               punto elenco — non e' una parola che possa capitare per caso in
               mezzo a una frase di matematica. Riconoscerlo anche quando non
               e' tutto il respiro (["pallino","cane"], non solo ["pallino"])
               permette di dettare il punto e il suo contenuto in un fiato
               solo, com'e' naturale fare: si esegue il comando per aprire il
               punto e si continua a elaborare quello che resta come il testo
               da scriverci dentro. Senza questa eccezione l'intera frase,
               "pallino" compreso, finiva scritta alla lettera invece di
               aprire il punto — il "pallino cane" scritto invece di un
               nuovo punto elenco con dentro "cane". */
            if (soloComando && soloComando.chiave === 'puntino' && soloComando.quante < parole.length) {
                eseguiComando(soloComando.chiave);
                parole = parole.slice(soloComando.quante);
                if (!parole.length) return;
            } else {
                comandiAttivi = false;
            }
        }

        var i = 0;
        while (i < parole.length) {
            var trovato = comandiAttivi ? ftRiconosci(parole, i, stato, v) : null;
            /* Se qui e QUESTA parola non e' bastata a trovare un comando, ma
               siamo dentro una formula, si prova a leggerla come se fossero
               due parole di comando rimaste incollate (vedi sopra il
               perche'). Solo dentro una formula: fuori, una parola lunga e'
               semplicemente una parola, non c'e' motivo di sospettarla. */
            if (!trovato && stato.formula && comandiAttivi) {
                var chiaveIncollata = ftRiconosciIncollato(parole[i], stato, v);
                if (chiaveIncollata) trovato = { chiave: chiaveIncollata, quante: 1 };
            }
            if (trovato) {
                svuota();
                eseguiComando(trovato.chiave);
                i += trovato.quante;
                continue;
            }
            buffer.push(parole[i]);
            i++;
        }
        svuota();
    }

    function ftComandiAccesi() {
        var b = bar.querySelector('.fmt-comandi-btn');
        return !!(b && b.classList.contains('is-active'));
    }

    // ---------- avvio e arresto ----------
    function stop(perPerditaFocus) {
        running = false;
        btn.classList.remove('is-rec');
        wrap.classList.remove('sta-dettando');
        chiudiProvvisorio();
        if (rec) { try { rec.stop(); } catch (e) {} }
        /* Lingua e comandi servono solo mentre si sta dettando: spento il
           microfono, restare aperti li' sotto sarebbe uno stato fermo a
           meta', come un menu che continua a mostrarsi dopo aver chiuso
           quello che lo conteneva. */
        fcChiudiSecondo(bar);
        var doc = fcdFromDom(ed);
        var sel = fcdGetSel(ed);
        ed.innerHTML = fcdToHTML(doc);
        ftSync(wrap, doc);
        /* Rimettere il cursore dov'era significa riprendersi il focus, ed e'
           giusto quando si spegne il microfono di proposito. Se invece ci si
           sta spegnendo PROPRIO PERCHE' il focus se n'e' andato altrove,
           riprenderselo lo strapperebbe di mano a chi ha appena toccato
           un'altra casella. */
        if (sel && !perPerditaFocus) { ed.focus(); try { fcdSetSel(ed, sel); } catch (e) {} }
        ftMathRefresh(wrap);
    }

    /* Il microfono appartiene alla SUA casella: se si va a scrivere altrove
       deve spegnersi, o si continua a dettare dentro una casella che non si
       sta piu' guardando. Lo spegnimento e' esposto sul wrap perche' chi se
       ne accorge (il blur dell'editor) sta in un'altra funzione. */
    wrap._ftFermaDettatura = function () {
        if (!running) return;
        /* Sui telefoni la richiesta del permesso, e la barra del microfono
           di sistema, tolgono il focus alla pagina proprio nell'istante in
           cui si accende: senza questa finestra di grazia il microfono si
           spegnerebbe da solo un attimo dopo essere partito. */
        if (Date.now() - ultimoAvvio < 700) return;
        stop(true);
    };


    /* I suggerimenti si possono anche toccare: a volte la parola non viene
       riconosciuta, e restare bloccati sarebbe frustrante. */
    bar.addEventListener('click', function (e) {
        var s = e.target.closest('.fmt-sugg-voce');
        if (!s) return;
        e.preventDefault();
        ed.focus();
        eseguiComando(s.dataset.cmdVoce);
    });

    var tastoCmd = bar.querySelector('.fmt-comandi-btn');
    if (tastoCmd) tastoCmd.addEventListener('click', function () {
        var on = !tastoCmd.classList.contains('is-active');
        tastoCmd.classList.toggle('is-active', on);
        tastoCmd.setAttribute('aria-pressed', on ? 'true' : 'false');
        if (on) { aggiornaSuggerimenti(); fcApriSecondo(bar, 'comandi'); }
        else fcChiudiSecondo(bar);
    });

    btn.addEventListener('mousedown', function (e) { e.preventDefault(); });

    /* Il microfono si accende su "click", ma NON solo su quello.
       In una pagina piena di gestori di gesti (big_edit intercetta pointerdown
       e touchmove su tutta la riga) un click puo' non arrivare mai al tasto: e
       un tasto che non fa niente e' indistinguibile da un tasto rotto. Con
       pointerup come rete di sicurezza si accende comunque, e il guardiano
       qui sotto evita che i due eventi lo accendano e spengano in sequenza. */
    var ultimoAvvio = 0;
    function accendiOSpegni() {
        /* Il pannello si apre da QUI, non (solo) dal click delegato sulla
           barra: quel click, su una pagina come big_edit che intercetta
           pointerdown e touchmove su tutta la riga, puo' non arrivare mai a
           destinazione — ed e' scritto esplicitamente nel commento sopra,
           proprio per questo pointerup e' stato messo come rete di
           sicurezza per accendere il microfono. La stessa rete deve valere
           anche per aprire la sua sezione, o si finiva con la registrazione
           che si accende e si spegne regolarmente mentre il pannello resta
           chiuso la meta' delle volte. */
        if (!fcPannelloAperto(bar, 'mic')) fcOpenPanel(bar, 'mic');

        var ora = Date.now();
        if (ora - ultimoAvvio < 350) return;   // stesso gesto, due eventi
        ultimoAvvio = ora;
        avvia();
    }
    btn.addEventListener('pointerup', function (e) {
        if (e.pointerType === 'mouse' && e.button !== 0) return;
        accendiOSpegni();
    });
    btn.addEventListener('click', function () { accendiOSpegni(); });

    function avvia() {
        if (running) { stop(); return; }

        /* Su un indirizzo che il browser non considera sicuro (niente https,
           e non "localhost") molti browser negano il microfono SENZA MAI
           CHIEDERE IL PERMESSO: e' una politica del browser stessa, non
           qualcosa che dipenda da un permesso negato in passato o da questo
           codice. E' la causa piu' comune di "sul telefono funziona, sul
           computer no e non chiede nemmeno" — sul telefono probabilmente si
           apre la pagina in https, sul computer magari con l'indirizzo della
           rete locale, che https non ce l'ha. Si dice subito, invece di
           lasciare che l'errore generico di permesso neghi la causa vera. */
        if (window.isSecureContext === false) {
            btn.title = 'Il microfono richiede una connessione sicura (https, oppure "localhost" se sei in locale): su una connessione non sicura il browser lo blocca senza nemmeno chiedere il permesso';
            btn.classList.add('fmt-mic-negato');
            return;
        }

        ed.focus();
        assicuraCursore();
        marcatore();                      // il cursore resta visibile, dov'era
        wrap.classList.add('sta-dettando');

        stato = { lista: false, formula: false, pila: [] };
        var bl = fcdFromDom(ed).blocks[(fcdGetSel(ed) || { a: { b: 0 } }).a.b];
        if (bl && bl.t === 'li') stato.lista = true;
        if (bl && bl.t === 'fx') stato.formula = true;
        aggiornaSuggerimenti();

        rec = new Rec();
        rec.lang = ftLinguaScelta();
        rec.continuous = true;
        rec.interimResults = true;

        rec.onresult = function (e) {
            var fin = '', interim = '';
            for (var i = e.resultIndex; i < e.results.length; i++) {
                var pezzo = e.results[i][0].transcript;
                if (e.results[i].isFinal) fin += pezzo + ' '; else interim += pezzo;
            }
            if (fin.trim()) elabora(fin);
            var m = marcatore();
            m.textContent = interim ? ' ' + interim : '';
        };
        /* Non tutti gli "errori" sono errori. Chrome manda "no-speech" a ogni
           pausa di silenzio e "aborted" ogni volta che il riconoscimento si
           riavvia: spegnere il microfono in quei casi lo faceva morire da
           solo dopo pochi secondi, ed e' il motivo per cui sembrava non
           accendersi piu'. Si spegne solo per i motivi veri — permesso
           negato, microfono assente — e si dice quale, invece di sparire in
           silenzio. */
        rec.onerror = function (e) {
            var causa = (e && e.error) || '';
            if (causa === 'no-speech' || causa === 'aborted' || causa === 'network') return;

            if (causa === 'not-allowed' || causa === 'service-not-allowed') {
                /* Stesso identico errore del browser sia che il permesso sia
                   stato davvero negato, sia che la pagina non sia servita in
                   https: senza distinguerli, chi sviluppa in locale con
                   l'indirizzo di rete legge "permesso negato" e va a cercare
                   il problema nel posto sbagliato. */
                btn.title = window.isSecureContext === false
                    ? 'Il microfono richiede una connessione sicura (https, oppure "localhost" se sei in locale): su una connessione non sicura il browser lo blocca senza nemmeno chiedere il permesso'
                    : 'Permesso per il microfono negato: va concesso dalle impostazioni del browser';
                btn.classList.add('fmt-mic-negato');
            } else if (causa === 'audio-capture') {
                btn.title = 'Nessun microfono disponibile';
                btn.classList.add('fmt-mic-negato');
            }
            stop();
        };

        /* Il riconoscimento si chiude da solo di continuo (dopo ogni frase, e
           dopo ogni silenzio): finche' l'utente non ha spento, si riparte.
           Il piccolo ritardo evita l'errore "gia' avviato" quando la chiusura
           e' ancora in corso. */
        rec.onend = function () {
            if (!running) return;
            setTimeout(function () {
                if (!running) return;
                try { rec.start(); } catch (err) { if (String(err).indexOf('already') === -1) stop(); }
            }, 150);
        };

        try {
            rec.start();
            running = true;
            btn.classList.remove('fmt-mic-negato');
            btn.classList.add('is-rec');
        } catch (e) {
            /* Se non parte, si dice perche'. Sparire in silenzio lasciando un
               tasto inerte e' il modo peggiore di fallire: chi lo usa non ha
               nessun modo di capire se e' rotto il programma o il permesso. */
            btn.title = 'Dettatura non avviata: ' + (e && e.message ? e.message : e);
            btn.classList.add('fmt-mic-negato');
            if (window.console && console.warn) console.warn('Dettatura:', e);
            stop();
        }
    }

    /* Il pannello si costruisce DOPO aver collegato il tasto al microfono, e
       protetto. Prima veniva prima: bastava un intoppo qui dentro - un pezzo
       di markup diverso, una preferenza salvata strana - perche' l'eccezione
       interrompesse tutto il resto e il tasto restasse senza gestore, cioe un
       tasto che cliccato non fa assolutamente niente. Ora, nel peggiore dei
       casi si perdono le impostazioni ma la dettatura parte lo stesso. */
    try {
        ftCostruisciPannelloMic(bar, function () {
            aggiornaSuggerimenti();
            if (!running || !rec) return;
            try { rec.abort ? rec.abort() : rec.stop(); } catch (e) {}
            setTimeout(function () {
                if (!running) return;
                rec.lang = ftLinguaScelta();
                try { rec.start(); } catch (e) {}
            }, 120);
        });
    } catch (e) {
        if (window.console && console.warn) console.warn('Pannello dettatura non costruito:', e);
    }
}

/* Porta il cursore fuori dalla formula, subito dopo. Serve a "fine formula":
   restando dentro, il testo seguente continuerebbe a essere codice LaTeX. */
function ftUsciDallaFormula(wrap) {
    var ed = ftEditor(wrap);
    var sel = window.getSelection();
    if (!sel || !sel.rangeCount) return;
    var n = sel.anchorNode;
    var el = n && (n.nodeType === 1 ? n : n.parentNode);
    var f = el && el.closest ? el.closest('.fc-x, .fc-fx') : null;
    if (!f) return;

    if (f.classList.contains('fc-fx')) {
        /* Formula a blocco: si esce aprendo un paragrafo sotto. */
        ftCmd(wrap, function (d, s) {
            d.blocks.splice(s.a.b + 1, 0, { t: 'p', runs: [] });
            return { doc: fcdNorm(d), caret: { b: s.a.b + 1, o: 0 } };
        });
        return;
    }
    var r = document.createRange();
    r.setStartAfter(f);
    r.collapse(true);
    sel.removeAllRanges();
    sel.addRange(r);
}

// ================================================================
// EDITOR — sottile per scelta.
//
// Tutta l'intelligenza sta in fc_doc.js: qui ci sono solo il collegamento fra
// i tasti e le operazioni, e la gestione del cursore. E' il motivo per cui
// questo file e' un decimo di quello di prima: i casi particolari non si
// trattano uno per uno, non esistono proprio.
//
// COME FUNZIONA, IN DUE RIGHE
//
// Mentre si scrive nessuno tocca niente: il browser fa il suo mestiere e il
// contenuto viene riletto nel modello a ogni battuta (fcdFromDom). Quando si
// preme un tasto della barra, invece, il giro e': leggi il modello, applica
// l'operazione, ridisegna, rimetti il cursore dov'era. Ridisegnare solo sui
// comandi e non a ogni lettera e' cio' che tiene la scrittura fluida e non
// litiga con la tastiera dei telefoni.
// ================================================================

function ftEditor(wrap) { return document.getElementById(wrap.dataset.editorId); }
function ftHidden(wrap) { return document.getElementById(wrap.dataset.field); }

/* La barra di questa casella, ovunque sia. Quasi sempre e' dentro il wrap,
   ma mentre e' agganciata in cima allo schermo vive fuori di li' (vedi
   .fmt-portale): un wrap.querySelector('.fmt-toolbar') in quel momento
   restituirebbe null, e chi lo chiama — l'aggiornamento dei tasti accesi, la
   chiusura dei suggerimenti, il tastino apri/chiudi — si spegnerebbe in
   silenzio proprio mentre si sta scrivendo. L'id invece non cambia mai. */
function ftBarra(wrap) {
    if (!wrap) return null;
    var b = wrap.querySelector('.fmt-toolbar');
    if (b) return b;
    return wrap.dataset.editorId ? document.getElementById(wrap.dataset.editorId + '_bar') : null;
}

/* Il campo nascosto e' l'unica cosa che il server vedra': va tenuto allineato
   sempre, non solo al salvataggio, o un invio partito mentre il fuoco e'
   ancora nell'editor spedirebbe la versione precedente. */
function ftSync(wrap, doc) {
    var ed = ftEditor(wrap);
    if (!doc) doc = fcdFromDom(ed);
    var h = ftHidden(wrap);
    if (h) h.value = fcdStringify(doc);
    var vuoto = doc.blocks.length === 1 && doc.blocks[0].t === 'p' && fcdBlockText(doc.blocks[0]) === '';
    ed.dataset.vuoto = vuoto ? '1' : '0';
    return doc;
}

function ftRender(wrap, doc, caret) {
    var ed = ftEditor(wrap);
    ed.innerHTML = fcdToHTML(doc);
    if (caret) fcdSetSel(ed, caret.a ? caret : { a: caret, f: caret });
    ftSync(wrap, doc);
}

/* Ogni comando passa di qui. La funzione riceve (documento, selezione) e
   restituisce il documento nuovo, eventualmente con dove mettere il cursore.
   Un solo posto in cui si legge, si applica e si ridisegna: se il cursore
   saltasse, salterebbe qui e in nessun altro punto. */
function ftCmd(wrap, fn) {
    var ed = ftEditor(wrap);

    /* Se si sta scrivendo dentro MathQuill, il blocco su cui il comando deve
       agire e' LA FORMULA — ma la selezione del documento non lo sa: sta
       nella casella nascosta di MathQuill e indica tutt'altro (di solito il
       primo blocco). Peggio: il ed.focus() qui sotto strappa il fuoco a
       MathQuill, e da quel momento la posizione e' persa del tutto.
       Percio' PRIMA si chiude ordinatamente il campo (il suo LaTeX finisce in
       data-tex, cosi' non si perde niente) e si ricorda su quale blocco si
       stava lavorando; poi il comando parte da li'. E' questo che faceva
       finire il pallino sul testo invece che sulla formula, e le conversioni
       fra formula in linea e a blocco in un punto qualunque della casella. */
    var biMQ = null;
    var campoMQ = ftMQCampoAttivo(ed);
    if (campoMQ && ftMQHaIlFuoco(campoMQ)) {
        var elBloccoMQ = campoMQ.classList.contains('fc-fx')
            ? campoMQ
            : (campoMQ.closest ? campoMQ.closest('.fc-p, .fc-li, .fc-fx') : null);
        biMQ = elBloccoMQ ? fcdBlockEls(ed).indexOf(elBloccoMQ) : -1;
        if (biMQ === -1) biMQ = null;
        ftMQSmonta(campoMQ);
    }

    ed.focus();
    var sel = fcdGetSel(ed) || { a: { b: 0, o: 0 }, f: { b: 0, o: 0 } };
    if (biMQ != null) {
        /* La posizione letta adesso non vale niente (il fuoco e' appena
           cambiato): si usa il blocco ricordato prima. */
        var docTmp = fcdFromDom(ed);
        var bloccoMQ = docTmp.blocks[biMQ];
        var fineMQ = bloccoMQ ? (bloccoMQ.t === 'fx' ? 0 : fcdBlockLen(bloccoMQ)) : 0;
        sel = { a: { b: biMQ, o: 0 }, f: { b: biMQ, o: fineMQ } };
    }
    var doc = fcdFromDom(ed);
    var out = fn(doc, sel);
    if (!out) return;
    var nuovo = out.blocks ? out : out.doc;
    var dove = out.caret ? { a: out.caret, f: out.caret } : (out.sel || sel);
    ftRender(wrap, nuovo, dove);
    if (out.dentro) ftCaretInEmptyMath(ed, out.caret);
    if (out.dopoFormula) ftCaretDopoFormula(ed, out.caret);
    ftMathRefresh(wrap);

    /* Digitare genera da solo l'evento 'input' del browser; un comando della
       barra no, perche' scrive nel DOM direttamente. Lo generiamo a mano,
       cosi' chi ascolta 'input' per sapere se qualcosa e' cambiato (come
       #fcRows in big_edit_fc.php) si accorge anche di un comando e non solo
       di quello che si digita. */
    ed.dispatchEvent(new Event('input', { bubbles: true }));
}

/* Mette il cursore SUBITO DOPO la formula che finisce in quel punto. Chiudendo
   una formula scrivendo il secondo dollaro si vuole continuare a scrivere
   testo: lasciando il cursore al confine, il browser lo tiene dentro e tutto
   quello che si scrive dopo finisce nel LaTeX. */
function ftCaretDopoFormula(ed, pos) {
    var els = Array.prototype.filter.call(ed.childNodes, function (n) { return n.nodeType === 1; });
    var blocco = els[pos.b];
    if (!blocco) return;
    var acc = 0, trovato = null;
    Array.prototype.slice.call(blocco.childNodes).forEach(function (n) {
        var len = fcdTextLen(n);
        if (!trovato && n.nodeType === 1 && n.classList && n.classList.contains('fc-x') && acc + len === pos.o) trovato = n;
        acc += len;
    });
    if (!trovato) return;
    try {
        var r = document.createRange();
        /* Non basta "subito dopo l'elemento": quel punto il browser lo
           considera ancora dentro, e il primo carattere digitato finisce nel
           LaTeX. Ci si appoggia al carattere invisibile che sta gia' dopo ogni
           formula proprio per questo. */
        var dopo = trovato.nextSibling;
        if (dopo && dopo.nodeType === 3 && dopo.nodeValue.charAt(0) === '\u200B') {
            r.setStart(dopo, 1);
        } else {
            var appoggio = document.createTextNode('\u200B');
            trovato.parentNode.insertBefore(appoggio, trovato.nextSibling);
            r.setStart(appoggio, 1);
        }
        r.collapse(true);
        var sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(r);
    } catch (e) {}
}

/* Mette il cursore DENTRO la formula vuota appena creata. Serve un giro nel
   DOM perche' un elemento lungo zero non ha una posizione propria nel conto
   dei caratteri: si cerca l'elemento vuoto che sta a quel punto. */
function ftCaretInEmptyMath(ed, pos) {
    var els = Array.prototype.filter.call(ed.childNodes, function (n) { return n.nodeType === 1; });
    var blocco = els[pos.b];
    if (!blocco) return;
    var acc = 0, trovato = null;
    Array.prototype.slice.call(blocco.childNodes).forEach(function (n) {
        /* Stessa regola di tutto il resto: una formula vale il suo LaTeX. Col
           textContent crudo, una formula gia' disegnata piu' indietro nella
           riga valeva quanto il disegno e questo conto finiva sfasato. */
        var len = fcdTextLen(n);
        if (!trovato && n.nodeType === 1 && n.classList && n.classList.contains('fc-x') && len === 0 && acc === pos.o) trovato = n;
        acc += len;
    });
    if (!trovato) return;
    var r = document.createRange();
    /* Dentro l'elemento serve un appiglio: un elemento vuoto non ha un punto
       in cui stare, e il cursore scivolerebbe fuori al primo carattere. */
    var appiglio = document.createTextNode('');
    trovato.appendChild(appiglio);
    r.setStart(appiglio, 0);
    r.collapse(true);
    var s = window.getSelection();
    s.removeAllRanges();
    s.addRange(r);
}

// ---------------------------------------------------------------
// I marchi a cursore fermo
// ---------------------------------------------------------------
/* Quando non c'e' niente di selezionato, "grassetto" non puo' cambiare del
   testo che non esiste ancora: deve armare la formattazione per quello che si
   scrivera' dopo. Si fa mettendo il cursore dentro un pezzo gia' marcato,
   vuoto. Ricliccando, il cursore esce da quel pezzo — ed e' esattamente il
   "clicco bold, scrivo, riclicco e si disattiva" che mancava.

   Il carattere invisibile serve solo a dare al pezzo vuoto un posto dove
   stare: fcdNorm lo toglie dal modello, quindi nel salvato non finisce mai. */
var FT_ZW = '\u200B';

function ftMarksAtCaret(ed) {
    var s = window.getSelection();
    if (!s || !s.rangeCount) return {};
    var n = s.anchorNode;
    var el = n && (n.nodeType === 1 ? n : n.parentNode);
    var t = el && el.closest ? el.closest('.fc-t') : null;
    if (!t || !ed.contains(t)) return {};
    var m = {};
    ['b', 'i', 'u', 'h'].forEach(function (k) { if (t.dataset[k]) m[k] = 1; });
    if (t.dataset.c) m.c = t.dataset.c;
    return m;
}

function ftInsertMarked(ed, marks) {
    var s = window.getSelection();
    if (!s || !s.rangeCount) return;
    var r = s.getRangeAt(0);
    r.deleteContents();

    var nodo;
    if (marks && Object.keys(marks).length) {
        nodo = document.createElement('span');
        nodo.className = 'fc-t';
        ['b', 'i', 'u', 'h'].forEach(function (k) { if (marks[k]) nodo.dataset[k] = '1'; });
        if (marks.c) nodo.dataset.c = marks.c;
        nodo.textContent = FT_ZW;
    } else {
        nodo = document.createTextNode(FT_ZW);
    }

    /* Se il cursore sta dentro un pezzo marcato e la formattazione nuova e'
       diversa, il pezzo nuovo va messo FUORI da quello vecchio: dentro,
       erediterebbe quello che stiamo togliendo. */
    var el = r.startContainer.nodeType === 1 ? r.startContainer : r.startContainer.parentNode;
    var dentro = el && el.closest ? el.closest('.fc-t') : null;
    if (dentro && ed.contains(dentro)) {
        dentro.parentNode.insertBefore(nodo, dentro.nextSibling);
    } else {
        r.insertNode(nodo);
    }

    var r2 = document.createRange();
    var target = nodo.nodeType === 1 ? nodo.firstChild : nodo;
    r2.setStart(target, target.length);
    r2.collapse(true);
    s.removeAllRanges();
    s.addRange(r2);
}

function ftToggleMarkCaret(wrap, key, val) {
    var ed = ftEditor(wrap);
    var m = ftMarksAtCaret(ed);
    var acceso = (val === undefined) ? !!m[key] : (m[key] === val);
    if (acceso) delete m[key];
    else m[key] = (val === undefined ? 1 : val);
    ftInsertMarked(ed, m);
    ftSync(wrap);
}

function ftMark(wrap, key, val) {
    var ed = ftEditor(wrap);
    /* Fermo o selezionato lo decide il DOM, non il modello. Con il modello si
       poteva credere che il cursore fosse fermo mentre nel DOM c'era del testo
       selezionato: il ramo "cursore fermo" chiama deleteContents(), e su una
       selezione larga significa cancellare quello che l'utente aveva scelto.
       E' successo davvero, con "seleziona tutto" e poi grassetto. */
    var s = window.getSelection();
    var collassata = !s || !s.rangeCount || s.getRangeAt(0).collapsed || !ed.contains(s.anchorNode);
    if (collassata) { ed.focus(); ftToggleMarkCaret(wrap, key, val); return; }
    ftCmd(wrap, function (doc, sl) { return { doc: fcdToggleMark(doc, sl, key, val), sel: sl }; });
}

/* Come ftMark, ma non alterna mai: mette sempre il valore dato, tolto solo
   quando val e' quello del colore di default (undefined/null). Serve ai
   pallini colore: cliccando piu' volte lo stesso colore su un testo
   selezionato, ftMark lo avrebbe tolto al secondo click (e' un interruttore,
   "guarda com'e' e fa il contrario" — bene per grassetto/corsivo, dove
   esistono solo due stati, ma un colore non e' un interruttore: e' una
   scelta fra tante, e ricliccare lo stesso rosso deve confermarlo, non
   spegnerlo. Per toglierlo davvero si tocca il pallino del colore di
   default, che passa val=undefined e finisce comunque nel "cancella". */
function ftMarkImposta(wrap, key, val) {
    var ed = ftEditor(wrap);
    var s = window.getSelection();
    var collassata = !s || !s.rangeCount || s.getRangeAt(0).collapsed || !ed.contains(s.anchorNode);
    if (collassata) {
        ed.focus();
        var m = ftMarksAtCaret(ed);
        if (val === null || val === undefined) delete m[key]; else m[key] = val;
        ftInsertMarked(ed, m);
        ftSync(wrap);
        return;
    }
    ftCmd(wrap, function (doc, sl) { return { doc: fcdSetMark(doc, sl, key, val === undefined ? null : val), sel: sl }; });
}

// ---------------------------------------------------------------
// Stato dei tasti: acceso quando lo e' davvero
// ---------------------------------------------------------------
/* Un tasto che non dice se e' attivo costringe a indovinare guardando il
   testo. Si aggiorna a ogni spostamento del cursore. */
var FT_MARK_CMD = { bold: 'b', italic: 'i', underline: 'u', yellow: 'h' };

function ftRefreshState(wrap) {
    var ed = ftEditor(wrap);
    var bar = ftBarra(wrap);
    if (!bar || !ed) return;

    var sel = fcdGetSel(ed);
    var doc = fcdFromDom(ed);
    var marks = ftMarksAtCaret(ed);
    var collassata = !sel || (sel.a.b === sel.f.b && sel.a.o === sel.f.o);

    Object.keys(FT_MARK_CMD).forEach(function (cmd) {
        var k = FT_MARK_CMD[cmd];
        var on = collassata ? !!marks[k] : (sel ? fcdRangeHas(doc, sel, k) : false);
        bar.querySelectorAll('.fmt-btn[data-cmd="' + cmd + '"]').forEach(function (b) {
            b.classList.toggle('is-active', !!on);
        });
    });

    var blocco = sel ? doc.blocks[sel.a.b] : null;
    bar.querySelectorAll('.fmt-btn[data-cmd="list"]').forEach(function (b) {
        b.classList.toggle('is-active', !!(blocco && blocco.t === 'li'));
    });
    /* Il pannello Elenco aperto ha senso solo su un punto elenco: e' li' che
       si sceglie il tipo di pallino o si cambia livello. Spostandosi su una
       riga che un pallino non ce l'ha, quelle scelte non significano piu'
       niente per quella riga — lasciarlo aperto e' un pannello che parla di
       un punto che non si sta piu' guardando. Si chiude da solo, come si
       chiuderebbe a mano toccando di nuovo la linguetta. */
    var listPanel = fcPanelOf(bar, 'list');
    if (listPanel && listPanel.classList.contains('is-open') && sel && (!blocco || blocco.t !== 'li')) {
        fcClosePanel(bar);
    }
    bar.querySelectorAll('.fmt-btn[data-cmd="math-display"]').forEach(function (b) {
        b.classList.toggle('is-active', !!(blocco && blocco.t === 'fx'));
    });

    var inFormula = false;
    var n = window.getSelection() && window.getSelection().anchorNode;
    var el = n && (n.nodeType === 1 ? n : n.parentNode);
    if (el && el.closest && el.closest('.fc-x') && ed.contains(el)) inFormula = true;
    bar.querySelectorAll('.fmt-btn[data-cmd="math-inline"]').forEach(function (b) {
        b.classList.toggle('is-active', inFormula);
    });

    var pallini = bar.querySelectorAll('.fmt-pop-dot[data-color]');
    /* Con del testo SELEZIONATO si guarda il colore di quel testo, non quello
       che avrebbe il cursore: prima con una selezione si passava sempre
       stringa vuota, e l'anello finiva sempre sul pallino neutro con la x —
       anche selezionando del testo tutto rosso. Se il tratto scelto e' di un
       colore solo, e' quello ad accendersi; se ne mescola piu' d'uno non si
       accende niente, perche' non c'e' una risposta giusta da dare. */
    var colore;
    if (collassata) {
        colore = marks.c || '';
    } else {
        var visti = {}, quanti = 0;
        fcdEachRunInRange(doc, sel, function (run) {
            var c = (run.m && run.m.c) || '';
            if (!Object.prototype.hasOwnProperty.call(visti, c)) { visti[c] = 1; quanti++; }
        });
        var chiavi = Object.keys(visti);
        colore = (quanti === 1) ? chiavi[0] : null;   // null = misto, nessuno acceso
    }
    pallini.forEach(function (d) { d.classList.toggle('is-scelto', colore !== null && (d.dataset.color || '') === colore); });

    /* Se in questo momento c'e' anche una copia in sovraimpressione, va
       rifatta: tutto quello che si e' appena aggiornato qui sopra (tasti
       accesi, colore scelto, pannelli) sta nell'originale, e la copia
       mostrerebbe ancora lo stato di un attimo fa. */
    if (typeof ftAggancioAggiornaClone === 'function' &&
        FT_AGGANCIO && FT_AGGANCIO.clone && FT_AGGANCIO.wrap === wrap) {
        ftAggancioAggiornaClone();
    }
}

// ---------------------------------------------------------------
// Le formule: codice quando ci sei dentro, formula quando esci
// ---------------------------------------------------------------
/* La verita' sta sempre in data-tex. Quello che si VEDE cambia: col cursore
   dentro, il codice modificabile; appena fuori, la formula disegnata da
   MathJax. Cosi' si scrive senza combattere con il disegno e si controlla il
   risultato senza uscire dall'editor.

   Il disegno e' marcato .fc-resa e vale zero caratteri nel conto delle
   posizioni: e' un'immagine del contenuto, non il contenuto — se lo si
   contasse, il cursore si sposterebbe di quanto e' lunga la formula
   disegnata, cioe' a caso. */
/* MathJax spesso arriva DOPO l'editor: e' caricato con defer, o dalla fine
   della pagina. Disegnare subito e basta significa non disegnare niente, ed e'
   il motivo per cui all'apertura si vedeva il codice e le formule comparivano
   solo dopo averci cliccato dentro e fuori. Qui si aspetta che sia pronto, poi
   si disegna. */
function ftQuandoMathJaxPronto(fn) {
    if (window.MathJax && MathJax.typesetPromise) { fn(); return; }
    if (window.MathJax && MathJax.startup && MathJax.startup.promise) {
        MathJax.startup.promise.then(fn).catch(fn);
        return;
    }
    var giri = 0;
    var attesa = setInterval(function () {
        if (window.MathJax && MathJax.typesetPromise) { clearInterval(attesa); fn(); }
        else if (++giri > 60) clearInterval(attesa);   // ~12s: non c'e', si lascia il codice
    }, 200);
}

/* ================================================================
   MathQuill: la modifica interattiva delle formule A BLOCCO.

   E' lo stesso motore che usa Desmos. Dentro una formula a blocco il
   cursore non scorre piu' su del testo LaTeX: entra in un editor suo, dove
   "\frac" o "/" aprono davvero i due quadratini di numeratore e
   denominatore, "^" alza l'esponente, le frecce girano dentro la struttura.
   Fuori dalla formula non cambia niente: appena si esce, MathJax la
   ridisegna come sempre, e nel documento resta il suo LaTeX e basta.

   Tutto quello che segue e' costruito per SPEGNERSI DA SOLO: se MathQuill
   non e' stato caricato (file non ancora messo sul sito, rete lenta, un
   errore qualsiasi), ftMQPronto() dice di no e ogni singolo punto qui sotto
   ricade sul comportamento di prima, cioe' il LaTeX modificabile a mano.
   Non e' prudenza di maniera: e' cio' che permette di aggiungere questo
   pezzo senza mettere a rischio un editor che gia' funziona.
   ================================================================ */

/* Le lettere degli insiemi numerici, nel loro carattere vero. MathQuill non
   sa leggere "\mathbb{R}" scritto a mano, ma il simbolo lo accetta senza
   problemi: e' la scorciatoia che rende la scrittura possibile. */
var FT_INSIEMI = {
    R: '\u211D', N: '\u2115', Z: '\u2124',
    Q: '\u211A', C: '\u2102', P: '\u2119'
};

function ftMQPronto() {
    return !!(window.MathQuill && window.MathQuill.getInterface);
}

var FT_MQ = null;
function ftMQ() {
    if (!ftMQPronto()) return null;
    if (!FT_MQ) { try { FT_MQ = window.MathQuill.getInterface(2); } catch (e) { return null; } }
    return FT_MQ;
}

/* Il campo MathQuill vivo in questo momento, se c'e'. Ce n'e' al massimo
   uno: si sta scrivendo in una formula per volta. */
function ftMQCampoAttivo(ed) {
    var el = ed && ed.querySelector ? ed.querySelector('.fc-fx.fc-mq, .fc-x.fc-mq') : null;
    return el && el._mqCampo ? el : null;
}

/* Vero se il cursore, in questo momento, sta scrivendo dentro un campo
   MathQuill. MathQuill riceve i tasti attraverso una casella di testo
   nascosta dentro di se': percio' il cursore del DOCUMENTO non sta piu'
   dentro il .fc-fx, e chi lo cercasse li' concluderebbe (a torto) che si e'
   usciti dalla formula. E' esattamente questo il punto in cui, senza questo
   controllo, la formula si richiuderebbe da sola a ogni tasto premuto. */
function ftMQHaIlFuoco(el) {
    if (!el || !el._mqCampo) return false;
    var a = document.activeElement;
    return !!(a && el.contains(a));
}

/* Monta l'editor interattivo sulla formula, mettendo il cursore dal lato da
   cui si e' arrivati ('sinistra' o 'destra'). Restituisce false se non e'
   stato possibile: chi chiama ricade sul LaTeX a mano. */
function ftMQMonta(el, wrap, dove) {
    var MQ = ftMQ();
    if (!MQ || !el) return false;
    if (el._mqCampo) { ftMQFuoco(el, dove); return true; }
    /* Questa formula MathQuill ha gia' dimostrato di non saperla leggere.
       Riprovare a ogni tasto non e' solo inutile: montare e subito smontare
       un campo sposta il fuoco sulla casella nascosta di MathQuill e poi lo
       toglie, e quel viavai rimandava il cursore in fondo a ogni freccia —
       rendendo impossibile muoversi (e quindi correggere) dentro le formule
       col ripiego, esattamente il difetto segnalato. Ci si ricorda del
       rifiuto e non si insiste. */
    if (el.dataset.mqNo === '1') {
        /* Gia' si sa che non va: non si riprova il montaggio, ma la formula va
           comunque sciolta se e' ancora disegnata — altrimenti resta li' come
           se il clic non avesse fatto nulla. E' esattamente questo che
           rendeva la seconda apertura impossibile: la prima volta il rifiuto
           passava da ftMQRipiego (che scioglie), la seconda volta si usciva
           qui PRIMA, senza scioglierla mai. */
        if (el.classList.contains('fc-resa')) {
            ftMQRipiego(el, el.dataset.tex != null ? el.dataset.tex : (el.textContent || '').replace(/\u200B/g, ''));
        }
        return false;
    }

    var tex = el.dataset.tex || '';
    var aBlocco = el.classList.contains('fc-fx');
    el.classList.remove('fc-resa');
    el.classList.add('fc-mq');
    /* Il div che ospita MathQuill non deve essere modificabile dal browser:
       dentro ci scrive solo MathQuill, con la sua struttura. Lasciandolo
       modificabile, i due cursori — quello del documento e quello di
       MathQuill — finirebbero per contendersi lo stesso spazio. */
    el.setAttribute('contenteditable', 'false');
    el.textContent = '';

    /* Il cursore del documento va tolto da qui PRIMA di montare, e questo e'
       il punto in cui la formula creata dal tasto smetteva di accettare
       qualunque cosa si scrivesse.
       Con una formula che nasce da del testo selezionato il cursore e'
       appoggiato a un NODO DI TESTO dentro l'elemento: la riga qui sopra
       (el.textContent = '') porta via quel nodo, e il cursore se ne va con
       lui — cosi' il focus() di MathQuill, un attimo dopo, trova campo
       libero.
       Con una formula VUOTA non c'e' nessun nodo di testo dentro cui stare:
       chi ha messo il cursore li' (fcdSetSel, tramite fcdDomFromPos) lo ha
       appoggiato o sull'ELEMENTO stesso (offset 0, un div senza figli non
       ha altro posto) o — a seconda di come il browser normalizza un punto
       "dentro un elemento vuoto" — sul suo GENITORE, con l'offset che punta
       all'indice dell'elemento fra i suoi fratelli. Il primo tentativo
       controllava solo il primo caso (el.contains(...)): quando il browser
       sceglieva il secondo, il controllo restava falso, la selezione non
       veniva tolta, e il bug proseguiva identico — questo e' il motivo per
       cui il primo giro di correzione non bastava.
       Qui si controlla invece l'intero editor (ed): a questo punto della
       funzione il cursore sta per forza dentro la formula appena creata (chi
       chiama ftMQMonta lo ha appena messo li', con fcdSetSel o con
       l'appiglio di ftCaretInEmptyMath), quindi qualunque selezione ancora
       presente da qualche parte dentro ed e' comunque quella da togliere di
       mezzo per lasciare campo libero a MathQuill — non serve indovinare
       esattamente su quale nodo il browser l'abbia normalizzata. Se il
       montaggio poi fallisce non si perde niente: il ripiego (ftMathEdita,
       subito dopo ftMQMonta) il cursore lo rimette dov'era. */
    try {
        var edSel = ftEditor(wrap);
        var selPrima = window.getSelection();
        if (edSel && selPrima && selPrima.rangeCount && selPrima.anchorNode && edSel.contains(selPrima.anchorNode)) {
            selPrima.removeAllRanges();
        }
    } catch (eSel) {}

    var campo;
    try {
        campo = MQ.MathField(el, {
            spaceBehavesLikeTab: false,
            handlers: {
                edit: function () {
                    /* "\mathbb{R}" e i suoi fratelli: MathQuill ha il comando
                       in tabella ma di fatto non lo riconosce quando lo si
                       scrive a mano — resta li' come testo, ed e' quello che
                       si vedeva. Il SIMBOLO invece lo accetta benissimo, come
                       qualunque altro carattere. Quindi appena la scrittura e'
                       completa la si scambia col simbolo vero: chi scrive il
                       comando LaTeX ottiene il risultato che si aspetta,
                       senza doverlo sapere. */
                    try {
                        var texOra = campo.latex();
                        var texNuovo = texOra
                            /* La forma "pulita", se mai arrivasse cosi'. */
                            .replace(/\\mathbb\s*\{\s*([RNZQCP])\s*\}/g, function (_, l) { return FT_INSIEMI[l] || _; })
                            /* La forma che MathQuill produce DAVVERO scrivendo
                               il comando lettera per lettera: non riconoscendo
                               "\mathbb" lo degrada a testo con parentesi
                               graffe, e diventa "\text{mathbb}\left\{R\right\}".
                               E' questa che si incontra nell'uso reale — la
                               prima da sola non intercettava niente. */
                            .replace(/\\text\{mathbb\}\s*\\left\\\{\s*([RNZQCP])\s*\\right\\\}/g, function (_, l) { return FT_INSIEMI[l] || _; })
                            /* MathQuill puo' aver gia' inghiottito il
                               "\\text{mathbb}" per conto suo lasciando le sole
                               graffe attorno alla lettera: anche quelle vanno
                               tolte, o resterebbe "{R}" con le parentesi in
                               bella vista. */
                            .replace(/\\left\\\{\s*([\u211D\u2115\u2124\u211A\u2102\u2119])\s*\\right\\\}/g, '$1');
                        if (texNuovo !== texOra) { campo.latex(texNuovo); campo.moveToRightEnd(); }
                    } catch (eIns) { /* se qualcosa non torna si lascia com'e' */ }
                    /* data-tex e' la fonte di verita' del documento: va tenuto
                       al passo a ogni tasto, o il salvataggio (e tutto cio'
                       che legge il modello) vedrebbe la formula di prima. */
                    try { el.dataset.tex = campo.latex(); } catch (e2) {}
                    ftSync(wrap);
                },
                /* Arrivati al bordo della formula, si esce nel testo: a
                   blocco verso il blocco vicino, in linea nella stessa riga
                   — sono gli stessi quattro casi (sinistra, destra, sopra,
                   sotto) su cui e' costruito il movimento del cursore fuori
                   di qui, solo con una destinazione diversa secondo il tipo. */
                /* Invio dentro una formula: MathQuill da solo lo ignora, e
                   una formula in fondo al documento diventava un vicolo
                   cieco — nessun modo di scrivere altro sotto. Qui l'invio
                   fa quello che ci si aspetta: esce sotto, aprendo una riga
                   nuova se sotto non c'e' ancora niente (se ne occupa
                   ftMQEsci, che la riga la crea quando serve). */
                enter: function () {
                    if (aBlocco) ftMQEsciNuovaRiga(el, wrap);
                    else ftMQEsciInLinea(el, wrap, 1);
                },
                moveOutOf: function (direzione) {
                    var verso = direzione === MQ.L ? -1 : 1;
                    if (aBlocco) ftMQEsci(el, wrap, verso); else ftMQEsciInLinea(el, wrap, verso);
                },
                upOutOf: function () { if (aBlocco) ftMQEsci(el, wrap, -1); else ftMQEsciInLinea(el, wrap, -1); },
                downOutOf: function () { if (aBlocco) ftMQEsci(el, wrap, 1); else ftMQEsciInLinea(el, wrap, 1); },
                /* Cancellare oltre il bordo di una formula VUOTA la elimina,
                   come gia' fa il resto dell'editor. */
                deleteOutOf: function (direzione) {
                    var verso = direzione === MQ.L ? -1 : 1;
                    if (aBlocco) ftMQCancellaFuori(el, wrap, verso); else ftMQCancellaFuoriInLinea(el, wrap, verso);
                }
            }
        });
    } catch (e) {
        el.classList.remove('fc-mq');
        ftMQRipiego(el, tex);
        return false;
    }

    el._mqCampo = campo;
    try { campo.latex(tex); } catch (e3) { ftMQRipiego(el, tex); return false; }

    /* La rete di sicurezza. MathQuill, davanti a qualcosa che non sa
       interpretare — una matrice, "\mathbb{R}", "\underbrace", un comando
       inventato — non avvisa con un errore: SVUOTA IL CAMPO IN SILENZIO. Se
       si procedesse comunque, il prossimo smontaggio scriverebbe quella
       stringa vuota in data-tex, e la formula sarebbe perduta per sempre —
       esattamente il difetto segnalato. Il controllo e' semplice e diretto:
       se il LaTeX di partenza non era vuoto ma quello letto indietro lo e',
       vuol dire che MathQuill l'ha rifiutato. Si annulla tutto e si torna al
       LaTeX scritto a mano, che quel comando lo sa gia' mostrare (anche se
       non a quadratini) — un ripiego, mai una perdita. */
    if (tex.trim() !== '' && campo.latex().trim() === '') {
        ftMQRipiego(el, tex);
        return false;
    }

    ftMQTasti(el, wrap, campo);
    ftMQFuoco(el, dove);
    return true;
}

/* Comandi che MathQuill non sa scrivere: li si scopre chiedendolo a lui, una
   volta sola per comando, invece di tenere una lista scritta a mano che
   invecchia. Il metodo e' quello che ftMQMonta usa gia' piu' su: gli si da'
   il comando, e se quello che ne riesce e' vuoto vuol dire che se l'e'
   mangiato. */
var FT_MQ_SUPPORTO = {};
function ftMQSupporta(comando) {
    if (Object.hasOwn ? Object.hasOwn(FT_MQ_SUPPORTO, comando) : (comando in FT_MQ_SUPPORTO)) {
        return FT_MQ_SUPPORTO[comando];
    }
    var ok = false;
    try {
        var MQ = ftMQ();
        var prova = document.createElement('span');
        prova.style.cssText = 'position:absolute;left:-9999px;top:-9999px;';
        document.body.appendChild(prova);
        var c = MQ.MathField(prova, {});
        /* Tre forme, perche' i comandi non hanno tutti la stessa forma: c'e'
           chi non vuole argomenti (\vert, \times), chi ne vuole uno
           (\overline, \sqrt) e chi due (\frac). Provandone una sola, quelli
           delle altre due famiglie uscivano vuoti e passavano per non
           supportati — \frac fra questi. Basta che UNA delle tre lasci
           qualcosa perche' il comando sia buono. */
        var forme = ['\\' + comando, '\\' + comando + '{ab}', '\\' + comando + '{a}{b}'];
        for (var fi = 0; fi < forme.length && !ok; fi++) {
            try { c.latex(''); c.write(forme[fi]); ok = c.latex().trim() !== ''; } catch (e2) {}
        }
        c.latex('');
        /* Il metro e' "ne e' rimasto qualcosa", non "c'e' ancora il nome del
           comando": tanti comandi che MathQuill sa fare benissimo spariscono
           dal LaTeX perche' diventano il simbolo corrispondente — \vert esce
           come "|", \times come il segno per. Cercando il nome li si
           giudicava tutti non supportati e finivano nella modifica a LaTeX:
           e' quello che e' successo a \vert. Quando invece un comando non lo
           sa fare, MathQuill si mangia tutto e non resta niente. */
        prova.remove();
    } catch (e) { ok = false; }
    FT_MQ_SUPPORTO[comando] = ok;
    return ok;
}

/* Porta questa formula dalla modifica con MathQuill a quella con il LaTeX
   scritto a mano, tenendo quello che c'era e aggiungendo in fondo il comando
   che ha fatto scattare il passaggio. Serve per i comandi che MathQuill non
   sa scrivere (\underbrace, \cancel...): invece di ingoiarli in silenzio —
   lasciando la formula vuota e l'utente senza capire perche' — si passa alla
   modalita' che quel comando lo sa gestire benissimo. */
function ftMQPassaALatex(el, wrap, comando) {
    var campo = el._mqCampo;
    var tex = '';
    var posComando = -1;
    var pezzo = '\\' + comando + '{}';
    if (campo) {
        /* Il comando deve finire DOVE STA IL CURSORE, non in fondo alla
           formula: attaccarlo alla fine e' sbagliato in tutti i casi in cui
           si sta scrivendo in mezzo a qualcosa di gia' scritto.
           Il punto esatto MathQuill non lo dice, ma si puo' fargli scrivere
           un segnalino li' dove si trova e poi cercarlo nel LaTeX: dove sta
           lui, sta il cursore. */
        var segnalino = '\\text{@ftq@}';
        try { campo.write(segnalino); } catch (e) {}
        tex = campo.latex() || '';
        var i = tex.indexOf(segnalino);
        if (i === -1) i = tex.indexOf('\\text{@ftq@}');
        if (i !== -1) {
            tex = tex.slice(0, i) + pezzo + tex.slice(i + segnalino.length);
            posComando = i;
        } else {
            /* Segnalino non ritrovato: meglio in fondo che perdere tutto. */
            posComando = tex.length;
            tex = tex + pezzo;
        }
    } else {
        tex = el.dataset.tex || '';
        posComando = tex.length;
        tex = tex + pezzo;
    }
    try { if (campo && campo.revert) campo.revert(); } catch (e) {}
    el._mqCampo = null;
    el.classList.remove('fc-mq', 'mq-editable-field', 'mq-math-mode', 'mq-focused');
    el.dataset.tex = tex;
    ftMQRipiego(el, tex);
    /* Cursore fra le graffe del comando appena inserito, dove si sta per
       scrivere: non all'ultima graffa della formula, che con il comando in
       mezzo a del testo gia' scritto sarebbe tutt'altro punto. */
    try {
        var t = el.firstChild;
        if (t) {
            var pos = (posComando >= 0)
                ? posComando + pezzo.length - 1   // subito prima della graffa che chiude
                : (el.textContent || '').lastIndexOf('}');
            if (pos < 0) pos = (el.textContent || '').length;
            /* Il testo mostrato ha un carattere invisibile in testa (lo mette
               ftMQRipiego per poter mettere il cursore ai bordi): la posizione
               contata sul LaTeX va spostata di uno. */
            if ((t.nodeValue || '').charAt(0) === '\u200B') pos += 1;
            var r = document.createRange();
            r.setStart(t, Math.max(0, Math.min(pos, (t.nodeValue || '').length)));
            r.collapse(true);
            var sel = window.getSelection();
            sel.removeAllRanges(); sel.addRange(r);
        }
    } catch (e) {}
    if (wrap) ftSync(wrap);
}

/* I tasti che MathQuill da solo non gestisce bene. Si ascolta la casella
   nascosta su cui lui riceve la tastiera. */
function ftMQTasti(el, wrap, campo) {
    var ta = el.querySelector('textarea');
    if (!ta || ta._ftTasti) return;
    ta._ftTasti = true;
    var buffer = '';   // quello che si sta scrivendo dopo una barra rovesciata

    ta.addEventListener('keydown', function (e) {
        if (e.ctrlKey || e.metaKey || e.altKey) return;

        /* IL CANCELLETTO. MathQuill non ha "\#": scrivendolo se lo mangia e
           la formula resta vuota, e digitando il carattere da solo prova a
           leggerlo come inizio di una macro e protesta. Lo si scrive quindi
           come "\text{#}", che lui accetta e che MathJax disegna come un
           cancelletto normale — il risultato che si voleva. */
        if (e.key === '#') {
            e.preventDefault();
            buffer = '';
            try { campo.write('\\text{#}'); } catch (err) {}
            return;
        }

        /* I COMANDI. Si GUARDA quello che viene digitato senza trattenerlo:
           i tasti arrivano a MathQuill come sempre, quindi mentre si scrive
           "\overline" lo si vede comparire lettera per lettera, com'e' giusto.
           (Trattenerli per decidere con calma faceva sparire tutto quello che
           si scriveva dopo la barra rovesciata fino all'invio: si scriveva al
           buio, ed e' il difetto da togliere.)
           Solo quando il nome del comando e' finito si controlla se MathQuill
           lo sa fare: se si', non si fa niente e ci pensa lui come sempre; se
           no, si cancella quello che ha in canna e si passa al LaTeX scritto
           a mano, che quel comando lo mostra benissimo. */
        if (e.key === '\\') { buffer = '\\'; return; }
        if (!buffer) return;

        if (/^[a-zA-Z]$/.test(e.key)) { buffer += e.key; return; }
        if (e.key === 'Backspace') { buffer = buffer.slice(0, -1); return; }
        if (e.key !== '{' && e.key !== ' ' && e.key !== 'Enter') { buffer = ''; return; }

        var comando = buffer.slice(1);
        buffer = '';
        if (!comando || ftMQSupporta(comando)) return;   // sa farlo lui: non ci si mette in mezzo

        e.preventDefault();
        /* Il nome del comando e' gia' dentro MathQuill (lo si e' lasciato
           scrivere apposta, per vederlo): va tolto un carattere alla volta,
           barra rovesciata compresa, prima di leggere quello che resta. */
        for (var k = 0; k <= comando.length; k++) {
            try { campo.keystroke('Backspace'); } catch (err) {}
        }
        ftMQPassaALatex(el, wrap, comando);
    });
}

/* Il ripiego: la stessa identica modalita' di modifica che l'editor usava
   PRIMA di MathQuill, e che le formule in linea usano tuttora — LaTeX a
   mano, con i due invisibili ai lati per rendere raggiungibile il punto
   prima del primo carattere (vedi ftMathEdita). Non e' un vicolo cieco: da
   li' si puo' continuare a scrivere e correggere normalmente, semplicemente
   senza i quadratini interattivi per QUESTA formula specifica. */
function ftMQRipiego(el, tex) {
    if (el._mqCampo) { try { el._mqCampo.revert(); } catch (e) {} }
    el._mqCampo = null;
    /* Si annota che per QUESTA formula MathQuill non va: cosi' i tasti
       successivi non riprovano (vedi ftMQMonta). L'annotazione va tolta
       appena il LaTeX cambia — corretto a mano, il testo potrebbe diventare
       qualcosa che MathQuill sa leggere benissimo. */
    el.dataset.mqNo = '1';
    el.classList.remove('fc-mq', 'fc-resa');
    el.removeAttribute('contenteditable');
    el.dataset.tex = tex;
    el.textContent = '\u200B' + tex + '\u200B';
}

function ftMQFuoco(el, dove) {
    if (!el || !el._mqCampo) return;
    var mettiA = function () {
        if (!el._mqCampo) return;   // smontata nel frattempo: non c'e' piu' niente da mettere a fuoco
        try {
            el._mqCampo.focus();
            if (dove === 'destra') el._mqCampo.moveToRightEnd();
            else if (dove === 'sinistra') el._mqCampo.moveToLeftEnd();
        } catch (e) {}
    };
    /* Il terzo e ultimo tentativo, al prossimo fotogramma e SOLO se il fuoco
       non e' arrivato davvero. Non e' un doppione dei due qui sotto: quelli
       sparano comunque, questo prima guarda. Serve per chi cambia il fuoco
       dopo di noi — una barra che si apre e ricalcola le altezze, un
       pannello che compare — e non e' un ciclo: si prova una volta sola. */
    var controlla = function () {
        if (!el._mqCampo) return;
        var att = document.activeElement;
        if (att && el.contains(att)) return;   // c'e' gia': niente da fare
        mettiA();
    };

    mettiA();
    /* Un secondo tentativo, un istante dopo. Cliccare un <button> nativo puo'
       — nei browser veri, non qui nei test — sistemare il SUO fuoco dopo che
       il gestore del clic e' gia' finito, disfacendo un focus() chiamato
       dentro quel gestore: e' esattamente il caso di "creo la formula col
       tasto della barra e non ci si scrive dentro" — funzionava aprendo una
       formula gia' scritta cliccandoci sopra (li' non c'e' nessun bottone di
       mezzo) ma non creandone una nuova dal tasto. Il tentativo sincrono
       resta per il caso normale; questo e' solo un rimedio per quell'unico
       scenario. */
    setTimeout(mettiA, 0);
    if (window.requestAnimationFrame) window.requestAnimationFrame(controlla);
    else setTimeout(controlla, 16);
}

/* Smonta l'editor interattivo e restituisce il LaTeX finale. Da qui in poi
   la formula torna a essere quello che era: un div col suo data-tex, che
   MathJax disegnera'. */
function ftMQSmonta(el) {
    if (!el || !el._mqCampo) return null;
    var tex = '';
    try { tex = el._mqCampo.latex(); } catch (e) { tex = el.dataset.tex || ''; }
    try { el._mqCampo.revert(); } catch (e2) {}
    el._mqCampo = null;
    el.classList.remove('fc-mq');
    el.dataset.tex = tex;
    el.textContent = tex;
    el.removeAttribute('contenteditable');
    return tex;
}

/* Uscita dalla formula verso il blocco vicino (verso -1 = su/sinistra,
   +1 = giu'/destra). Se di la' non c'e' niente, si apre una riga nuova: da
   una formula a blocco si deve sempre poter uscire, o si resterebbe
   intrappolati nell'ultima riga del documento. */
/* L'uscita dell'INVIO: a differenza di quella delle frecce, apre SEMPRE una
   riga nuova sotto la formula, anche se sotto c'era gia' qualcosa. E' la
   differenza fra "spostarsi" e "andare a capo": premere invio in mezzo a un
   testo non porta il cursore alla riga successiva gia' scritta, ne aggiunge
   una. */
function ftMQEsciNuovaRiga(el, wrap) {
    var ed = ftEditor(wrap);
    if (!ed) return;
    var bi = fcdBlockEls(ed).indexOf(el);
    ftMQSmonta(el);
    if (bi === -1) { ftMathRefresh(wrap); return; }
    ftCmd(wrap, function (d) {
        d.blocks.splice(bi + 1, 0, { t: 'p', runs: [] });
        return { doc: fcdNorm(d), caret: { b: bi + 1, o: 0 } };
    });
}

function ftMQEsci(el, wrap, verso) {
    var ed = ftEditor(wrap);
    if (!ed) return;
    var bi = fcdBlockEls(ed).indexOf(el);
    ftMQSmonta(el);
    if (bi === -1) { ftMathRefresh(wrap); return; }

    var doc = fcdFromDom(ed);
    var idx = verso < 0 ? bi - 1 : bi + 1;

    if (idx < 0 || idx >= doc.blocks.length || doc.blocks[idx].t === 'fx') {
        ftCmd(wrap, function (d) {
            var punto = verso < 0 ? bi : bi + 1;
            d.blocks.splice(punto, 0, { t: 'p', runs: [] });
            return { doc: fcdNorm(d), caret: { b: punto, o: 0 } };
        });
        return;
    }

    ftCmd(wrap, function (d) {
        var lung = fcdBlockLen(d.blocks[idx]);
        return { doc: d, caret: { b: idx, o: verso < 0 ? lung : 0 } };
    });
}

/* Backspace (o canc) al bordo di una formula VUOTA: la si toglie del tutto,
   come fa gia' il resto dell'editor. Se invece ha un contenuto non si
   cancella niente — si esce e basta, che e' la regola su cui e' costruito
   tutto il resto: una formula non sparisce mai in un colpo solo. */
function ftMQCancellaFuori(el, wrap, verso) {
    var ed = ftEditor(wrap);
    if (!ed) return;
    var bi = fcdBlockEls(ed).indexOf(el);
    var tex = ftMQSmonta(el);

    if (tex && tex.trim() !== '') { ftMQEsciDaIndice(wrap, bi, verso); return; }
    if (bi === -1) { ftMathRefresh(wrap); return; }

    ftCmd(wrap, function (d) {
        d.blocks.splice(bi, 1);
        var docN = fcdNorm(d);
        var idx = Math.max(0, Math.min(verso < 0 ? bi - 1 : bi, docN.blocks.length - 1));
        var lung = docN.blocks[idx].t === 'fx' ? 0 : fcdBlockLen(docN.blocks[idx]);
        return { doc: docN, caret: { b: idx, o: verso < 0 ? lung : 0 } };
    });
}

function ftMQEsciDaIndice(wrap, bi, verso) {
    var ed = ftEditor(wrap);
    var doc = fcdFromDom(ed);
    var idx = verso < 0 ? bi - 1 : bi + 1;
    if (bi === -1 || idx < 0 || idx >= doc.blocks.length) { ftMathRefresh(wrap); return; }
    ftCmd(wrap, function (d) {
        var lung = d.blocks[idx].t === 'fx' ? 0 : fcdBlockLen(d.blocks[idx]);
        return { doc: d, caret: { b: idx, o: verso < 0 ? lung : 0 } };
    });
}

/* Per una formula IN LINEA (uno span .fc-x dentro un paragrafo), trova a che
   punto del testo del blocco che la contiene comincia e finisce. Non serve
   sapere dov'e' il cursore: serve sapere dov'e' LEI, per decidere dove
   metterlo uscendo, o quale run togliere se resta vuota. La si trova
   contando, fra i run del blocco, quale run-formula e' — lo stesso ordinale
   dello span fra i suoi fratelli .fc-x nel DOM. */
function ftMQPosizioneInLinea(ed, span) {
    var blockEl = span.closest('.fc-p, .fc-li');
    if (!blockEl) return null;
    var bi = fcdBlockEls(ed).indexOf(blockEl);
    if (bi === -1) return null;
    var ordinale = Array.prototype.indexOf.call(blockEl.querySelectorAll('.fc-x'), span);
    var doc = fcdFromDom(ed);
    var blocco = doc.blocks[bi];
    if (!blocco || !blocco.runs) return null;
    var acc = 0, contate = -1;
    for (var k = 0; k < blocco.runs.length; k++) {
        var len = fcdRunText(blocco.runs[k]).length;
        if (typeof blocco.runs[k].x === 'string') {
            contate++;
            if (contate === ordinale) return { bi: bi, nRuns: k, inizio: acc, fine: acc + len, ordinale: ordinale };
        }
        acc += len;
    }
    return null;
}

/* Uscita da una formula IN LINEA verso il testo che la circonda, nello
   STESSO blocco — a differenza di quella a blocco, qui non c'e' mai un
   "blocco vicino" da aprire: si esce semplicemente a sinistra o a destra
   della formula, dentro la stessa riga. */
function ftMQEsciInLinea(el, wrap, verso) {
    var ed = ftEditor(wrap);
    if (!ed) return;
    var pos = ftMQPosizioneInLinea(ed, el);
    ftMQSmonta(el);
    if (!pos) { ftMathRefresh(wrap); return; }
    /* "Sto uscendo": ftCmd qui sotto fa ripartire ftMathRefresh, che vedrebbe
       il cursore appoggiato al bordo della formula, lo scambierebbe per un
       cursore DENTRO di lei e rimonterebbe MathQuill riprendendosi il fuoco —
       si usciva e si rientrava nello stesso istante, e la freccia destra
       sembrava non fare niente. Con questo segno ftMathRefresh sa di non
       rimontare niente per un attimo, il tempo che il cursore si sistemi
       fuori. */
    wrap._ftUscitaFormula = Date.now();
    ftCmd(wrap, function (d, s) {
        return { doc: d, caret: { b: pos.bi, o: verso < 0 ? pos.inizio : pos.fine } };
    });

    /* PERCHE' NON BASTA IL CURSORE AL BORDO
       Chiedere il cursore "alla fine della formula" lo mette in un punto che
       appartiene ancora alla formula: per chi guarda il documento, la
       posizione subito dopo l'ultimo carattere di una formula e' sia il suo
       bordo destro sia l'inizio del testo dopo. ftMathRefresh, che scatta
       subito dopo, la legge come "il cursore e' dentro quella formula" e
       rimonta MathQuill — che si riprende il fuoco. Da fuori sembrava che la
       freccia destra non facesse niente: si usciva e si rientrava nello
       stesso istante, all'infinito. Non capitava sempre perche' dipende da
       dove il browser normalizza il cursore, e con esponenti o pedici (che
       aggiungono elementi dentro la formula) finisce piu' facilmente dentro.
       Qui lo si posa esplicitamente FUORI dall'elemento, nel testo attorno:
       da li' nessuno puo' scambiarlo per un cursore dentro la formula. */
    try {
        var edNuovo = ftEditor(wrap);
        var bEl = fcdBlockEls(edNuovo)[pos.bi];
        var spans = bEl ? bEl.querySelectorAll('.fc-x') : null;
        var sp = (spans && pos.ordinale != null) ? spans[pos.ordinale] : null;
        if (sp && sp.parentNode) {
            var r = document.createRange();
            if (verso < 0) r.setStartBefore(sp); else r.setStartAfter(sp);
            r.collapse(true);
            var sel = window.getSelection();
            sel.removeAllRanges();
            sel.addRange(r);
        }
    } catch (e) {}
}

/* Backspace/canc al bordo di una formula IN LINEA vuota: si toglie il run,
   non solo si esce — stessa regola della formula a blocco, adattata al
   fatto che qui non si tocca l'elenco dei blocchi ma quello dei run. */
function ftMQCancellaFuoriInLinea(el, wrap, verso) {
    var ed = ftEditor(wrap);
    if (!ed) return;
    var tex = ftMQSmonta(el);
    var pos = ftMQPosizioneInLinea(ed, el);
    if (!pos) { ftMathRefresh(wrap); return; }

    if (tex && tex.trim() !== '') {
        ftCmd(wrap, function (d, s) {
            return { doc: d, caret: { b: pos.bi, o: verso < 0 ? pos.inizio : pos.fine } };
        });
        return;
    }

    ftCmd(wrap, function (d, s) {
        var blocco = d.blocks[pos.bi];
        blocco.runs.splice(pos.nRuns, 1);
        return { doc: fcdNorm(d), caret: { b: pos.bi, o: pos.inizio } };
    });
}

/* Vero se in QUESTO editor si sta scrivendo dentro una formula MathQuill. */
function ftMQFuocoQui(ed) {
    var el = ftMQCampoAttivo(ed);
    return !!(el && ftMQHaIlFuoco(el));
}

/* Su quale blocco sta lavorando l'utente ADESSO. Non basta leggere la
   selezione del documento: se MathQuill ha il fuoco, quella selezione sta
   nella sua casella nascosta e indica tutt'altro blocco (di solito il
   primo) — ed e' per questo che un comando come "elenco", premuto stando
   dentro una formula, finiva per applicarsi al testo invece che a lei.
   Restituisce null se non c'e' niente di sensato da dire. */
/* Chiude (ridisegna) tutte le formule TRANNE quella su cui si sta
   lavorando. Serve dove non si puo' chiamare ftMathRefresh per intero —
   perche' un campo MathQuill appena montato verrebbe smontato — ma le altre
   formule vanno comunque richiuse: senza, passando col clic da una formula
   all'altra la prima restava aperta a mostrare il suo codice. */
function ftChiudiAltreFormule(ed, tranne) {
    ed.querySelectorAll('.fc-x, .fc-fx').forEach(function (altra) {
        if (altra !== tranne) ftMathDisegna(altra);
    });
}

function ftBloccoCorrente(ed) {
    var campo = ftMQCampoAttivo(ed);
    if (campo && ftMQHaIlFuoco(campo)) {
        var i = fcdBlockEls(ed).indexOf(campo.classList.contains('fc-fx') ? campo : (campo.closest ? campo.closest('.fc-p, .fc-li, .fc-fx') : campo));
        if (i !== -1) return i;
    }
    var sel = fcdGetSel(ed);
    return sel ? sel.a.b : null;
}

/* Entra nella formula a blocco che sta all'indice dato, dal lato indicato.
   Restituisce false se MathQuill non c'e' o non e' quel tipo di blocco: chi
   chiama ricade sul comportamento di prima, il LaTeX modificabile a mano. */
function ftMQEntra(ed, wrap, indice, dove) {
    if (!ftMQPronto()) return false;
    var el = fcdBlockEls(ed)[indice];
    if (!el || !el.classList || !el.classList.contains('fc-fx')) return false;
    /* Se e' disegnata, il LaTeX vero sta in data-tex: va recuperato prima di
       svuotare il div per farci entrare MathQuill. */
    if (el.dataset.tex == null) el.dataset.tex = (el.textContent || '').replace(/\u200B/g, '');
    return ftMQMonta(el, wrap, dove);
}

function ftMathEdita(el, portaCursore, wrap, dove, offsetEsatto) {
    /* Prima con MathQuill, quando c'e' — a blocco e in linea, la stessa
       logica per entrambe. */
    if ((el.classList.contains('fc-fx') || el.classList.contains('fc-x')) && ftMQPronto()) {
        var w = wrap || (el.closest ? el.closest('.fmt-editor-wrap') : null);
        if (w) {
            if (ftMQMonta(el, w, dove || (portaCursore ? 'destra' : 'sinistra'))) return;
            /* ftMQMonta ha rifiutato la formula — un integrale con "\,", una
               matrice, un comando che MathQuill non sa leggere — e PRIMA di
               restituire false ha gia' rimesso il LaTeX a mano con i due
               invisibili ai lati (ftMQRipiego, chiamata al suo interno).
               Qui sotto c'era un controllo ("se non e' fc-resa, esci") che
               quel ripiego aveva appena reso vero da solo: si usciva PRIMA
               di piazzare il cursore, lasciando la formula visibile e
               modificabile ma senza nessuno dentro — ed e' li' che il clic
               sembrava non aver fatto nulla di utile.

               PERCHE' IL CURSORE TORNAVA SEMPRE IN FONDO: ftRender ricostruisce
               TUTTO l'HTML dell'editor da zero a ogni tasto (fcdToHTML), e per
               una formula quell'HTML e' sempre "vergine" — senza i due
               invisibili, senza sapere che QUESTA formula era gia' stata
               rifiutata da MathQuill l'ultima volta. Cosi' ftMQMonta ci
               riprovava a ogni carattere digitato, falliva di nuovo, e questo
               ramo doveva rimettere il cursore da solo — ma sapeva solo "da
               che lato si e' entrati", non la posizione precisa: il risultato
               era sempre l'inizio o sempre la fine, mai il punto esatto appena
               scritto, un carattere via via piu' lontano dal punto giusto man
               mano che la formula cresceva. Quando chi chiama conosce l'offset
               preciso (ftMathRefresh lo calcola sempre, leggendolo da dove il
               cursore sta GIA') lo si usa; solo se non lo si ha si ripiega sul
               lato d'ingresso come prima. */
            if (el.firstChild) {
                var testo0 = el.textContent || '';
                var lung0 = testo0.length;
                var posReale;
                if (typeof offsetEsatto === 'number') {
                    /* offsetEsatto conta i caratteri VERI (senza gli
                       invisibili): va ritradotto in una posizione dentro il
                       nodo di testo, che gli invisibili li ha — stessa
                       traduzione di fcdDomFromPos. */
                    var vere0 = 0, contate0 = 0;
                    while (vere0 < lung0 && contate0 < offsetEsatto) {
                        if (testo0.charAt(vere0) !== '\u200B') contate0++;
                        vere0++;
                    }
                    posReale = vere0;
                } else {
                    var daSinistra = dove === 'sinistra';
                    posReale = daSinistra ? Math.min(1, lung0) : Math.max(0, lung0 - 1);
                }
                var r0 = document.createRange();
                r0.setStart(el.firstChild, posReale);
                r0.collapse(true);
                var sel0 = window.getSelection();
                sel0.removeAllRanges();
                sel0.addRange(r0);
            }
            return;
        }
    }
    if (!el.classList.contains('fc-resa')) return;
    /* Senza data-tex non si torna indietro: rimettere il testo significherebbe
       svuotare la formula. Meglio lasciarla disegnata che cancellarla. */
    if (el.dataset.tex == null || el.dataset.tex === '') {
        if (fcdEDisegnata(el)) return;
    }
    el.classList.remove('fc-resa');
    el.removeAttribute('contenteditable');
    /* Due caratteri invisibili DENTRO lo span, uno per lato.
       Senza, il contenuto e' esattamente il LaTeX ("2"), e allora la
       posizione "dentro, all'inizio" e' (testo, 0) — che per il browser e'
       lo STESSO PUNTO VISIVO di "subito prima dello span". Chrome, davanti a
       due posizioni indistinguibili, ne sceglie una lui, e sceglie quella
       fuori: il cursore usciva dalla formula pur restando lei aperta, ed e'
       esattamente il passo che mancava (non ci si riusciva a mettere fra il
       dollaro e la prima lettera).
       Con un invisibile davanti, "dentro all'inizio" diventa la posizione
       DOPO un carattere vero: non e' piu' ambigua, e il browser non ha piu'
       niente da interpretare. Non sporcano il LaTeX perche' ovunque lo si
       legga lo si ripulisce gia' da questi caratteri. */
    el.textContent = '\u200B' + (el.dataset.tex || '') + '\u200B';
    if (portaCursore && el.firstChild) {
        var r = document.createRange();
        /* In fondo al LaTeX, cioe' PRIMA dell'invisibile di chiusura: dopo di
           esso sarebbe di nuovo il punto ambiguo di cui sopra, sul bordo. */
        r.setStart(el.firstChild, Math.max(0, (el.textContent || '').length - 1));
        r.collapse(true);
        var sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(r);
    }
}

function ftMathDisegna(el) {
    /* Se qui c'era MathQuill, prima lo si smonta: il suo LaTeX finale finisce
       in data-tex, e da quel momento la formula e' di nuovo un div normale
       che MathJax puo' disegnare. Senza questo passaggio si darebbe in pasto
       a MathJax la struttura interna di MathQuill, che non e' LaTeX. */
    if (el._mqCampo) ftMQSmonta(el);
    if (el.classList.contains('fc-resa')) return;
    /* Se dentro c'e' gia' il disegno di MathJax, quello che si vede NON e' il
       LaTeX: e' la formula scritta a parole ("ω" al posto di "\omega"). Puo'
       succedere quando il disegno, che e' asincrono, arriva dopo che qualcuno
       ha tolto il marchio .fc-resa. Prendendo il testo da li' si scriveva in
       data-tex il disegno al posto del codice, e da quel momento ogni conto di
       caratteri era sbagliato di quanto e' lungo il codice. La verita', quando
       il disegno c'e', sta solo in data-tex. */
    var tex = fcdEDisegnata(el)
        ? (el.dataset.tex || '')
        : (el.textContent || '').replace(/\u200B/g, '');
    el.dataset.tex = tex;                     // si salva prima di sostituire cio' che si vede
    if (!tex) return;                         // vuota: resta com'e', o sparirebbe dalla vista
    if (!(window.MathJax && (MathJax.typesetPromise || MathJax.tex2chtmlPromise))) return;

    var blocco = el.classList.contains('fc-fx');
    el.classList.add('fc-resa');
    /* Non modificabile mentre e' disegnata: dentro c'e' il disegno di MathJax,
       decine di elementi che non hanno niente a che vedere con la formula. Se
       il cursore ci finisse dentro, si scriverebbe dentro il disegno. */
    el.setAttribute('contenteditable', 'false');

    /* Le formule A BLOCCO hanno un involucro interno in piu'; quelle in linea
       no, non ne hanno bisogno. Il motivo: lo scorrimento orizzontale delle
       formule larghe (overflow-x:auto) e il pallino della lista, posizionato
       FUORI dal bordo con uno scostamento negativo, non possono convivere
       sullo stesso elemento — un overflow diverso da visible ritaglia
       qualunque cosa provi a stare fuori dai propri confini, pallino
       compreso. Prima il pallino spariva proprio per questo: lo spazio c'era
       (il margine), ma quello che ci si doveva disegnare dentro veniva
       tagliato via dallo stesso overflow pensato per le formule larghe.
       Separando i due ruoli su due elementi — l'esterno porta il pallino e
       non ritaglia niente, l'interno scorre e ritaglia solo il proprio
       disegno — tornano a convivere senza pestarsi i piedi. */
    var bersaglioMathJax = el;
    if (blocco) {
        el.innerHTML = '<span class="fc-fx-vetro"></span>';
        bersaglioMathJax = el.firstChild;
        bersaglioMathJax.textContent = '\\[' + tex + '\\]';
    } else {
        el.textContent = '\\(' + tex + '\\)';
    }

    /* Dov'e' il cursore ADESSO, prima di dare il via al disegno. MathJax
       lavora in modo ASINCRONO: finisce di sostituire il contenuto della
       formula molti millisecondi dopo, quando il cursore e' gia' stato messo
       al suo posto — e quella sostituzione se lo porta via, tipicamente in
       fondo alla riga. E' precisamente per questo che l'invio all'inizio di
       una riga si comportava male SOLO dopo una formula a blocco: solo li'
       c'era un disegno in ritardo a rimettere mano al DOM. Il cursore non e'
       dentro la formula (li' non ci puo' stare), quindi rimetterlo dov'era e'
       sempre sicuro. */
    var edPadre = el.closest ? el.closest('.fc-editor') : null;
    var selPrima = window.getSelection();
    var nodoPrima = (selPrima && selPrima.rangeCount) ? selPrima.anchorNode : null;
    var offsetPrima = (selPrima && selPrima.rangeCount) ? selPrima.anchorOffset : 0;
    var avevaIlFuoco = !!(edPadre && document.activeElement &&
                          (edPadre === document.activeElement || edPadre.contains(document.activeElement)));

    function rimettiCursore() {
        if (!avevaIlFuoco || !nodoPrima) return;
        if (!edPadre || !edPadre.contains(nodoPrima)) return;   // quel punto non esiste piu'
        if (el.contains(nodoPrima)) return;                      // era dentro la formula: ora non si puo'
        var s = window.getSelection();
        if (s && s.rangeCount && s.anchorNode === nodoPrima && s.anchorOffset === offsetPrima) return;
        var lung = nodoPrima.nodeType === 3 ? (nodoPrima.nodeValue || '').length : nodoPrima.childNodes.length;
        if (offsetPrima > lung) return;
        try {
            var r = document.createRange();
            r.setStart(nodoPrima, offsetPrima);
            r.collapse(true);
            s.removeAllRanges();
            s.addRange(r);
        } catch (eR) { /* punto non piu' valido: si lascia dov'e' */ }
    }

    try {
        MathJax.typesetPromise([bersaglioMathJax]).then(rimettiCursore, function () { ftMathEdita(el); });
    } catch (e) { ftMathEdita(el); }
}

/* Chiamata a ogni spostamento del cursore: una sola formula per volta puo'
   essere in modifica, tutte le altre sono disegnate. */
function ftMathRefresh(wrap) {
    var ed = ftEditor(wrap);
    if (!ed) return;

    /* Se si sta scrivendo dentro MathQuill, siamo GIA' dentro quella formula
       e non c'e' altro da decidere. Va controllato per primo, prima di
       guardare dov'e' il cursore del documento: mentre MathQuill ha il fuoco
       quel cursore non sta piu' dentro il .fc-fx (i tasti li riceve una
       casella nascosta), e cercarlo li' porterebbe a concludere che si e'
       usciti — richiudendo la formula sotto le dita, a ogni tasto. */
    var mqAttivo = ftMQCampoAttivo(ed);
    if (mqAttivo && ftMQHaIlFuoco(mqAttivo)) {
        ed.querySelectorAll('.fc-x, .fc-fx').forEach(function (altro) {
            if (altro !== mqAttivo) ftMathDisegna(altro);
        });
        return;
    }

    var sel = window.getSelection();
    /* Dove sta il cursore PRIMA di toccare qualunque cosa: serve per
       rimetterlo li' in fondo (vedi in coda a questa funzione). */
    var primaNodo = (sel && sel.rangeCount) ? sel.anchorNode : null;
    var primaOffset = (sel && sel.rangeCount) ? sel.anchorOffset : 0;
    var dentro = null;
    if (sel && sel.rangeCount && ed.contains(sel.anchorNode)) {
        var n = sel.anchorNode;
        var el = n.nodeType === 1 ? n : n.parentNode;
        if (el && el.closest) dentro = el.closest('.fc-x, .fc-fx');

        /* Dentro vuol dire DENTRO lo span, alla lettera: nient'altro.
           Qui c'era una "zona di tolleranza" che considerava dentro anche i
           caratteri invisibili ai lati, per rimediare al fatto che un tocco
           su un confine largo zero pixel a volte cade dall'altra parte. Ma
           quella tolleranza cancellava proprio la distinzione che serve: sul
           bordo di una formula il cursore deve potersi fermare da tutt'e due
           i lati, ed e' cio' che rende raggiungibile il punto prima della sua
           prima lettera. Ora il lato lo decide chi muove il cursore (le
           frecce lo scelgono e lo piazzano di proposito), e qui si legge
           soltanto dov'e' finito. */
    }

    /* Una formula che si sta per richiudere (non e' piu' "dentro") ma e'
       rimasta vuota non ha senso: nessuno l'ha riempita, e disegnarla
       lascerebbe un segnaposto invisibile e irraggiungibile — niente da
       vedere, niente su cui cliccare per toglierlo. Si toglie lei, non si
       disegna. Solo quelle ancora APERTE contano (non .fc-resa): una
       formula gia' disegnata e vuota non dovrebbe esistere, ma deciderlo
       non e' compito di un refresh scattato per il movimento del cursore.
       fcdTrim() e' la seconda rete, per quelle che sfuggissero anche a
       questa (un'altra scheda, un salvataggio partito nel mezzo). */
    /* Appena usciti da una formula non si rimonta nulla: vedi il commento in
       ftMQEsciInLinea. Passato l'attimo, il segno si cancella da solo. */
    if (wrap._ftUscitaFormula && Date.now() - wrap._ftUscitaFormula < 400) dentro = null;
    else wrap._ftUscitaFormula = 0;

    var daTogliere = null;
    ed.querySelectorAll('.fc-x, .fc-fx').forEach(function (el) {
        if (el === dentro || daTogliere) return;
        if (el.classList.contains('fc-resa')) return;
        if ((el.textContent || '').replace(/\u200B/g, '').trim() === '') daTogliere = el;
    });
    if (daTogliere) {
        var aBlocco = daTogliere.classList.contains('fc-fx');
        var elBlocco = aBlocco ? daTogliere : daTogliere.closest('.fc-p, .fc-li');
        var bi = fcdBlockEls(ed).indexOf(elBlocco);
        if (bi !== -1) {
            var ordinale = Array.prototype.indexOf.call(elBlocco.querySelectorAll('.fc-x'), daTogliere);
            ftCmd(wrap, function (d, s) {
                var blocco = d.blocks[bi];
                if (!blocco) return null;
                if (aBlocco) {
                    d.blocks.splice(bi, 1);
                    var docNorm = fcdNorm(d);
                    return { doc: docNorm, caret: { b: Math.min(bi, docNorm.blocks.length - 1), o: 0 } };
                }
                var acc = 0, contate = -1;
                for (var k = 0; k < (blocco.runs || []).length; k++) {
                    var rk = blocco.runs[k];
                    if (typeof rk.x === 'string') {
                        contate++;
                        if (contate === ordinale) { blocco.runs.splice(k, 1); return { doc: fcdNorm(d), caret: { b: bi, o: acc } }; }
                    }
                    acc += fcdRunText(rk).length;
                }
                return null;
            });
            return;
        }
    }

    ed.querySelectorAll('.fc-x, .fc-fx').forEach(function (el) {
        if (el === dentro) {
            /* Da che lato mettere il cursore MONTANDO MathQuill: non un
               default fisso, ma quello vero, letto dal punto in cui il
               cursore GIA' sta (appena piazzato da chi ha chiamato questo
               refresh — un backspace dal testo, una freccia). Senza,
               ftMathEdita(el) chiamata con un solo argomento risolveva
               "dove" a 'sinistra' sempre e comunque: un backspace che
               arrivava alla formula da SOTTO veniva rispedito a leggerla
               dall'inizio invece che dalla fine, un dettaglio che ribaltava
               esattamente il verso da cui si era entrati. */
            var doveMR = 'destra', offMR = null;
            if (sel && sel.rangeCount) {
                var nMR = sel.anchorNode;
                var testoMR = fcdSenzaZw(el.textContent || '');
                offMR = (nMR === el) ? sel.anchorOffset
                    : (nMR && nMR.nodeType === 3 && el.contains(nMR))
                        ? fcdSenzaZw((nMR.nodeValue || '').slice(0, sel.anchorOffset)).length
                        : null;
                if (offMR === 0) doveMR = 'sinistra';
                else if (offMR != null && offMR >= testoMR.length) doveMR = 'destra';
            }
            ftMathEdita(el, false, wrap, doveMR, offMR);
        }
        else ftMathDisegna(el);
    });

    /* ftMathRefresh deve RIFLETTERE lo stato, mai spostare il cursore. Ma
       ridisegnare una formula ne riscrive il contenuto, e riscrivere il nodo
       su cui il cursore sta appoggiato lo fa scivolare in fondo: chi era a
       meta' di "\cancel{ab}" si ritrovava dopo l'ultimo carattere, a ogni
       freccia, senza riuscire piu' a muoversi ne' a correggere. Se il punto
       in cui era e' ancora valido, ce lo si rimette.

       ECCEZIONE, e non da poco: se la formula "dentro" e' appena stata presa
       in mano da MathQuill (ci ha appena messo il fuoco lui, un attimo fa,
       dentro ftMathEdita/ftMQMonta), primaNodo e' ancora il vecchio nodo di
       PRIMA del montaggio — lo stesso elemento .fc-fx, ma quando ancora non
       aveva dentro la struttura di MathQuill. L'elemento e' lo stesso
       (ed.contains(primaNodo) resta vero, un div non sparisce), ma il suo
       CONTENUTO adesso e' tutt'altro: lo textarea nascosto di MathQuill, il
       disegno del cursore finto, tutto cio' che lui gestisce da solo.
       Rimettere qui la selezione del documento a (quell'elemento, 0)
       significa piazzarla PRIMA del primo figlio di MathQuill — un punto che
       per il documento sembra ragionevole ma che a MathQuill dice "il tuo
       cursore non e' piu' dove pensavi": il fuoco del browser (document.
       activeElement) resta sulla sua casella nascosta, i tasti continuano ad
       arrivarci, ma quello che digiti non viene piu' mostrato — esattamente
       il difetto "creo la formula col tasto e non ci si scrive dentro". Non
       succedeva aprendo una formula gia' scritta cliccandoci sopra perche'
       li' primaNodo era gia' un discendente della struttura di MathQuill (ci
       si era gia' dentro), non l'elemento contenitore: la condizione qui
       sotto restava falsa da sola. Creando una formula nuova invece
       primaNodo e' SEMPRE il contenitore (fcdSetSel lo piazza li', un div
       vuoto non ha altro posto), e la condizione scattava sempre — e sempre
       nel momento sbagliato. Se chi e' "dentro" ora ha gia' il fuoco di
       MathQuill, il suo cursore lo gestisce lui: non c'e' niente da
       ripristinare, e soprattutto niente da guastare. */
    if (primaNodo && ed.contains(primaNodo) && !(dentro && dentro._mqCampo && ftMQHaIlFuoco(dentro))) {
        var oraSel = window.getSelection();
        if (oraSel && oraSel.rangeCount &&
            (oraSel.anchorNode !== primaNodo || oraSel.anchorOffset !== primaOffset)) {
            var lunghezza = primaNodo.nodeType === 3
                ? (primaNodo.nodeValue || '').length
                : primaNodo.childNodes.length;
            if (primaOffset <= lunghezza) {
                try {
                    var rr = document.createRange();
                    rr.setStart(primaNodo, primaOffset);
                    rr.collapse(true);
                    oraSel.removeAllRanges();
                    oraSel.addRange(rr);
                } catch (eSel) { /* punto non piu' valido: si lascia dov'e' */ }
            }
        }
    }

    /* I suggerimenti aperti dal tasto della formula vivono quanto la formula:
       uscendone, o cancellandola, devono sparire. Restando aperti si potevano
       toccare fuori da una formula, e finivano nel testo come LaTeX crudo.
       Quelli aperti per la dettatura non si toccano: li' servono anche nel
       testo normale. */
    var bar = ftBarra(wrap);
    if (!bar) return;
    var tastoCmd = bar.querySelector('.fmt-comandi-btn');
    var perVoce = !!(tastoCmd && tastoCmd.classList.contains('is-active'));
    if (perVoce) return;

    var pannello = bar.querySelector('.fmt-panel-2[data-panel2="comandi"]');
    if (pannello && pannello.classList.contains('is-open') && !dentro) fcChiudiSecondo(bar);
}

// ---------------------------------------------------------------
// Tastiera
// ---------------------------------------------------------------
/* ================================================================
   La barra agganciata allo schermo.

   position:sticky non basta: nella pagina vera la carta che contiene la
   casella ha overflow:hidden per via degli angoli arrotondati, e un solo
   antenato cosi' — non importa quanto lontano — spegne sticky del tutto.
   Qui si rifa' la stessa cosa a mano: si guarda se la barra della casella
   ATTIVA (quella con dentro il cursore) sta per uscire dalla cima dello
   schermo scorrendo, e in quel momento le si mette position:fixed, con un
   segnaposto della stessa altezza al suo posto perche' il resto della pagina
   non salti su. Un solo ascoltatore per tutta la pagina, non uno per casella:
   ha senso tenerne agganciata soltanto una alla volta — quella su cui si sta
   scrivendo — non farle impilare tutte quante in cima.
   ================================================================ */
/* Quanto e' alta un'eventuale intestazione della pagina gia' fissa in cima
   allo schermo — un header, una barra dei menu — sotto la quale la barra
   agganciata deve mettersi, non sopra: altrimenti la nasconde o ci si
   sovrappone. Non si puo' presumere una struttura fissa: questo file finisce
   in pagine diverse, ognuna con la sua intestazione o senza. Si cerca quindi
   a runtime: qualunque elemento gia' incollato in cima allo schermo (che sia
   lui stesso position:fixed con top vicino a zero) conta come intestazione,
   la barra si mette subito sotto quello piu' basso di tutti. Nessuna
   intestazione cosi' -> si torna al margine minimo di prima. */
/* DOVE DEVE INCOLLARSI LA COPIA, E FIN DOVE PUO' ARRIVARE
   Non basta piu' guardare le barre in cima alla PAGINA: questo file finisce
   anche dentro dei popup, e li' la cima non e' quella della finestra ma quella
   del popup, che per giunta ha spesso una sua barra sticky (il titolo, i tasti
   salva e chiudi). Guardando solo la pagina succedevano le due cose viste:
   nel popup di modifica la copia si piazzava SOPRA la barra del popup
   (calcolata sulla pagina sotto, non su di lui), e nel popup degli esempi non
   compariva affatto — li' il contenuto scorre dentro un pannello, non nella
   finestra, e per di piu' il popup sta a z-index 11000, ben sopra il 1000
   della copia, che quindi ci sarebbe finita dietro anche comparendo.
   Si risale quindi la catena degli antenati della casella e si raccoglie:
     - i CONTENITORI che scorrono o sono fissi (il pannello del popup): danno
       la cima sotto cui stare e il fondo oltre cui non sconfinare;
     - le BARRE incollate in cima dentro ciascuno di loro (intestazione della
       pagina, barra del popup): spingono la cima ancora piu' giu';
     - lo z-index piu' alto incontrato: la copia deve stare sopra tutti.
   Gli elementi si raccolgono una volta sola, quando si entra nella casella;
   le loro posizioni si rileggono a ogni giro, perche' quelle cambiano
   scorrendo. Cosi' non si scandisce mezza pagina a ogni pixel di scorrimento. */
function ftAggancioRaccogli(wrap) {
    var contenitori = [], barre = [], z = 1000;
    var altezzaSchermo = window.innerHeight || 800;
    var el = wrap;
    while (el && el.nodeType === 1) {
        var st = window.getComputedStyle(el);
        var zi = parseInt(st.zIndex, 10);
        if (!isNaN(zi) && zi + 10 > z) z = zi + 10;

        if (el !== wrap) {
            /* Contenitore vero = o e' fisso allo schermo, o scorre DAVVERO
               (ha piu' contenuto di quanto ne mostri). Prima bastava
               overflow-y:auto per essere promossi a contenitore, e quello ce
               l'hanno tanti riquadri che in realta' non scorrono affatto: la
               cima veniva presa da uno di loro, a meta' pagina, e la copia
               compariva li' invece che sotto la barra in alto — troppo presto
               e nel posto sbagliato, in tutte le pagine. */
            var scorre = /(auto|scroll)/.test(st.overflowY) &&
                         el.scrollHeight > el.clientHeight + 1;
            if (st.position === 'fixed' || scorre) contenitori.push(el);
        }

        /* Le barre incollate in cima stanno FRA I FIGLI di questo antenato:
           l'intestazione della pagina fra i figli del body, la barra del
           popup fra i figli del pannello. Si saltano i rami che contengono la
           casella stessa: la sua barra non e' un'intestazione da schivare. */
        var figli = el.children;
        for (var i = 0; i < figli.length; i++) {
            var f = figli[i];
            if (f.contains(wrap)) continue;
            if (f.classList && f.classList.contains('fmt-portale')) continue;
            var sf = window.getComputedStyle(f);
            if (sf.position !== 'fixed' && sf.position !== 'sticky') continue;
            /* Deve essere una barra ANCORATA IN CIMA: il 'top' con cui e'
               incollata e' zero o poco piu'. Il "poco piu'" non e' pignoleria:
               una barra puo' essere incollata a 20-30px perche' sopra di lei
               ce n'e' gia' un'altra (in inserimento_big l'intestazione sta
               sotto la striscia del pomodoro). Con la soglia a 8px quella
               veniva scartata e la copia le finiva sopra; senza nessun filtro,
               invece, passava qualunque elemento incollato a meta' pagina e la
               copia finiva la' in mezzo. 60px prende le barre impilate vere e
               lascia fuori tutto il resto. */
            var topCss = parseFloat(sf.top);
            if (isNaN(topCss) || topCss > 60) continue;
            var rf = f.getBoundingClientRect();
            /* Alta quanto mezzo schermo o piu' non e' una barra in cima, e'
               una sovrapposizione a tutto schermo (un visore di immagini, una
               finestra di conferma): contarla darebbe una cima piu' bassa del
               fondo dello schermo, e nessuna copia comparirebbe mai. */
            if (rf.height === 0 || rf.height > altezzaSchermo * 0.5) continue;
            /* E deve passarle davanti: una barra che sta in un'altra colonna
               non puo' nascondere questa casella, quindi non e' affar suo.
               Senza questo controllo, in una pagina a due colonne bastava un
               elemento incollato nella colonna accanto per spostare la copia. */
            var rw = wrap.getBoundingClientRect();
            if (rf.right <= rw.left + 4 || rf.left >= rw.right - 4) continue;
            barre.push(f);
        }

        if (el === document.body) break;
        el = el.parentNode;
    }
    return { contenitori: contenitori, barre: barre, z: z };
}

/* Rilegge le posizioni di quanto raccolto sopra e restituisce la cima sotto
   cui incollarsi e il fondo oltre cui sparire. */
/* Quanto e' occupata la cima dello SCHERMO, misurata guardando davvero cosa
   c'e' li' sopra invece di indovinarlo dall'elenco delle regole CSS.
   Si parte da zero e si scende: finche' il punto che si tocca appartiene a
   qualcosa di fisso o incollato, quella e' roba che sta sopra la pagina —
   una barra. Al primo punto che appartiene al contenuto normale ci si ferma:
   li' comincia lo spazio libero.
   Perche' cosi' e non come nel caso dei popup: nella pagina piena le barre
   arrivano da fogli di stile diversi (l'intestazione, la striscia del
   pomodoro, la barra laterale, quello che verra' aggiunto domani) e cercare
   di riconoscerle una per una dalle loro regole e' un gioco che si perde —
   ogni volta ne sbucava una che spostava la copia a meta' schermo. Cosa
   c'e' davvero in cima invece lo si puo' semplicemente guardare.
   Nei popup si continua a fare come prima, che li' funziona: la' la cima non
   e' quella dello schermo ma quella del pannello, e questo metodo non
   saprebbe distinguerle. */
function ftSogliaDalloSchermo(toolbar) {
    var r = toolbar.getBoundingClientRect();
    var cx = Math.min(window.innerWidth - 2, Math.max(2, r.left + r.width / 2));
    var limite = (window.innerHeight || 800) * 0.35;   // piu' giu' non e' piu' un'intestazione
    var soglia = 0;
    for (var y = 2; y < limite; y += 6) {
        var el = document.elementFromPoint(cx, y);
        if (!el) break;
        /* La copia sta li' sopra anche lei: se la si contasse come barra,
           a ogni giro si spingerebbe piu' in giu' da sola. */
        if (el.closest && el.closest('.fmt-portale')) break;
        var fisso = false, n = el;
        while (n && n !== document.body) {
            var st = window.getComputedStyle(n);
            if (st.position === 'fixed' || st.position === 'sticky') { fisso = true; break; }
            n = n.parentElement;
        }
        if (!fisso) break;
        soglia = y;
    }
    return soglia;
}

function ftAggancioMisure(a) {
    var soglia = 0, limite = window.innerHeight;

    var c = a.contenitori || [];
    if (!c.length) {
        /* Pagina piena, nessun pannello di mezzo: si guarda cosa occupa la
           cima dello schermo. */
        return { soglia: ftSogliaDalloSchermo(a.toolbar) + 6, limite: limite };
    }
    for (var i = 0; i < c.length; i++) {
        var rc = c[i].getBoundingClientRect();
        if (rc.height === 0) continue;      // contenitore chiuso: non vincola niente
        if (rc.top > soglia) soglia = rc.top;
        if (rc.bottom < limite) limite = rc.bottom;
    }

    /* Una passata sola, dalla barra piu' in alto alla piu' in basso.
       L'ORDINE e' la cosa importante: due barre impilate (la striscia del
       pomodoro in cima, l'intestazione appoggiata sotto di lei) si accettano a
       catena solo se si incontra prima quella sopra — nell'ordine del DOM
       possono capitare al contrario, e allora l'intestazione veniva scartata
       come "troppo in basso" e la copia le finiva sopra. Ordinandole per
       posizione la catena e' sempre quella giusta.
       Una passata sola e non un ciclo ripetuto: il ciclo che avevo messo qui
       si incatenava all'infinito verso il basso — ogni elemento appoggiato a
       quello appena accettato abbassava la cima ancora un po', e la copia
       finiva a meta' pagina. */
    var b = (a.barre || []).slice().sort(function (x, y) {
        return x.getBoundingClientRect().top - y.getBoundingClientRect().top;
    });
    for (var j = 0; j < b.length; j++) {
        var rb = b[j].getBoundingClientRect();
        if (rb.height === 0) continue;      // barra nascosta in questo momento
        /* Conta solo se e' davvero appoggiata alla cima di dove siamo: una
           barra piu' in basso e' contenuto normale, non un ostacolo. */
        if (rb.top > soglia + 4) continue;
        if (rb.bottom > soglia) soglia = rb.bottom;
    }

    return { soglia: soglia + 6, limite: limite };
}


var FT_AGGANCIO = { wrap: null, toolbar: null, portale: null, clone: null };

/* IL DUPLICATO IN SOVRAIMPRESSIONE
   La barra originale non si sposta e non si nasconde MAI: resta nel suo posto
   dentro la casella, per tutta la vita della pagina. Quando quel posto esce
   dallo schermo scorrendo, non la si insegue e non la si porta via — se ne
   AGGIUNGE una seconda, una copia, fissa in cima allo schermo. Tornando su, la
   copia sparisce e si rivede l'originale dov'e' sempre stata.
   I tentativi precedenti spostavano l'originale dentro un contenitore fisso e
   le lasciavano al posto suo un segnaposto vuoto: bastava un intoppo qualunque
   nel viaggio di andata o di ritorno per ritrovarsi con il buco e nessuna
   barra da nessuna parte — ed e' esattamente quello che si vedeva. Una copia
   non ha viaggi da fare: o c'e' o non c'e', e l'originale non rischia niente.
   Il contenitore vive in fondo al body perche' dentro la carta ci sono un
   will-change:transform (che sposta il riferimento di position:fixed sulla
   carta stessa) e un overflow:hidden (che ritaglia via cio' che ne esce):
   vedi il commento sul .fmt-portale nel CSS. */
function ftAggancioCreaClone(a) {
    if (a.clone) return;
    a.portale = document.createElement('div');
    a.portale.className = 'fmt-portale';
    a.clone = a.toolbar.cloneNode(true);
    a.clone.classList.add('fmt-agganciata', 'fmt-clone');
    /* Gli id della copia darebbero duplicati in pagina: si tolgono, tanto la
       copia non e' mai il bersaglio di nessuna ricerca per id — ogni cosa che
       fa passa dall'originale (vedi ftAggancioInoltra). */
    a.clone.removeAttribute('id');
    a.clone.querySelectorAll('[id]').forEach(function (n) { n.removeAttribute('id'); });
    a.portale.appendChild(a.clone);
    document.body.appendChild(a.portale);

    /* Un tocco sulla copia non deve spostare il fuoco dalla casella, esatta-
       mente come sull'originale. */
    a.portale.addEventListener('mousedown', function (e) { e.preventDefault(); });
    /* La copia non ha nessuna logica propria: ogni click viene rigirato al
       pulsante corrispondente dell'originale, cercato per posizione fra tutti
       i pulsanti della barra. Cosi' non esistono due comportamenti da tenere
       allineati — ce n'e' uno solo, quello di sempre. */
    a.portale.addEventListener('click', ftAggancioInoltra);
}

function ftAggancioInoltra(e) {
    var a = FT_AGGANCIO;
    if (!a.clone || !a.toolbar) return;
    var premuto = e.target.closest ? e.target.closest('button') : null;
    if (!premuto) return;
    var daCopia = a.clone.querySelectorAll('button');
    var daVero = a.toolbar.querySelectorAll('button');
    for (var i = 0; i < daCopia.length; i++) {
        if (daCopia[i] === premuto) {
            if (daVero[i]) daVero[i].click();
            /* Il click ha cambiato lo stato dell'originale (un pannello che si
               apre, un tasto che si accende): la copia va rifatta, o mostrerebbe
               ancora com'erano le cose un attimo fa. */
            ftAggancioAggiornaClone();
            return;
        }
    }
}

/* Rifa' il contenuto della copia da quello dell'originale. Chiamata dopo ogni
   click sulla copia e ogni volta che l'originale cambia aspetto
   (ftRefreshState): la copia deve sempre mostrare esattamente quello che
   mostrerebbe l'originale se la si stesse guardando. */
function ftAggancioAggiornaClone() {
    var a = FT_AGGANCIO;
    if (!a.clone || !a.toolbar) return;
    a.clone.innerHTML = a.toolbar.innerHTML;
    a.clone.querySelectorAll('[id]').forEach(function (n) { n.removeAttribute('id'); });
    a.clone.className = a.toolbar.className + ' fmt-agganciata fmt-clone';
    if (a.portale) a.portale.classList.toggle('fmt-chiusa', a.wrap.classList.contains('fmt-chiusa'));
}

function ftAggancioTogliClone(a) {
    if (a.portale && a.portale.parentNode) a.portale.parentNode.removeChild(a.portale);
    a.portale = null;
    a.clone = null;
}

function ftAggancioStacca() {
    /* Via gli ascoltatori messi sui contenitori di questa casella: lasciarli
       significherebbe accumularne uno per ogni casella toccata. */
    var vecchi = FT_AGGANCIO.contenitori || [];
    for (var i = 0; i < vecchi.length; i++) {
        vecchi[i].removeEventListener('scroll', ftAggancioAggiorna);
    }
    ftAggancioTogliClone(FT_AGGANCIO);
    FT_AGGANCIO = { wrap: null, toolbar: null, portale: null, clone: null };
}

function ftAggancioSuCasella(wrap) {
    if (FT_AGGANCIO.wrap === wrap) return;
    ftAggancioStacca();
    var toolbar = ftBarra(wrap);
    if (!toolbar) return;
    FT_AGGANCIO = { wrap: wrap, toolbar: toolbar, portale: null, clone: null,
                    contenitori: [], barre: [], z: 1000 };
    /* Contenitori, barre e z-index si raccolgono qui, a ogni casella su cui ci
       si mette: fra una e l'altra puo' essere cambiato tutto il contorno —
       una casella dentro un popup ha sopra di se' cose completamente diverse
       da una casella nella pagina, e la stessa casella puo' finire dentro un
       popup che prima non c'era. */
    var ctx = ftAggancioRaccogli(wrap);
    FT_AGGANCIO.contenitori = ctx.contenitori;
    FT_AGGANCIO.barre = ctx.barre;
    FT_AGGANCIO.z = ctx.z;

    /* Lo scorrimento DENTRO un contenitore (il pannello di un popup) va
       ascoltato sul contenitore stesso. L'ascoltatore generale e' su window,
       e li' quello scorrimento non arriva: la copia veniva creata al primo
       tocco e poi restava ferma dov'era, senza piu' aggiornarsi ne' sparire
       quando la cella finiva. Dentro i popup era proprio cosi'. */
    for (var ci = 0; ci < ctx.contenitori.length; ci++) {
        ctx.contenitori[ci].addEventListener('scroll', ftAggancioAggiorna, { passive: true });
    }
    ftAggancioAggiorna();

    /* Toccare una casella fa comparire la barra (fmt-attiva) partendo da
       altezza zero e crescendo con una transizione — e sul telefono lo stesso
       tocco fa anche scorrere da solo la pagina per tenere la casella sopra la
       tastiera. Le due cose succedono insieme: nell'istante della misura qui
       sopra la barra puo' non avere ancora disegnato un solo fotogramma della
       sua altezza vera. Due giri di requestAnimationFrame aspettano che il
       browser l'abbia davvero disegnata prima di rimisurare; il timeout e' la
       rete di sicurezza per quando il fotogramma non arriva subito (scheda in
       secondo piano, dispositivo lento). */
    requestAnimationFrame(function () {
        requestAnimationFrame(function () {
            if (FT_AGGANCIO.wrap === wrap) ftAggancioAggiorna();
        });
    });
    setTimeout(function () {
        if (FT_AGGANCIO.wrap === wrap) ftAggancioAggiorna();
    }, 320);
}

/* Chiamata a ogni scroll/resize/tasto. Due domande, in quest'ordine:
   1. la casella e' ancora sullo schermo? Se e' scorsa via del tutto, niente
      copia: non ha piu' senso una barra per una casella che non si vede.
   2. la barra ORIGINALE si vede? Se si', niente copia — si usa quella, che e'
      sempre li' al suo posto. Se e' scorsa sopra la cima, si aggiunge la copia
      fissa in alto.
   Nient'altro entra nella decisione: non quanto e' lunga la casella, non
   quanto ne resta sotto. Le condizioni in piu' che avevo aggiunto prima
   ("non tenere la barra se sotto resta poco") facevano sparire tutto proprio
   mentre si stava ancora scrivendo. */
function ftAggancioAggiorna() {
    var a = FT_AGGANCIO;
    if (!a.wrap || !document.body.contains(a.wrap)) { ftAggancioStacca(); return; }

    var m = ftAggancioMisure(a);
    var soglia = m.soglia;
    var rWrap = a.wrap.getBoundingClientRect();
    var r = a.toolbar.getBoundingClientRect();

    /* Dentro un popup non c'e' spazio utile: il pannello e' chiuso o
       schiacciato. Meglio niente copia che una copia che sbuca fuori dai
       suoi bordi. */
    if (m.limite - soglia < 24) { ftAggancioTogliClone(a); return; }

    /* "Fuori" vuol dire fuori dallo SCHERMO, non fuori da un valore calcolato:
       se la soglia risultasse per qualunque motivo sbagliata per eccesso, una
       casella perfettamente visibile verrebbe scambiata per scomparsa e la
       copia non nascerebbe mai. Legandola ai bordi veri della finestra questo
       non puo' succedere: al massimo la copia resta qualche pixel piu' su o
       piu' giu' del punto ideale, ma c'e'. */
    /* La copia se ne va appena arriva a toccare il fondo della cella: se
       della cella resta visibile solo un dito, e' giusto vedere quel dito e
       non una barra che gli sta sopra coprendolo. Serve quindi che sotto la
       copia ci sia ancora cella, non solo che la cella sia in qualche modo
       sullo schermo. */
    var casellaFuori = rWrap.bottom <= soglia + r.height || rWrap.top >= m.limite;
    /* Altezza zero = la barra si sta ancora aprendo: non e' "scorsa via", e'
       solo non ancora disegnata. Trattarla come fuori schermo farebbe
       comparire una copia alta niente. */
    var barraSotto = r.height > 0 && r.top < soglia;

    if (casellaFuori || !barraSotto) { ftAggancioTogliClone(a); return; }

    ftAggancioCreaClone(a);
    ftAggancioAggiornaClone();
    a.clone.style.left = r.left + 'px';
    a.clone.style.width = r.width + 'px';
    a.clone.style.top = soglia + 'px';
    /* Sopra tutto quello che le sta attorno: i popup di questo sito arrivano a
       z-index 12500, e con il 1000 del foglio di stile la copia ci sarebbe
       finita dietro — invisibile pur essendo stata creata. */
    a.clone.style.zIndex = String(a.z || 1000);
}

(function ftAggancioAvvia() {
    if (window.__ftAggancioAvviato) return;
    window.__ftAggancioAvviato = true;

    /* Il fuoco e' ancora dentro questa casella? Vale anche il portale: la
       barra agganciata e' sua a tutti gli effetti, anche se in quel momento
       non le sta piu' dentro nel DOM. */
    function ftFuocoAncoraQui(wrap) {
        var att = document.activeElement;
        if (!att) return false;
        if (wrap.contains(att)) return true;
        return !!(FT_AGGANCIO.portale && FT_AGGANCIO.wrap === wrap && FT_AGGANCIO.portale.contains(att));
    }

    document.addEventListener('focusin', function (e) {
        var wrap = e.target.closest ? e.target.closest('.fmt-editor-wrap') : null;
        if (!wrap) return;
        /* La barra si vede solo mentre si scrive in questa casella: e' la
           classe a deciderlo, il CSS la richiude da solo su tutte le altre. */
        document.querySelectorAll('.fmt-editor-wrap.fmt-attiva').forEach(function (altro) {
            if (altro !== wrap) altro.classList.remove('fmt-attiva');
        });
        wrap.classList.add('fmt-attiva');
        ftAggancioSuCasella(wrap);
    });
    document.addEventListener('focusout', function (e) {
        var wrap = e.target.closest ? e.target.closest('.fmt-editor-wrap') : null;
        setTimeout(function () {
            /* Rimandato di un attimo perche' nel momento esatto del focusout
               il browser non ha ancora detto dove sta andando il fuoco: fra
               una formula e il testo attorno, o fra la casella e un tasto
               della barra, si passa sempre da un istante in cui non e' di
               nessuno. */
            if (FT_AGGANCIO.wrap && !ftFuocoAncoraQui(FT_AGGANCIO.wrap)) ftAggancioStacca();
            if (wrap && !ftFuocoAncoraQui(wrap)) wrap.classList.remove('fmt-attiva');
        }, 0);
    });
    window.addEventListener('scroll', ftAggancioAggiorna, { passive: true, capture: true });
    window.addEventListener('resize', ftAggancioAggiorna);
})();

function ftSetupEditor(wrap) {
    var ed = ftEditor(wrap);

    /* La formula che TOCCA la posizione o (dentro, o esattamente su uno dei
       suoi due bordi), con i suoi estremi in caratteri del documento. */
    function ftFormulaA(block, o) {
        var acc = 0, runs = (block && block.runs) || [];
        for (var i = 0; i < runs.length; i++) {
            var len = fcdRunText(runs[i]).length;
            if (typeof runs[i].x === 'string' && o >= acc && o <= acc + len) {
                return { inizio: acc, fine: acc + len, nRuns: i };
            }
            acc += len;
        }
        return null;
    }

    /* Quanti sono, fra i run che vengono prima, quelli che sono formule:
       serve a ritrovare nel DOM lo span giusto, che e' l'n-esimo .fc-x del
       blocco. */
    function ftOrdinaleFormula(block, nRuns) {
        var n = 0, runs = (block && block.runs) || [];
        for (var i = 0; i < nRuns; i++) if (typeof runs[i].x === 'string') n++;
        return n;
    }

    /* Lo span della formula dentro cui sta il cursore adesso, oppure null.
       E' questo — e non la sola posizione numerica — a dire da che LATO di un
       confine ci si trova. */
    function ftSpanDelCursore() {
        var s = window.getSelection();
        if (!s || !s.rangeCount) return null;
        var n = s.anchorNode;
        var el = n && (n.nodeType === 1 ? n : n.parentNode);
        return (el && el.closest) ? el.closest('.fc-x') : null;
    }

    /* Vero se il cursore sta sulla riga VISIVA piu' in alto (su) o piu' in
       basso (giu') del blocco che lo contiene. Non si puo' rispondere
       guardando la posizione nel testo — un paragrafo che va a capo da solo
       per la larghezza ha piu' righe visive pur essendo un blocco solo — si
       guarda dove il cursore sta DAVVERO sullo schermo, confrontandolo con i
       bordi del blocco. Senza rects utilizzabili (un elemento nascosto, o
       l'ambiente di test) si assume di essere al bordo: e' la scelta piu'
       prudente, quella che fa comunque provare a entrare nella formula
       invece di lasciare che il cursore ci salti sopra senza accorgersene. */
    /* Vero se il cursore sta sulla riga piu' in alto (su) o piu' in basso
       (giu') del blocco che lo contiene, SECONDO IL TESTO: nessun a capo
       manuale (uno "shift+invio", il solo modo in cui un blocco puo' avere
       piu' di una riga scritta apposta) prima di lui, per "su" — nessuno
       dopo, per "giu'".

       La geometria vera (getBoundingClientRect) sembrava piu' precisa in
       teoria, ma in pratica si e' rivelata fragile: il "min-height" dei
       paragrafi e altri dettagli del layout la facevano sbagliare proprio
       nel caso piu' comune, un paragrafo di una riga sola, che e' esattamente
       quello che deve funzionare sempre. Il limite di questo controllo pero'
       piu' semplice si vede solo su un paragrafo cosi' lungo da andare a capo
       DA SOLO per la larghezza, senza nessuno shift+invio: in quel caso
       "prima riga" e "ultima riga" restano indistinguibili dalla sola lunghezza
       del testo, e ci si comporta come se lo fossero entrambe. */
    function ftSulBordoVisivo(testoBlocco, o, su) {
        return su ? testoBlocco.slice(0, o).indexOf('\n') === -1 : testoBlocco.slice(o).indexOf('\n') === -1;
    }

    /* Mette il cursore in un punto preciso, dicendo esplicitamente da che
       parte del confine deve stare. */
    /* Dal k-esimo carattere VERO alla posizione reale dentro il nodo, che di
       caratteri invisibili ne contiene. Per k = 0 si va comunque oltre quelli
       iniziali: e' cio' che rende "dentro all'inizio" un punto a se', e non
       lo stesso punto di "subito prima della formula". */
    function ftOffsetSaltandoGuide(testo, k) {
        var reale = 0, veri = 0;
        while (reale < testo.length && veri < k) {
            if (testo.charAt(reale) !== '\u200B') veri++;
            reale++;
        }
        if (k === 0) while (reale < testo.length && testo.charAt(reale) === '\u200B') reale++;
        return reale;
    }

    function ftPiazzaCursore(blockEl, block, o, dentro, f) {
        var r = document.createRange();
        var spans = blockEl.querySelectorAll('.fc-x');
        var span = f ? spans[ftOrdinaleFormula(block, f.nRuns)] : null;
        if (!span) return false;

        if (dentro) {
            /* Prima si prova con MathQuill, come per le formule a blocco: se
               riesce a rappresentarla, l'editor interattivo prende il posto
               del LaTeX a mano e il cursore lo mette gia' lui, dal lato da
               cui si e' arrivati. "o === f.inizio" vuol dire che si e'
               arrivati da destra (spostandosi a sinistra), quindi si entra
               dal lato sinistro della formula — e viceversa. */
            if (ftMQPronto()) {
                var doveMQ = (o === f.inizio) ? 'sinistra' : 'destra';
                if (span.dataset.tex == null) span.dataset.tex = (span.textContent || '').replace(/\u200B/g, '');
                if (ftMQMonta(span, wrap, doveMQ)) return true;
            }
            ftMathEdita(span);                     // dentro ci si sta sul LaTeX, non sul disegno
            var t = span.firstChild;
            /* Se la formula non era mai stata disegnata, gli appigli invisibili
               non ci sono ancora: si mettono adesso, o il primo carattere
               resterebbe irraggiungibile come prima. */
            var testo = t && t.nodeType === 3 ? t.nodeValue : null;
            if (testo === null || testo.charAt(0) !== '\u200B' || testo.charAt(testo.length - 1) !== '\u200B') {
                span.textContent = '\u200B' + (span.dataset.tex || (testo || '').replace(/\u200B/g, '')) + '\u200B';
                t = span.firstChild;
            }
            if (!t) return false;
            r.setStart(t, ftOffsetSaltandoGuide(t.nodeValue, o - f.inizio));
        } else if (o === f.fine) {
            /* Subito DOPO la formula: oltre gli invisibili del nodo che segue,
               cosi' il punto e' inequivocabilmente fuori dallo span. */
            var dopo = span.nextSibling;
            if (dopo && dopo.nodeType === 3) {
                r.setStart(dopo, (dopo.nodeValue.match(/^\u200B+/) || [''])[0].length);
            } else {
                r.setStart(span.parentNode, Array.prototype.indexOf.call(span.parentNode.childNodes, span) + 1);
            }
        } else if (o === f.inizio) {
            /* Subito PRIMA della formula: prima degli invisibili che chiudono
               il nodo precedente — non dopo, o si tornerebbe sul punto
               ambiguo attaccato allo span. */
            var prima = span.previousSibling;
            if (prima && prima.nodeType === 3) {
                var coda = (prima.nodeValue.match(/\u200B+$/) || [''])[0].length;
                r.setStart(prima, Math.max(0, prima.nodeValue.length - coda));
            } else {
                r.setStart(span.parentNode, Array.prototype.indexOf.call(span.parentNode.childNodes, span));
            }
        } else {
            return false;   // lontano da una formula: se ne occupa il browser
        }
        r.collapse(true);
        var s = window.getSelection();
        s.removeAllRanges();
        s.addRange(r);
        return true;
    }

    ed.addEventListener('keydown', function (e) {
        /* Dentro MathQuill i tasti li gestisce lui: frecce, cancella, tutto.
           Intervenire qui vorrebbe dire muovere il cursore DEL DOCUMENTO
           mentre l'utente sta muovendo quello DELLA FORMULA — due cursori
           che si contendono lo stesso tasto. L'uscita dalla formula non si
           perde: la annuncia MathQuill stesso, con i suoi
           moveOutOf/upOutOf/downOutOf.

           L'INVIO e' l'eccezione, e va intercettato PRIMA. MathQuill ha un
           gestore "enter", ma lo chiama solo quando gli arriva un a capo
           come carattere digitato — mai per il tasto Invio, che passa da
           un'altra strada e lui semplicemente ignora. Il risultato era una
           formula in fondo al documento senza piu' modo di scrivere sotto.
           Qui l'invio fa quello che ci si aspetta: chiude la formula e apre
           una riga nuova. */
        var campoInvio = ftMQCampoAttivo(ed);
        if (campoInvio && ftMQHaIlFuoco(campoInvio) && e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            if (campoInvio.classList.contains('fc-fx')) ftMQEsciNuovaRiga(campoInvio, wrap);
            else ftMQEsciInLinea(campoInvio, wrap, 1);
            return;
        }
        if (ftMQFuocoQui(ed)) return;

        /* Grassetto/corsivo/sottolineato da tastiera: il browser gestisce
           Ctrl+B (e affini) DA SOLO in un contenteditable, con il suo comando
           nativo — inserisce o toglie un <b> vero nel DOM, senza passare dal
           nostro modello. E' esattamente questo il motivo per cui "premo
           Ctrl+B per togliere il grassetto, poi lo riapplica": il comando
           nativo lo toglie per un istante, ma il modello (fcdFromDom) non lo
           sa — per lui quella porzione e' ancora segnata "b":1 — e al primo
           ridisegno (che con il pannello Testo aperto scatta piu' spesso,
           perche' i suoi pulsanti si tengono sincronizzati col cursore)
           quella verita' vecchia riscrive il DOM e il grassetto torna.
           Intercettando il tasto PRIMA che il browser lo veda (preventDefault
           qui sotto) il comando nativo non parte mai: a togliere e mettere il
           grassetto ci pensa sempre e solo ftMark, la stessa funzione che usa
           il pulsante della barra — un'unica fonte di verita', mai due che
           possono disallinearsi. */
        if ((e.ctrlKey || e.metaKey) && !e.altKey && !e.shiftKey) {
            var tastoFmt = e.key === 'b' || e.key === 'B' ? 'b'
                : e.key === 'i' || e.key === 'I' ? 'i'
                : e.key === 'u' || e.key === 'U' ? 'u'
                : null;
            if (tastoFmt) {
                e.preventDefault();
                ftMark(wrap, tastoFmt);
                return;
            }
        }
        /* Le frecce vicino a una formula.

           Il punto e' che la stessa posizione del documento, sul bordo di una
           formula, e' DUE posti diversi: uno fuori e uno dentro. In "1$2$3"
           il confine dopo il 2 e' sia "subito dopo la formula" sia "in fondo
           al suo contenuto" — stesso numero, due lati. Scrivendo li' cambia
           tutto: da un lato si allunga la formula, dall'altro il testo dopo.
           Quindi il cursore non si sposta di un carattere per volta e basta:
           su ogni bordo si ferma due volte, prima da un lato e poi dall'altro,
           e solo al secondo colpo lo attraversa davvero. E' cio' che rende
           possibile mettersi PRIMA della prima lettera di una formula, che
           altrimenti non e' raggiungibile: ci si passava sopra e si finiva
           gia' fuori.

           Da che lato si e' adesso non si puo' dedurre dal numero: lo dice il
           DOM, cioe' se il cursore e' dentro lo span della formula o no. */
        if ((e.key === 'ArrowLeft' || e.key === 'ArrowRight') &&
            !e.shiftKey && !e.ctrlKey && !e.altKey && !e.metaKey) {
            var sel0 = fcdGetSel(ed);
            if (sel0 && sel0.a.b === sel0.f.b && sel0.a.o === sel0.f.o) {
                var doc0 = fcdFromDom(ed);
                var bi0 = sel0.a.b;
                var blocco0 = doc0.blocks[bi0];
                if (blocco0 && blocco0.t !== 'fx') {
                    var o = sel0.a.o;
                    var f = ftFormulaA(blocco0, o);
                    var dentro = !!ftSpanDelCursore();
                    var oNuovo = o, dentroNuovo = dentro, agire = false;

                    if (e.key === 'ArrowLeft') {
                        if (dentro && f && o === f.inizio) {
                            dentroNuovo = false; agire = true;              // esce dal bordo sinistro
                        } else if (!dentro && f && o === f.fine) {
                            dentroNuovo = true; agire = true;               // entra dal bordo destro
                        } else if (dentro && f && o > f.inizio) {
                            oNuovo = o - 1; dentroNuovo = true; agire = true;
                        }
                    } else {
                        if (dentro && f && o === f.fine) {
                            dentroNuovo = false; agire = true;              // esce dal bordo destro
                        } else if (!dentro && f && o === f.inizio) {
                            dentroNuovo = true; agire = true;               // entra dal bordo sinistro
                        } else if (dentro && f && o < f.fine) {
                            oNuovo = o + 1; dentroNuovo = true; agire = true;
                        }
                    }

                    if (agire) {
                        var blockEl = fcdBlockEls(ed)[bi0];
                        var fNuovo = ftFormulaA(blocco0, oNuovo);
                        if (blockEl && ftPiazzaCursore(blockEl, blocco0, oNuovo, dentroNuovo, fNuovo || f)) {
                            e.preventDefault();
                            /* Se il posto l'ha preso MathQuill, ftMathRefresh
                               NON va chiamato: lui decide chi e' "dentro"
                               guardando il fuoco, e il fuoco su un campo
                               appena creato puo' non risultare ancora
                               registrato — cosi' concludeva che eravamo
                               fuori e SMONTAVA la formula appena aperta,
                               facendo sparire il cursore dalla casella. Il
                               campo appena montato e' gia' nello stato
                               giusto: non c'e' niente da aggiornare. */
                            var campoOra = ftMQCampoAttivo(ed);
        if (campoOra) ftChiudiAltreFormule(ed, campoOra); else ftMathRefresh(wrap);
                            ftRefreshState(wrap);
                            return;
                        }
                    }

                    /* Nessuna formula IN LINEA qui vicino: se pero' si e'
                       esattamente al bordo del blocco (il suo inizio per la
                       freccia sinistra, la sua fine per la destra) e li'
                       accanto c'e' una formula A BLOCCO, la freccia ci
                       salterebbe sopra — un div non modificabile non e' una
                       fermata per il cursore nativo, e' come se non ci fosse.
                       Si entra a mano, dal lato da cui ci si arriva: aperta,
                       pronta a continuare a muoversi dentro con lo stesso
                       tasto. */
                    if (!agire) {
                        var alBordoBlocco = e.key === 'ArrowLeft' ? (o === 0) : (o === fcdBlockLen(blocco0));
                        if (alBordoBlocco) {
                            var viciniIdxA = e.key === 'ArrowLeft' ? bi0 - 1 : bi0 + 1;
                            var vicinoA = doc0.blocks[viciniIdxA];
                            if (vicinoA && vicinoA.t === 'fx') {
                                e.preventDefault();
                                var testoVicinoA = vicinoA.tex || '';
                                var daDestra = e.key === 'ArrowLeft';
                                if (ftMQEntra(ed, wrap, viciniIdxA, daDestra ? 'destra' : 'sinistra')) return;
                                ftCmd(wrap, function (d, s) {
                                    return { doc: d, caret: { b: viciniIdxA, o: daDestra ? testoVicinoA.length : 0 } };
                                });
                                return;
                            }
                        }
                    }
                }
            }
        }

        /* Su e giu': stesso identico problema di sinistra e destra, ma qui
           non basta guardare la posizione nel testo — "sono all'inizio del
           blocco" non vuol dire "sono sulla sua riga visiva piu' in alto" se
           il paragrafo va a capo da solo per la larghezza. Serve la
           geometria vera: dove sta il cursore sullo schermo, non dove sta nel
           testo. E' l'unico modo che non si sbaglia anche quando un
           paragrafo lungo occupa piu' righe. */
        if ((e.key === 'ArrowUp' || e.key === 'ArrowDown') &&
            !e.shiftKey && !e.ctrlKey && !e.altKey && !e.metaKey) {
            var selV = fcdGetSel(ed);
            if (selV && selV.a.b === selV.f.b && selV.a.o === selV.f.o) {
                var docV = fcdFromDom(ed);
                var biV = selV.a.b;
                var blockV = docV.blocks[biV];
                if (blockV && blockV.t !== 'fx') {
                    var suV = e.key === 'ArrowUp';
                    if (ftSulBordoVisivo(fcdBlockText(blockV), selV.a.o, suV)) {
                        var viciniIdxV = suV ? biV - 1 : biV + 1;
                        var vicinoV = docV.blocks[viciniIdxV];
                        if (vicinoV && vicinoV.t === 'fx') {
                            e.preventDefault();
                            var testoVicinoV = vicinoV.tex || '';
                            if (ftMQEntra(ed, wrap, viciniIdxV, suV ? 'destra' : 'sinistra')) return;
                            ftCmd(wrap, function (d, s) {
                                return { doc: d, caret: { b: viciniIdxV, o: suV ? testoVicinoV.length : 0 } };
                            });
                            return;
                        }
                    }
                }
            }
        }
        if (e.key === 'Enter' && !e.shiftKey && ftIntelligenteAuto()) {
            if (ftCreaFormulaABloccoDaParola()) { e.preventDefault(); return; }
        }
        if (e.key === 'Enter') {
            var gestitoInvio = false;
            var selE = fcdGetSel(ed);
            if (selE && selE.a.b === selE.f.b && selE.a.o === selE.f.o && selE.a.o === 0) {
                var docE = fcdFromDom(ed);
                var biE = selE.a.b;
                var bE = docE.blocks[biE];
                if (bE && bE.t === 'fx' && bE.lvl) {
                    e.preventDefault();
                    gestitoInvio = true;
                    if (e.shiftKey) {
                        /* Una riga di continuo: stessa altezza della lista,
                           ma senza un pallino tutto suo — un a capo dentro
                           lo stesso punto, non un punto nuovo. La formula
                           resta dov'e', spostata giu' di una riga, e il
                           cursore resta dentro di lei. */
                        ftCmd(wrap, function (d, s) {
                            d.blocks.splice(biE, 0, { t: 'p', lvl: bE.lvl, runs: [] });
                            return { doc: fcdNorm(d), caret: { b: biE + 1, o: 0 } };
                        });
                    } else {
                        /* Un punto elenco vero e proprio, con lo stesso
                           pallino della formula: il cursore ci si sposta
                           dentro, come premere invio in un punto qualunque
                           della stessa lista — ed e' proprio questo a
                           rendere gratuito il "doppio invio chiude la
                           lista": il secondo invio cade su un punto vuoto,
                           e a quello ci pensa gia' fcdSplitBlock. */
                        ftCmd(wrap, function (d, s) {
                            var nuovo = { t: 'li', lvl: bE.lvl, runs: [] };
                            if (bE.c) nuovo.c = bE.c;
                            d.blocks.splice(biE, 0, nuovo);
                            return { doc: fcdNorm(d), caret: { b: biE, o: 0 } };
                        });
                    }
                }
            }
            if (!gestitoInvio && !e.shiftKey) {
                e.preventDefault();
                /* La formattazione attiva sopravvive all'a capo. Spezzando il
                   blocco la riga nuova nasce vuota, e un blocco vuoto non puo'
                   contenere marchi: andando a capo mentre si scrive in corsivo si
                   ripartiva in tondo, come se il tasto si fosse spento da solo.
                   Si rimette com'era, esattamente come fa Word. */
                var marchi = ftMarksAtCaret(ed);
                ftCmd(wrap, function (doc, sel) { return fcdSplitBlock(doc, sel); });
                if (Object.keys(marchi).length) ftInsertMarked(ed, marchi);
                ftSync(wrap);
            }
            return;
        }
        if (e.key === 'Tab') {
            var d0 = fcdFromDom(ed), s0 = fcdGetSel(ed);
            if (s0 && d0.blocks[s0.a.b] && d0.blocks[s0.a.b].t === 'li') {
                e.preventDefault();
                ftCmd(wrap, function (doc, sel) { return { doc: fcdIndent(doc, sel, e.shiftKey ? -1 : 1), sel: sel }; });
            }
            return;
        }
        /* Cancellare non deve MAI far sparire una formula intera in un
           colpo solo — ne' un tasto Canc su una riga vuota che la precede
           subito, ne' un backspace/canc che la incontra continuando a
           cancellare del testo normale. Deve invece ENTRARCI, e da li' in
           poi cancellarne i caratteri uno alla volta, come farebbe con
           qualunque altro testo. Le due direzioni sono speculari: backspace
           guarda a sinistra/sopra, canc guarda a destra/sotto. */
        if (e.key === 'Backspace' || e.key === 'Delete') {
            var indietro = e.key === 'Backspace';
            var selC = fcdGetSel(ed);
            if (selC && selC.a.b === selC.f.b && selC.a.o === selC.f.o) {
                var docC = fcdFromDom(ed);
                var biC = selC.a.b, oC = selC.a.o;
                var bC = docC.blocks[biC];

                if (bC && bC.t === 'fx') {
                    /* Se la formula ha un pallino, backspace al suo inizio lo
                       toglie — o scala di un livello, se e' annidata — prima
                       di pensare a qualunque altra cosa. E' lo stesso identico
                       comportamento di un punto elenco normale: chi ha un
                       pallino da togliere lo toglie prima di uscire da
                       qualunque parte. Canc, guardando in avanti, non c'entra
                       con l'inizio della formula: qui riguarda solo
                       backspace. */
                    if (indietro && oC === 0 && bC.lvl) {
                        e.preventDefault();
                        ftCmd(wrap, function (d, s) {
                            var f = d.blocks[biC];
                            if ((f.lvl | 0) > 1) f.lvl = f.lvl - 1;
                            else { delete f.lvl; delete f.c; }
                            return { doc: fcdNorm(d), caret: { b: biC, o: 0 } };
                        });
                        return;
                    }

                    /* CASO A: il cursore e' dentro una formula A BLOCCO vuota.
                       Non c'e' bordo da attraversare — e' vuota, quindi
                       backspace o canc la devono eliminare del tutto, non
                       scavalcarla come farebbero con una formula che ha
                       davvero un contenuto. */
                    if (!bC.tex) {
                        e.preventDefault();
                        ftCmd(wrap, function (d, s) {
                            d.blocks.splice(biC, 1);
                            var vaSopra = indietro && biC > 0;
                            var idxFinale = vaSopra ? biC - 1 : Math.min(biC, d.blocks.length - 1);
                            var docNorm = fcdNorm(d);
                            idxFinale = Math.max(0, Math.min(idxFinale, docNorm.blocks.length - 1));
                            var lenFinale = fcdBlockLen(docNorm.blocks[idxFinale]);
                            return { doc: docNorm, caret: { b: idxFinale, o: vaSopra ? lenFinale : 0 } };
                        });
                        return;
                    }

                    /* CASO 0: il cursore e' GIA' dentro la formula a blocco, al
                       suo stesso bordo (l'inizio, per backspace; la fine, per
                       canc). Non c'e' niente della formula da cancellare in
                       quella direzione: si esce verso il blocco vicino, intatta,
                       proprio come farebbe una formula in linea (vedi caso 2, piu'
                       sotto). Solo se non c'e' un vicino in quella direzione — la
                       formula e' la prima o l'ultima cosa del documento — non
                       resta altro da fare che il vecchio comportamento: scioglierla
                       in testo modificabile, invece di restare bloccati li'. */
                    var alSuoBordo = indietro ? (oC === 0) : (oC === fcdBlockLen(bC));
                    if (alSuoBordo) {
                        var viciniIdx0 = indietro ? biC - 1 : biC + 1;
                        var vicino0 = docC.blocks[viciniIdx0];
                        if (vicino0 && vicino0.t !== 'fx') {
                            e.preventDefault();
                            ftCmd(wrap, function (d, s) {
                                var lenVicino = fcdBlockLen(d.blocks[viciniIdx0]);
                                return { doc: d, caret: { b: viciniIdx0, o: indietro ? lenVicino : 0 } };
                            });
                            return;
                        }
                    }
                }

                if (bC && bC.t !== 'fx') {

                    /* CASO C: una formula IN LINEA tocca questa posizione.
                       Se e' VUOTA la si elimina del tutto (stesso discorso
                       del CASO A, ma per una formula dentro il testo invece
                       che a blocco): il suo "inizio" e "fine" coincidono,
                       quindi backspace e canc arrivano qui identici e la
                       tolgono, senza limitarsi ad attraversarne il bordo. */
                    var fC = ftFormulaA(bC, oC);
                    if (fC && fC.inizio === fC.fine) {
                        e.preventDefault();
                        ftCmd(wrap, function (d, s) {
                            var b2 = d.blocks[biC];
                            b2.runs.splice(fC.nRuns, 1);
                            return { doc: fcdNorm(d), caret: { b: biC, o: fC.inizio } };
                        });
                        return;
                    }

                    /* CASO 1: una formula A BLOCCO sta dall'altra parte del
                       confine di questo blocco. Se questo blocco e' vuoto lo
                       si toglie di mezzo; se ha del testo lo si lascia stare.
                       In entrambi i casi non si tocca la formula: ci si
                       limita a portarci il cursore dentro, aperta.

                       Una riga vuota non ha una direzione naturale — e' vuota
                       sia "davanti" che "dietro" allo stesso modo — quindi se
                       non trova niente dal lato che il tasto guarderebbe di
                       norma (backspace guarda su, canc guarda giu') e la riga
                       e' vuota, si guarda anche dall'altro lato. E' il caso
                       di una riga vuota in cima al documento, con la formula
                       solo sotto: li' backspace non avrebbe nulla "prima" da
                       controllare, ma la riga va tolta comunque, e con lo
                       stesso identico risultato di canc — come richiesto. */
                    var vuotoC = fcdBlockLen(bC) === 0;
                    var alBordo = indietro ? (oC === 0) : (oC === fcdBlockLen(bC));
                    if (alBordo || vuotoC) {
                        var naturale = indietro ? biC - 1 : biC + 1;
                        var opposto = indietro ? biC + 1 : biC - 1;
                        var viciniIdx = null;
                        if (docC.blocks[naturale] && docC.blocks[naturale].t === 'fx') viciniIdx = naturale;
                        else if (vuotoC && docC.blocks[opposto] && docC.blocks[opposto].t === 'fx') viciniIdx = opposto;

                        if (viciniIdx !== null) {
                            e.preventDefault();
                            var daSopra = viciniIdx < biC;
                            ftCmd(wrap, function (d, s) {
                                if (vuotoC) d.blocks.splice(biC, 1);
                                var idxF = vuotoC ? (daSopra ? viciniIdx : viciniIdx - 1) : viciniIdx;
                                var testoF = d.blocks[idxF].tex || '';
                                return { doc: fcdNorm(d), caret: { b: idxF, o: daSopra ? testoF.length : 0 } };
                            });
                            return;
                        }

                        /* Riga vuota con un'altra riga qualunque accanto (non
                           una formula): la si toglie qui, a mano. Lasciarlo
                           fare al browser sembrava funzionare con UNA riga
                           vuota sola, ma con piu' righe vuote di fila l'ultima
                           restava sempre li': la fusione nativa di un
                           paragrafo vuoto — che vuoto non e' del tutto, ha
                           dentro il <br> che lo tiene aperto — non e'
                           prevedibile allo stesso modo ogni volta. Togliendola
                           noi il conto torna sempre: un colpo, una riga. */
                        if (vuotoC) {
                            var idxVicino = indietro ? biC - 1 : biC + 1;
                            var vicinoQualunque = docC.blocks[idxVicino];
                            if (vicinoQualunque && vicinoQualunque.t !== 'fx') {
                                e.preventDefault();
                                ftCmd(wrap, function (d) {
                                    d.blocks.splice(biC, 1);
                                    var docN = fcdNorm(d);
                                    var idxFin = Math.max(0, Math.min(indietro ? biC - 1 : biC, docN.blocks.length - 1));
                                    var lungFin = docN.blocks[idxFin].t === 'fx' ? 0 : fcdBlockLen(docN.blocks[idxFin]);
                                    return { doc: docN, caret: { b: idxFin, o: indietro ? lungFin : 0 } };
                                });
                                return;
                            }
                        }
                    }

                    /* CASO 2: una formula IN LINEA tocca questa posizione.
                       Se ci si arriva da fuori, il colpo la apre (non
                       cancella nulla, ne' di lei ne' del testo attorno). Se
                       si e' gia' dentro e si e' proprio al suo bordo, il
                       colpo la richiude uscendo dall'altra parte — anche
                       qui senza cancellare niente. Solo strettamente dentro,
                       lontano da entrambi i bordi, si lascia fare al
                       browser: li' e' testo normale, un carattere alla
                       volta, esattamente come dovrebbe essere. */
                    if (fC) {
                        var dentroC = !!ftSpanDelCursore();
                        var alBordoEsterno = indietro ? (!dentroC && oC === fC.fine) : (!dentroC && oC === fC.inizio);
                        var alBordoInterno = indietro ? (dentroC && oC === fC.inizio) : (dentroC && oC === fC.fine);
                        if (alBordoEsterno || alBordoInterno) {
                            e.preventDefault();
                            var blockElC = fcdBlockEls(ed)[biC];
                            if (blockElC && ftPiazzaCursore(blockElC, bC, oC, alBordoEsterno, fC)) {
                                /* Stesso motivo del ramo delle frecce: se
                                   MathQuill ha appena preso il posto, un
                                   refresh lo smonterebbe e il cursore
                                   sparirebbe. */
                                var campoOra = ftMQCampoAttivo(ed);
        if (campoOra) ftChiudiAltreFormule(ed, campoOra); else ftMathRefresh(wrap);
                                ftRefreshState(wrap);
                            }
                            return;
                        }
                    }
                }
            }
        }
        if (e.key === 'Backspace') {
            var sel = fcdGetSel(ed);
            if (!sel || sel.a.b !== sel.f.b || sel.a.o !== sel.f.o || sel.a.o !== 0) return;
            var doc = fcdFromDom(ed);
            var out = fcdBackspaceAtStart(doc, sel);
            if (out) { e.preventDefault(); ftRender(wrap, out.doc, out.caret); }
        }
    });

    /* Incollare: si prende solo il testo. Quello che arriva da fuori porta con
       se' font, dimensioni e colori di un'altra pagina, e in un quaderno di
       appunti quella roba non la vuole nessuno. */
    /* Copiando un pezzo che contiene formule, negli appunti deve finire il
       LORO CODICE, non il disegno: incollando altrove si riottiene la formula,
       e non una fila di simboli senza senso. Il testo semplice basta perche'
       l'editor riconosce "$...$" e ricostruisce la formula da solo. */
    ['copy', 'cut'].forEach(function (evento) {
        ed.addEventListener(evento, function (e) {
            /* Dentro una formula copia e taglia li fa MathQuill, che sa
               esattamente cosa significa "meta' frazione". */
            if (ftMQFuocoQui(ed)) return;
            /* Selezione tutta dentro una sola formula: si sta copiando del
               CODICE, e il codice si copia com'e'. Aggiungerci i dollari
               attorno — cosa giusta quando si copia del testo che contiene
               formule — qui produrrebbe una formula dentro la formula. */
            var s0 = window.getSelection();
            if (s0 && s0.rangeCount && !s0.isCollapsed) {
                var a = s0.anchorNode, f = s0.focusNode;
                var ea = a && (a.nodeType === 1 ? a : a.parentNode);
                var ef = f && (f.nodeType === 1 ? f : f.parentNode);
                var fa = ea && ea.closest ? ea.closest('.fc-x, .fc-fx') : null;
                var ff = ef && ef.closest ? ef.closest('.fc-x, .fc-fx') : null;
                if (fa && fa === ff) return;      // se ne occupa il browser
            }

            var sel = fcdGetSel(ed);
            if (!sel) return;
            var doc = fcdFromDom(ed);
            var s0 = sel.a.b < sel.f.b || (sel.a.b === sel.f.b && sel.a.o <= sel.f.o) ? sel : { a: sel.f, f: sel.a };
            var pezzi = [];
            for (var bi = s0.a.b; bi <= s0.f.b; bi++) {
                var b = doc.blocks[bi];
                if (!b) continue;
                if (b.t === 'fx') { pezzi.push('$$' + b.tex + '$$'); continue; }
                var da = (bi === s0.a.b) ? s0.a.o : 0;
                var a  = (bi === s0.f.b) ? s0.f.o : fcdBlockLen(b);
                var acc = 0, riga = '';
                (b.runs || []).forEach(function (r) {
                    var t = (typeof r.x === 'string') ? r.x : (r.s || '');
                    var inizio = Math.max(da, acc), fine = Math.min(a, acc + t.length);
                    if (fine > inizio) {
                        var pezzo = t.slice(inizio - acc, fine - acc);
                        riga += (typeof r.x === 'string') ? '$' + pezzo + '$' : pezzo;
                    }
                    acc += t.length;
                });
                pezzi.push((b.t === 'li' ? '- ' : '') + riga);
            }
            var testo = pezzi.join('\n');
            if (!testo) return;
            e.preventDefault();
            var cd = (e.clipboardData || window.clipboardData);
            cd.setData('text/plain', testo);
            /* La formattazione vera — grassetto, colori, elenchi, formule —
               passa da qui: un formato tutto nostro, che solo un'altra pagina
               di questo editor sa leggere. Copiando fuori dal sito resta il
               testo semplice qui sopra, che e' quello che qualunque altra
               applicazione si aspetta comunque di trovare. */
            try {
                var sottodoc = fcdEstraiSelezione(doc, s0);
                cd.setData('application/x-nisama-fcdoc', JSON.stringify(sottodoc));
            } catch (errCopia) { /* niente di grave: resta il testo semplice sopra */ }
            if (evento === 'cut') ftCmd(wrap, function (d, s2) { return fcdDeleteRange(d, s2); });
        });
    });

    ed.addEventListener('paste', function (e) {
        if (ftMQFuocoQui(ed)) return;   // dentro una formula incolla MathQuill
        e.preventDefault();
        var cd = (e.clipboardData || window.clipboardData);
        var ricco = cd.getData('application/x-nisama-fcdoc');
        if (ricco) {
            try {
                var sottodoc = JSON.parse(ricco);
                ftCmd(wrap, function (d, s2) {
                    var posizione = s2.a;
                    if (!(s2.a.b === s2.f.b && s2.a.o === s2.f.o)) {
                        var tolto = fcdDeleteRange(d, s2);
                        d = tolto.doc; posizione = tolto.caret;
                    }
                    return fcdIncollaSottodocumento(d, posizione, sottodoc);
                });
                return;
            } catch (errIncolla) { /* formato non riconosciuto: si scende al testo semplice sotto */ }
        }
        var t = cd.getData('text/plain') || '';
        if (!t) return;
        /* Se quello che si incolla contiene formule scritte con i dollari, le
           si rimette come formule invece che come testo: e' il gesto piu'
           comune, copiare da una scheda all'altra. */
        if (/\$[^$\n]+\$/.test(t)) {
            ftCmd(wrap, function (d, s2) {
                var pezzo = fcdParse(t);
                var b = d.blocks[s2.a.b];
                if (!b || b.t === 'fx' || pezzo.blocks.length !== 1 || pezzo.blocks[0].t !== 'p') {
                    /* piu' righe: si inseriscono come blocchi nuovi */
                    var giu = pezzo.blocks;
                    d.blocks.splice.apply(d.blocks, [s2.a.b + 1, 0].concat(giu));
                    return { doc: fcdNorm(d), caret: { b: s2.a.b + giu.length, o: 0 } };
                }
                var idx = fcdSplitAt(b, s2.a.o);
                b.runs.splice.apply(b.runs, [idx, 0].concat(pezzo.blocks[0].runs));
                return { doc: fcdNorm(d), caret: { b: s2.a.b, o: s2.a.o + fcdBlockLen(pezzo.blocks[0]) } };
            });
            return;
        }
        document.execCommand('insertText', false, t);
    });

    /* Clic su una formula disegnata: torna codice, col cursore in fondo,
       pronta da correggere. Senza questo, l'unico modo di rimetterci mano
       sarebbe cancellarla e riscriverla. */
    ed.addEventListener('mousedown', function (e) {
        var resa = e.target.closest ? e.target.closest('.fc-resa') : null;
        if (!resa || !ed.contains(resa)) return;
        /* Shift premuto vuol dire che si sta ESTENDENDO una selezione, non
           cliccando per modificare: intervenire qui la spezzerebbe a meta',
           facendo sembrare la formula immune alla selezione (e' lo stesso
           problema gia' risolto per l'evidenziazione — questo ne era la
           causa quando la selezione parte o passa da un clic con shift). */
        if (e.shiftKey) return;
        e.preventDefault();
        /* Prima si chiude quella che era aperta, poi si apre questa. Senza,
           passando col clic direttamente da una formula all'altra la prima
           restava aperta a mostrare il suo codice, e per richiuderla si era
           costretti a un giro fuori da tutte le formule. Il ridisegno di
           quelle vecchie non tocca questa, che viene aperta subito dopo. */
        ftChiudiAltreFormule(ed, resa);
        ftMathEdita(resa, true, wrap, 'destra');
    });

    /* Scrivere "$...$" a mano crea la formula, senza passare dal tasto. Si
       controlla solo alla chiusura del secondo dollaro e solo se in mezzo c'e'
       qualcosa: cosi' "$$" da solo resta due dollari, e "costa 5$ e 3$" non
       viene toccato finche' non si scrive davvero una formula. */
    /* Riconosce, nel testo che finisce appena prima del $ che si sta per
       digitare, una coppia di delimitatori appena completata: $$...$$ (a
       blocco) ha la precedenza perche' e' la piu' specifica, altrimenti
       $...$ (in linea). Ritorna null se non c'e' niente da chiudere — "$$" da
       solo, senza contenuto, resta cosi' com'e', proprio come gia' faceva la
       versione a un solo dollaro. */
    function ftDollariRileva(preConNuovo) {
        if (preConNuovo.slice(-2) === '$$') {
            var corpo = preConNuovo.slice(0, -2);
            var apreB = corpo.lastIndexOf('$$');
            if (apreB === -1) return null;                 // e' l'APERTURA di "$$", non una chiusura
            var dentroB = corpo.slice(apreB + 2);
            if (dentroB === '' || dentroB.indexOf('$') !== -1 || /^\s|\s$/.test(dentroB)) return null;
            return { blocco: true, apre: apreB, dentro: dentroB };
        }
        if (preConNuovo.slice(-1) === '$') {
            var corpo1 = preConNuovo.slice(0, -1);
            var apre1 = corpo1.lastIndexOf('$');
            if (apre1 === -1) return null;
            /* Il $ trovato e' parte di una coppia "$$" ancora incompleta (il
               suo compagno e' proprio la lettera prima)? Allora non e' un
               apritore singolo: si aspetta il secondo $ di chiusura, che
               arrivera' al prossimo carattere digitato. */
            if (apre1 > 0 && corpo1.charAt(apre1 - 1) === '$') return null;
            var dentro1 = corpo1.slice(apre1 + 1);
            if (dentro1 === '' || dentro1.indexOf('$') !== -1 || /^\s|\s$/.test(dentro1)) return null;
            return { blocco: false, apre: apre1, dentro: dentro1 };
        }
        return null;
    }

    /* Una PAROLA che comincia con "\" seguita da lettere ("\omega",
       "\sqrt{2}") o che ha la forma di un pedice, di un esponente, o di
       entrambi insieme ("ciao_x", "a^2", "a_i^2") e' quasi certamente un
       comando LaTeX scritto a mano: appena finisce (lo spazio che la segue
       lo dice) diventa una formula da sola, senza che serva scrivere i
       dollari. */
    function ftLatexRilevato(parola) {
        if (/^\\[a-zA-Z]+/.test(parola)) return true;
        /* Un pedice, un esponente, o entrambi insieme in un ordine o
           nell'altro — ma non una catena di piu' di uno dello stesso tipo:
           "file_name_qui" resta un nome di variabile, non diventa una
           formula solo perche' ha un underscore, anzi due. */
        if (/^[a-zA-Z0-9]+(_[a-zA-Z0-9]+\^[a-zA-Z0-9]+|\^[a-zA-Z0-9]+_[a-zA-Z0-9]+|_[a-zA-Z0-9]+|\^[a-zA-Z0-9]+)$/.test(parola)) return true;
        return false;
    }

    /* La parola LaTeX appena finita, se il cursore e' esattamente alla sua
       fine e non si e' gia' dentro una formula: usata sia dallo spazio
       (sotto) sia dall'invio (nel gestore dei tasti, piu' sotto). Tenerla
       in un punto solo evita che i due si comportino in modo diverso per
       una semplice disattenzione nel copiarla due volte. */
    function ftParolaLatexDaConvertire(ed) {
        var sel = fcdGetSel(ed);
        if (!sel || sel.a.b !== sel.f.b || sel.a.o !== sel.f.o) return null;
        var doc = fcdFromDom(ed);
        var bl = doc.blocks[sel.a.b];
        if (!bl || bl.t === 'fx') return null;
        /* Il cursore e' gia' dentro una formula in linea (ancora aperta,
           quindi modificabile — una gia' disegnata non si potrebbe nemmeno
           raggiungere per scriverci)? Allora quello che sta scrivendo e'
           LaTeX vero, non una parola candidata a diventarne una: applicarne
           un'altra sopra non avrebbe alcun senso. */
        if (ftFormulaA(bl, sel.a.o)) return null;
        var testo = fcdBlockText(bl);
        var prima = testo.slice(0, sel.a.o);
        var ultimoSpazio = Math.max(prima.lastIndexOf(' '), prima.lastIndexOf('\n'));
        var parola = prima.slice(ultimoSpazio + 1);
        if (!parola || !ftLatexRilevato(parola)) return null;
        return { bi: sel.a.b, inizio: sel.a.o - parola.length, fine: sel.a.o, parola: parola, testoBlocco: testo };
    }

    /* La stessa idea dello spazio, ma al contrario: l'invio dice "ho finito
       con questa riga", quindi qui la parola diventa una formula A BLOCCO —
       la riga tutta sua, con un'altra riga vuota subito sotto pronta per
       continuare, esattamente come premere a mano il tasto "a blocco". Se
       c'era gia' del testo prima sulla stessa riga, quello resta com'era,
       sopra.
       E' una funzione a se' (non piu' incollata dentro il solo gestore
       keydown) perche' l'Invio, sulla tastiera VIRTUALE del telefono, spesso
       non genera affatto un keydown riconoscibile — molte tastiere Android e
       gli IME in generale saltano il keydown per l'Invio e passano
       direttamente a beforeinput con inputType "insertParagraph". Il
       cervello (il pulsante che riconosce da solo il LaTeX digitato) per
       questo funzionava "solo in linea, mai a blocco" su chi scrive da
       telefono: lo spazio arriva sempre come beforeinput/insertText e quindi
       funzionava, l'invio arrivava SOLO come keydown e su quelle tastiere
       non scattava mai. Restituisce true se ha agito (la chiamata deve
       fermarsi li'), false se non c'era niente da convertire. */
    function ftCreaFormulaABloccoDaParola() {
        var trovataE = ftParolaLatexDaConvertire(ed);
        if (!trovataE) return false;
        ftCmd(wrap, function (d, s) {
            var b = d.blocks[trovataE.bi];
            var i0 = fcdSplitAt(b, trovataE.inizio), i1 = fcdSplitAt(b, trovataE.fine);
            var dopoRuns = b.runs.splice(i1);
            b.runs.splice(i0);
            var nuovo = { t: 'fx', tex: trovataE.parola };
            if (b.t === 'li') { nuovo.lvl = b.lvl; if (b.c) nuovo.c = b.c; }
            var dopoBlocco = { t: b.t === 'li' ? 'li' : 'p', runs: dopoRuns };
            if (b.t === 'li') { dopoBlocco.lvl = b.lvl; if (b.c) dopoBlocco.c = b.c; }

            var pezzi = [];
            if (fcdBlockLen(b) > 0) pezzi.push(b);
            pezzi.push(nuovo);
            /* Dopo la formula ci va sempre una riga pronta a
               ricevere quello che si scrive di seguito: quella che
               c'era gia' (se c'era altro testo dopo il cursore) o,
               se non c'era, una nuova riga vuota apposta. */
            pezzi.push(fcdBlockLen(dopoBlocco) > 0 ? dopoBlocco : { t: 'p', runs: [] });

            var indiceFormula = trovataE.bi + pezzi.indexOf(nuovo);
            d.blocks.splice.apply(d.blocks, [trovataE.bi, 1].concat(pezzi));
            return { doc: fcdNorm(d), caret: { b: indiceFormula + 1, o: 0 } };
        });
        return true;
    }

    ed.addEventListener('beforeinput', function (e) {
        /* Il segnaposto sparisce appena si comincia a scrivere, senza aspettare
           il giro di sincronizzazione: e' un dettaglio, ma vedere il testo
           d'esempio ancora li' mentre si digita da' l'impressione che la
           casella non stia ricevendo quello che si scrive. */
        if (e.inputType && e.inputType.indexOf('insert') === 0) ed.dataset.vuoto = '0';

        /* Spento per difetto: qui sotto e' la stessa idea del dollaro
           automatico, ma innescata dallo spazio dopo una parola invece che
           dal dollaro stesso. Lo spazio fa SEMPRE una formula in linea: e'
           il tasto che dice "continuo su questa riga", quindi la riga resta
           quella, formula compresa. Chi vuole la riga tutta per la formula
           preme invio, non spazio — se ne occupa il gestore dei tasti (e,
           per le tastiere virtuali che l'invio lo mandano solo di qui, il
           blocco subito sotto). */
        if (e.inputType === 'insertText' && e.data === ' ' && ftIntelligenteAuto()) {
            var trovataI = ftParolaLatexDaConvertire(ed);
            if (trovataI) {
                e.preventDefault();
                /* La parola diventa la formula, e lo spazio appena digitato
                   si rimette subito dopo — non si perde, altrimenti
                   continuando a scrivere la frase successiva si salderebbe
                   alla formula. */
                ftCmd(wrap, function (d, s2) {
                    var b = d.blocks[trovataI.bi];
                    var i0 = fcdSplitAt(b, trovataI.inizio), i1 = fcdSplitAt(b, trovataI.fine);
                    b.runs.splice(i0, i1 - i0, { x: trovataI.parola }, { s: ' ' });
                    return { doc: fcdNorm(d), caret: { b: trovataI.bi, o: trovataI.inizio + trovataI.parola.length + 1 } };
                });
                return;
            }
        }

        /* L'invio da tastiera virtuale: il gestore keydown (piu' sopra nel
           file) intercetta l'Invio fisico, ma su molte tastiere Android e
           IME l'Invio non genera affatto un keydown riconoscibile — passa
           qui direttamente, di solito con inputType "insertParagraph". Alcune
           tastiere pero' segnalano un Invio semplice come "insertLineBreak"
           (che dovrebbe voler dire solo "riga di continuo", non un vero a
           capo) — una scorciatoia non proprio corretta, ma capita davvero,
           ed e' molto probabilmente la ragione per cui il riconoscimento
           restava "sempre in linea, mai a blocco" anche dopo aver
           aggiunto qui sotto il solo "insertParagraph": su quelle tastiere
           l'Invio vero non generava NESSUNO dei due segnali che si stava
           ad ascoltare. Si accettano entrambi: il costo, un a capo voluto
           con Maiusc+Invio che ogni tanto crea la formula invece di restare
           un semplice ritorno a capo, e' minore del beneficio di riconoscere
           l'Invio vero su chi scrive da telefono. */
        if ((e.inputType === 'insertParagraph' || e.inputType === 'insertLineBreak') && ftIntelligenteAuto()) {
            if (ftCreaFormulaABloccoDaParola()) { e.preventDefault(); return; }
        }

        /* Spento (il caso di default): il dollaro e' sempre e solo un
           carattere, "costa 5$$" o "costa 5$ e altri 3$" restano testo, punto
           — per la formula ci sono i due tasti sopra. */
        if (e.inputType !== 'insertText' || e.data !== '$' || !ftDollariAuto()) return;
        var sel = fcdGetSel(ed);
        if (!sel || sel.a.b !== sel.f.b || sel.a.o !== sel.f.o) return;

        var doc = fcdFromDom(ed);
        var bl = doc.blocks[sel.a.b];
        if (!bl || bl.t === 'fx') return;

        var testo = fcdBlockText(bl).slice(0, sel.a.o);
        /* Una formula non comincia ne' finisce con uno spazio. E' questa
           regola che distingue "$\omega$" da "costa 5$ e poi $": nel secondo
           caso in mezzo ai due dollari c'e' " e poi ", che e' una frase, non
           una formula. La stessa regola vale identica per $$...$$. */
        var trovato = ftDollariRileva(testo + '$');
        if (!trovato) return;

        e.preventDefault();

        if (!trovato.blocco) {
            ftCmd(wrap, function (d, s2) {
                var b = d.blocks[s2.a.b];
                var i0 = fcdSplitAt(b, trovato.apre), i1 = fcdSplitAt(b, s2.a.o);
                b.runs.splice(i0, i1 - i0, { x: trovato.dentro });
                return { doc: fcdNorm(d), caret: { b: s2.a.b, o: trovato.apre + trovato.dentro.length }, dopoFormula: true };
            });
            return;
        }

        /* $$...$$ va a blocco, come premere il tasto "a blocco": occupa una
           riga sua, e dopo si continua a scrivere testo normale sotto, non
           dentro la formula appena chiusa. */
        ftCmd(wrap, function (d, s2) {
            var bi = s2.a.b;
            var b = d.blocks[bi];
            var i0 = fcdSplitAt(b, trovato.apre), i1 = fcdSplitAt(b, s2.a.o);
            b.runs.splice(i0, i1 - i0);
            var nuovo = { t: 'fx', tex: trovato.dentro };
            if (b.t === 'li') { nuovo.lvl = b.lvl; if (b.c) nuovo.c = b.c; }
            if (fcdBlockLen(b) === 0) {
                d.blocks[bi] = nuovo;
            } else {
                d.blocks.splice(bi + 1, 0, nuovo);
                bi += 1;
            }
            d.blocks.splice(bi + 1, 0, { t: 'p', runs: [] });
            return { doc: fcdNorm(d), caret: { b: bi + 1, o: 0 } };
        });
    });

    ed.addEventListener('input', function () {
        /* Se si sta scrivendo dentro una formula, data-tex deve seguire: e'
           lui la fonte di verita', non cio' che si vede. */
        var sel = window.getSelection();
        if (sel && sel.rangeCount) {
            var n = sel.anchorNode, el = n && (n.nodeType === 1 ? n : n.parentNode);
            var f = el && el.closest ? el.closest('.fc-x, .fc-fx') : null;
            if (f && !fcdEDisegnata(f)) {
                f.dataset.tex = (f.textContent || '').replace(/\u200B/g, '');
                /* Il LaTeX e' cambiato sotto le dita: se era una di quelle
                   che MathQuill aveva rifiutato, va data un'altra
                   possibilita' — correggendo a mano il pezzo che non
                   capiva, adesso potrebbe leggerla benissimo. */
                delete f.dataset.mqNo;
            }
        }
        ftSync(wrap); ftRefreshState(wrap);
        /* Scrivere puo' far crescere la casella (una riga nuova, un a capo
           dentro una lista lunga) e spostare cosi' la barra rispetto alla
           soglia — senza che nessuno abbia scorso la pagina, quindi senza
           che il nostro ascoltatore sullo scroll se ne accorga da solo.
           Ricontrollare anche qui, a ogni tasto, e' la rete di sicurezza:
           se un caso di crescita sfuggisse ancora al solo scroll, si
           corregge comunque al giro successivo, non resta scoperto finche'
           non arriva uno scroll vero. Se non c'e' niente da agganciare (la
           barra e' gia' a posto, o non e' il caso) la funzione non fa
           nulla di visibile. */
        if (typeof ftAggancioAggiorna === 'function') ftAggancioAggiorna();
    });
    /* keyup e mouseup scattano dopo OGNI tasto e OGNI clic. Se in quel
       momento si sta scrivendo dentro MathQuill, il refresh non va fatto:
       lui decide chi e' "dentro" dal fuoco, e il fuoco di MathQuill sta su
       una casella nascosta che non sempre risulta gia' registrata — cosi'
       concludeva che si era usciti e smontava la formula sotto le dita,
       facendo sparire il cursore a meta' di una parola. Lo stato di un campo
       montato e' gia' quello giusto: non c'e' niente da aggiornare. */
    ed.addEventListener('keyup', function () {
        ftRefreshState(wrap);
        var campoOra = ftMQCampoAttivo(ed);
        if (campoOra) ftChiudiAltreFormule(ed, campoOra); else ftMathRefresh(wrap);
    });
    ed.addEventListener('mouseup', function () {
        ftRefreshState(wrap);
        var campoOra = ftMQCampoAttivo(ed);
        if (campoOra) ftChiudiAltreFormule(ed, campoOra); else ftMathRefresh(wrap);
    });

    /* All'uscita si ridisegna dal modello: qualunque stranezza lasciata dal
       browser (pezzi vuoti, marcatori doppi) sparisce, e quello che si vede
       torna a essere esattamente quello che verra' salvato. */
    ed.addEventListener('blur', function () {
        /* Entrare in una formula NON e' uscire dall'editor. MathQuill riceve
           i tasti da una casella nascosta dentro di se': quando il cursore
           entra nella formula, per il browser l'editor ha perso il fuoco e
           scatta questo blur — che ridisegnando tutto distruggeva il campo
           appena aperto, e con lui il cursore, esattamente un istante dopo
           averlo messo dentro. Era questo a far "sparire il cursore e il
           focus dalla casella" premendo la freccia verso una formula.
           Il fuoco non e' uscito: si e' spostato piu' dentro. */
        if (ftMQCampoAttivo(ed)) return;
        if (wrap._ftFermaDettatura) wrap._ftFermaDettatura();
        var doc = fcdFromDom(ed);
        ed.innerHTML = fcdToHTML(doc);
        ftSync(wrap, doc);
        ftMathRefresh(wrap);       // fuori dall'editor si vedono tutte disegnate
    });

    /* L'uscita VERA, quella che il blur qui sopra non puo' piu' riconoscere:
       si controlla dove e' finito il fuoco, non piu' se e' partito. Se e'
       andato del tutto fuori da questa casella — un'altra casella, un altro
       punto della pagina — allora si', si esce davvero: la formula aperta si
       chiude e si ridisegna tutto dal modello, come si e' sempre fatto. Il
       controllo e' rimandato di un attimo perche' nel momento esatto del
       focusout il browser non ha ancora detto dove sta andando il fuoco. */
    ed.addEventListener('focusout', function () {
        setTimeout(function () {
            if (!document.body.contains(ed)) return;
            var dentroQui = document.activeElement && wrap.contains(document.activeElement);
            if (dentroQui) return;                    // fuoco ancora qui (magari dentro una formula)
            var campo = ftMQCampoAttivo(ed);
            if (campo) ftMQSmonta(campo);
            if (wrap._ftFermaDettatura) wrap._ftFermaDettatura();
            var doc2 = fcdFromDom(ed);
            ed.innerHTML = fcdToHTML(doc2);
            ftSync(wrap, doc2);
            ftMathRefresh(wrap);
        }, 0);
    });
}

// ---------------------------------------------------------------
// I tasti della barra
// ---------------------------------------------------------------
function ftSetupToolbar(wrap) {
    var bar = ftBarra(wrap);
    var ed = ftEditor(wrap);
    if (!bar || !ed) return;

    /* Nessun <button> della barra deve MAI poter tenere il fuoco per conto
       suo: e' la causa vera, mai risolta del tutto, di "creo la formula col
       tasto e non ci si scrive dentro". Non e' il mousedown il colpevole (lo
       preveniamo gia' qui sotto, su tutta la barra): un <button> nativo, in
       Chrome, si mette a fuoco da solo come parte della sua "attivazione"
       quando il CLIC si completa — un passo distinto dal mousedown, che
       preventDefault sul mousedown non tocca affatto — e lo fa DOPO che il
       nostro gestore del click e' gia' finito. Il tentativo di rincorrerlo
       (un secondo focus() con setTimeout/requestAnimationFrame, ancora piu'
       sotto in ftMQFuoco) e' una corsa contro un tempo che il browser non
       promette mai: a volte arriva prima lui, a volte prima noi — ed e'
       esattamente per questo che il difetto sembrava capriccioso invece che
       sempre uguale. tabindex="-1" lo toglie alla radice: un elemento con
       quel valore resta cliccabile come sempre (il suo click parte
       regolarmente) ma non e' piu' un candidato per il fuoco assegnato dal
       click stesso ne' dal tasto Tab — puo' comunque prendere il fuoco se
       lo si chiama esplicitamente da codice (mai il nostro caso), quindi
       niente da rincorrere piu'. Si applica a chi c'e' gia' ora; i pulsanti
       creati piu' tardi (lingue, suggerimenti vocali) lo impostano da soli
       alla nascita. */
    bar.querySelectorAll('button').forEach(function (b) { b.tabIndex = -1; });

    /* Qualunque mousedown dentro la barra, non solo sui pulsanti: un tocco
       nello spazio vuoto (il margine fra un gruppo e l'altro, lo sfondo del
       cassetto) non e' su nessun elemento cliccabile, ma il comportamento di
       default del mousedown su un'area non modificabile e' comunque togliere
       il fuoco a cio' che ce l'ha — la casella, in questo caso — anche senza
       che ci sia niente su cui il fuoco potrebbe spostarsi. Prima si
       intercettavano solo i pulsanti conosciuti (.fmt-btn, .fmt-pop-dot,
       .fmt-sugg-voce): un tocco a vuoto, o su un pulsante generato piu' tardi
       con un'altra classe (.fmt-lingua, nel pannello delle lingue), passava
       dritto e la casella perdeva il fuoco — la barra si richiudeva da sola
       (vedi fmt-attiva) come se si fosse toccato fuori. Prevenendo il default
       su OGNI mousedown qui dentro, un tocco a vuoto ora e' davvero come non
       aver toccato niente: il fuoco resta dov'era, e i pulsanti veri
       continuano a funzionare lo stesso — preventDefault sul mousedown non
       tocca il click ne' lo stile :active, ferma solo lo spostamento del
       fuoco che nessuno ha chiesto. */
    bar.addEventListener('mousedown', function (e) { e.preventDefault(); });

    bar.addEventListener('click', function (e) {

        var dot = e.target.closest('.fmt-pop-dot');
        /* Preso di poco fuori? Vale lo stesso. I pallini sono piccoli e
           distanziati, e un dito che cade nel vuoto fra due — o appena sopra
           o sotto la riga — non faceva niente: si doveva riprovare, spesso
           piu' volte. Qui, se il tocco e' caduto dentro la fila dei colori ma
           non su un pallino, si prende quello con il centro piu' vicino.
           Solo entro una distanza ragionevole (18px dal bordo del pallino):
           piu' lontano di cosi' non e' piu' "quasi centrato", e applicare un
           colore che non si voleva sarebbe peggio che non applicarne
           nessuno. */
        if (!dot) {
            /* Si cerca in tutto il pannello, non solo dentro la striscia dei
               colori: cadere appena SOPRA o SOTTO la fila e' il caso piu'
               comune di tutti, e li' il tocco atterra gia' fuori dalla
               striscia — restringendo la ricerca a lei non si sarebbe preso
               proprio il caso da risolvere. */
            var fila = e.target.closest ? (e.target.closest('.fmt-panel') || e.target.closest('.fmt-swatches')) : null;
            if (fila) {
                var vicini = fila.querySelectorAll('.fmt-swatches .fmt-pop-dot');
                var migliore = null, minima = Infinity;
                for (var vi = 0; vi < vicini.length; vi++) {
                    var rv = vicini[vi].getBoundingClientRect();
                    var dx = Math.max(rv.left - e.clientX, 0, e.clientX - rv.right);
                    var dy = Math.max(rv.top - e.clientY, 0, e.clientY - rv.bottom);
                    var d = Math.sqrt(dx * dx + dy * dy);
                    if (d < minima) { minima = d; migliore = vicini[vi]; }
                }
                if (migliore && minima <= 18) dot = migliore;
            }
        }
        if (dot) {
            if (dot.hasAttribute('data-color')) ftMarkImposta(wrap, 'c', dot.dataset.color || undefined);
            else ftCmd(wrap, function (doc, sel) { return { doc: fcdSetListColor(doc, sel, dot.dataset.listColor || null), sel: sel }; });
            ftRefreshState(wrap);
            return;
        }

        var btn = e.target.closest('.fmt-btn');
        if (!btn) return;

        var cmd = btn.dataset.cmd;

        /* Il microfono e' l'unico tasto che fa due cose: accende la dettatura
           (se ne occupa il suo gestore) e apre le sue impostazioni, che si
           cambiano mentre si parla. Gli altri con un pannello fanno solo
           quello. */
        if (btn.classList.contains('fmt-tab')) {

            if (btn.dataset.panel === 'list') {
                /* Comportamento voluto, a due tempi: sul pallino gia' messo,
                   un click a pannello CHIUSO apre solo il pannello (non
                   tocca il pallino); e' ricliccandolo a pannello GIA' APERTO
                   che lo toglie. Frase senza pallino -> lo crea e apre
                   sempre il pannello, in un solo click.
                   (Avevo scambiato questa descrizione per un difetto da
                   correggere in un giro precedente, rendendo tutto un
                   singolo click — non era quello che veniva chiesto: qui e'
                   ripristinato il comportamento a due tempi originale.) */
                var edLi = ftEditor(wrap);
                var docLi = fcdFromDom(edLi);
                /* Il blocco vero, anche se il fuoco e' dentro MathQuill: la
                   sola selezione del documento indicherebbe il testo attorno
                   (o il primo blocco), ed e' li' che il pallino finiva per
                   sbaglio invece che sulla formula. */
                var biLi = ftBloccoCorrente(edLi);
                var bloccoLi = (biLi != null) ? docLi.blocks[biLi] : null;
                var eraLi = !!(bloccoLi && (bloccoLi.t === 'li' || (bloccoLi.t === 'fx' && bloccoLi.lvl)));
                var pannelloEraAperto = fcPannelloAperto(bar, 'list');

                if (eraLi && !pannelloEraAperto) {
                    /* C'e' gia' il pallino ma il pannello e' chiuso: questo
                       click apre solo il pannello, il pallino resta. */
                    fcOpenPanelForce(bar, 'list');
                    return;
                }

                ftCmd(wrap, function (d, s) { return { doc: fcdToggleList(d, s), sel: s }; });

                if (eraLi) fcClosePanel(bar);
                else fcOpenPanelForce(bar, 'list');

                ftRefreshState(wrap);
                return;
            }

            /* Il microfono e' l'unico altro tasto che fa due cose: accende la
               dettatura (se ne occupa il suo gestore) e apre le sue
               impostazioni, che si cambiano mentre si parla. Gli altri con un
               pannello fanno solo quello. */
            if (cmd !== 'dictate' || !fcPannelloAperto(bar, 'mic')) fcOpenPanel(bar, btn.dataset.panel);

            if (cmd !== 'dictate') return;
        }

        if (cmd === 'dictate') return;
        if (cmd === 'punteggiatura') return;    // se ne occupa il pannello della dettatura
        if (cmd === 'dollari-auto') return;     // se ne occupa il suo interruttore, qui sotto
        if (cmd === 'intelligente-auto') return;   // idem

        if (FT_MARK_CMD[cmd]) { ftMark(wrap, FT_MARK_CMD[cmd]); }
        else if (cmd === 'list')        ftCmd(wrap, function (d, s) { return { doc: fcdToggleList(d, s), sel: s }; });
        else if (cmd === 'indent')      ftCmd(wrap, function (d, s) { return { doc: fcdIndent(d, s, 1), sel: s }; });
        else if (cmd === 'outdent')     ftCmd(wrap, function (d, s) { return { doc: fcdIndent(d, s, -1), sel: s }; });
        else if (cmd === 'soft-break')  ftCmd(wrap, function (d, s) { return fcdInsertSoftBreak(d, s); });
        else if (cmd === 'math-inline') { ftCmd(wrap, function (d, s) { return fcdToggleInlineMath(d, s); }); ftSuggerisciFormula(wrap, bar); }
        else if (cmd === 'math-display') { ftCmd(wrap, function (d, s) { return fcdToggleBlockMath(d, s); }); ftSuggerisciFormula(wrap, bar); }

        ftRefreshState(wrap);
    });

    /* L'interruttore dei dollari: una preferenza persistente come la
       punteggiatura, non uno stato che dipende dal cursore — si aggiorna una
       volta sola qui, non ad ogni ftRefreshState. */
    var dollariBtn = bar.querySelector('.fmt-dollari');
    if (dollariBtn) {
        var segnaDollari = function (on) {
            dollariBtn.classList.toggle('is-active', on);
            dollariBtn.setAttribute('aria-pressed', on ? 'true' : 'false');
            dollariBtn.title = on
                ? 'Acceso — scrivendo $...$ o $$...$$ si crea subito la formula'
                : 'Spento — il simbolo $ resta un carattere normale, la formula si crea con i tasti sopra';
        };
        segnaDollari(ftDollariAuto());
        dollariBtn.addEventListener('click', function () {
            var on = !dollariBtn.classList.contains('is-active');
            ftPrefScrivi('dollari', on ? '1' : '0');
            segnaDollari(on);
        });
    }

    /* Stessa idea, per il riconoscimento automatico. */
    var intelligenteBtn = bar.querySelector('.fmt-intelligente');
    if (intelligenteBtn) {
        var segnaIntelligente = function (on) {
            intelligenteBtn.classList.toggle('is-active', on);
            intelligenteBtn.setAttribute('aria-pressed', on ? 'true' : 'false');
            intelligenteBtn.title = on
                ? 'Acceso — un comando LaTeX appena scritto ("\\omega", "ciao_x", "a^2") diventa subito una formula'
                : 'Spento — un comando LaTeX scritto a mano resta testo normale finche\' non lo metti tu tra i dollari';
        };
        segnaIntelligente(ftIntelligenteAuto());
        intelligenteBtn.addEventListener('click', function () {
            var on = !intelligenteBtn.classList.contains('is-active');
            ftPrefScrivi('intelligente', on ? '1' : '0');
            segnaIntelligente(on);
        });
    }

    ftSetupDictation(wrap, bar);
}

/* Entrando in una formula col TASTO (non a voce) compaiono lo stesso i
   suggerimenti: sono comodi anche col dito, e chi scrive a mano non deve
   ricordarsi a memoria come si chiamano i comandi LaTeX. Si chiudono da soli
   uscendo dalla formula. */
/* Disegna i suggerimenti senza passare dalla dettatura: serve dove il
   riconoscimento vocale non esiste (molti browser da computer), perche' i
   comandi restano utili anche solo da toccare. */
function ftSuggBase(bar, stato) {
    var cassetto = bar.querySelector('.fmt-panel-2[data-panel2="comandi"] .fmt-sugg');
    if (!cassetto) return;
    var v = ftVocabolario(ftLinguaScelta());
    cassetto.innerHTML = '';
    ftComandiAttivi(stato, false).forEach(function (ch) {
        var def = v.cmd[ch];
        if (!def) return;
        var b = document.createElement('button');
        b.type = 'button';
        b.tabIndex = -1;   // mai fuoco da solo: vedi il commento in ftSetupToolbar
        b.className = 'fmt-sugg-voce' + (FT_COMANDI_CHIUSURA.indexOf(ch) !== -1 ? ' fmt-sugg-chiudi' : '');
        b.dataset.cmdVoce = ch;
        b.textContent = def.eti;
        b.title = 'Toccalo per inserirlo';
        cassetto.appendChild(b);
    });
}

function ftSuggerisciFormula(wrap, bar) {
    var ed = ftEditor(wrap);
    var sel = window.getSelection();
    var dentro = false;
    /* Con MathQuill montato il cursore del documento non sta piu' dentro la
       formula (i tasti li riceve una casella nascosta), e da poco non ci sta
       piu' nemmeno per un istante: ftMQMonta lo toglie di mezzo di proposito.
       Cercarlo li' porterebbe a concludere che si e' fuori, e i suggerimenti
       "fine formula / frazione / radice" non comparirebbero proprio nel
       momento in cui servono. Un campo montato e' gia' una risposta. */
    if (ftMQCampoAttivo(ed)) dentro = true;
    if (!dentro && sel && sel.rangeCount && sel.anchorNode && ed.contains(sel.anchorNode)) {
        var n = sel.anchorNode, el = n.nodeType === 1 ? n : n.parentNode;
        dentro = !!(el && el.closest && el.closest('.fc-x, .fc-fx'));
    }
    if (!dentro) { fcChiudiSecondo(bar); return; }

    if (typeof wrap._ftStato === 'function') {
        var st = wrap._ftStato();
        st.formula = true;
        st.pila = ftPilaVera(wrap) || [];
    }
    if (typeof wrap._ftSugg === 'function') wrap._ftSugg();
    else ftSuggBase(bar, { lista: false, formula: true, pila: ftPilaVera(wrap) || [] });   // dove non c'e' il microfono
    fcApriSecondo(bar, 'comandi');
}

// ---------------------------------------------------------------
// Avvio
// ---------------------------------------------------------------
function ftInit(wrap) {
    if (wrap._ftInit) return;

    /* Un MODELLO non e' un editor. big_edit tiene una copia nascosta di questa
       barra e la clona per ogni carta, con dentro i segnaposto %%RAW_TEXT%%.
       Inizializzarla significava farle leggere quei segnaposto come se fossero
       testo dell'utente e riscriverne il contenuto: il modello si corrompeva,
       e ogni carta clonata da li' nasceva gia' sbagliata. Un modello si clona,
       non si accende. */
    if (wrap.closest('#editor-template-container, [data-ft-modello], template')) return;

    wrap._ftInit = true;
    var ed = ftEditor(wrap);
    if (!ed) return;

    /* Legge indifferentemente il formato nuovo e quello vecchio: una scheda
       scritta un anno fa si apre come sempre. */
    var doc = fcdParse(wrap.dataset.raw || '');
    ed.innerHTML = fcdToHTML(doc);
    ftSync(wrap, doc);

    ftSetupToolbar(wrap);
    ftSetupSwitch(wrap);
    ftSetupEditor(wrap);
    ftRefreshState(wrap);
    ftMathRefresh(wrap);
    /* e di nuovo appena MathJax e' pronto, che di solito e' dopo */
    ftQuandoMathJaxPronto(function () { ftMathRefresh(wrap); });
}

/* I simboli disegnati da MathJax non sono testo: sono contenuto generato
   dalla CSS, e il contenuto generato non viene MAI evidenziato dalla
   selezione del browser. Percio' selezionando una frase che contiene una
   formula si vedeva tutto evidenziato tranne lei, come se non fosse stata
   presa — mentre invece nel documento c'e' eccome, e infatti se si cancella
   sparisce anche la formula. L'evidenziazione va quindi dipinta a mano:
   si marca ogni formula che la selezione attraversa. */
function ftMarcaFormuleSelezionate(wrap) {
    var ed = ftEditor(wrap);
    if (!ed) return;
    var sel = window.getSelection();
    var attiva = sel && sel.rangeCount && !sel.isCollapsed ? sel.getRangeAt(0) : null;
    ed.querySelectorAll('.fc-x, .fc-fx').forEach(function (f) {
        var dentro = false;
        if (attiva) {
            try { dentro = attiva.intersectsNode(f); } catch (e) { dentro = false; }
        }
        f.classList.toggle('fc-sel', dentro);
    });
}

document.addEventListener('selectionchange', function () {
    var n = document.activeElement;
    if (n && n.classList && n.classList.contains('fc-editor')) {
        var w = n.closest('.fmt-editor-wrap');
        if (w) { ftRefreshState(w); ftMathRefresh(w); ftMarcaFormuleSelezionate(w); }
    }
});

/* Gli editor che nascono DOPO il caricamento — una carta aggiunta in
   big_edit, una riga nuova in inserimento_big — non passavano di qui: la barra
   c'era ma non era collegata a niente, e sembrava rotta solo sulle carte
   nuove. Invece di chiedere a ogni pagina di ricordarsi di inizializzarle
   (cosa che prima o poi si dimentica), le si guarda nascere. */
function ftInitTutti(radice) {
    (radice || document).querySelectorAll('.fmt-editor-wrap').forEach(ftInit);
}

document.addEventListener('DOMContentLoaded', function () {
    ftInitTutti(document);

    if (!window.MutationObserver) return;
    new MutationObserver(function (mutazioni) {
        mutazioni.forEach(function (m) {
            m.addedNodes.forEach(function (n) {
                if (n.nodeType !== 1) return;
                if (n.classList && n.classList.contains('fmt-editor-wrap')) ftInit(n);
                else if (n.querySelectorAll) ftInitTutti(n);
            });
        });
    }).observe(document.body, { childList: true, subtree: true });
});

/* Nome pubblico, per le pagine che preferiscono chiamarla a mano subito dopo
   aver inserito una carta invece di aspettare l'osservatore. */
window.ftInitTutti = ftInitTutti;

/* Nome storico: le pagine che chiamavano fcSyncAllEditors() prima di inviare
   un modulo continuano a funzionare. */
function fcSyncAllEditors() {
    document.querySelectorAll('.fmt-editor-wrap').forEach(function (w) { ftSync(w); });
}

</script>

<?php endif; ?>
