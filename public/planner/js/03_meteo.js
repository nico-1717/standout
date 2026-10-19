/* planner/js/03_meteo.js — meteo (Open-Meteo), grafico ora per ora, cambio del luogo.
   NON e' un file a se': e' un pezzo della funzione unica del planner, che
   apre in 01_stato_e_server.js e chiude in 13_avvio.js. planner_js.php li
   unisce in ordine e li manda al browser come un file solo. */
/* ------------------------------------------------------------------ */
/* Meteo (Open-Meteo, dati CC BY 4.0)                                  */
/* ------------------------------------------------------------------ */
/* `manuale` dice che il luogo l'ha scelto l'utente dal popup, non il
   telefono. DECISIONE (la stessa è scritta anche nell'endpoint `preview` di
   meteo_api.php): quel luogo NON si salva da nessuna parte. Vive nella
   pagina aperta e muore ricaricandola, o spegnendo e riaccendendo il meteo.
   È controintuitivo — di solito quello che si sceglie resta — ma è quello
   che serve: cambiare posto qui vuol dire sbirciare che tempo fa dove si
   andrà domani, non trasferirsi. In archivio `weather_lat/lon/place`
   continuano a dire dove si è davvero. */
var WX = { on: false, giorni: {}, adesso: null, place: null, lat: null, lon: null, fonte: null, fonte_url: null, nota: null, manuale: false };

var WX_ICONE = {
    'sole':            '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><circle cx="12" cy="12" r="4"/><path d="M12 3v2M12 19v2M3 12h2M19 12h2M5.6 5.6l1.4 1.4M17 17l1.4 1.4M5.6 18.4L7 17M17 7l1.4-1.4"/></svg>',
    'sole-nubi':       '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><circle cx="8.5" cy="8.5" r="3"/><path d="M8.5 3.5v1.4M3.5 8.5h1.4M5 5l1 1"/><path d="M12 19h6a2.6 2.6 0 100-5.2 3.4 3.4 0 00-6.5-1A2.4 2.4 0 0012 19z"/></svg>',
    'nubi':            '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M7 18h10.5a3 3 0 100-6 4 4 0 00-7.7-1.2A2.9 2.9 0 007 18z"/></svg>',
    'nebbia':          '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M4 10h16M6 14h12M4 18h16"/></svg>',
    'pioggia-leggera': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M7 15h10.5a3 3 0 100-6 4 4 0 00-7.7-1.2A2.9 2.9 0 007 15z"/><path d="M10 18.5v1.5M14 18.5v1.5"/></svg>',
    'pioggia':         '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M7 14h10.5a3 3 0 100-6 4 4 0 00-7.7-1.2A2.9 2.9 0 007 14z"/><path d="M9 17v3M12.5 17v3M16 17v3"/></svg>',
    'pioggia-forte':   '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M7 13h10.5a3 3 0 100-6 4 4 0 00-7.7-1.2A2.9 2.9 0 007 13z"/><path d="M8 16v4.5M12 16v4.5M16 16v4.5"/></svg>',
    'neve':            '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M7 13h10.5a3 3 0 100-6 4 4 0 00-7.7-1.2A2.9 2.9 0 007 13z"/><path d="M9.5 17.5h.01M13 19h.01M16 17.5h.01"/></svg>',
    'temporale':       '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M7 13h10.5a3 3 0 100-6 4 4 0 00-7.7-1.2A2.9 2.9 0 007 13z"/><path d="M13 15l-3 4h3l-1 3"/></svg>',
    /* Le due notturne. Non arrivano mai dal server — il servizio manda solo
       'sole' e 'sole-nubi' — ma ci arriva sopra `wxIconaNome()` quando l'ora
       di cui si sta parlando cade fra il tramonto e l'alba. Sono qui e non in
       WXA_SVG perché quelle sono le sagome della scena della barra, disegnate
       piene: qui serve lo stesso tratto delle altre icone, se no la luna
       stonerebbe accanto a una nuvola. */
    'luna':            '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20 14.6A8.6 8.6 0 019.4 4a7.6 7.6 0 1010.6 10.6z"/></svg>',
    'luna-nubi':       '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12.6 9.4A5.1 5.1 0 016.2 3a4.5 4.5 0 106.4 6.4z"/><path d="M12 19h6a2.6 2.6 0 100-5.2 3.4 3.4 0 00-6.5-1A2.4 2.4 0 0012 19z"/></svg>'
};
function wxClasse(icona) {
    if (icona === 'sole' || icona === 'sole-nubi') return ' pl-wx-sun';
    /* la notte ha un colore suo: il giallo del sole su una luna sembra un
       errore di stampa, e il grigio delle nuvole non distingue più niente */
    if (icona === 'luna' || icona === 'luna-nubi') return ' pl-wx-moon';
    if (icona === 'neve') return ' pl-wx-snow';
    if (icona === 'temporale') return ' pl-wx-storm';
    if (icona.indexOf('pioggia') === 0) return ' pl-wx-rain';
    return '';
}

