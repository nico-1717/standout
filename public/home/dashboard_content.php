<?php

require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/auth_core.php';
require_once __DIR__ . '/../../lib/piano_helpers.php';
require_once __DIR__ . '/../social/friends_core.php';   // sezione amici

$standout_utente = standout_utente_corrente();
if (!$standout_utente) { return; } // index.php dovrebbe già garantirlo, ma per sicurezza usciamo comunque

$standout_piano = standout_get_piano_utente($standout_utente['id']);
$uid = $standout_utente['id'];

/* quante notifiche di amicizia in sospeso (serve per il pallino rosso) */
$nd_notifiche = fr_conta_notifiche(fr_conn(), (int)$uid);

/* ==========================================================================
   DATI REALI per il widget statistiche a rotazione
   (stessa logica/tabelle di fc_stats.php, in versione semplificata)
   ========================================================================== */
/* sec_db() riusa la connessione gia' aperta invece di aprirne una seconda:
   questo file e' incluso da index.php, che una connessione ce l'ha gia'. */
$standout_conn = sec_db();

/* I numeri delle flashcard vengono da fc_summary.php, lo stesso riepilogo
   della pagina principale delle flashcard (fc_home.php): studio e lingue
   insieme, una sola fonte. Prima qui c'erano query proprie che, fra l'altro,
   contavano le carte di TUTTI gli utenti e leggevano la vecchia tabella
   "gameplays", che non esiste piu'. */
require_once __DIR__ . '/../../lib/fc/fc_summary.php';

$nd_streak = 0; $nd_accuracy = 0; $nd_mastery_pct = 0;
$nd_settimana = [];
for ($i = 6; $i >= 0; $i--) { $nd_settimana[date('Y-m-d', strtotime("-$i day"))] = 0; }

if ($standout_conn) {
    $nd_fc = fc_riepilogo((int)$uid);
    $nd_streak    = (int)$nd_fc['serie'];
    $nd_accuracy  = (int)$nd_fc['precisione'];
    $nd_settimana = $nd_fc['settimana'];
    $nd_totali    = (int)$nd_fc['study']['carte'] + (int)$nd_fc['vocab']['parole'];
    $nd_imparate  = (int)$nd_fc['study']['imparate'] + (int)$nd_fc['vocab']['imparate'];
    $nd_mastery_pct = $nd_totali > 0 ? min(100, (int)round($nd_imparate / $nd_totali * 100)) : 0;
}

$nd_barra = function (string $etichetta, int $usato, $limite, string $colore): string {
    $pct = $limite ? max(0, min(100, (int)round(($usato / max(1, (int)$limite)) * 100))) : 0;
    
    // Spezziamo la stringa con un tag span per impedire a Safari su iPhone di riconoscerlo come numero di telefono
    if ($limite !== null) {
        $testo_html = sec_h($usato) . ' <span>/</span> ' . sec_h($limite);
    } else {
        $testo_html = sec_h($usato) . ' <span>/</span> illimitato';
    }
    
    $out  = '<div class="nd-usage-item"><span class="lbl">' . sec_h($etichetta) . '</span>';
    $out .= '<div class="nd-usage-track"><div class="nd-usage-fill" style="width:' . $pct . '%;background:' . $colore . '"></div></div>';
    $out .= '<div class="nd-usage-num">' . $testo_html . '</div></div>';
    return $out;
};

/* le iniziali dei giorni arrivano dal dizionario: in inglese sono M T W T F S S,
   e una sigla di due lettere non si ricava tagliando il nome italiano */
$nd_giorni_lbl = [t('dashboard.g_lun'), t('dashboard.g_mar'), t('dashboard.g_mer'),
                  t('dashboard.g_gio'), t('dashboard.g_ven'), t('dashboard.g_sab'), t('dashboard.g_dom')];
$nd_week_max = max(1, max($nd_settimana));
?>

<?php include __DIR__ . '/../partials/sidebar.html'; ?>

