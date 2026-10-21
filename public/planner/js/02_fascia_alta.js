/* planner/js/02_fascia_alta.js — cielo e palette, meteo nella barra in alto, settimana, mese, mini calendario, affermazione.
   NON e' un file a se': e' un pezzo della funzione unica del planner, che
   apre in 01_stato_e_server.js e chiude in 13_avvio.js. planner_js.php li
   unisce in ordine e li manda al browser come un file solo. */
/* ------------------------------------------------------------------ */
/* Cielo, insetto, palette                                             */
/* ------------------------------------------------------------------ */
/* ---- alberi, foglie, insetti: una scena diversa per ogni mese ---- */
/* ambKey: che cosa c'è disegnato adesso nella barra ('mese|7', 'wx|2026-08-01|3').
   Serve a non ricostruire la scena a ogni ridisegno, ma solo quando cambia. */
var AMB = null, ambKey = null;


function svgWrap(inner, extra) {
    return '<svg viewBox="0 0 60 80" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round">'
         + inner + (extra || '') + '</svg>';
}
/* (treeMarkup, TREES e BUDS sono spariti: gli alberi non si disegnano più a
   codice, sono file dentro ambient/ — uno per mese, disegni veri invece di
   quattro curve approssimate.) */

var PART_SVG = {
    snow:  '<svg viewBox="0 0 10 10"><circle cx="5" cy="5" r="3.6" fill="currentColor"/></svg>',
    petal: '<svg viewBox="0 0 10 10"><path d="M5 .8C7.6 2.6 8.8 5 7.4 7.2 6.4 8.8 3.6 8.8 2.6 7.2 1.2 5 2.4 2.6 5 .8z" fill="currentColor"/></svg>',
    leaf:  '<svg viewBox="0 0 12 12"><path d="M11 1C6 1 1 4 1 8.2c0 1.6.8 2.6 2 2.8C4.6 8 7.4 5 11 1z" fill="currentColor"/></svg>',
    seed:  '<svg viewBox="0 0 12 12"><circle cx="6" cy="8.6" r="1.5" fill="currentColor"/><g stroke="currentColor" stroke-width=".7" stroke-linecap="round"><path d="M6 7.2V2M6 7.2L2.6 3.4M6 7.2l3.4-3.8M6 7.2L3.2 6M6 7.2L8.8 6"/></g></svg>',
    rain:  '<svg viewBox="0 0 4 14"><path d="M2 1v12" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/></svg>',
    mote:  '<svg viewBox="0 0 10 10"><circle cx="5" cy="5" r="3" fill="currentColor" opacity=".7"/></svg>',
    star:  '<svg viewBox="0 0 12 12"><path d="M6 0l1 5 5 1-5 1-1 5-1-5-5-1 5-1z" fill="currentColor"/></svg>'
};
var DRIFT_SVG = {
    butterfly:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"><path d="M12 6v11"/><path d="M12 8c-2-4-6-5-8-3 0 3 3 6 8 7"/><path d="M12 8c2-4 6-5 8-3 0 3-3 6-8 7"/><path d="M12 6l-1.6-2.4M12 6l1.6-2.4"/></svg>',
    bee:      '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"><ellipse cx="13" cy="14" rx="5" ry="3.6"/><path d="M11 11.2v5.6M14 10.8v6.4"/><path d="M11 11c-2-3-5-4-7-2 0 2.4 2.4 4 5 4.4"/><path d="M18 12.4c1.6-.8 2.6-.4 3 .6"/></svg>',
    ladybug:  '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"><circle cx="12" cy="13" r="5.6"/><path d="M12 7.4v11"/><circle cx="9" cy="11.6" r=".9" fill="currentColor"/><circle cx="15" cy="14.4" r=".9" fill="currentColor"/><path d="M10 6.4l-.8-1.6M14 6.4l.8-1.6"/></svg>',
    dragonfly:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.1" stroke-linecap="round"><path d="M12 7v11"/><circle cx="12" cy="6" r="1.4"/><path d="M12 9.4c-3.4-2-6.6-2-8 0 1.4 1.6 4.6 1.8 8 .6"/><path d="M12 9.4c3.4-2 6.6-2 8 0-1.4 1.6-4.6 1.8-8 .6"/><path d="M12 12.6c-2.6-1.4-5-1.4-6 0 1 1.2 3.4 1.4 6 .5"/><path d="M12 12.6c2.6-1.4 5-1.4 6 0-1 1.2-3.4 1.4-6 .5"/></svg>',
    bird:     '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"><path d="M3 13c3-4 6-4 9 0 3-4 6-4 9 0"/></svg>',
    swallow:  '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"><path d="M2 11c4-3.6 7.4-3 10 1 2.6-4 6-4.6 10-1"/><path d="M12 12v3.4"/></svg>'
};

function rand(a, b) { return a + Math.random() * (b - a); }

/* La cartella dei disegni veri di alberi e animali: un file per mese, esterni
   e non scritti a mano nel codice, così il browser scarica solo quello che
   serve e se lo tiene in cache.

   Si caricano come MASCHERA, non come immagine: la maschera ritaglia la forma
   e il colore lo mette il fondo. Un solo file basta quindi per qualunque
   tinta, e l'albero di ottobre può essere ramato senza avere un file ramato. */
/* La cartella dei disegni di alberi e animali. Il percorso è relativo alla
   PAGINA, non a questo file: viene risolto a partire dall'indirizzo di
   planner.php, perché gli indirizzi delle maschere sono impostati qui via
   JavaScript. Quindi 'svg/' significa la cartella svg accanto a planner.php.
   Se un giorno sposti i disegni, cambia solo questa riga. */
