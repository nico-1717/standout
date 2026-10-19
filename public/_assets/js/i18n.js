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
  var letti = 0;

  /* I contenitori sono uno per la pagina (id="i18n-dati") e uno per ogni
     componente che ne porta altri (class="i18n-dati"). Quelli dei componenti
     possono arrivare DOPO questo file, percio' quando una chiave manca si
     rilegge: costa poco e succede solo la prima volta. */
  function leggi() {
    var tutti = document.querySelectorAll('#i18n-dati, script.i18n-dati');
    if (tutti.length === letti) return false;
    for (var i = letti; i < tutti.length; i++) {
      try {
        var d = JSON.parse(tutti[i].textContent) || {};
        for (var g in d) if (Object.prototype.hasOwnProperty.call(d, g)) dati[g] = d[g];
      } catch (e) {
        // una pagina senza traduzioni deve restare usabile: si vedranno le
        // chiavi, non una schermata bianca
        if (window.console) console.warn('Translations not readable:', e);
      }
    }
    letti = tutti.length;
    return true;
  }
  leggi();

  function cerca(chiave) {
    var trovato = cercaIn(chiave);
    if (trovato === null && leggi()) trovato = cercaIn(chiave);
    return trovato;
  }

  function cercaIn(chiave) {
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
