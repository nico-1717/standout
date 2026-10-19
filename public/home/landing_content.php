<?php
/* =====================================================================
   STANDOUT — la pagina di chi non è ancora entrato.

   Rifatta da capo il 19/10/2026. Quella di prima parlava solo di flashcard
   (l'app era nata così) e pesava: GSAP e ScrollTrigger caricati in mezzo
   alla pagina, quattrocento carte animate, due fogli di Google Fonts che
   bloccavano il disegno. Il pulsante «Accedi», che sta in fondo al body,
   compariva solo dopo tutto questo: su un telefono in 4G, secondi.

   Adesso:
   - la prima schermata è solo HTML e CSS, e si disegna subito;
   - niente librerie: le comparse allo scorrimento sono un
     IntersectionObserver di dieci righe, che parte dopo il primo disegno;
   - i caratteri arrivano senza bloccare (si vede subito il carattere di
     sistema, poi si passa a quello vero);
   - il telefono finto in alto è disegnato in CSS: nessuna immagine da
     scaricare, e i testi sono tradotti come il resto.
   ===================================================================== */
$lp_smart = t('lp.m_smart');
$lp_n = function_exists('mb_strlen') ? mb_strlen($lp_smart, 'UTF-8') : strlen($lp_smart);

/* le icone: tratti semplici, colore dal contenitore */
$lp_ico = [
    'planner'  => '<path d="M5 6.5h14a1.5 1.5 0 011.5 1.5v11A1.5 1.5 0 0119 20.5H5A1.5 1.5 0 013.5 19V8A1.5 1.5 0 015 6.5z"/><path d="M3.5 11h17M8 4v4M16 4v4M8 15h3"/>',
    'progetti' => '<circle cx="12" cy="13" r="7.5"/><path d="M12 13V9.2M12 13l2.6 1.6M10 3.5h4"/>',
    'flashcard'=> '<rect x="3.5" y="7" width="13" height="12" rx="2"/><path d="M7.5 7V5.5A1.5 1.5 0 019 4h10a1.5 1.5 0 011.5 1.5v9A1.5 1.5 0 0119 16h-2.5"/>',
    'lingue'   => '<path d="M4 6h9M8.5 4v2M6 6c.6 3 2.7 5.6 5.5 7M11 6c-.8 3.3-3 6.3-6.5 8"/><path d="M13 20l3.5-8 3.5 8M14.2 17.4h4.6"/>',
    'studio'   => '<path d="M3.5 8.5L12 4.5l8.5 4-8.5 4z"/><path d="M7 10.5v4.2c1.4 1.3 3.1 1.8 5 1.8s3.6-.5 5-1.8v-4.2M20.5 8.5v5"/>',
    'abitudini'=> '<circle cx="12" cy="12" r="8"/><path d="M12 4a8 8 0 018 8"/><path d="M8.6 12.2l2.2 2.2 4.4-4.6"/>',
    'diario'   => '<path d="M6.5 3.5h11A1.5 1.5 0 0119 5v14a1.5 1.5 0 01-1.5 1.5h-11A1.5 1.5 0 015 19V5a1.5 1.5 0 011.5-1.5z"/><path d="M9 8h6M9 11.5h6M9 15h3.5"/>',
    'liste'    => '<path d="M9.5 6.5h10M9.5 12h10M9.5 17.5h10"/><path d="M4 6.2l1.2 1.2L7.4 5M4 11.7l1.2 1.2 2.2-2.4"/><circle cx="5.2" cy="17.5" r="1.1"/>',
];
$lp_card = function (string $k, string $colore) use ($lp_ico) {
    echo '<article class="lp-card lp-rv" style="--c:' . $colore . '">'
       . '<span class="lp-card-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' . $lp_ico[$k] . '</svg></span>'
       . '<h3>' . htmlspecialchars(t('lp.f_' . $k . '_t'), ENT_QUOTES, 'UTF-8') . '</h3>'
       . '<p>' . htmlspecialchars(t('lp.f_' . $k . '_p'), ENT_QUOTES, 'UTF-8') . '</p>'
       . '</article>';
};
?>
<div class="lp" id="lp">