var AMBIENT_DIR = '../_assets/svg/';

function fondale(nome, colore, classe) {
    var n = el('div', classe);
    var u = 'url("' + AMBIENT_DIR + (nome.indexOf('albero-') === 0 ? 'trees/' : 'animals/') + nome + '.svg")';
    n.style.webkitMaskImage = u;
    n.style.maskImage = u;
    n.style.backgroundColor = colore;
    return n;
}

/* Chi si muove e chi no.

   Quasi tutti stanno fermi: sono animali che, visti dal vero, se ne stanno
   lì — il gatto dorme, la talpa spunta dalla terra, il pinguino guarda. Un
   pinguino che sfreccia da un lato all'altro sembrava una lepre.
   Camminano solo quelli che camminando ci si vedono davvero, e piano.
   La slitta è l'unica che vola, e plana come i gabbiani. */
var ANIMALI_CHE_CAMMINANO = ['cane', 'tartaruga', 'riccio'];
var ANIMALI_CHE_VOLANO    = ['slitta'];

function renderAmbient(cfg) {
    var host = $('plAmbient');
    if (!host) return;
    host.innerHTML = '';
    /* solo questa scena obbedisce alla barra dell'opacità */
    host.classList.add('pl-amb-natura');
    var color = cfg.treeColor || '#9BAA9C';

    /* Un albero solo, verso il primo quarto della barra. Prima ce n'erano due,
       uno per lato, ed erano due copie della stessa sagoma: con un disegno
       vero la ripetizione si notava. */
    host.appendChild(fondale(cfg.tree || 'albero-giugno', color, 'pl-tree'));

    /* l'animale del mese */
    if (cfg.animal) {
        var moto = '';
        if (ANIMALI_CHE_CAMMINANO.indexOf(cfg.animal) !== -1) moto = ' pl-animal-cammina';
        else if (ANIMALI_CHE_VOLANO.indexOf(cfg.animal) !== -1) moto = ' pl-animal-vola';
        /* Una classe col nome della bestia (pl-animal-cane, pl-animal-volpe…)
           così nel CSS si può ritoccare posizione e dimensione di una sola
           senza toccare le altre: un cane e una tartaruga non stanno bene
           nello stesso riquadro. */
        var a = fondale(cfg.animal, cfg.animalColor || '#7A6F64',
                        'pl-animal pl-animal-' + cfg.animal + moto);
        if (moto) a.style.animationDelay = '-' + rand(0, 20).toFixed(1) + 's';
        host.appendChild(a);
    }

    (cfg.particles || []).forEach(function (p) {
        var still = p.type === 'star';
        for (var n = 0; n < (p.count || 8); n++) {
            var size = rand(p.size ? p.size[0] : 4, p.size ? p.size[1] : 8);
            var part = el('div', 'pl-part ' + (still ? 'pl-part-still' : (p.type === 'rain' ? 'pl-part-rain' : 'pl-part-fall')));
            part.style.left = rand(-2, 98).toFixed(1) + '%';
            part.style.width = size.toFixed(1) + 'px';
            part.style.height = (p.type === 'rain' ? size * 1.6 : size).toFixed(1) + 'px';
            part.style.color = (p.colors || ['#FFFFFF'])[n % (p.colors || ['#FFFFFF']).length];
            part.style.animationDuration = rand(p.duration ? p.duration[0] : 16, p.duration ? p.duration[1] : 26).toFixed(1) + 's';
            part.style.animationDelay = '-' + rand(0, 22).toFixed(1) + 's';
            part.style.setProperty('--sway', rand(-(p.sway || 20), p.sway || 20).toFixed(0) + 'px');
            if (still) { part.style.top = rand(6, 62).toFixed(0) + '%'; part.style.animationDuration = rand(3, 6).toFixed(1) + 's'; }
            part.innerHTML = PART_SVG[p.type] || PART_SVG.mote;
            host.appendChild(part);
        }
    });

    (cfg.drifters || []).forEach(function (d) {
        for (var n = 0; n < (d.count || 1); n++) {
            var isFly = d.type === 'firefly';
            /* chi cammina o scivola non batte le ali: niente ondeggio veloce */
            /* pinguino, scoiattolo, riccio, renna e slitta non passano più di
               qui: sono diventati l'animale del mese, disegnato da file.
               Restano insetti e uccelli. */
            var terrestre = false;
            var slitta = false;
            var wrap = el('div', 'pl-drift'
                + (isFly ? ' pl-firefly' : '')
                + (d.type === 'bird' || d.type === 'swallow' ? ' pl-drift-slow' : '')
                + (terrestre ? ' pl-drift-walk' : '')
                + (slitta ? ' pl-drift-walk pl-drift-fly' : ''));
            var size = d.size || 15;
            wrap.style.top = rand(8, 62).toFixed(0) + '%';
            wrap.style.width = size + 'px';
            wrap.style.height = (isFly ? size : size) + 'px';
            wrap.style.color = d.color || '#B7AEA3';
            wrap.style.animationDuration = ((d.duration || 42) + rand(-6, 6)).toFixed(1) + 's';
            wrap.style.animationDelay = '-' + rand(0, 30).toFixed(1) + 's';
            if (!isFly) {
                var inner = el('span', 'pl-drift-in');
                inner.style.animationDuration = rand(2.4, 4.2).toFixed(1) + 's';
                inner.innerHTML = DRIFT_SVG[d.type] || DRIFT_SVG.butterfly;
                wrap.appendChild(inner);
            }
            host.appendChild(wrap);
        }
    });
}

