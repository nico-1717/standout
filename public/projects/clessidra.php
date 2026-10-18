<?php
/* ==========================================================================
   clessidra.php — clessidra "bella" (fisica sabbia + capovolgimento), a parte
   rispetto a pomodoro.php per non appesantirlo.

   Uso: <?php include __DIR__ . '/clessidra.php'; ?>  dentro al contenitore che gestisce
   già mostra/nascondi (in pomodoro.php questo è <div id="glassWrap">).

   Espone window.Clessidra con 5 metodi, pensati per essere pilotati dal
   tick esistente di pomodoro.php (nessuna logica di durata qui dentro):
     - Clessidra.idle()            stato di riposo: piena sopra, ferma
     - Clessidra.playStartFlip()   capovolgimento (una tantum) a inizio sessione
     - Clessidra.setFraction(f)    f in 0..1 = quanto tempo è trascorso
     - Clessidra.setRunning(bool)  avvia/ferma la pioggia di granelli
     - Clessidra.finish()          stato finale: tutta travasata, ferma

   Colore sabbia = colore del progetto (var(--pomo-live), come il resto
   dell'interfaccia: grigio neutro di default, teal in pausa). Il vetro/telaio
   usa un tono neutro derivato dai token della pagina.
   ========================================================================== */
if (!defined('CLESSIDRA_ASSETS_EMITTED')):
define('CLESSIDRA_ASSETS_EMITTED', true);
?>
<style>
.clessidra-wrap{
    --cl-glass: color-mix(in srgb, var(--text-3) 65%, var(--surface));
    --cl-sand: var(--pomo-live, var(--pomo-accent));
    width:min(46vw, 190px); aspect-ratio:430/680; height:auto;
    margin:14px 0 2px; position:relative;
    filter:drop-shadow(0 14px 28px rgba(0,0,0,.14));
}
@media (prefers-color-scheme: dark){ .clessidra-wrap{ filter:drop-shadow(0 14px 30px rgba(0,0,0,.5)); } }
.clessidra-wrap svg{ width:100%; height:100%; display:block; overflow:visible; }
.clessidra-flip{ transform-origin:center center; will-change:transform; }
.clessidra-grain{ filter:drop-shadow(0 1px 1px rgba(0,0,0,.18)); }
</style>
<?php endif; ?>