/* Fra il tramonto e l'alba il sole non c'è: disegnarlo lo stesso faceva
   sembrare pieno giorno un cielo che a quell'ora è soltanto sereno e buio.
   Cambiano solo le due icone che hanno il sole dentro — la pioggia delle
   undici di sera resta pioggia come quella di mezzogiorno.

   `minuti` è l'ora di cui si sta parlando, contata dalla mezzanotte. Quando
   manca vuol dire "il riassunto della giornata": su oggi vale l'ora corrente,
   come già fa la scena della barra in alto, mentre sugli altri giorni si
   resta di giorno, perché il riassunto di un giorno intero non ha un'ora sua
   e mostrarlo con la luna direbbe una cosa falsa.

   Alba e tramonto si prendono dal giorno *di cui si parla* e non da oggi: a
   dicembre il buio arriva due ore prima che a giugno, e una soglia fissa
   avrebbe sbagliato metà anno. */
function wxIconaNome(nome, date, minuti) {
    if (nome !== 'sole' && nome !== 'sole-nubi') return nome;
    if (minuti === null || minuti === undefined) {
        if (date !== S.today) return nome;
        var adesso = new Date();
        minuti = adesso.getHours() * 60 + adesso.getMinutes();
    }
    var g = WX.giorni[date];
    /* se il servizio non manda alba e tramonto restano le sei e le venti:
       sbagliano di poco e mai di molto, ed è meglio di non decidere */
    var alba = (g && g.alba) ? toMin(g.alba) : 6 * 60;
    var tramonto = (g && g.tramonto) ? toMin(g.tramonto) : 20 * 60;
    if (minuti >= alba && minuti < tramonto) return nome;
    return nome === 'sole' ? 'luna' : 'luna-nubi';
}
function wxIcona(nome, date, minuti) {
    var n = wxIconaNome(nome, date, minuti);
    return WX_ICONE[n] || WX_ICONE.nubi;
}

function wxCall(action, data) {
    var opt = { method: data ? 'POST' : 'GET', credentials: 'same-origin', headers: { 'X-CSRF-Token': S.csrf } };
    if (data) { opt.headers['Content-Type'] = 'application/json'; opt.body = JSON.stringify(data); }
    return fetch('meteo_api.php?ajax=' + action, opt)
        .then(function (r) { return r.json(); })
        .catch(function () { return { ok: false }; });
}

function wxApply(r) {
    if (!r || !r.ok) return;
    WX.on = !!r.enabled;
    WX.giorni = r.giorni || {};
    WX.adesso = r.adesso || null;
    /* La posizione arriva quando si accende il meteo; se un aggiornamento
       successivo non la ripete, quella di prima resta buona invece di
       sparire dal popup. */
    /* Con il luogo scelto a mano il nome va scritto anche quando è vuoto: se
       di quel punto non si sa il nome, tenere quello di prima direbbe che la
       previsione è di una città in cui non si sta guardando. */
    if (r.manuale) WX.place = r.place || null;
    else if (r.place) WX.place = r.place;
    if (r.lat !== undefined && r.lat !== null) { WX.lat = r.lat; WX.lon = r.lon; }
    WX.manuale = !!r.manuale;
    WX.fonte = r.fonte || null;
    WX.fonte_url = r.fonte_url || null;
    if (r.nota_affidabilita) WX.nota = r.nota_affidabilita;
    $('plWeatherBtn').classList.toggle('pl-weather-on', WX.on);
    /* scena, tag del giorno e tag nella colonna degli orari: renderDays() da
       solo non li tocca tutti, e appena acceso il meteo i gradi orari
       comparivano solo al ridisegno dopo */
    wxRedraw();
}

/* Se il luogo è stato scelto a mano, ricaricare vuol dire ricaricare
   *quello*: chiedere `status` riporterebbe la posizione rilevata e il posto
   scelto sparirebbe da sotto le mani senza che nessuno l'abbia chiesto. */
function wxLoad() {
    if (WX.manuale && WX.lat !== null && WX.lat !== undefined) {
        return wxCall('preview', { lat: WX.lat, lon: WX.lon, place: WX.place || '' }).then(wxApply);
    }
    return wxCall('status').then(wxApply);
}

/* Il tag da mettere in una cella: solo per i giorni che abbiamo. */
function wxTag(date) {
    if (!WX.on) return null;
    var g = WX.giorni[date];
    if (!g) return null;
    /* niente minuti: è il riassunto del giorno, e wxIconaNome() sa già che
       su oggi vuol dire "adesso" e sugli altri giorni vuol dire "di giorno" */
    var nome = wxIconaNome(g.icon, date, null);
    var b = el('button', 'pl-wx' + wxClasse(nome));
    b.type = 'button';
    b.innerHTML = WX_ICONE[nome] || WX_ICONE.nubi;
    var t = wxTagTesto(date, g);
    if (t) b.appendChild(el('span', null, t));
    b.setAttribute('aria-label', g.label + (t ? ' · ' + t : ''));
    b.addEventListener('click', function (e) { e.stopPropagation(); wxPopup(date); });
    return b;
}

/* I gradi accanto all'icona. Guardando un giorno solo c'è posto per dire
   tutto: su oggi prima quanto fa adesso e poi la forbice della giornata, sugli
   altri giorni la forbice e basta. Con più giorni davanti i tag stanno in fila
   e resta spazio per un numero solo, che è la massima. */
function wxTagTesto(date, g) {
    var pezzi = [];
    var uno = visibleDays() === 1 && S.view !== 'month';
    if (uno && date === S.today && WX.adesso
        && WX.adesso.temp !== null && WX.adesso.temp !== undefined) {
        pezzi.push(WX.adesso.temp + '°');
    }
    var min = g.tmin, max = g.tmax;
    if (uno && min !== null && min !== undefined && max !== null && max !== undefined) {
        pezzi.push(min + '/' + max + '°');
    } else if (max !== null && max !== undefined) {
        pezzi.push(max + '°');
    }
    return pezzi.join(' · ');
}

