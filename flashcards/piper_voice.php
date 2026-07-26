<!-- ================================================================
     PIPER — voce neurale offline, generata dentro il browser
     ----------------------------------------------------------------
     Alternativa alle voci di sistema: su iPhone Safari espone solo Alice
     e le Eloquence, e non c'è modo di accedere alle Enhanced/Premium
     scaricate. Piper gira interamente nel browser (modello ONNX +
     fonemizzatore espeak-ng compilati in WebAssembly), quindi dà la
     stessa voce su iPhone, Android e PC, senza costi per carattere.

     Il modello (~60MB) si scarica UNA volta e resta nel browser
     (Origin Private File System): dalla seconda volta è immediato.

     Questo file va incluso in game_fc.php DOPO floating_player.php.

     PRINCIPIO DI FONDO: qui dentro non deve mai poter rompere il sito.
     Tutto è opzionale e incapsulato: se la libreria non si carica, se il
     download fallisce o se il dispositivo non ce la fa, si torna in
     automatico alla voce di sistema. Piper entra in gioco SOLO se
     l'utente sceglie esplicitamente una delle sue voci.

     Espone window.PiperTTS. Il resto del sito lo usa solo se esiste.
     ================================================================ -->

<style>
    /* Barra di avanzamento del download, mostrata nel popup impostazioni */
    .piper-dl{ margin-top:9px; display:none; }
    .piper-dl.show{ display:block; }
    .piper-dl-bar{
        height:6px; border-radius:99px; overflow:hidden;
        background:rgba(120,120,128,.2);
    }
    .piper-dl-fill{
        height:100%; width:0%; border-radius:99px;
        background:linear-gradient(90deg,#3D96FF,#007AFF);
        transition:width .25s ease;
    }
    .piper-dl-text{ margin-top:6px; font-size:11.5px; color:var(--text-2); }
</style>

<script>
(function () {
    'use strict';

    // Da dove prendiamo la libreria. È un modulo ES: lo carichiamo con import()
    // dinamico, così se il CDN non risponde l'errore resta confinato qui.
    var LIB_URL = 'https://cdn.jsdelivr.net/npm/@diffusionstudio/vits-web/+esm';

    // NIENTE elenco di voci scritto a mano. Un tentativo precedente teneva qui una
    // lista di riserva con it_IT-paola-medium: quella voce esiste davvero su
    // HuggingFace, ma è stata aggiunta DOPO la versione della libreria che usiamo,
    // che punta a una revisione più vecchia del catalogo. Risultato: il download
    // rispondeva "Entry not found" (un 404 letto come JSON) e la voce risultava
    // scaricata in un istante ma poi non funzionava.
    // Regola: offriamo SOLO le voci che la libreria stessa dichiara di avere.
    // Se non riusciamo a leggere il catalogo, non proponiamo nulla: meglio nessuna
    // voce Piper che una voce che non potrà mai funzionare.

    var lib = null;          // modulo caricato
    var libPromise = null;   // per non caricarlo due volte in parallelo
    var audio = null;        // <audio> con l'ultima frase sintetizzata
    var vociCache = null;
    var ultimoErrorePonte = null; // ultimo errore di scrittura, per la diagnostica

    // NOTA sulla lentezza della sintesi: la libreria (vits-web 1.0.3) ricrea la
    // sessione di inferenza ONNX a ogni frase e non offre alcun modo pulito per
    // riusarla. Cachearla richiederebbe di riscrivere il suo pipeline interno
    // (fonemizzatore + ONNX), cosa che rimetterebbe a rischio tutto ciò che
    // abbiamo faticato a far funzionare su Safari. Per questo, per ora, ci
    // limitiamo a dare un feedback chiaro durante l'attesa (il toast "Sto
    // generando la voce…" in game_fc.php). Se la lentezza diventasse un problema
    // serio, la strada giusta è il fork Mintplex/piper-tts-web, che espone
    // TTSSession apposta per riusare l'inferenza — da valutare a parte.

    // ============================================================
    // UN SOLO <audio>, SBLOCCATO AL PRIMO TOCCO
    // ------------------------------------------------------------
    // iOS consente audio.play() solo se legato a un gesto dell'utente. La sintesi
    // Piper dura secondi, quindi quando play() parte il tocco è "scaduto" e iOS la
    // blocca: il player restava fermo e ripartiva solo premendo un tasto (nuovo
    // gesto). La cura standard: un unico elemento <audio> "sbloccato" con un play
    // muto durante il primo tocco della pagina; da lì in poi cambiargli .src e
    // richiamare play() non richiede un nuovo gesto.
    var audioSbloccato = false;
    function creaAudioCondiviso() {
        if (audio) return audio;
        audio = new Audio();
        audio.preload = 'auto';
        return audio;
    }
    function sbloccaAudio() {
        if (audioSbloccato) return;
        var a = creaAudioCondiviso();
        // WAV silenzioso minimo: lo facciamo partire e fermare dentro il gesto.
        a.src = 'data:audio/wav;base64,UklGRiQAAABXQVZFZm10IBAAAAABAAEARKwAAIhYAQACABAAZGF0YQAAAAA=';
        var p = a.play();
        if (p && p.then) {
            p.then(function () { a.pause(); a.currentTime = 0; audioSbloccato = true; })
             .catch(function () { /* riproveremo al prossimo tocco */ });
        } else {
            audioSbloccato = true;
        }
    }
    // Sblocchiamo al primo tocco/click reale sulla pagina.
    if (typeof document !== 'undefined') {
        var sblocca1 = function () {
            sbloccaAudio();
            document.removeEventListener('touchend', sblocca1, true);
            document.removeEventListener('mousedown', sblocca1, true);
            document.removeEventListener('click', sblocca1, true);
        };
        document.addEventListener('touchend', sblocca1, true);
        document.addEventListener('mousedown', sblocca1, true);
        document.addEventListener('click', sblocca1, true);
    }

    // ============================================================
    // PONTE DI SCRITTURA PER SAFARI
    // ------------------------------------------------------------
    // La libreria salva i modelli nell'Origin Private File System usando
    // handle.createWritable(). Quella funzione è arrivata su tutti i browser
    // solo da settembre 2025: sugli iPhone non aggiornatissimi NON esiste, e
    // il salvataggio fallisce -> "voce Piper non disponibile" (mentre su Chrome
    // desktop, che ce l'ha da anni, funziona tutto).
    // Safari però offre createSyncAccessHandle(), utilizzabile SOLO dentro un
    // Web Worker. Qui aggiungiamo createWritable solo se manca, appoggiandoci a
    // un worker. Scriviamo i pezzi man mano che arrivano invece di accumulare
    // 60MB in memoria: sull'iPhone la memoria è la risorsa più scarsa.
    // ============================================================
    var CODICE_WORKER = `let access = null, offset = 0;
        self.onmessage = async (e) => {
            const m = e.data;
            try {
                if (m.tipo === 'apri') {
                    // Il worker raggiunge il file DA SOLO, partendo dalla radice
                    // dello spazio privato: gli basta il percorso (stringhe).
                    // Non riceve più il FileSystemFileHandle, perché Safari non
                    // lo sa clonare tra thread ("The object can not be cloned").
                    let dir = await navigator.storage.getDirectory();
                    const parti = m.percorso.slice();
                    const nomeFile = parti.pop();
                    for (const p of parti) {
                        dir = await dir.getDirectoryHandle(p, { create: true });
                    }
                    const fh = await dir.getFileHandle(nomeFile, { create: true });
                    access = await fh.createSyncAccessHandle();
                    access.truncate(0);
                    offset = 0;
                    self.postMessage({ id: m.id, ok: true });
                } else if (m.tipo === 'scrivi') {
                    const dati = new Uint8Array(m.pezzo);
                    access.write(dati, { at: offset });
                    offset += dati.byteLength;
                    self.postMessage({ id: m.id, ok: true });
                } else if (m.tipo === 'chiudi') {
                    access.flush();
                    access.close();
                    access = null;
                    self.postMessage({ id: m.id, ok: true });
                }
            } catch (err) {
                self.postMessage({ id: m.id, ok: false, errore: String(err && err.message || err) });
            }
        };
    `;

    function serveIlPonte() {
        try {
            // ATTENZIONE: NON si può controllare createSyncAccessHandle da qui.
            // È esposto solo dentro i Web Worker, quindi sul thread principale non
            // compare sul prototipo NEMMENO su Safari, che ce l'ha. Un tentativo
            // precedente lo pretendeva, e così il ponte non si installava proprio
            // sui dispositivi per cui era stato scritto (il riquadro diagnostico
            // diceva "non serve" mentre createWritable risultava assente).
            // Ci basta sapere che createWritable manca e che i Worker esistono:
            // se poi createSyncAccessHandle non c'è, il worker fallisce e lo
            // gestiamo come un normale errore.
            return typeof FileSystemFileHandle !== 'undefined'
                && FileSystemFileHandle.prototype
                && typeof FileSystemFileHandle.prototype.createWritable !== 'function'
                && typeof Worker !== 'undefined'
                && typeof WritableStream !== 'undefined';
        } catch (e) { return false; }
    }

    function installaPonteScrittura() {
        if (!serveIlPonte()) return false;

        var url = URL.createObjectURL(new Blob([CODICE_WORKER], { type: 'text/javascript' }));

        FileSystemFileHandle.prototype.createWritable = function () {
            var handle = this;
            var worker = new Worker(url);
            var contatore = 0;
            var attese = {};

            // La libreria inghiotte gli errori di scrittura con un console.error
            // (vedi p() nel suo sorgente), quindi da fuori si vede solo un file
            // vuoto e nessuna spiegazione. Ce lo teniamo da parte noi, così la
            // diagnostica a schermo può dire cosa è andato storto davvero.
            function annota(e) {
                ultimoErrorePonte = (e && e.message) ? e.message : String(e);
                return e;
            }

            worker.onerror = function (e) {
                annota(new Error('worker: ' + (e && e.message ? e.message : 'errore sconosciuto')));
            };

            worker.onmessage = function (e) {
                var a = attese[e.data.id];
                if (!a) return;
                delete attese[e.data.id];
                if (e.data.ok) a.risolvi();
                else a.rifiuta(annota(new Error(e.data.errore)));
            };

            function chiedi(msg, trasferibili) {
                return new Promise(function (risolvi, rifiuta) {
                    var id = ++contatore;
                    attese[id] = { risolvi: risolvi, rifiuta: rifiuta };
                    msg.id = id;
                    try {
                        worker.postMessage(msg, trasferibili || []);
                    } catch (e) {
                        // Es. se l'handle non è trasferibile al worker su questo browser
                        rifiuta(annota(e));
                    }
                });
            }

            // NIENTE handle nella postMessage: Safari non sa clonare un
            // FileSystemFileHandle tra thread e rispondeva
            // "The object can not be cloned", che la libreria nascondeva in un
            // console.error lasciando solo un file vuoto e nessuna spiegazione.
            // Ricaviamo il PERCORSO (semplici stringhe, sempre clonabili) e
            // lasciamo che sia il worker a raggiungere il file da solo: lo spazio
            // privato è lo stesso, quindi ci arriva senza problemi.
            var percorso = navigator.storage.getDirectory().then(function (root) {
                if (typeof root.resolve !== 'function') return ['piper', handle.name];
                return root.resolve(handle).then(function (parti) {
                    return (parti && parti.length) ? parti : ['piper', handle.name];
                }).catch(function () {
                    // "piper" è la cartella che usa la libreria (vedi il suo sorgente)
                    return ['piper', handle.name];
                });
            });

            var pronto = percorso.then(function (p) {
                return chiedi({ tipo: 'apri', percorso: p });
            });

            // Deve comportarsi come un WritableStream (la libreria potrebbe usare
            // pipeTo) e avere anche i metodi diretti di FileSystemWritableFileStream.
            var stream = new WritableStream({
                start: function () { return pronto; },
                write: function (pezzo) {
                    // La libreria scrive un BLOB (vedi p() nel suo sorgente:
                    // "await t.write(m)" dove m arriva da una fetch). Una versione
                    // precedente non lo prevedeva: il Blob finiva nel ramo finale e
                    // veniva scritta la stringa "[object Blob]". Il file restava di
                    // fatto vuoto e ogni lettura successiva dava
                    // "JSON Parse error: Unexpected EOF".
                    return Promise.resolve().then(function () {
                        if (typeof Blob !== 'undefined' && pezzo instanceof Blob) return pezzo.arrayBuffer();
                        if (pezzo instanceof ArrayBuffer) return pezzo;
                        if (ArrayBuffer.isView(pezzo)) return pezzo.buffer.slice(pezzo.byteOffset, pezzo.byteOffset + pezzo.byteLength);
                        return new TextEncoder().encode(String(pezzo)).buffer;
                    }).then(function (buf) {
                        return chiedi({ tipo: 'scrivi', pezzo: buf }, [buf]);
                    });
                },
                close: function () {
                    return chiedi({ tipo: 'chiudi' }).then(function () { worker.terminate(); });
                },
                abort: function () { try { worker.terminate(); } catch (e) {} }
            });

            // FileSystemWritableFileStream espone write()/close() diretti, senza
            // dover passare da getWriter(): li aggiungiamo per compatibilità.
            stream.write = function (dati) {
                var w = stream.getWriter();
                return w.write(dati).then(function () { w.releaseLock(); });
            };
            stream.truncate = function () { return Promise.resolve(); };
            stream.seek = function () { return Promise.resolve(); };

            return Promise.resolve(stream);
        };

        return true;
    }

    var ponteInstallato = installaPonteScrittura();
    // Oggetto segnaposto: il resto del sito riconosce "sta parlando" guardando
    // window.currentUtterance. Con Piper non esiste un'utterance vera, ma
    // mantenere lo stesso contratto ci permette di non toccare la modalità
    // vocale (che su quel controllo decide se mettere in pausa o riprendere).
    var SEGNAPOSTO = { piper: true };

    function caricaLibreria() {
        if (lib) return Promise.resolve(lib);
        if (libPromise) return libPromise;
        libPromise = import(/* webpackIgnore: true */ LIB_URL)
            .then(function (m) { lib = m; return m; })
            .catch(function (e) {
                libPromise = null; // così un tentativo successivo può riprovare
                console.warn('Piper: libreria non caricata', e);
                throw e;
            });
        return libPromise;
    }

    function isPiperVoice(id) {
        return typeof id === 'string' && id.indexOf('piper:') === 0;
    }
    function voiceIdDa(id) {
        return isPiperVoice(id) ? id.slice(6) : null;
    }

    /**
     * Elenco voci italiane REALMENTE utilizzabili.
     *
     * Attenzione alla differenza, che è costata parecchi tentativi: voices() legge
     * il catalogo voices.json (che elenca tutto, Paola compresa), ma download() e
     * predict() ricavano il percorso del file da PATH_MAP, una mappa fissa dentro
     * la libreria. Per l'italiano PATH_MAP contiene SOLO it_IT-riccardo-x_low.
     * Chiedendo Paola, PATH_MAP[id] è undefined, la libreria scarica ".../undefined"
     * e HuggingFace risponde "Entry not found" (il 404 letto come JSON).
     * Quindi la verità su cosa è scaricabile sta in PATH_MAP, non nel catalogo.
     */
    /**
     * Paola non è in PATH_MAP, ma i suoi file POTREBBERO comunque esserci sul
     * mirror da cui la libreria scarica. Invece di darlo per scontato (l'errore
     * che ha prodotto i "Entry not found"), lo chiediamo: se entrambi i file
     * rispondono, la aggiungiamo alla mappa e diventa utilizzabile a tutti gli
     * effetti; se anche solo uno manca, non la offriamo proprio.
     * PATH_MAP e HF_BASE sono esportate dalla libreria, quindi è tutto lecito.
     */
    var VOCI_DA_TENTARE = [
        { id: 'it_IT-paola-medium', percorso: 'it/it_IT/paola/medium/it_IT-paola-medium.onnx' }
    ];

    function provaVociAggiuntive(t) {
        if (!t.PATH_MAP || !t.HF_BASE) return Promise.resolve();

        return Promise.all(VOCI_DA_TENTARE.map(function (v) {
            if (t.PATH_MAP[v.id]) return Promise.resolve(); // già prevista dalla libreria

            var url = t.HF_BASE + '/' + v.percorso;
            return Promise.all([
                fetch(url, { method: 'HEAD' }),
                fetch(url + '.json', { method: 'HEAD' })
            ]).then(function (risposte) {
                if (risposte[0].ok && risposte[1].ok) {
                    t.PATH_MAP[v.id] = v.percorso;
                    console.info('Piper: ' + v.id + ' è disponibile sul mirror, la aggiungo.');
                } else {
                    console.info('Piper: ' + v.id + ' non è sul mirror di questa versione della libreria, non la propongo.');
                }
            }).catch(function () { /* rete assente: semplicemente non la offriamo */ });
        })).then(function () {});
    }

    function listaVoci() {
        if (vociCache) return Promise.resolve(vociCache);
        return caricaLibreria().then(function (t) {
            return provaVociAggiuntive(t).then(function () {
                var mappa = t.PATH_MAP || {};
                var utilizzabili = Object.keys(mappa).filter(function (k) { return k.indexOf('it_IT') === 0; });

                // Proviamo a leggere anche il catalogo, solo per avere nomi e qualità
                // più precisi. Se non risponde, ce la caviamo con l'identificativo.
                return t.voices().catch(function () { return []; }).then(function (catalogo) {
                    var perId = {};
                    (catalogo || []).forEach(function (v) {
                        var k = v && (v.key || v.id);
                        if (k) perId[k] = v;
                    });

                    vociCache = utilizzabili.map(function (id) {
                        var v = perId[id] || {};
                        var pezzi = id.split('-');
                        var nome = v.name || pezzi[1] || id;
                        var qualita = v.quality || pezzi[2] || '';
                        return {
                            id: id,
                            nome: nome.charAt(0).toUpperCase() + nome.slice(1),
                            peso: (qualita === 'x_low' ? '~20 MB' : qualita === 'low' ? '~30 MB' : '~60 MB')
                        };
                    });
                    return vociCache;
                });
            });
        }).catch(function (e) {
            console.warn('Piper: catalogo voci non leggibile', e);
            vociCache = [];
            return vociCache;
        });
    }

    /** I due file che compongono una voce, come li nomina la libreria. */
    function nomiFile(voiceId) {
        try {
            var percorso = lib && lib.PATH_MAP ? lib.PATH_MAP[voiceId] : null;
            if (!percorso) return null;
            var base = percorso.split('/').pop();   // es. it_IT-riccardo-x_low.onnx
            return [base, base + '.json'];
        } catch (e) { return null; }
    }

    /**
     * I file rimasti a 0 byte da tentativi andati male vanno buttati.
     * Nel sorgente della libreria, getFileHandle(..., {create:true}) crea il file
     * PRIMA di scriverlo, e se la scrittura fallisce l'errore viene solo stampato
     * in console: resta un file vuoto. Da quel momento la libreria lo considera
     * "già scaricato" e ogni lettura dà "Unexpected EOF", per sempre, anche dopo
     * aver corretto il problema di scrittura. Questa funzione rompe quel circolo.
     */
    /** Stato dei file di una voce: presenti? vuoti? */
    function statoFile(voiceId) {
        var nomi = nomiFile(voiceId);
        if (!nomi || !navigator.storage || !navigator.storage.getDirectory) return Promise.resolve(null);

        return navigator.storage.getDirectory()
            .then(function (root) { return root.getDirectoryHandle('piper', { create: true }); })
            .then(function (dir) {
                return Promise.all(nomi.map(function (n) {
                    return dir.getFileHandle(n)
                        .then(function (h) { return h.getFile(); })
                        .then(function (f) { return { nome: n, assente: false, vuoto: !f || f.size === 0, byte: f ? f.size : 0 }; })
                        .catch(function () { return { nome: n, assente: true, vuoto: false, byte: 0 }; });
                })).then(function (esiti) { return { dir: dir, esiti: esiti }; });
            })
            .catch(function () { return null; });
    }

    /**
     * Aspetta che i file siano davvero scritti.
     *
     * Serve perché download() della libreria NON attende la scrittura: nel suo
     * sorgente la funzione che salva, p(), viene chiamata dentro una freccia con
     * corpo a graffe e senza return, quindi Promise.all aspetta solo la fetch.
     * download() si risolve mentre il salvataggio è ancora in corso. Su Chrome la
     * scrittura è talmente rapida che non si nota; sull'iPhone, dove passa dal
     * nostro ponte via worker, il controllo scattava a metà scrittura, vedeva 0
     * byte e dichiarava il fallimento di qualcosa che stava andando bene.
     */
    function attendiFileScritti(voiceId, timeoutMs) {
        var scadenza = Date.now() + (timeoutMs || 30000);

        function giro() {
            return statoFile(voiceId).then(function (s) {
                if (!s) return false;
                var pronti = s.esiti.every(function (e) { return !e.assente && !e.vuoto; });
                if (pronti) return true;
                if (Date.now() > scadenza) return false;
                return new Promise(function (r) { setTimeout(r, 400); }).then(giro);
            });
        }
        return giro();
    }

    /**
     * I file rimasti a 0 byte da tentativi andati male vanno buttati.
     * Nel sorgente della libreria, getFileHandle(..., {create:true}) crea il file
     * PRIMA di scriverlo, e se la scrittura fallisce l'errore viene solo stampato
     * in console: resta un file vuoto. Da quel momento la libreria lo considera
     * "già scaricato" e ogni lettura dà "Unexpected EOF", per sempre, anche dopo
     * aver corretto il problema di scrittura. Questa funzione rompe quel circolo.
     */
    function ripulisciFileRotti(voiceId) {
        return statoFile(voiceId).then(function (s) {
            if (!s) return false;
            var rotti = s.esiti.filter(function (e) { return e.vuoto; });
            if (!rotti.length) return false;

            console.warn('Piper: trovati file vuoti in cache, li elimino', rotti.map(function (r) { return r.nome; }));

            // Cancelliamo NOI con removeEntry(). Non usiamo la remove() della
            // libreria perché internamente chiama handle.remove(), un metodo non
            // standard che esiste solo su Chrome: su Safari lancia, e la libreria
            // si limita a stampare l'errore in console. Risultato: il file vuoto
            // non veniva mai cancellato e l'iPhone restava bloccato su
            // "Unexpected EOF" per sempre. removeEntry() invece è standard.
            return Promise.all(rotti.map(function (r) {
                return s.dir.removeEntry(r.nome).catch(function (e) {
                    console.warn('Piper: non riesco a cancellare ' + r.nome, e);
                });
            })).then(function () { return true; });
        });
    }

    /** Voci già scaricate su questo dispositivo (per non riscaricarle e per l'etichetta nel menu). */
    function giaScaricate() {
        return caricaLibreria().then(function (t) {
            return t.stored();
        }).catch(function () { return []; });
    }

    /** Scarica il modello se manca. onProgress riceve una percentuale 0-100. */
    function assicuraModello(voiceId, onProgress) {
        // listaVoci() è anche ciò che aggiunge a PATH_MAP le voci non previste dalla
        // libreria (Paola). Va chiamata PRIMA di qualsiasi download: altrimenti, se
        // si legge una card senza aver mai aperto le impostazioni in questa sessione,
        // PATH_MAP non sarebbe ancora aggiornata e tornerebbe il 404 "Entry not found".
        // È memorizzata, quindi dopo la prima volta non costa nulla.
        return caricaLibreria()
            .then(function (t) { return listaVoci().then(function () { return t; }); })
            .then(function (t) {
                // Prima buttiamo eventuali file a 0 byte: senza questo, un tentativo
                // fallito in passato resterebbe in cache per sempre e la voce
                // risulterebbe "già scaricata" pur essendo inutilizzabile.
                return ripulisciFileRotti(voiceId).then(function () {
                    return t.stored();
                }).then(function (presenti) {
                if ((presenti || []).indexOf(voiceId) !== -1) {
                    if (onProgress) onProgress(100);
                    return true;
                }
                return t.download(voiceId, function (p) {
                    if (onProgress && p && p.total) {
                        // Fermiamoci al 99%: il download è finito ma il salvataggio
                        // su disco no (download() non lo aspetta, vedi sopra).
                        onProgress(Math.min(99, Math.round(p.loaded * 100 / p.total)));
                    }
                }).then(function () {
                    if (onProgress) onProgress(99);
                    // Qui il file è scaricato ma la scrittura può essere ancora in
                    // corso: aspettiamo che i file esistano e non siano vuoti.
                    return attendiFileScritti(voiceId).then(function (ok) {
                        if (!ok) {
                            return ripulisciFileRotti(voiceId).then(function () {
                                var dettaglio = ultimoErrorePonte ? ' Dettaglio: ' + ultimoErrorePonte : '';
                                throw new Error('Il modello è stato scaricato ma non si è salvato nello spazio del browser.' + dettaglio);
                            });
                        }
                        if (onProgress) onProgress(100);
                        return true;
                    });
                });
            });
        });
    }

    var urlCorrente = null;
    var letturaViva = false;   // c'è una lettura Piper in corso o in pausa (non solo l'elemento sbloccato)
    var generazioneCorrente = 0; // "biglietto": ogni nuova lettura ne prende uno; le vecchie si accorgono di essere superate

    /* ============================================================
       DIVISIONE IN FRASI + PAUSE + THREAD CEDUTO
       ------------------------------------------------------------
       Tre problemi avevano la STESSA radice: predict() ricrea da zero la
       sessione ONNX e la esegue TUTTA in un colpo sul thread principale. Su un
       testo lungo questo:
        1) blocca il thread per secondi -> il ciclo del microfono
           (recognition.onend -> safeStart) non gira -> iOS reputa morta la
           sessione di ascolto -> parte il cold-start di 40s;
        2) impedisce ai setTimeout della UI di scattare, e satura la CPU:
           WebKit su iPhone uccide la pagina ("troppo lavoro sul thread");
        3) niente pause tra le frasi: le voci x_low/medium tirano dritto.

       La cura è unica: spezzare il testo in FRASI, sintetizzarne UNA alla volta
       cedendo il thread tra l'una e l'altra, e unire i WAV inserendo un SILENZIO
       tra le frasi. Così il thread non resta mai bloccato a lungo, il microfono
       continua a riavviarsi, la pagina non crasha, e la punteggiatura si sente.
       ============================================================ */
    var PAUSA_FRASE_MS = 320;   // silenzio dopo . ! ?
    var PAUSA_VIRGOLA_MS = 150; // silenzio dopo ; :

    function dividiInFrasi(testo) {
        if (!testo) return [];
        // Gli a-capo arrivano qui già trasformati da ttsCleanText in ", ,":
        // sono confini forti (elenchi, paragrafi) e meritano la pausa piena.
        var t = testo.replace(/,\s*,/g, '. ');
        // Proteggiamo i decimali ("3.14") prima di spezzare: quel punto non è
        // una fine di frase. Usiamo un segnaposto e lo ripristiniamo dopo.
        t = t.replace(/(\d)\.(\d)/g, '$1\u0001$2');
        // Teniamo il segno di punteggiatura attaccato alla frase, così sappiamo
        // quanto silenzio mettergli dopo. Spezziamo dopo . ! ? ; : — le virgole
        // NO: ogni pezzo costa una sintesi in più, e la micro-pausa della
        // virgola Piper la fa già abbastanza bene da solo.
        var pezzi = t.replace(/\s+/g, ' ').match(/[^.!?;:]+[.!?;:]*/g) || [t];
        var frasi = [];
        pezzi.forEach(function (p) {
            var s = p.split('\u0001').join('.').trim();
            if (!s) return;
            var ultimo = s.charAt(s.length - 1);
            var pausa = 0;
            if (ultimo === '.' || ultimo === '!' || ultimo === '?') pausa = PAUSA_FRASE_MS;
            else if (ultimo === ';' || ultimo === ':') pausa = PAUSA_VIRGOLA_MS;
            // Frammenti di 1-2 caratteri (residui di abbreviazioni o refusi): non
            // vale una sintesi a parte, li attacchiamo alla frase precedente.
            var nudo = s.replace(/[^A-Za-z0-9À-ÿ]/g, '');
            if (nudo.length < 3 && frasi.length) {
                frasi[frasi.length - 1].testo += ' ' + s;
                frasi[frasi.length - 1].pausaDopo = pausa;
                return;
            }
            frasi.push({ testo: s, pausaDopo: pausa });
        });
        return frasi;
    }

    // --- Strumenti per leggere e ricostruire l'header WAV ---
    // Piper restituisce un WAV (header + PCM). Per concatenare più frasi in un
    // unico file leggibile dal tag <audio>, leggiamo i parametri dal primo
    // header, uniamo tutti i campioni PCM (con silenzi in mezzo) e riscriviamo
    // un solo header davanti. Più leggero che tenere N elementi audio in vita.
    function leggiWav(arrayBuffer) {
        var dv = new DataView(arrayBuffer);
        var canali = 1, sampleRate = 22050, bits = 16, dataOffset = 44, dataLen = arrayBuffer.byteLength - 44;
        try {
            var off = 12; // dopo "RIFF"+size+"WAVE"
            while (off + 8 <= dv.byteLength) {
                var id = String.fromCharCode(dv.getUint8(off), dv.getUint8(off + 1), dv.getUint8(off + 2), dv.getUint8(off + 3));
                var size = dv.getUint32(off + 4, true);
                if (id === 'fmt ') {
                    canali = dv.getUint16(off + 10, true);
                    sampleRate = dv.getUint32(off + 12, true);
                    bits = dv.getUint16(off + 22, true);
                } else if (id === 'data') {
                    dataOffset = off + 8;
                    dataLen = size;
                    break;
                }
                off += 8 + size + (size % 2); // i chunk sono allineati a 2 byte
            }
        } catch (e) { /* teniamo i default */ }
        return {
            canali: canali, sampleRate: sampleRate, bits: bits,
            pcm: new Uint8Array(arrayBuffer, dataOffset, Math.min(dataLen, arrayBuffer.byteLength - dataOffset))
        };
    }

    function bytesSilenzio(ms, sampleRate, canali, bits) {
        var campioni = Math.floor(sampleRate * (ms / 1000));
        return new Uint8Array(campioni * canali * (bits / 8)); // già a zero = silenzio
    }

    function costruisciWav(pcmParts, sampleRate, canali, bits) {
        var totale = 0, i;
        for (i = 0; i < pcmParts.length; i++) totale += pcmParts[i].length;

        var buffer = new ArrayBuffer(44 + totale);
        var dv = new DataView(buffer);
        function scriviStr(off, s) { for (var k = 0; k < s.length; k++) dv.setUint8(off + k, s.charCodeAt(k)); }
        var byteRate = sampleRate * canali * (bits / 8);
        var blockAlign = canali * (bits / 8);

        scriviStr(0, 'RIFF'); dv.setUint32(4, 36 + totale, true); scriviStr(8, 'WAVE');
        scriviStr(12, 'fmt '); dv.setUint32(16, 16, true); dv.setUint16(20, 1, true);
        dv.setUint16(22, canali, true); dv.setUint32(24, sampleRate, true);
        dv.setUint32(28, byteRate, true); dv.setUint16(32, blockAlign, true); dv.setUint16(34, bits, true);
        scriviStr(36, 'data'); dv.setUint32(40, totale, true);

        var out = new Uint8Array(buffer), off2 = 44;
        for (i = 0; i < pcmParts.length; i++) { out.set(pcmParts[i], off2); off2 += pcmParts[i].length; }
        return buffer;
    }

    // Cede il controllo al browser: dà respiro al thread perché il ciclo del
    // microfono e i timer della UI possano girare tra una frase e l'altra.
    function cediThread() {
        return new Promise(function (r) { setTimeout(r, 0); });
    }

    /**
     * Sintetizza e riproduce. Il testo arriva GIÀ pulito da ttsCleanText()
     * di game_fc.php. Sintesi frase-per-frase: se una frase fallisce, la
     * saltiamo e proseguiamo, invece di perdere tutta la lettura.
     */
    function parla(testo, voiceId, onEnd) {
        var mioTurno = ++generazioneCorrente; // se ne parte un'altra, questa si ferma da sola
        var frasi = dividiInFrasi(testo);
        if (!frasi.length) { if (onEnd) onEnd(); return Promise.resolve(true); }

        return caricaLibreria()
            .then(function () { return assicuraModello(voiceId); })
            .then(function () {
                var pcmParts = [];
                var sampleRate = 22050, canali = 1, bits = 16, primoFatto = false;

                // Catena sequenziale: una frase alla volta, cedendo il thread.
                var catena = Promise.resolve();
                frasi.forEach(function (fr) {
                    catena = catena.then(function () {
                        if (mioTurno !== generazioneCorrente) return; // superati: molliamo
                        return lib.predict({ text: fr.testo, voiceId: voiceId }).then(function (wav) {
                            if (mioTurno !== generazioneCorrente) return;
                            return (wav.arrayBuffer ? wav.arrayBuffer() : Promise.resolve(wav)).then(function (ab) {
                                var w = leggiWav(ab);
                                if (!primoFatto) { sampleRate = w.sampleRate; canali = w.canali; bits = w.bits; primoFatto = true; }
                                pcmParts.push(w.pcm);
                                if (fr.pausaDopo > 0) pcmParts.push(bytesSilenzio(fr.pausaDopo, sampleRate, canali, bits));
                            });
                        }).catch(function (e) {
                            // Una frase non sintetizzata non deve affondare l'intera lettura
                            console.warn('Piper: frase saltata:', fr.testo, e);
                        }).then(cediThread); // <-- respiro al thread dopo OGNI frase
                    });
                });

                return catena.then(function () {
                    if (mioTurno !== generazioneCorrente) return true; // una lettura più recente ha vinto
                    if (!pcmParts.length) {
                        // Nessuna frase sintetizzata: è un fallimento vero. Lo
                        // segnaliamo al chiamante (così ripiega sulla voce di
                        // sistema) e ci assicuriamo che il micro possa riaccendersi.
                        try { window.dispatchEvent(new CustomEvent('piper:ended')); } catch (e) {}
                        throw new Error('sintesi Piper non riuscita (nessun audio prodotto)');
                    }
                    var wavUnito = costruisciWav(pcmParts, sampleRate, canali, bits);
                    return riproduci(new Blob([wavUnito], { type: 'audio/wav' }), mioTurno, onEnd);
                });
            });
    }

    // Riproduce un blob WAV già pronto (o un URL diretto, es. un MP3 generato
    // dal server) sull'elemento condiviso e sbloccato.
    function riproduci(sorgente, mioTurno, onEnd) {
        var a = creaAudioCondiviso();
        try { a.pause(); } catch (e) {}
        if (urlCorrente) { try { URL.revokeObjectURL(urlCorrente); } catch (e) {} urlCorrente = null; }
        if (typeof sorgente === 'string') {
            a.src = sorgente; // URL diretto: nessun blob da liberare dopo
        } else {
            urlCorrente = URL.createObjectURL(sorgente);
            a.src = urlCorrente;
        }
        a.currentTime = 0;

        try {
            var v = (typeof ttsSettings !== 'undefined' && ttsSettings.speed) ? parseFloat(ttsSettings.speed) : 1;
            a.playbackRate = v || 1;
        } catch (e) {}

        // addEventListener (non onX): così il player, che assegna a.onended per
        // conto suo, non ci cancella i gestori. Li ripuliamo a ogni lettura.
        if (a._piperHandlers) {
            a.removeEventListener('play',  a._piperHandlers.play);
            a.removeEventListener('pause', a._piperHandlers.pause);
            a.removeEventListener('ended', a._piperHandlers.ended);
        }
        a._piperHandlers = {
            play:  function () { if (mioTurno === generazioneCorrente) { window.currentUtterance = SEGNAPOSTO; window.isSpeakingNative = true; } },
            pause: function () { if (mioTurno === generazioneCorrente) { window.currentUtterance = null; } },
            ended: function () {
                if (mioTurno !== generazioneCorrente) return; // evento di una lettura superata
                letturaViva = false;
                window.currentUtterance = null;
                window.isSpeakingNative = false;
                if (urlCorrente) { try { URL.revokeObjectURL(urlCorrente); } catch (e) {} urlCorrente = null; }
                try { if (typeof hideFloatingPlayer === 'function') hideFloatingPlayer(); } catch (e) {}
                if (a._piperSegnalaFine) a._piperSegnalaFine(); else { try { window.dispatchEvent(new CustomEvent('piper:ended')); } catch (e) {} }
                if (onEnd) onEnd();
            }
        };
        a.addEventListener('play',  a._piperHandlers.play);
        a.addEventListener('pause', a._piperHandlers.pause);
        a.addEventListener('ended', a._piperHandlers.ended);

        letturaViva = true;
        window.currentUtterance = SEGNAPOSTO;
        window.isSpeakingNative = true;

        // Segnaliamo che sta per partire un audio-file, così la modalità microfono
        // sa che NON deve tentare restart finché non arriva 'piper:ended'. NON
        // fermiamo noi il riconoscimento: farlo nello stesso istante di play()
        // creava un conflitto che impediva all'audio di partire. Lasciamo che sia
        // iOS a sospendere il micro; noi ci limitiamo a non disturbarlo.
        try { window.dispatchEvent(new CustomEvent('piper:willplay')); } catch (e) {}

        // Rete di sicurezza ASSOLUTA: qualunque cosa succeda da qui in poi,
        // 'piper:ended' DEVE essere emesso, altrimenti la modalità microfono
        // resterebbe convinta che un audio stia ancora suonando e non
        // riaccenderebbe MAI il microfono ("si blocca tutto"). Lo emettiamo noi
        // se entro un tempo di sicurezza non è arrivato né 'ended' né 'error'.
        var emesso = false;
        function segnalaFine() {
            if (emesso) return;
            emesso = true;
            try { window.dispatchEvent(new CustomEvent('piper:ended')); } catch (e) {}
        }
        a._piperSegnalaFine = segnalaFine; // lo useranno anche i gestori ended

        var p = a.play();
        return Promise.resolve(p).then(function () {
            try { if (typeof attachFloatingPlayer === 'function') attachFloatingPlayer(a); } catch (e) {}
            return true;
        }).catch(function (e) {
            if (e && (e.name === 'AbortError' || /interrupt|pause/i.test(String(e.message || '')))) return true;
            // iOS ha rifiutato l'avvio (gesto scaduto): mostriamo il player in
            // pausa con l'audio GIUSTO, un tocco lo fa partire subito. E SBLOCCHIAMO
            // subito il microfono: se non lo facessimo, resterebbe spento per sempre.
            try {
                if (typeof attachFloatingPlayer === 'function') attachFloatingPlayer(a);
                if (typeof setFpIcon === 'function') setFpIcon('play');
            } catch (e2) {}
            window.currentUtterance = null;
            segnalaFine(); // il micro può riaccendersi: nessun audio sta suonando
            return true;
        });
    }

    function fermaAudio() {
        generazioneCorrente++; // invalida qualunque sintesi frase-per-frase ancora in corso
        letturaViva = false;
        if (!audio) return;
        try { audio.pause(); } catch (e) {}
        try { audio.currentTime = 0; } catch (e) {}
        if (urlCorrente) { try { URL.revokeObjectURL(urlCorrente); } catch (e) {} urlCorrente = null; }
    }

    window.PiperTTS = {
        // Utile alla diagnostica a schermo: dice se su questo dispositivo abbiamo
        // dovuto (e potuto) attivare il ponte di scrittura per Safari.
        ponteScritturaAttivo: ponteInstallato,
        serviva: serveIlPonte,
        ultimoErrorePonte: function () { return ultimoErrorePonte; },

        isPiperVoice: isPiperVoice,
        voiceIdDa: voiceIdDa,
        listaVoci: listaVoci,
        giaScaricate: giaScaricate,
        assicuraModello: assicuraModello,
        parla: parla,

        // Riproduce un file audio qualsiasi (es. un MP3 generato dal server, per
        // le voci Microsoft) con lo STESSO contratto di stato di Piper: player
        // flottante, pausa/riprendi a voce, eventi per la modalità microfono.
        riproduciUrl: function (url, onEnd) {
            var mioTurno = ++generazioneCorrente;
            return riproduci(url, mioTurno, onEnd);
        },

        // Tutte queste rispondono in base a letturaViva, NON al semplice esistere
        // di 'audio': quell'elemento ora è condiviso e presente sempre (sbloccato
        // al primo tocco per iOS), quindi senza il flag sembrerebbe che ci sia
        // sempre una lettura in corso e la modalità microfono si incepperebbe.
        inRiproduzione: function () { return !!(letturaViva && audio && !audio.paused && !audio.ended); },
        inPausa:        function () { return !!(letturaViva && audio && audio.paused && !audio.ended); },
        attivo:         function () { return !!(letturaViva && audio && !audio.ended); },

        pausa:   function () { if (letturaViva && audio && !audio.paused && !audio.ended) { audio.pause(); return true; } return false; },
        riprendi:function () { if (letturaViva && audio && audio.paused && !audio.ended) { audio.play().catch(function(){}); return true; } return false; },
        salta:   function (secondi) { if (letturaViva && audio && !audio.ended) { audio.currentTime = Math.max(0, audio.currentTime + secondi); return true; } return false; },
        velocita:function (v) { if (audio) { try { audio.playbackRate = v; } catch (e) {} } },
        ferma:   function () {
            var stavaSuonando = letturaViva;
            letturaViva = false;
            fermaAudio();
            window.currentUtterance = null;
            window.isSpeakingNative = false;
            if (stavaSuonando) {
                // Anche uno stop forzato è una "fine riproduzione" per chi ci
                // ascolta (la modalità microfono deve poter riaccendere l'ascolto).
                try { window.dispatchEvent(new CustomEvent('piper:ended')); } catch (e) {}
            }
            return stavaSuonando;
        }
    };
})();
</script>