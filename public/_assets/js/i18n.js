/* Standout - le traduzioni lato JavaScript.
 *
 * Le stringhe arrivano gia' nella pagina, dentro un <script type="application/json">
 * scritto da standout_i18n_json(). Non c'e' nessuna richiesta al server: la pagina
 * e' gia' stata costruita nella lingua giusta, quindi le sue stringhe viaggiano
 * insieme a lei.
 *
 * Uso, uguale a quello PHP:  T('planner.salva')  /  T('comune.ciao', {nome: 'Nico'})
 */
(function () {

  var dati = {};
  try {
    var contenitore = document.getElementById('i18n-dati');
    if (contenitore) dati = JSON.parse(contenitore.textContent) || {};
  } catch (e) {
    // una pagina senza traduzioni deve restare usabile: si vedranno le chiavi,
    // non una schermata bianca
    if (window.console) console.warn('Traduzioni non leggibili:', e);
  }

  function cerca(chiave) {
    var nodo = dati;
    var pezzi = String(chiave).split('.');
    for (var i = 0; i < pezzi.length; i++) {
      if (nodo === null || typeof nodo !== 'object' || !(pezzi[i] in nodo)) return null;
      nodo = nodo[pezzi[i]];
    }
    return (typeof nodo === 'string') ? nodo : null;
  }

  window.T = function (chiave, sostituzioni) {
    var testo = cerca(chiave);
    // stessa scelta del PHP: una chiave mancante si deve VEDERE
    if (testo === null) return '<<' + chiave + '>>';

    if (sostituzioni) {
      for (var nome in sostituzioni) {
        if (Object.prototype.hasOwnProperty.call(sostituzioni, nome)) {
          testo = testo.split('{' + nome + '}').join(String(sostituzioni[nome]));
        }
      }
    }
    return testo;
  };

  /* Comodo per il CSS e per i formati di data: <html lang="..."> lo mette gia'
     PHP, qui lo si rende leggibile anche al JavaScript senza doverlo ripetere. */
  window.T.lingua = document.documentElement.lang || 'it';
})();