/* L'indice UV da solo non dice niente: 7 è tanto o poco? */
function wxUvTesto(uv) {
    if (uv === null || uv === undefined) return null;
    if (uv < 3) return T('planner.uv_basso');
    if (uv < 6) return T('planner.uv_moderato');
    if (uv < 8) return T('planner.uv_alto');
    if (uv < 11) return T('planner.uv_molto_alto');
    return T('planner.uv_estremo');
}
/* minuti → "6 h 20 min": più leggibile di 380 */
function wxDurata(min) {
    if (min === null || min === undefined) return null;
    var h = Math.floor(min / 60), m = min % 60;
    if (!h) return T('planner.n_min', {n: m});
    return h + ' h' + (m ? ' ' + m + ' min' : '');
}

/* La striscia ora per ora. Per oggi parte dall'ora corrente: le ore già
   passate occuperebbero il posto migliore per niente. */
/* ------------------------------------------------------------------ */
/* Il grafico ora per ora                                              */
/* ------------------------------------------------------------------ */
/* Una striscia di caselle diceva i numeri ma non l'andamento: per capire se
   la giornata si sta scaldando o raffreddando bisognava leggerli tutti e
   confrontarli a mente. La curva lo dice a colpo d'occhio, e i numeri
   restano lì sotto per chi li vuole.

   Ci sono tutte le ventiquattro ore, non solo quelle che restano: si scorre
   in orizzontale, e aprendo il popup su oggi la vista si porta da sé
   sull'ora corrente. */
var WXC = { w: 54, h: 84, pad: 13 };

