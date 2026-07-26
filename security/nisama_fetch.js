/* =====================================================================
   NISAMA — TOKEN AUTOMATICO SULLE CHIAMATE
   Va incluso nel <head>, prima di ogni altro script:

     <meta name="csrf-token" content="...">
     <script src="nisama_fetch.js"></script>

   Da qui in avanti ogni fetch() e ogni XMLHttpRequest che scrive
   (POST, PUT, PATCH, DELETE) verso il nostro dominio porta con sé
   l'header X-CSRF-Token, senza dover modificare una riga del codice
   già scritto nelle pagine.

   Serve perché il server, da security_core.php, rifiuta le scritture
   senza token: è la difesa contro le richieste create da altri siti
   sfruttando la sessione aperta dell'utente.
   ===================================================================== */
(function () {
    'use strict';

    var meta = document.querySelector('meta[name="csrf-token"]');
    var TOKEN = meta ? meta.getAttribute('content') : (window.NISAMA_CSRF || '');
    if (!TOKEN) return;
    window.NISAMA_CSRF = TOKEN;

    var WRITE = { POST: 1, PUT: 1, PATCH: 1, DELETE: 1 };

    /** true solo per le richieste che restano dentro il nostro dominio. */
    function sameOrigin(url) {
        try {
            if (url === undefined || url === null) return true;
            var s = String(url);
            if (s === '' || s.charAt(0) === '/' || s.charAt(0) === '?' || s.charAt(0) === '#') return true;
            if (!/^[a-z][a-z0-9+.-]*:/i.test(s)) return true;       // percorso relativo
            return new URL(s, location.href).origin === location.origin;
        } catch (e) {
            return false;
        }
    }

    /* ---------- recupero automatico del token ----------
       Su hosting condiviso la sessione PHP può essere ripulita prima del
       previsto: la pagina resta aperta con un token vecchio e la prima
       scrittura viene rifiutata con "sessione scaduta", pur essendo
       l'utente ancora loggato. Invece di mostrargli l'errore, prendiamo
       un token nuovo e rimandiamo la richiesta una volta sola. */
    var refreshing = null;
    function refreshToken() {
        if (refreshing) return refreshing;
        refreshing = nativeFetchRef('csrf_token.php', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d && d.ok && d.token) { TOKEN = d.token; window.NISAMA_CSRF = TOKEN; stampAll(); }
                refreshing = null;
                return TOKEN;
            })
            .catch(function () { refreshing = null; return TOKEN; });
        return refreshing;
    }
    function looksLikeCsrfFail(res, text) {
        if (res.status !== 403) return false;
        return /"error"\s*:\s*"csrf"/.test(text) || /Sessione scaduta/i.test(text);
    }

    /* ---------- fetch ---------- */
    var nativeFetchRef = window.fetch ? window.fetch.bind(window) : null;
    if (window.fetch) {
        var nativeFetch = window.fetch;
        window.fetch = function (input, init) {
            init = init || {};
            var url = (typeof input === 'string') ? input : (input && input.url);
            var method = String(init.method || (input && input.method) || 'GET').toUpperCase();

            if (WRITE[method] && sameOrigin(url)) {
                var h = init.headers;
                if (h instanceof Headers) {
                    if (!h.has('X-CSRF-Token')) h.set('X-CSRF-Token', TOKEN);
                } else if (Array.isArray(h)) {
                    var found = h.some(function (p) { return String(p[0]).toLowerCase() === 'x-csrf-token'; });
                    if (!found) h.push(['X-CSRF-Token', TOKEN]);
                    init.headers = h;
                } else {
                    var obj = {};
                    for (var k in (h || {})) if (Object.prototype.hasOwnProperty.call(h, k)) obj[k] = h[k];
                    var has = Object.keys(obj).some(function (k) { return k.toLowerCase() === 'x-csrf-token'; });
                    if (!has) obj['X-CSRF-Token'] = TOKEN;
                    init.headers = obj;
                }
                if (!init.credentials) init.credentials = 'same-origin';
            }
            var self = this;
            if (!(WRITE[method] && sameOrigin(url))) return nativeFetch.call(self, input, init);

            /* se il token era vecchio, lo rinnoviamo e riproviamo una volta */
            return nativeFetch.call(self, input, init).then(function (res) {
                if (res.status !== 403 || init.__nisamaRetried) return res;
                return res.clone().text().then(function (text) {
                    if (!looksLikeCsrfFail(res, text)) return res;
                    return refreshToken().then(function (tok) {
                        init.__nisamaRetried = true;
                        if (init.headers instanceof Headers) init.headers.set('X-CSRF-Token', tok);
                        else {
                            var h = {};
                            for (var k in (init.headers || {})) if (Object.prototype.hasOwnProperty.call(init.headers, k)) h[k] = init.headers[k];
                            h['X-CSRF-Token'] = tok;
                            init.headers = h;
                        }
                        /* se il token viaggiava nel corpo, aggiorniamo anche quello */
                        if (typeof init.body === 'string' && init.body.indexOf('csrf_token=') !== -1) {
                            init.body = init.body.replace(/csrf_token=[^&]*/, 'csrf_token=' + encodeURIComponent(tok));
                        } else if (init.body instanceof FormData && init.body.has('csrf_token')) {
                            init.body.set('csrf_token', tok);
                        }
                        return nativeFetch.call(self, input, init);
                    });
                });
            });
        };
    }

    /* ---------- XMLHttpRequest (per il codice più vecchio) ---------- */
    var open = XMLHttpRequest.prototype.open;
    var send = XMLHttpRequest.prototype.send;
    XMLHttpRequest.prototype.open = function (method, url) {
        this.__nisamaWrite = !!WRITE[String(method || '').toUpperCase()] && sameOrigin(url);
        return open.apply(this, arguments);
    };
    XMLHttpRequest.prototype.send = function () {
        if (this.__nisamaWrite) {
            try { this.setRequestHeader('X-CSRF-Token', TOKEN); } catch (e) { /* già inviato */ }
        }
        return send.apply(this, arguments);
    };

    /* ---------- moduli inviati come <form> ----------
       Il token va messo NEI moduli appena esistono, non al momento dell'invio:
       form.submit() chiamato da JavaScript non fa scattare l'evento 'submit',
       quindi un ascoltatore su quell'evento verrebbe semplicemente saltato e
       la richiesta partirebbe senza token. */
    function stampForm(form) {
        if (!form || form.tagName !== 'FORM') return;
        if (String(form.method || 'get').toLowerCase() !== 'post') return;
        if (!sameOrigin(form.getAttribute('action'))) return;
        if (form.querySelector('input[name="csrf_token"]')) return;
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'csrf_token';
        input.value = TOKEN;
        form.appendChild(input);
    }
    function stampAll(root) {
        var forms = (root || document).querySelectorAll ? (root || document).querySelectorAll('form') : [];
        for (var i = 0; i < forms.length; i++) stampForm(forms[i]);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { stampAll(); });
    } else {
        stampAll();
    }

    /* i moduli creati dopo (popup, sezioni caricate al volo) lo ricevono da sé */
    if (window.MutationObserver) {
        new MutationObserver(function (records) {
            for (var i = 0; i < records.length; i++) {
                var added = records[i].addedNodes;
                for (var k = 0; k < added.length; k++) {
                    var n = added[k];
                    if (n.nodeType !== 1) continue;
                    if (n.tagName === 'FORM') stampForm(n);
                    else stampAll(n);
                }
            }
        }).observe(document.documentElement, { childList: true, subtree: true });
    }

    /* rete di sicurezza per i moduli inviati con un vero clic */
    document.addEventListener('submit', function (e) { stampForm(e.target); }, true);
})();