<header class="lp-head">
    <a class="lp-logo" href="./" aria-label="Standout">
        <svg viewBox="0 0 32 32" aria-hidden="true"><rect x="3" y="3" width="26" height="26" rx="8" fill="#5B8DEF"/><path d="M11 20.5c1.2 1.1 2.9 1.7 4.8 1.7 2.6 0 4.2-1.2 4.2-3.1 0-4.1-8.6-2.4-8.6-6.6 0-1.9 1.7-3.2 4.2-3.2 1.7 0 3.1.5 4.1 1.4" fill="none" stroke="#fff" stroke-width="2.4" stroke-linecap="round"/></svg>
        <span>Standout</span>
    </a>
</header>

<!-- ============ 1. LA PRIMA SCHERMATA ============ -->
<section class="lp-hero">
    <div class="lp-hero-txt">
        <p class="lp-eyebrow"><?php te('lp.eyebrow'); ?></p>
        <h1 class="lp-title"><?php te('lp.titolo_1'); ?><br><span class="lp-grad"><?php te('lp.titolo_2'); ?></span></h1>
        <p class="lp-sub"><?php te('lp.sub'); ?></p>
        <div class="lp-cta">
            <button type="button" class="lp-btn lp-btn-main" data-lp-auth="registrati"><?php te('lp.inizia'); ?></button>
            <button type="button" class="lp-btn lp-btn-ghost" data-lp-auth="login"><?php te('lp.accedi'); ?></button>
        </div>
        <p class="lp-note"><?php te('lp.nota_hero'); ?></p>
    </div>

    <div class="lp-hero-vis" aria-hidden="true">
        <div class="lp-phone">
            <div class="lp-ph-notch"></div>
            <div class="lp-ph-head">
                <span class="lp-ph-num">19</span>
                <span class="lp-ph-wd"><?php te('lp.m_giorno'); ?></span>
                <span class="lp-ph-wx">☀︎ 21°</span>
            </div>
            <div class="lp-ph-smart">
                <span class="lp-ph-type" style="--n:<?php echo (int)$lp_n; ?>"><?php echo htmlspecialchars($lp_smart, ENT_QUOTES, 'UTF-8'); ?></span>
                <span class="lp-ph-go">→</span>
            </div>
            <div class="lp-ph-grid">
                <?php foreach (['09', '10', '11', '12', '13', '14', '15', '16', '17', '18'] as $i => $h): ?>
                <span class="lp-ph-h" style="--i:<?php echo $i; ?>"><?php echo $h; ?></span>
                <?php endforeach; ?>
                <div class="lp-ph-ev" style="--s:0;--d:1.5;--c:#5B8DEF"><b><?php te('lp.m_ev1'); ?></b><i>09:00 – 10:30</i></div>
                <div class="lp-ph-ev" style="--s:2;--d:1.5;--c:#A78BFA"><b><?php te('lp.m_ev2'); ?></b><i>11:00 – 12:30</i><em class="lp-ph-pomo">● <?php te('lp.m_pomo'); ?></em></div>
                <div class="lp-ph-ev lp-ph-new" style="--s:4;--d:1;--c:#4CC38A"><b><?php te('lp.m_ev3'); ?></b><i>13:00 – 14:00</i></div>
                <div class="lp-ph-ev" style="--s:9;--d:1;--c:#FF6B5E"><b><?php te('lp.m_ev4'); ?></b><i>18:00 – 19:00</i></div>
            </div>
            <div class="lp-ph-foot">
                <svg class="lp-ph-ring" viewBox="0 0 44 44"><circle cx="22" cy="22" r="18" stroke="rgba(255,255,255,.1)"/><circle class="lp-ring-a" cx="22" cy="22" r="18" stroke="#4CC38A"/><circle cx="22" cy="22" r="12" stroke="rgba(255,255,255,.1)"/><circle class="lp-ring-b" cx="22" cy="22" r="12" stroke="#FFC94D"/></svg>
                <div class="lp-ph-pills">
                    <span><?php te('lp.m_abitudini'); ?> · 4/5</span>
                    <span class="lp-ph-pill">✓ <?php te('lp.m_pill'); ?></span>
                </div>
            </div>
        </div>
        <span class="lp-float lp-float-1">🔥 <?php te('lp.m_serie'); ?></span>
        <span class="lp-float lp-float-2">✓ Google Calendar</span>
        <span class="lp-float lp-float-3">🍅 +1 <?php te('lp.m_pomodoro'); ?></span>
    </div>