<div class="standout-dash">
  <div class="nd-wrap">

    <div class="nd-hero nd-pop">
      <div class="nd-hero-top">
        <?php if ($nd_streak > 0): ?>
          <div class="nd-streak-pill">🔥 <?php echo (int)$nd_streak; ?></div>
        <?php endif; ?>
      </div>
      <div class="nd-hero-content">
        <h1 class="nd-greeting"><?php te('dashboard.saluto', ['nome' => $standout_utente['username']]); ?></h1>
        <p class="nd-greeting-sub"><?php te('dashboard.pronto_a_ripassare_qualcosa_oggi'); ?></p>
      </div>
    </div>

    <div class="nd-top-grid">
      <div class="nd-triv-wrap nd-pop" style="animation-delay:.05s">
        <div class="nd-triv-glow"></div>
        <div class="nd-triv-card" id="ndTrivCard" tabindex="0" role="button" aria-label="<?php te('dashboard.tocca_per_scoprire_la_risposta'); ?>">
          <div class="nd-triv-inner">
            <div class="nd-triv-face nd-triv-front">
              <span class="nd-triv-tag" id="ndTrivTag"><?php te('dashboard.curiosita'); ?></span>
              <p id="ndTrivQ"><?php te('comune.carica'); ?></p>
              <span class="nd-triv-hint"><?php te('dashboard.tocca_per_scoprire'); ?></span>
              
              <div class="nd-triv-shine"></div> 
            </div>
            <div class="nd-triv-face nd-triv-back">
              <span class="nd-triv-tag" id="ndTrivTagBack"><?php te('dashboard.risposta'); ?></span>
              <p id="ndTrivA">…</p>
              <button class="nd-triv-next" id="ndTrivNext" type="button"><?php te('dashboard.prossima_carta'); ?></button>
            </div>
          </div>
        </div>
      </div> <!-- Fine nd-triv-wrap -->

      <!-- LA GRIGLIA (nd-top-grid) CONTINUA QUI SOTTO -->
      <div class="nd-stats-rotator nd-pop" style="animation-delay:.1s" id="ndStatsRotator">
        <div class="nd-stat-slide is-active">
          <svg class="nd-donut" viewBox="0 0 120 120">
            <circle cx="60" cy="60" r="52" fill="none" stroke="var(--hairline)" stroke-width="12"/>
            <circle cx="60" cy="60" r="52" fill="none" stroke="url(#ndGradMastery)" stroke-width="12" stroke-linecap="round"
                    stroke-dasharray="326.7" stroke-dashoffset="<?php echo (float)(326.7 * (1 - $nd_mastery_pct / 100)); ?>" transform="rotate(-90 60 60)"/>
            <defs><linearGradient id="ndGradMastery" x1="0" y1="0" x2="1" y2="1">
              <stop offset="0%" stop-color="var(--blue)"/><stop offset="100%" stop-color="var(--purple)"/>
            </linearGradient></defs>
          </svg>
          <div class="nd-stat-num"><?php echo (int)$nd_mastery_pct; ?>%</div>
          <div class="nd-stat-label"><?php te('dashboard.carte_padroneggiate'); ?></div>
        </div>
        <div class="nd-stat-slide">
          <div class="nd-stat-flame">🔥</div>
          <div class="nd-stat-num"><?php echo (int)$nd_streak; ?></div>
          <div class="nd-stat-label"><?php te($nd_streak === 1 ? 'dashboard.giorno_di_fila' : 'dashboard.giorni_di_fila'); ?></div>
        </div>
        <div class="nd-stat-slide">
          <div class="nd-week-bars">
            <?php foreach ($nd_settimana as $data => $val):
                $h = max(6, (int)round(($val / $nd_week_max) * 54));
                $dow = (int)date('N', strtotime($data)) - 1;
            ?>
              <div class="nd-week-bar-wrap">
                <div class="nd-week-bar" style="height:<?php echo (int)$h; ?>px"></div>
                <span><?php echo sec_h($nd_giorni_lbl[$dow]); ?></span>
              </div>
            <?php endforeach; ?>
          </div>
          <div class="nd-stat-label" style="margin-top:6px;"><?php te('dashboard.ultimi_7_giorni'); ?></div>
        </div>
        <div class="nd-stat-slide">
          <div class="nd-stat-num"><?php echo (int)$nd_accuracy; ?>%</div>
          <div class="nd-stat-label"><?php te('dashboard.precisione_30_giorni'); ?></div>
        </div>
        <div class="nd-stat-dots">
          <span class="is-active"></span><span></span><span></span><span></span>
        </div>
      </div>
    </div>

    <?php if ($standout_piano): ?>
    <div class="nd-plan-card nd-pop" style="animation-delay:.15s">
      <div class="nd-plan-head">
        <span class="nd-plan-name"><?php echo sec_h(ucfirst($standout_piano['name'])); ?></span>
        <span class="nd-plan-head-note"><?php te('dashboard.utilizzo_di_questo_mese'); ?></span>
      </div>
      <div class="nd-usage-grid">
        <?php
          echo $nd_barra(t('dashboard.caratteri_audio'), (int)$standout_piano['monthly_audio_chars'], $standout_piano['audio_chars_limit'], 'var(--purple)');
          echo $nd_barra(t('dashboard.immagini_generate'), (int)$standout_piano['monthly_generated_images'], $standout_piano['images_limit'], 'var(--pink)');
          echo $nd_barra(t('dashboard.token_testuali'), (int)$standout_piano['monthly_text_tokens'], $standout_piano['text_tokens_limit'], 'var(--green)');
        ?>
      </div>
    </div>
    <?php endif; ?>

    <div class="nd-actions">
      <a href="fc/fc_stats.php" class="nd-action nd-pop" style="animation-delay:.2s">
        <span class="ic" style="background:linear-gradient(135deg,var(--blue),var(--teal));">📊</span>
        <h3><?php te('dashboard.le_tue_statistiche'); ?></h3>
        <p><?php te('dashboard.streak_obiettivo_giornaliero_traguardi_e'); ?></p>
      </a>
      <a href="fc/fc_insert.php" class="nd-action nd-pop" style="animation-delay:.25s">
        <span class="ic" style="background:linear-gradient(135deg,var(--purple),var(--pink));">✦</span>
        <h3><?php te('dashboard.crea_nuove_flashcard'); ?></h3>
        <p><?php te('dashboard.da_solo_o_con_il'); ?></p>
      </a>
      <a href="fc/fc_home.php" class="nd-action nd-pop" style="animation-delay:.3s">
        <span class="ic" style="background:linear-gradient(135deg,var(--orange),var(--yellow));">🎮</span>
        <h3><?php te('dashboard.gioca_ripassa'); ?></h3>
        <p><?php te('dashboard.scegli_una_categoria_e_mettiti'); ?></p>
      </a>
      <a href="social/friends.php" class="nd-action nd-pop" style="animation-delay:.35s">
        <span class="ic" style="background:linear-gradient(135deg,#FFB302,#FF3B7A 46%,#7B3FE4);">👥</span>
        <?php if ($nd_notifiche > 0): ?><span class="nd-dot"><?php echo (int)$nd_notifiche; ?></span><?php endif; ?>
        <h3><?php te('dashboard.amici_e_classifica'); ?></h3>
        <p><?php te('dashboard.confronta_streak_focus_e_traguardi'); ?></p>
      </a>
    </div>

    <h2 class="nd-tips-title nd-pop" style="animation-delay:.4s"><?php te('dashboard.come_sfruttare_al_meglio_standout'); ?></h2>
    <div class="nd-tips">
      <div class="nd-tip nd-pop" style="animation-delay:.38s; background:var(--purple-soft);"><span class="emoji">⚡</span><?php th('dashboard.consiglio_frantuma', ['evidenza' => '<b>' . t('dashboard.consiglio_frantuma_ev') . '</b>']); ?></div>
      <div class="nd-tip nd-pop" style="animation-delay:.41s; background:var(--blue-soft);"><span class="emoji">🎙</span><?php th('dashboard.consiglio_tutor', ['evidenza' => '<b>' . t('dashboard.consiglio_tutor_ev') . '</b>']); ?></div>
      <div class="nd-tip nd-pop" style="animation-delay:.44s; background:var(--green-soft);"><span class="emoji">💡</span><?php th('dashboard.consiglio_esempio', ['evidenza' => '<b>' . t('dashboard.consiglio_esempio_ev') . '</b>']); ?></div>
      <div class="nd-tip nd-pop" style="animation-delay:.47s; background:var(--orange-soft);"><span class="emoji">🎯</span><?php th('dashboard.consiglio_obiettivo', ['evidenza' => '<b>' . t('dashboard.consiglio_obiettivo_ev') . '</b>']); ?></div>
      <div class="nd-tip nd-pop" style="animation-delay:.5s; background:var(--red-soft);"><span class="emoji">🚗</span><?php th('dashboard.consiglio_mani_libere', ['evidenza' => '<b>' . t('dashboard.consiglio_mani_libere_ev') . '</b>']); ?></div>
    </div>

  </div>