/* ------------------------------------------------------------------ */
/* La barra in alto quando il meteo è acceso                           */
/* ------------------------------------------------------------------ */

/* Sagome della scena. Sono qui e non in `svg/` perché non sono disegni:
   sono forme geometriche di poche righe, e caricarle come file vorrebbe
   dire una richiesta in più per ogni nuvola. */
var WXA_SVG = {
    nuvola: '<svg viewBox="0 0 100 46" preserveAspectRatio="none"><path d="M20 44h58c11 0 18-7 18-15 0-9-8-15-17-14-2-9-10-14-19-14-11 0-19 7-21 16-9 0-16 6-16 14 0 7 5 13 10 13z" fill="currentColor"/></svg>',
    sole:   '<svg viewBox="0 0 60 60"><circle cx="30" cy="30" r="13" fill="currentColor"/><g stroke="currentColor" stroke-width="2.6" stroke-linecap="round"><path d="M30 4v8M30 48v8M4 30h8M48 30h8M11.6 11.6l5.6 5.6M42.8 42.8l5.6 5.6M11.6 48.4l5.6-5.6M42.8 17.2l5.6-5.6"/></g></svg>',
    luna:   '<svg viewBox="0 0 60 60"><path d="M38 6a24 24 0 100 48 20 20 0 010-48z" fill="currentColor"/></svg>'
};

/* Quante gocce (o fiocchi) per ciascun tipo di tempo: la quantità è il
   modo più immediato per far sentire la differenza fra pioviggine e
   rovescio, più delle icone. */
/* I numeri sono raddoppiati abbondanti rispetto a prima: la scena era così
   discreta che a colpo d'occhio pioggia e nuvolo si somigliavano, e la barra
   non diceva niente. Ora un rovescio si vede che è un rovescio. Restano
   elementi animati solo con `transform` e `opacity`, quindi il costo cresce
   di poco anche a sessanta gocce. */
var WXA_SCENA = {
    'sole':            { sole: true,  nuvole: 0, gocce: 0,  tipo: null },
    'sole-nubi':       { sole: true,  nuvole: 3, gocce: 0,  tipo: null },
    'nubi':            { sole: false, nuvole: 7, gocce: 0,  tipo: null, coperto: true },
    'nebbia':          { sole: false, nuvole: 2, gocce: 0,  tipo: null, nebbia: true },
    'pioggia-leggera': { sole: false, nuvole: 4, gocce: 22, tipo: 'rain' },
    'pioggia':         { sole: false, nuvole: 6, gocce: 40, tipo: 'rain', coperto: true },
    'pioggia-forte':   { sole: false, nuvole: 7, gocce: 56, tipo: 'rain', coperto: true },
    'neve':            { sole: false, nuvole: 5, gocce: 34, tipo: 'snow' },
    'temporale':       { sole: false, nuvole: 7, gocce: 46, tipo: 'rain', lampo: true, coperto: true }
};

/* Quanto fa caldo, in tre gradini. Il colore lo decide la massima del giorno
   e non l'icona, perché una giornata di sole a 18° e una a 38° hanno la
   stessa icona ma non sono affatto la stessa cosa. */
function wxCaldo(g) {
    var t = (g && g.tmax !== null && g.tmax !== undefined) ? g.tmax : null;
    if (t === null) return 0;
    if (t >= 36) return 3;
    if (t >= 32) return 2;
    if (t >= 28) return 1;
    return 0;
}

/* Vale la pena sostituire alberi e bestie con il cielo del giorno solo
   quando si guarda un giorno solo: con più giorni davanti, una scena sola
   racconterebbe il tempo di uno e mentirebbe sugli altri. */
/* La scena della barra racconta il tempo di *un* giorno, quindi vale solo
   dove se ne guarda uno: la vista giorno con una colonna sola e la vista
   settimanale, dove il giorno scelto è quello agganciato a sinistra. Con più
   colonne davanti direbbe il tempo di una spacciandolo per quello di tutte.
   `visibleDays()` non basta a dirlo: in vista settimana non conta quante
   colonne si vedono, perché il giorno scelto è comunque uno solo. */
function ambUnGiorno() {
    if (S.view === 'month') return false;
    if (S.view === 'week') return true;
    return visibleDays() < 2;
}

function ambientMeteo() {
    if (!WX.on || !ambUnGiorno()) return null;
    var g = WX.giorni[S.focusDate];
    if (!g) return null;
    /* Su oggi conta che tempo fa adesso, non il riassunto della giornata: una
       giornata segnata "poco nuvolosa" che alle sei di sera sta piovendo deve
       mostrare la pioggia, se no la barra racconta una cosa che non c'è più.
       Sugli altri giorni il riassunto è l'unica cosa che si possa dire. */
    if (S.focusDate === S.today && WX.adesso && WX.adesso.icon) {
        return { icon: WX.adesso.icon, code: 'ora-' + WX.adesso.icon,
                 tmax: g.tmax, tmin: g.tmin, label: WX.adesso.label || g.label };
    }
    return g;
}

/* Sera e notte. Vale solo su oggi: sugli altri giorni non c'è un'ora
   corrente da guardare, e una previsione non è né di giorno né di notte. */
function wxNotte(date) {
    if (date !== S.today) return false;
    /* Il server manda già se è giorno o notte lì dove ci si trova, calcolato
       su alba e tramonto veri: a dicembre alle sei di sera è buio, a giugno
       no, e un orario fisso avrebbe sbagliato mezzo anno. L'orologio resta
       come ripiego se quel dato non arriva. */
    if (WX.adesso && WX.adesso.giorno !== null && WX.adesso.giorno !== undefined) {
        return WX.adesso.giorno === false;
    }
    var h = new Date().getHours();
    return h < 6 || h >= 20;
}

