// fc_parser.js
// ================================================================
// Motore condiviso di formattazione per le flashcard.
// Un'unica fonte di verità per: testo-con-tag <-> AST <-> DOM (editor) <-> HTML (anteprima).
// Usato sia dall'editor WYSIWYG (format_toolbar.php) sia da qualsiasi pagina di sola
// visualizzazione (es. visualizzazione_fc.php) — così colori, elenchi e formule
// vengono sempre interpretati e mostrati nello stesso identico modo, ovunque.
//
// Sintassi supportata nel testo grezzo salvato a DB:
//   /bold{...}  /italic{...}  /underline{...}  /yellow{...}
//   /red{...} /blue{...} /green{...} /purple{...} /orange{...} /gray{...}
//   /list{- punto\n-- sotto-punto\n--- sotto-sotto-punto...}   (profondità libera)
//   /list-<colore>{...}   (stessa cosa, ma con la barra colorata: /list-red{...})
//   $formula$   $$formula in evidenza$$
// ================================================================

function escapeHTML(text) {
    return String(text == null ? '' : text)
        .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
}

// Tavolozza UNICA: stessi valori del design system usato in tutta l'app (iOS-style).
// "var(--nome, #fallback)": sulle pagine che già definiscono --red/--blue/ecc (tutte, oggi)
// arriva gratis anche l'adattamento dark-mode; altrove resta comunque leggibile.
var FC_PALETTE = {
    red:    'var(--red, #FF3B30)',
    blue:   'var(--blue, #007AFF)',
    green:  'var(--green, #34C759)',
    purple: 'var(--purple, #AF52DE)',
    orange: 'var(--orange, #FF9500)',
    gray:   'var(--text-2, #8A8A8E)'
};
var FC_COLORS = ['red', 'blue', 'green', 'purple', 'orange'];       // colori testo esposti nella toolbar
var FC_LIST_COLORS = ['red', 'blue', 'green', 'purple', 'orange', 'gray']; // colori disponibili per la barra della lista
var FC_SIMPLE = { bold: 'bold', italic: 'italic', underline: 'underline', yellow: 'yellow' };

