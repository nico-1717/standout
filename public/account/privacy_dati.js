/* Privacy e dati: abilita il tasto "elimina" solo con la casella spuntata e la
   password scritta, e manda la richiesta. standout_fetch.js aggiunge da solo il
   token CSRF a ogni fetch. */
(function () {
  'use strict';
  var T = {};
  try { T = JSON.parse(document.getElementById('pdTxt').textContent); } catch (e) {}
  var ack = document.getElementById('pdAck');
  var pw = document.getElementById('pdPw');
  var btn = document.getElementById('pdBtn');
  var msg = document.getElementById('pdMsg');
  var form = document.getElementById('pdDelete');
  if (!ack || !pw || !btn || !form) return;

  function aggiorna() { btn.disabled = !(ack.checked && pw.value.length > 0); }
  ack.addEventListener('change', aggiorna);
  pw.addEventListener('input', aggiorna);

  function mostra(testo, ok) {
    msg.textContent = testo;
    msg.hidden = false;
    msg.classList.toggle('lg-msg-ok', !!ok);
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    if (btn.disabled) return;
    btn.disabled = true;
    btn.textContent = T.attesa || '…';
    msg.hidden = true;
    fetch('elimina_account.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify({ password: pw.value, conferma: true })
    }).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (j) { return { status: r.status, j: j }; });
    }).then(function (x) {
      if (x.j && x.j.success) {
        mostra(T.ok || 'OK', true);
        setTimeout(function () { location.href = '../'; }, 1800);
        return;
      }
      pw.value = '';
      mostra(x.status === 403 ? (T.err_pw || 'Error') : x.status === 429 ? (T.err_troppe || 'Error') : (T.err || 'Error'), false);
      btn.textContent = T.btn || '';
      aggiorna();
    }).catch(function () {
      mostra(T.err || 'Error', false);
      btn.textContent = T.btn || '';
      aggiorna();
    });
  });
})();


/* Cambia password: il tasto si accende solo con i tre campi pieni; gli errori di
   validazione (password comune, troppo corta, diverse) vengono dal server, che e'
   l'unico a conoscere l'elenco delle password diffuse. */
(function () {
  'use strict';
  var T = {};
  try { T = JSON.parse(document.getElementById('pdTxt').textContent); } catch (e) {}
  var form = document.getElementById('pdPass');
  var vecchia = document.getElementById('pdPwOld');
  var nuova = document.getElementById('pdPwNew');
  var conferma = document.getElementById('pdPwNew2');
  var btn = document.getElementById('pdPassBtn');
  var msg = document.getElementById('pdPassMsg');
  if (!form || !vecchia || !nuova || !conferma || !btn || !msg) return;

  function aggiorna() { btn.disabled = !(vecchia.value && nuova.value && conferma.value); }
  [vecchia, nuova, conferma].forEach(function (c) { c.addEventListener('input', aggiorna); });

  function mostra(testo, ok) {
    msg.textContent = testo;
    msg.hidden = false;
    msg.classList.toggle('lg-msg-ok', !!ok);
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    if (btn.disabled) return;
    btn.disabled = true;
    btn.textContent = T.cambia_attesa || '…';
    msg.hidden = true;
    fetch('cambia_password.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify({ attuale: vecchia.value, nuova: nuova.value, conferma: conferma.value })
    }).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (j) { return { status: r.status, j: j }; });
    }).then(function (x) {
      btn.textContent = T.cambia_btn || '';
      if (x.j && x.j.success) {
        vecchia.value = nuova.value = conferma.value = '';
        mostra(T.cambia_ok || 'OK', true);
        aggiorna();
        return;
      }
      if (x.status === 422 && x.j && x.j.errori) {
        mostra(Object.keys(x.j.errori).map(function (k) { return x.j.errori[k]; }).join(' '), false);
      } else {
        vecchia.value = '';
        mostra(x.status === 403 ? (T.err_pw || 'Error') : x.status === 429 ? (T.err_troppe || 'Error') : (T.err || 'Error'), false);
      }
      aggiorna();
    }).catch(function () {
      btn.textContent = T.cambia_btn || '';
      mostra(T.err || 'Error', false);
      aggiorna();
    });
  });
})();