function renderAmbientMeteo(g, date) {
    var host = $('plAmbient');
    if (!host) return;
    host.innerHTML = '';
    host.classList.remove('pl-amb-natura');
    var s = WXA_SCENA[g.icon] || WXA_SCENA['nubi'];

    /* Il rosso del caldo va per primo, così sole e nuvole gli restano sopra
       invece di finirci sotto. Al terzo gradino la barra è tutta rossa: è
       esattamente il colpo d'occhio che deve dare una giornata a 36 gradi. */
    var caldo = wxCaldo(g);
    if (caldo) host.appendChild(el('div', 'pl-wxa-caldo pl-wxa-caldo-' + caldo));
    /* cielo coperto: un velo grigio dietro le nuvole, se no fra tre nuvole e
       sette si vede solo che sono di più, non che il cielo è chiuso */
    if (s.coperto) host.appendChild(el('div', 'pl-wxa-coperto'));

    /* Di sera e di notte la scena cambia registro: un velo scuro sopra tutto
       e, a cielo libero, qualche stella. Senza, alle undici di sera la barra
       sembrava un pomeriggio d'estate. */
    var notte = wxNotte(date);
    if (notte) {
        host.appendChild(el('div', 'pl-wxa-notte'));
        if (!s.coperto && !s.gocce && !s.nebbia) {
            for (var st = 0; st < 12; st++) {
                var stella = el('div', 'pl-part pl-part-still pl-wxa-stella');
                stella.style.left = rand(2, 96).toFixed(1) + '%';
                stella.style.top = rand(6, 62).toFixed(0) + '%';
                var sz = rand(4, 8);
                stella.style.width = sz.toFixed(1) + 'px';
                stella.style.height = sz.toFixed(1) + 'px';
                stella.style.animationDuration = rand(2.4, 5.5).toFixed(1) + 's';
                stella.style.animationDelay = '-' + rand(0, 4).toFixed(1) + 's';
                stella.innerHTML = PART_SVG.star;
                host.appendChild(stella);
            }
        }
    }

    /* Sole o luna secondo l'ora: alle nove di sera un sole pieno nella
       barra è la cosa più stonata che si possa vedere. */
    if (s.sole) {
        var astro = el('div', 'pl-wxa-sun' + (notte ? ' pl-wxa-moon' : '')
                       + (!notte && caldo >= 2 ? ' pl-wxa-sun-caldo' : ''));
        astro.innerHTML = notte ? WXA_SVG.luna : WXA_SVG.sole;
        host.appendChild(astro);
    }

    for (var n = 0; n < s.nuvole; n++) {
        var c = el('div', 'pl-wxa-cloud');
        c.innerHTML = WXA_SVG.nuvola;
        c.style.top = rand(4, 34).toFixed(0) + '%';
        c.style.width = rand(78, 132).toFixed(0) + 'px';
        /* durate lunghe e sfasate: due nuvole che viaggiano appaiate si
           vedrebbero come un blocco unico */
        c.style.animationDuration = rand(90, 150).toFixed(0) + 's';
        c.style.animationDelay = '-' + rand(0, 80).toFixed(0) + 's';
        c.style.opacity = (0.62 + Math.random() * 0.34).toFixed(2);
        host.appendChild(c);
    }

    if (s.nebbia) {
        for (var f = 0; f < 3; f++) {
            var banda = el('div', 'pl-wxa-fog');
            banda.style.top = (24 + f * 20) + '%';
            banda.style.animationDuration = rand(28, 46).toFixed(0) + 's';
            banda.style.animationDelay = '-' + rand(0, 20).toFixed(0) + 's';
            host.appendChild(banda);
        }
    }

    for (var i = 0; i < s.gocce; i++) {
        var neve = s.tipo === 'snow';
        var p = el('div', 'pl-part ' + (neve ? 'pl-part-fall' : 'pl-part-rain'));
        var size = neve ? rand(4, 7.5) : rand(7, 13);
        p.style.left = rand(-2, 98).toFixed(1) + '%';
        p.style.width = (neve ? size : size / 3.4).toFixed(1) + 'px';
        p.style.height = size.toFixed(1) + 'px';
        p.style.color = neve ? '#FFFFFF' : '#6F9BB8';
        p.style.animationDuration = (neve ? rand(9, 16) : rand(1.1, 2.2)).toFixed(1) + 's';
        p.style.animationDelay = '-' + rand(0, 12).toFixed(1) + 's';
        p.style.setProperty('--sway', rand(-14, 14).toFixed(0) + 'px');
        p.innerHTML = neve ? PART_SVG.snow : PART_SVG.rain;
        host.appendChild(p);
    }

    if (s.lampo) {
        var lampo = el('div', 'pl-wxa-flash');
        host.appendChild(lampo);
    }

    /* Niente riassunto al centro: copriva la scena ed era un doppione, il
       popup si apre già dal tag accanto al giorno, dalla striscia sotto le
       priorità e dal tastino del meteo. Così la barra torna tutta decorazione
       e lascia passare i tocchi (le regole `.pl-wxa-tag` restano nel foglio
       di stile, semmai il riassunto dovesse tornare). */
}

/* Decide che cosa mettere nella barra: la scena del meteo se il meteo è
   acceso su un giorno solo, altrimenti l'albero e la bestia del mese.
   La chiave serve a non ridisegnare tutto a ogni scorrimento: si ricostruisce
   solo quando cambia davvero la scena (mese diverso, o tempo diverso). */