</div>

<?php include __DIR__ . '/../partials/notifiche_widget.php'; ?>

<?php echo standout_i18n_json(['comune', 'dashboard']); ?>
<script>
(function(){
  const slides = document.querySelectorAll('#ndStatsRotator .nd-stat-slide');
  const dots = document.querySelectorAll('#ndStatsRotator .nd-stat-dots span');
  let idx = 0;
  if (slides.length > 1 && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
    setInterval(function () {
      slides[idx].classList.remove('is-active');
      dots[idx].classList.remove('is-active');
      idx = (idx + 1) % slides.length;
      slides[idx].classList.add('is-active');
      dots[idx].classList.add('is-active');
    }, 4000);
  }

  /* Le domande esistono in una versione per lingua: trivia.json (italiano,
     l'originale) e trivia_<lingua>.json. La lingua e' quella della pagina,
     che config.php scrive in <html lang>. */
  const LINGUA = (document.documentElement.lang || 'it').slice(0, 2);
  const FILE_TRIVIA = LINGUA === 'it' ? 'home/trivia.json' : 'home/trivia_' + LINGUA + '.json';

  const FALLBACK = LINGUA === 'en' ? [
    { tipo: 'trivia', domanda: 'What is the capital of Romania?', risposta: 'Bucharest' },
    { tipo: 'trivia', domanda: 'How many bones does the adult human body have?', risposta: '206' },
    { tipo: 'quote', domanda: 'Live as if you were to die tomorrow. Learn as if you were to live forever.', risposta: 'Mahatma Gandhi (attributed)' }
  ] : [
    { tipo: 'trivia', domanda: 'Qual è la capitale della Romania?', risposta: 'Bucarest' },
    { tipo: 'trivia', domanda: 'Quante ossa ha il corpo umano adulto?', risposta: '206' },
    { tipo: 'quote', domanda: 'Non smettere mai di imparare, perché la vita non smette mai di insegnare.', risposta: 'Proverbio' }
  ];

  const card = document.getElementById('ndTrivCard');
  const elQ = document.getElementById('ndTrivQ');
  const elA = document.getElementById('ndTrivA');
  const elTag = document.getElementById('ndTrivTag');
  const elTagBack = document.getElementById('ndTrivTagBack');
  const btnNext = document.getElementById('ndTrivNext');
  
  let caricando = false;
  let databaseCarte = []; 
  let ultimaCarta = null; // Variabile per ricordare cosa è appena uscito

  function mostraCarta(dati) {
    elTag.textContent = dati.tipo;
    elTagBack.textContent = T(dati.tipo.includes('quote') ? 'dashboard.autore' : 'dashboard.risposta');
    elQ.textContent = dati.domanda;
    elA.textContent = dati.risposta;
  }

  async function nuovaCarta() {
    if (caricando) return;
    caricando = true;
    
    try {
      // Se il database è vuoto, lo scarichiamo dal file locale
      if (databaseCarte.length === 0) {
        elQ.textContent = T('comune.carica'); // Mostra caricamento solo la prima volta
        let res = await fetch(FILE_TRIVIA);
        /* una lingua senza file suo ripiega sull'italiano */
        if (!res.ok && FILE_TRIVIA !== 'home/trivia.json') res = await fetch('home/trivia.json');
        if (!res.ok) throw new Error('File JSON non trovato');
        databaseCarte = await res.json();
      }

      // Evitiamo che esca la stessa carta due volte di fila
      let carteDisponibili = databaseCarte;
      if (databaseCarte.length > 1 && ultimaCarta !== null) {
        carteDisponibili = databaseCarte.filter(c => c.domanda !== ultimaCarta.domanda);
      }

      // Pesca una carta a caso e salvala in memoria
      const cartaScelta = carteDisponibili[Math.floor(Math.random() * carteDisponibili.length)];
      ultimaCarta = cartaScelta;
      mostraCarta(cartaScelta);

    } catch (e) {
      console.error("Errore nel caricamento del JSON, uso il fallback:", e);
      
      // Stessa logica anti-doppione per il Fallback
      let fallbackDisponibili = FALLBACK;
      if (FALLBACK.length > 1 && ultimaCarta !== null) {
        fallbackDisponibili = FALLBACK.filter(c => c.domanda !== ultimaCarta.domanda);
      }
      const cartaScelta = fallbackDisponibili[Math.floor(Math.random() * fallbackDisponibili.length)];
      ultimaCarta = cartaScelta;
      mostraCarta(cartaScelta);

    } finally {
      caricando = false;
    }
  }

  if (card) {
    card.addEventListener('click', function () {
      card.classList.toggle('is-flipped');
    });
    card.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); card.classList.toggle('is-flipped'); }
    });
  }
  
  if (btnNext) {
    btnNext.addEventListener('click', function (e) {
      e.stopPropagation(); // Evita che il click chiuda e riapra la carta simultaneamente
      card.classList.remove('is-flipped');
      // Aspetta 400 millisecondi prima di cambiare il testo per nasconderlo mentre la carta gira
      setTimeout(nuovaCarta, 400); 
    });
  }
  
  nuovaCarta();
})();
</script>