</section>

<!-- ============ 2. TUTTO IN UN POSTO ============ -->
<section class="lp-sec" id="lp-tutto">
    <p class="lp-eyebrow lp-rv"><?php te('lp.tutto_eyebrow'); ?></p>
    <h2 class="lp-h2 lp-rv"><?php te('lp.tutto_titolo'); ?></h2>
    <p class="lp-p lp-rv"><?php te('lp.tutto_sub'); ?></p>
    <div class="lp-cards">
        <?php
        $lp_card('planner', '#5B8DEF');
        $lp_card('progetti', '#FF6B5E');
        $lp_card('flashcard', '#A78BFA');
        $lp_card('lingue', '#4CC38A');
        $lp_card('studio', '#FFC94D');
        $lp_card('abitudini', '#2EC4B6');
        $lp_card('diario', '#FF9F43');
        $lp_card('liste', '#8E9AB8');
        ?>
    </div>
</section>

<!-- ============ 3. LA SCIENZA (lo studio) ============ -->
<section class="lp-sec lp-sec-alt" id="lp-scienza">
    <p class="lp-eyebrow lp-rv"><?php te('lp.sci_eyebrow'); ?></p>
    <h2 class="lp-h2 lp-rv"><?php te('landing.il_tuo_cervello_dimentica_per'); ?><br><?php te('landing.le_flashcard_lo_sfruttano'); ?></h2>
    <p class="lp-p lp-rv"><?php te('landing.nel_1885_lo_psicologo_hermann'); ?></p>
    <div class="lp-chart lp-rv">
        <svg viewBox="0 0 600 260" preserveAspectRatio="xMidYMid meet" aria-hidden="true">
            <line x1="40" y1="230" x2="580" y2="230" stroke="rgba(245,243,238,.15)"/>
            <line x1="40" y1="20" x2="40" y2="230" stroke="rgba(245,243,238,.15)"/>
            <text x="40" y="250" fill="#A9ACC0" font-size="12"><?php te('landing.giorno_0'); ?></text>
            <text x="500" y="250" fill="#A9ACC0" font-size="12"><?php te('landing.giorno_30'); ?></text>
            <text x="4" y="30" fill="#A9ACC0" font-size="12">100%</text>
            <path class="lp-curve" stroke="#FF6B5E" d="M40,30 Q80,90 120,140 Q160,175 220,196 Q280,210 340,217 Q420,223 500,226 Q540,227 560,228"/>
            <path class="lp-curve lp-curve-2" stroke="#4CC38A" d="M40,30 Q75,52 110,80 L120,42 Q160,58 200,75 L210,40 Q250,54 290,68 L300,38 Q340,50 380,62 L390,37 Q430,47 470,58 L480,36 Q510,44 560,50"/>
        </svg>
        <div class="lp-legend">
            <span><i style="background:#FF6B5E"></i><?php te('landing.solo_rilettura_passiva'); ?></span>
            <span><i style="background:#4CC38A"></i><?php te('landing.richiamo_attivo_dilazionato'); ?></span>
        </div>
    </div>
    <div class="lp-stats">
        <div class="lp-stat lp-rv">
            <div class="lp-stat-n">52% <small><?php te('landing.vs'); ?></small> 14%</div>
            <p><?php th('landing.studio_roediger', [
                'rileggere' => '<span class="lp-hl-c">' . t('landing.rileggere') . '</span>',
                'testato'   => '<span class="lp-hl-g">' . t('landing.testato_attivamente') . '</span>',
                /* Esperimento 2 dell'articolo: quanto si perde in una settimana
                   studiando quattro volte (52%) o studiando una volta e
                   mettendosi alla prova tre (14%). Il "56% contro 13%" della
                   pagina di prima non c'e' nell'articolo. */
                'perso1'    => '<strong>52%</strong>',
                'perso2'    => '<strong>14%</strong>',
            ]); ?></p>
        </div>
        <div class="lp-stat lp-rv">
            <div class="lp-stat-n">2 <small><?php te('landing.su'); ?></small> 10</div>
            <p><?php th('landing.dunlosky', [
                'richiamo' => '<strong>' . t('landing.richiamo_attivo') . '</strong>',
                'pratica'  => '<strong>' . t('landing.pratica_dilazionata') . '</strong>',
            ]); ?></p>
        </div>
        <div class="lp-stat lp-rv">
            <div class="lp-stat-n lp-stat-w"><?php te('landing.a_parita_di_tempo'); ?></div>
            <p><?php te('landing.la_ricerca_sulla_pratica_dilazionata'); ?></p>
        </div>
    </div>
    <p class="lp-fonti lp-rv"><?php th('landing.fonti', [
        'r1' => '<em>Psychological Science in the Public Interest</em>',
        'r2' => '<em>Psychological Science</em>',
    ]); ?></p>