/* Quanto si vede la scena del mese: 0 invisibile, 10 piena. C'è chi la trova
   invadente ma non la vuole spegnere del tutto, e prima l'unica scelta era
   fra tutto e niente. Il valore di partenza è 9 — quasi il massimo — cioè
   com'era prima che la barra esistesse. Il meteo non è compreso: quella
   scena dice che tempo fa, non è una decorazione. */
function ambOpacita() {
    var v = S.settings.ambient_opacity;
    return (v === 0 || v) ? Math.max(0, Math.min(10, v)) : 9;
}
function applyAmbientOpacity() {
    document.documentElement.style.setProperty('--ambiente-op', (ambOpacita() / 10).toFixed(2));
}

/* La carta d'identità della scena di un giorno: due giorni con la stessa
   chiave hanno la stessa barra. Serve a due cose — non ricostruirla quando
   non è cambiato niente, e sapere se la copia fatta per il giorno vicino
   durante il cambio pagina va bene o va buttata. Prima il conto stava dentro
   applyAmbient e nessun altro poteva farlo: è il motivo per cui oltre
   l'ultimo giorno di previsione si continuava a vedere il tempo di prima. */
function ambChiave(date) {
    if (S.settings.ambient_enabled === false) return 'niente';
    var g = (WX.on && ambUnGiorno()) ? WX.giorni[date] : null;
    if (g) {
        var w = (date === S.today && WX.adesso && WX.adesso.icon)
            ? { icon: WX.adesso.icon, code: 'ora-' + WX.adesso.icon, tmax: g.tmax, tmin: g.tmin }
            : g;
        return 'wx|' + date + '|' + w.code + '|' + wxCaldo(w) + '|' + (wxNotte(date) ? 'n' : 'g');
    }
    if (!AMB) return 'niente';
    var base = S.view === 'month' ? (S.monthCursor || date) : date;
    return 'mese|' + (parseIso(base).getMonth() + 1);
}

function applyAmbient(force) {
    var host = $('plAmbient');
    if (!host) return;
    if (S.settings.ambient_enabled === false) { host.innerHTML = ''; ambKey = 'niente'; return; }

    var chiave = ambChiave(S.focusDate);
    if (!force && chiave === ambKey) return;
    ambKey = chiave;

    var wxg = ambientMeteo();
    if (wxg) { renderAmbientMeteo(wxg, S.focusDate); return; }

    if (!AMB) return;
    /* mese nuovo, cielo nuovo */
    setTimeout(applySky, 0);
    var base = S.view === 'month' ? (S.monthCursor || S.focusDate) : S.focusDate;
    renderAmbient((AMB.months && AMB.months[String(parseIso(base).getMonth() + 1)]) || {});
}

/* Tutto quello che il meteo tocca, rifatto in una volta sola. Prima ogni
   punto che accendeva o spegneva il meteo si ricordava a mano quali cinque
   cose ridisegnare, e bastava dimenticarne una perché un tag o una scena
   restassero indietro fino al ridisegno successivo. */
function wxRedraw() {
    renderDays();
    renderWeekBits();
    renderGutterMeteo(null, S.focusDate);
    /* la colonna delle ore della settimana è un'altra: accendendo il meteo
       da lì, senza questo i tag comparivano solo al ridisegno dopo */
    if (S.view === 'week') renderGutterMeteo($('plWeekGut'), S.focusDate);
    applyAmbient(true);
    if (S.view === 'month') renderMonth();
}

function loadAmbient() {
    return fetch('planner_ambient.json', { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) { AMB = d; applyAmbient(true); applySky(); })
        .catch(function () { /* senza il file la barra resta pulita */ });
}

/* Il colore della fascia in alto: con alberi e insetti attivi segue il mese
   (freddo d'inverno, caldo d'estate, così si sente la stagione); altrimenti
   segue il colore che l'utente ha scelto per la pagina. Prima era sempre
   una sfumatura calda fissa, che con le palette fredde stonava. */
function applySky() {
    var bar = $('plTopbar');
    if (!bar) return;
    bar.className = 'pl-topbar';

    var base = S.view === 'month' ? (S.monthCursor || S.focusDate) : S.focusDate;
    var m = String(parseIso(base).getMonth() + 1);
    var cfg = AMB && AMB.months ? AMB.months[m] : null;

    if (S.settings.ambient_enabled !== false && cfg && cfg.sky) {
        bar.style.setProperty('--sky-grad',
            'linear-gradient(168deg, ' + cfg.sky[0] + ' 0%, ' + cfg.sky[1] + ' 52%, var(--paper) 94%)');
    } else {
        /* nessun ambiente: la fascia è un velo del colore scelto */
        bar.style.setProperty('--sky-grad',
            'linear-gradient(168deg, var(--accent-wash) 0%, var(--paper) 78%)');
    }
    applyAmbient();
}
function applyPalette(key) {
    var root = document.documentElement;
    root.className = root.className.replace(/pl-palette-\S+/g, '').trim() + ' pl-palette-' + key;
}