// ================================================================
// 1) TESTO GREZZO -> AST
// ================================================================
function fcParse(str) {
    str = String(str == null ? '' : str);
    var i = 0, n = str.length, nodes = [], textBuf = '';

    function flushText() {
        if (!textBuf) return;
        var parts = textBuf.split('\n');
        parts.forEach(function (p, idx) {
            if (p) nodes.push({ t: 'text', v: p });
            if (idx < parts.length - 1) nodes.push({ t: 'br' });
        });
        textBuf = '';
    }

    while (i < n) {
        var ch = str[i];

        if (ch === '$') {
            var display = false, j = i;
            if (str[j + 1] === '$') { display = true; j += 2; } else { j += 1; }
            var delim = display ? '$$' : '$';
            var closeIdx = str.indexOf(delim, j);
            if (closeIdx === -1) { textBuf += ch; i++; continue; }
            var tex = str.substring(j, closeIdx);
            flushText();
            nodes.push({ t: 'math', tex: tex, display: display });
            i = closeIdx + delim.length;
            continue;
        }

        if (ch === '/') {
            // /list oppure /list-colore (fail-safe: un suffisso non valido = nessun colore, non testo rotto)
            var mList = /^\/list(-[a-zA-Z]+)?\{/.exec(str.slice(i));
            if (mList) {
                var rawSuffix = mList[1] ? mList[1].slice(1).toLowerCase() : '';
                var listColor = (FC_LIST_COLORS.indexOf(rawSuffix) !== -1) ? rawSuffix : null;
                var contentStart = i + mList[0].length;
                var depth = 1, k = contentStart;
                while (k < n && depth > 0) {
                    if (str[k] === '{') depth++;
                    else if (str[k] === '}') { depth--; if (depth === 0) break; }
                    k++;
                }
                var innerL = str.substring(contentStart, k);
                flushText();
                nodes.push(fcParseList(innerL, listColor));
                i = k + 1;
                continue;
            }

            var mTag = /^\/([a-zA-Z]+)\{/.exec(str.slice(i));
            if (mTag) {
                var name = mTag[1].toLowerCase();
                var known = FC_SIMPLE[name] || FC_COLORS.indexOf(name) !== -1 || name === 'gray';
                if (known) {
                    var contentStart2 = i + mTag[0].length;
                    var depth2 = 1, k2 = contentStart2;
                    while (k2 < n && depth2 > 0) {
                        if (str[k2] === '{') depth2++;
                        else if (str[k2] === '}') { depth2--; if (depth2 === 0) break; }
                        k2++;
                    }
                    var inner2 = str.substring(contentStart2, k2);
                    flushText();
                    if (FC_SIMPLE[name]) nodes.push({ t: FC_SIMPLE[name], c: fcParse(inner2) });
                    else nodes.push({ t: 'color', color: name, c: fcParse(inner2) });
                    i = k2 + 1;
                    continue;
                }
            }
        }

        textBuf += ch;
        i++;
    }
    flushText();
    return nodes;
}

// Profondità libera: il livello è semplicemente il numero di trattini/pallini iniziali
// ("-" = 1, "--" = 2, "---" = 3, ...) — niente casi speciali, niente limite a due livelli.
function fcParseList(inner, color) {
    var content = String(inner == null ? '' : inner).replace(/^\n/, '').replace(/\n$/, '');
    var result = { t: 'list', color: color || null, items: [] };
    if (content.trim() === '') return result;
    
    content.split('\n').forEach(function (line) {
        var trimmed = line.trim();
        if (trimmed === '') return;
        
        var m = /^([-•]+)\s*(.*)$/.exec(trimmed);
        if (m) {
            // È un nuovo punto elenco
            var lvl = m[1].replace(/•/g, '-').length;
            result.items.push({ level: lvl, c: fcParse(m[2]) });
        } else {
            // Non inizia per trattino: è un a capo (br) del punto precedente!
            if (result.items.length > 0) {
                var lastItem = result.items[result.items.length - 1];
                lastItem.c.push({ t: 'br' });
                // Uniamo i nodi della nuova riga a quelli dell'item corrente
                Array.prototype.push.apply(lastItem.c, fcParse(trimmed));
            } else {
                // Fallback di sicurezza se la prima riga in assoluto non ha il trattino
                result.items.push({ level: 1, c: fcParse(trimmed) });
            }
        }
    });
    return result;
}

// ================================================================
// 2) AST -> TESTO GREZZO (per il salvataggio a DB)
// ================================================================
function fcAstToTagText(nodes) { return (nodes || []).map(fcNodeToTagText).join(''); }
function fcNodeToTagText(node) {
    switch (node.t) {
        case 'text': return node.v;
        case 'br': return '\n';
        case 'bold': return '/bold{' + fcAstToTagText(node.c) + '}';
        case 'italic': return '/italic{' + fcAstToTagText(node.c) + '}';
        case 'underline': return '/underline{' + fcAstToTagText(node.c) + '}';
        case 'yellow': return '/yellow{' + fcAstToTagText(node.c) + '}';
        case 'color': return '/' + node.color + '{' + fcAstToTagText(node.c) + '}';
        case 'math': return node.display ? ('$$' + node.tex + '$$') : ('$' + node.tex + '$');
        case 'list': {
            var tag = node.color ? ('list-' + node.color) : 'list';
            var lines = (node.items || []).map(function (it) {
                // Rimosso il replace per preservare i <br> tramutati in \n
                var content = fcAstToTagText(it.c).trim();
                content = content.replace(/^[-•]+\s*/, ''); // toglie trattini duplicati salvati per errore
                return '-'.repeat(Math.max(1, it.level)) + ' ' + content;
            });
            return '/' + tag + '{\n' + lines.join('\n') + '\n}';
        }
        default: return '';
    }
}

// ================================================================
// 3) AST -> DOM (per idratare l'editor WYSIWYG)
// ================================================================
function fcAstToDomChildren(nodes, parent) {
    (nodes || []).forEach(function (n) { parent.appendChild(fcNodeToDom(n)); });
    return parent;
}
function fcNodeToDom(node) {
    switch (node.t) {
        case 'text': return document.createTextNode(node.v);
        case 'br': return document.createElement('br');
        case 'bold': return fcAstToDomChildren(node.c, document.createElement('strong'));
        case 'italic': return fcAstToDomChildren(node.c, document.createElement('em'));
        case 'underline': return fcAstToDomChildren(node.c, document.createElement('u'));
        case 'yellow': { var mk = document.createElement('mark'); mk.className = 'fc-hl'; return fcAstToDomChildren(node.c, mk); }
        case 'color': { var sp = document.createElement('span'); sp.className = 'fc-c'; sp.dataset.c = node.color; return fcAstToDomChildren(node.c, sp); }
        case 'math': {
            var el = document.createElement('span');
            el.className = 'fc-math';
            el.contentEditable = 'false';
            el.dataset.tex = node.tex;
            el.dataset.display = node.display ? 'true' : 'false';
            el.textContent = node.display ? ('$$' + node.tex + '$$') : ('$' + node.tex + '$');
            return el;
        }
        case 'list': return fcBuildListDom(node.items, node.color);
        default: return document.createTextNode('');
    }
}
function fcMakeLiRow(level) {
    var row = document.createElement('div');
    row.className = 'fc-li';
    row.dataset.level = String(level);
    row.style.setProperty('--lvl', level);
    return row;
}
function fcApplyListColor(listEl, color) {
    if (color) { listEl.dataset.color = color; listEl.style.setProperty('--list-color', FC_PALETTE[color] || FC_PALETTE.blue); }
    else { delete listEl.dataset.color; listEl.style.removeProperty('--list-color'); }
}
function fcBuildListDom(items, color) {
    var wrap = document.createElement('div');
    wrap.className = 'fc-list';
    fcApplyListColor(wrap, color);
    (items || []).forEach(function (it) {
        var row = fcMakeLiRow(Math.max(1, it.level));
        fcAstToDomChildren(it.c, row);
        if (!row.childNodes.length) row.appendChild(document.createTextNode('\u200B'));
        wrap.appendChild(row);
    });
    return wrap;
}

// ================================================================
// 4) DOM -> AST (per leggere lo stato dell'editor prima di salvare)
// ================================================================
function fcDomToAst(node) {
    var out = [];
    node.childNodes.forEach(function (child) {
        if (child.nodeType === Node.TEXT_NODE) {
            if (child.nodeValue) out.push({ t: 'text', v: child.nodeValue });
            return;
        }
        if (child.nodeType !== Node.ELEMENT_NODE) return;
        var tag = child.tagName.toLowerCase();
        if (tag === 'br') { out.push({ t: 'br' }); return; }
        if (tag === 'strong' || tag === 'b') { out.push({ t: 'bold', c: fcDomToAst(child) }); return; }
        if (tag === 'em' || tag === 'i') { out.push({ t: 'italic', c: fcDomToAst(child) }); return; }
        if (tag === 'u') { out.push({ t: 'underline', c: fcDomToAst(child) }); return; }
        if (tag === 'mark' && child.classList.contains('fc-hl')) { out.push({ t: 'yellow', c: fcDomToAst(child) }); return; }
        if (tag === 'span' && child.classList.contains('fc-c')) { out.push({ t: 'color', color: child.dataset.c, c: fcDomToAst(child) }); return; }
        if (tag === 'span' && child.classList.contains('fc-math')) { out.push({ t: 'math', tex: child.dataset.tex || '', display: child.dataset.display === 'true' }); return; }
        if (tag === 'div' && child.classList.contains('fc-list')) { out.push(fcListDivToAst(child)); return; }
        if (tag === 'div') { out.push.apply(out, fcDomToAst(child)); out.push({ t: 'br' }); return; }
        out.push.apply(out, fcDomToAst(child));
    });
    return out;
}
function fcListDivToAst(listEl) {
    var items = [];
    Array.prototype.slice.call(listEl.children).forEach(function (row) {
        if (!row.classList || !row.classList.contains('fc-li')) return;
        var level = parseInt(row.dataset.level, 10);
        if (!level || level < 1) level = 1;
        items.push({ level: level, c: fcDomToAst(row) });
    });
    return { t: 'list', color: listEl.dataset.color || null, items: items };
}

// ================================================================
// 5) AST -> HTML (anteprima di sola lettura: visualizzazione_fc.php e simili)
//    Stesse classi CSS usate dall'editor (.fc-c, .fc-hl, .fc-list, .fc-li) così
//    l'aspetto è identico ovunque; lo stile arriva dal blocco auto-iniettato più sotto.
// ================================================================
function fcAstToPreviewHTML(nodes) { return (nodes || []).map(fcNodeToPreviewHTML).join(''); }
function fcNodeToPreviewHTML(node) {
    switch (node.t) {
        case 'text': return escapeHTML(node.v);
        case 'br': return '<br>';
        case 'bold': return '<strong>' + fcAstToPreviewHTML(node.c) + '</strong>';
        case 'italic': return '<em>' + fcAstToPreviewHTML(node.c) + '</em>';
        case 'underline': return '<u>' + fcAstToPreviewHTML(node.c) + '</u>';
        case 'yellow': return '<mark class="fc-hl">' + fcAstToPreviewHTML(node.c) + '</mark>';
        case 'color': return '<span class="fc-c" data-c="' + escapeHTML(node.color) + '">' + fcAstToPreviewHTML(node.c) + '</span>';
        case 'math': return escapeHTML(node.display ? ('$$' + node.tex + '$$') : ('$' + node.tex + '$'));
        case 'list': {
            var attr = node.color ? (' data-color="' + escapeHTML(node.color) + '" style="--list-color:' + FC_PALETTE[node.color] + '"') : '';
            var rows = (node.items || []).map(function (it) {
                return '<div class="fc-li" style="--lvl:' + Math.max(1, it.level) + '">' + fcAstToPreviewHTML(it.c) + '</div>';
            }).join('');
            return '<div class="fc-list"' + attr + '>' + rows + '</div>';
        }
        default: return '';
    }
}

// Punto d'ingresso pubblico e stabile: stesso nome di sempre, così il codice esistente
// che lo richiama (anche nelle pagine non ancora aggiornate) continua a funzionare.
function parseTextForPreview(text) {
    return fcAstToPreviewHTML(fcParse(text));
}

// ================================================================
// 6) UTILITY CONDIVISE
// ================================================================

// Ritenta finché MathJax non è pronto, invece di sperare che lo sia già al momento della chiamata.
function fcTypesetMath(elements) {
    if (window.MathJax && MathJax.typesetPromise) {
        MathJax.typesetPromise(elements).catch(function () {});
    } else {
        setTimeout(function () { fcTypesetMath(elements); }, 200);
    }
}

// Cerca tutti gli elementi con testo grezzo salvato in data-raw, li formatta e li rende.
// Un solo posto per farlo bene, invece che una copia diversa per ogni pagina.
function renderAllRawTexts(root) {
    var scope = root || document;
    var found = false;
    scope.querySelectorAll('.raw-text-content').forEach(function (el) {
        el.innerHTML = parseTextForPreview(el.getAttribute('data-raw'));
        found = true;
    });
    if (found) fcTypesetMath([scope === document ? document.body : scope]);
}

// ================================================================
// 7) CSS AUTO-INIETTATO: basta includere questo file per avere colori/elenchi/
//    evidenziatore già stilizzati ovunque, senza dover copiare regole in ogni pagina.
// ================================================================
(function fcInjectParserStyles() {
    if (document.getElementById('fc-parser-styles')) return;
    var css =
        '.fc-c[data-c="red"]{ color:' + FC_PALETTE.red + '; }' +
        '.fc-c[data-c="blue"]{ color:' + FC_PALETTE.blue + '; }' +
        '.fc-c[data-c="green"]{ color:' + FC_PALETTE.green + '; }' +
        '.fc-c[data-c="purple"]{ color:' + FC_PALETTE.purple + '; }' +
        '.fc-c[data-c="orange"]{ color:' + FC_PALETTE.orange + '; }' +
        '.fc-c[data-c="gray"]{ color:' + FC_PALETTE.gray + '; }' +
        'mark.fc-hl{ background:#FFE066; color:#1C1C1E; padding:0 3px; border-radius:3px; }' +
        '.fc-list{ margin:8px 0; --list-color:' + FC_PALETTE.blue + '; padding:2px 0 2px 11px; border-left:3px solid var(--list-color); }' +
        '.fc-li{ position:relative; margin:3px 0; padding-left:calc(14px + var(--lvl,1) * 18px); line-height:1.45; }' +
        '.fc-li::before{ content:"•"; position:absolute; left:calc(var(--lvl,1) * 18px - 12px); color:var(--list-color); font-weight:700; }';
    var styleEl = document.createElement('style');
    styleEl.id = 'fc-parser-styles';
    styleEl.textContent = css;
    document.head.appendChild(styleEl);
})();

document.addEventListener('DOMContentLoaded', function () { renderAllRawTexts(); });