</section>

<!-- ============ 4. SOCIAL, IN ARRIVO ============ -->
<section class="lp-sec" id="lp-social">
    <div class="lp-social lp-rv">
        <span class="lp-badge"><?php te('lp.social_badge'); ?></span>
        <h2 class="lp-h2"><?php te('lp.social_t'); ?></h2>
        <p class="lp-p"><?php te('lp.social_p'); ?></p>
        <ul class="lp-social-list">
            <li><span>👋</span><?php te('lp.social_1'); ?></li>
            <li><span>🏆</span><?php te('lp.social_2'); ?></li>
            <li><span>🃏</span><?php te('lp.social_3'); ?></li>
        </ul>
        <div class="lp-avatars" aria-hidden="true"><i style="--c:#5B8DEF">A</i><i style="--c:#FF6B5E">M</i><i style="--c:#4CC38A">L</i><i style="--c:#A78BFA">S</i><i style="--c:#FFC94D">+</i></div>
    </div>
</section>

<!-- ============ 5. OVUNQUE ============ -->
<section class="lp-sec lp-sec-alt" id="lp-ovunque">
    <h2 class="lp-h2 lp-rv"><?php te('lp.ovunque_t'); ?></h2>
    <p class="lp-p lp-rv"><?php te('lp.ovunque_p'); ?></p>
    <div class="lp-chips lp-rv">
        <span><?php te('lp.o_1'); ?></span>
        <span><?php te('lp.o_2'); ?></span>
        <span><?php te('lp.o_3'); ?></span>
        <span><?php te('lp.o_4'); ?></span>
    </div>
</section>

<!-- ============ 6. FINE ============ -->
<section class="lp-final">
    <h2 class="lp-rv"><?php te('lp.finale_t'); ?></h2>
    <button type="button" class="lp-btn lp-btn-main lp-rv" data-lp-auth="registrati"><?php te('lp.crea'); ?></button>
</section>

<footer class="lp-foot">
    <span>Standout</span> — <?php te('lp.footer'); ?> · © <?php echo date('Y'); ?>
</footer>
</div>

<script>
/* Dopo il primo disegno: i pulsanti aprono il popup (che è già pronto,
   sta più in alto nella pagina) e le sezioni compaiono scorrendo. Niente
   librerie: un osservatore e una classe. */
(function () {
    document.querySelectorAll('[data-lp-auth]').forEach(function (b) {
        b.addEventListener('click', function () {
            if (!window.StandoutAuth) return;
            if (b.dataset.lpAuth === 'login') StandoutAuth.apriLogin();
            else StandoutAuth.apriRegistrazione();
        });
    });
    var els = document.querySelectorAll('.lp-rv');
    if (!('IntersectionObserver' in window)) {
        els.forEach(function (e) { e.classList.add('lp-in'); });
        return;
    }
    var io = new IntersectionObserver(function (voci) {
        voci.forEach(function (v) {
            if (!v.isIntersecting) return;
            v.target.classList.add('lp-in');
            io.unobserve(v.target);
        });
    }, { threshold: 0.12, rootMargin: '0px 0px -6% 0px' });
    /* le carte di una griglia entrano una dopo l'altra */
    document.querySelectorAll('.lp-cards, .lp-stats').forEach(function (g) {
        Array.prototype.forEach.call(g.children, function (c, i) { c.style.transitionDelay = (i % 4) * 70 + 'ms'; });
    });
    els.forEach(function (e) { io.observe(e); });
})();
</script>