/* ------------------------------------------------------------------ */
/* Barra in alto: settimana, mese, mini calendario, affermazione        */
/* ------------------------------------------------------------------ */
function renderWeekBits() {
    var d = parseIso(S.focusDate);
    if (S.view !== 'month') {
        $('plMonthName').textContent = MESI[d.getMonth()];
        $('plMonthYear').textContent = d.getFullYear();
    }
    var nd = visibleDays();
    /* il tag del meteo si rifà da capo a ogni giro: senza toglierlo, cambiando
       giorno se ne accumulerebbe uno accanto all'altro */
    var wxVecchio = $('plDayWx');
    if (wxVecchio && wxVecchio.parentNode) wxVecchio.parentNode.removeChild(wxVecchio);
    if (S.view === 'month') {
        // nella vista mese il giorno non ha senso: si guarda il mese intero
        $('plDayNum').textContent = '';
        $('plDayWd').textContent = '';
    } else if (nd >= 2) {
        /* il giorno a fuoco sta in mezzo, quindi l'intervallo comincia prima
           di lui: "24 ven – 26 dom" con il 25 al centro */
        var firstIso = addDays(S.focusDate, -daysBefore());
        var lastIso = addDays(firstIso, nd - 1);
        var first = parseIso(firstIso);
        var last = parseIso(lastIso);
        var host = $('plDayNum');
        host.textContent = '';
        host.appendChild(document.createTextNode(String(first.getDate())));
        host.appendChild(el('span', 'pl-day-wd-in', GIORNI[dowIndex(firstIso)].slice(0, 3)));
        host.appendChild(el('span', 'pl-day-dash', '–'));
        host.appendChild(document.createTextNode(String(last.getDate())));
        host.appendChild(el('span', 'pl-day-wd-in', GIORNI[dowIndex(lastIso)].slice(0, 3)));
        $('plDayWd').textContent = '';
    } else {
        $('plDayNum').textContent = String(d.getDate());
        $('plDayWd').textContent = GIORNI[dowIndex(S.focusDate)];
        /* Con un giorno solo il meteo si appoggia qui, accanto al nome del
           giorno: è l'unico posto dove si vede sempre. Sta *fuori* dal
           pulsante del giorno e non dentro: un tasto dentro un altro tasto
           confonde i lettori di schermo e rende ambiguo il tocco. */
        var wtop = wxTag(S.focusDate);
        if (wtop) {
            wtop.id = 'plDayWx';
            wtop.classList.add('pl-day-wx');
            var btn = $('plDayBtn');
            btn.parentNode.insertBefore(wtop, btn.nextSibling);
            /* Accanto al numero e SOPRA il nome del giorno: sulla stessa riga
               del nome non ci sta (mese e calendarietto ne prendono meta'), e
               li' finiva per coprirlo. Si sposta di quanto e' largo il numero,
               che cambia fra 1 e 31. */
            wtop.style.marginLeft = ($('plDayNum').offsetWidth + 6) + 'px';
        }
    }
    /* il foglietto di "oggi": mese abbreviato in alto, numero sotto */
    var oggiD = parseIso(S.today);
    $('plTodayD').textContent = String(oggiD.getDate());
    $('plTodayM').textContent = MESI[oggiD.getMonth()].slice(0, 3);
    $('plWeekN').textContent = S.week.number || 1;

    var aff = $('plAffirm');
    aff.textContent = S.settings.show_affirmations === false ? '' : (S.week.affirmation || '');
    aff.classList.toggle('pl-hidden', !aff.textContent);

    var qb = $('plQuoteBar');
    var showQuote = S.settings.show_motivational !== false && !!S.week.quote;
    $('plQuoteText').textContent = S.week.quote || '';
    qb.classList.toggle('pl-hidden', !showQuote);

    renderMiniStatic();
    renderMini();
    updateWeekPanelBtn();
    syncScrollerTop();
}

/* calendarietto sempre visibile: la settimana in corso è una fascia colorata */
function renderMiniStatic() {
    var box = $('plMiniStatic');
    if (!box) return;
    box.innerHTML = '';
    GIORNI_S.forEach(function (g) {
        var s = el('span', 'pl-sw-wd', g);
        box.appendChild(s);
    });
    var focus = parseIso(S.focusDate);
    var start = monday(iso(new Date(focus.getFullYear(), focus.getMonth(), 1)));
    var wStart = monday(S.focusDate);
    for (var i = 0; i < 42; i++) {
        var ds = addDays(start, i);
        var dd = parseIso(ds);
        var s2 = el('span', null, String(dd.getDate()));
        if (dd.getMonth() !== focus.getMonth()) s2.className = 'pl-sw-out';
        if (monday(ds) === wStart) s2.className += ' pl-sw-week';
        if (ds === S.today) s2.className += ' pl-sw-today';
        box.appendChild(s2);
    }
}

function renderMini() {
    var box = $('plMini');
    box.innerHTML = '';

    var cursor = parseIso(S.miniCursor || S.focusDate);
    var head = el('div', 'pl-mini-head pl-serif', MESI[cursor.getMonth()] + ' ' + cursor.getFullYear());
    box.appendChild(head);
    GIORNI_S.forEach(function (g) { box.appendChild(el('div', 'pl-mini-wd', g)); });

    var first = new Date(cursor.getFullYear(), cursor.getMonth(), 1);
    var start = monday(iso(first));
    var wStart = monday(S.focusDate);

    for (var i = 0; i < 42; i++) {
        var ds = addDays(start, i);
        var dd = parseIso(ds);
        var cell = el('div', 'pl-mini-d', String(dd.getDate()));
        if (dd.getMonth() !== cursor.getMonth()) cell.classList.add('pl-out');
        if (monday(ds) === wStart) cell.classList.add('pl-inweek');
        if (ds === S.today) cell.classList.add('pl-today-d');
        cell.dataset.date = ds;
        box.appendChild(cell);
        if (i >= 34 && monday(addDays(start, i + 1)) !== monday(ds) && dd.getMonth() !== cursor.getMonth()) break;
    }
}

