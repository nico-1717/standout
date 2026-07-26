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

$ft_editor_id = $ft_field . '_editor';



if (!defined('FC_PARSER_JS_INCLUDED')) {
    define('FC_PARSER_JS_INCLUDED', true);
    echo "<script>
        // Controllo intelligente: inietta lo script solo se non esiste già nel DOM
        if (!document.querySelector('script[src*=\"fc_parser.js\"]')) {
            var script = document.createElement('script');
            script.src = 'fc_parser.js';
            document.head.appendChild(script);
        }
    </script>\n";
}

?>

<div class="fmt-editor-wrap" data-editor-id="<?= htmlspecialchars($ft_editor_id) ?>" data-field="<?= htmlspecialchars($ft_field) ?>" data-raw="<?= htmlspecialchars($ft_raw, ENT_QUOTES) ?>">

    <div class="fmt-toolbar" data-target="<?= htmlspecialchars($ft_editor_id) ?>">

        <button type="button" class="fmt-btn" data-cmd="bold" title="Grassetto"><b>B</b></button>

        <button type="button" class="fmt-btn" data-cmd="italic" title="Corsivo"><i>I</i></button>

        <button type="button" class="fmt-btn" data-cmd="underline" title="Sottolineato"><u>U</u></button>

        <button type="button" class="fmt-btn" data-cmd="yellow" title="Evidenziatore">

            <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21l3.6-1 11-11-2.6-2.6-11 11z"/><path d="M14 5l5 5"/></svg>

        </button>



        <span class="fmt-sep"></span>

        <button type="button" class="fmt-btn fmt-dot" data-cmd="color" data-color="red" title="Rosso"><span class="fmt-color-dot" style="background:var(--red,#FF3B30)"></span></button>
        <button type="button" class="fmt-btn fmt-dot" data-cmd="color" data-color="blue" title="Blu"><span class="fmt-color-dot" style="background:var(--blue,#007AFF)"></span></button>
        <button type="button" class="fmt-btn fmt-dot" data-cmd="color" data-color="green" title="Verde"><span class="fmt-color-dot" style="background:var(--green,#34C759)"></span></button>
        <button type="button" class="fmt-btn fmt-dot" data-cmd="color" data-color="purple" title="Viola"><span class="fmt-color-dot" style="background:var(--purple,#AF52DE)"></span></button>
        <button type="button" class="fmt-btn fmt-dot" data-cmd="color" data-color="orange" title="Arancione"><span class="fmt-color-dot" style="background:var(--orange,#FF9500)"></span></button>



        <span class="fmt-sep"></span>

        <button type="button" class="fmt-btn fmt-wide" data-cmd="list" title="Elenco puntato">

            <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="4" cy="6" r="1.2" fill="currentColor" stroke="none"/><line x1="9" y1="6" x2="20" y2="6"/><circle cx="4" cy="12" r="1.2" fill="currentColor" stroke="none"/><line x1="9" y1="12" x2="20" y2="12"/><circle cx="4" cy="18" r="1.2" fill="currentColor" stroke="none"/><line x1="9" y1="18" x2="20" y2="18"/></svg>

            Elenco

        </button>

        <button type="button" class="fmt-btn" data-cmd="outdent" title="Riduci livello elenco">

            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="11 17 6 12 11 7"/><line x1="18" y1="12" x2="6" y2="12"/></svg>

        </button>

        <button type="button" class="fmt-btn" data-cmd="indent" title="Crea sotto-punto (livello successivo)">

            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="13 17 18 12 13 7"/><line x1="6" y1="12" x2="18" y2="12"/></svg>

        </button>

        <!-- NUOVO PULSANTE A CAPO MORBIDO -->
        <button type="button" class="fmt-btn" data-cmd="soft-break" title="A capo (senza nuovo punto)">
            <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 10 4 15 9 20"/><path d="M20 4v7a4 4 0 0 1-4 4H4"/></svg>
        </button>

        <div class="fmt-pop-wrap">

            <button type="button" class="fmt-btn" data-cmd="list-color" title="Colore della barra dell'elenco">

                <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="8" height="8" rx="2"/><rect x="13" y="3" width="8" height="8" rx="2"/><rect x="3" y="13" width="8" height="8" rx="2"/><rect x="13" y="13" width="8" height="8" rx="2"/></svg>

            </button>

            <div class="fmt-popover" hidden>

                <button type="button" class="fmt-pop-dot fmt-pop-dot-default" data-list-color="" title="Predefinito">

                    <svg viewBox="0 0 24 24" width="11" height="11" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>

                </button>

                <button type="button" class="fmt-pop-dot" data-list-color="red" style="background:var(--red,#FF3B30)" title="Rosso"></button>

                <button type="button" class="fmt-pop-dot" data-list-color="blue" style="background:var(--blue,#007AFF)" title="Blu"></button>

                <button type="button" class="fmt-pop-dot" data-list-color="green" style="background:var(--green,#34C759)" title="Verde"></button>

                <button type="button" class="fmt-pop-dot" data-list-color="purple" style="background:var(--purple,#AF52DE)" title="Viola"></button>

                <button type="button" class="fmt-pop-dot" data-list-color="orange" style="background:var(--orange,#FF9500)" title="Arancione"></button>

                <button type="button" class="fmt-pop-dot" data-list-color="gray" style="background:var(--text-2,#8A8A8E)" title="Grigio"></button>

            </div>

        </div>



        <span class="fmt-sep"></span>

        <button type="button" class="fmt-btn" data-cmd="math-inline" title="Formula in linea">

            <span class="fmt-ix">x</span>

        </button>

        <button type="button" class="fmt-btn fmt-wide fmt-block" data-cmd="math-display" title="Formula evidenziata, a capo">

            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 4H5a1 1 0 0 0-1 1v14a1 1 0 0 0 1 1h3"/><path d="M16 4h3a1 1 0 0 1 1 1v14a1 1 0 0 1-1 1h-3"/></svg>

            Formula

        </button>



        <span class="fmt-sep"></span>

        <button type="button" class="fmt-btn fmt-raw-toggle" data-cmd="raw-toggle" title="Vedi/modifica il testo grezzo">

            <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>

        </button>

    </div>



    <div class="fc-editor" id="<?= htmlspecialchars($ft_editor_id) ?>" contenteditable="true" data-placeholder="<?= htmlspecialchars($ft_placeholder) ?>"></div>

    <textarea class="fc-raw-editor" id="<?= htmlspecialchars($ft_editor_id) ?>_raw" spellcheck="false" style="display:none;" placeholder="/bold{...}  /list{- punto}  $formula$ ..."></textarea>

    <input type="hidden" name="<?= htmlspecialchars($ft_field) ?>" id="<?= htmlspecialchars($ft_field) ?>">