function wxGrafico(g, date) {
    if (!g.ore || !g.ore.length) return null;
    var ore = g.ore, W = WXC.w, H = WXC.h;
    var largh = ore.length * W;
    var oraBase = parseInt(ore[0].h, 10) || 0;
    var adesso = date === S.today ? new Date().getHours() : -1;

    /* la scala verticale si adatta alla giornata: con l'escursione di un
       giorno d'agosto schiacciata su un asse fisso 0–40 la curva sarebbe
       piatta e non racconterebbe niente */
    var temp = ore.map(function (o) { return o.t; }).filter(function (t) { return t !== null && t !== undefined; });
    var tMin = Math.min.apply(null, temp), tMax = Math.max.apply(null, temp);
    if (!temp.length) { tMin = 0; tMax = 1; }
    if (tMax - tMin < 3) { tMax = tMin + 3; }   // giornate senza sbalzi: un minimo di respiro

    function xDi(i) { return i * W + W / 2; }
    function yDi(t) { return H - WXC.pad - ((t - tMin) / (tMax - tMin)) * (H - WXC.pad * 2); }

    /* `pl-wxh` resta il nome di fuori: è quello che lo sfoglia-giorni del
       popup riconosce per non rubare lo scorrimento orizzontale a questo. */
    var box = el('div', 'pl-wxh pl-wxc');
    var dentro = el('div', 'pl-wxc-in');
    dentro.style.width = largh + 'px';

    /* Dove cade sull'asse un certo orario. Vale per alba, tramonto e per la
       riga di adesso: tutte e tre stanno dove stanno davvero, non sull'ora
       più vicina. */
    function xDiOra(hhmm) {
        if (!hhmm) return null;
        return (toMin(hhmm) / 60 - oraBase) * W + W / 2;
    }

    /* --- riga di sopra: le ore --- */
    var su = el('div', 'pl-wxc-riga');
    ore.forEach(function (o) {
        var c = el('div', 'pl-wxc-cell' + (parseInt(o.h, 10) === adesso ? ' pl-ora' : ''));
        c.style.width = W + 'px';
        /* l'ora e basta, anche sulla colonna di adesso: a dire dove siamo
           c'è già la riga verticale, e la parola al posto del numero toglieva
           l'unica cosa che quella colonna doveva dire */
        c.appendChild(el('div', 'pl-wxc-h', parseInt(o.h, 10) + ''));
        su.appendChild(c);
    });
    dentro.appendChild(su);

    /* --- riga delle icone, con la fascia del giorno e della notte ---
       La fascia era il fondo delle icone stesse, quindi cambiava colore al
       bordo della casella: con il tramonto alle 20:45 il blu cominciava alle
       20:30, mezz'ora prima del buio. Ora è una sfumatura a gradini netti
       messa sotto tutta la riga, e i due gradini cadono esattamente dove
       cadono le righe tratteggiate dell'alba e del tramonto. */
    var righeIc = el('div', 'pl-wxc-riga pl-wxc-icone');
    var xAlba = xDiOra(g.alba), xTram = xDiOra(g.tramonto);
    var GIORNO = '#F8EFD4', NOTTE = '#E3E6F1';
    var dA = (xAlba === null) ? 0 : Math.max(0, Math.min(largh, xAlba));
    var dT = (xTram === null) ? largh : Math.max(0, Math.min(largh, xTram));
    if (dT < dA) dT = dA;
    var tappe = [];
    function tratto(da, a, colore) {
        if (a <= da) return;
        tappe.push(colore + ' ' + da.toFixed(1) + 'px', colore + ' ' + a.toFixed(1) + 'px');
    }
    tratto(0, dA, NOTTE);
    tratto(dA, dT, GIORNO);
    tratto(dT, largh, NOTTE);
    if (tappe.length) righeIc.style.background = 'linear-gradient(to right, ' + tappe.join(', ') + ')';

    ore.forEach(function (o) {
        var c = el('div', 'pl-wxc-cell');
        c.style.width = W + 'px';
        /* qui l'ora c'è, ed è quella della casella: le colonne dopo il
           tramonto passano alla luna una per una, come nella realtà */
        var iNome = wxIconaNome(o.i, date, parseInt(o.h, 10) * 60);
        var ic = el('div', 'pl-wxc-ic' + wxClasse(iNome));
        ic.innerHTML = WX_ICONE[iNome] || WX_ICONE.nubi;
        c.appendChild(ic);
        righeIc.appendChild(c);
    });
    dentro.appendChild(righeIc);

    /* --- la curva --- */
    var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('class', 'pl-wxc-svg');
    svg.setAttribute('width', largh);
    svg.setAttribute('height', H);
    svg.setAttribute('viewBox', '0 0 ' + largh + ' ' + H);

    /* Le etichette di alba e tramonto stanno in un contenitore insieme alla
       curva, non nel grafico intero: appese in cima a tutto finirebbero
       sopra i nomi delle ore. */
    var area = el('div', 'pl-wxc-area');

    function tag(nome, attrs) {
        var n = document.createElementNS('http://www.w3.org/2000/svg', nome);
        Object.keys(attrs).forEach(function (k) { n.setAttribute(k, attrs[k]); });
        return n;
    }

    var punti = [];
    ore.forEach(function (o, i) {
        svg.appendChild(tag('line', { x1: xDi(i), y1: 0, x2: xDi(i), y2: H, class: 'pl-wxc-guida' }));
        if (o.t === null || o.t === undefined) return;
        punti.push({ x: xDi(i), y: yDi(o.t), i: i });
    });

    /* Alba e tramonto: una riga tratteggiata dove cadono davvero, non
       incollata all'ora più vicina — un tramonto alle 20:41 non è alle 21. */
    [['alba', g.alba, T('planner.alba')], ['tramonto', g.tramonto, T('planner.tramonto')]].forEach(function (v) {
        if (!v[1]) return;
        var x = (toMin(v[1]) / 60 - oraBase) * W + W / 2;
        if (x < 0 || x > largh) return;
        svg.appendChild(tag('line', { x1: x, y1: 0, x2: x, y2: H, class: 'pl-wxc-sole pl-wxc-' + v[0] }));
        var et = el('div', 'pl-wxc-et pl-wxc-et-' + v[0], (v[0] === 'alba' ? '↑ ' : '↓ ') + v[1]);
        et.style.left = x + 'px';
        area.appendChild(et);
    });

    /* La riga di adesso, solo su oggi. Le colonne dicono le ore tonde, ma
       quello che si cerca guardando il grafico è dove si è in questo momento:
       senza una riga bisognava contare a mente fra le nove e le dieci. Sta
       dove sta davvero, come alba e tramonto. */
    if (date === S.today) {
        var oraOra = new Date();
        var xOra = (oraOra.getHours() + oraOra.getMinutes() / 60 - oraBase) * W + W / 2;
        if (xOra >= 0 && xOra <= largh) {
            svg.appendChild(tag('line', { x1: xOra, y1: 0, x2: xOra, y2: H, class: 'pl-wxc-adesso' }));
            var eo = el('div', 'pl-wxc-et pl-wxc-et-adesso', T('planner.adesso'));
            eo.style.left = xOra + 'px';
            area.appendChild(eo);
        }
    }

    if (punti.length > 1) {
        /* curva morbida: ogni tratto è una cubica con le maniglie a metà
           strada, che è il modo più semplice per non avere spigoli sui punti */
        var d = 'M' + punti[0].x + ',' + punti[0].y.toFixed(1);
        for (var k = 1; k < punti.length; k++) {
            var a = punti[k - 1], b = punti[k], mx = (a.x + b.x) / 2;
            d += ' C' + mx + ',' + a.y.toFixed(1) + ' ' + mx + ',' + b.y.toFixed(1)
               + ' ' + b.x + ',' + b.y.toFixed(1);
        }
        svg.appendChild(tag('path', { d: d, class: 'pl-wxc-curva' }));
    }
    punti.forEach(function (p) {
        svg.appendChild(tag('circle', {
            cx: p.x, cy: p.y.toFixed(1), r: ore[p.i] && parseInt(ore[p.i].h, 10) === adesso ? 5 : 3.6,
            class: 'pl-wxc-punto'
        }));
    });
    area.appendChild(svg);
    dentro.appendChild(area);

    /* --- riga di sotto: gradi e pioggia --- */
    var giu = el('div', 'pl-wxc-riga');
    ore.forEach(function (o) {
        var c = el('div', 'pl-wxc-cell' + (parseInt(o.h, 10) === adesso ? ' pl-ora' : ''));
        c.style.width = W + 'px';
        c.appendChild(el('div', 'pl-wxc-t', (o.t !== null && o.t !== undefined) ? o.t + '°' : '—'));
        /* la probabilità si scrive solo quando c'è: una colonna di zeri
           riempirebbe il grafico di rumore */
        c.appendChild(el('div', 'pl-wxc-p', o.p ? o.p + '%' : ''));
        c.title = o.l + (o.ap !== null && o.ap !== undefined ? T('planner.percepiti') + o.ap + '°' : '')
                + (o.v !== null && o.v !== undefined ? T('planner.vento_lbl') + o.v + T('planner.km_h') : '')
                + (o.u !== null && o.u !== undefined ? T('planner.wx_umidita', {n: o.u}) : '');
        giu.appendChild(c);
    });
    dentro.appendChild(giu);

    box.appendChild(dentro);
    /* su oggi si parte da adesso, ma indietro si può tornare: le ore passate
       ci sono tutte, semplicemente non sono quelle che interessano per prime */
    if (adesso >= 0) {
        setTimeout(function () {
            box.scrollLeft = Math.max(0, (adesso - oraBase) * W - W);
        }, 0);
    }
    return box;
}