/* sfoglia i mesi DENTRO al popup, senza toccare il giorno a fuoco */
function stepMini(delta) {
    var c = parseIso(S.miniCursor || S.focusDate);
    var next = new Date(c.getFullYear(), c.getMonth() + delta, 1);
    S.miniCursor = iso(next);
    renderMini();
}
function openMini() {
    S.miniCursor = null;
    renderMini();
    $('plMini').classList.add('pl-open');
}
function toggleMini() {
    var box = $('plMini');
    if (box.classList.contains('pl-open')) box.classList.remove('pl-open');
    else openMini();
}

/* mese: in vista mese sfoglia i nomi dei mesi; in vista giorno passa alla
   vista mese (il pulsante dedicato non serve più). */
$('plMonthBtn').addEventListener('click', function () {
    if (S.view === 'month') {
        $('plMonthsBar').classList.toggle('pl-open');
        syncScrollerTopAnimated();
        return;
    }
    setView('month');
});
/* giorno e calendarietto: in vista mese tornano al giorno; in vista giorno
   aprono il popup del calendario. */
$('plDayBtn').addEventListener('click', function () {
    if (S.view === 'month') { setView('day'); return; }
    toggleMini();
});
$('plMiniStatic').addEventListener('click', function () {
    if (S.view === 'month') { setView('day'); return; }
    toggleMini();
});
$('plMini').addEventListener('click', function (e) {
    var c = e.target.closest('.pl-mini-d');
    if (!c || !c.dataset.date) return;
    goToDate(c.dataset.date, true);
    $('plMini').classList.remove('pl-open');
});
/* dentro al popup: scorri a destra o sinistra per vedere gli altri mesi */
(function miniSwipe() {
    var box = $('plMini');
    var x0 = null, y0 = 0, horiz = false;
    box.addEventListener('touchstart', function (e) {
        if (e.touches.length !== 1) return;
        x0 = e.touches[0].clientX; y0 = e.touches[0].clientY; horiz = false;
    }, { passive: true });
    box.addEventListener('touchmove', function (e) {
        if (x0 === null) return;
        var dx = e.touches[0].clientX - x0, dy = e.touches[0].clientY - y0;
        if (Math.abs(dx) > Math.abs(dy) && Math.abs(dx) > 10) horiz = true;
    }, { passive: true });
    box.addEventListener('touchend', function (e) {
        if (x0 === null) return;
        var t = e.changedTouches[0];
        var dx = t.clientX - x0;
        x0 = null;
        if (!horiz || Math.abs(dx) < 30) return;
        stepMini(dx < 0 ? 1 : -1);   // scorri a sinistra: mese successivo
        haptic(6);
    }, { passive: true });
    box.addEventListener('wheel', function (e) {
        if (Math.abs(e.deltaX) <= Math.abs(e.deltaY)) return;
        e.preventDefault();
        stepMini(e.deltaX > 0 ? 1 : -1);
    }, { passive: false });
})();
/* un tocco fuori chiude i menù dei pulsanti a destra: lasciarli aperti
   copriva la griglia e bloccava lo scorrimento degli orari */
document.addEventListener('pointerdown', function (e) {
    if (e.target.closest('.pl-fab-menu, .pl-fabs button, .pl-ring')) return;
    closeMenus();
}, true);

/* toccare fuori dal calendario lo richiude: ora è un pannello indipendente
   sopra la pagina, non più una sezione che spinge giù il resto. */
document.addEventListener('click', function (e) {
    var mini = $('plMini');
    if (!mini.classList.contains('pl-open')) return;
    if (e.target.closest('#plMini, #plMonthBtn, #plDayBtn, #plMiniStatic')) return;
    mini.classList.remove('pl-open');
});

$('plWeekBtn').addEventListener('click', function () {
    askWeekNumber();
});

/* Riepilogo della settimana: il numero si modifica in cima, sotto c'è
   quanto stai davvero spendendo su abitudini, categorie e routine, e
   com'è andato il diario (quest'ultimo solo da martedì in avanti). */
function askWeekNumber() {
    var week = monday(S.focusDate);
    openSheet(T('planner.la_settimana'), function (body) {
        /* numero, in piccolo e modificabile */
        var top = el('div', 'pl-row-sb');
        var lab = el('div');
        lab.appendChild(el('div', 'pl-label', T('planner.settimana_numero')));
        lab.appendChild(el('div', 'pl-row-sub', T('planner.cambiandolo_le_successive_si_rinumerano')));
        top.appendChild(lab);
        var num = el('input', 'pl-pick');
        num.type = 'number'; num.min = '1'; num.max = '9999';
        num.value = S.week.number || 1;
        num.style.width = '76px';
        num.style.textAlign = 'center';
        num.addEventListener('change', function () {
            var n = Math.max(1, Math.min(9999, parseInt(num.value, 10) || 1));
            api('week_save', { week_start: week, number: n }).then(function (r) {
                if (!r.ok) return;
                S.week = r.week;
                if (S.weekCache[week]) S.weekCache[week].week = r.week;
                renderWeekBits();
            });
        });
        top.appendChild(num);
        body.appendChild(top);

        var box = el('div');
        box.appendChild(el('div', 'pl-quote pl-serif', T('planner.sto_mettendo_insieme_i_conti')));
        body.appendChild(box);

        api('week_summary', null, { week_start: week }).then(function (r) {
            box.innerHTML = '';
            if (!r.ok) {
                box.appendChild(el('div', 'pl-quote pl-serif', T('planner.riepilogo_non_disponibile')));
                return;
            }
            box.appendChild(summaryHabits(r));
            box.appendChild(summaryCats(r));
            box.appendChild(summaryRoutines(r));
            if (r.show_diary && r.diary) box.appendChild(summaryDiary(r.diary));
        });
    });
}

