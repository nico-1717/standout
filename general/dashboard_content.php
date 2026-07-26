<?php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_core.php';
require_once __DIR__ . '/piano_helpers.php';
require_once __DIR__ . '/friends_core.php';   // sezione amici

$nisama_utente = nisama_utente_corrente();
if (!$nisama_utente) { return; } // index.php dovrebbe già garantirlo, ma per sicurezza usciamo comunque

$nisama_piano = nisama_get_piano_utente($nisama_utente['id']);
$uid = $nisama_utente['id'];

/* quante notifiche di amicizia in sospeso (serve per il pallino rosso) */
$nd_notifiche = fr_conta_notifiche(fr_conn(), (int)$uid);

/* ==========================================================================
   DATI REALI per il widget statistiche a rotazione
   (stessa logica/tabelle di stats_fc.php, in versione semplificata)
   ========================================================================== */
$nisama_conn = mysqli_connect(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);

$nd_total_cards = 0; $nd_learnt_cards = 0; $nd_mastery_pct = 0;
$nd_streak = 0; $nd_accuracy = 0;
$nd_settimana = [];
for ($i = 6; $i >= 0; $i--) { $nd_settimana[date('Y-m-d', strtotime("-$i day"))] = 0; }

if ($nisama_conn) {
    // Conteggio totale carte (fc_images e fc_child_cards con i nuovi nomi colonna standardizzati)
    $totRes = mysqli_query($nisama_conn, "SELECT COUNT(*) AS c FROM (SELECT id FROM fc_images WHERE is_father = 0 UNION ALL SELECT id_child FROM fc_child_cards) t");
    $nd_total_cards = $totRes ? (int)mysqli_fetch_assoc($totRes)['c'] : 0;

    // Carte imparate dall'utente (tabella rinominata user_learnt_fc)
    $learntRes = mysqli_query($nisama_conn, "SELECT COUNT(DISTINCT fc_image_id) AS c FROM user_learnt_fc WHERE user_id = " . (int)$uid);
    $nd_learnt_cards = $learntRes ? (int)mysqli_fetch_assoc($learntRes)['c'] : 0;
    $nd_mastery_pct = $nd_total_cards > 0 ? (int)round(($nd_learnt_cards / $nd_total_cards) * 100) : 0;

    // MODIFICA: Attività settimanale unificata (gameplays + fc_gameplays)
    $weekRes = mysqli_query($nisama_conn, "SELECT d, COUNT(*) AS c FROM (
        SELECT DATE(gp.play_date) AS d, gm.user_id
        FROM gameplays gp
        JOIN games gm ON gp.game_id = gm.id
        WHERE gp.play_date >= (CURDATE() - INTERVAL 6 DAY)
        UNION ALL
        SELECT DATE(fcg.play_date) AS d, gm.user_id
        FROM fc_gameplays fcg
        JOIN games gm ON fcg.game_id = gm.id
        WHERE fcg.play_date >= (CURDATE() - INTERVAL 6 DAY)
    ) t 
    WHERE t.user_id = " . (int)$uid . "
    GROUP BY d");
    if ($weekRes) { while ($row = mysqli_fetch_assoc($weekRes)) { if (isset($nd_settimana[$row['d']])) { $nd_settimana[$row['d']] = (int)$row['c']; } } }

    // MODIFICA: Calcolo della streak unificato (giorni di gioco sia su gameplays che su fc_gameplays)
    $streakRes = mysqli_query($nisama_conn, "SELECT DISTINCT d FROM (
        SELECT DATE(gp.play_date) AS d, gm.user_id
        FROM gameplays gp
        JOIN games gm ON gp.game_id = gm.id
        WHERE gp.play_date IS NOT NULL
        UNION ALL
        SELECT DATE(fcg.play_date) AS d, gm.user_id
        FROM fc_gameplays fcg
        JOIN games gm ON fcg.game_id = gm.id
        WHERE fcg.play_date IS NOT NULL
    ) t 
    WHERE t.user_id = " . (int)$uid . "
    ORDER BY d ASC");
    $giorni = [];
    if ($streakRes) { while ($row = mysqli_fetch_assoc($streakRes)) { $giorni[] = $row['d']; } }
    $run = 0; $prev = null;
    foreach ($giorni as $d) {
        $dateObj = new DateTime($d);
        if ($prev !== null && (int)$prev->diff($dateObj)->format('%a') == 1) { $run++; } else { $run = 1; }
        $prev = $dateObj;
    }
    if (!empty($giorni)) {
        $oggi = new DateTime('today');
        $ultimo = new DateTime(end($giorni));
        $diff = (int)$oggi->diff($ultimo)->format('%r%a');
        if ($diff === 0 || $diff === -1) { $nd_streak = $run; }
    }

    // MODIFICA: Precisione unificata degli ultimi 30 giorni (gameplays + fc_gameplays)
    $accRes = mysqli_query($nisama_conn, "SELECT
        SUM(CASE WHEN t.user_answer = 'Correct' THEN 1 ELSE 0 END) AS corrette,
        SUM(CASE WHEN t.user_answer IN ('Correct','Wrong') THEN 1 ELSE 0 END) AS totali
    FROM (
        SELECT gp.user_answer, gp.play_date, gm.user_id
        FROM gameplays gp
        JOIN games gm ON gp.game_id = gm.id
        WHERE gp.play_date >= (CURDATE() - INTERVAL 29 DAY)
        UNION ALL
        SELECT fcg.user_answer, fcg.play_date, gm.user_id
        FROM fc_gameplays fcg
        JOIN games gm ON fcg.game_id = gm.id
        WHERE fcg.play_date >= (CURDATE() - INTERVAL 29 DAY)
    ) t 
    WHERE t.user_id = " . (int)$uid);
    if ($accRes) {
        $r = mysqli_fetch_assoc($accRes);
        if ($r && (int)$r['totali'] > 0) { $nd_accuracy = (int)round(((int)$r['corrette'] / (int)$r['totali']) * 100); }
    }
}

$nd_barra = function (string $etichetta, int $usato, $limite, string $colore): string {
    $pct = $limite ? max(0, min(100, (int)round(($usato / max(1, (int)$limite)) * 100))) : 0;
    
    // Spezziamo la stringa con un tag span per impedire a Safari su iPhone di riconoscerlo come numero di telefono
    if ($limite !== null) {
        $testo_html = htmlspecialchars($usato, ENT_QUOTES) . ' <span>/</span> ' . htmlspecialchars($limite, ENT_QUOTES);
    } else {
        $testo_html = htmlspecialchars($usato, ENT_QUOTES) . ' <span>/</span> illimitato';
    }
    
    $out  = '<div class="nd-usage-item"><span class="lbl">' . htmlspecialchars($etichetta, ENT_QUOTES) . '</span>';
    $out .= '<div class="nd-usage-track"><div class="nd-usage-fill" style="width:' . $pct . '%;background:' . $colore . '"></div></div>';
    $out .= '<div class="nd-usage-num">' . $testo_html . '</div></div>';
    return $out;
};

$nd_giorni_lbl = ['Lu','Ma','Me','Gi','Ve','Sa','Do'];
$nd_week_max = max(1, max($nd_settimana));
?>
<style>
:root{
    --bg:#F2F2F7; --surface:#FFFFFF; --surface-2:#F2F2F7;
    --text:#1C1C1E; --text-2:#8A8A8E; --text-3:#AEAEB2;
    --hairline:rgba(60,60,67,.12); --hairline-2:rgba(60,60,67,.22);
    --blue:#007AFF; --green:#34C759; --red:#FF3B30; --orange:#FF9500; --purple:#AF52DE;
    --teal:#30B0C7; --pink:#FF2D55; --indigo:#5856D6; --yellow:#FFCC00;
    --blue-soft:rgba(0,122,255,.09); --green-soft:rgba(52,199,89,.13); --red-soft:rgba(255,59,48,.12);
    --orange-soft:rgba(255,149,0,.14); --purple-soft:rgba(175,82,222,.12);
    --header-glass:rgba(249,249,251,.82);
    --shadow-card:0 1px 1px rgba(0,0,0,.04), 0 10px 30px rgba(0,0,0,.07);
    --btn-hl:inset 0 1px 0 rgba(255,255,255,.25), inset 0 -1px 0 rgba(0,0,0,.16);
    --raise-sm:0 1px 1.5px rgba(0,0,0,.08), 0 5px 12px -4px rgba(0,0,0,.13);
    --r-card:22px; --r-btn:15px;
    --spring:cubic-bezier(.32,.72,0,1);
    --safe-top:env(safe-area-inset-top, 0px);
    --safe-bottom:env(safe-area-inset-bottom, 0px);
}
@media (prefers-color-scheme: dark){
    :root{
        --bg:#000000; --surface:#1C1C1E; --surface-2:#2C2C2E;
        --text:#F5F5F7; --text-2:#98989F; --text-3:#636366;
        --hairline:rgba(84,84,88,.42); --hairline-2:rgba(84,84,88,.62);
        --blue:#0A84FF; --green:#30D158; --red:#FF453A; --orange:#FF9F0A; --purple:#BF5AF2;
        --teal:#40C8E0; --pink:#FF375F; --indigo:#5E5CE6; --yellow:#FFD60A;
        --blue-soft:rgba(10,132,255,.16); --green-soft:rgba(48,209,88,.17); --red-soft:rgba(255,69,58,.17);
        --orange-soft:rgba(255,159,10,.18); --purple-soft:rgba(191,90,242,.18);
        --header-glass:rgba(16,16,18,.78);
        --shadow-card:0 1px 1px rgba(0,0,0,.35), 0 10px 30px rgba(0,0,0,.5);
        --btn-hl:inset 0 1px 0 rgba(255,255,255,.22), inset 0 -1px 0 rgba(0,0,0,.3);
        --raise-sm:0 1px 1.5px rgba(0,0,0,.45), 0 5px 12px -4px rgba(0,0,0,.55);
    }
}

.nisama-dash{ font-family:-apple-system,BlinkMacSystemFont,"SF Pro Text","SF Pro Display",system-ui,"Segoe UI",Roboto,sans-serif; background:var(--bg); color:var(--text); min-height:100vh; padding:28px 5vw calc(60px + var(--safe-bottom)); }
.nisama-dash *{ box-sizing:border-box; }
.nd-wrap{ max-width:1080px; margin:0 auto; }
.nd-pop{ opacity:0; transform:translateY(18px) scale(.98); animation:nd-in .55s var(--spring) forwards; }
@keyframes nd-in{ to{ opacity:1; transform:none; } }

.nd-hero{ position:relative; overflow:hidden; border-radius:28px; padding:30px 26px; margin-bottom:22px; background:linear-gradient(135deg, var(--blue), var(--indigo) 55%, var(--purple)); color:#fff; box-shadow:var(--shadow-card); }
.nd-hero::before{ content:""; position:absolute; inset:-40%; background:radial-gradient(circle at 20% 20%, rgba(255,255,255,.35), transparent 40%), radial-gradient(circle at 85% 75%, rgba(255,255,255,.25), transparent 45%); animation:nd-blob-move 12s ease-in-out infinite alternate; }
@keyframes nd-blob-move{ from{ transform:translate(0,0) rotate(0deg); } to{ transform:translate(-4%,3%) rotate(8deg); } }
.nd-hero-content{ position:relative; z-index:1; }
.nd-greeting{ font-weight:800; font-size:clamp(24px,4vw,32px); margin:0 0 4px; letter-spacing:-.02em; }
.nd-greeting-sub{ margin:0; font-size:14.5px; opacity:.92; font-weight:500; }
.nd-hero-top{ position:absolute; top:20px; right:20px; z-index:3; display:flex; align-items:center; gap:10px; }
.nd-streak-pill{ display:flex; align-items:center; gap:6px; background:rgba(255,255,255,.22); backdrop-filter:blur(10px); border-radius:999px; padding:8px 14px; font-weight:800; font-size:14px; }
.nd-action .nd-dot{ position:absolute; top:14px; right:14px; min-width:21px; height:21px; padding:0 5px; border-radius:999px; background:var(--red); color:#fff; font-size:12px; font-weight:800; line-height:21px; text-align:center; box-shadow:0 3px 10px rgba(255,59,48,.55); }

.nd-top-grid{ display:grid; grid-template-columns:1.3fr 1fr; gap:18px; margin-bottom:18px; }
@media (max-width:760px){ .nd-top-grid{ grid-template-columns:1fr; } }

.nd-triv-wrap{ position:relative; height:250px; display:flex; align-items:center; justify-content:center; }
/* 1. Il contenitore fisso che modella la forma dell'alone e lo sfoca */
.nd-triv-glow {
    position: absolute;
    inset: -6px; /* Definisce quanto l'alone sporge dalla carta */
    border-radius: 28px; /* Leggermente più grande del raggio della carta */
    filter: blur(16px); /* Sfoca il risultato finale */
    opacity: .65;
    z-index: 0;
    overflow: hidden; /* FONDAMENTALE: nasconde gli angoli del gradiente che gira */
}

/* 2. Il gradiente vero e proprio, reso gigantesco e fatto ruotare all'interno */
.nd-triv-glow::before {
    content: "";
    position: absolute;
    top: -50%;
    left: -50%;
    width: 200%;
    height: 200%;
    background: conic-gradient(from 0deg, var(--blue), var(--purple), var(--pink), var(--orange), var(--blue));
    animation: nd-glow-spin 7s linear infinite;
}@keyframes nd-glow-spin{ to{ transform:rotate(360deg); } }
.nd-triv-card{ position:relative; z-index:1; width:100%; height:100%; perspective:1200px; cursor:pointer; outline:none; }
.nd-triv-inner{ position:relative; width:100%; height:100%; transform-style:preserve-3d; transition:transform .8s var(--spring); }
.nd-triv-card.is-flipped .nd-triv-inner{ transform:rotateY(180deg); }
.nd-triv-face{ position:absolute; inset:0; backface-visibility:hidden; -webkit-backface-visibility:hidden; background:var(--surface); border-radius:var(--r-card); border:.5px solid var(--hairline); box-shadow:var(--shadow-card); padding:24px; display:flex; flex-direction:column; justify-content:center; overflow:hidden; }
.nd-triv-back{ transform:rotateY(180deg); align-items:flex-start; }
.nd-triv-tag{ font-size:10.5px; font-weight:800; letter-spacing:.12em; text-transform:uppercase; color:var(--purple); margin-bottom:12px; }
.nd-triv-face p{ margin:0; font-size:18px; font-weight:700; line-height:1.4; color:var(--text); }
.nd-triv-hint{ margin-top:auto; padding-top:14px; font-size:12px; color:var(--text-2); font-weight:600; }
.nd-triv-next{ margin-top:16px; appearance:none; border:none; cursor:pointer; font-family:inherit; font-weight:800; font-size:13px; background:var(--blue-soft); color:var(--blue); padding:9px 16px; border-radius:999px; }
.nd-triv-shine{ position:absolute; top:-50%; left:-60%; width:40%; height:200%; background:linear-gradient(115deg, transparent, rgba(255,255,255,.55), transparent); transform:rotate(20deg); animation:nd-shine 5s ease-in-out infinite; pointer-events:none; }
@keyframes nd-shine{ 0%,80%,100%{ left:-60%; } 40%{ left:130%; } }

.nd-stats-rotator{ position:relative; background:var(--surface); border:.5px solid var(--hairline); border-radius:var(--r-card); box-shadow:var(--shadow-card); height:250px; display:flex; flex-direction:column; align-items:center; justify-content:center; overflow:hidden; }
.nd-stat-slide{ display:none; flex-direction:column; align-items:center; justify-content:center; gap:6px; text-align:center; padding:16px; }
.nd-stat-slide.is-active{ display:flex; animation:nd-slide-in .5s var(--spring); }
@keyframes nd-slide-in{ from{ opacity:0; transform:translateY(8px) scale(.96); } to{ opacity:1; transform:none; } }
.nd-donut{ width:104px; height:104px; }
.nd-stat-num{ font-size:30px; font-weight:800; letter-spacing:-.02em; }
.nd-stat-flame{ font-size:38px; line-height:1; }
.nd-stat-label{ font-size:12.5px; color:var(--text-2); font-weight:700; }
.nd-week-bars{ display:flex; align-items:flex-end; gap:8px; height:64px; }
.nd-week-bar-wrap{ display:flex; flex-direction:column; align-items:center; gap:6px; }
.nd-week-bar{ width:9px; border-radius:5px; background:linear-gradient(180deg, var(--teal), var(--blue)); min-height:6px; }
.nd-week-bar-wrap span{ font-size:10px; color:var(--text-3); font-weight:700; }
.nd-stat-dots{ position:absolute; bottom:14px; display:flex; gap:6px; }
.nd-stat-dots span{ width:6px; height:6px; border-radius:50%; background:var(--hairline-2); transition:background .3s, transform .3s; }
.nd-stat-dots span.is-active{ background:var(--blue); transform:scale(1.3); }

.nd-plan-card{ background:var(--surface); border:.5px solid var(--hairline); border-radius:var(--r-card); box-shadow:var(--shadow-card); padding:22px; margin-bottom:18px; }
.nd-plan-head{ display:flex; justify-content:space-between; align-items:center; margin-bottom:18px; flex-wrap:wrap; gap:8px; }
.nd-plan-name{ font-size:11px; text-transform:uppercase; letter-spacing:.08em; background:linear-gradient(135deg,var(--blue),var(--purple)); color:#fff; padding:5px 13px; border-radius:999px; font-weight:800; }
.nd-plan-head-note{ font-size:12.5px; color:var(--text-2); font-weight:600; }
.nd-usage-grid{ display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:20px; }
.nd-usage-item .lbl{ font-size:11.5px; color:var(--text-2); display:block; margin-bottom:8px; font-weight:700; }
.nd-usage-track{ height:7px; border-radius:5px; background:var(--hairline); overflow:hidden; }
.nd-usage-fill{ height:100%; border-radius:5px; }
.nd-usage-num{ font-size:11.5px; color:var(--text-2); margin-top:6px; font-weight:600; }

.nd-actions{ display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:14px; margin-bottom:26px; }
.nd-action{ position:relative; background:var(--surface); border:.5px solid var(--hairline); border-radius:18px; padding:20px; text-decoration:none; color:var(--text); box-shadow:var(--raise-sm); transition:transform .25s var(--spring), box-shadow .25s ease; overflow:hidden; display:block; }
.nd-action:active{ transform:scale(.97); }
.nd-action:hover{ transform:translateY(-3px); box-shadow:var(--shadow-card); }
.nd-action .ic{ width:44px; height:44px; border-radius:14px; display:flex; align-items:center; justify-content:center; font-size:20px; margin-bottom:14px; color:#fff; }
.nd-action h3{ margin:0 0 5px; font-size:15px; font-weight:800; }
.nd-action p{ margin:0; font-size:12.5px; color:var(--text-2); line-height:1.5; font-weight:500; }

.nd-tips-title{ font-size:19px; font-weight:800; margin:0 0 16px; }
.nd-tips{ display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:12px; }
.nd-tip{ position:relative; border-radius:16px; padding:16px; font-size:13px; line-height:1.55; font-weight:600; color:var(--text); overflow:hidden; box-shadow:var(--raise-sm); }
.nd-tip .emoji{ font-size:20px; display:block; margin-bottom:8px; }
.nd-tip b{ font-weight:800; }

@media (prefers-reduced-motion: reduce){
    .nd-pop{ animation:none; opacity:1; transform:none; }
    .nd-hero::before, .nd-triv-glow, .nd-triv-shine{ animation:none; }
}
</style>

<?php include 'sidebar.html'; ?>

<div class="nisama-dash">
  <div class="nd-wrap">

    <div class="nd-hero nd-pop">
      <div class="nd-hero-top">
        <?php if ($nd_streak > 0): ?>
          <div class="nd-streak-pill">🔥 <?php echo (int)$nd_streak; ?></div>
        <?php endif; ?>
      </div>
      <div class="nd-hero-content">
        <h1 class="nd-greeting">Ciao, <?php echo htmlspecialchars($nisama_utente['username'], ENT_QUOTES); ?> 👋</h1>
        <p class="nd-greeting-sub">Pronto a ripassare qualcosa oggi?</p>
      </div>
    </div>

    <div class="nd-top-grid">
      <div class="nd-triv-wrap nd-pop" style="animation-delay:.05s">
        <div class="nd-triv-glow"></div>
        <div class="nd-triv-card" id="ndTrivCard" tabindex="0" role="button" aria-label="Tocca per scoprire la risposta">
          <div class="nd-triv-inner">
            <div class="nd-triv-face nd-triv-front">
              <span class="nd-triv-tag" id="ndTrivTag">Curiosità</span>
              <p id="ndTrivQ">Caricamento…</p>
              <span class="nd-triv-hint">Tocca per scoprire →</span>
              
              <div class="nd-triv-shine"></div> 
            </div>
            <div class="nd-triv-face nd-triv-back">
              <span class="nd-triv-tag" id="ndTrivTagBack">Risposta</span>
              <p id="ndTrivA">…</p>
              <button class="nd-triv-next" id="ndTrivNext" type="button">Prossima carta ✦</button>
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
                    stroke-dasharray="326.7" stroke-dashoffset="<?php echo 326.7 * (1 - $nd_mastery_pct / 100); ?>" transform="rotate(-90 60 60)"/>
            <defs><linearGradient id="ndGradMastery" x1="0" y1="0" x2="1" y2="1">
              <stop offset="0%" stop-color="var(--blue)"/><stop offset="100%" stop-color="var(--purple)"/>
            </linearGradient></defs>
          </svg>
          <div class="nd-stat-num"><?php echo (int)$nd_mastery_pct; ?>%</div>
          <div class="nd-stat-label">Carte padroneggiate</div>
        </div>
        <div class="nd-stat-slide">
          <div class="nd-stat-flame">🔥</div>
          <div class="nd-stat-num"><?php echo (int)$nd_streak; ?></div>
          <div class="nd-stat-label"><?php echo $nd_streak === 1 ? 'giorno di fila' : 'giorni di fila'; ?></div>
        </div>
        <div class="nd-stat-slide">
          <div class="nd-week-bars">
            <?php foreach ($nd_settimana as $data => $val):
                $h = max(6, (int)round(($val / $nd_week_max) * 54));
                $dow = (int)date('N', strtotime($data)) - 1;
            ?>
              <div class="nd-week-bar-wrap">
                <div class="nd-week-bar" style="height:<?php echo $h; ?>px"></div>
                <span><?php echo $nd_giorni_lbl[$dow]; ?></span>
              </div>
            <?php endforeach; ?>
          </div>
          <div class="nd-stat-label" style="margin-top:6px;">Ultimi 7 giorni</div>
        </div>
        <div class="nd-stat-slide">
          <div class="nd-stat-num"><?php echo (int)$nd_accuracy; ?>%</div>
          <div class="nd-stat-label">Precisione (30 giorni)</div>
        </div>
        <div class="nd-stat-dots">
          <span class="is-active"></span><span></span><span></span><span></span>
        </div>
      </div>
    </div>

    <?php if ($nisama_piano): ?>
    <div class="nd-plan-card nd-pop" style="animation-delay:.15s">
      <div class="nd-plan-head">
        <span class="nd-plan-name"><?php echo htmlspecialchars(ucfirst($nisama_piano['name']), ENT_QUOTES); ?></span>
        <span class="nd-plan-head-note">Utilizzo di questo mese</span>
      </div>
      <div class="nd-usage-grid">
        <?php
          echo $nd_barra('Caratteri audio', (int)$nisama_piano['monthly_audio_chars'], $nisama_piano['audio_chars_limit'], 'var(--purple)');
          echo $nd_barra('Immagini generate', (int)$nisama_piano['monthly_generated_images'], $nisama_piano['images_limit'], 'var(--pink)');
          echo $nd_barra('Token testuali IA', (int)$nisama_piano['monthly_text_tokens'], $nisama_piano['text_tokens_limit'], 'var(--green)');
        ?>
      </div>
    </div>
    <?php endif; ?>

    <div class="nd-actions">
      <a href="stats_fc.php" class="nd-action nd-pop" style="animation-delay:.2s">
        <span class="ic" style="background:linear-gradient(135deg,var(--blue),var(--teal));">📊</span>
        <h3>Le tue statistiche</h3>
        <p>Streak, obiettivo giornaliero, traguardi e confronto con la famiglia.</p>
      </a>
      <a href="inserimento_big_fc.php" class="nd-action nd-pop" style="animation-delay:.25s">
        <span class="ic" style="background:linear-gradient(135deg,var(--purple),var(--pink));">✦</span>
        <h3>Crea nuove flashcard</h3>
        <p>Da solo o con il prompt pronto per l'IA: incolli, ricontrolli, salvi.</p>
      </a>
      <a href="history_fc.php" class="nd-action nd-pop" style="animation-delay:.3s">
        <span class="ic" style="background:linear-gradient(135deg,var(--orange),var(--yellow));">🎮</span>
        <h3>Gioca / ripassa</h3>
        <p>Scegli una categoria e mettiti alla prova.</p>
      </a>
      <a href="friends.php" class="nd-action nd-pop" style="animation-delay:.35s">
        <span class="ic" style="background:linear-gradient(135deg,#FFB302,#FF3B7A 46%,#7B3FE4);">👥</span>
        <?php if ($nd_notifiche > 0): ?><span class="nd-dot"><?php echo (int)$nd_notifiche; ?></span><?php endif; ?>
        <h3>Amici e classifica</h3>
        <p>Confronta streak, focus e traguardi. E scopri chi oggi è stato più concentrato.</p>
      </a>
    </div>

    <h2 class="nd-tips-title nd-pop" style="animation-delay:.4s">Come sfruttare al meglio Nisama</h2>
    <div class="nd-tips">
      <div class="nd-tip nd-pop" style="animation-delay:.38s; background:var(--purple-soft);"><span class="emoji">⚡</span><b>Frantuma</b> una scheda quando hai poca energia: la trasforma in tante micro-domande rapide che, insieme, ricostruiscono il discorso intero.</div>
      <div class="nd-tip nd-pop" style="animation-delay:.41s; background:var(--blue-soft);"><span class="emoji">🎙</span>Usa il <b>tutor vocale</b> per farti spiegare un concetto con una metafora e un'intonazione naturale, con la voce che preferisci.</div>
      <div class="nd-tip nd-pop" style="animation-delay:.44s; background:var(--green-soft);"><span class="emoji">💡</span>Genera un <b>esempio pratico</b> con un tocco solo, quando la teoria da sola non ti basta per capire davvero.</div>
      <div class="nd-tip nd-pop" style="animation-delay:.47s; background:var(--orange-soft);"><span class="emoji">🎯</span>Il tuo <b>obiettivo giornaliero</b>, la streak e i traguardi sono nelle Statistiche: puoi modificarli quando vuoi.</div>
      <div class="nd-tip nd-pop" style="animation-delay:.5s; background:var(--red-soft);"><span class="emoji">🚗</span>La <b>modalità mani libere</b>, per ripassare parlando in auto o a passeggio, arriverà presto.</div>
    </div>

  </div>
</div>

<?php include __DIR__ . '/notifiche_widget.php'; ?>

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

  const FALLBACK = [
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
    elTagBack.textContent = dati.tipo.includes('quote') ? 'Autore' : 'Risposta';
    elQ.textContent = dati.domanda;
    elA.textContent = dati.risposta;
  }

  async function nuovaCarta() {
    if (caricando) return;
    caricando = true;
    
    try {
      // Se il database è vuoto, lo scarichiamo dal file locale
      if (databaseCarte.length === 0) {
        elQ.textContent = 'Caricamento…'; // Mostra caricamento solo la prima volta
        const res = await fetch('trivia.json');
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