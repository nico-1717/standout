/* fc_languages.js — la pagina delle lingue.
 *
 * Tre cose: l'elenco da cui aggiungere una lingua (con ricerca), il menu di
 * un corso, e la conferma di eliminazione. I dati arrivano gia' in pagina
 * (#lng-dati), quindi la ricerca filtra in memoria mentre si scrive, senza
 * chiedere niente al server.
 */
(function () {
    'use strict';

    var D;
    try { D = JSON.parse(document.getElementById('lng-dati').textContent); } catch (e) { return; }

    var $ = function (id) { return document.getElementById(id); };
    var nota = D.nota;            // lingua di partenza scelta in questo momento
    var modo = 'impara';          // 'impara' = scelgo la lingua nuova, 'nota' = scelgo da quale parto
    var inviando = false;

    /* Ricerca senza accenti e senza maiuscole: "francais", "Français" e
       "FRANCESE" devono trovare la stessa cosa. */
    function norm(s) {
        return String(s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();
    }
    D.catalogo.forEach(function (l) {
        l.k = norm(l.n + ' ' + l.v + ' ' + l.a); l.kn = norm(l.n);
        l.parole = l.k.split(/[\s()\-]+/);
    });

    function voce(codice) {
        for (var i = 0; i < D.catalogo.length; i++) if (D.catalogo[i].c === codice) return D.catalogo[i];
        for (var j = 0; j < D.catalogo.length; j++) if (D.catalogo[j].b === codice) return D.catalogo[j];
        return null;
    }
    function bandiera(f) { return '../_assets/svg/flags/' + f + '.svg'; }
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function corsoGia(learn) {
        for (var i = 0; i < D.corsi.length; i++) {
            if (D.corsi[i].l === learn && D.corsi[i].k === nota) return D.corsi[i];
        }
        return null;
    }
    function buzz(ms) { try { if (navigator.vibrate) navigator.vibrate(ms); } catch (e) {} }

    var toastT = null;
    function toast(msg) {
        var t = $('toast'); if (!t) return;
        t.textContent = msg; t.classList.add('show');
        clearTimeout(toastT); toastT = setTimeout(function () { t.classList.remove('show'); }, 2600);
    }

    /* ------------------------------------------------------------------
       FOGLI
       ------------------------------------------------------------------ */
    function apri(el) {
        if (!el) return;
        el.style.display = 'flex'; el.classList.remove('closing'); el.setAttribute('aria-hidden', 'false');
        document.documentElement.classList.add('hub-locked');
    }
    function chiudi(el) {
        if (!el || el.style.display !== 'flex') return;
        el.classList.add('closing');
        setTimeout(function () {
            el.style.display = 'none'; el.classList.remove('closing'); el.setAttribute('aria-hidden', 'true');
            if (!document.querySelector('.modal-overlay[aria-hidden="false"]')) document.documentElement.classList.remove('hub-locked');
        }, 220);
    }
    document.addEventListener('click', function (e) {
        if (e.target.classList && e.target.classList.contains('modal-overlay')) { chiudi(e.target); return; }
        var x = e.target.closest('[data-chiudi]');
        if (x) chiudi(x.closest('.modal-overlay'));
    });
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        var aperti = document.querySelectorAll('.modal-overlay[aria-hidden="false"]');
        if (aperti.length) chiudi(aperti[aperti.length - 1]);
    });

    /* ------------------------------------------------------------------
       ELENCO DELLE LINGUE
       ------------------------------------------------------------------ */
    function riga(l, attiva) {
        var gia = modo === 'impara' ? corsoGia(l.c) : null;
        var stessa = modo === 'impara' && l.b === nota;
        var sel = modo === 'nota' && l.b === nota;
        var nome = esc(l.n) + (modo === 'impara' && l.v ? ' <span class="lr-var">' + esc(l.v) + '</span>' : '');
        var dx = gia ? '<span class="lr-badge">' + esc(T('fchub.gia_aggiunta')) + '</span>'
               : sel ? '<svg class="lr-check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12 5 5L20 7"/></svg>'
               : '<svg class="lr-chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m9 6 6 6-6 6"/></svg>';
        var nat = l.nat && norm(l.nat) !== l.kn ? '<span class="lr-nat">' + esc(l.nat) + '</span>' : '';
        return '<button type="button" class="lng-row' + (attiva ? ' is-active' : '') + (stessa ? ' is-disabled' : '') + '" role="option"'
             + ' data-code="' + esc(modo === 'nota' ? l.b : l.c) + '"' + (stessa ? ' aria-disabled="true"' : '') + '>'
             + '<img class="flag flag-md" src="' + bandiera(l.f) + '" alt="" loading="lazy">'
             + '<span class="lr-txt"><span class="lr-name">' + nome + '</span>' + nat + '</span>'
             + dx + '</button>';
    }

    function elenco(q) {
        var lista = $('lngList'); if (!lista) return;
        var nq = norm(q);
        var fonte = D.catalogo;
        if (modo === 'nota') {
            // una riga per lingua: per la lingua di partenza la variante non conta
            var viste = {};
            fonte = fonte.filter(function (l) { if (viste[l.b]) return false; viste[l.b] = 1; return true; });
        }
        var html = '';
        if (!nq) {
            if (modo === 'impara') {
                var pop = D.popolari.map(voce).filter(function (l) { return l && l.b !== nota; }).slice(0, 9);
                html += '<div class="lng-sec">' + esc(T('fchub.piu_studiate')) + '</div><div class="lng-pop-grid">'
                     + pop.map(tessera).join('') + '</div>';
            }
            html += '<div class="lng-sec">' + esc(T(modo === 'nota' ? 'fchub.da_quale_lingua' : 'fchub.tutte_le_lingue')) + '</div>';
            html += fonte.map(function (l) { return riga(l, false); }).join('');
        } else {
            /* Prima chi COMINCIA con quello che hai scritto, poi chi lo
               contiene: scrivendo "in" si vuole l'inglese, non il cinese. */
            var inizio = [], dentro = [];
            fonte.forEach(function (l) {
                if (l.kn.indexOf(nq) === 0 || norm(l.nat).indexOf(nq) === 0 || l.c.toLowerCase() === nq) inizio.push(l);
                /* "dentro" vuol dire all'inizio di una parola qualunque dei
                   nomi: "bras" trova il portoghese del Brasile, ma "ted" non
                   deve trovare l'inglese solo perche' "United" lo contiene */
                else if (l.parole.some(function (p) { return p.indexOf(nq) === 0; })) dentro.push(l);
            });
            var tutti = inizio.concat(dentro);
            if (!tutti.length) {
                html = '<div class="lng-none">' + esc(T('fchub.nessuna_lingua', { q: q })) + '</div>';
            } else {
                var primo = true;
                html = tutti.map(function (l) {
                    var r = riga(l, primo && !(modo === 'impara' && l.b === nota)); primo = false; return r;
                }).join('');
            }
        }
        lista.innerHTML = html;
    }

    function tessera(l) {
        var gia = corsoGia(l.c);
        return '<button type="button" class="pop-tile' + (gia ? ' is-done' : '') + '" data-code="' + esc(l.c) + '">'
             + '<img class="flag flag-lg" src="' + bandiera(l.f) + '" alt="" loading="lazy">'
             + '<span class="pop-name">' + esc(l.n) + '</span>'
             + (l.v ? '<span class="pop-var">' + esc(l.v) + '</span>' : '')
             + (gia ? '<span class="pop-check" aria-hidden="true">✓</span>' : '')
             + '</button>';
    }

    function mostraNota() {
        var l = voce(nota);
        if (!l) return;
        $('knownFlag').src = bandiera(l.f);
        $('knownName').textContent = l.n;
    }

    function apriAggiungi() {
        modo = 'impara';
        $('knownRow').classList.remove('is-open');
        $('lngSearch').value = '';
        $('lngSearch').placeholder = T('fchub.cerca_lingua');
        elenco('');
        apri($('sheetAdd'));
        /* La tastiera si apre da sola solo dove c'e' una tastiera vera: sul
           telefono coprirebbe proprio le lingue proposte. */
        if (window.matchMedia && window.matchMedia('(pointer:fine)').matches) {
            setTimeout(function () { $('lngSearch').focus(); }, 250);
        }
        buzz(8);
    }

    function scegli(codice) {
        if (modo === 'nota') {
            nota = codice;
            mostraNota();
            modo = 'impara';
            $('knownRow').classList.remove('is-open');
            $('lngSearch').value = '';
            $('lngSearch').placeholder = T('fchub.cerca_lingua');
            elenco('');
            return;
        }
        var gia = corsoGia(codice);
        if (gia) { location.href = 'fc_decks.php?course=' + gia.id; return; }
        var l = voce(codice);
        if (!l || l.b === nota) return;
        aggiungi(codice);
    }

    function aggiungi(codice) {
        if (inviando) return;
        inviando = true;
        buzz(12);
        var corpo = new URLSearchParams();
        corpo.set('action', 'add'); corpo.set('learn', codice); corpo.set('known', nota);
        document.querySelectorAll('[data-code="' + codice + '"]').forEach(function (b) { b.classList.add('is-loading'); });
        fetch('fc_languages.php', { method: 'POST', body: corpo, credentials: 'same-origin',
                                    headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json().catch(function () { return { ok: false }; }); })
            .then(function (j) {
                if (j && j.ok && j.url) { location.href = j.url; return; }
                inviando = false;
                document.querySelectorAll('.is-loading').forEach(function (b) { b.classList.remove('is-loading'); });
                toast((j && j.message) || T('fchub.err_rete'));
            })
            .catch(function () {
                inviando = false;
                document.querySelectorAll('.is-loading').forEach(function (b) { b.classList.remove('is-loading'); });
                toast(T('fchub.err_rete'));
            });
    }

    var cerca = $('lngSearch');
    if (cerca) {
        cerca.addEventListener('input', function () { elenco(cerca.value); });
        /* Invio sceglie la prima: si scrive "ted", Invio, ed e' fatto. Le
           frecce si muovono nell'elenco come in un menu vero. */
        cerca.addEventListener('keydown', function (e) {
            var righe = Array.prototype.slice.call(document.querySelectorAll('#lngList .lng-row:not(.is-disabled)'));
            var att = document.querySelector('#lngList .lng-row.is-active');
            var i = righe.indexOf(att);
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                if (!righe.length) return;
                i = e.key === 'ArrowDown' ? Math.min(righe.length - 1, i + 1) : Math.max(0, i - 1);
                righe.forEach(function (r) { r.classList.remove('is-active'); });
                righe[i].classList.add('is-active');
                righe[i].scrollIntoView({ block: 'nearest' });
            } else if (e.key === 'Enter') {
                e.preventDefault();
                var r = att || righe[0];
                if (r) scegli(r.getAttribute('data-code'));
            }
        });
    }

    var kr = $('knownRow');
    if (kr) kr.addEventListener('click', function () {
        modo = modo === 'nota' ? 'impara' : 'nota';
        kr.classList.toggle('is-open', modo === 'nota');
        cerca.value = '';
        cerca.placeholder = T(modo === 'nota' ? 'fchub.cerca_lingua_nota' : 'fchub.cerca_lingua');
        elenco('');
        buzz(6);
    });

    document.addEventListener('click', function (e) {
        var b = e.target.closest('#lngList [data-code], #popVuoto [data-code]');
        if (!b || b.classList.contains('is-disabled')) return;
        scegli(b.getAttribute('data-code'));
    });

    ['btnAggiungi', 'btnAggiungiCard', 'btnAggiungiVuoto'].forEach(function (id) {
        var b = $(id); if (b) b.addEventListener('click', apriAggiungi);
    });

    /* Pagina vuota: le lingue piu' studiate sono gia' li', si sceglie senza
       aprire niente. */
    var pv = $('popVuoto');
    if (pv) {
        pv.innerHTML = D.popolari.map(voce).filter(function (l) { return l && l.b !== nota; }).slice(0, 9).map(tessera).join('');
    }

    /* ------------------------------------------------------------------
       MENU DI UN CORSO
       ------------------------------------------------------------------ */
    var corrente = null;

    function post(dati) {
        var corpo = new URLSearchParams();
        Object.keys(dati).forEach(function (k) { corpo.set(k, dati[k]); });
        return fetch('fc_languages.php', { method: 'POST', body: corpo, credentials: 'same-origin',
                                           headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json().catch(function () { return { ok: false }; }); });
    }

    document.addEventListener('click', function (e) {
        var b = e.target.closest('.lng-more');
        if (!b) return;
        e.preventDefault();
        corrente = b.closest('.lng-card');
        var id = corrente.getAttribute('data-id');
        $('moreTitolo').textContent = corrente.getAttribute('data-name');
        $('moreDecks').href = 'fc_decks.php?course=' + id;
        $('moreHistory').href = 'fc_history.php?course=' + id;
        $('moreVariant').style.display = corrente.getAttribute('data-variants') === '1' ? '' : 'none';
        $('moreProjectTxt').textContent = T(corrente.getAttribute('data-project') !== '0' ? 'fchub.progetto_collegato' : 'fchub.collega_progetto');
        $('moreProject').disabled = corrente.getAttribute('data-project') !== '0';
        apri($('sheetMore'));
        buzz(8);
    });

    var mp = $('moreProject');
    if (mp) mp.addEventListener('click', function () {
        if (!corrente || mp.disabled) return;
        post({ action: 'project', course: corrente.getAttribute('data-id') }).then(function (j) {
            if (j && j.ok) {
                corrente.setAttribute('data-project', String(j.project_id));
                chiudi($('sheetMore'));
                toast(T('fchub.progetto_ok'));
            } else toast((j && j.message) || T('fchub.err_rete'));
        });
    });

    /* Cambiare accento: dentro lo stesso foglio, al posto delle azioni,
       compaiono le varianti di quella lingua. */
    var mv = $('moreVariant');
    if (mv) mv.addEventListener('click', function () {
        if (!corrente) return;
        var attuale = corrente.getAttribute('data-learn');
        var base = attuale.split('-')[0];
        var varianti = D.catalogo.filter(function (l) { return l.b === base; });
        var box = document.createElement('div');
        box.className = 'row-actions';
        box.innerHTML = varianti.map(function (l) {
            return '<button type="button" class="row-action" data-var="' + esc(l.c) + '">'
                 + '<img class="flag flag-xs" src="' + bandiera(l.f) + '" alt="">'
                 + '<span>' + esc(l.n + (l.v ? ' (' + l.v + ')' : '')) + '</span>'
                 + (l.c === attuale ? '<svg class="lr-check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12 5 5L20 7"/></svg>' : '')
                 + '</button>';
        }).join('');
        var vecchio = $('sheetMore').querySelector('.row-actions');
        vecchio.style.display = 'none';
        vecchio.parentNode.appendChild(box);
        box.addEventListener('click', function (ev) {
            var v = ev.target.closest('[data-var]');
            if (!v) return;
            var nuova = v.getAttribute('data-var');
            if (nuova === attuale) { chiudi($('sheetMore')); return; }
            post({ action: 'variant', course: corrente.getAttribute('data-id'), learn: nuova }).then(function (j) {
                if (j && j.ok) location.reload();
                else toast((j && j.message) || T('fchub.err_rete'));
            });
        });
        /* richiudendo, il foglio deve tornare quello delle azioni */
        var ripristina = function () { box.remove(); vecchio.style.display = ''; };
        setTimeout(function () {
            var oss = new MutationObserver(function () {
                if ($('sheetMore').getAttribute('aria-hidden') === 'true') { ripristina(); oss.disconnect(); }
            });
            oss.observe($('sheetMore'), { attributes: true, attributeFilter: ['aria-hidden'] });
        }, 0);
    });

    var md = $('moreDelete');
    if (md) md.addEventListener('click', function () {
        if (!corrente) return;
        var n = parseInt(corrente.getAttribute('data-words'), 10) || 0;
        $('delTitolo').textContent = T('fchub.elimina_titolo', { lingua: corrente.getAttribute('data-name') });
        $('delTesto').textContent = n > 0 ? T(n === 1 ? 'fchub.elimina_testo_1' : 'fchub.elimina_testo_n', { n: n })
                                          : T('fchub.elimina_testo_vuoto');
        chiudi($('sheetMore'));
        setTimeout(function () { apri($('sheetDelete')); }, 230);
    });

    var dc = $('delConferma');
    if (dc) dc.addEventListener('click', function () {
        if (!corrente || dc.disabled) return;
        dc.disabled = true;
        post({ action: 'delete', course: corrente.getAttribute('data-id') }).then(function (j) {
            dc.disabled = false;
            if (j && j.ok) location.reload();
            else toast((j && j.message) || T('fchub.err_rete'));
        });
    });

    mostraNota();

    /* Arrivando da "Aggiungi lingua" della pagina principale il foglio si apre
       da solo: chi ha toccato quel tasto vuole scegliere, non guardare. */
    if (location.hash === '#add') {
        try { history.replaceState(null, '', location.pathname + location.search); } catch (e) {}
        apriAggiungi();
    }
})();
