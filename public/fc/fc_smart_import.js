/* fc_smart_import.js — l'import "intelligente" e la generazione di parole con l'IA.

   IMPORTA
   Una casella sola. Si incolla quello che si ha e la pagina capisce da sé:
   - celle copiate da Excel o Google Fogli (colonne separate da tabulazioni,
     con le virgolette quando una cella va a capo);
   - un CSV (virgole o punti e virgola);
   - un JSON: un elenco di oggetti, di elenchi, o un oggetto che ne contiene uno;
   - righe semplici "parola - traduzione", "domanda = risposta", "a: b".
   Se la prima riga è un'intestazione (Word, Meaning, Esempio, Link…) le
   colonne si assegnano dai nomi; se no dal contenuto: una colonna di
   indirizzi è l'immagine, una colonna di frasi che contengono la parola è
   l'esempio, le altre in ordine. Sotto la casella si vede subito cosa è
   stato capito, colonna per colonna, e ogni assegnazione si può cambiare.

   Negli esempi delle parole la parola diventa "_" (la lacuna del gioco), se
   compare scritta così com'è.

   GENERA CON L'IA (solo lingue)
   Parole su un argomento, al livello scelto, oppure il completamento di un
   elenco incollato. Le parole già nella tabella non si ripetono. */
(function () {
    'use strict';

    var VOCAB = !!(window.FC && window.FC.tipo === 'vocab');
    function $(id) { return document.getElementById(id); }
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
        });
    }
    function norm(s) {
        return String(s == null ? '' : s).toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '')
            .replace(/\(.*?\)/g, '').replace(/[^a-z0-9Ѐ-ӿͰ-Ͽ぀-ヿ一-鿿]+/g, '');
    }

    /* ============================================================
       1. I CAMPI
       ============================================================ */
    /* 'n' = le note della parola (fc_vocab_cards.notes), solo nelle lingue */
    var CAMPI = VOCAB ? ['q', 'a', 'm', 'ex', 'n', 'img', ''] : ['q', 'a', 'img', ''];
    function nomeCampo(c) {
        switch (c) {
            case 'q':   return T(VOCAB ? 'fc.smi_campo_q_vocab' : 'fc.smi_campo_q');
            case 'a':   return T(VOCAB ? 'fc.smi_campo_a_vocab' : 'fc.smi_campo_a');
            case 'm':   return T('fc.smi_campo_m');
            case 'ex':  return T('fc.smi_campo_ex');
            case 'n':   return T('fc.smi_campo_n');
            case 'img': return T('fc.smi_campo_img');
            default:    return T('fc.smi_campo_salta');
        }
    }

    /* i nomi con cui una colonna può chiamarsi, normalizzati */
    var SINONIMI = {
        q:   ['word', 'words', 'parola', 'parole', 'vocabolo', 'termine', 'term', 'lemma', 'front', 'fronte', 'vocab', 'vocabulary',
              'expression', 'espressione', 'w', 'q', 'question', 'domanda', 'domande', 'questions', 'prompt', 'concetto', 'concept'],
        a:   ['translation', 'traduzione', 'traduzioni', 'translations', 't', 'a', 'answer', 'risposta', 'risposte', 'answers',
              'back', 'retro', 'solution', 'soluzione'],
        m:   ['meaning', 'meanings', 'significato', 'significati', 'definition', 'definizione', 'def', 'description',
              'descrizione', 'spiegazione', 'explanation', 'm', 'gloss'],
        ex:  ['example', 'examples', 'esempio', 'esempi', 'sentence', 'sentences', 'frase', 'frasi', 'usage', 'uso',
              'context', 'contesto', 'e', 'ex'],
        n:   ['notes', 'note', 'nota', 'notas', 'notizen', 'tip', 'tips', 'trick', 'trucco', 'trucchi', 'memo', 'promemoria',
              'hint', 'hints', 'suggerimento', 'suggerimenti', 'remark', 'remarks', 'comment', 'comments', 'commento', 'commenti',
              'mnemonic', 'mnemonico', 'appunti', 'appunto', 'n'],
        img: ['image', 'images', 'immagine', 'immagini', 'img', 'picture', 'pic', 'photo', 'foto', 'link', 'url',
              'imageurl', 'imagelink', 'linkimmagine', 'src']
    };
    /* nelle lingue anche il nome della lingua è un'intestazione: "English |
       Italiano" vuol dire parola | traduzione */
    if (VOCAB && window.FC) {
        var lingue = function (x) { return x ? [norm(x.nome), norm(x.codice), norm(String(x.codice || '').split('-')[0])] : []; };
        SINONIMI.q = SINONIMI.q.concat(lingue(FC.impara));
        SINONIMI.a = SINONIMI.a.concat(lingue(FC.conosce));
        ['english', 'inglese', 'german', 'tedesco', 'deutsch', 'french', 'francese', 'francais', 'spanish', 'spagnolo',
         'espanol', 'portuguese', 'portoghese', 'japanese', 'giapponese', 'chinese', 'cinese', 'russian', 'russo']
            .forEach(function (n) { if (SINONIMI.a.indexOf(n) === -1 && SINONIMI.q.indexOf(n) === -1) SINONIMI.q.push(n); });
    }
    function campoDaNome(nome) {
        var n = norm(nome);
        if (!n) return null;
        for (var c in SINONIMI) if (SINONIMI[c].indexOf(n) !== -1) return VOCAB || c === 'q' || c === 'a' || c === 'img' ? c : null;
        return null;
    }

    /* ============================================================
       2. LETTURA
       ============================================================ */
    /* Tabella con un separatore di un carattere, virgolette comprese: una
       cella di Excel che va a capo arriva fra virgolette e non va spezzata. */
    function leggiTabella(testo, sep) {
        var righe = [], riga = [], campo = '', dentro = false, i = 0;
        testo = String(testo).replace(/\r\n?/g, '\n');
        while (i < testo.length) {
            var c = testo[i];
            if (dentro) {
                if (c === '"') {
                    if (testo[i + 1] === '"') { campo += '"'; i += 2; continue; }
                    dentro = false; i++; continue;
                }
                campo += c; i++; continue;
            }
            if (c === '"' && campo.trim() === '') { dentro = true; campo = ''; i++; continue; }
            if (c === sep) { riga.push(campo); campo = ''; i++; continue; }
            if (c === '\n') { riga.push(campo); righe.push(riga); riga = []; campo = ''; i++; continue; }
            campo += c; i++;
        }
        riga.push(campo);
        righe.push(riga);
        return righe.map(function (r) { return r.map(function (x) { return x.trim(); }); })
                    .filter(function (r) { return r.some(function (x) { return x !== ''; }); });
    }

    /* Righe semplici con un separatore di più caratteri: si taglia solo alla
       prima occorrenza per ogni colonna attesa, così un trattino dentro la
       traduzione non crea una colonna in più. */
    function leggiRighe(testo, sep, colonne) {
        return String(testo).replace(/\r\n?/g, '\n').split('\n').map(function (l) { return l.trim(); }).filter(Boolean)
            .map(function (l) {
                var parti = [], resto = l;
                for (var k = 0; k < colonne - 1; k++) {
                    var p = resto.indexOf(sep);
                    if (p === -1) break;
                    parti.push(resto.slice(0, p).trim());
                    resto = resto.slice(p + sep.length);
                }
                parti.push(resto.trim());
                return parti;
            });
    }

    /* Quale separatore. Per ognuno si contano le righe che lo contengono e
       quanto è costante il numero di colonne: la tabulazione vince quasi
       sempre (è quello che mette Excel); la virgola solo se dà colonne
       regolari, perché nelle frasi le virgole ci sono comunque. */
    function scegliSeparatore(testo) {
        var linee = String(testo).replace(/\r\n?/g, '\n').split('\n').filter(function (l) { return l.trim() !== ''; }).slice(0, 60);
        if (!linee.length) return null;
        function conta(l, s) { return l.split(s).length - 1; }
        function moda(arr) {
            var f = {}, m = 0, v = 0;
            arr.forEach(function (x) { f[x] = (f[x] || 0) + 1; if (f[x] > m) { m = f[x]; v = x; } });
            return { valore: +v, quota: m / arr.length };
        }
        var candidati = [
            { s: '\t', tab: true, min: 0.5 },
            { s: ';', tab: true, min: 0.7, reg: 0.75 },
            { s: '|', tab: true, min: 0.7, reg: 0.75 },
            { s: ',', tab: true, min: 0.8, reg: 0.85 },
            { s: ' - ', min: 0.6 }, { s: ' – ', min: 0.6 }, { s: ' — ', min: 0.6 },
            { s: ' = ', min: 0.6 }, { s: ' : ', min: 0.6 }, { s: ': ', min: 0.6 }, { s: ' -> ', min: 0.6 }, { s: ' → ', min: 0.6 }
        ];
        for (var i = 0; i < candidati.length; i++) {
            var c = candidati[i];
            var conti = linee.map(function (l) { return conta(l, c.s); });
            var con = conti.filter(function (n) { return n > 0; }).length / linee.length;
            if (con < c.min) continue;
            var m = moda(conti);
            if (c.reg && m.quota < c.reg) continue;
            return { sep: c.s, tabella: !!c.tab, colonne: Math.max(1, m.valore + 1) };
        }
        return { sep: null, tabella: false, colonne: 1 };
    }

    /* Il JSON delle IA arriva spesso con le barre del LaTeX non raddoppiate e
       gli a capo dentro le stringhe: si prova prima com'è, poi riparato. */
    function leggiJson(testo) {
        try { return JSON.parse(testo); } catch (e1) {
            var sicuro = testo.replace(/\n(?!\s*[\}\]\s]*\s*(?:,|\]|"|\{))/g, '\\n')
                              .replace(/(^|[^\\])\\(?!n|t|r|"|\\|\/|u[0-9a-fA-F]{4})/g, '$1\\\\');
            return JSON.parse(sicuro);
        }
    }

    function daJson(dati) {
        /* un oggetto che contiene un elenco: {"cards": [...]} */
        if (dati && !Array.isArray(dati) && typeof dati === 'object') {
            var dentro = null;
            Object.keys(dati).some(function (k) { if (Array.isArray(dati[k])) { dentro = dati[k]; return true; } return false; });
            dati = dentro || [dati];
        }
        if (!Array.isArray(dati) || !dati.length) return null;
        if (Array.isArray(dati[0])) {
            return { colonne: dati[0].map(function (_, i) { return T('fc.smi_colonna_n', { n: i + 1 }); }),
                     righe: dati.map(function (r) { return r.map(testoCella); }), intestazione: false };
        }
        if (typeof dati[0] !== 'object') {
            return { colonne: [T('fc.smi_colonna_n', { n: 1 })], righe: dati.map(function (x) { return [testoCella(x)]; }), intestazione: false };
        }
        var chiavi = [];
        dati.forEach(function (o) { if (o && typeof o === 'object') Object.keys(o).forEach(function (k) { if (chiavi.indexOf(k) === -1) chiavi.push(k); }); });
        return {
            colonne: chiavi,
            righe: dati.filter(function (o) { return o && typeof o === 'object'; })
                       .map(function (o) { return chiavi.map(function (k) { return testoCella(o[k]); }); }),
            intestazione: true
        };
    }
    function testoCella(v) {
        if (v == null) return '';
        if (Array.isArray(v)) return v.map(testoCella).filter(Boolean).join(' · ');
        if (typeof v === 'object') return testoCella(v.text || v.testo || v.value || Object.values(v)[0]);
        return String(v).trim().replace(/\\%/g, '%');
    }

    function sembraUrl(s) { return /^https?:\/\/\S+$/i.test(String(s).trim()); }

    /* ============================================================
       3. ANALISI: formato, intestazione, a cosa serve ogni colonna
       ============================================================ */
    function analizza(testo) {
        testo = String(testo || '').trim();
        if (!testo) return null;
        var tab = null, formato = '', erroreJson = null;

        if (/^[\[{]/.test(testo)) {
            try { tab = daJson(leggiJson(testo)); formato = 'json'; }
            catch (e) { erroreJson = e.message; }
            /* comincia come un JSON: se non si legge, è un JSON rotto, non una
               tabella con le parentesi dentro */
            if (!tab && /^\[\s*[\[{"]|^\{\s*"/.test(testo)) return { errore: erroreJson || '?' };
        }
        if (!tab) {
            var s = scegliSeparatore(testo);
            if (!s) return null;
            var righe;
            if (s.sep === null) righe = testo.split(/\r?\n/).map(function (l) { return [l.trim()]; }).filter(function (r) { return r[0]; });
            else if (s.tabella) righe = leggiTabella(testo, s.sep);
            else righe = leggiRighe(testo, s.sep, s.colonne);
            if (!righe.length) return erroreJson ? { errore: erroreJson } : null;
            formato = s.sep === '\t' ? 'excel' : (s.sep === ',' || s.sep === ';' || s.sep === '|') ? 'csv' : 'righe';
            var n = 0;
            righe.forEach(function (r) { n = Math.max(n, r.length); });
            righe = righe.map(function (r) { while (r.length < n) r.push(''); return r; });

            /* intestazione: la prima riga è fatta di nomi di campo che si
               riconoscono, e nessuna sua cella è una frase lunga */
            var prima = righe[0], riconosciuti = 0;
            prima.forEach(function (c) { if (campoDaNome(c)) riconosciuti++; });
            var corta = prima.every(function (c) { return c.length <= 40; });
            var intest = righe.length > 1 && corta && riconosciuti >= Math.max(1, Math.ceil(prima.filter(Boolean).length / 2));
            tab = {
                colonne: intest ? prima.map(function (c, i) { return c || T('fc.smi_colonna_n', { n: i + 1 }); })
                                : prima.map(function (_, i) { return T('fc.smi_colonna_n', { n: i + 1 }); }),
                righe: intest ? righe.slice(1) : righe,
                intestazione: intest
            };
            if (erroreJson && formato === 'righe' && tab.colonne.length < 2) return { errore: erroreJson };
        }

        tab.formato = formato;
        tab.campi = assegna(tab);
        return tab;
    }

    function assegna(tab) {
        var n = tab.colonne.length, campi = new Array(n).fill(null), usati = {};
        /* 1. dai nomi */
        if (tab.intestazione) {
            tab.colonne.forEach(function (nome, i) {
                var c = campoDaNome(nome);
                if (c && !usati[c]) { campi[i] = c; usati[c] = true; }
            });
        }
        /* 2. dal contenuto: indirizzi = immagine */
        for (var i = 0; i < n; i++) {
            if (campi[i] !== null) continue;
            var piene = tab.righe.map(function (r) { return r[i]; }).filter(Boolean);
            if (piene.length && !usati.img && piene.filter(sembraUrl).length / piene.length >= 0.6) { campi[i] = 'img'; usati.img = true; }
        }
        /* 3. lingue: la colonna di frasi che contengono la parola è l'esempio */
        if (VOCAB) {
            var colQ = campi.indexOf('q');
            var primaLibera = -1;
            for (var j = 0; j < n; j++) if (campi[j] === null) { primaLibera = j; break; }
            var qIdx = colQ !== -1 ? colQ : primaLibera;
            if (qIdx !== -1 && !usati.ex) {
                for (var k = 0; k < n; k++) {
                    if (k === qIdx || campi[k] !== null) continue;
                    var conta = 0, tot = 0;
                    tab.righe.forEach(function (r) {
                        if (!r[k] || !r[qIdx]) return;
                        tot++;
                        if (r[k].indexOf('_') !== -1 || (r[k].length > r[qIdx].length + 8 && r[k].toLowerCase().indexOf(r[qIdx].toLowerCase()) !== -1)) conta++;
                    });
                    if (tot && conta / tot >= 0.5) { campi[k] = 'ex'; usati.ex = true; break; }
                }
            }
        }
        /* 4. il resto in ordine */
        var ordine = VOCAB ? ['q', 'a', 'm', 'ex'] : ['q', 'a'];
        for (var z = 0; z < n; z++) {
            if (campi[z] !== null) continue;
            var libero = ordine.filter(function (c) { return !usati[c]; })[0];
            campi[z] = libero || '';
            if (libero) usati[libero] = true;
        }
        return campi;
    }

    /* Dalle colonne alle carte. */
    function carte(tab) {
        if (!tab || !tab.righe) return { carte: [], lacune: 0 };
        var lacune = 0;
        var out = tab.righe.map(function (r) {
            var c = { q: '', a: '', m: '', ex: '', n: '', imgLink: '' };
            tab.campi.forEach(function (campo, i) {
                var v = r[i] || '';
                if (!campo || !v) return;
                if (campo === 'img') { if (sembraUrl(v) && !c.imgLink) c.imgLink = v; return; }
                c[campo] = c[campo] ? c[campo] + ' · ' + v : v;
            });
            if (VOCAB && c.ex && c.q && c.ex.indexOf('_') === -1) {
                var p = c.ex.toLowerCase().indexOf(c.q.toLowerCase());
                if (p !== -1) { c.ex = c.ex.slice(0, p) + '_' + c.ex.slice(p + c.q.length); lacune++; }
            }
            return c;
        }).filter(function (c) { return c.q !== '' || c.a !== '' || c.imgLink; });
        return { carte: out, lacune: lacune };
    }

    /* ============================================================
       4. LA CASELLA E QUELLO CHE SI È CAPITO
       ============================================================ */
    var ultimo = null;

    /* tabFissa: dopo un cambio a mano si ridisegna quella, senza rileggere il
       testo (si perderebbe la scelta appena fatta) */
    function disegna(tabFissa) {
        var box = $('fcaiSmartEsito'), area = $('fcaiSmart'), nota = $('fcaiImportNota');
        if (!box || !area) return;
        if (nota) { nota.textContent = ''; nota.className = 'fcai-nota'; }
        var tab = tabFissa && tabFissa.campi ? tabFissa : analizza(area.value);
        ultimo = tab && !tab.errore ? tab : null;
        aggiornaBottone();
        if (!tab) { box.hidden = true; box.innerHTML = ''; return; }
        box.hidden = false;
        if (tab.errore) {
            box.innerHTML = '<div class="fcai-smart-err">' + esc(T('fc.smi_json_errore', { errore: tab.errore })) + '</div>';
            return;
        }
        var risultato = carte(tab);
        var formati = { excel: 'fc.smi_formato_excel', csv: 'fc.smi_formato_csv', json: 'fc.smi_formato_json', righe: 'fc.smi_formato_righe' };
        var h = '<div class="fcai-smart-riass">'
              + '<span class="fcai-smart-tag">' + esc(T(formati[tab.formato] || 'fc.smi_formato_righe')) + '</span>'
              + (tab.intestazione ? '<span class="fcai-smart-tag fcai-smart-tag-2">' + esc(T('fc.smi_con_intestazione')) + '</span>' : '')
              + '<b>' + esc(risultato.carte.length === 1 ? T('fc.smi_1_carta') : T('fc.smi_n_carte', { n: risultato.carte.length })) + '</b></div>';

        h += '<div class="fcai-smart-cols">';
        tab.colonne.forEach(function (nome, i) {
            var campione = '';
            for (var r = 0; r < tab.righe.length && !campione; r++) campione = tab.righe[r][i] || '';
            h += '<label class="fcai-smart-col' + (tab.campi[i] ? '' : ' is-off') + '">'
               + '<span class="fcai-smart-colnome">' + esc(nome) + '</span>'
               + '<span class="fcai-smart-colcamp" title="' + esc(campione) + '">' + esc(campione.length > 46 ? campione.slice(0, 44) + '…' : campione) + '</span>'
               + '<select data-col="' + i + '">'
               + CAMPI.map(function (c) { return '<option value="' + c + '"' + (tab.campi[i] === c ? ' selected' : '') + '>' + esc(nomeCampo(c)) + '</option>'; }).join('')
               + '</select></label>';
        });
        h += '</div>';

        if (tab.campi.indexOf('q') === -1) {
            h += '<div class="fcai-smart-err">' + esc(T('fc.smi_manca_q', { campo: nomeCampo('q').toLowerCase() })) + '</div>';
        }

        var prime = risultato.carte.slice(0, 3);
        if (prime.length) {
            h += '<div class="fcai-smart-anteprima"><div class="fcai-label">' + esc(T('fc.smi_anteprima')) + '</div>';
            prime.forEach(function (c) {
                h += '<div class="fcai-smart-carta">'
                   + (c.imgLink ? '<img src="' + esc(c.imgLink) + '" alt="" loading="lazy" referrerpolicy="no-referrer">' : '')
                   + '<div><b>' + esc(c.q) + '</b>' + (c.a ? '<span> — ' + esc(c.a) + '</span>' : '')
                   + (c.m ? '<i>' + esc(c.m) + '</i>' : '')
                   + (c.ex ? '<i class="fcai-smart-ex">' + esc(c.ex) + '</i>' : '')
                   + (c.n ? '<i class="fcai-smart-nota">' + esc(c.n) + '</i>' : '') + '</div></div>';
            });
            if (risultato.lacune) h += '<div class="fcai-nota">' + esc(T('fc.smi_lacune_fatte', { n: risultato.lacune })) + '</div>';
            h += '</div>';
        }
        box.innerHTML = h;
        box.querySelectorAll('select[data-col]').forEach(function (sel) {
            sel.addEventListener('change', function () {
                var i = +sel.getAttribute('data-col'), v = sel.value;
                /* un campo sta in una colonna sola, tranne "ignora": chi lo
                   aveva prima lo cede */
                if (v) ultimo.campi = ultimo.campi.map(function (c, k) { return k !== i && c === v ? '' : c; });
                ultimo.campi[i] = v;
                disegna(ultimo);
            });
        });
    }
    function aggiornaBottone() {
        var b = $('fcaiBtnImporta');
        if (!b) return;
        var n = ultimo ? carte(ultimo).carte.length : 0;
        b.disabled = !n || (ultimo && ultimo.campi.indexOf('q') === -1);
        b.textContent = n ? (n === 1 ? T('fc.smi_crea_1') : T('fc.smi_crea', { n: n })) : T('fc.ibfai_crea_carte');
    }

    /* ============================================================
       5. GENERA CON L'IA (lingue)
       ============================================================ */
    var vai = { modo: 'argomento', livello: '', quante: 20, timer: null, conto: 0 };

    function paroleNellaTabella() {
        var out = [];
        document.querySelectorAll('#fcRows .fc-row').forEach(function (r) {
            if (r.dataset.status === 'deleted') return;
            var q = $('q-desc-' + r.dataset.rid);
            if (q && q.value.trim()) out.push(q.value.trim());
        });
        return out.slice(0, 400);
    }
    function richiestaParole() {
        var elenco = vai.modo === 'elenco'
            ? ($('fcaiParoleElenco').value || '').split(/\r?\n/).map(function (s) { return s.trim(); }).filter(Boolean).slice(0, 60)
            : [];
        return {
            corso: (window.FC && FC.corso) || 0,
            argomento: vai.modo === 'argomento' ? ($('fcaiParoleArg').value || '').trim() : '',
            parole: elenco,
            livello: vai.livello,
            quante: vai.quante,
            significato: $('fcaiParoleSig').checked ? 1 : 0,
            esempio: $('fcaiParoleEs').checked ? 1 : 0,
            indicazioni: ($('fcaiParoleInd').value || '').trim(),
            escludi: paroleNellaTabella()
        };
    }
    function vuota(d) { return !d.argomento && !d.parole.length; }

    function contaCosto() {
        clearTimeout(vai.timer);
        vai.timer = setTimeout(async function () {
            var box = $('fcaiParoleCosto'), d = richiestaParole(), mio = ++vai.conto;
            if (vuota(d) || !window.FCAI) { box.textContent = ''; return; }
            try {
                var st = await FCAI.stima('parole', d);
                if (mio !== vai.conto) return;
                var loc = document.documentElement.lang || 'it';
                box.className = 'fcai-nota' + (st.sufficiente ? '' : ' is-err');
                box.textContent = st.disponibili === null
                    ? T('fc.vai_costo', { n: st.stima.toLocaleString(loc) })
                    : T('fc.vai_costo_resto', { n: st.stima.toLocaleString(loc), disp: st.disponibili.toLocaleString(loc) })
                      + (st.sufficiente ? '' : ' · ' + T('fc.vai_non_bastano'));
            } catch (e) { if (mio === vai.conto) box.textContent = ''; }
        }, 450);
    }

    async function generaParole() {
        var d = richiestaParole(), nota = $('fcaiParoleNota'), btn = $('fcaiBtnParole');
        if (vuota(d)) { nota.className = 'fcai-nota is-err'; nota.textContent = T('fc.vai_vuoto'); return; }
        btn.disabled = true;
        btn.classList.add('is-busy');
        nota.className = 'fcai-nota';
        nota.textContent = T('fc.vai_in_corso');
        try {
            var r = await fetch('fc_ai.php', {
                method: 'POST', headers: { 'Content-Type': 'application/json' }, cache: 'no-store',
                body: JSON.stringify(Object.assign({ azione: 'parole' }, d))
            });
            var j = null;
            try { j = await r.json(); } catch (e) {}
            if (!j) throw { codice: 'rete', http: r.status };
            if (!j.ok) throw Object.assign({ codice: j.error || 'errore' }, j);
            var nuove = (j.parole || []).map(function (p) { return { q: p.q, a: p.a, m: p.m || '', ex: p.ex || '' }; });
            if (window.FCAIInserisci) await FCAIInserisci(nuove, false, true);
            nota.className = 'fcai-nota is-ok';
            nota.textContent = T('fc.vai_fatto', { n: nuove.length, c: j.token || 0 });
            if (vai.modo === 'elenco') $('fcaiParoleElenco').value = '';
            contaCosto();
        } catch (e) {
            nota.className = 'fcai-nota is-err';
            nota.textContent = window.FCAIJ && FCAIJ.messaggio ? FCAIJ.messaggio(e) : T('fc.ibfai_errore_ia');
        } finally {
            btn.disabled = false;
            btn.classList.remove('is-busy');
        }
    }

    function avviaParole() {
        if (!VOCAB || !$('fcaiBtnParole')) return;
        document.querySelectorAll('#fcaiParoleModi .fcai-chip').forEach(function (b) {
            b.addEventListener('click', function () {
                vai.modo = b.getAttribute('data-modo');
                document.querySelectorAll('#fcaiParoleModi .fcai-chip').forEach(function (x) { x.classList.toggle('is-on', x === b); });
                document.querySelectorAll('[data-parole-pan]').forEach(function (p) { p.hidden = p.getAttribute('data-parole-pan') !== vai.modo; });
                contaCosto();
            });
        });
        function scelte(id, chiave, numero) {
            document.querySelectorAll('#' + id + ' .fcai-chip').forEach(function (b) {
                b.addEventListener('click', function () {
                    vai[chiave] = numero ? +b.getAttribute('data-v') : b.getAttribute('data-v');
                    document.querySelectorAll('#' + id + ' .fcai-chip').forEach(function (x) { x.classList.toggle('is-on', x === b); });
                    contaCosto();
                });
            });
        }
        scelte('fcaiParoleLiv', 'livello', false);
        scelte('fcaiParoleQuante', 'quante', true);
        ['fcaiParoleArg', 'fcaiParoleElenco', 'fcaiParoleInd'].forEach(function (id) { $(id).addEventListener('input', contaCosto); });
        ['fcaiParoleSig', 'fcaiParoleEs'].forEach(function (id) { $(id).addEventListener('change', contaCosto); });
        $('fcaiParoleArg').addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); generaParole(); } });
        $('fcaiBtnParole').addEventListener('click', generaParole);
    }

    function avvia() {
        var area = $('fcaiSmart');
        if (area) {
            var t = null;
            area.addEventListener('input', function () { clearTimeout(t); t = setTimeout(function () { disegna(); }, 220); });
            area.addEventListener('paste', function () { setTimeout(function () { disegna(); }, 0); });
            aggiornaBottone();
        }
        avviaParole();
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', avvia);
    else avvia();

    window.FCSmart = {
        analizza: analizza,
        carte: carte,
        /* quello che si importa adesso, con le scelte fatte a mano */
        attuale: function () { return ultimo; },
        ridisegna: function () { disegna(); },
        svuota: function () { var a = $('fcaiSmart'); if (a) a.value = ''; disegna(); }
    };
})();