/* Su quale posto è calcolata la previsione. Il nome se il server è riuscito
   a trovarlo, altrimenti le coordinate: sono meno belle da leggere ma sono
   pur sempre una risposta, e "non lo so" non lo è. */
function wxDove() {
    if (WX.place) return WX.place;
    if (WX.lat !== null && WX.lat !== undefined && WX.lon !== null && WX.lon !== undefined) {
        return WX.lat.toFixed(2).replace('.', ',') + ' / ' + WX.lon.toFixed(2).replace('.', ',');
    }
    return null;
}

/* Un giorno avanti o indietro dentro il popup del meteo: si riscrive il
   contenuto di quello aperto invece di impilarne un secondo sopra. */
function wxVaiA(date, passo) {
    var nuovo = addDays(date, passo);
    if (!WX.giorni[nuovo]) return;
    wxPopup(nuovo);
}

/* ------------------------------------------------------------------ */
/* Cambio del luogo dal popup                                          */
/* ------------------------------------------------------------------ */
/* Il pannellino di ricerca vive dentro la testa del popup, non in un foglio
   suo: aprire un secondo foglio sopra il primo avrebbe voluto dire perdere
   di vista la previsione che si sta guardando, che è proprio la cosa che si
   vuole confrontare con quella dell'altro posto. */
function wxCambiaLuogo(date, testa) {
    var aperto = testa.querySelector('.pl-wx-cerca');
    /* secondo tocco sulla posizione: si richiude, come ci si aspetta da una
       cosa che si è aperta toccandola */
    if (aperto) { aperto.parentNode.removeChild(aperto); return; }

    var box = el('div', 'pl-wx-cerca');
    var inp = el('input', 'pl-in');
    inp.type = 'search';
    inp.placeholder = T('planner.citta_o_paese');
    inp.maxLength = 60;
    box.appendChild(inp);

    var res = el('div', 'pl-wx-cerca-res');
    box.appendChild(res);

    /* La via del ritorno c'è solo quando serve. Non si tiene in memoria la
       posizione vera: la sa il server, che non l'ha mai cambiata, e
       richiederla è più onesto che fidarsi di una copia. */
    if (WX.manuale) {
        var back = el('button', 'pl-wx-cerca-back', T('planner.torna_alla_posizione_rilevata'));
        back.type = 'button';
        back.addEventListener('click', function () { wxPosizioneVera(date); });
        box.appendChild(back);
    }

    testa.appendChild(box);
    inp.focus();

    inp.addEventListener('input', function () {
        var q = inp.value.trim();
        if (q.length < 3) { res.innerHTML = ''; return; }
        /* si aspetta che smetta di scrivere: una chiamata per lettera
           battuta sarebbe otto chiamate per «milano» */
        debounceSave('wxluogo', 350, function () {
            wxCall('place_search', { q: q }).then(function (r) {
                res.innerHTML = '';
                if (!r || !r.ok || !r.luoghi || !r.luoghi.length) {
                    res.appendChild(el('div', 'pl-row-sub', T('planner.nessun_posto_con_questo_nome')));
                    return;
                }
                r.luoghi.forEach(function (p) {
                    var b = el('button', 'pl-wx-cerca-row');
                    b.type = 'button';
                    b.appendChild(el('span', 'pl-row-lab', p.nome));
                    if (p.dove) b.appendChild(el('span', 'pl-row-sub', p.dove));
                    b.addEventListener('click', function () {
                        wxUsaLuogo(p.lat, p.lon, p.nome, date);
                    });
                    res.appendChild(b);
                });
            });
        });
    });
}

/* Il posto scelto vale per la pagina aperta e basta: niente scrittura in
   archivio, né qui né nell'endpoint. Vedi il commento su WX.manuale. */
function wxUsaLuogo(lat, lon, nome, date) {
    wxCall('preview', { lat: lat, lon: lon, place: nome || '' }).then(function (r) {
        if (!r || !r.ok) { toast(T('planner.previsione_non_disponibile_per_quel')); return; }
        wxApply(r);
        /* si riapre sullo stesso giorno se in quel posto c'è ancora: la
           finestra dei giorni è la stessa dappertutto, ma su un giorno vuoto
           il popup non avrebbe niente da dire */
        wxPopup(WX.giorni[date] ? date : S.today);
        toast(T('planner.previsione_per', {posto: (r.place || T('planner.il_posto_scelto'))}));
    });
}

function wxPosizioneVera(date) {
    wxCall('status').then(function (r) {
        if (!r || !r.ok) return;
        wxApply(r);
        wxPopup(WX.giorni[date] ? date : S.today);
        toast(T('planner.torno_alla_posizione_rilevata'));
    });
}