<div class="clessidra-wrap" id="clessidraWrap">
<div class="clessidra-flip" id="clessidraFlip">
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="480 40 430 680" preserveAspectRatio="xMidYMid meet">
        <defs>
            <clipPath id="clGlassChamber">
                <path d="M 731.50 613.97 C741.95,613.40 752.14,612.50 754.14,611.96 C760.50,610.26 776.19,592.49 782.64,579.71 C791.48,562.19 795.16,543.75 793.18,526.91 C790.94,507.80 785.73,492.75 774.48,472.88 C766.97,459.62 751.90,440.43 740.41,429.50 C727.65,417.36 714.90,402.75 711.92,396.84 C704.20,381.54 704.23,373.88 712.06,359.63 C714.95,354.36 737.93,328.84 742.93,325.34 C745.18,323.76 762.92,301.92 769.19,293.00 C771.71,289.42 775.82,282.67 778.33,278.00 C802.50,233.01 798.67,187.86 767.71,152.71 C764.42,148.98 760.85,145.45 759.78,144.88 C758.70,144.31 749.65,143.15 739.66,142.31 C701.52,139.09 632.27,141.21 626.05,145.78 C622.00,148.76 609.37,165.79 605.13,174.00 C591.92,199.56 590.21,225.24 599.69,255.47 C607.15,279.26 624.93,304.88 655.94,336.50 C664.03,344.75 672.10,353.65 673.88,356.27 C678.49,363.05 681.00,371.08 681.00,379.03 C681.00,391.79 676.69,399.05 657.00,419.51 C630.83,446.70 624.44,454.50 613.41,472.68 C606.94,483.35 602.02,494.95 597.37,510.50 C595.00,518.44 594.67,521.27 594.59,534.50 C594.51,547.32 594.84,550.71 596.83,557.85 C601.17,573.43 606.96,585.09 615.29,595.00 C622.87,604.02 629.42,609.99 633.33,611.43 C641.68,614.51 695.77,615.91 731.50,613.97 Z" />
            </clipPath>
            <clipPath id="clBottomHalf">
                <rect x="400" y="378" width="600" height="400" />
            </clipPath>
        </defs>

        <g clip-path="url(#clGlassChamber)">
            <path id="clTopSand" fill="var(--cl-sand)" />
            <g id="clBottomSandWrapper">
                <path id="clBottomSand" fill="var(--cl-sand)" />
            </g>
        </g>

        <g id="clSandParticles" clip-path="url(#clGlassChamber)"></g>

        <g>
            <path fill-rule="evenodd" d="M 644.50 705.30 C611.98,704.08 574.18,700.28 551.13,695.92 C532.65,692.43 513.23,685.17 510.95,680.90 C509.69,678.55 509.60,644.12 510.84,640.89 C512.44,636.73 518.60,632.40 526.35,630.00 C530.28,628.77 533.83,627.47 534.24,627.10 C534.75,626.63 535.45,178.11 535.02,131.83 C535.00,130.66 532.97,129.49 528.25,127.93 C520.36,125.31 515.10,122.21 512.12,118.43 C510.13,115.90 510.00,114.58 510.00,97.30 C510.00,86.48 510.43,78.07 511.04,76.93 C512.20,74.75 520.93,70.11 528.50,67.65 C542.52,63.08 576.74,57.52 606.00,55.05 C656.97,50.75 735.34,50.96 785.86,55.53 C834.02,59.89 867.93,67.63 875.50,76.00 C877.32,78.01 877.52,79.77 877.69,95.77 C877.84,108.85 877.53,114.18 876.50,116.65 C874.74,120.86 867.71,125.41 858.75,128.14 L 852.00 130.19 L 852.00 378.52 L 852.00 626.84 L 858.75 629.06 C867.62,631.97 871.95,634.22 875.00,637.48 C877.45,640.10 877.51,640.53 877.82,658.25 C877.99,668.20 877.87,677.37 877.56,678.63 C877.24,679.89 875.07,682.20 872.74,683.77 C856.93,694.41 817.96,701.24 751.64,704.99 C735.77,705.89 665.61,706.09 644.50,705.30 ZM 760.23 662.52 C785.89,660.96 785.42,661.00 810.50,657.92 C838.90,654.44 864.19,648.65 867.62,644.86 C869.21,643.11 869.20,642.88 867.55,641.06 C865.64,638.94 854.28,634.71 852.87,635.58 C852.39,635.88 852.00,637.65 852.00,639.53 C852.00,642.30 851.44,643.24 849.05,644.47 C843.60,647.29 825.95,646.10 823.84,642.77 C823.39,642.07 823.02,638.90 823.02,635.73 C823.00,630.65 822.73,629.85 820.75,629.13 C817.31,627.89 798.22,625.70 796.51,626.36 C795.42,626.77 795.00,628.44 795.00,632.29 C795.00,639.19 793.01,641.10 783.00,643.88 C772.93,646.66 764.42,647.78 740.05,649.51 C706.61,651.89 655.58,650.98 624.58,647.44 C613.81,646.22 598.76,642.64 595.68,640.57 C592.75,638.60 592.48,637.94 592.20,632.22 L 591.90 626.00 L 588.20 626.02 C583.89,626.04 567.41,628.63 565.98,629.51 C565.44,629.85 564.96,632.00 564.90,634.31 C564.67,644.20 562.29,645.85 548.18,645.94 C538.03,646.01 536.21,645.16 535.38,639.97 C534.91,637.02 534.27,636.00 532.91,636.00 C530.04,636.00 520.32,639.90 518.89,641.63 C516.97,643.94 520.65,646.29 530.95,649.34 C551.25,655.36 582.95,659.71 626.50,662.47 C655.02,664.27 731.03,664.30 760.23,662.52 ZM 823.00 378.44 C823.00,235.67 822.63,136.89 822.10,136.56 C821.02,135.90 797.61,138.51 796.06,139.46 C795.48,139.82 795.00,142.82 795.00,146.12 C795.00,153.51 794.03,155.44 788.99,158.00 C784.45,160.32 784.31,159.35 791.31,173.50 C799.65,190.33 802.75,206.00 801.71,225.94 C800.99,239.75 798.75,249.93 793.35,263.94 C788.17,277.34 785.31,282.63 775.19,297.50 C764.91,312.61 758.64,320.06 741.54,337.50 C718.23,361.28 713.13,369.32 714.29,380.52 C714.59,383.41 715.60,387.29 716.53,389.14 C719.59,395.19 728.62,406.48 736.46,414.06 C764.89,441.55 784.18,468.46 793.40,493.50 C805.87,527.37 804.35,556.87 788.67,584.99 C782.40,596.25 782.45,595.69 787.28,598.11 C794.32,601.65 795.00,602.63 795.00,609.22 C795.00,617.09 796.11,618.98 800.79,619.08 C802.83,619.12 808.10,619.71 812.50,620.39 C817.55,621.18 820.96,621.29 821.75,620.69 C822.74,619.95 823.00,570.32 823.00,378.44 ZM 581.09 619.62 C585.81,618.91 590.20,618.00 590.84,617.60 C591.52,617.18 592.00,614.29 592.00,610.58 C592.00,605.14 592.35,603.92 594.57,601.70 C595.99,600.28 598.43,598.84 600.00,598.50 C605.51,597.29 605.51,595.91 600.02,586.34 C586.98,563.60 583.17,541.32 588.00,516.01 C590.72,501.72 593.16,494.72 600.71,479.57 C611.22,458.47 622.28,443.87 646.62,418.98 C670.71,394.33 675.80,385.60 673.09,373.52 C671.41,366.00 663.75,354.98 652.17,343.42 C633.84,325.12 624.19,314.15 614.68,300.78 C600.58,280.97 591.92,262.26 587.96,243.00 C586.09,233.93 585.87,230.33 586.31,216.50 C586.92,197.54 588.59,190.14 595.58,175.50 C603.86,158.16 603.37,160.19 599.70,158.61 C593.35,155.88 592.00,153.66 592.00,145.96 C592.00,142.13 591.89,138.98 591.75,138.96 C591.61,138.94 585.66,138.29 578.52,137.52 C568.56,136.46 565.36,136.41 564.78,137.32 C563.88,138.73 563.73,613.38 564.62,617.88 C565.19,620.69 565.60,621.00 568.88,620.96 C570.87,620.95 576.36,620.34 581.09,619.62 ZM 731.50 613.97 C741.95,613.40 752.14,612.50 754.14,611.96 C760.50,610.26 776.19,592.49 782.64,579.71 C791.48,562.19 795.16,543.75 793.18,526.91 C790.94,507.80 785.73,492.75 774.48,472.88 C766.97,459.62 751.90,440.43 740.41,429.50 C727.65,417.36 714.90,402.75 711.92,396.84 C704.20,381.54 704.23,373.88 712.06,359.63 C714.95,354.36 737.93,328.84 742.93,325.34 C745.18,323.76 762.92,301.92 769.19,293.00 C771.71,289.42 775.82,282.67 778.33,278.00 C802.50,233.01 798.67,187.86 767.71,152.71 C764.42,148.98 760.85,145.45 759.78,144.88 C758.70,144.31 749.65,143.15 739.66,142.31 C701.52,139.09 632.27,141.21 626.05,145.78 C622.00,148.76 609.37,165.79 605.13,174.00 C591.92,199.56 590.21,225.24 599.69,255.47 C607.15,279.26 624.93,304.88 655.94,336.50 C664.03,344.75 672.10,353.65 673.88,356.27 C678.49,363.05 681.00,371.08 681.00,379.03 C681.00,391.79 676.69,399.05 657.00,419.51 C630.83,446.70 624.44,454.50 613.41,472.68 C606.94,483.35 602.02,494.95 597.37,510.50 C595.00,518.44 594.67,521.27 594.59,534.50 C594.51,547.32 594.84,550.71 596.83,557.85 C601.17,573.43 606.96,585.09 615.29,595.00 C622.87,604.02 629.42,609.99 633.33,611.43 C641.68,614.51 695.77,615.91 731.50,613.97 Z M 613.00 607.07 C613.00,606.19 610.26,603.00 609.49,603.00 C609.29,603.00 607.86,603.48 606.32,604.08 L 603.50 605.15 L 606.00 606.54 C608.87,608.13 613.00,608.45 613.00,607.07 Z M 782.23 606.17 C783.53,604.87 779.53,602.70 777.21,603.43 C776.27,603.73 774.89,604.95 774.15,606.13 C772.83,608.24 772.90,608.28 777.02,607.72 C779.34,607.41 781.69,606.71 782.23,606.17 Z M 609.43 151.43 C611.97,148.89 611.32,147.77 607.75,148.53 C603.53,149.42 602.06,150.79 604.01,152.03 C606.15,153.38 607.64,153.22 609.43,151.43 Z M 783.53 151.61 C785.31,150.30 785.15,150.13 781.21,148.94 C776.26,147.46 774.95,148.19 777.49,150.99 C779.64,153.36 780.94,153.49 783.53,151.61 Z M 592.01 125.39 C592.63,119.29 594.07,117.33 599.71,114.91 C617.67,107.21 686.91,102.83 732.50,106.51 C766.18,109.22 768.84,109.58 782.26,113.19 C792.49,115.95 795.00,118.27 795.00,124.98 C795.00,128.59 795.44,130.23 796.51,130.64 C798.22,131.30 817.31,129.11 820.75,127.87 C822.64,127.18 823.00,126.31 823.00,122.46 C823.00,119.93 823.45,117.03 824.00,116.01 C826.16,111.96 844.17,110.91 849.66,114.51 C850.87,115.30 852.00,116.97 852.18,118.22 C852.64,121.49 855.39,121.56 862.62,118.48 C866.93,116.65 869.00,115.22 869.00,114.06 C869.00,109.05 836.61,100.90 801.50,97.07 C760.46,92.60 745.77,91.91 692.11,91.93 C638.31,91.95 619.16,92.93 582.00,97.58 C550.99,101.46 522.62,108.14 519.10,112.38 C517.52,114.28 521.53,117.65 527.55,119.48 C534.26,121.51 534.66,121.44 535.34,118.05 C536.20,113.74 539.23,112.57 549.54,112.53 C561.82,112.49 564.00,113.85 564.00,121.55 C564.00,125.05 564.46,127.23 565.25,127.53 C567.86,128.50 584.65,130.81 588.00,130.66 C591.45,130.50 591.51,130.43 592.01,125.39 Z" fill="var(--cl-glass)" />
        </g>
    </svg>