/* una riga del riepilogo: pallino colorato, nome, dettaglio, valore a destra */
function sumRow(color, name, detail, value) {
    var row = el('div', 'pl-row-sb');
    var left = el('div', 'pl-row');
    if (color) {
        var dot = el('span');
        dot.style.cssText = 'width:9px;height:9px;border-radius:50%;flex-shrink:0;background:' + color;
        left.appendChild(dot);
    }
    var txt = el('div');
    txt.appendChild(el('div', 'pl-row-lab', name));
    if (detail) txt.appendChild(el('div', 'pl-row-sub', detail));
    left.appendChild(txt);
    row.appendChild(left);
    if (value) {
        var v = el('div', 'pl-row-lab', value);
        v.style.whiteSpace = 'nowrap';
        row.appendChild(v);
    }
    return row;
}
function sumSection(title, note) {
    var f = el('div', 'pl-field');
    f.style.marginTop = '20px';
    f.appendChild(el('label', 'pl-label', title));
    if (note) f.appendChild(el('div', 'pl-row-sub', note));
    return f;
}
function hoursLabel(min) {
    if (!min) return '—';
    if (min < 60) return T('planner.n_min', {n: min});
    var h = Math.floor(min / 60), m = min % 60;
    return h + 'h' + (m ? ' ' + pad(m) : '');
}

function summaryHabits(r) {
    var f = sumSection(T('planner.abitudini_t'));
    var any = false;
    (r.habits || []).forEach(function (h) {
        if (!h.planned && !h.minutes) return;
        any = true;
        /* "1 volta su 3 · 2 fatte": in inglese il plurale non si fa attaccando una
               lettera, quindi ogni forma e' una chiave intera. */
            var goal = h.goal ? T('planner.su_n_volte', {n: h.goal}) : '';
            var detail = T(h.planned === 1 ? 'planner.n_volta' : 'planner.n_volte', {n: h.planned}) + goal
                + (h.done ? '  ·  ' + T(h.done === 1 ? 'planner.n_fatta' : 'planner.n_fatte', {n: h.done}) : '');
        f.appendChild(sumRow(h.color, (h.icon ? h.icon + ' ' : '') + h.name, detail, hoursLabel(h.minutes)));
    });
    if (!any) {
        f.appendChild(el('div', 'pl-row-sub', T('planner.nessuna_abitudine_messa_in_agenda')));
        return f;
    }
    var tot = sumRow(null, T('planner.in_tutto_sulle_abitudini'), null, hoursLabel(r.habit_minutes));
    tot.style.borderTop = '.5px solid var(--hair)';
    f.appendChild(tot);
    return f;
}

function summaryCats(r) {
    var f = sumSection(T('planner.categorie'));
    var any = false;
    (r.categories || []).forEach(function (c) {
        if (!c.minutes && !c.count) return;
        any = true;
        f.appendChild(sumRow(c.color, c.name,
                T(c.count === 1 ? 'planner.n_evento' : 'planner.n_eventi', {n: c.count}), hoursLabel(c.minutes)));
    });
    if (!any) f.appendChild(el('div', 'pl-row-sub', T('planner.ancora_niente_in_agenda')));
    return f;
}

function summaryRoutines(r) {
    var f = sumSection(T('planner.routine_t'));
    var any = false;
    (r.routines || []).forEach(function (x) {
        if (!x.times && !x.minutes) return;
        any = true;
        f.appendChild(sumRow(x.color, x.name,
            T(x.times === 1 ? 'planner.n_volta_r' : 'planner.n_volte_r', {n: x.times}), hoursLabel(x.minutes)));
    });
    if (!any) f.appendChild(el('div', 'pl-row-sub', T('planner.nessuna_routine_inserita')));
    return f;
}

function summaryDiary(d) {
    var f = sumSection(T('planner.diario_t'));
    f.appendChild(sumRow(null, T('planner.giorni_scritti'), null, T('planner.n_su_7', {n: d.days})));
    var faces = window.standoutFace;
    var moodRow = sumRow(null, T('planner.come_e_andata_in_media'), null,
        d.mood_avg !== null ? String(d.mood_avg).replace('.', ',') : '—');
    var enRow = sumRow(null, T('planner.energia_in_media'), null,
        d.energy_avg !== null ? String(d.energy_avg).replace('.', ',') : '—');
    if (faces) {
        /* le stesse faccine e stelline del diario, disegnate a matita */
        if (d.mood_avg !== null) {
            var mi = el('span');
            mi.style.cssText = 'width:26px;height:26px;color:var(--ink-2);flex-shrink:0';
            mi.innerHTML = faces('mood', Math.round(d.mood_avg), 26);
            moodRow.querySelector('.pl-row').insertBefore(mi, moodRow.querySelector('.pl-row').firstChild);
        }
        if (d.energy_avg !== null) {
            var ei = el('span');
            ei.style.cssText = 'width:26px;height:26px;color:var(--ink-2);flex-shrink:0';
            ei.innerHTML = faces('energy', Math.round(d.energy_avg), 26);
            enRow.querySelector('.pl-row').insertBefore(ei, enRow.querySelector('.pl-row').firstChild);
        }
    }
    f.appendChild(moodRow);
    f.appendChild(enRow);
    return f;
}