function wxPopup(date) {
    var g = WX.giorni[date];
    if (!g) return;
    var d = parseIso(date);
    openSheet(T('planner.meteo'), function (root) {
        /* Tutto dentro un contenitore nostro invece che direttamente nel
           corpo del popup: gli ascoltatori dello scorrimento se ne vanno con
           lui quando il contenuto si riscrive, e non si accumulano a ogni
           giorno sfogliato. */
        var body = el('div', 'pl-wx-sheet');
        root.appendChild(body);

        /* Giorno e posizione restano in vista mentre si scorre: sono la
           didascalia di tutto il resto — senza sapere di che giorno e di che
           posto si parla, i numeri qui sotto non vogliono dire niente — e
           scorrendo fino in fondo sparivano proprio mentre servivano.
           Si appiccica al riquadro che scorre, che è il corpo del foglio:
           qui non c'è nessuna griglia che possa trattenerlo dentro una cella
           come succedeva alla colonna delle ore. */
        var testa = el('div', 'pl-wx-testa');
        body.appendChild(testa);

        /* Che giorno è e su quale posto è calcolata: senza, la previsione è
           un numero che non si sa a cosa si riferisca. Le frecce servono su
           computer, dove lo scorrimento con il dito non c'è. */
        var nav = el('div', 'pl-wx-nav');
        var prima = el('button', 'pl-wx-nav-b');
        prima.type = 'button';
        prima.setAttribute('aria-label', T('planner.giorno_precedente'));
        prima.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M15 5l-7 7 7 7"/></svg>';
        var dopo = el('button', 'pl-wx-nav-b');
        dopo.type = 'button';
        dopo.setAttribute('aria-label', T('planner.giorno_successivo'));
        dopo.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M9 5l7 7-7 7"/></svg>';
        prima.disabled = !WX.giorni[addDays(date, -1)];
        dopo.disabled  = !WX.giorni[addDays(date, 1)];
        prima.addEventListener('click', function () { wxVaiA(date, -1); });
        dopo.addEventListener('click', function () { wxVaiA(date, 1); });
        nav.appendChild(prima);
        nav.appendChild(el('div', 'pl-label',
            GIORNI[dowIndex(date)] + ' ' + d.getDate() + ' ' + MESI[d.getMonth()].toLowerCase()));
        nav.appendChild(dopo);
        testa.appendChild(nav);

        /* La posizione su una riga sua e non appesa alla data: infilata lì in
           mezzo alle frecce spariva, ed era proprio la cosa che serviva
           sapere per fidarsi del numero dei gradi.
           Ed è un tasto: toccandola si cerca un altro posto. Il gesto è
           quello che viene da sé — si tocca quello che si vuole cambiare —
           e non serve un tastino in più in una testa già piena. */
        var dove = wxDove();
        var bDove = el('button', 'pl-wx-dove' + (WX.manuale ? ' pl-wx-dove-man' : ''));
        bDove.type = 'button';
        bDove.setAttribute('aria-label', T('planner.cambia_il_luogo_della_previsione'));
        bDove.appendChild(el('span', null, dove ? T('planner.previsione_per_breve', {posto: dove}) : T('planner.posizione_non_disponibile')));
        bDove.innerHTML += '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s7-5.6 7-11a7 7 0 10-14 0c0 5.4 7 11 7 11z"/><circle cx="12" cy="10" r="2.4"/></svg>';
        bDove.addEventListener('click', function () { wxCambiaLuogo(date, testa); });
        testa.appendChild(bDove);

        var big = el('div', 'pl-row');
        big.style.margin = '10px 0 14px';
        var ico = el('div');
        ico.style.cssText = 'width:44px;height:44px;color:var(--ink-2);flex-shrink:0';
        ico.innerHTML = wxIcona(g.icon, date, null);
        big.appendChild(ico);
        var txt = el('div');
        txt.appendChild(el('div', 'pl-sheet-title pl-serif', g.label));
        if (g.tmax !== null) {
            txt.appendChild(el('div', 'pl-row-sub',
                T('planner.massima', {t: g.tmax}) + (g.tmin !== null ? T('planner.minima') + g.tmin + '°' : '')));
        }
        if (g.percepita_max !== null && g.percepita_max !== undefined) {
            txt.appendChild(el('div', 'pl-row-sub',
                T('planner.percepiti_t', {t: g.percepita_max})
                + (g.percepita_min !== null && g.percepita_min !== undefined ? ' / ' + g.percepita_min + '°' : '')));
        }
        big.appendChild(txt);
        body.appendChild(big);

        /* Adesso: ha senso solo sul giorno di oggi, altrove sarebbe un dato
           che parla di un altro giorno. */
        if (date === S.today && WX.adesso && WX.adesso.temp !== null) {
            /* la riga di adesso ha un'ora precisa: quella del server se c'è,
               se no quella dell'orologio. Alle nove di sera qui ci va la luna. */
            var oMin = WX.adesso.ora ? toMin(WX.adesso.ora)
                                     : (new Date().getHours() * 60 + new Date().getMinutes());
            var oNome = wxIconaNome(WX.adesso.icon, date, oMin);
            var ora = el('div', 'pl-wx-now' + wxClasse(oNome));
            var oIco = el('span', 'pl-wx-now-ic');
            oIco.innerHTML = WX_ICONE[oNome] || WX_ICONE.nubi;
            ora.appendChild(oIco);
            var pezzi = [T('planner.adesso_t', {t: WX.adesso.temp})];
            if (WX.adesso.percepita !== null && WX.adesso.percepita !== undefined) pezzi.push('percepiti ' + WX.adesso.percepita + '°');
            if (WX.adesso.vento !== null && WX.adesso.vento !== undefined) pezzi.push(WX.adesso.vento + T('planner.km_h'));
            if (WX.adesso.umidita !== null && WX.adesso.umidita !== undefined) pezzi.push(T('planner.umidita_lbl') + WX.adesso.umidita + '%');
            ora.appendChild(el('span', null, WX.adesso.label + ' · ' + pezzi.join(' · ')));
            body.appendChild(ora);
        }

        var strip = wxGrafico(g, date);
        if (strip) {
            body.appendChild(el('div', 'pl-label', T('planner.ora_per_ora')));
            body.appendChild(strip);
        }

        function riga(k, v) {
            if (v === null || v === undefined || v === '') return;
            var r = el('div', 'pl-row-sb');
            r.appendChild(el('span', 'pl-row-lab', k));
            r.appendChild(el('span', 'pl-row-sub', v));
            body.appendChild(r);
        }
        riga(T('planner.probabilita_di_pioggia'), (g.pioggia !== null && g.pioggia !== undefined) ? g.pioggia + '%' : null);
        riga(T('planner.pioggia_prevista'), (g.pioggia_mm !== null && g.pioggia_mm !== undefined && g.pioggia_mm > 0)
            ? g.pioggia_mm + ' mm' + (g.pioggia_ore ? ' in ' + T(g.pioggia_ore === 1 ? 'planner.n_ora' : 'planner.n_ore', {n: g.pioggia_ore}) : '') : null);
        riga(T('planner.vento_massimo'), (g.vento !== null && g.vento !== undefined)
            ? g.vento + T('planner.km_h') + (g.vento_da ? ' da ' + g.vento_da : '') : null);
        riga(T('planner.raffiche_fino_a'), (g.raffiche !== null && g.raffiche !== undefined) ? g.raffiche + T('planner.km_h') : null);
        riga(T('planner.indice_uv'), (g.uv !== null && g.uv !== undefined) ? g.uv + ' (' + wxUvTesto(g.uv) + ')' : null);
        riga(T('planner.alba_e_tramonto'), g.alba && g.tramonto ? g.alba + ' — ' + g.tramonto : null);
        riga(T('planner.ore_di_sole'), wxDurata(g.sole_min));
        riga(T('planner.attendibilita_stimata'), g.affidabilita + '%');

        if (WX.nota) {
            var nota = el('div', 'pl-row-sub', WX.nota);
            nota.style.marginTop = '12px';
            body.appendChild(nota);
        }

        /* attribuzione: i dati sono CC BY 4.0, citare la fonte è dovuto */
        var fonte = el('div', 'pl-row-sub');
        fonte.style.marginTop = '14px';
        fonte.appendChild(document.createTextNode(T('planner.dati_lbl')));
        var a = el('a', null, WX.fonte || 'Open-Meteo');
        a.href = WX.fonte_url || 'https://open-meteo.com';
        a.target = '_blank';
        a.rel = 'noopener';
        a.style.color = 'var(--accent-ink)';
        fonte.appendChild(a);
        fonte.appendChild(document.createTextNode(T('planner.licenza')));
        body.appendChild(fonte);

        var off = el('button', 'pl-btn', T('planner.disattiva_il_meteo'));
        off.type = 'button';
        off.style.marginTop = '18px';
        off.addEventListener('click', function () {
            /* Si spegne subito e si avvisa il server dopo. Aspettando la
               risposta, tag e animazione restavano in vista per tutto il
               tempo del giro in rete: spegnere una cosa deve sembrare
               immediato, perché la decisione è già stata presa. */
            /* si torna anche alla posizione vera: riaccendendolo dopo, il
               posto sbirciato ieri sera non deve tornare da solo */
            WX.on = false; WX.giorni = {}; WX.adesso = null; WX.manuale = false;
            $('plWeatherBtn').classList.remove('pl-weather-on');
            closeOv('plSheetOv');
            wxRedraw();   // tornano l'albero e la bestia del mese
            wxCall('disable', {}).then(function () { toast(T('planner.meteo_disattivato')); });
        });
        body.appendChild(off);

        /* Scorrimento orizzontale per sfogliare i giorni. La striscia ora per
           ora scorre già per conto suo in orizzontale: se il dito parte da lì
           il gesto è suo, e rubarglielo renderebbe la striscia inservibile. */
        var sx = 0, sy = 0, vivo = false;
        body.addEventListener('pointerdown', function (e) {
            /* Dalla testa il gesto non parte: lì dentro ci sono il campo di
               ricerca del luogo e i suoi risultati, e trascinare per scegliere
               una parola cambiava giorno. */
            vivo = !(e.target.closest && e.target.closest('.pl-wxh, .pl-wx-testa'));
            sx = e.clientX; sy = e.clientY;
        });
        body.addEventListener('pointerup', function (e) {
            if (!vivo) return;
            vivo = false;
            var dx = e.clientX - sx, dy = e.clientY - sy;
            /* più orizzontale che verticale, se no si cambierebbe giorno
               ogni volta che si scorre il popup per leggerlo fino in fondo */
            /* soglia bassa: sfogliare i giorni è la cosa che si fa più
               spesso qui dentro, e chiederla con un gesto ampio la rendeva
               faticosa */
            if (Math.abs(dx) < 26 || Math.abs(dx) < Math.abs(dy)) return;
            wxVaiA(date, dx < 0 ? 1 : -1);
        });
    });
}

