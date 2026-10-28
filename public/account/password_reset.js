/* «Password dimenticata»: i due moduli (chiedere il link, scegliere la password
   nuova). Nessun script dentro la pagina (CSP): i testi arrivano da #prTxt e il
   token CSRF dal <meta>. */
(function () {
  'use strict';
  var T = {};
  try { T = JSON.parse(document.getElementById('prTxt').textContent); } catch (e) {}
  var meta = document.querySelector('meta[name="csrf-token"]');
  var csrf = meta ? meta.getAttribute('content') : '';
  var msg = document.getElementById('prMsg');
  var btn = document.getElementById('prBtn');

  function mostra(testo, ok) {
    if (!msg) return;
    msg.textContent = testo;
    msg.hidden = false;
    msg.classList.toggle('ok', !!ok);
  }

  /* POST JSON con il token CSRF; ritorna {status, j}. */
  function invia(url, corpo) {
    return fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': csrf },
      body: JSON.stringify(corpo)
    }).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (j) { return { status: r.status, j: j }; });
    });
  }

  /* 1) chiedere il link */
  var richiesta = document.getElementById('prRichiesta');
  if (richiesta) {
    richiesta.addEventListener('submit', function (e) {
      e.preventDefault();
      var id = document.getElementById('prIdentifier');
      if (!id.value.trim()) { mostra(T.err_vuoto || 'Error', false); return; }
      btn.disabled = true;
      btn.textContent = T.attesa || '…';
      msg.hidden = true;
      invia('password_dimenticata.php', { identifier: id.value }).then(function (x) {
        btn.textContent = T.btn || '';
        if (x.j && x.j.success) {
          mostra(x.j.message || 'OK', true);
          id.value = '';
          return;                       // il tasto resta spento: il link e' gia' partito
        }
        btn.disabled = false;
        mostra(x.status === 429 ? (T.err_troppe || 'Error') : (T.err || 'Error'), false);
      }).catch(function () {
        btn.disabled = false;
        btn.textContent = T.btn || '';
        mostra(T.err || 'Error', false);
      });
    });
  }

  /* 2) scegliere la password nuova */
  var nuova = document.getElementById('prNuova');
  if (nuova) {
    nuova.addEventListener('submit', function (e) {
      e.preventDefault();
      var p1 = document.getElementById('prPw1');
      var p2 = document.getElementById('prPw2');
      btn.disabled = true;
      btn.textContent = T.attesa || '…';
      msg.hidden = true;
      invia('password_nuova.php', { t: document.getElementById('prToken').value, nuova: p1.value, conferma: p2.value }).then(function (x) {
        btn.textContent = T.btn || '';
        if (x.j && x.j.success) {
          var pagina = document.getElementById('prPagina');
          pagina.textContent = '';
          var h = document.createElement('h1'); h.textContent = T.ok_t || 'OK';
          var p = document.createElement('p'); p.textContent = T.ok_p || '';
          var a = document.createElement('a'); a.className = 'bottone'; a.href = T.home || '/'; a.textContent = T.vai || '';
          pagina.appendChild(h); pagina.appendChild(p); pagina.appendChild(a);
          return;
        }
        if (x.status === 422 && x.j && x.j.errori) {
          btn.disabled = false;
          mostra(Object.keys(x.j.errori).map(function (k) { return x.j.errori[k]; }).join(' '), false);
          return;
        }
        if (x.status === 410) {          // il link non e' piu' buono: si chiede uno nuovo
          var pg = document.getElementById('prPagina');
          pg.textContent = '';
          var h2 = document.createElement('h1'); h2.textContent = T.ko_t || '';
          var p2el = document.createElement('p'); p2el.textContent = T.ko_p || '';
          var a2 = document.createElement('a'); a2.className = 'bottone'; a2.href = T.richiesta || '#'; a2.textContent = T.richiedi || '';
          pg.appendChild(h2); pg.appendChild(p2el); pg.appendChild(a2);
          return;
        }
        btn.disabled = false;
        mostra(x.status === 429 ? (T.err_troppe || 'Error') : (T.err || 'Error'), false);
      }).catch(function () {
        btn.disabled = false;
        btn.textContent = T.btn || '';
        mostra(T.err || 'Error', false);
      });
    });
  }
})();