</div>



<?php if (!defined('FT_ASSETS_EMITTED')): ?>

<?php define('FT_ASSETS_EMITTED', true); ?>

<style>

/* ================================================================

   TOOLBAR DI FORMATTAZIONE + EDITOR WYSIWYG — componente condiviso.

   Le variabili --blue/--surface/ecc si aspettano di trovare quelle

   della pagina ospite (tutte le pagine dell'app le definiscono già);

   ogni valore ha comunque un fallback per restare robusto altrove.

   ================================================================ */

.fmt-editor-wrap{ position:relative; }

.fmt-toolbar{

    display:flex; gap:6px; flex-wrap:wrap; align-items:center;

    background:var(--surface-2,#EFEFF4); border-radius:14px; padding:8px;

    box-shadow:inset 0 0 0 .5px var(--hairline,rgba(60,60,67,.12));

}

.fmt-btn{

    background:var(--chip-bg,var(--surface,#fff)); border:none; border-radius:10px; cursor:pointer;

    min-width:36px; height:36px; padding:0 10px; font-size:14.5px; font-weight:800;

    color:var(--text,#1C1C1E); font-family:inherit; display:flex; align-items:center; justify-content:center; gap:5px;

    box-shadow:var(--pill-hl,inset 0 0 0 .5px rgba(60,60,67,.12)), var(--raise-sm,0 1px 1.5px rgba(0,0,0,.08));

    transition:transform .14s var(--spring,cubic-bezier(.32,.72,0,1)), background .15s, color .15s;

}

.fmt-btn:active{ transform:scale(.88); }

.fmt-btn svg{ display:block; }

.fmt-btn.fmt-wide{ font-size:12px; padding:0 12px; }

.fmt-dot .fmt-color-dot{ width:16px; height:16px; border-radius:50%; display:block; box-shadow:inset 0 0 0 1px rgba(0,0,0,.08); }

.fmt-ix{ font-style:italic; font-family:Georgia,"Times New Roman",serif; font-size:17px; color:var(--blue,#007AFF); }

.fmt-block{ background:var(--blue-soft,rgba(0,122,255,.09)); color:var(--blue,#007AFF); }

.fmt-sep{ width:1px; height:22px; background:var(--hairline-2,rgba(60,60,67,.22)); margin:0 2px; flex-shrink:0; }

.fmt-btn.is-active{ background:var(--blue,#007AFF); color:#fff; box-shadow:inset 0 1px 0 rgba(255,255,255,.25); }



/* popover colore lista */

.fmt-pop-wrap{ position:relative; }

.fmt-popover{

    position:absolute; top:calc(100% + 8px); left:0; z-index:80;

    display:flex; gap:7px; padding:9px; background:var(--surface,#fff); border-radius:13px;

    box-shadow:0 10px 30px rgba(0,0,0,.22), inset 0 0 0 .5px var(--hairline,rgba(60,60,67,.12));

    animation:fmtPopIn .18s var(--spring,cubic-bezier(.32,.72,0,1)) both;

}

.fmt-popover[hidden] {

    display: none !important;

}

@keyframes fmtPopIn{ from{ opacity:0; transform:translateY(-6px) scale(.92); } }

.fmt-pop-dot{ width:24px; height:24px; border-radius:50%; border:none; cursor:pointer; padding:0; flex-shrink:0; transition:transform .14s var(--spring,cubic-bezier(.32,.72,0,1)); }

.fmt-pop-dot:active{ transform:scale(.85); }

.fmt-pop-dot-default{

    background:var(--surface-2,#EFEFF4); color:var(--text-2,#8A8A8E);

    box-shadow:inset 0 0 0 1.5px var(--hairline-2,rgba(60,60,67,.22));

    display:flex; align-items:center; justify-content:center;

}



/* editor WYSIWYG */

.fc-editor{

    min-height:110px; padding:14px 15px; background:var(--surface,#fff);

    border:.5px solid var(--hairline-2,rgba(60,60,67,.22)); border-top:none;

    border-radius:0 0 var(--r-field,13px) var(--r-field,13px);

    font-size:16px; line-height:1.65; color:var(--text,#1C1C1E); outline:none;

    word-wrap:break-word; overflow-wrap:break-word; white-space:pre-wrap;

}

.fc-editor:empty:before{ content:attr(data-placeholder); color:var(--text-3,#AEAEB2); }

.fc-editor:focus{ box-shadow:inset 0 0 0 1.5px var(--blue,#007AFF); }



.fc-raw-editor{

    display:block; width:100%; min-height:110px; padding:14px 15px; box-sizing:border-box;

    background:var(--surface-3,var(--surface-2,#F7F7FA)); color:var(--text,#1C1C1E);

    border:.5px solid var(--hairline-2,rgba(60,60,67,.22)); border-top:none;

    border-radius:0 0 var(--r-field,13px) var(--r-field,13px);

    font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:14px; line-height:1.6;

    outline:none; resize:vertical;

}

.fc-raw-editor:focus{ box-shadow:inset 0 0 0 1.5px var(--blue,#007AFF); }



.fc-math{

    display:inline-block; cursor:pointer; padding:1px 6px; margin:0 1px; border-radius:7px;

    background:var(--blue-soft,rgba(0,122,255,.09)); box-shadow:inset 0 0 0 1px rgba(0,122,255,.28);

    vertical-align:middle; transition:transform .12s var(--spring,cubic-bezier(.32,.72,0,1));

}

.fc-math[data-display="true"]{ display:block; width:max-content; max-width:100%; margin:8px 0; padding:6px 10px; }

.fc-math:active{ transform:scale(.95); }

.fc-math mjx-container{ margin:0 !important; }

.fc-math-raw{

    font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:14.5px;

    background:var(--surface-2,#EFEFF4); color:var(--blue,#007AFF); padding:1px 4px; border-radius:5px;

    box-shadow:inset 0 0 0 1px var(--hairline-2,rgba(60,60,67,.22));

}

.fc-math-raw[data-display="true"]{ display:block; width:max-content; max-width:100%; margin:6px 0; padding:5px 9px; }



/* in modalità raw, i comandi che agiscono sulla struttura DOM (non sul testo) si disattivano */

.fmt-editor-wrap.raw-mode .fmt-btn[data-cmd="indent"],

.fmt-editor-wrap.raw-mode .fmt-btn[data-cmd="outdent"],

.fmt-editor-wrap.raw-mode .fmt-btn[data-cmd="list-color"]{ opacity:.35; pointer-events:none; }

</style>



<script>

// ============================================================

// UTILITY DI SELEZIONE / CURSORE

// ============================================================

function fcUnwrapElement(el) {

    var parent = el.parentNode;

    while (el.firstChild) parent.insertBefore(el.firstChild, el);

    parent.removeChild(el);

}

function fcPlaceCaretInside(el, pos) {

    if (el.childNodes.length === 0) el.appendChild(document.createTextNode('\u200B'));

    var sel = window.getSelection();

    var r = document.createRange();

    r.selectNodeContents(el);

    r.collapse(pos === 0);

    sel.removeAllRanges();

    sel.addRange(r);

}

function fcPlaceCaretAfter(node) {

    var sel = window.getSelection();

    var r = document.createRange();

    r.setStartAfter(node);

    r.collapse(true);

    sel.removeAllRanges();

    sel.addRange(r);

}

function fcPlaceCaretAtEnd(el) {

    var sel = window.getSelection();

    var r = document.createRange();

    r.selectNodeContents(el);

    r.collapse(false);

    sel.removeAllRanges();

    sel.addRange(r);

}



// ============================================================
// GRASSETTO / CORSIVO / SOTTOLINEATO / EVIDENZIATORE / COLORI
// ============================================================
function fcApplyCustomFormat(editor, cmd, colorValue) {
    var sel = window.getSelection();
    if (!sel.rangeCount) return;
    var range = sel.getRangeAt(0);
    if (!editor.contains(range.commonAncestorContainer)) return;

    // Helper per creare il nuovo tag desiderato
    var makeWrapper = function() {
        var el;
        if (cmd === 'color') { el = document.createElement('span'); el.className = 'fc-c'; el.dataset.c = colorValue; }
        else if (cmd === 'bold') el = document.createElement('strong');
        else if (cmd === 'italic') el = document.createElement('em');
        else if (cmd === 'underline') el = document.createElement('u');
        else if (cmd === 'yellow') { el = document.createElement('mark'); el.className = 'fc-hl'; }
        return el;
    };

    // 1. CASO: Nessun testo selezionato (il cursore lampeggia). Permette di iniziare a digitare col formato.
    if (range.collapsed) {
        var wrapper = makeWrapper();
        wrapper.appendChild(document.createTextNode('\u200B'));
        range.insertNode(wrapper);
        var r2 = document.createRange();
        r2.setStart(wrapper.firstChild, 1);
        r2.collapse(true);
        sel.removeAllRanges(); sel.addRange(r2);
        editor.focus();
        editor.dispatchEvent(new Event('input', { bubbles: true }));
        return;
    }

    // Identifica il selettore CSS relativo allo stile che stiamo applicando
    var ancSelector = '';
    if (cmd === 'color') ancSelector = 'span.fc-c';
    else if (cmd === 'bold') ancSelector = 'strong,b';
    else if (cmd === 'italic') ancSelector = 'em,i';
    else if (cmd === 'underline') ancSelector = 'u';
    else if (cmd === 'yellow') ancSelector = 'mark.fc-hl';

    // 2. CASO TOGGLE: Se hai selezionato esattamente una parola già formattata per togliergli l'effetto
    var container = range.commonAncestorContainer;
    if (container.nodeType === 3) container = container.parentNode;
    var anc = container.closest ? container.closest(ancSelector) : null;
    
    if (anc && editor.contains(anc) && anc.textContent === range.toString()) {
        if (cmd === 'color') {
            if (anc.dataset.c === colorValue) fcUnwrapElement(anc); // Clicchi rosso su rosso: si toglie
            else anc.dataset.c = colorValue; // Clicchi verde su rosso: si scambia
        } else {
            fcUnwrapElement(anc); // Togli grassetto/ecc
        }
        editor.focus();
        editor.dispatchEvent(new Event('input', { bubbles: true }));
        return;
    }

    // 3. CASO MAGIA (Estrai, Pulisci, Avvolgi): previene matrioske e sovrapposizioni su selezioni ampie
    var fragment = range.extractContents();

    function unwrapNodes(nodeList) {
        nodeList.forEach(function(el) {
            var parent = el.parentNode;
            while (el.firstChild) parent.insertBefore(el.firstChild, el);
            parent.removeChild(el);
        });
    }

    // Distruggiamo tutti i tag interni dello stesso tipo (es. rimuove vecchi colori o grassetti annidati)
    unwrapNodes(fragment.querySelectorAll(ancSelector));

    // Avvolge il frammento pulito nel nuovo tag definitivo
    var finalWrap = makeWrapper();
    finalWrap.appendChild(fragment);
    range.insertNode(finalWrap);

    // Ripristina la selezione per mostrare all'utente l'azione completata
    var r3 = document.createRange();
    r3.selectNodeContents(finalWrap);
    sel.removeAllRanges(); sel.addRange(r3);
    
    editor.focus();
    editor.dispatchEvent(new Event('input', { bubbles: true }));
}



// ============================================================

// ELENCHI PUNTATI (profondità libera) + colore della barra

// ============================================================

function fcGetClosestLi(editor, node) {

    var n = node;

    while (n && n !== editor) {

        if (n.nodeType === 1 && n.classList && n.classList.contains('fc-li')) return n;

        n = n.parentNode;

    }

    return null;

}

function fcGetClosestList(editor, node) {

    var li = fcGetClosestLi(editor, node);

    if (li) return li.parentNode;

    var n = node;

    while (n && n !== editor) {

        if (n.nodeType === 1 && n.classList && n.classList.contains('fc-list')) return n;

        n = n.parentNode;

    }

    return null;

}

function fcSetListColorAtSelection(editor, color) {

    var sel = window.getSelection();

    if (!sel.rangeCount) return false;

    var range = sel.getRangeAt(0);

    if (!editor.contains(range.commonAncestorContainer)) return false;

    var list = fcGetClosestList(editor, range.startContainer);

    if (!list) return false;

    fcApplyListColor(list, color || null);

    editor.dispatchEvent(new Event('input', { bubbles: true }));

    return true;

}

function fcIsLiEmpty(li) {

    return li.textContent.replace(/\u200B/g, '').trim() === '';

}

function fcExitList(li, editor) {

    var wrap = li.parentNode;

    var nextRow = li.nextElementSibling;

    wrap.removeChild(li);



    if (wrap.children.length === 0) {

        var br = document.createElement('br');

        var parent = wrap.parentNode;

        parent.insertBefore(br, wrap.nextSibling);

        parent.removeChild(wrap);

        fcPlaceCaretAfter(br);

    } else if (!nextRow) {

        var br2 = document.createElement('br');

        wrap.parentNode.insertBefore(br2, wrap.nextSibling);

        fcPlaceCaretAfter(br2);

    } else {

        fcPlaceCaretInside(nextRow, 0);

    }

    editor.dispatchEvent(new Event('input', { bubbles: true }));

}

function fcSplitLi(li, range) {

    var r = document.createRange();

    r.setStart(range.startContainer, range.startOffset);

    r.setEnd(li, li.childNodes.length);

    var frag = r.extractContents();

    var lvl = li.dataset.level || '1';

    var newLi = fcMakeLiRow(parseInt(lvl, 10) || 1);

    newLi.appendChild(frag);

    if (fcIsLiEmpty(newLi)) { newLi.textContent = ''; newLi.appendChild(document.createTextNode('\u200B')); }

    li.parentNode.insertBefore(newLi, li.nextSibling);

    fcPlaceCaretInside(newLi, 0);

}

function fcIndentLi(li) {

    var prev = li.previousElementSibling;

    if (!prev || !prev.classList.contains('fc-li')) return;

    var curLevel = parseInt(li.dataset.level, 10) || 1;

    var prevLevel = parseInt(prev.dataset.level, 10) || 1;

    var newLevel = Math.min(curLevel + 1, prevLevel + 1);

    li.dataset.level = String(newLevel);

    li.style.setProperty('--lvl', newLevel);

    fcPlaceCaretInside(li, 1);

}

function fcOutdentLi(li) {

    var curLevel = parseInt(li.dataset.level, 10) || 1;

    if (curLevel <= 1) return;

    li.dataset.level = String(curLevel - 1);

    li.style.setProperty('--lvl', curLevel - 1);

    fcPlaceCaretInside(li, 1);

}

function fcSplitFragmentByBr(frag) {

    var lines = [[]];

    Array.prototype.slice.call(frag.childNodes).forEach(function (n) {

        if (n.nodeType === 1 && n.tagName === 'BR') lines.push([]);

        else lines[lines.length - 1].push(n);

    });

    return lines;

}

function fcConvertSelectionToList(editor, range) {

    var frag = range.extractContents();

    var lines = fcSplitFragmentByBr(frag);

    var wrap = document.createElement('div');

    wrap.className = 'fc-list';

    lines.forEach(function (lineNodes) {

        var row = fcMakeLiRow(1);

        lineNodes.forEach(function (n) { row.appendChild(n); });

        if (!row.childNodes.length) row.appendChild(document.createTextNode('\u200B'));

        wrap.appendChild(row);

    });

    range.insertNode(wrap);

    var lastRow = wrap.lastElementChild;

    if (lastRow) fcPlaceCaretInside(lastRow, 1);

}

function fcToggleList(editor) {

    editor.focus();

    var sel = window.getSelection();

    var range = (sel.rangeCount && editor.contains(sel.getRangeAt(0).commonAncestorContainer)) ? sel.getRangeAt(0) : null;

    if (!range) { fcPlaceCaretAtEnd(editor); range = window.getSelection().getRangeAt(0); }

    var li = fcGetClosestLi(editor, range.startContainer);

    if (li) { fcExitList(li, editor); return; }

    if (range.collapsed) {

        var wrap = document.createElement('div');

        wrap.className = 'fc-list';

        var row = fcMakeLiRow(1);

        row.appendChild(document.createTextNode('\u200B'));

        wrap.appendChild(row);

        range.insertNode(wrap);

        fcPlaceCaretInside(row, 0);

    } else {

        fcConvertSelectionToList(editor, range);

    }

    editor.dispatchEvent(new Event('input', { bubbles: true }));

}



// ============================================================

// FORMULE LATEX: inserimento, conversione raw <-> resa, riedit

// ============================================================

function fcInsertFormula(editorId, display) {

    var editor = document.getElementById(editorId);

    editor.focus();

    var sel = window.getSelection();

    var range;

    if (sel.rangeCount && editor.contains(sel.getRangeAt(0).commonAncestorContainer)) {

        range = sel.getRangeAt(0);

    } else {

        range = document.createRange();

        range.selectNodeContents(editor);

        range.collapse(false);

    }

    var tex = range.collapsed ? '' : range.toString();

    var raw = display ? ('$$' + tex + '$$') : ('$' + tex + '$');

    range.deleteContents();



    var span = document.createElement('span');

    span.className = 'fc-math-raw';

    if (display) span.dataset.display = 'true';

    span.textContent = raw;



    if (display) {
        var divDopo = document.createElement('div');
        divDopo.appendChild(document.createElement('br'));

        range.insertNode(divDopo);
        range.insertNode(span);

        var r2 = document.createRange();
        if (tex) { r2.setStart(divDopo, 0); r2.collapse(true); }
        else { r2.setStart(span.firstChild, 2); r2.collapse(true); }
        sel.removeAllRanges(); sel.addRange(r2);
    } else {

        range.insertNode(span);

        var r2b = document.createRange();

        if (tex) { r2b.selectNodeContents(span); r2b.collapse(false); }

        else { r2b.setStart(span.firstChild, 1); r2b.collapse(true); }

        sel.removeAllRanges(); sel.addRange(r2b);

    }



    editor.focus();

    editor.dispatchEvent(new Event('input', { bubbles: true }));

}

function fcConvertRenderedToRaw(chip) {

    var tex = chip.dataset.tex || '';

    var display = chip.dataset.display === 'true';

    var raw = display ? ('$$' + tex + '$$') : ('$' + tex + '$');

    var span = document.createElement('span');

    span.className = 'fc-math-raw';

    if (display) span.dataset.display = 'true';

    span.textContent = raw;



    chip.parentNode.replaceChild(span, chip);



    if (display && (!span.nextSibling || span.nextSibling.nodeName !== 'DIV')) {

        var divDopo = document.createElement('div');

        divDopo.appendChild(document.createElement('br'));

        span.parentNode.insertBefore(divDopo, span.nextSibling);

    }



    fcPlaceCaretInside(span, display ? 2 : 1);

    return span;

}

function fcSplitAndConvert(textNode, match, display) {

    var full = match[0], tex = match[1], idx = match.index;

    var before = textNode.nodeValue.slice(0, idx);

    var after = textNode.nodeValue.slice(idx + full.length);



    var chip = document.createElement('span');

    chip.className = 'fc-math';

    chip.contentEditable = 'false';

    chip.dataset.tex = tex;

    chip.dataset.display = display ? 'true' : 'false';

    chip.textContent = full;



    var insertParent = textNode.parentNode;

    var refNode = textNode;

    if (insertParent.classList && insertParent.classList.contains('fc-math-raw')) {

        refNode = insertParent;

        insertParent = insertParent.parentNode;

    }



    var beforeNode = before ? document.createTextNode(before) : null;

    var afterNode = after ? document.createTextNode(after) : null;

    if (beforeNode) insertParent.insertBefore(beforeNode, refNode);

    insertParent.insertBefore(chip, refNode);

    if (afterNode) insertParent.insertBefore(afterNode, refNode);

    insertParent.removeChild(refNode);



    fcTypesetMath([chip]);

}

function fcScanAndConvertFormulas(editor) {

    if (!editor) return;

    var sel = window.getSelection();

    var caretNode = (sel && sel.rangeCount) ? sel.getRangeAt(0).startContainer : null;



    var textNodes = [];

    var walker = document.createTreeWalker(editor, NodeFilter.SHOW_TEXT, {

        acceptNode: function (n) {

            var p = n.parentElement;

            while (p && p !== editor) {

                if (p.classList && p.classList.contains('fc-math')) return NodeFilter.FILTER_REJECT;

                p = p.parentElement;

            }

            return NodeFilter.FILTER_ACCEPT;

        }

    });

    var node;

    while ((node = walker.nextNode())) textNodes.push(node);



    var displayRe = /\$\$([^\$]+?)\$\$/;

    var inlineRe = /\$([^\$]+?)\$/;



    textNodes.forEach(function (tn) {

        if (!tn.parentNode) return;

        if (tn === caretNode) return;

        var parentIsRaw = tn.parentElement && tn.parentElement.classList && tn.parentElement.classList.contains('fc-math-raw');

        if (parentIsRaw && caretNode && tn.parentElement.contains(caretNode)) return;



        var str = tn.nodeValue;

        var m = displayRe.exec(str);

        if (m) { fcSplitAndConvert(tn, m, true); return; }

        m = inlineRe.exec(str);

        if (m) { fcSplitAndConvert(tn, m, false); return; }

    });

}



// ============================================================

// SINCRONIZZAZIONE / IDRATAZIONE (per-wrap, non più per campo fisso)

// ============================================================

function fcSyncHiddenFor(wrap) {

    var hidden = document.getElementById(wrap.dataset.field);

    if (!hidden) return;

    if (wrap.classList.contains('raw-mode')) {

        var rawTa = wrap.querySelector('.fc-raw-editor');

        hidden.value = rawTa ? rawTa.value : hidden.value;

        return;

    }

    var editor = wrap.querySelector('.fc-editor');

    if (!editor) return;

    hidden.value = fcAstToTagText(fcDomToAst(editor)).replace(/\u200B/g, '');

}

function fcHydrateWrap(wrap, rawText) {

    var editor = wrap.querySelector('.fc-editor');

    if (!editor) return;

    editor.innerHTML = '';

    if (rawText) fcAstToDomChildren(fcParse(rawText), editor);

    fcSyncHiddenFor(wrap);

    fcTypesetMath([editor]);

}

// Da chiamare nel submit del form ospite: converte eventuali formule rimaste "in sospeso"

// e aggiorna tutti i campi hidden in un colpo solo.

// Esempio di come dovrebbe essere fcSyncAllEditors nel tuo format_toolbar.php
// Da chiamare nel submit del form ospite per aggiornare tutti i campi hidden in un colpo solo.
function fcSyncAllEditors() {
    document.querySelectorAll('.fmt-editor-wrap').forEach(function(wrap) {
        var editor = wrap.querySelector('.fc-editor');
        if (editor) {
            // Assicurati che le formule siano scompattate prima del salvataggio
            if (typeof fcScanAndConvertFormulas === 'function') {
                fcScanAndConvertFormulas(editor);
            }
            fcSyncHiddenFor(wrap);
        }
    });
}



// ============================================================

// MODALITA' RAW: alterna tra editor formattato e testo grezzo modificabile

// ============================================================

function fcToggleRawMode(wrap) {
    var editor = wrap.querySelector('.fc-editor');
    var rawTa = wrap.querySelector('.fc-raw-editor');
    var btn = wrap.querySelector('.fmt-raw-toggle');
    var isRaw = wrap.classList.contains('raw-mode');

    if (!isRaw) {
        // Forza la lettura e protezione di eventuali formule rimaste scritte in sospeso
        if (typeof fcScanAndConvertFormulas === 'function') fcScanAndConvertFormulas(editor);
        
        rawTa.value = fcAstToTagText(fcDomToAst(editor)).replace(/\u200B/g, '');

        wrap.classList.add('raw-mode');

        if (btn) btn.classList.add('is-active');

        editor.style.display = 'none';

        rawTa.style.display = 'block';

        rawTa.focus();

    } else {

        editor.innerHTML = '';

        fcAstToDomChildren(fcParse(rawTa.value), editor);

        fcTypesetMath([editor]);

        wrap.classList.remove('raw-mode');

        if (btn) btn.classList.remove('is-active');

        rawTa.style.display = 'none';

        editor.style.display = 'block';

    }

    fcSyncHiddenFor(wrap);

}



// Riusa la logica storica "avvolgi/rimuovi tag nel testo selezionato", utile quando

// si preferisce scrivere/curare la sintassi a mano nella modalità raw.

function fcRawInsertTag(textarea, tag) {

    var start = textarea.selectionStart, end = textarea.selectionEnd, val = textarea.value;

    var openTag = '/' + tag + '{', closeTag = '}';



    if (start !== end) {

        var before = val.substring(0, start), after = val.substring(end), selected = val.substring(start, end);

        if (before.endsWith(openTag) && after.startsWith(closeTag)) {

            textarea.value = before.slice(0, -openTag.length) + selected + after.slice(closeTag.length);

            textarea.selectionStart = start - openTag.length; textarea.selectionEnd = end - openTag.length;

        } else if (selected.startsWith(openTag) && selected.endsWith(closeTag)) {

            var inner = selected.substring(openTag.length, selected.length - closeTag.length);

            textarea.value = before + inner + after;

            textarea.selectionStart = start; textarea.selectionEnd = start + inner.length;

        } else {

            textarea.value = before + openTag + selected + closeTag + after;

            textarea.selectionStart = start + openTag.length; textarea.selectionEnd = start + openTag.length + selected.length;

        }

    } else {

        var emptyTag = openTag + closeTag;

        textarea.value = val.substring(0, start) + emptyTag + val.substring(start);

        var caret = start + openTag.length;

        textarea.selectionStart = caret; textarea.selectionEnd = caret;

    }

    textarea.focus();

    textarea.dispatchEvent(new Event('input', { bubbles: true }));

}

function fcRawInsertList(textarea) {

    var start = textarea.selectionStart, end = textarea.selectionEnd, val = textarea.value;

    var selected = val.substring(start, end);

    var listText = selected ? selected.replace(/\n/g, '\n- ') : '';

    if (listText && !listText.startsWith('- ')) listText = '- ' + listText;

    if (!listText) listText = '- ';

    var block = '\n/list{\n' + listText + '\n}\n';

    textarea.value = val.substring(0, start) + block + val.substring(end);

    var caret = start + block.length - 2;

    textarea.selectionStart = caret; textarea.selectionEnd = caret;

    textarea.focus();

    textarea.dispatchEvent(new Event('input', { bubbles: true }));

}

function fcRawInsertMath(textarea, display) {

    var start = textarea.selectionStart, end = textarea.selectionEnd, val = textarea.value;

    var selected = val.substring(start, end);

    var delim = display ? '$$' : '$';

    textarea.value = val.substring(0, start) + delim + selected + delim + val.substring(end);

    var caret = start + delim.length + selected.length + delim.length;

    textarea.selectionStart = caret; textarea.selectionEnd = caret;

    textarea.focus();

    textarea.dispatchEvent(new Event('input', { bubbles: true }));

}



// ============================================================

// INSERIMENTO TESTO INCOLLATO / GESTIONE INVIO

// ============================================================

function fcInsertPlainText(editor, text) {

    var sel = window.getSelection();

    if (!sel.rangeCount) return;

    var range = sel.getRangeAt(0);

    range.deleteContents();

    var lines = text.split(/\r\n|\r|\n/);

    var frag = document.createDocumentFragment();

    var lastNode = null;

    lines.forEach(function (line, idx) {

        if (line) { lastNode = document.createTextNode(line); frag.appendChild(lastNode); }

        if (idx < lines.length - 1) { lastNode = document.createElement('br'); frag.appendChild(lastNode); }

    });

    range.insertNode(frag);

    if (lastNode) fcPlaceCaretAfter(lastNode);

    editor.dispatchEvent(new Event('input', { bubbles: true }));

    fcScanAndConvertFormulas(editor);

}

function fcInsertSoftBreak() {
    var sel = window.getSelection();
    if (!sel.rangeCount) return;
    var range = sel.getRangeAt(0);
    range.deleteContents();
    
    var br = document.createElement('br');
    range.insertNode(br);
    
    // Fix per il cursore pigro di Safari/Chrome: 
    // Inseriamo uno spazio a larghezza zero e spostiamo il cursore subito dopo.
    var zw = document.createTextNode('\u200B');
    br.parentNode.insertBefore(zw, br.nextSibling);
    
    var r = document.createRange();
    r.setStart(zw, 1);
    r.collapse(true);
    sel.removeAllRanges();
    sel.addRange(r);
}

function fcHandleEnter(editor) {
    var sel = window.getSelection();
    if (!sel.rangeCount) return;
    var range = sel.getRangeAt(0);
    var li = fcGetClosestLi(editor, range.startContainer);
    if (li) {
        if (fcIsLiEmpty(li)) { fcExitList(li, editor); return; }
        fcSplitLi(li, range);
        editor.dispatchEvent(new Event('input', { bubbles: true }));
        return;
    }
    
    // Usa il nuovo sistema che forza il cursore a scendere
    fcInsertSoftBreak();
    editor.dispatchEvent(new Event('input', { bubbles: true }));
}



// ============================================================

// TOOLBAR ED EDITOR: wiring eventi

// ============================================================

function fcSetupToolbar(toolbar) {

    var wrap = toolbar.closest('.fmt-editor-wrap');

    var editor = document.getElementById(toolbar.dataset.target);

    if (!editor || !wrap) return;



    toolbar.addEventListener('mousedown', function (e) {

        if (e.target.closest('.fmt-btn')) e.preventDefault();

    });



    toolbar.addEventListener('click', function (e) {

        var popDot = e.target.closest('.fmt-pop-dot');

        if (popDot) {

            fcSetListColorAtSelection(editor, popDot.dataset.listColor || null);

            toolbar.querySelectorAll('.fmt-popover').forEach(function (p) { p.hidden = true; });

            fcSyncHiddenFor(wrap);

            return;

        }



        var btn = e.target.closest('.fmt-btn');

        if (!btn) return;

        var cmd = btn.dataset.cmd;



        if (cmd === 'list-color') {

            var pop = btn.parentNode.querySelector('.fmt-popover');

            var willOpen = pop && pop.hidden;

            document.querySelectorAll('.fmt-popover').forEach(function (p) { p.hidden = true; });

            if (pop) pop.hidden = !willOpen;

            return;

        }

        if (cmd === 'raw-toggle') { fcToggleRawMode(wrap); return; }



        if (wrap.classList.contains('raw-mode')) {

            var rawTa = wrap.querySelector('.fc-raw-editor');

            if (cmd === 'bold' || cmd === 'italic' || cmd === 'underline' || cmd === 'yellow') fcRawInsertTag(rawTa, cmd);

            else if (cmd === 'color') fcRawInsertTag(rawTa, btn.dataset.color);

            else if (cmd === 'list') fcRawInsertList(rawTa);

            else if (cmd === 'math-inline') fcRawInsertMath(rawTa, false);

            else if (cmd === 'math-display') fcRawInsertMath(rawTa, true);

            fcSyncHiddenFor(wrap);

            return;

        }



        editor.focus();
        
        // --- NUOVA LOGICA ---
        if (['bold', 'italic', 'underline', 'yellow'].includes(cmd)) {
            fcApplyCustomFormat(editor, cmd);
        }
        else if (cmd === 'color') {
            fcApplyCustomFormat(editor, cmd, btn.dataset.color);
        }
        // --------------------
        
        else if (cmd === 'list') fcToggleList(editor);

        else if (cmd === 'indent') {

            var sel1 = window.getSelection();

            if (sel1.rangeCount) { var li1 = fcGetClosestLi(editor, sel1.getRangeAt(0).startContainer); if (li1) { fcIndentLi(li1); editor.dispatchEvent(new Event('input', { bubbles: true })); } }

        }

        else if (cmd === 'outdent') {
            var sel2 = window.getSelection();
            if (sel2.rangeCount) { var li2 = fcGetClosestLi(editor, sel2.getRangeAt(0).startContainer); if (li2) { fcOutdentLi(li2); editor.dispatchEvent(new Event('input', { bubbles: true })); } }
        }
        else if (cmd === 'soft-break') {
            fcInsertSoftBreak();
            editor.dispatchEvent(new Event('input', { bubbles: true }));
        }
        else if (cmd === 'math-inline') fcInsertFormula(editor.id, false);

        else if (cmd === 'math-display') fcInsertFormula(editor.id, true);

        fcSyncHiddenFor(wrap);

    });

}



function fcSetupEditor(editor) {
    var wrap = editor.closest('.fmt-editor-wrap');
    editor.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            if (e.shiftKey) {
                fcInsertSoftBreak();
                editor.dispatchEvent(new Event('input', { bubbles: true }));
            } else {
                fcHandleEnter(editor);
            }
        } else if (e.key === 'Tab') {
            var sel = window.getSelection();
            if (!sel.rangeCount) return;
            var li = fcGetClosestLi(editor, sel.getRangeAt(0).startContainer);
            if (li) {
                e.preventDefault();
                if (e.shiftKey) fcOutdentLi(li); else fcIndentLi(li);
                editor.dispatchEvent(new Event('input', { bubbles: true }));
            }
        }
    });
    
    editor.addEventListener('paste', function (e) {
        e.preventDefault();
        var text = (e.clipboardData || window.clipboardData).getData('text/plain');
        fcInsertPlainText(editor, text);
    });
    
    editor.addEventListener('input', function () {
        if (editor.innerHTML === '<br>') editor.innerHTML = '';
        clearTimeout(editor._fcSyncTimer);
        editor._fcSyncTimer = setTimeout(function () { if (wrap) fcSyncHiddenFor(wrap); }, 250);
    });
    
    editor.addEventListener('blur', function () {
        fcScanAndConvertFormulas(editor);
        if (wrap) fcSyncHiddenFor(wrap);
    });
}

function fcSetupRawEditor(rawTa) {

    var wrap = rawTa.closest('.fmt-editor-wrap');

    rawTa.addEventListener('input', function () {

        clearTimeout(rawTa._fcSyncTimer);

        rawTa._fcSyncTimer = setTimeout(function () { if (wrap) fcSyncHiddenFor(wrap); }, 250);

    });

}



// Tocco su una formula già resa (chip) -> torna al testo grezzo per poterla rieditare.

document.addEventListener('click', function (e) {

    var chip = e.target.closest && e.target.closest('.fc-math');

    if (!chip) return;

    var ed = chip.closest('.fc-editor');

    if (!ed) return;

    e.preventDefault();

    fcConvertRenderedToRaw(chip);

    ed.focus();

    ed.dispatchEvent(new Event('input', { bubbles: true }));

});

// Chiude i popover (es. colore lista) se si tocca altrove.

document.addEventListener('click', function (e) {

    if (e.target.closest('.fmt-pop-wrap')) return;

    document.querySelectorAll('.fmt-popover').forEach(function (p) { p.hidden = true; });

});



// ============================================================

// INIZIALIZZAZIONE (generica: funziona per N editor nella stessa pagina)

// ============================================================

function fcInitEditorWrap(wrap) {
    if (wrap._fcInit) return;
    wrap._fcInit = true;
    var editor = document.getElementById(wrap.dataset.editorId);
    var toolbar = wrap.querySelector('.fmt-toolbar');
    var rawTa = wrap.querySelector('.fc-raw-editor');
    
    if (!editor || !toolbar) return;
    fcSetupToolbar(toolbar);
    fcSetupEditor(editor);
    if (rawTa) fcSetupRawEditor(rawTa);
    
    // Popola l'editor con il testo grezzo
    fcHydrateWrap(wrap, wrap.dataset.raw || '');

    // AGGIUNGI QUESTO: Forza MathJax a processare il nuovo contenuto
    // Facciamo scansionare le formule al nostro editor, in modo che le protegga
    // dentro al suo tag <span class="fc-math"> PRIMA che MathJax le "mangi"
    if (typeof fcScanAndConvertFormulas === 'function') {
        fcScanAndConvertFormulas(editor);
    }
}



// Tracking globale dell'editor attivo (come in DESIDERATO)

var fcLastActiveEditor = null;

document.addEventListener('focusin', function (e) {

    var ed = e.target.closest && e.target.closest('.fc-editor');

    if (ed) fcLastActiveEditor = ed;

});

document.addEventListener('selectionchange', function () {

    if (fcLastActiveEditor && document.activeElement === fcLastActiveEditor) {

        fcScanAndConvertFormulas(fcLastActiveEditor);

    }

});



document.addEventListener('DOMContentLoaded', function () {

    document.querySelectorAll('.fmt-editor-wrap').forEach(fcInitEditorWrap);

});

</script>

<?php endif; ?>