$('plWeatherBtn').addEventListener('click', function () {
    if (WX.on) {
        /* il tasto accende e spegne: le informazioni si aprono dai tag */
        WX.on = false; WX.giorni = {}; WX.adesso = null; WX.manuale = false;
        $('plWeatherBtn').classList.remove('pl-weather-on');
        wxRedraw();
        wxCall('disable', {}).then(function () { toast(T('planner.meteo_disattivato')); });
        return;
    }
    if (!navigator.geolocation) { wxChiediLuogo(T('planner.questo_browser_non_sa_dire')); return; }
    toast(T('planner.chiedo_la_posizione'));
    navigator.geolocation.getCurrentPosition(function (pos) {
        wxCall('enable', {
            lat: pos.coords.latitude,
            lon: pos.coords.longitude
        }).then(function (r) {
            if (!r.ok) { toast(T('planner.meteo_non_attivato')); return; }
            wxApply(r);
            toast(r.place ? T('planner.meteo_attivo_per', {posto: r.place}) : T('planner.meteo_attivo'));
        });
    }, function () {
        /* Il permesso negato non è la fine del discorso: il posto lo si sa,
           basta poterlo scrivere. Prima qui c'era solo il dispiacere, e
           l'unico modo di avere le previsioni era andare a cambiare i
           permessi del browser — cioè, in pratica, rinunciare. */
        wxChiediLuogo(T('planner.non_riesco_a_rilevare_la'));
    }, { timeout: 12000, maximumAge: 600000 });
});

