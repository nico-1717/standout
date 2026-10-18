<?php
/**
 * credits_body.php — il contenuto della pagina dei crediti, senza la pagina.
 *
 * Lo usano in due: credits.php a tutta pagina, e lo stesso credits.php con
 * ?riquadro=1 per il popup dentro il pannello delle carte. Una copia sola,
 * perche' due elenchi di movimenti da tenere allineati sarebbero diventati
 * diversi al primo ritocco.
 *
 * Non va aperto da solo: si aspetta le variabili gia' pronte di credits.php
 * ($stato, $piano, $mese_primo, $e_questo_mese, $generazioni, $singole,
 * $accrediti, ...).
 */
if (!isset($stato)) { http_response_code(404); exit; }
?>
<main class="cred-main">

    <?php /* IL RIQUADRO IN ALTO: quanto resta, prima di tutto il resto. E'
             l'unica cosa che l'utente cerca nove volte su dieci. */ ?>
    <section class="cred-card cred-saldo">
        <?php if ($stato['limite'] === null): ?>
            <div class="cred-grande"><?php te('fc.cred_illimitato'); ?></div>
            <div class="cred-sotto"><?php te('fc.cred_usati_mese', ['n' => number_format($stato['usati'], 0, ',', '.')]); ?></div>
        <?php else:
            $quota = $stato['limite'] > 0 ? min(100, round($stato['usati'] / $stato['limite'] * 100)) : 0; ?>
            <div class="cred-grande"><?php echo number_format($stato['disponibili'], 0, ',', '.'); ?></div>
            <div class="cred-sotto"><?php te('fc.cred_restano', ['limite' => number_format($stato['limite'], 0, ',', '.')]); ?></div>
            <div class="cred-barra<?php echo $quota >= 85 ? ' is-warn' : ''; ?>"><i style="width:<?php echo $quota; ?>%"></i></div>
            <?php if ($quota >= 85): ?>
                <div class="cred-avviso"><?php te('fc.cred_quasi_finiti'); ?></div>
            <?php endif; ?>
        <?php endif; ?>
        <?php if ($piano): ?>
            <div class="cred-piano"><?php te('fc.cred_piano', ['nome' => $piano['name']]); ?></div>
        <?php endif; ?>
    </section>

    <?php /* Sfogliare i mesi con due link veri: niente JavaScript, e l'indirizzo
             si puo' salvare o mandare. */ ?>
    <nav class="cred-mesi">
        <a href="?mese=<?php echo date('Y-m', strtotime($mese_primo . ' -1 month')); ?>" rel="prev">←</a>
        <b><?php echo sec_h(ucfirst(strftime_it($mese_primo))); ?></b>
        <?php if (!$e_questo_mese): ?>
            <a href="?mese=<?php echo date('Y-m', strtotime($mese_primo . ' +1 month')); ?>" rel="next">→</a>
        <?php else: ?>
            <span class="cred-vuoto">→</span>
        <?php endif; ?>
    </nav>

    <?php if (!$generazioni && !$singole && !$accrediti): ?>
        <section class="cred-card cred-niente"><?php te('fc.cred_niente'); ?></section>
    <?php endif; ?>

    <?php if ($generazioni): ?>
        <h2 class="cred-tit"><?php te('fc.cred_generazioni'); ?> <span><?php echo number_format($tot_generazioni, 0, ',', '.'); ?></span></h2>
        <section class="cred-card">
            <?php foreach ($generazioni as $g):
                $stile = crediti_stile_leggibile($g['nota']); ?>
                <div class="cred-riga">
                    <div class="cred-riga-testo">
                        <b><?php echo sec_h($g['mazzo'] !== null ? $g['mazzo'] : t('fc.cred_mazzo_rimosso')); ?></b>
                        <small>
                            <?php echo crediti_data($g['inizio']); ?> ·
                            <?php te('fc.cred_passi', ['n' => (int)$g['passi']]); ?>
                            <?php if ($g['status'] === 'paused' || $g['status'] === 'failed'): ?>
                                · <span class="cred-tag"><?php te('fc.cred_interrotta'); ?></span>
                            <?php endif; ?>
                            <?php if ($stile !== ''): ?><br><?php echo sec_h($stile); ?><?php endif; ?>
                        </small>
                    </div>
                    <div class="cred-num">−<?php echo number_format((int)$g['crediti'], 0, ',', '.'); ?></div>
                </div>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>

    <?php if ($singole): ?>
        <h2 class="cred-tit"><?php te('fc.cred_singole'); ?> <span><?php echo number_format($tot_singole, 0, ',', '.'); ?></span></h2>
        <section class="cred-card">
            <?php foreach ($singole as $s): ?>
                <div class="cred-riga">
                    <div class="cred-riga-testo">
                        <b><?php te('fc.cred_azione_' . $s['azione']); ?></b>
                        <small><?php echo crediti_data($s['giorno']); ?> · <?php te('fc.cred_volte', ['n' => $s['quante']]); ?></small>
                    </div>
                    <div class="cred-num">−<?php echo number_format($s['crediti'], 0, ',', '.'); ?></div>
                </div>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>

    <?php if ($accrediti): ?>
        <h2 class="cred-tit"><?php te('fc.cred_accrediti'); ?> <span>+<?php echo number_format($tot_accrediti, 0, ',', '.'); ?></span></h2>
        <section class="cred-card">
            <?php foreach ($accrediti as $a): ?>
                <div class="cred-riga">
                    <div class="cred-riga-testo">
                        <b><?php te('fc.cred_motivo_' . $a['reason']); ?></b>
                        <small><?php echo crediti_data($a['created_at']); ?><?php if ($a['note']): ?> · <?php echo sec_h($a['note']); ?><?php endif; ?></small>
                    </div>
                    <div class="cred-num is-piu">+<?php echo number_format((int)$a['delta'], 0, ',', '.'); ?></div>
                </div>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>

    <p class="cred-nota"><?php te('fc.cred_spiegazione'); ?></p>

</main>