</div>
</div>

<?php if (!defined('CLESSIDRA_SCRIPT_EMITTED')): define('CLESSIDRA_SCRIPT_EMITTED', true); ?>
<script>
(function(){
    'use strict';
    var wrapper = document.getElementById('clessidraFlip');
    var topSand = document.getElementById('clTopSand');
    var bottomSand = document.getElementById('clBottomSand');
    var bottomSandWrapper = document.getElementById('clBottomSandWrapper');
    var particlesHost = document.getElementById('clSandParticles');
    if (!wrapper || !topSand || !bottomSand) return; // markup non presente su questa pagina

    var NECK_X = 693, NECK_Y = 378;
    var MAX_FILL_HEIGHT = 188; // 80% esatto dell'altezza della camera (236px)

    var particles = [];
    var particleRafId = null;
    var running = false;
    var flipping = false;
    var lastBottomY = 614, lastActiveMound = 0;
    var flipRafId = null;

    function getTopSandPath(fillAmount, sloshOffset){
        sloshOffset = sloshOffset || 0;
        if (fillAmount <= 0) return 'M ' + NECK_X + ',' + NECK_Y + ' Z';
        var edgeY = NECK_Y - (fillAmount * MAX_FILL_HEIGHT);
        var dip = (1 - fillAmount) * 60;
        var centerY = edgeY + dip + sloshOffset;
        if (centerY > NECK_Y) centerY = NECK_Y;
        if (edgeY > NECK_Y) edgeY = NECK_Y;
        var funnelHalfWidth = 16, tipDepth = 30;
        var tipY = NECK_Y + tipDepth;
        return 'M 450,' + edgeY + ' Q ' + NECK_X + ',' + centerY + ' 940,' + edgeY +
               ' L 940,' + NECK_Y + ' L ' + (NECK_X + funnelHalfWidth) + ',' + NECK_Y +
               ' Q ' + NECK_X + ',' + tipY + ' ' + (NECK_X - funnelHalfWidth) + ',' + NECK_Y +
               ' L 450,' + NECK_Y + ' Z';
    }

    function updateSandLevels(fillAmount, sloshOffset){
        topSand.setAttribute('d', getTopSandPath(fillAmount, sloshOffset));
        var emptyPercent = 1 - fillAmount;
        var bottomY = 614 - (emptyPercent * MAX_FILL_HEIGHT);
        var activeMound = (emptyPercent > 0 && emptyPercent < 1) ? 22 : 0;
        var moundY = bottomY - activeMound;
        bottomSand.setAttribute('d', 'M 450,620 L 940,620 L 940,' + bottomY + ' Q ' + NECK_X + ',' + moundY + ' 450,' + bottomY + ' Z');
        lastBottomY = bottomY; lastActiveMound = activeMound;
    }

    function spawnParticle(){
        var circle = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
        var r = 1.0 + Math.random() * 2.0;
        var x = NECK_X + (Math.random() - 0.5) * 4;
        var y = NECK_Y;
        circle.setAttribute('cx', x); circle.setAttribute('cy', y); circle.setAttribute('r', r);
        circle.setAttribute('fill', 'var(--cl-sand)'); circle.setAttribute('class', 'clessidra-grain');
        particlesHost.appendChild(circle);
        return { el: circle, x: x, y: y, vx: (Math.random() - 0.5) * 0.8, vy: 2 + Math.random() * 2, gravity: 0.25 };
    }

    function particleLoop(){
        if (!running && particles.length === 0) { particleRafId = null; return; }
        if (running) {
            var spawnCount = Math.floor(Math.random() * 3) + 2;
            for (var i = 0; i < spawnCount; i++) particles.push(spawnParticle());
        }
        for (var j = particles.length - 1; j >= 0; j--) {
            var p = particles[j];
            p.vy += p.gravity; p.x += p.vx; p.y += p.vy;
            p.el.setAttribute('cx', p.x); p.el.setAttribute('cy', p.y);
            var collisionY = lastBottomY + 8;
            var distFromCenter = Math.abs(p.x - NECK_X);
            if (distFromCenter < 90 && running) {
                var visualDunaHeight = lastActiveMound * (1 - (distFromCenter / 90));
                collisionY = lastBottomY - visualDunaHeight + 8;
            }
            if (p.y >= collisionY) { p.el.remove(); particles.splice(j, 1); }
        }
        particleRafId = requestAnimationFrame(particleLoop);
    }

    function ensureParticleLoop(){ if (!particleRafId) particleRafId = requestAnimationFrame(particleLoop); }

    window.Clessidra = {
        // stato di riposo: piena sopra, ferma, nessun granello (pronta per essere capovolta)
        idle: function(){
            if (flipRafId) { cancelAnimationFrame(flipRafId); flipRafId = null; }
            running = false; flipping = false;
            particles.forEach(function(p){ p.el.remove(); }); particles = [];
            wrapper.style.transition = 'none';
            wrapper.style.transform = 'rotate(0deg)';
            bottomSand.removeAttribute('transform');
            bottomSandWrapper.removeAttribute('clip-path');
            
            // MODIFICA QUI: cambia 1 in 0
            updateSandLevels(0); 
        },
        // stato finale: tutta travasata (vuota sopra), ferma
        finish: function(){
            if (flipRafId) { cancelAnimationFrame(flipRafId); flipRafId = null; }
            running = false; flipping = false;
            updateSandLevels(0);
        },
        // f in 0..1 = frazione di tempo trascorsa (0=appena iniziato, 1=esaurito)
        setFraction: function(f){
            if (flipping) return; // il capovolgimento gestisce da solo il proprio stato visivo
            f = Math.max(0, Math.min(1, f));
            updateSandLevels(1 - f);
        },
        setRunning: function(isRunning){
            running = !!isRunning;
            if (running) ensureParticleLoop();
        },
        // capovolgimento (una tantum) a inizio sessione: il blocco di sabbia accumulato
        // scivola su attraverso la strozzatura mentre il vetro ruota di 180°
        playStartFlip: function(){
            running = false;
            flipping = true;
            particles.forEach(function(p){ p.el.remove(); }); particles = [];

            var flipDuration = 800;
            var flipStartTime = Date.now();
            topSand.setAttribute('d', getTopSandPath(0));
            bottomSandWrapper.setAttribute('clip-path', 'url(#clBottomHalf)');
            bottomSand.setAttribute('d', 'M -1000 0 L 2500 0 L 2500 2500 L -1000 2500 Z');

            var startY = 614 - MAX_FILL_HEIGHT;
            var endY = NECK_Y + MAX_FILL_HEIGHT;

            wrapper.style.transition = 'none';
            if (flipRafId) cancelAnimationFrame(flipRafId);

            function ease(t){ return t < 0.5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2; }

            function flipLoop(){
                var now = Date.now();
                var progress = (now - flipStartTime) / flipDuration;
                if (progress >= 1) progress = 1;
                var easedProgress = ease(progress);
                var angle = easedProgress * 180;
                wrapper.style.transform = 'rotate(' + angle + 'deg)';
                var currentYs = startY + (endY - startY) * easedProgress;
                bottomSand.setAttribute('transform', 'translate(' + NECK_X + ', ' + currentYs + ') rotate(' + (-angle) + ') translate(-' + NECK_X + ', 0)');

                if (progress < 1) {
                    flipRafId = requestAnimationFrame(flipLoop);
                } else {
                    flipRafId = null;
                    wrapper.style.transform = 'rotate(0deg)';
                    bottomSand.removeAttribute('transform');
                    bottomSandWrapper.removeAttribute('clip-path');
                    updateSandLevels(1);
                    flipping = false;
                    // NB: non forziamo qui "running = true" — è chi pilota il modulo
                    // (setRunning) a decidere se i granelli devono davvero cadere,
                    // es. solo quando la clessidra è la visualizzazione attiva
                }
            }
            requestAnimationFrame(flipLoop);
        }
    };

    updateSandLevels(0); // stato iniziale di default: pieno sopra, come idle() (poi bootstrap chiama lo stato giusto)
})();
</script>
<?php endif; ?>