/* Il luogo scritto a mano quando il telefono non lo dice. A differenza del
   cambio luogo dal popup, QUESTO si salva: non è una sbirciata a un'altra
   città, è la posizione che vale per questa pagina e per le prossime, e
   ritrovarsi il meteo spento a ogni ricarica sarebbe come non averlo. */
function wxChiediLuogo(perche) {
    /* Stesso foglio degli altri, costruito a mano perché è l'unico che nasce
       da un rifiuto e non da un tocco: non ha un posto suo in planner.php e
       non ne merita uno, visto che quasi sempre non serve. */
    var ov = el('div', 'pl-ov');
    ov.id = 'plWxLuogoOv';
    var sheet = el('div', 'pl-sheet pl-sheet-auto');
    sheet.appendChild(el('div', 'pl-grab'));
    var head = el('div', 'pl-sheet-head');
    head.appendChild(el('span', 'pl-sheet-title pl-serif', T('planner.dove_sei')));
    var chiudi = el('button', 'pl-txt-btn', T('planner.chiudi'));
    chiudi.type = 'button';
    chiudi.addEventListener('click', function () { ov.remove(); });
    head.appendChild(chiudi);
    sheet.appendChild(head);

    var body = el('div', 'pl-sheet-body');
    body.appendChild(el('div', 'pl-row-sub', perche + T('planner.scrivi_posto')));

    var box = el('div', 'pl-wx-cerca');
    var inp = el('input', 'pl-in');
    inp.type = 'search';
    inp.placeholder = T('planner.citta_o_paese');
    inp.maxLength = 60;
    box.appendChild(inp);
    var res = el('div', 'pl-wx-cerca-res');
    box.appendChild(res);
    body.appendChild(box);
    sheet.appendChild(body);
    ov.appendChild(sheet);
    document.body.appendChild(ov);
    /* la classe che lo fa salire va messa dopo l'inserimento, se no il foglio
       comparirebbe già su e la salita non si vedrebbe */
    requestAnimationFrame(function () { ov.classList.add('pl-open'); });
    setTimeout(function () { inp.focus(); }, 260);

    inp.addEventListener('input', function () {
        var q = inp.value.trim();
        if (q.length < 3) { res.innerHTML = ''; return; }
        debounceSave('wxluogo', 350, function () {
            wxCall('place_search', { q: q }).then(function (r) {
                res.innerHTML = '';
                if (!r || !r.ok || !r.luoghi || !r.luoghi.length) {
                    res.appendChild(el('div', 'pl-row-sub', T('planner.nessun_posto_con_questo_nome')));
                    return;
                }
                r.luoghi.forEach(function (p) {
                    var b = el('button', 'pl-wx-cerca-row');
                    b.type = 'button';
                    b.appendChild(el('span', 'pl-row-lab', p.nome));
                    if (p.dove) b.appendChild(el('span', 'pl-row-sub', p.dove));
                    b.addEventListener('click', function () {
                        /* `enable` e non `preview`: qui la posizione la si sta
                           dichiarando, non sbirciando */
                        wxCall('enable', { lat: p.lat, lon: p.lon }).then(function (rr) {
                            if (!rr || !rr.ok) { toast(T('planner.meteo_non_attivato')); return; }
                            ov.remove();
                            wxApply(rr);
                            toast(T('planner.meteo_attivo_per', {posto: (rr.place || p.nome)}));
                        });
                    });
                    res.appendChild(b);
                });
            });
        });
    });
}
